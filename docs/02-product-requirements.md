# 02 · Product requirements

This document turns the [assessment brief](01-requirements.md) into the product we built: who it is for, what it must do, and what we chose to leave out. Each requirement has an ID (`FR-…`, `NFR-…`) so the [traceability matrix](12-traceability-matrix.md) can point at it. The detailed rules live in [04 · Business logic](04-business-logic.md); this document says *what*, that one says *exactly how*.

## 1. Product summary

Techzu Support is a help desk for one company. Customers open tickets and talk to support through them. Agents pick up, answer and resolve tickets inside a resolution SLA that counts only business hours. Admins run the desk: users, roles, categories, holidays, SLA rules and bulk imports. Everything is available as web pages and as a versioned JSON API.

## 2. Users

| Role | Who they are | What they need |
|---|---|---|
| Customer | A person at a client company | Open a ticket with files, follow its progress, reply, close or reopen it once resolved. See nothing of other customers and nothing internal. |
| Agent (Support Agent) | Support staff | See the whole queue, claim or be assigned work that matches their skills, answer quickly (saved replies), talk privately with colleagues (internal notes), know what is about to breach. |
| Admin (Administrator) | Support lead | Everything an agent does, plus assigning any ticket, managing users, roles, categories, holidays and SLA rules, importing old tickets, and watching the team's workload. |

## 3. Goals

1. No ticket is lost or double-handled: every ticket has a unique number and at most one agent, even under concurrent clicks.
2. Deadlines are fair and correct: the SLA counts only open business time, pauses while waiting for the customer, and never punishes an agent twice for the same pause.
3. Nothing leaks: a customer can never see another customer's ticket or any internal content, in any channel.
4. People hear about what matters, without the app slowing down: notifications and escalations run in the background.
5. The desk stays fast as data grows: indexed queries, pagination everywhere, no N+1 queries.

## 4. Functional requirements

### 4.1 Core requirements from the brief

| ID | Requirement | Brief § |
|---|---|---|
| FR-01 | Create, view, update, resolve and close tickets; separate Active and Resolved lists. A ticket holds number, subject, description, priority, category, status, assigned agent, customer, creator, created date and SLA due time. | 1 |
| FR-02 | Statuses Open, In Progress, Waiting for Customer, Resolved, Closed, with a fixed set of allowed transitions. Customers may close or reopen their own resolved ticket. | 1 |
| FR-03 | Ticket numbers `PREFIX-YYYY-NNNNNN`, unique under concurrency, restarting each business-timezone year, growing past six digits. | 2 |
| FR-04 | Roles Admin, Agent, Customer; named permissions; ownership rules; all enforced on the server, for web and API. | 3 |
| FR-05 | Manual assignment, agent claim, and automatic assignment by category, availability and least load with a round-robin tie-break. | 4 |
| FR-06 | Two agents claiming the same ticket at once: exactly one wins, the other gets a clear conflict. | 5 |
| FR-07 | Customer replies, agent replies, internal notes. Internal notes never reach customers. | 6 |
| FR-08 | Attachments on tickets and replies: type and size validation, private storage, permission-checked download and image preview. | 7 |
| FR-09 | Resolution SLA per priority (defaults Low 48 h, Medium 24 h, High 8 h, Critical 2 h); SLA states Within, Approaching, Breached, Paused, Stopped. | 8 |
| FR-10 | SLA counts only configurable business hours (default Sunday–Thursday 09:00–18:00, Asia/Dhaka) and skips holidays. | 9 |
| FR-11 | A scheduled scan escalates approaching and breached tickets, notifies the right people and writes the history. | 10 |
| FR-12 | Mail and in-app notifications for created, assigned, replied (both sides), resolved, SLA approaching, SLA breached; sent in the background after commit. | 11 |
| FR-13 | Search and filters: number, keyword, status, priority, category, agent, customer, created date range, SLA state; paginated and sortable. | 12 |
| FR-14 | CSV import for large files: streamed, chunked, background, per-row validation, duplicate detection, error report. | 13 |
| FR-15 | Dashboard: open, unassigned, critical, breached, approaching, resolved; by priority; by agent. | 14 |
| FR-16 | Activity history for every important change, with time and actor. | 15 |
| FR-17 | Schema, indexes and queries designed for large tables. | 16 |
| FR-18 | Automated tests for the high-risk areas. | 17 |

### 4.2 Features beyond the brief

These were added to make the desk usable day to day. They follow the same rules as the core features (server-side authorization, internal-note guarantees, tests).

