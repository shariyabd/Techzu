# 01 · Requirements (the assessment brief)

This is the assessment brief we were given, reformatted for reading: headings, numbered sections and tables. The meaning is unchanged. Nothing here describes our implementation; for that, start at [02 · Product requirements](02-product-requirements.md) and [12 · Traceability matrix](12-traceability-matrix.md).

## Purpose of the assessment

The assessment evaluates more than project completion. It looks at the candidate's approach to Laravel development, database design, business logic, code structure, security, performance and maintainability.

## Project: Support Ticket & SLA Management System

Build a support ticket management system in Laravel. Customers create support tickets. Support agents manage, respond to, assign, resolve and track them.

---

## 1. Ticket management

Required operations:
- create a ticket
- view a ticket
- update a ticket
- resolve or close a ticket
- view upcoming or active tickets
- view resolved or closed tickets

Each ticket holds at least:

| Field | Field |
|---|---|
| Unique ticket ID | Status |
| Subject | Assigned agent |
| Description | Created by |
| Priority | Created date |
| Category | SLA / due time |

Example statuses: **Open**, **In Progress**, **Waiting for Customer**, **Resolved**, **Closed**. The statuses may be extended or changed if the design needs it.

## 2. Ticket ID generation

Generate a unique ticket number with a predefined prefix format, for example `TKT-2026-000001`. The implementation must guarantee uniqueness and allow for future growth.

## 3. Roles and permissions

At least these roles:
- Administrator
- Support Agent
- Customer / User

Expected behaviour:
- Customers can access only the tickets they are permitted to see.
- Support agents can perform only the actions they are authorized for.
- Administrators have appropriate administrative access.
- Authorization is enforced on the server.

The permission structure is up to the candidate.

## 4. Ticket assignment

Tickets can be assigned to support agents, both **manually** and **automatically**. The automatic strategy is up to the candidate; possible inputs include agent workload, round-robin, ticket category and agent availability. **Document the chosen approach.**

## 5. Concurrent ticket assignment

Scenario: two agents try to accept the same unassigned ticket at about the same time. The system must never assign the ticket to more than one agent. Design a solution and be ready to explain it.

## 6. Ticket conversation

Customers and agents communicate through the ticket. Support:
- customer replies
- agent replies
- internal agent notes

**Internal notes must never be visible to customers.**

## 7. Attachments

Customers and agents can attach files to tickets or replies. Consider:
- file validation
- file storage
- file size and type restrictions
- secure file access

## 8. SLA management

Each ticket has a resolution SLA based on its priority. Example:

| Priority | Resolution time |
|---|---|
| Low | 48 hours |
| Medium | 24 hours |
| High | 8 hours |
| Critical | 2 hours |

The application identifies tickets that are **within SLA**, **approaching an SLA breach**, and **SLA breached**. Changing the SLA rules must not require unnecessary changes throughout the application.

## 9. Business hours

SLA calculation respects configurable business hours. Example: business days Sunday to Thursday, business hours 9:00 AM to 6:00 PM.

A Critical ticket (2-hour SLA) created on Thursday at 5:00 PM shows why: adding two clock hours gives the wrong deadline. Design a correct approach. It need not cover every enterprise calendar scenario, but the implementation and reasoning must be clear and maintainable.

## 10. SLA escalation

Automatically identify and escalate tickets that are approaching or have exceeded their SLA. An escalation may include:
- marking the ticket as escalated
- notifying the appropriate users
- sending an email
- creating an escalation activity or history entry

The exact mechanism is up to the candidate.

## 11. Notifications

Notify people about important ticket events, for example: ticket created, ticket assigned, agent replied, customer replied, ticket resolved, SLA approaching, SLA breached. Email must be processed without needlessly blocking user requests.

## 12. Search and filtering

Search and filter tickets, for example by: ticket ID, keyword, status, priority, category, assigned agent, customer, created date, SLA status. **Results are paginated.**

## 13. CSV import

