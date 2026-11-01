# 13 · Demo data

`php artisan migrate --seed` (or `migrate:fresh --seed`) fills the database with a realistic help desk, so every feature has something to show. This page says what is created and how.

## 1. How the seed works

- `DatabaseSeeder` always runs `RolesAndPermissionsSeeder` (roles and the permission matrix) and `SlaDefaultsSeeder` (copies the SLA defaults from config into the stored SLA settings, once). It runs `DemoSeeder` only when `APP_ENV` is `local` or `testing`.
- `DemoSeeder` does nothing if `admin@example.com` already exists, so running the seed twice adds nothing. Use `php artisan migrate:fresh --seed` to start over.
- **It replays history instead of inserting rows.** The seeder moves the clock back about 40 days and then performs every step through the real Actions (create ticket, claim, assign, reply, change status, change priority, import) at its planned moment, running `sla:scan` every two hours of simulated time and once at the end. So the timelines, SLA pauses, escalations, notifications and assignee rotation are exactly what the app itself would have produced.
- During the replay mail goes to an in-memory mailer, broadcasting is off, queued work runs inline and automatic assignment is driven explicitly by the scenarios. Nothing is emailed or broadcast.
- A fixed random seed (`DemoSeeder::FAKER_SEED`) makes the content the same on every run; only the dates move with "now".
- A fresh seed first removes avatar, attachment and import files left by an earlier demo seed.
- **Profile photos** for 14 of the 19 people are downloaded from Unsplash when the computer is online, then cropped and re-encoded like any uploaded avatar. Offline (or if a download fails) the seed carries on and those people show initials.

## 2. Accounts

Every account uses the password **`password`**. All demo accounts are created already verified, so they sign in straight to the app.

A customer who signs up through the registration page must confirm their email address before they can use the app; until then every page leads to the "Verify your email" page. Locally (the `log` mailer) the verification link is written to `storage/logs/laravel.log`; with Docker it lands in the Mailpit inbox at http://localhost:8025.

| Role | Email | Name | Categories and notes |
|---|---|---|---|
| Admin | `admin@example.com` | Ada Mensah | |
| Agent | `agent1@example.com` | Liam Carter | Billing, Technical Support |
| Agent | `agent2@example.com` | Priya Sharma | Technical Support |
| Agent | `agent3@example.com` | Marcus Chen | General Inquiry |
| Agent | `agent4@example.com` | Sofia Rossi | Billing, General Inquiry. Went on leave six days ago: marked unavailable and her active tickets were reassigned, so she is never auto-assigned |
| Agent | `agent5@example.com` | Daniel Okafor | Account & Access, Technical Support |
| Agent | `agent6@example.com` | Hannah Weber | Feature Requests, General Inquiry, Billing |
| Customer | `customer1@example.com` | Emma Johnson | Harbor & Pine Coffee |
| Customer | `customer2@example.com` | Noah Williams | Kestrel Analytics |
| Customer | `customer3@example.com` | Olivia Brown | Lumen Dental Group |
| Customer | `customer4@example.com` | James Garcia | Orchard Lane Books |
| Customer | `customer5@example.com` | Ava Martinez | Brightpath Tutors |
| Customer | `customer6@example.com` | Lucas Silva | Copperleaf Interiors |
| Customer | `customer7@example.com` | Mia Anderson | Fieldstone Realty |
| Customer | `customer8@example.com` | Ethan Patel | Nimbus Travel |
| Customer | `customer9@example.com` | Chloe Dubois | Quarry Hill Fitness |
| Customer | `customer10@example.com` | Benjamin Lee | Saltmarsh Media |
| Customer | `customer11@example.com` | Zara Ahmed | Tidewater Clinics |
| Customer | `customer12@example.com` | Ryan O'Connor | Verdant Grocers |

Each person also gets a "last seen" time (from "just now" to several days ago, and never for a few customers), so presence labels and the agents directory look lived-in.

## 3. What else is created

Figures from a seed run on 2026-11-01; ticket states depend on the time you seed.

| Data | Amount |
|---|---|
| Categories | 6: Billing, Technical Support, General Inquiry, Account & Access, Feature Requests, and Legacy Desktop App (inactive) |
| Holidays | 4 upcoming dates, always in the future: Victory Day (16 Dec), Christmas Day (25 Dec), New Year's Day (1 Jan), International Mother Language Day (21 Feb) |
| SLA settings | the defaults: Low 48 h, Medium 24 h, High 8 h, Critical 2 h, warning at 75%, Sunday–Thursday 09:00–18:00, Asia/Dhaka |
| Saved replies | 9: 6 team replies and 3 personal ones, some tied to a category, using placeholders |
| Tickets | 88 (82 from the stories, 6 from the import) |
| By status | about 10 Open, 25 In Progress, 12 Waiting for Customer, 21 Resolved, 20 Closed |
| By priority | about 7 Critical, 22 High, 28 Medium, 31 Low |
| SLA | a handful breached and approaching among the active tickets, 12 paused while waiting for the customer |
| Replies | about 250, of which about 27 are internal notes |
| Attachments | about 36 (screenshots, logs, spreadsheets, PDFs), some on internal notes |
| Activity entries | about 650 |
| Notifications | about 600 across all inboxes; the admin, agent 1, agent 2 and customer 1 have read theirs at different times |
| CSV import | `legacy-helpdesk-export.csv`, finished 70 hours before the seed: 9 rows, 6 imported, 3 rejected (an invalid email, a customer that does not exist, and a duplicate row without a reference) |

## 4. The scenarios

The tickets are grouped into stories, each showing one part of the product:

| Story group | What it shows |
|---|---|
| Untouched | brand-new Open tickets, some unassigned, one filed by an agent on a customer's behalf |
| Assigned without a reply | claimed, manually assigned and auto-assigned tickets that nobody answered yet (the "Unresponded" filter) |
| In progress | ongoing conversations, some approaching or past their deadline |
| Waiting for customer | paused SLA clocks, including ones where the customer replied and the ticket resumed |
| Resolved and closed | finished work for the dashboard's resolved counts and trend |
| Reopened | customer replies that reopened resolved tickets with their remaining SLA time |
| Hand-offs | tickets passed between agents, and the reassignment when agent 4 went on leave |
| Category and priority changes | timeline entries and SLA recalculation from the original start |
| Internal-only | tickets with internal notes and internal attachments that customers never see |
| On behalf | tickets created by staff for a customer |
| Long conversations | threads long enough to show paging, the unread divider and the "new messages" pill |

## 5. Before a presentation

**Reseed shortly before you present:**

```bash
php artisan migrate:fresh --seed
```

SLA states are computed from the current time. As hours pass, "approaching" tickets become "breached" and the scheduler escalates them, and over a weekend (Friday and Saturday are closed days) nothing moves at all. A fresh seed puts the desk back in its intended mix. It takes about ten seconds, a little longer while photos download.

Then start the app with `composer run dev` so the scheduler, queue worker and live updates run during the demo. With Docker, the `queue`, `scheduler` and `reverb` services already run; reseed with `docker compose exec app php artisan migrate:fresh --seed`.
