# 07 · API

The application exposes a versioned REST JSON API under `/api/v1`. It uses the same FormRequests, Actions and Policies as the web pages (TD-001, TD-003), so every business rule and permission applies identically. API responses always go through the Resources in `app/Http/Resources/V1`.

## 1. Reference documentation

| Where | What |
|---|---|
| `{APP_URL}/docs/api` | Interactive documentation (Scramble UI), generated from the routes, FormRequests and Resources |
| `{APP_URL}/docs/api.json` | The same OpenAPI 3.1 document as JSON |
| [`docs/api/openapi.json`](api/openapi.json) | A committed export of that document |

- Both URLs are open to everyone when `APP_ENV=local`. In every other environment they require a signed-in user who may manage users (admins); others get 403.
- After changing the API, regenerate the committed copy with `php artisan scramble:export`. A test (`tests/Feature/Docs/ApiDocsTest.php`) fails if any `/api/v1` route is missing from it.

## 2. Authentication

Sanctum personal access tokens.

```bash
# 1. Get a token (rate limited: 5 per minute per email + IP)
curl -s -X POST http://127.0.0.1:8000/api/v1/auth/tokens \
  -H "Accept: application/json" \
  -d email=agent1@example.com -d password=password -d device_name=cli

# 2. Use it
curl -s http://127.0.0.1:8000/api/v1/tickets \
  -H "Accept: application/json" \
  -H "Authorization: Bearer <token>"

# 3. Revoke the token you are using
curl -s -X DELETE http://127.0.0.1:8000/api/v1/auth/tokens/current \
  -H "Authorization: Bearer <token>"
```

- Wrong credentials give one generic validation error.
- The token's abilities mirror the user's permission names at issue time, but authorization always uses the user's live permissions through the Policies. Password changes, password resets and role changes revoke tokens (see [06](06-security-and-authorization.md#8-sessions-passwords-and-tokens)).
- Every `/api/v1` route is in the `api` group: JSON responses always (`ForceJsonResponse`), and 60 requests per minute per user.

## 3. Conventions

| Topic | Convention |
|---|---|
| Format | JSON; send `Accept: application/json`; files as `multipart/form-data` |
| Lists | Laravel pagination: `data`, `links`, `meta` (`current_page`, `per_page`, `total`, …). `per_page` from 1 to 100, default 20 |
| Ticket identity | `{ticket}` is the ticket number, for example `/api/v1/tickets/TKT-2026-000042` |
| Business-rule errors | `{"message": "...", "code": "ticket_already_claimed"}` with 409 or 422. Branch on `code` |
| Validation errors | 422 with `message` and `errors` per field |
| Authorization | 401 without a token, 403 when not allowed, 404 when the resource must stay hidden (another customer's ticket, an internal reply) |
| Rate limiting | 429 with `Retry-After` |

Stable error codes: `invalid_status_transition`, `ticket_already_claimed`, `agent_not_eligible`, `ticket_closed`, `import_header_invalid`, `cannot_change_own_role`, `cannot_remove_user_management`, `customer_internal_notes_forbidden`, `invalid_sla_settings`.

## 4. Endpoints

| Area | Method and path | Notes |
|---|---|---|
| Auth | `POST /auth/tokens` | issue a token |
| | `DELETE /auth/tokens/current` | revoke the current token |
| | `GET /me` | the token's user |
| Profile | `PATCH /me/profile` | change name |
| | `PUT /me/password` | change password (6/min) |
| | `POST /me/avatar`, `DELETE /me/avatar` | upload or remove the profile picture |
| Tickets | `GET /tickets` | list with filters: `scope` (`active`, `resolved`, `all`), `number`, `q`, `status[]`, `priority[]`, `category_id`, `assigned_agent_id`, `unassigned`, `customer_id`, `created_from`, `created_to`, `sla_state`, `response`, `sort`, `per_page` |
| | `POST /tickets` | create (staff send `customer_email`); up to 5 `attachments[]` |
| | `GET /tickets/{ticket}` | details with visible attachments |
| | `PATCH /tickets/{ticket}` | subject, description (customer, Open only), category (staff) |
| | `PATCH /tickets/{ticket}/status` | status change |
| | `PATCH /tickets/{ticket}/priority` | priority change |
| | `GET /tickets/{ticket}/activities` | timeline, paginated |
| Assignment | `POST /tickets/{ticket}/claim` | 409 if someone else got it first |
| | `POST /tickets/{ticket}/assign` | `agent_id`, optional `outside_category` (admins) |
| Conversation | `GET /tickets/{ticket}/replies` | paginated, visible replies only |
| | `POST /tickets/{ticket}/replies` | `body`, `is_internal`, up to 5 `attachments[]` |
| | `GET /attachments/{attachment}` | permission-checked download |
| Saved replies | `GET /saved-replies` | `q`, `scope` (`mine`/`team`), paginated |
| | `POST /saved-replies`, `PATCH /saved-replies/{id}`, `DELETE /saved-replies/{id}` | `shared` only on create, team replies admin-only |
| Directory | `GET /agents`, `GET /agents/{user}` | agents directory with workload numbers |
| | `GET /customers/lookup` | typeahead (`q` or `id`), 60/min |
| Categories | `GET /categories` | active categories |
| Dashboard | `GET /dashboard` | totals and breakdowns |
| Notifications | `GET /notifications` | own inbox, `unread` filter |
| | `POST /notifications/{id}/read`, `POST /notifications/read-all` | mark read |
| Imports | `POST /imports` | upload a CSV (`file`, optional `auto_assign`); 20/min |
| | `GET /imports/{import}` | progress and counts |
| | `GET /imports/{import}/errors` | rejected rows, paginated |
| Admin | `GET/POST /admin/users`, `PATCH /admin/users/{user}` | user management |
| | `GET /admin/roles`, `PATCH /admin/roles/{role}` | permission matrix |
| | `GET/POST /admin/categories`, `PATCH/DELETE /admin/categories/{category}` | categories |
| | `GET/POST /admin/holidays`, `PATCH/DELETE /admin/holidays/{holiday}` | holidays; optional `recalculate_active_tickets` |
| | `GET /admin/sla`, `PUT /admin/sla` | SLA rules; optional `recalculate_active_tickets` |

All paths are relative to `/api/v1`. The OpenAPI document is the authoritative list of parameters and response shapes. `php artisan route:list --path=api/v1` prints the live routes.

## 5. Realtime is web-only

Broadcast channels are authorized with the web session (`routes/channels.php` uses the `web` and `auth` middleware). API clients poll the endpoints above; there is no token-authenticated WebSocket access.