| ID | Feature | What it does |
|---|---|---|
| FR-20 | Admin-editable SLA rules | Admins change resolution targets, the warning percentage, the business timezone and weekly opening windows on a settings page. Changes apply to new tickets; an explicit "Recalculate active tickets" option re-applies them to open work in the background. The same option exists for holiday changes. |
| FR-21 | Fair SLA across pauses and reopen | Time waiting for the customer and time spent resolved are credited and never taken back by a later priority change. A customer's public reply on a Resolved ticket reopens it. |
| FR-22 | Reliable escalation | Each escalation is announced at least once and never twice, even if a process dies mid-way. |
| FR-23 | Assignment eligibility | Manual assignment requires an available agent who handles the ticket's category; only admins can knowingly override the category. Assigning an Open ticket starts work on it. |
| FR-24 | Profile and avatars | Every user edits their name, changes their password (other sessions end) and uploads a profile picture that is cropped, re-encoded and served privately. |
| FR-25 | Saved replies | Personal and team reply templates with placeholders, inserted at the cursor from the reply box. |
| FR-26 | Agents directory | A staff page listing each agent's workload (active, unresponded, awaiting reply, breached, recently resolved, last active), each number linking to the filtered ticket list. |
| FR-27 | Response filter | Filter tickets by "Unresponded" or "Awaiting reply". |
| FR-28 | Customer lookup | A typeahead to find a customer when filtering or filing a ticket on their behalf. |
| FR-29 | Chat inbox | The ticket page works like a messenger: pinned composer, unread divider, new-message pill, optimistic sending. |
| FR-30 | Presence and typing | See who else has the ticket open and who is typing; internal-note typing is visible to staff only. |
| FR-31 | Realtime updates | Lists, ticket pages and the unread count update live over WebSockets. Messages carry identifiers only; pages refetch through normal permission checks. |
| FR-32 | REST API with documentation | `/api/v1` with Sanctum tokens, sharing validation, actions and policies with the web; OpenAPI document generated from the code. |
| FR-33 | Permission matrix editor | Admins edit which permissions each fixed role has, with two hard guards (customers never get internal notes; admins never lose user management). |
| FR-34 | Demo data | A realistic seeded help desk for presentations and manual testing. |

## 5. Non-functional requirements

| ID | Requirement |
|---|---|
| NFR-01 Security | Every route goes through a policy. Customers get 404 for tickets that are not theirs. Internal content never reaches customers through pages, API, notifications, history, broadcasts or typing signals. Files are private. Rate limits on login, tokens, uploads and API. |
| NFR-02 Integrity | Real foreign keys; state changes inside transactions; side effects dispatched after commit. |
| NFR-03 Performance | Every list is paginated (max 100 per page). Hot queries are index-backed and checked with `EXPLAIN` in tests. Lazy loading is blocked outside production. Dashboard and queue counts are cached and invalidated on change. |
| NFR-04 Background work | Notifications, auto-assignment, SLA recalculation, broadcasts and imports run on queues in a fixed priority order. The SLA scan runs every minute on one server without overlap. |
| NFR-05 Configurability | SLA defaults, business hours defaults, file limits, ticket prefix, page sizes, cache lifetimes and retention periods come from configuration; SLA rules are then edited in the app. |
| NFR-06 Testability | Tests run on MySQL (the same engine as production), including real concurrency tests. |
| NFR-07 Operability | Old activity, read notifications, finished batches and failed jobs are pruned nightly. The app works without the realtime server. |
| NFR-08 Usability | A responsive, consistent UI. Client-side validation mirrors server rules. Reduced-motion preferences are respected. |

## 6. Out of scope

- Multiple companies (multi-tenancy) and per-team calendars: one business calendar and timezone.
- A first-response SLA: only a resolution SLA.
- Email verification, two-factor authentication, passkeys.
- Ticket merging, customer satisfaction surveys, a knowledge base.
- Creating or deleting roles, or inventing new permission names at runtime.
- Deleting users.
- Virus scanning of uploads.
- Docker packaging (planned; see the README).

## 7. Assumptions

- A single support organisation; agents may read every customer's tickets so they can collaborate.
- Business hours are wall-clock windows in one timezone; holidays close the whole day.
- An SLA breach detected up to one minute late (the scan interval) is acceptable for SLAs measured in hours.
- Imported tickets are trusted historical data: their SLA starts at their original creation time, and they do not trigger per-ticket emails.
- Mail goes to the log in development; real delivery is a configuration change.