Import ticket data from CSV, designed to reasonably support large files. Consider:
- memory usage
- validation
- invalid rows
- duplicate data
- error reporting
- long-running processing

The Laravel implementation is up to the candidate.

## 14. Dashboard

A simple but useful dashboard. It may show: total open tickets, unassigned tickets, critical tickets, SLA-breached tickets, tickets approaching a breach, resolved tickets, tickets by priority, tickets by agent. Complex visualizations are not needed: usability, query quality and implementation matter more.

## 15. Activity / audit history

Important ticket activity must be traceable, with enough detail to understand what happened. Example:

| Time | Entry |
|---|---|
| 10:01 AM | Ticket created by User A |
| 10:05 AM | Assigned to Agent B |
| 10:20 AM | Priority changed from Medium to High |
| 10:35 AM | Agent replied |
| 11:00 AM | Ticket escalated |
| 11:15 AM | Status changed from In Progress to Resolved |

## 16. Database and performance

Design the database for an eventually large number of tickets, ticket replies, attachments, activity records and notifications. Evaluated: database structure, relationships, indexes, query efficiency, pagination, N+1 query handling and Eloquent usage. Generating millions of records is not required.

## 17. Automated testing

Include automated tests for the areas the candidate considers important. Which tests to write is deliberately unspecified: choosing what is critical enough to test is part of the evaluation.

---

## Technical expectations

The architecture is the candidate's choice. Design patterns or Laravel components are not required for their own sake: use Laravel features where they fit. Decisions must be reasonable, maintainable and explainable.

Areas that may be evaluated: validation, Eloquent relationships, authorization, business logic organization, jobs and queues, events and listeners, notifications, scheduler, database transactions, error handling, storage, configuration, testing, security, performance.

## AI tool usage

AI-assisted tools (ChatGPT, Claude, GitHub Copilot, Cursor and similar) are allowed and do not count against the candidate. The candidate is fully responsible for every line submitted. In the technical interview the candidate may be asked to explain any part of the implementation or a decision, debug an issue, modify a feature, add a small business requirement, refactor code, or update or write tests.

## Evaluation criteria

| Criterion | What is assessed |
|---|---|
| Project completion | Quality of completed functionality matters more than superficially covering everything. An incomplete project is not automatically rejected. |
| Laravel knowledge | Understanding and practical use of Laravel |
| Code structure | Organization, separation of concerns, maintainability, reusability, appropriate abstraction |
| Business logic | Turning SLA calculation, escalation, assignment, authorization and business hours into maintainable logic |
| Database management | Schema design, relationships, indexing, query efficiency, data integrity, transactions where needed |
| Security and authorization | Users reach only the resources and actions they are authorized for |
| Background processing | Appropriate handling of long-running or asynchronous work |
| Testing | Quality of the tests and the choice of what to test |
| Code quality | Readability, naming, consistency, maintainability, error handling, no unnecessary complexity |
| UI/UX | Reasonably clean, responsive and usable; not the primary focus |

## Bonus (optional)

Improvements that show additional skill, for example: Docker setup, API endpoints, API documentation, CI/CD, caching, real-time updates, import progress tracking, advanced reporting, rate limiting, additional automated tests, logging and monitoring, or other meaningful engineering work. Bonus work must not come at the cost of the core requirements.

## Submission requirements

1. A GitHub repository with the complete source code.
2. A README or documentation with complete instructions for running the project locally.
3. Database setup and migration instructions.
4. Required environment and configuration instructions.
5. Queue and scheduler setup instructions, if the implementation needs them.
6. Test execution instructions.
7. The important architectural decisions and assumptions.

A submission may be rejected if the instructions are not sufficient to set up and run the project. Do not commit production credentials, API keys, passwords or other secrets. Deploying a demo is optional and counts as a plus.

## Technical interview

The interview covers technical topics and the submitted project. Expect to explain the implementation, answer questions and make a small change during the interview: a business rule, a bug fix, an authorization change, a query improvement, or a new or updated test. The aim is to assess understanding of the code and the ability to work in an existing codebase, not memorized syntax.
