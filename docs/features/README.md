# Feature logic guide

This guide explains **what each feature does and where in the code each rule lives**, for a reader who hasn't read the codebase.

Each doc follows [_TEMPLATE.md](_TEMPLATE.md). It's written by the task's documenter subagent after the task passes review, and checked against the code by the spec reviewer before the task lands.

| Feature doc | Written by | Covers | Status |
|---|---|---|---|
| [platform-foundation.md](platform-foundation.md) | T00 | App shell, API tokens, error format, rate limits, route loading, home redirect | Pending |
| [data-model.md](data-model.md) | T01 | Tables, enums, status transitions, roles and permissions, demo data | Pending |
| [sla-calculation.md](sla-calculation.md) | T02 | Business hours and holidays, due/warning times, pause/resume, priority change | Pending |
| [ticket-numbering.md](ticket-numbering.md) | T03 | `TKT-YYYY-000001` generation under concurrency | Pending |
| [authorization.md](authorization.md) | T04 | Who can see and do what, 404 versus 403, internal-content hiding | Pending |
| [ticket-lifecycle.md](ticket-lifecycle.md) | T05 | Create, update, status and priority changes, activity log, ticket pages and API | Pending |
| [assignment.md](assignment.md) | T06 | Claim, manual assign, handoff, auto-assignment | Pending |
| [conversation-attachments.md](conversation-attachments.md) | T07 | Replies, internal notes, attachments, SLA resume on customer reply | Pending |
| [search-filtering.md](search-filtering.md) | T08 | Filters, keyword search, SLA-state filter, sorting | Pending |
| [sla-escalation.md](sla-escalation.md) | T09 | Warning/breach scan, escalation levels, at-most-once alerts | Pending |
| [notifications.md](notifications.md) | T10 | Who gets notified of what, delivery channels, inbox | Pending |
| [csv-import.md](csv-import.md) | T11 | CSV upload, row rules, batching, error report | Pending |
| [dashboard.md](dashboard.md) | T12 | Metrics, how each number is computed, caching | Pending |
| [administration.md](administration.md) | T13 | Categories, holidays, users, roles, agent profiles | Pending |
| [security-performance.md](security-performance.md) | T14 | Security checklist, index usage, query guards | Pending |

When a task lands, its row's status becomes **Current (Txx)**. If a later task changes a feature's logic, its documenter adds a "Changed by Txx" section to that feature's doc.
