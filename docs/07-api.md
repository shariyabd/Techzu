# 07 · API

The application exposes a versioned REST JSON API under `/api/v1`. It uses the same FormRequests, Actions and Policies as the web pages (TD-001, TD-003), so every business rule and permission applies identically. API responses always go through the Resources in `app/Http/Resources/V1`.

This page is the complete reference: conventions, authentication, errors, and every endpoint with its parameters, examples and error cases. Everything here was checked against the routes, FormRequests, Policies, Actions and Resources in the code.

## Contents

1. [Overview](#1-overview)
2. [Interactive documentation and the OpenAPI export](#2-interactive-documentation-and-the-openapi-export)
3. [Authentication](#3-authentication)
4. [Conventions](#4-conventions)
   - [4.1 Headers](#41-headers) · [4.2 Response envelope](#42-response-envelope) · [4.3 Pagination](#43-pagination) · [4.4 Sorting and filtering](#44-sorting-and-filtering) · [4.5 Dates and times](#45-dates-and-times) · [4.6 Identifiers](#46-identifiers) · [4.7 File uploads](#47-file-uploads) · [4.8 Rate limits](#48-rate-limits)
5. [Errors](#5-errors)
6. [Roles, permissions and who can call what](#6-roles-permissions-and-who-can-call-what)
7. [Enumerations and the status transition table](#7-enumerations-and-the-status-transition-table)
8. [Shared response objects](#8-shared-response-objects)
9. [Endpoints: auth and current user](#9-endpoints-auth-and-current-user)
10. [Endpoints: profile](#10-endpoints-profile)
11. [Endpoints: tickets](#11-endpoints-tickets)
12. [Endpoints: conversation and attachments](#12-endpoints-conversation-and-attachments)
13. [Endpoints: assignment](#13-endpoints-assignment)
14. [Endpoints: categories](#14-endpoints-categories)
15. [Endpoints: customer lookup](#15-endpoints-customer-lookup)
16. [Endpoints: dashboard](#16-endpoints-dashboard)
17. [Endpoints: agent directory](#17-endpoints-agent-directory)
18. [Endpoints: notifications](#18-endpoints-notifications)
19. [Endpoints: saved replies](#19-endpoints-saved-replies)
20. [Endpoints: CSV imports](#20-endpoints-csv-imports)
21. [Endpoints: administration](#21-endpoints-administration)
22. [Typical workflows](#22-typical-workflows)
23. [Realtime is web-only](#23-realtime-is-web-only)
24. [Endpoint index](#24-endpoint-index)

---

## 1. Overview

| Item | Value |
|---|---|
| Base URL (local) | `http://127.0.0.1:8000/api/v1` (in general `{APP_URL}/api/v1`) |
| Versioning | The major version is in the path (`/api/v1`). Breaking changes would ship under a new prefix |
| Format | JSON only. Every `/api/v1` response is JSON, including errors and unknown routes (`ForceJsonResponse` middleware) |
| Authentication | Laravel Sanctum personal access tokens, sent as `Authorization: Bearer <token>` |
| Authorization | Policies on every route, driven by the user's live permissions (section 6) |
| Endpoints | 49 operations on 37 paths (section 24) |

All paths below are relative to the base URL. `php artisan route:list --path=api/v1` prints the live routes.

## 2. Interactive documentation and the OpenAPI export

| Where | What |
|---|---|
| `{APP_URL}/docs/api` | Interactive documentation (Scramble UI), generated from the routes, FormRequests and Resources |
| `{APP_URL}/docs/api.json` | The same OpenAPI 3.1 document as JSON |
| [`docs/api/openapi.json`](api/openapi.json) | A committed export of that document |

- Both URLs are open to everyone when `APP_ENV=local`. In every other environment they require a signed-in web session of a user who may manage users (admins by default); everyone else gets 403.
- After changing the API, regenerate the committed copy:

  ```bash
  php artisan scramble:export --path=docs/api/openapi.json
  ```

  `tests/Feature/Docs/ApiDocsTest.php` fails if any `/api/v1` route is missing from it.
- The generated document infers shapes automatically. Where it and this page differ (for example the conditional `description` and `category_id` rules of `PATCH /tickets/{ticket}`, which Scramble cannot evaluate), this page describes the real behaviour.

## 3. Authentication

The API uses Sanctum personal access tokens. There is no cookie or session authentication for `/api/v1`.

### Flow

1. **Get a token**: `POST /auth/tokens` with `email`, `password` and `device_name`. The response is `201` with `{ token, token_type, abilities }` (section 9.1).
2. **Call the API** with the headers `Accept: application/json` and `Authorization: Bearer <token>`.
3. **Check who you are**: `GET /me` returns the user, their roles and their permission names.
4. **Sign out**: `DELETE /auth/tokens/current` revokes only the token used for that request.

```bash
TOKEN=$(curl -s -X POST http://127.0.0.1:8000/api/v1/auth/tokens \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"agent1@example.com","password":"password","device_name":"cli"}' \
  | jq -r '.data.token')

curl -s http://127.0.0.1:8000/api/v1/me \
  -H "Accept: application/json" -H "Authorization: Bearer $TOKEN"
```

### Rules

- **Wrong credentials** (unknown email or wrong password) give one generic `422` validation error on `email`, so an attacker cannot tell which part was wrong. The check takes at least 200 ms either way.
- **Unverified email**: a user who has not verified their email address gets `403` with `code: "email_unverified"`, and no token is created. Users created by an admin are verified automatically; self-registered customers must click the link in their verification email first.
- **Abilities** are the user's permission names at the moment the token was issued (for example `tickets.view.any`). They are informational: every request is authorized against the user's *live* permissions through the Policies, so a later role or permission change takes effect immediately.
- **Token revocation**: changing your password (`PUT /me/password`) revokes all your other tokens and keeps the current one. An admin resetting a user's password, or changing a user's role, revokes all of that user's tokens. A revoked token gets `401`.
- Tokens do not expire on their own; revoke them with `DELETE /auth/tokens/current`.

Demo accounts (seeded by `DemoSeeder`, password `password` for all): `admin@example.com`, `agent1@example.com` to `agent6@example.com`, `customer1@example.com` and more. See [13-demo-data.md](13-demo-data.md).

## 4. Conventions

### 4.1 Headers

| Header | When | Value |
|---|---|---|
| `Accept` | Always | `application/json` |
| `Authorization` | Every endpoint except `POST /auth/tokens` | `Bearer <token>` |
| `Content-Type` | Requests with a JSON body | `application/json` |
| `Content-Type` | Requests that upload files | `multipart/form-data` (curl sets it, with the boundary, when you use `-F`) |

### 4.2 Response envelope

- A single object is wrapped in `data`: `{"data": {...}}`.
- A list is wrapped in `data`, and paginated lists add `links` and `meta` (section 4.3).
- Some lists add extra top-level keys (the agent directory adds `summary`).
- `204 No Content` responses have an empty body.

### 4.3 Pagination

Paginated lists use Laravel's length-aware paginator:

```json
{
  "data": [ ... ],
  "links": {
    "first": "http://127.0.0.1:8000/api/v1/tickets?page=1",
    "last": "http://127.0.0.1:8000/api/v1/tickets?page=4",
    "prev": null,
    "next": "http://127.0.0.1:8000/api/v1/tickets?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 4,
    "links": [ { "url": null, "label": "&laquo; Previous", "active": false }, "..." ],
    "path": "http://127.0.0.1:8000/api/v1/tickets",
    "per_page": 20,
    "to": 20,
    "total": 72
  }
}
```

- `page` starts at 1.
- `per_page` is an integer from 1 to 100. Larger values give `422`. The default is 20 unless an endpoint says otherwise (import errors: 25). Some admin lists have a fixed page size (categories 20, holidays 50).
- The `links` URLs keep your other query parameters.
- Non-paginated lists (`GET /categories`, `GET /customers/lookup`, `GET /admin/roles`) return only `data`.

### 4.4 Sorting and filtering

- Sort and filter keys come from fixed whitelists. Any other value of `sort`, `scope`, `status[]`, `priority[]`, `sla_state`, `response`, `role` or `availability` is rejected with `422`. Unknown query parameters are ignored.
- Array filters use the bracket form: `status[]=open&status[]=in_progress`.
- Boolean query parameters accept `1`, `0`, `true`, `false`.
- Each list's whitelist is listed with the endpoint.

### 4.5 Dates and times

- Every timestamp in a response is **UTC, ISO-8601** with an explicit offset, for example `2026-11-01T03:00:00+00:00`. `null` means "not set".
- Date-only values (holiday dates, `created_from`/`created_to` filters) use `YYYY-MM-DD`. Date filters are interpreted as whole days **in the business timezone** set in the SLA settings, then converted to UTC.
- Business-hour times in SLA settings use `HH:MM` (24-hour); `24:00` is allowed as an end time.
- SLA clocks are counted in **business seconds** (only time inside business hours and outside holidays counts).

### 4.6 Identifiers

| Resource | Path parameter | Example |
|---|---|---|
| Ticket | `{ticket}` is the **ticket number**, not the database id | `/tickets/TKT-2026-000042` |
| Attachment | `{attachment}` numeric id | `/attachments/15` |
| Notification | `{notification}` UUID | `/notifications/9d3c1e2a-.../read` |
| Import | `{import}` numeric id | `/imports/3` |
| User, category, holiday, saved reply | numeric id | `/admin/users/12` |
| Role | `{role}` role name: `admin`, `agent` or `customer` | `/admin/roles/agent` |

Ticket numbers have the format `{PREFIX}-{YEAR}-{NNNNNN}` (prefix from `TICKET_NUMBER_PREFIX`, default `TKT`; year in the business timezone; a six-digit sequence that restarts each year).

### 4.7 File uploads

Send files as `multipart/form-data`. With curl, use `-F` for every field. Multiple files use the array form `attachments[]`.

| Upload | Field | Allowed types | Max size | Max count |
|---|---|---|---|---|
| Ticket and reply attachments | `attachments[]` | `pdf, png, jpg, jpeg, gif, txt, csv, doc, docx, xls, xlsx, zip` (extension and content type must both match) | 10240 KB each (`ATTACHMENTS_MAX_KB`) | 5 per request (`ATTACHMENTS_MAX_FILES`) |
| Profile picture | `avatar` | `jpg, jpeg, png, webp` images | 2048 KB (`AVATARS_MAX_KB`) | 1; 64 to 4096 px on each side |
| CSV import | `file` | `csv` or `txt` | 51200 KB (`IMPORTS_MAX_UPLOAD_KB`) | 1 |

- Files are stored on a private disk. They are never public; download them through `GET /attachments/{attachment}` with a token.
- PHP only parses multipart bodies on `POST`. To send a file with a `PATCH` (only `PATCH /admin/users/{user}` accepts one), send `POST` with the extra form field `_method=PATCH`.

### 4.8 Rate limits

| Limiter | Applies to | Limit | Keyed by |
|---|---|---|---|
| `api` | Every `/api/v1` route | 60 requests per minute | the user id (the IP address when unauthenticated) |
| `api-tokens` | `POST /auth/tokens` | 5 per minute | the lower-cased email plus the IP address |
| `uploads` | `POST /tickets`, `POST /tickets/{ticket}/replies`, `POST /me/avatar`, `POST /imports` | 20 per minute | the user id |
| fixed `6,1` | `PUT /me/password` | 6 per minute | the user id |
| fixed `60,1` | `GET /customers/lookup` | 60 per minute | the user id |

Limits stack: an upload request counts against both `api` and `uploads`. Going over a limit gives `429` with a `Retry-After` header (seconds); `X-RateLimit-Limit` and `X-RateLimit-Remaining` are sent on throttled routes.

## 5. Errors

### 5.1 Shapes

**Validation error (422)**: Laravel's standard shape. `message` summarises the first error; `errors` lists every failing field (array fields use dot paths such as `attachments.0` or `week.monday.0.end`).

```json
{
  "message": "The subject field is required. (and 1 more error)",
  "errors": {
    "subject": ["The subject field is required."],
    "category_id": ["The category id field is required."]
  }
}
```

**Domain error**: a broken business rule. Branch on `code`, which is stable; `message` is human-readable and may change.

```json
{
  "message": "Ticket TKT-2026-000042 has already been claimed by another agent.",
  "code": "ticket_already_claimed"
}
```

**Other errors** have only a `message`:

| Status | When | Body |
|---|---|---|
| 401 | Missing, invalid or revoked token | `{"message": "Unauthenticated."}` |
| 403 | You can see the resource but may not perform the action | `{"message": "This action is unauthorized."}` |
| 404 | The route or record does not exist, **or it exists but must stay hidden from you** | `{"message": "Not Found"}` (a missing record may give `No query results for model [...]`) |
| 405 | Wrong HTTP method for an existing path | `{"message": "..."}` |
| 409 | Conflict (claim lost to another agent) | domain error shape |
| 422 | Validation failed, or a domain rule refused the change | validation or domain error shape |
| 429 | Rate limit exceeded | `{"message": "Too Many Attempts."}` |
| 500 | Unexpected server error | `{"message": "Server Error"}` |

### 5.2 404 rather than 403 (TD-012)

When a user may not even *see* a resource, the API answers `404`, never `403`, so it does not reveal that the resource exists:

- A customer asking for another customer's ticket (any ticket endpoint: show, update, status, priority, activities, replies, claim, assign).
- An internal attachment requested by someone who cannot read internal notes.
- Another user's notification, another agent's private saved reply, an agent profile for a user who is not staff.

`403` means "you can see it, but you may not do this", for example a customer trying to change the priority of their own ticket, or anyone trying to change a Closed ticket.

### 5.3 Domain error codes

Every subclass of `App\Exceptions\Domain\DomainException`:

| Code | HTTP | Raised by | Meaning |
|---|---|---|---|
| `email_unverified` | 403 | `POST /auth/tokens` | The user has not verified their email address |
| `invalid_status_transition` | 422 | `PATCH /tickets/{ticket}/status` | The target status is not allowed from the current one (section 7.2) |
| `ticket_already_claimed` | 409 | `POST /tickets/{ticket}/claim` | Another agent claimed (or was assigned) the ticket first |
| `agent_unavailable` | 422 | `POST /tickets/{ticket}/claim` | The claiming agent is marked unavailable |
| `agent_not_eligible` | 422 | `POST /tickets/{ticket}/assign` | The target is not an available agent, or does not handle the ticket's category |
| `ticket_closed` | 422 | assign, priority, reply | The ticket finished while the request was in flight (assign or priority on a finished ticket; a reply on a ticket closed concurrently) |
| `internal_note_forbidden` | 403 | `POST /tickets/{ticket}/replies` | Last-line guard when an internal note is attempted without permission (normally the request is refused earlier with a plain 403) |
| `customer_internal_notes_forbidden` | 422 | `PATCH /admin/roles/{role}` | The customer role can never receive `tickets.notes.internal` |
| `cannot_remove_user_management` | 422 | `PATCH /admin/roles/{role}` | The admin role must keep `users.manage` |
| `cannot_change_own_role` | 422 | `PATCH /admin/users/{user}` | An admin tried to change their own role |
| `invalid_sla_settings` | 422 | `PUT /admin/sla` | The SLA settings break a rule (the message names it) |
| `import_header_invalid` | n/a | CSV import job | Never returned over HTTP. A bad CSV header makes the import finish as `failed`, with the problem stored as error row 1 (see section 20) |

## 6. Roles, permissions and who can call what

Authorization always goes through a Policy, which checks a permission and, where relevant, ownership (TD-010). Admins can change which permissions each role has (`PATCH /admin/roles/{role}`), so the table below shows the **seeded defaults**.

### 6.1 Default permissions per role

| Permission | Admin | Agent | Customer |
|---|:-:|:-:|:-:|
| `tickets.create` | ✓ | ✓ | ✓ |
| `tickets.create.on_behalf` | ✓ | ✓ | |
| `tickets.view.any` | ✓ | ✓ | |
| `tickets.view.own` | | | ✓ |
| `tickets.reply` | ✓ | ✓ | ✓ |
| `tickets.notes.internal` | ✓ | ✓ | |
| `tickets.claim` | ✓ | ✓ | |
| `tickets.update.any` | ✓ | | |
| `tickets.update.assigned` | | ✓ | |
| `tickets.update.own` | | | ✓ |
| `tickets.assign.any` | ✓ | | |
| `tickets.handoff.own` | | ✓ | |
| `dashboard.view` | ✓ | ✓ | |
| `imports.manage` | ✓ | | |
| `users.manage` | ✓ | | |
| `categories.manage` | ✓ | | |
| `holidays.manage` | ✓ | | |
| `sla.manage` | ✓ | | |
| `saved_replies.use` | ✓ | ✓ | |
| `saved_replies.manage_shared` | ✓ | | |

### 6.2 Who can call each endpoint group (defaults)

| Group | Admin | Agent | Customer | Rule |
|---|:-:|:-:|:-:|---|
| Auth, `GET /me`, profile | ✓ | ✓ | ✓ | any authenticated user |
| List / show tickets, activities, replies | all tickets | all tickets | own tickets only | `tickets.view.any`, or `tickets.view.own` + ticket owner |
| Create ticket | ✓ (on behalf) | ✓ (on behalf) | ✓ (for self) | `tickets.create`; `customer_email` needs `tickets.create.on_behalf` |
| Update ticket | any | assigned to them | own, while Open | see section 11.4 |
| Change status | any | assigned to them | own, Resolved → Closed/In Progress | see section 11.5 |
| Change priority | any | assigned to them | ✗ | active tickets only |
| Reply | ✓ | ✓ | own tickets | `tickets.reply`; not on Closed tickets |
| Internal note | ✓ | ✓ | ✗ | `tickets.notes.internal` |
| Claim | ✓ | ✓ | ✗ | `tickets.claim`, active, unassigned |
| Assign / hand off | any agent, any category | hand off own tickets within category | ✗ | `tickets.assign.any` or `tickets.handoff.own` |
| Download attachment | ✓ | ✓ | own tickets, non-internal files | ticket visibility + internal rule |
| `GET /categories` | ✓ | ✓ | ✓ | `tickets.create` |
| Customer lookup | ✓ | ✓ | ✗ | `tickets.view.any` or `tickets.create.on_behalf` |
| Dashboard | ✓ | ✓ | ✗ | `dashboard.view` |
| Agent directory | ✓ | ✓ | ✗ | `tickets.view.any` |
| Notifications | ✓ | ✓ | ✓ | `tickets.view.own` or `tickets.view.any` |
| Saved replies | ✓ (personal + team) | ✓ (personal) | ✗ | `saved_replies.use`; team replies need `saved_replies.manage_shared` |
| Imports | ✓ | ✗ | ✗ | `imports.manage` |
| Admin users and roles | ✓ | ✗ | ✗ | `users.manage` |
| Admin categories | ✓ | ✗ | ✗ | `categories.manage` |
| Admin holidays | ✓ | ✗ | ✗ | `holidays.manage` |
| Admin SLA | ✓ | ✗ | ✗ | `sla.manage` |

Customers never receive internal replies, internal attachments, staff-only activity entries (`internal_note`, `sla_approaching`, `escalated`), staff-only notifications or the `escalation_level` field, in any response.

## 7. Enumerations and the status transition table

### 7.1 Enumerations

| Enum | Values |
|---|---|
| Ticket status | `open` (Open), `in_progress` (In Progress), `waiting_for_customer` (Waiting for Customer), `resolved` (Resolved), `closed` (Closed) |
| Active statuses | `open`, `in_progress`, `waiting_for_customer` |
| Finished statuses | `resolved`, `closed` |
| Ticket priority | `low`, `medium`, `high`, `critical` (ranked 1 to 4) |
| SLA state | `within` (Within SLA), `approaching` (Approaching breach), `breached` (Breached), `paused` (Paused), `stopped` (Stopped, for finished tickets) |
| Escalation level (staff only) | `0` none, `1` approaching, `2` breached |
| Role | `admin` (Administrator), `agent` (Support Agent), `customer` (Customer) |
| Import status | `pending`, `processing`, `completed`, `completed_with_errors`, `failed` |
| Saved reply scope | `mine`, `team` |
| Saved reply placeholders | `{customer_name}`, `{customer_first_name}`, `{ticket_number}`, `{ticket_subject}`, `{agent_name}`, `{agent_first_name}` |
| Activity type | `created`, `updated`, `assigned`, `claimed`, `unassigned`, `status_changed`, `priority_changed`, `category_changed`, `replied`, `internal_note`*, `sla_paused`, `sla_resumed`, `sla_approaching`*, `escalated`*, `imported`, `sla_recalculated` (* staff only) |
| Permission | the 20 names in section 6.1 |

Enum fields in responses are objects with `value` (stable, use this in code) and `label` (display text), for example `"status": {"value": "in_progress", "label": "In Progress"}`.

### 7.2 Status transitions

From `App\Enums\TicketStatus::allowedTransitions()`. Any other move gives `422 invalid_status_transition`.

| From | Allowed targets |
|---|---|
| `open` | `in_progress`, `waiting_for_customer`, `resolved` |
| `in_progress` | `waiting_for_customer`, `resolved` |
| `waiting_for_customer` | `in_progress`, `resolved` |
| `resolved` | `closed`, `in_progress` (reopen) |
| `closed` | none (final) |

Side effects:

- Moving to `waiting_for_customer` pauses the SLA clock; leaving it resumes the clock.
- Moving to `resolved` sets `resolved_at`. Reopening (`resolved` → `in_progress`) clears it and restarts the clock with the paused time credited.
- Moving to `closed` sets `closed_at`. A closed ticket accepts no further changes or replies.
- Claiming or assigning an `open` ticket moves it to `in_progress` automatically.
- A public reply by the ticket's customer on a `waiting_for_customer` or `resolved` ticket moves it to `in_progress` automatically.

## 8. Shared response objects

### 8.1 User summary

Used for `customer`, `assigned_agent`, `created_by`, `author`, `causer` and import `user`.

```json
{ "id": 7, "name": "Liam Carter", "avatar_url": "http://127.0.0.1:8000/avatars/7?v=3f2a9c1b0d4e", "last_seen_at": "2026-11-01T02:58:10+00:00" }
```

`avatar_url` is `null` when the user has no picture. The avatar URL accepts the same bearer token.

### 8.2 Category

```json
{ "id": 2, "name": "Technical Support", "slug": "technical-support", "is_active": true }
```

### 8.3 Ticket (full)

Returned by create, show, update, status, priority, claim and assign.

```json
{
  "data": {
    "number": "TKT-2026-000042",
    "subject": "Cannot export invoices to PDF",
    "description": "Since this morning the export button returns an error.",
    "status": { "value": "in_progress", "label": "In Progress" },
    "priority": { "value": "high", "label": "High" },
    "category": { "id": 2, "name": "Technical Support", "slug": "technical-support", "is_active": true },
    "customer": { "id": 21, "name": "Emma Johnson", "avatar_url": null, "last_seen_at": "2026-11-01T02:40:00+00:00" },
    "created_by": { "id": 21, "name": "Emma Johnson", "avatar_url": null, "last_seen_at": "2026-11-01T02:40:00+00:00" },
    "assigned_agent": { "id": 7, "name": "Liam Carter", "avatar_url": null, "last_seen_at": "2026-11-01T02:58:10+00:00" },
    "sla": {
      "state": { "value": "within", "label": "Within SLA" },
      "due_at": "2026-11-02T11:00:00+00:00",
      "warning_at": "2026-11-02T09:00:00+00:00",
      "paused_at": null,
      "clock": { "remaining_seconds": 28800, "running": false, "changes_at": "2026-11-01T09:00:00+00:00", "resumes_at": "2026-11-01T09:00:00+00:00" }
    },
    "escalation_level": 0,
    "created_at": "2026-11-01T03:00:00+00:00",
    "updated_at": "2026-11-01T03:05:12+00:00",
    "resolved_at": null,
    "closed_at": null,
    "can": {
      "update": true,
      "editDescription": false,
      "changeStatus": true,
      "changePriority": true,
      "claim": false,
      "assign": true,
      "assignOutsideCategory": false,
      "reply": true,
      "addInternalNote": true,
      "allowedStatuses": ["waiting_for_customer", "resolved"]
    }
  }
}
```

- `assigned_agent` is `null` when unassigned.
- `escalation_level` is present only for users with `tickets.view.any` (staff).
- `sla.clock` is `null` for finished tickets. `remaining_seconds` is business seconds and goes negative once breached. `running` is `false` outside business hours or while paused; `resumes_at` says when a stopped clock starts again (`null` while paused for the customer).
- `can` lists what the **calling user** may do right now, so clients can show or hide actions. `allowedStatuses` already combines the transition table with the user's permissions.
- `GET /tickets/{ticket}` also includes `attachments` (section 8.5): the files attached to the ticket itself, without reply files, and without internal files for customers.

### 8.4 Ticket (list item)

Returned by `GET /tickets` and in the dashboard's `my_tickets`.

```json
{
  "number": "TKT-2026-000042",
  "subject": "Cannot export invoices to PDF",
  "status": { "value": "open", "label": "Open" },
  "priority": { "value": "high", "label": "High" },
  "category": { "id": 2, "name": "Technical Support", "slug": "technical-support", "is_active": true },
  "customer": { "id": 21, "name": "Emma Johnson", "avatar_url": null, "last_seen_at": null },
  "assigned_agent": null,
  "sla": {
    "state": { "value": "approaching", "label": "Approaching breach" },
    "due_at": "2026-11-01T11:00:00+00:00",
    "clock": { "remaining_seconds": 1800, "running": true, "changes_at": "2026-11-01T17:00:00+00:00", "resumes_at": null }
  },
  "escalation_level": 1,
  "replies_count": 3,
  "has_agent_response": false,
  "created_at": "2026-11-01T03:00:00+00:00",
  "updated_at": "2026-11-01T03:00:00+00:00"
}
```

`replies_count` counts only the replies the caller can see. `has_agent_response` is `true` once someone other than the customer has posted a public reply. The dashboard's `my_tickets` items omit `category`, `customer`, `assigned_agent`, `replies_count` and `has_agent_response`.

### 8.5 Attachment

```json
{
  "id": 15,
  "original_name": "export-error.png",
  "mime_type": "image/png",
  "size_bytes": 48213,
  "is_internal": false,
  "download_url": "http://127.0.0.1:8000/api/v1/attachments/15",
  "preview_url": null,
  "created_at": "2026-11-01T03:00:00+00:00"
}
```

`preview_url` is always `null` over the API; use `download_url` with your token.

### 8.6 Reply

```json
{
  "id": 88,
  "body": "Thanks, we are looking into it.",
  "is_internal": false,
  "author": { "id": 7, "name": "Liam Carter", "avatar_url": null, "last_seen_at": "2026-11-01T03:05:00+00:00" },
  "attachments": [],
  "created_at": "2026-11-01T03:05:12+00:00"
}
```

---

## 9. Endpoints: auth and current user

### 9.1 `POST /auth/tokens`: issue a token

Exchanges credentials for a bearer token. **No authentication.** Rate limit: 5 per minute per email + IP.

Body (JSON):

| Field | Type | Required | Rules |
|---|---|---|---|
| `email` | string | yes | valid email, max 255 |
| `password` | string | yes | max 72 |
| `device_name` | string | yes | max 255; becomes the token's name |

```bash
curl -s -X POST http://127.0.0.1:8000/api/v1/auth/tokens \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"agent1@example.com","password":"password","device_name":"cli"}'
```

`201 Created`:

```json
{
  "data": {
    "token": "5|q3Vf0nO7Yb1rT2c...",
    "token_type": "Bearer",
    "abilities": ["tickets.create", "tickets.create.on_behalf", "tickets.view.any", "tickets.reply", "tickets.notes.internal", "tickets.claim", "tickets.update.assigned", "tickets.handoff.own", "dashboard.view", "saved_replies.use"]
  }
}
```

Errors:

| Status | When |
|---|---|
| 422 | missing fields, or wrong email/password: `{"message": "These credentials do not match our records.", "errors": {"email": ["These credentials do not match our records."]}}` |
| 403 `email_unverified` | correct credentials but the email address is not verified |
| 429 | more than 5 attempts in a minute for this email + IP |

### 9.2 `DELETE /auth/tokens/current`: revoke the current token

Any authenticated user. Revokes only the token sent with this request; other tokens keep working.

```bash
curl -s -X DELETE http://127.0.0.1:8000/api/v1/auth/tokens/current \
  -H "Accept: application/json" -H "Authorization: Bearer <token>"
```

`204 No Content`. Errors: `401` without a valid token.

### 9.3 `GET /me`: the current user

Any authenticated user.

```bash
curl -s http://127.0.0.1:8000/api/v1/me -H "Accept: application/json" -H "Authorization: Bearer <token>"
```

`200 OK`:

```json
{
  "data": {
    "id": 21,
    "name": "Emma Johnson",
    "email": "customer1@example.com",
    "avatar_url": null,
    "roles": ["customer"],
    "permissions": ["tickets.create", "tickets.view.own", "tickets.reply", "tickets.update.own"]
  }
}
```

`permissions` are the live permission names (from the user's roles and any direct grants).

## 10. Endpoints: profile

All four endpoints act on the token's own user and are open to every authenticated user.

### 10.1 `PATCH /me/profile`: change your name

| Field | Type | Required | Rules |
|---|---|---|---|
| `name` | string | yes | max 255; surrounding spaces are trimmed |

```bash
curl -s -X PATCH http://127.0.0.1:8000/api/v1/me/profile \
  -H "Accept: application/json" -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"name":"Emma J. Johnson"}'
```

`200 OK` with the same body as `GET /me`. Errors: `422`.

### 10.2 `PUT /me/password`: change your password

Rate limit: 6 per minute.

| Field | Type | Required | Rules |
|---|---|---|---|
| `current_password` | string | yes | must match your current password |
| `password` | string | yes | 8 to 72 characters (production: at least 12, mixed case, letters, numbers, symbols, not found in known breaches); must differ from `current_password` |
| `password_confirmation` | string | yes | must equal `password` |

```bash
curl -s -X PUT http://127.0.0.1:8000/api/v1/me/password \
  -H "Accept: application/json" -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"current_password":"password","password":"n3w-Secret-pass","password_confirmation":"n3w-Secret-pass"}'
```

`204 No Content`. All your **other** API tokens and web sessions are revoked; the token used for this call keeps working. Errors: `422` (wrong current password, confirmation mismatch, too short, same as current), `429`.

### 10.3 `POST /me/avatar`: upload a profile picture

Multipart. Rate limit: `uploads` (20 per minute).

| Field | Type | Required | Rules |
|---|---|---|---|
| `avatar` | file | yes | image, `jpg`/`jpeg`/`png`/`webp`, max 2048 KB, 64 to 4096 px wide and high |

```bash
curl -s -X POST http://127.0.0.1:8000/api/v1/me/avatar \
  -H "Accept: application/json" -H "Authorization: Bearer <token>" \
  -F "avatar=@./me.png"
```

`200 OK` with the `GET /me` body; `avatar_url` now points to the resized picture (256 px). Errors: `422` (not an image, wrong type, too large, dimensions out of range).

### 10.4 `DELETE /me/avatar`: remove the profile picture

```bash
curl -s -X DELETE http://127.0.0.1:8000/api/v1/me/avatar -H "Accept: application/json" -H "Authorization: Bearer <token>"
```

`200 OK` with the `GET /me` body and `"avatar_url": null`.

---

## 11. Endpoints: tickets

### 11.1 `GET /tickets`: list, filter and search tickets

Who: anyone with `tickets.view.any` (all tickets) or `tickets.view.own` (only tickets where they are the customer). Users with neither get `403`. Paginated.

Query parameters:

| Name | Type | Default | Allowed values / rules |
|---|---|---|---|
| `scope` | string | `active` | `active` (Open, In Progress, Waiting for Customer), `resolved` (Resolved, Closed), `all`. Forced to `all` for a ticket-number lookup (below) |
| `number` | string | | max 32. Ticket-number prefix match, case-insensitive. A value starting with a digit gets the prefix added: `2026-0000` matches `TKT-2026-0000…` |
| `q` | string | | max 200. Full-text search over subject and description (MySQL boolean mode). If `q` looks like a ticket number (`TKT-2026-000042` or `2026-42`), it is treated as a number lookup instead |
| `status[]` | array of string | | ticket status values (section 7.1). Applied within the scope |
| `priority[]` | array of string | | `low`, `medium`, `high`, `critical` |
| `category_id` | integer | | an existing category id |
| `assigned_agent_id` | integer | | tickets assigned to this user |
| `unassigned` | boolean | `false` | `true` keeps only tickets with no assignee |
| `customer_id` | integer | | tickets of this customer (customers only ever see their own anyway) |
| `created_from` | date `YYYY-MM-DD` | | created on or after this day (business timezone) |
| `created_to` | date `YYYY-MM-DD` | | created on or before this day; must be on or after `created_from` |
| `sla_state` | string | | `within`, `approaching`, `breached`, `paused`, `stopped` |
| `response` | string | | `unresponded` (no public reply from anyone but the customer yet), `awaiting` (the latest public reply is the customer's, so the customer is awaiting a reply) |
| `sort` | string | `-created_at` | `-created_at` newest first, `created_at` oldest first, `-updated_at` recently updated, `updated_at` least recently updated, `sla_due_at` due soonest, `-sla_due_at` due latest, `-priority` highest priority, `priority` lowest priority |
| `per_page` | integer | 20 | 1 to 100 |
| `page` | integer | 1 | ≥ 1 |

**Ticket-number lookup.** When `number` is filled, or `q` looks like a ticket number, the server switches `scope` to `all` so finished tickets are found too, and drops an `assigned_agent_id` equal to the caller's own id (so a "my tickets" view does not hide the ticket being looked up). Customers still only find their own tickets.

```bash
curl -s -G http://127.0.0.1:8000/api/v1/tickets \
  -H "Accept: application/json" -H "Authorization: Bearer <token>" \
  --data-urlencode "status[]=open" --data-urlencode "status[]=in_progress" \
  --data-urlencode "priority[]=critical" \
  --data-urlencode "sla_state=approaching" \
  --data-urlencode "sort=sla_due_at" --data-urlencode "per_page=25"

curl -s -G http://127.0.0.1:8000/api/v1/tickets \
  -H "Accept: application/json" -H "Authorization: Bearer <token>" \
  --data-urlencode "q=TKT-2026-000042"

curl -s -G http://127.0.0.1:8000/api/v1/tickets \
  -H "Accept: application/json" -H "Authorization: Bearer <token>" \
  --data-urlencode "unassigned=1" --data-urlencode "response=unresponded"
```

`200 OK`: a paginated list of ticket list items (section 8.4).

Errors: `401`, `403` (no ticket view permission), `422` (unknown `scope`/`sort`/`status`/`priority`/`sla_state`/`response` value, bad date, `created_to` before `created_from`, `per_page` above 100, unknown `category_id`).

### 11.2 `POST /tickets`: create a ticket

Who: `tickets.create` (all roles by default). Customers create tickets for themselves. Staff with `tickets.create.on_behalf` create tickets for a customer and **must** send `customer_email`. Rate limit: `uploads`.

Body (JSON, or multipart when attaching files):

| Field | Type | Required | Rules |
|---|---|---|---|
| `subject` | string | yes | max 255 |
| `description` | string | yes | max 20000 |
| `priority` | string | yes | `low`, `medium`, `high`, `critical` |
| `category_id` | integer | yes | an existing **active** category |
| `customer_email` | string | staff: yes; customers: forbidden | the email of an existing user with the customer role |
| `attachments[]` | files | no | up to 5 files, 10240 KB each, allowed types in section 4.7 |

The server sets the number, status (`open`), SLA deadlines from the priority's target, and the creator. Automatic assignment (if enabled with `TICKETS_AUTO_ASSIGN`) runs in the background afterwards, so the response normally shows `assigned_agent: null`.

```bash
curl -s -X POST http://127.0.0.1:8000/api/v1/tickets \
  -H "Accept: application/json" -H "Authorization: Bearer <customer-token>" \
  -F "subject=Cannot export invoices to PDF" \
  -F "description=Since this morning the export button returns an error." \
  -F "priority=high" \
  -F "category_id=2" \
  -F "attachments[]=@./export-error.png" \
  -F "attachments[]=@./invoice-123.pdf"

curl -s -X POST http://127.0.0.1:8000/api/v1/tickets \
  -H "Accept: application/json" -H "Authorization: Bearer <agent-token>" -H "Content-Type: application/json" \
  -d '{"subject":"Password reset email not arriving","description":"Customer called; reset email never arrives.","priority":"medium","category_id":4,"customer_email":"customer2@example.com"}'
```

`201 Created`: the full ticket (section 8.3) with `status.value = "open"`.

Errors:

| Status | When |
|---|---|
| 403 | no `tickets.create` |
| 422 | missing or invalid fields; inactive or unknown category; more than 5 files; a file too large or of a disallowed type; `customer_email` sent by a customer ("The customer email field is prohibited."); staff without `customer_email`; `customer_email` not a customer ("The selected customer does not exist.") |
| 429 | over the `uploads` limit |

### 11.3 `GET /tickets/{ticket}`: show a ticket

Who: anyone who can see the ticket. A customer asking for someone else's ticket gets `404`.

```bash
curl -s http://127.0.0.1:8000/api/v1/tickets/TKT-2026-000042 \
  -H "Accept: application/json" -H "Authorization: Bearer <token>"
```

`200 OK`: the full ticket (section 8.3) plus `attachments` (files attached to the ticket itself; customers do not get internal files). Replies are fetched separately (section 12.1). Errors: `404`.

### 11.4 `PATCH /tickets/{ticket}`: update subject, description or category

Who and what:

| Caller | Can change | Condition |
|---|---|---|
| Admin (`tickets.update.any`) | `subject`, `category_id` | ticket not Closed; `category_id` only while the ticket is active |
| Assigned agent (`tickets.update.assigned`) | `subject`, `category_id` | same, and only on tickets assigned to them |
| Customer who owns the ticket (`tickets.update.own`) | `subject`, `description` | only while the ticket is `open` |

Body (JSON; send only the fields you change):

| Field | Type | Required | Rules |
|---|---|---|---|
| `subject` | string | no | max 255, not empty if sent |
| `description` | string | no | max 20000; **only** for the customer while Open, otherwise `422 prohibited` |
| `category_id` | integer | no | an existing active category; **only** for staff who manage the ticket, otherwise `422 prohibited` |

Unchanged values are ignored; when nothing changes, nothing is recorded. Each real change is written to the activity timeline.

```bash
curl -s -X PATCH http://127.0.0.1:8000/api/v1/tickets/TKT-2026-000042 \
  -H "Accept: application/json" -H "Authorization: Bearer <agent-token>" -H "Content-Type: application/json" \
  -d '{"subject":"PDF export fails for invoices over 50 lines","category_id":3}'
```

`200 OK`: the full ticket. Errors: `404` (not visible), `403` (not allowed to update, ticket Closed, customer on a non-Open ticket, agent not assigned), `422` (validation, prohibited field).

### 11.5 `PATCH /tickets/{ticket}/status`: change the status

Who:

- Staff who manage the ticket: admins (`tickets.update.any`) on any ticket; agents (`tickets.update.assigned`) on tickets assigned to them. Any target allowed by the transition table.
- The customer who owns the ticket (`tickets.update.own`): only when it is `resolved`, and only to `closed` (accept the resolution) or `in_progress` (reopen).
- Nobody can change a `closed` ticket.

| Field | Type | Required | Rules |
|---|---|---|---|
| `status` | string | yes | a ticket status value (section 7.1) |

```bash
curl -s -X PATCH http://127.0.0.1:8000/api/v1/tickets/TKT-2026-000042/status \
  -H "Accept: application/json" -H "Authorization: Bearer <agent-token>" -H "Content-Type: application/json" \
  -d '{"status":"waiting_for_customer"}'
```

`200 OK`: the full ticket with the new status (and `sla.paused_at` set when moving to `waiting_for_customer`).

Errors:

| Status | When |
|---|---|
| 404 | ticket not visible |
| 403 | caller does not manage the ticket; ticket is Closed; customer on a non-Resolved ticket or with a target other than `closed`/`in_progress` |
| 422 | unknown status value |
| 422 `invalid_status_transition` | the move is not in the transition table, for example `resolved` → `waiting_for_customer`: `{"message": "A ticket cannot move from Resolved to Waiting for Customer.", "code": "invalid_status_transition"}` |

### 11.6 `PATCH /tickets/{ticket}/priority`: change the priority

Who: staff who manage the ticket (admins on any ticket, agents on tickets assigned to them), and only while the ticket is active. Customers cannot change priority. The SLA due and warning times are recalculated from the new priority's target.

| Field | Type | Required | Rules |
|---|---|---|---|
| `priority` | string | yes | `low`, `medium`, `high`, `critical` |

```bash
curl -s -X PATCH http://127.0.0.1:8000/api/v1/tickets/TKT-2026-000042/priority \
  -H "Accept: application/json" -H "Authorization: Bearer <admin-token>" -H "Content-Type: application/json" \
  -d '{"priority":"critical"}'
```

`200 OK`: the full ticket with new `priority` and `sla` times. Sending the current priority is a no-op. Errors: `404`, `403` (customer, unassigned agent, finished ticket), `422` (bad value), `422 ticket_closed` (ticket finished concurrently).

### 11.7 `GET /tickets/{ticket}/activities`: the activity timeline

Who: anyone who can see the ticket. Newest first. Customers do not receive staff-only entries (`internal_note`, `sla_approaching`, `escalated`).

| Query | Type | Default | Rules |
|---|---|---|---|
| `per_page` | integer | 20 | clamped to 1..100 |
| `page` | integer | 1 | |

```bash
curl -s "http://127.0.0.1:8000/api/v1/tickets/TKT-2026-000042/activities?per_page=50" \
  -H "Accept: application/json" -H "Authorization: Bearer <token>"
```

`200 OK` (paginated):

```json
{
  "data": [
    {
      "id": 310,
      "type": "status_changed",
      "description": "Status changed from Open to In Progress",
      "properties": { "from": "open", "to": "in_progress" },
      "causer": { "id": 7, "name": "Liam Carter", "avatar_url": null, "last_seen_at": "2026-11-01T03:05:00+00:00" },
      "created_at": "2026-11-01T03:05:00+00:00"
    },
    {
      "id": 309,
      "type": "claimed",
      "description": "Claimed by Liam Carter",
      "properties": { "to": { "id": 7, "name": "Liam Carter" } },
      "causer": { "id": 7, "name": "Liam Carter", "avatar_url": null, "last_seen_at": "2026-11-01T03:05:00+00:00" },
      "created_at": "2026-11-01T03:05:00+00:00"
    }
  ],
  "links": { "...": "..." },
  "meta": { "current_page": 1, "per_page": 50, "total": 2, "...": "..." }
}
```

`type` is an activity type (section 7.1). `causer` is `null` for system actions (SLA scan, imports, automatic assignment). `properties` varies by type. Errors: `404`.

---

## 12. Endpoints: conversation and attachments

### 12.1 `GET /tickets/{ticket}/replies`: list replies

Who: anyone who can see the ticket. Oldest first. Customers get only public replies and only non-internal attachments; staff with `tickets.notes.internal` also get internal notes (`is_internal: true`).

| Query | Type | Default | Rules |
|---|---|---|---|
| `per_page` | integer | 20 | 1 to 100 |
| `page` | integer | 1 | ≥ 1 |

```bash
curl -s "http://127.0.0.1:8000/api/v1/tickets/TKT-2026-000042/replies?per_page=50" \
  -H "Accept: application/json" -H "Authorization: Bearer <token>"
```

`200 OK`: a paginated list of replies (section 8.6), each with `author` and `attachments`. Errors: `404` (ticket not visible), `422` (bad `per_page`).

### 12.2 `POST /tickets/{ticket}/replies`: reply or add an internal note

Who: `tickets.reply` on a ticket you can see that is not Closed. Internal notes (`is_internal: true`) also need `tickets.notes.internal` (agents and admins). Rate limit: `uploads`.

Body (JSON, or multipart with files):

| Field | Type | Required | Rules |
|---|---|---|---|
| `body` | string | yes | max 20000 |
| `is_internal` | boolean | no, default `false` | `true` makes a staff-only note; its attachments are internal too |
| `attachments[]` | files | no | up to 5, 10240 KB each, allowed types in section 4.7 |

Effects:

- A public reply by the ticket's customer on a `waiting_for_customer` or `resolved` ticket moves it to `in_progress` (resuming or reopening the SLA clock).
- A public reply touches the ticket's `updated_at`; an internal note does not.
- Notifications go out in the background; customers are never notified about internal notes.

```bash
curl -s -X POST http://127.0.0.1:8000/api/v1/tickets/TKT-2026-000042/replies \
  -H "Accept: application/json" -H "Authorization: Bearer <agent-token>" \
  -F "body=Could you send the invoice number that fails?" \
  -F "attachments[]=@./steps.pdf"

curl -s -X POST http://127.0.0.1:8000/api/v1/tickets/TKT-2026-000042/replies \
  -H "Accept: application/json" -H "Authorization: Bearer <agent-token>" -H "Content-Type: application/json" \
  -d '{"body":"Reproduced on staging; looks like the 50-line limit in the PDF template.","is_internal":true}'
```

`201 Created`: the reply (section 8.6), with `author` and `attachments`.

Errors:

| Status | When |
|---|---|
| 404 | ticket not visible (another customer's ticket) |
| 403 | no `tickets.reply`; ticket is Closed; `is_internal: true` without `tickets.notes.internal` (for example a customer) |
| 403 `internal_note_forbidden` | rare race: permission lost between the check and the write |
| 422 | missing `body`, too many files, a file too large or of a disallowed type |
| 422 `ticket_closed` | the ticket was closed while the reply was being saved |
| 429 | over the `uploads` limit |

### 12.3 `GET /attachments/{attachment}`: download an attachment

Who: anyone who can see the attachment's ticket. Internal attachments additionally need `tickets.notes.internal`; others get `404`.

```bash
curl -s -OJ http://127.0.0.1:8000/api/v1/attachments/15 \
  -H "Authorization: Bearer <token>"
```

`200 OK`: the file as a download (`Content-Disposition: attachment; filename="export-error.png"`), with its stored `Content-Type` and `X-Content-Type-Options: nosniff`. Errors: `401`, `404` (unknown id, ticket not visible, or internal file for a customer).

---

## 13. Endpoints: assignment

### 13.1 `POST /tickets/{ticket}/claim`: claim an unassigned ticket

Who: `tickets.claim` (agents and admins) on an active ticket. The claim is atomic: if two agents claim at once, exactly one wins. Claiming an `open` ticket moves it to `in_progress`. No body.

```bash
curl -s -X POST http://127.0.0.1:8000/api/v1/tickets/TKT-2026-000042/claim \
  -H "Accept: application/json" -H "Authorization: Bearer <agent-token>"
```

`200 OK`: the full ticket with `assigned_agent` set to the caller.

Errors:

| Status | When |
|---|---|
| 404 | ticket not visible |
| 403 | no `tickets.claim` (customers), or the ticket is not active |
| 409 `ticket_already_claimed` | the ticket already has an assignee: `{"message": "Ticket TKT-2026-000042 has already been claimed by another agent.", "code": "ticket_already_claimed"}` |
| 422 `agent_unavailable` | the caller is marked unavailable |

### 13.2 `POST /tickets/{ticket}/assign`: assign or hand off

Who:

- Admins (`tickets.assign.any`): assign or reassign any active ticket to any available agent, optionally outside the agent's categories.
- Agents (`tickets.handoff.own`): hand off an active ticket **currently assigned to them** to another available agent who handles the ticket's category.

Assigning an `open` ticket moves it to `in_progress`. Assigning to the current assignee is a no-op.

| Field | Type | Required | Rules |
|---|---|---|---|
| `agent_id` | integer | yes | an existing user id; must be an available agent (role `agent`, `is_available = true`) |
| `outside_category` | boolean | no, default `false` | `true` skips the category check; requires `tickets.assign.any` |

```bash
curl -s -X POST http://127.0.0.1:8000/api/v1/tickets/TKT-2026-000042/assign \
  -H "Accept: application/json" -H "Authorization: Bearer <admin-token>" -H "Content-Type: application/json" \
  -d '{"agent_id":9,"outside_category":true}'
```

`200 OK`: the full ticket with the new `assigned_agent`.

Errors:

| Status | When |
|---|---|
| 404 | ticket not visible, or `agent_id` user vanished |
| 403 | customer; agent handing off a ticket not assigned to them; `outside_category: true` without `tickets.assign.any`; ticket not active |
| 422 | missing or unknown `agent_id` |
| 422 `agent_not_eligible` | target is not an available agent (`"Noah Williams is not an available agent."`) or does not handle the category (`"Priya Sharma does not handle this ticket's category."`) |
| 422 `ticket_closed` | the ticket finished concurrently |

There is no "unassign" endpoint. Tickets become unassigned when an admin changes an agent's role away from agent.

---

## 14. Endpoints: categories

### 14.1 `GET /categories`: active categories

Who: `tickets.create` (all roles by default). Use it to fill the category picker when creating a ticket. Not paginated (up to 500), sorted by name, active categories only.

```bash
curl -s http://127.0.0.1:8000/api/v1/categories -H "Accept: application/json" -H "Authorization: Bearer <token>"
```

`200 OK`:

```json
{ "data": [
  { "id": 3, "name": "Billing", "slug": "billing", "is_active": true },
  { "id": 2, "name": "Technical Support", "slug": "technical-support", "is_active": true }
] }
```

Category management is under `/admin/categories` (section 21.3).

## 15. Endpoints: customer lookup

### 15.1 `GET /customers/lookup`: find customers

Who: `tickets.view.any` or `tickets.create.on_behalf` (agents and admins). Rate limit: 60 per minute. Typeahead for choosing a customer, for example before creating a ticket on their behalf or filtering by `customer_id`.

| Query | Type | Required | Rules |
|---|---|---|---|
| `q` | string | no | max 100; prefix match on name or email |
| `id` | integer | no | ≥ 1; return this customer only |

Without `q` and `id` the result is empty. At most 10 customers, sorted by name. Only users with the customer role are returned. Not paginated.

```bash
curl -s -G http://127.0.0.1:8000/api/v1/customers/lookup \
  -H "Accept: application/json" -H "Authorization: Bearer <agent-token>" --data-urlencode "q=emma"
```

`200 OK`:

```json
{ "data": [ { "id": 21, "name": "Emma Johnson", "email": "customer1@example.com" } ] }
```

Errors: `403` (customers), `422`, `429`.

## 16. Endpoints: dashboard

### 16.1 `GET /dashboard`: totals and breakdowns

Who: `dashboard.view` (agents and admins). Team-wide numbers are cached for up to 60 seconds (`DASHBOARD_CACHE_TTL_SECONDS`). `my_tickets` is the caller's five active assigned tickets with the earliest due time.

```bash
curl -s http://127.0.0.1:8000/api/v1/dashboard -H "Accept: application/json" -H "Authorization: Bearer <agent-token>"
```

`200 OK`:

```json
{
  "data": {
    "totals": { "open": 48, "unassigned": 6, "critical": 4, "breached": 3, "approaching": 5, "resolved": 120 },
    "sla_health": { "within": 34, "approaching": 5, "breached": 3, "paused": 6 },
    "by_status": [
      { "status": "open", "count": 12 },
      { "status": "in_progress", "count": 30 },
      { "status": "waiting_for_customer", "count": 6 }
    ],
    "by_priority": [
      { "priority": "critical", "count": 4 },
      { "priority": "high", "count": 11 },
      { "priority": "medium", "count": 22 },
      { "priority": "low", "count": 11 }
    ],
    "by_agent": [
      { "agent_id": 7, "name": "Liam Carter", "avatar_url": null, "active": 9, "breached": 1 }
    ],
    "trend": [
      { "date": "2026-10-19", "created": 7, "resolved": 5 },
      { "date": "2026-11-01", "created": 3, "resolved": 1 }
    ],
    "my_tickets": [
      {
        "number": "TKT-2026-000042",
        "subject": "Cannot export invoices to PDF",
        "status": { "value": "in_progress", "label": "In Progress" },
        "priority": { "value": "high", "label": "High" },
        "sla": { "state": { "value": "within", "label": "Within SLA" }, "due_at": "2026-11-02T11:00:00+00:00", "clock": { "remaining_seconds": 28800, "running": false, "changes_at": "2026-11-01T09:00:00+00:00", "resumes_at": "2026-11-01T09:00:00+00:00" } },
        "escalation_level": 0,
        "created_at": "2026-11-01T03:00:00+00:00",
        "updated_at": "2026-11-01T03:05:12+00:00"
      }
    ]
  }
}
```

- `totals.open`, `unassigned`, `critical`, `breached`, `approaching` count active tickets. `totals.resolved` counts tickets resolved in the last 30 days (`DASHBOARD_RESOLVED_WINDOW_DAYS`).
- `by_status` and `by_priority` cover active tickets. `trend` has one entry per day for the last 14 days (`DASHBOARD_TREND_DAYS`), dates in the business timezone.

Errors: `403` (customers).

## 17. Endpoints: agent directory

### 17.1 `GET /agents`: list staff with workload numbers

Who: `tickets.view.any` (agents and admins). Paginated, with an extra top-level `summary`.

| Query | Type | Default | Allowed values / rules |
|---|---|---|---|
| `q` | string | | max 100; prefix match on name or email |
| `role` | string | `agent` | `agent`, `admin`, `all` (agents and admins) |
| `availability` | string | | `available`, `unavailable` |
| `category_id` | integer | | agents who handle this category |
| `sort` | string | `name` | `name` (A–Z), `-name` (Z–A), `-active` (most active tickets), `-unresponded` (most unresponded), `-breached` (most SLA breaches) |
| `per_page` | integer | 20 | 1 to 100 |
| `page` | integer | 1 | |

```bash
curl -s -G http://127.0.0.1:8000/api/v1/agents \
  -H "Accept: application/json" -H "Authorization: Bearer <agent-token>" \
  --data-urlencode "availability=available" --data-urlencode "sort=-active"
```

`200 OK`:

```json
{
  "data": [
    {
      "id": 7,
      "name": "Liam Carter",
      "email": "agent1@example.com",
      "avatar_url": null,
      "role": "agent",
      "is_available": true,
      "last_seen_at": "2026-11-01T02:58:10+00:00",
      "categories": [ { "id": 3, "name": "Billing" }, { "id": 2, "name": "Technical Support" } ],
      "stats": { "total_assigned": 41, "active": 9, "unresponded": 2, "awaiting_reply": 3, "breached": 1, "resolved_recent": 18 }
    }
  ],
  "links": { "...": "..." },
  "meta": { "...": "..." },
  "summary": { "agents": 6, "available": 5, "unresponded": 7, "breached": 3 }
}
```

`stats.resolved_recent` counts the last 30 days. `summary` covers all users with the agent role, regardless of filters. Errors: `403` (customers), `422`.

### 17.2 `GET /agents/{user}`: one staff member

Who: `tickets.view.any`. `{user}` must be an agent or admin; any other user id gives `404`.

```bash
curl -s http://127.0.0.1:8000/api/v1/agents/7 -H "Accept: application/json" -H "Authorization: Bearer <agent-token>"
```

`200 OK`: `{"data": { ...one directory entry as above... }}`. Errors: `403`, `404`.

## 18. Endpoints: notifications

Who: anyone with `tickets.view.own` or `tickets.view.any` (all roles by default). Each user sees only their own notifications. Staff-only notification types are filtered out for users who cannot read internal notes.

### 18.1 `GET /notifications`: your inbox

Newest first. Paginated.

| Query | Type | Default | Rules |
|---|---|---|---|
| `unread` | boolean | `false` | `true` returns only unread notifications |
| `per_page` | integer | 20 | 1 to 100 |
| `page` | integer | 1 | |

```bash
curl -s "http://127.0.0.1:8000/api/v1/notifications?unread=1" \
  -H "Accept: application/json" -H "Authorization: Bearer <token>"
```

`200 OK`:

```json
{
  "data": [
    {
      "id": "9d3c1e2a-6b7f-4c1d-9a51-2f0e7d1b6c43",
      "type": "TicketRepliedNotification",
      "ticket_number": "TKT-2026-000042",
      "title": "New reply on TKT-2026-000042",
      "body": "Could you send the invoice number that fails?",
      "url": "http://127.0.0.1:8000/tickets/TKT-2026-000042",
      "read_at": null,
      "created_at": "2026-11-01T03:06:00+00:00"
    }
  ],
  "links": { "...": "..." },
  "meta": { "...": "..." }
}
```

`type` is one of `TicketCreatedNotification`, `TicketAssignedNotification`, `TicketNeedsAssignmentNotification`, `TicketRepliedNotification`, `InternalNoteAddedNotification`, `TicketResolvedNotification`, `SlaApproachingNotification`, `SlaBreachedNotification`, `TicketImportFinishedNotification`, `ImportedTicketsAssignedNotification`. `title` and `body` are display text and may change; `url` points to the web page.

### 18.2 `POST /notifications/{notification}/read`: mark one as read

`{notification}` is the notification UUID. No body.

```bash
curl -s -X POST http://127.0.0.1:8000/api/v1/notifications/9d3c1e2a-6b7f-4c1d-9a51-2f0e7d1b6c43/read \
  -H "Accept: application/json" -H "Authorization: Bearer <token>"
```

`200 OK`: the notification with `read_at` set. Errors: `404` (not a UUID, or not your notification).

### 18.3 `POST /notifications/read-all`: mark all as read

```bash
curl -s -X POST http://127.0.0.1:8000/api/v1/notifications/read-all \
  -H "Accept: application/json" -H "Authorization: Bearer <token>"
```

`204 No Content`.

## 19. Endpoints: saved replies

Canned answers for staff. Who: `saved_replies.use` (agents and admins). A reply is either **personal** (`scope: "mine"`, visible only to its owner) or **team** (`scope: "team"`, visible to all staff). Creating, editing or deleting team replies needs `saved_replies.manage_shared` (admins). The body may contain the placeholders listed in section 7.1; any other `{placeholder}` is rejected. Placeholders are filled in by the web composer; the API stores and returns them as written.

### 19.1 `GET /saved-replies`: list

| Query | Type | Default | Rules |
|---|---|---|---|
| `q` | string | | max 100; searches title and body |
| `scope` | string | both | `mine` (only yours), `team` (only team); omitted = yours plus team |
| `per_page` | integer | 20 | 1 to 100 |
| `page` | integer | 1 | |

Sorted by title.

```bash
curl -s -G http://127.0.0.1:8000/api/v1/saved-replies \
  -H "Accept: application/json" -H "Authorization: Bearer <agent-token>" --data-urlencode "q=refund"
```

`200 OK` (paginated):

```json
{
  "data": [
    {
      "id": 5,
      "title": "Refund processed",
      "body": "Hi {customer_first_name}, the refund for {ticket_number} has been processed.",
      "scope": "team",
      "category": { "id": 3, "name": "Billing" },
      "category_id": 3,
      "can": { "update": false, "delete": false },
      "created_at": "2026-10-20T09:00:00+00:00",
      "updated_at": "2026-10-20T09:00:00+00:00"
    }
  ],
  "links": { "...": "..." },
  "meta": { "...": "..." }
}
```

### 19.2 `POST /saved-replies`: create

| Field | Type | Required | Rules |
|---|---|---|---|
| `title` | string | yes | max 120 |
| `body` | string | yes | max 5000; only known placeholders |
| `category_id` | integer | no | an existing category, or `null` |
| `shared` | boolean | no, default `false` | `true` creates a team reply (needs `saved_replies.manage_shared`) |

```bash
curl -s -X POST http://127.0.0.1:8000/api/v1/saved-replies \
  -H "Accept: application/json" -H "Authorization: Bearer <agent-token>" -H "Content-Type: application/json" \
  -d '{"title":"Ask for screenshot","body":"Hi {customer_first_name}, could you attach a screenshot of the error?","category_id":2}'
```

`201 Created`: the saved reply. Errors: `403` (no `saved_replies.use`, or `shared: true` without `saved_replies.manage_shared`), `422` (validation, `"Unknown placeholder: {order_id}."`).

### 19.3 `PATCH /saved-replies/{savedReply}`: update

Same fields as create except `shared` (the scope cannot change). Send `title` and `body` every time (they are required). Who: the owner of a personal reply; `saved_replies.manage_shared` for team replies.

```bash
curl -s -X PATCH http://127.0.0.1:8000/api/v1/saved-replies/12 \
  -H "Accept: application/json" -H "Authorization: Bearer <agent-token>" -H "Content-Type: application/json" \
  -d '{"title":"Ask for screenshot","body":"Hi {customer_first_name}, please attach a screenshot.","category_id":null}'
```

`200 OK`: the saved reply. Errors: `404` (someone else's personal reply), `403` (team reply without `manage_shared`), `422`.

### 19.4 `DELETE /saved-replies/{savedReply}`: delete

Same rules as update.

```bash
curl -s -X DELETE http://127.0.0.1:8000/api/v1/saved-replies/12 -H "Accept: application/json" -H "Authorization: Bearer <agent-token>"
```

`204 No Content`. Errors: `403`, `404`.

## 20. Endpoints: CSV imports

Who: `imports.manage` (admins). Imports run in the background on the `imports` queue: upload the file, then poll its progress.

### CSV format

- First row is the header. Column names are case-insensitive; order does not matter; a UTF-8 byte-order mark is ignored.
- Required columns: `subject`, `description`, `priority`, `category`, `customer_email`.
- Optional columns: `external_reference`, `status`, `created_at`. Any other column, a blank column name, or a duplicate column makes the header invalid.
- Per row: `subject` max 255; `description` max 65535; `priority` a priority value; `category` the **slug** of an active category; `customer_email` an existing customer; `external_reference` max 64 and unique across tickets (a row whose reference was already imported is rejected); `status` a status value; `created_at` any parseable date, read in the business timezone.
- Blank lines are skipped.

```csv
external_reference,subject,description,priority,category,customer_email,status,created_at
LEGACY-1001,Invoice total wrong,The October invoice shows the wrong VAT.,high,billing,customer1@example.com,open,2026-10-30 14:20
LEGACY-1002,Cannot log in,Password reset link expired.,medium,account-access,customer2@example.com,,
```

### 20.1 `POST /imports`: start an import

Multipart. Rate limit: `uploads`.

| Field | Type | Required | Rules |
|---|---|---|---|
| `file` | file | yes | `csv` or `txt`, max 51200 KB |
| `auto_assign` | boolean | no, default `false` | `true` assigns the imported, still-unassigned active tickets to available agents when the import finishes (only if automatic assignment is enabled, `TICKETS_AUTO_ASSIGN`) |

```bash
curl -s -X POST http://127.0.0.1:8000/api/v1/imports \
  -H "Accept: application/json" -H "Authorization: Bearer <admin-token>" \
  -F "file=@./tickets.csv" -F "auto_assign=1"
```

`202 Accepted`:

```json
{
  "data": {
    "id": 3,
    "original_name": "tickets.csv",
    "status": { "value": "pending", "label": "Pending" },
    "is_finished": false,
    "auto_assign": true,
    "total_rows": 0,
    "processed_rows": 0,
    "succeeded_rows": 0,
    "failed_rows": 0,
    "progress_percent": 0,
    "started_at": null,
    "finished_at": null,
    "created_at": "2026-11-01T03:10:00+00:00",
    "user": { "id": 1, "name": "Ada Mensah", "avatar_url": null, "last_seen_at": "2026-11-01T03:09:00+00:00" }
  }
}
```

Errors: `403` (not admin), `422` (missing file, wrong type, too large), `429`. The header is checked **after** the upload, by the background job; a bad header does not give an HTTP error (see below).

### 20.2 `GET /imports/{import}`: progress

```bash
curl -s http://127.0.0.1:8000/api/v1/imports/3 -H "Accept: application/json" -H "Authorization: Bearer <admin-token>"
```

`200 OK`: the same shape as above. Status moves `pending` → `processing` → `completed`, `completed_with_errors` or `failed`. Poll until `is_finished` is `true` (every 2 to 5 seconds is enough). `progress_percent` is `processed_rows * 100 / total_rows`.

**Invalid header**: the import ends as `failed` with no rows processed, and `GET /imports/{import}/errors` holds one entry with `row_number: 1` and `errors: {"header": ["The CSV header is invalid: missing required columns: priority; unknown columns: urgency."]}`. An empty file gives `"The CSV file is empty or has no header row."`. This is the `import_header_invalid` rule.

Errors: `403`, `404`.

### 20.3 `GET /imports/{import}/errors`: rejected rows

Paginated, `per_page` default **25** (1 to 100).

```bash
curl -s "http://127.0.0.1:8000/api/v1/imports/3/errors?per_page=50" -H "Accept: application/json" -H "Authorization: Bearer <admin-token>"
```

`200 OK`:

```json
{
  "data": [
    {
      "id": 17,
      "row_number": 4,
      "errors": {
        "category": ["The selected category is invalid."],
        "customer_email": ["The customer email must belong to an existing customer."]
      },
      "raw": { "subject": "Broken link", "description": "Link in email 404s", "priority": "low", "category": "marketing", "customer_email": "nobody@example.com" },
      "created_at": "2026-11-01T03:10:05+00:00"
    }
  ],
  "links": { "...": "..." },
  "meta": { "...": "..." }
}
```

`row_number` is the line in the file (the header is line 1). Errors: `403`, `404`, `422`.

---

## 21. Endpoints: administration

All under `/admin`. Admins only, by default.

### 21.1 Users (`users.manage`)

#### `GET /admin/users`: list users

| Query | Type | Default | Rules |
|---|---|---|---|
| `q` | string | | max 100; prefix match on name or email |
| `role` | string | | `admin`, `agent`, `customer` |
| `per_page` | integer | 20 | 1 to 100 |
| `page` | integer | 1 | |

Sorted by name.

```bash
curl -s -G http://127.0.0.1:8000/api/v1/admin/users -H "Accept: application/json" -H "Authorization: Bearer <admin-token>" --data-urlencode "role=agent"
```

`200 OK` (paginated):

```json
{
  "data": [
    {
      "id": 7,
      "name": "Liam Carter",
      "email": "agent1@example.com",
      "avatar_url": null,
      "role": "agent",
      "is_available": true,
      "last_assigned_at": "2026-11-01T02:30:00+00:00",
      "categories": [ { "id": 3, "name": "Billing" } ],
      "created_at": "2026-10-01T08:00:00+00:00"
    }
  ],
  "links": { "...": "..." },
  "meta": { "...": "..." }
}
```

#### `POST /admin/users`: create a user

| Field | Type | Required | Rules |
|---|---|---|---|
| `name` | string | yes | max 255 |
| `email` | string | yes | lower-case, valid, max 255, unique |
| `password` | string | yes | password rules from section 10.2 |
| `role` | string | yes | `admin`, `agent`, `customer` |
| `category_ids` | array of integer | no | distinct existing category ids; used for agents only |
| `is_available` | boolean | no, default `true` | used for agents only |

The new user's email is marked verified, so they can request a token straight away.

```bash
curl -s -X POST http://127.0.0.1:8000/api/v1/admin/users \
  -H "Accept: application/json" -H "Authorization: Bearer <admin-token>" -H "Content-Type: application/json" \
  -d '{"name":"Jonas Berg","email":"jonas@example.com","password":"Str0ng-pass-123","role":"agent","category_ids":[2,3],"is_available":true}'
```

`201 Created`: the user (same shape as the list item). Errors: `403`, `422`.

#### `PATCH /admin/users/{user}`: update a user

A full update: send `name`, `email` and `role` every time.

| Field | Type | Required | Rules |
|---|---|---|---|
| `name` | string | yes | max 255 |
| `email` | string | yes | lower-case, valid, unique except for this user |
| `role` | string | yes | `admin`, `agent`, `customer` |
| `password` | string | no | when sent, resets the password, revokes all the user's tokens and ends their sessions |
| `category_ids` | array of integer | no | agents only; omitted keeps the current categories |
| `is_available` | boolean | no | agents only; omitted keeps the current value |
| `avatar` | file | no | same rules as `POST /me/avatar`; send as multipart with `POST` + `_method=PATCH` |
| `remove_avatar` | boolean | no | `true` removes the picture |

Changing a role revokes all the user's tokens. Changing an agent to another role unassigns their active tickets. Changing a user to customer clears their categories and notifications.

```bash
curl -s -X PATCH http://127.0.0.1:8000/api/v1/admin/users/7 \
  -H "Accept: application/json" -H "Authorization: Bearer <admin-token>" -H "Content-Type: application/json" \
  -d '{"name":"Liam Carter","email":"agent1@example.com","role":"agent","is_available":false}'
```

`200 OK`: the user. Errors: `403`, `404`, `422`, `422 cannot_change_own_role` (an admin changing their own role).

### 21.2 Roles and permissions (`users.manage`)

#### `GET /admin/roles`: the permission matrix

```bash
curl -s http://127.0.0.1:8000/api/v1/admin/roles -H "Accept: application/json" -H "Authorization: Bearer <admin-token>"
```

`200 OK` (not paginated; order admin, agent, customer):

```json
{ "data": [
  { "value": "admin", "label": "Administrator", "permissions": ["tickets.create", "...", "saved_replies.manage_shared"] },
  { "value": "agent", "label": "Support Agent", "permissions": ["tickets.create", "...", "saved_replies.use"] },
  { "value": "customer", "label": "Customer", "permissions": ["tickets.create", "tickets.view.own", "tickets.reply", "tickets.update.own"] }
] }
```

#### `PATCH /admin/roles/{role}`: replace a role's permissions

`{role}` is `admin`, `agent` or `customer` (anything else is `404`). The list **replaces** the role's permissions; send the complete set.

| Field | Type | Required | Rules |
|---|---|---|---|
| `permissions` | array of string | yes (may be empty) | distinct permission names from section 6.1 |

```bash
curl -s -X PATCH http://127.0.0.1:8000/api/v1/admin/roles/agent \
  -H "Accept: application/json" -H "Authorization: Bearer <admin-token>" -H "Content-Type: application/json" \
  -d '{"permissions":["tickets.create","tickets.create.on_behalf","tickets.view.any","tickets.reply","tickets.notes.internal","tickets.claim","tickets.update.assigned","tickets.handoff.own","dashboard.view","saved_replies.use"]}'
```

`200 OK`: the role. Takes effect immediately for every user with the role. Errors: `403`, `404`, `422` (unknown permission, duplicates, missing `permissions`), `422 customer_internal_notes_forbidden`, `422 cannot_remove_user_management`.

### 21.3 Categories (`categories.manage`)

#### `GET /admin/categories`: all categories

Includes inactive ones and a ticket count. Paginated, fixed 20 per page (`page` only), sorted by name.

```bash
curl -s http://127.0.0.1:8000/api/v1/admin/categories -H "Accept: application/json" -H "Authorization: Bearer <admin-token>"
```

`200 OK`: `{"data": [{"id": 3, "name": "Billing", "slug": "billing", "is_active": true, "tickets_count": 57}], "links": {...}, "meta": {...}}`.

#### `POST /admin/categories` and `PATCH /admin/categories/{category}`

Both take the same body (PATCH is a full update: send `name` and `slug`).

| Field | Type | Required | Rules |
|---|---|---|---|
| `name` | string | yes | max 100 |
| `slug` | string | yes | max 100; lower-case letters, digits and single hyphens (`^[a-z0-9]+(-[a-z0-9]+)*$`); trimmed and lower-cased before validation; unique |
| `is_active` | boolean | no | inactive categories cannot be chosen for new tickets |

```bash
curl -s -X POST http://127.0.0.1:8000/api/v1/admin/categories \
  -H "Accept: application/json" -H "Authorization: Bearer <admin-token>" -H "Content-Type: application/json" \
  -d '{"name":"Shipping","slug":"shipping","is_active":true}'
```

`201 Created` (POST) or `200 OK` (PATCH): the category with `tickets_count`. Errors: `403`, `404`, `422` (slug format, slug taken).

#### `DELETE /admin/categories/{category}`

- A category **without tickets** is deleted: `204 No Content`.
- A category **with tickets** is deactivated instead and returned: `200 OK` with `"is_active": false`.

```bash
curl -s -X DELETE http://127.0.0.1:8000/api/v1/admin/categories/8 -H "Accept: application/json" -H "Authorization: Bearer <admin-token>"
```

Errors: `403`, `404`.

### 21.4 Holidays (`holidays.manage`)

Holidays are days on which the SLA clock does not run. Every write accepts an optional `recalculate_active_tickets` flag: when `true`, the due and warning times of all active tickets are recalculated in the background; otherwise existing tickets keep their deadlines until something else recalculates them.

#### `GET /admin/holidays`: holidays of a year

| Query | Type | Default | Rules |
|---|---|---|---|
| `year` | integer | current year in the business timezone | 2000 to 2100 |
| `page` | integer | 1 | 50 per page, sorted by date |

```bash
curl -s "http://127.0.0.1:8000/api/v1/admin/holidays?year=2026" -H "Accept: application/json" -H "Authorization: Bearer <admin-token>"
```

`200 OK`: `{"data": [{"id": 4, "date": "2026-12-25", "weekday": "Friday", "name": "Christmas Day"}], "links": {...}, "meta": {...}}`.

#### `POST /admin/holidays` and `PATCH /admin/holidays/{holiday}`

| Field | Type | Required | Rules |
|---|---|---|---|
| `date` | string | yes | `YYYY-MM-DD`, unique (another holiday on the same day is refused) |
| `name` | string | yes | max 100 |
| `recalculate_active_tickets` | boolean | no | see above |

```bash
curl -s -X POST http://127.0.0.1:8000/api/v1/admin/holidays \
  -H "Accept: application/json" -H "Authorization: Bearer <admin-token>" -H "Content-Type: application/json" \
  -d '{"date":"2026-12-25","name":"Christmas Day","recalculate_active_tickets":true}'
```

`201 Created` (POST) or `200 OK` (PATCH): the holiday. Errors: `403`, `404`, `422`.

#### `DELETE /admin/holidays/{holiday}`

Optional `recalculate_active_tickets` (query string or body).

```bash
curl -s -X DELETE "http://127.0.0.1:8000/api/v1/admin/holidays/4?recalculate_active_tickets=1" \
  -H "Accept: application/json" -H "Authorization: Bearer <admin-token>"
```

`204 No Content`. Errors: `403`, `404`.

### 21.5 SLA settings (`sla.manage`)

#### `GET /admin/sla`: current SLA rules

```bash
curl -s http://127.0.0.1:8000/api/v1/admin/sla -H "Accept: application/json" -H "Authorization: Bearer <admin-token>"
```

`200 OK`:

```json
{
  "data": {
    "resolution_minutes": { "low": 2880, "medium": 1440, "high": 480, "critical": 120 },
    "warning_threshold_percent": 75,
    "timezone": "Asia/Dhaka",
    "week": {
      "sunday": [ { "start": "09:00", "end": "17:00" } ],
      "monday": [ { "start": "09:00", "end": "17:00" } ],
      "tuesday": [ { "start": "09:00", "end": "17:00" } ],
      "wednesday": [ { "start": "09:00", "end": "17:00" } ],
      "thursday": [ { "start": "09:00", "end": "17:00" } ],
      "friday": [],
      "saturday": []
    },
    "customized": true
  }
}
```

`resolution_minutes` are business minutes per priority. The warning ("approaching") point is reached after `warning_threshold_percent` of the target has elapsed. `customized` is `false` while the defaults from `config/sla.php` are in use.

#### `PUT /admin/sla`: replace the SLA rules

A full replacement; send every field.

| Field | Type | Required | Rules |
|---|---|---|---|
| `resolution_minutes` | object | yes | exactly the keys `low`, `medium`, `high`, `critical` |
| `resolution_minutes.<priority>` | integer | yes | 1 to 43200 |
| `warning_threshold_percent` | integer | yes | 1 to 99 |
| `timezone` | string | yes | a valid IANA timezone, for example `Asia/Dhaka` |
| `week` | object | yes | only the keys `sunday` … `saturday` (lower-case); a missing day means closed |
| `week.<day>` | array | | up to 6 windows per day |
| `week.<day>.<n>.start` / `.end` | string | yes | `HH:MM`, `00:00` to `23:59`; `24:00` allowed as an end; end after start; windows on the same day must not overlap |
| `recalculate_active_tickets` | boolean | no | recalculate active tickets' deadlines in the background |

At least one window must be open somewhere in the week.

```bash
curl -s -X PUT http://127.0.0.1:8000/api/v1/admin/sla \
  -H "Accept: application/json" -H "Authorization: Bearer <admin-token>" -H "Content-Type: application/json" \
  -d '{
    "resolution_minutes": {"low": 2880, "medium": 1440, "high": 480, "critical": 120},
    "warning_threshold_percent": 80,
    "timezone": "Asia/Dhaka",
    "week": {
      "sunday": [{"start": "09:00", "end": "13:00"}, {"start": "14:00", "end": "18:00"}],
      "monday": [{"start": "09:00", "end": "18:00"}],
      "tuesday": [{"start": "09:00", "end": "18:00"}],
      "wednesday": [{"start": "09:00", "end": "18:00"}],
      "thursday": [{"start": "09:00", "end": "18:00"}],
      "friday": [],
      "saturday": []
    },
    "recalculate_active_tickets": true
  }'
```

`200 OK`: the new settings (same shape as GET). Errors: `403`, `422` with field errors such as `week.monday.0.end: "The window must end after it starts."`, `week.sunday.1.start: "This window overlaps another window on the same day."` or `week: "Open at least one business window in the week."`; `422 invalid_sla_settings` if a rule is broken that the request validation did not catch.

---

## 22. Typical workflows

### 22.1 Customer opens a ticket; an agent claims, replies and resolves it

```bash
BASE=http://127.0.0.1:8000/api/v1
H='-H Accept:application/json'

# 1. Customer signs in and lists categories
CUSTOMER=$(curl -s -X POST $BASE/auth/tokens $H -H "Content-Type: application/json" \
  -d '{"email":"customer1@example.com","password":"password","device_name":"mobile"}' | jq -r .data.token)
curl -s $BASE/categories $H -H "Authorization: Bearer $CUSTOMER"

# 2. Customer creates a ticket with a screenshot -> 201, status "open"
NUMBER=$(curl -s -X POST $BASE/tickets $H -H "Authorization: Bearer $CUSTOMER" \
  -F "subject=Cannot export invoices to PDF" -F "description=The export button returns an error." \
  -F "priority=high" -F "category_id=2" -F "attachments[]=@./export-error.png" | jq -r .data.number)

# 3. Agent signs in and finds unassigned, unanswered tickets due soonest
AGENT=$(curl -s -X POST $BASE/auth/tokens $H -H "Content-Type: application/json" \
  -d '{"email":"agent1@example.com","password":"password","device_name":"cli"}' | jq -r .data.token)
curl -s -G $BASE/tickets $H -H "Authorization: Bearer $AGENT" \
  --data-urlencode unassigned=1 --data-urlencode response=unresponded --data-urlencode sort=sla_due_at

# 4. Agent claims it -> 200, assigned to the agent, status "in_progress"
#    (409 ticket_already_claimed if a colleague was faster: refresh the list and pick another)
curl -s -X POST $BASE/tickets/$NUMBER/claim $H -H "Authorization: Bearer $AGENT"

# 5. Agent adds an internal note, then asks the customer a question and waits
curl -s -X POST $BASE/tickets/$NUMBER/replies $H -H "Authorization: Bearer $AGENT" -H "Content-Type: application/json" \
  -d '{"body":"Reproduced on staging.","is_internal":true}'
curl -s -X POST $BASE/tickets/$NUMBER/replies $H -H "Authorization: Bearer $AGENT" -H "Content-Type: application/json" \
  -d '{"body":"Which invoice number fails?"}'
curl -s -X PATCH $BASE/tickets/$NUMBER/status $H -H "Authorization: Bearer $AGENT" -H "Content-Type: application/json" \
  -d '{"status":"waiting_for_customer"}'

# 6. Customer answers -> the ticket returns to "in_progress" and the SLA clock resumes
curl -s -X POST $BASE/tickets/$NUMBER/replies $H -H "Authorization: Bearer $CUSTOMER" -H "Content-Type: application/json" \
  -d '{"body":"Invoice INV-2026-0193."}'

# 7. Agent resolves; the customer accepts by closing (or reopens with "in_progress")
curl -s -X PATCH $BASE/tickets/$NUMBER/status $H -H "Authorization: Bearer $AGENT" -H "Content-Type: application/json" \
  -d '{"status":"resolved"}'
curl -s -X PATCH $BASE/tickets/$NUMBER/status $H -H "Authorization: Bearer $CUSTOMER" -H "Content-Type: application/json" \
  -d '{"status":"closed"}'
```

The customer's `GET /tickets/$NUMBER/replies` never contains the internal note, and their `GET /tickets/$NUMBER/activities` has no `internal_note` entry.

### 22.2 Admin imports a CSV and polls progress

```bash
ADMIN=$(curl -s -X POST $BASE/auth/tokens $H -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password","device_name":"import-script"}' | jq -r .data.token)

# 1. Upload -> 202 with status "pending"
IMPORT=$(curl -s -X POST $BASE/imports $H -H "Authorization: Bearer $ADMIN" \
  -F "file=@./tickets.csv" -F "auto_assign=1" | jq -r .data.id)

# 2. Poll until finished
until [ "$(curl -s $BASE/imports/$IMPORT $H -H "Authorization: Bearer $ADMIN" | jq -r .data.is_finished)" = "true" ]; do
  sleep 3
done
curl -s $BASE/imports/$IMPORT $H -H "Authorization: Bearer $ADMIN" | jq '.data | {status, total_rows, succeeded_rows, failed_rows}'

# 3. Inspect rejected rows (status "completed_with_errors" or "failed")
curl -s "$BASE/imports/$IMPORT/errors?per_page=100" $H -H "Authorization: Bearer $ADMIN"
```

The background workers must be running (`composer run dev`, or `php artisan queue:work --queue=default,broadcasts,notifications,imports`) for an import to progress past `pending`.

## 23. Realtime is web-only

Broadcast channels are authorized with the web session (`routes/channels.php` uses the `web` and `auth` middleware). API clients poll the endpoints above (for example `GET /notifications?unread=1`, `GET /imports/{import}`); there is no token-authenticated WebSocket access.

## 24. Endpoint index

All 49 operations, relative to `/api/v1`.

| # | Method | Path | Purpose | Default access | Section |
|---|---|---|---|---|---|
| 1 | POST | `/auth/tokens` | Issue a token | public | 9.1 |
| 2 | DELETE | `/auth/tokens/current` | Revoke the current token | any user | 9.2 |
| 3 | GET | `/me` | Current user | any user | 9.3 |
| 4 | PATCH | `/me/profile` | Change name | any user | 10.1 |
| 5 | PUT | `/me/password` | Change password | any user | 10.2 |
| 6 | POST | `/me/avatar` | Upload profile picture | any user | 10.3 |
| 7 | DELETE | `/me/avatar` | Remove profile picture | any user | 10.4 |
| 8 | GET | `/tickets` | List, filter, search | all roles (customers: own) | 11.1 |
| 9 | POST | `/tickets` | Create | all roles | 11.2 |
| 10 | GET | `/tickets/{ticket}` | Show | all roles (customers: own) | 11.3 |
| 11 | PATCH | `/tickets/{ticket}` | Update subject/description/category | admin, assigned agent, owning customer (Open) | 11.4 |
| 12 | PATCH | `/tickets/{ticket}/status` | Change status | admin, assigned agent, owning customer (from Resolved) | 11.5 |
| 13 | PATCH | `/tickets/{ticket}/priority` | Change priority | admin, assigned agent | 11.6 |
| 14 | GET | `/tickets/{ticket}/activities` | Timeline | all roles (customers: own) | 11.7 |
| 15 | GET | `/tickets/{ticket}/replies` | List replies | all roles (customers: own, public only) | 12.1 |
| 16 | POST | `/tickets/{ticket}/replies` | Reply / internal note | all roles (notes: staff) | 12.2 |
| 17 | GET | `/attachments/{attachment}` | Download a file | all roles (customers: own, non-internal) | 12.3 |
| 18 | POST | `/tickets/{ticket}/claim` | Claim | admin, agent | 13.1 |
| 19 | POST | `/tickets/{ticket}/assign` | Assign / hand off | admin, assigned agent | 13.2 |
| 20 | GET | `/categories` | Active categories | all roles | 14.1 |
| 21 | GET | `/customers/lookup` | Customer typeahead | admin, agent | 15.1 |
| 22 | GET | `/dashboard` | Dashboard | admin, agent | 16.1 |
| 23 | GET | `/agents` | Agent directory | admin, agent | 17.1 |
| 24 | GET | `/agents/{user}` | One staff member | admin, agent | 17.2 |
| 25 | GET | `/notifications` | Inbox | all roles | 18.1 |
| 26 | POST | `/notifications/{notification}/read` | Mark one read | all roles | 18.2 |
| 27 | POST | `/notifications/read-all` | Mark all read | all roles | 18.3 |
| 28 | GET | `/saved-replies` | List saved replies | admin, agent | 19.1 |
| 29 | POST | `/saved-replies` | Create (team: admin) | admin, agent | 19.2 |
| 30 | PATCH | `/saved-replies/{savedReply}` | Update | owner; team: admin | 19.3 |
| 31 | DELETE | `/saved-replies/{savedReply}` | Delete | owner; team: admin | 19.4 |
| 32 | POST | `/imports` | Start CSV import | admin | 20.1 |
| 33 | GET | `/imports/{import}` | Import progress | admin | 20.2 |
| 34 | GET | `/imports/{import}/errors` | Rejected rows | admin | 20.3 |
| 35 | GET | `/admin/users` | List users | admin | 21.1 |
| 36 | POST | `/admin/users` | Create user | admin | 21.1 |
| 37 | PATCH | `/admin/users/{user}` | Update user | admin | 21.1 |
| 38 | GET | `/admin/roles` | Permission matrix | admin | 21.2 |
| 39 | PATCH | `/admin/roles/{role}` | Replace role permissions | admin | 21.2 |
| 40 | GET | `/admin/categories` | All categories | admin | 21.3 |
| 41 | POST | `/admin/categories` | Create category | admin | 21.3 |
| 42 | PATCH | `/admin/categories/{category}` | Update category | admin | 21.3 |
| 43 | DELETE | `/admin/categories/{category}` | Delete or deactivate | admin | 21.3 |
| 44 | GET | `/admin/holidays` | Holidays of a year | admin | 21.4 |
| 45 | POST | `/admin/holidays` | Add holiday | admin | 21.4 |
| 46 | PATCH | `/admin/holidays/{holiday}` | Update holiday | admin | 21.4 |
| 47 | DELETE | `/admin/holidays/{holiday}` | Remove holiday | admin | 21.4 |
| 48 | GET | `/admin/sla` | SLA rules | admin | 21.5 |
| 49 | PUT | `/admin/sla` | Replace SLA rules | admin | 21.5 |
