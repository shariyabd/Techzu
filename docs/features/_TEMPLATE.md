# <Feature name>

> Written by the <Txx> documenter · last updated by <Txx>
> Plan: [docs/plans/<Txx-slug>.md](../plans/<Txx-slug>.md) · Decisions: TD-xxx, TD-yyy

## In one paragraph
What this feature does for the user and why it exists, in plain language. No code names.

## Business rules
One row per rule. Every row must point to real code and a real test.

| # | Rule (plain language) | Implemented in | Proved by |
|---|---|---|---|
| R1 | A customer only sees their own tickets; others return 404 | `app/Policies/TicketPolicy.php::view()` | `tests/Feature/Authorization/TicketPolicyTest.php` › "hides other customers' tickets" |
| R2 | … | `path/File.php::method()` | `tests/...Test.php` › "<test name>" |

## How it flows
The main path as numbered steps, from the request to the database to the side effects.

1. `POST /tickets` → `Web/TicketController::store()` (thin)
2. `StoreTicketRequest` validates …
3. `CreateTicket::handle()` opens a transaction, then …
4. After commit: `TicketCreated` event → listeners `RecordTicketActivity`, `QueueAutoAssignment` …

Add a short Mermaid `sequenceDiagram` only if the flow has more than one actor or runs asynchronously.

## Data
| Table.column | Meaning | Written by |
|---|---|---|
| `tickets.sla_due_at` | When the SLA breaches | `SlaCalculator` only |

## Events and side effects
| Event | Listener / job | Effect | Queue |
|---|---|---|---|

## Permissions
| Action | Customer | Agent | Admin | Enforced in |
|---|---|---|---|---|

## Endpoints
| Method | Web route | API route | Request | Response |
|---|---|---|---|---|

## Configuration
| Key | Default | Effect |
|---|---|---|

## Edge cases and failure handling
- What happens on a race, a retry, a rollback or invalid input, and where that is handled (file::method).

## Error codes
| Code | HTTP | When |
|---|---|---|

## Files at a glance
- `app/...`: one line on its responsibility.
