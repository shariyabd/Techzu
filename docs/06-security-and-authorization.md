# 06 · Security and authorization

Who may do what, how it is enforced, and the guarantees around internal notes, files, sessions and realtime channels. Decisions: TD-010, TD-011, TD-012, TD-120 in [11 · Technical decisions](11-technical-decisions.md). Plain-language rules: [04 · Business logic](04-business-logic.md#authorization).

## 1. Two layers

1. **Capabilities** (spatie/laravel-permission): *what kind of thing* a user may do. Named `<resource>.<action>[.<scope>]`, always referenced through the `App\Enums\Permission` enum. Roles are fixed (`admin`, `agent`, `customer`, the `App\Enums\Role` enum).
2. **Policies**: *on which record*. They combine a capability with ownership and state. Every route is authorized through a Policy, either with `can:` route middleware or in the FormRequest's `authorize()`. Controllers, Actions and Vue props never call `hasRole()` or `hasPermissionTo()`.

The role → permission mapping is seeded from `Permission::forRole()` by `RolesAndPermissionsSeeder`. Admins with `users.manage` can change it at runtime on the permission matrix page, with two hard guards: the customer role can never receive `tickets.notes.internal`, and the admin role can never lose `users.manage`.

## 2. Permission matrix (seeded defaults)

| Permission | Meaning | Customer | Agent | Admin |
|---|---|:-:|:-:|:-:|
| `tickets.create` | Create tickets | ✓ | ✓ | ✓ |
| `tickets.create.on_behalf` | Create tickets for a customer | | ✓ | ✓ |
| `tickets.view.any` | See every ticket; staff areas | | ✓ | ✓ |
| `tickets.view.own` | See tickets where they are the customer | ✓ | | |
| `tickets.reply` | Post public replies | ✓ | ✓ | ✓ |
| `tickets.notes.internal` | Read and write internal notes | | ✓ | ✓ |
| `tickets.claim` | Claim unassigned tickets | | ✓ | ✓ |
| `tickets.update.any` | Manage any ticket | | | ✓ |
| `tickets.update.assigned` | Manage tickets assigned to them | | ✓ | |
| `tickets.update.own` | Edit own Open ticket; close or reopen own Resolved ticket | ✓ | | |
| `tickets.assign.any` | Assign any ticket, including outside its category | | | ✓ |
| `tickets.handoff.own` | Hand off their own ticket | | ✓ | |
| `dashboard.view` | Dashboard | | ✓ | ✓ |
| `imports.manage` | CSV import | | | ✓ |
| `users.manage` | Users, roles, permission matrix, API docs | | | ✓ |
| `categories.manage` | Categories | | | ✓ |
| `holidays.manage` | Holidays | | | ✓ |
| `sla.manage` | SLA rules and business hours | | | ✓ |
| `saved_replies.use` | Use saved replies, manage own | | ✓ | ✓ |
| `saved_replies.manage_shared` | Manage team saved replies | | | ✓ |

Source: `app/Enums/Permission.php`. The matrix test (`tests/Unit/Enums/PermissionTest.php`) and the seeder test pin these defaults.

## 3. What each role can do

| Action | Customer | Agent | Admin |
|---|---|---|---|
| Create ticket | as self | on behalf of a customer | on behalf of a customer |
| View ticket | own only | all | all |
| Public reply | own, not on Closed | all, not on Closed | all, not on Closed |
| Internal notes (read and write) | never | all | all |
| Claim an unassigned active ticket | ✗ | ✓ | ✓ |
| Edit subject | own, while Open | assigned to self, not Closed | all, not Closed |
| Edit description | own, while Open | ✗ (the customer's words) | ✗ |
| Change status | close or reopen own Resolved | assigned to self, not Closed | all, not Closed |
| Change priority | ✗ | assigned to self, active tickets only | all, active tickets only |
| Change category | ✗ | assigned to self, not Closed | all, not Closed |
| Assign or reassign | ✗ | hand off own ticket, in-category | any agent; may override category |
| Dashboard, agents directory, customer lookup | ✗ (403) | ✓ | ✓ |
| Saved replies | ✗ (403) | own personal replies; use team replies | own and team replies |
| Profile (name, password, picture) | ✓ | ✓ | ✓ |
| Users, roles, categories, holidays, SLA rules, imports | ✗ | ✗ | ✓ |
| API documentation (outside `local`) | ✗ | ✗ | ✓ |

"Manage" means `tickets.update.any`, or `tickets.update.assigned` while being the assignee (`TicketPolicy::manages`).

## 4. The visibility rule

`App\Services\Tickets\TicketVisibility` turns the user's permissions into three facts and is used by every ticket query and policy:

| Fact | Comes from | Effect |
|---|---|---|
| sees all tickets | `tickets.view.any` | no customer restriction |
| sees own tickets | `tickets.view.own` | `customer_id = me` |
| sees internal | `tickets.notes.internal` | internal replies, internal attachments, staff-only timeline entries |

The ticket list, queue counts, the ticket page, the conversation, attachments, the timeline and channel authorization all read the same object, so editing the permission matrix can never make them disagree. Escalation level in API resources is shown only to `tickets.view.any`; "SLA breach approaching", "Ticket escalated" and "Internal note added" timeline entries only to `tickets.notes.internal`.

## 5. 404 versus 403 (TD-012)

| Situation | Response |
|---|---|
| A ticket, reply or attachment the user may not see (another customer's ticket, an internal reply for a customer) | **404**, through `Response::denyAsNotFound()` in the policy; existence is never confirmed |
| A visible ticket, but a forbidden action (a customer changing priority; anyone replying on Closed) | **403** |
| An internal note requested by someone without `addInternalNote` (a customer, or staff whose role lost `tickets.notes.internal`) | **403** (`StoreRequest::authorize`, and `InternalNoteForbidden` from `AddReply`); nothing is saved, never downgraded to a public reply |
| A hand-off whose actor stopped being the assignee between the request check and the locked write | **403** (`AssignTicket` re-authorizes after locking) |
| Another user's personal saved reply | **404** |
| An agent profile for a non-staff user | **404** |
| A profile picture the viewer may not see | **404** |
| Someone else's notification | **404** |
| Staff-only areas (dashboard, agents directory, customer lookup, saved replies) for a customer | **403**: the area exists for everyone, so there is nothing to hide |
| Admin areas for agents and customers | **403** |
| Guests | redirect to login (web), 401 (API) |

## 6. Internal-note guarantees

Customers (anyone without `tickets.notes.internal`) never receive internal content, in any channel:

| Channel | How it is enforced |
|---|---|
| Queries | `TicketReply::visibleTo()` and `TicketVisibility::restrictInternal()` exclude `is_internal` rows; reply counts and attachment lists use the same scope |
| Inertia props and API resources | built from those queries; `TicketReplyResource` refuses to serialize an internal reply for a viewer without the permission, even if a query forgot the scope |
| Attachments | `AttachmentPolicy` denies internal attachments as 404; download and preview both go through it |
| Activity history | `Ticket::activitiesVisibleTo()` hides `internal_note`, `sla_approaching` and `escalated` events |
| Notifications | an internal note notifies only the assigned agent (database only); the customer is never a recipient. Every notification a user received as staff is deleted when they are demoted to customer, and internal-note notifications are dropped from the inbox, the unread count and `NotificationResource` for viewers who no longer hold `tickets.notes.internal` |
| Broadcasts | an internal note fires `InternalNoteAdded` on `tickets.{number}.staff` only; the public channel gets nothing for it |
| Typing whispers | internal-note typing is whispered only on the staff channel |
| Writing | a request for an internal note without `addInternalNote` is refused with 403 at the FormRequest and again in `AddReply`; it is never saved as a public reply |
| Activity timing | an internal note does not touch the ticket's `updated_at`, so customers can't see staff activity in "last updated" or list order |
| The matrix | the customer role can never be granted `tickets.notes.internal` |

## 7. Files

**Attachments** (TD-061)
- Allowed extensions: pdf, png, jpg, jpeg, gif, txt, csv, doc, docx, xls, xlsx, zip; both the extension and the sniffed content type must match (`File::types()`); 10 MB per file, 5 files per ticket or reply (configurable).
- Stored on the private `local` disk (or `ATTACHMENTS_DISK`) under `attachments/YYYY/MM/{uuid}.{ext}`. Never under `public/`.
- Downloads stream through `AttachmentPolicy` with `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`. Inline preview exists only for PNG, JPEG and GIF; SVG is never inline.
- Files are written before the database transaction and deleted if it fails.

**Profile pictures**
- JPG, PNG or WebP, at most 2 MB, 64–4096 px per side; checked by extension, MIME type and real dimensions.
- Re-encoded with GD to a 256 px centre-cropped square (WebP, or PNG when WebP is unavailable), which strips metadata. Stored privately under a random 40-character name.
- Served by `AvatarController` after `UserPolicy::viewAvatar` (self, `tickets.view.any`, or a customer viewing staff), with `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'; sandbox`, `Cache-Control: private` and an ETag. URLs carry a version derived from the stored path, so a new picture busts caches.

## 8. Sessions, passwords and tokens

| Event | Effect |
|---|---|
| Self-service password change | requires the current password; new must differ; ends every other web session, rotates the remember-me token, revokes all other API tokens; the current session or token stays |
| Admin sets a user's password | rotates remember-me, revokes all API tokens, ends all the user's web sessions (keeps the admin's own current session when editing themselves) |
| Password reset by email | rotates remember-me and revokes all API tokens |
| Role change | revokes all API tokens (abilities were captured at issue time) |
| Login, logout | session regenerated; logout also rotates the CSRF token and discards the encrypted Inertia history key |

**Email verification.** Every account must have a verified email address before it can use the app. On the web, every app page (dashboard, tickets, profile, admin, notifications) sits behind `auth` + `verified`, so a signed-in but unverified user is redirected to the verification notice page, from which they can resend the link; only logout, the notice, the signed verification link and the resend route are reachable without it. On the API, `POST /api/v1/auth/tokens` refuses an unverified account with `403` and the code `email_unverified` (after the password check, so it reveals nothing about unknown emails). The API's `auth:sanctum` routes therefore don't add `verified`: an unverified user can never obtain a token. Seeded demo accounts and users created by an admin are already verified; self-registered customers are not.

Session ending uses the database session table, so it applies when `SESSION_DRIVER=database` (the default). Passwords: 8–72 characters outside production; in production at least 12 with mixed case, numbers, symbols and an uncompromised check.

## 9. Rate limits

| Limiter | Limit | Applies to |
|---|---|---|
| `api` | 60/min per user (per IP for guests) | every `/api/v1` route |
| `uploads` | 20/min per user | ticket creation, replies, import upload, avatar upload (web and API) |
| `api-tokens` | 5/min per email + IP | `POST /api/v1/auth/tokens` |
| `throttle:5,1` | 5/min | login, register, forgot password, reset password |
| `throttle:6,1` | 6/min | profile password change (web and API); email verification link (`GET /email/verify/{id}/{hash}`, also `signed`) and resend (`POST /email/verification-notification`) |
| `throttle:60,1` | 60/min | customer lookup, saved-reply picker |

## 10. Realtime channel authorization

Channels are authorized in `routes/channels.php` through the same policies as the pages, behind the `web` + `auth` + `verified` middleware (`bootstrap/app.php`), so an unverified user cannot join any channel:

| Channel | Rule |
|---|---|
| `App.Models.User.{id}` | the user's own id (carries their notifications and, for customers, changes to their own tickets) |
| `tickets.queue` | `tickets.view.any` |
| `tickets.{ticket}` | `TicketPolicy::view` |
| `tickets.{ticket}.staff` | `TicketPolicy::viewInternal` |
| `presence.tickets.{ticket}` | `TicketPolicy::view`; returns only id, name, avatar URL and side (customer or staff) |

Broadcast payloads carry only `{ticket, kind, at}`. Client events (whispers) are accepted from channel members only. Typing names on the presence channel come from the server-provided member list.

## 11. Other measures

| Concern | Measure |
|---|---|
| Mass assignment | explicit `$fillable`; `number`, `assigned_agent_id`, `status`, `sla_*`, `escalation_level` are never fillable; roles are set only by admin Actions; the saved-reply owner comes from the actor |
| SQL injection | bindings everywhere; FULLTEXT operators stripped from search input; sort columns whitelisted |
| XSS | Vue escapes output; `v-html` is forbidden (checked by a test) |
| CSRF | Laravel's web middleware |
| Self-service routes | profile routes act only on the signed-in user, so they need no policy; the route audit lists them as auth-only |
| Destructive commands | blocked in production (`DB::prohibitDestructiveCommands`) |
| Secrets | none committed; `.env` is gitignored; `.env.example` holds placeholders and local-only Reverb values |

### Deliberate decisions

- **Presence on user summaries.** `UserSummaryResource` includes `last_seen_at`, so a customer sees when the staff on their ticket were last active. It powers "Your agent is active now" and is intended. A customer only ever sees summaries of people on their own tickets (themselves and staff), never of other customers.
- **Avatar URLs reveal staff ids.** Staff avatars are served by id to anyone who can see a ticket the staff member worked on, so staff ids can be enumerated. Staff names are already shown to customers on their tickets, so the id adds nothing worth hiding.

## 12. Known limitations

SLA escalation events (`sla_approaching`, `sla_breached`) are broadcast only on the staff channel of the ticket and the staff queue channel, so customers never learn that an escalation happened.


- Uploaded files are not virus-scanned.
- Agents can read every customer's tickets, by design (TD-011).
- On S3, downloads should move to temporary URLs issued after the policy check.

## 13. Where it is tested

- Route-by-route matrix for every web and API route: `tests/Feature/Authorization/AuthorizationMatrixTest.php`, with `tests/Support/AuthorizationMatrix.php` and `tests/Support/RouteAudit.php`.
- Policies: `tests/Feature/Authorization/TicketPolicyTest.php`, `TicketReplyPolicyTest.php`, `AttachmentPolicyTest.php`, `AdminPoliciesTest.php`.
- Leakage: `tests/Feature/Security/InternalNoteLeakageTest.php`, `tests/Feature/Notifications/InternalNoteEndToEndTest.php`, `tests/Feature/Security/PermissionScopedVisibilityTest.php`.
- Checklist (auth on every route, uploads throttled, no `v-html`, disguised PHP rejected): `tests/Feature/Security/SecurityChecklistTest.php`.
- Channels: `tests/Feature/Realtime/ChannelAuthorizationTest.php`, `PresenceChannelTest.php`.
- Email verification (app pages, broadcasting handshake, signed links, resend throttle, API token refusal): `tests/Feature/Auth/EmailVerificationTest.php`.
