# Feature logic

What each feature does and the rules it follows, in plain language, for a reader who hasn't read the code. One section per feature. Each section describes only behaviour the code actually has, and holds no file, class or test references.

Each task's documenter replaces its own section's `Pending` line when the task is done. If a later task changes a feature's logic, that task's documenter updates the affected section in place and changes nothing else.

## Platform foundation (T00)
_Pending._

## Authentication (T00a)
Authentication is plain email-and-password sign-in with a server-side session. There are no extra security layers on top of it.

**Logging in**
- A user signs in with an email and a password, and can tick "remember me" to stay signed in beyond the browser session.
- Wrong credentials give one generic error on the email field. It does not reveal whether the email exists.
- Sign-in attempts are limited to 5 per minute from the same address. After that the user must wait before trying again.
- After a successful sign-in the session is renewed. The user goes back to the page they originally asked for, or to the home page. The home page sends guests to the login page and every signed-in user to the dashboard.

**Signed-in users and the guest pages**
- A signed-in user who opens the login or register page is sent to the home page instead.

**Logging out**
- Logging out ends the session, renews the form-protection (CSRF) token and returns the user to the login page. Only signed-in users can log out.

**Customer self-registration**
- Anyone can register with a name, an email and a password typed twice. The name and email may be up to 255 characters. The email must be entered in lower case: an address with capital letters is rejected, not converted.
- The email must be unique.
- The password rule depends on the environment. In production it needs at least 12 characters with upper and lower case letters, numbers and symbols, and it must not appear in known data breaches. Outside production the default minimum of 8 characters applies.
- The new account is signed in straight away and sent to the home page.
- Self-registration gives the new account the customer role.

**What is not included**
- No two-factor authentication, passkeys, email verification, password reset, password confirmation step or account settings pages.
- The colour scheme simply follows the operating system's light or dark preference. There is no appearance setting.

**API tokens**
- API clients get a token by posting an email, a password and a device name. The same generic error is returned for bad credentials.
- Token requests are limited to 5 per minute for each email and address combination.
- A verified email is not required to obtain a token. The token carries the user's current permissions.
- A client can revoke the token it is using.
- A client holding a token can fetch the details of the user the token belongs to.

## Data model (T01)
The data model is the shared vocabulary every other feature builds on.

**Records and how they relate**
- Users are customers, agents or admins. Agents have an availability flag and a set of categories they handle.
- Every ticket belongs to one category and one customer, records who created it (the customer or a staff member acting on their behalf), and may be assigned to one agent. It has a unique ticket number and an optional external reference that must also be unique.
- A ticket has replies. A reply is written by one user and is either public or an internal note. Attachments belong to a ticket, may belong to a specific reply, and can be marked internal.
- Holidays are dates on which the business is closed. CSV imports record who ran them, their progress and a list of per-row errors.
- There is one counter per year for ticket numbers.
- Each ticket also has a history of activity entries; each entry reads as one timeline line (listed below).

**Roles**
- Customers can create tickets, see and update their own tickets and reply to them.
- Agents can create tickets (including on behalf of a customer), see all tickets, reply, add internal notes, claim tickets, update tickets assigned to them, hand off their own tickets and view the dashboard.
- Admins can do everything agents can except hand off, and additionally update and assign any ticket, run CSV imports, manage users, categories and holidays.
- Agents and admins together are "staff". Each role's permissions are set to exactly this list; re-running the setup neither duplicates permissions nor leaves stale ones.

**Statuses**
- Open, In Progress, Waiting for Customer, Resolved and Closed. Open, In Progress and Waiting for Customer are active; Resolved and Closed are finished.
- Allowed changes: Open can go to In Progress, Waiting for Customer or Resolved. In Progress can go to Waiting for Customer or Resolved. Waiting for Customer can go to In Progress or Resolved. Resolved can go to Closed or back to In Progress. Closed is final and allows no change.

**Priorities and assignment methods**
- Priorities from lowest to highest are Low, Medium, High and Critical, and they sort in that order.
- A ticket is assigned manually, claimed by an agent, or assigned automatically.

**SLA state and escalation**
- A ticket's SLA state is worked out from its due time and its warning time. It is Stopped once the ticket is Resolved or Closed, and Paused while the SLA clock is paused. Otherwise it is Breached at or after the due time, Approaching at or after the warning time, and Within before that. Both limits are inclusive.
- Escalation level is 0 (none), 1 (approaching) or 2 (breached).

**Who can see what**
- Customers see only their own tickets. Staff see all tickets.
- Customers never see internal notes or the "Internal note added" timeline entry. Staff see everything.

**Fields that cannot be set directly**
- A ticket's number, assignee, status, SLA fields and escalation level cannot be filled in from user input. Only the subject, description, priority, category, customer, creator and external reference can. Role assignment never comes from user input either.

**Deleting records**
- Nothing is soft-deleted; deletion is permanent.
- Deleting a ticket deletes its replies and attachments. Deleting a reply deletes its attachments.
- A user who is a ticket's customer or creator, a reply author, an attachment uploader or an importer, and a category that tickets use, cannot be deleted.
- Deleting an agent leaves their tickets unassigned.
- Deleting an agent or category removes the agent-category links.

**Timeline lines**
- Ticket created; Ticket updated (with the changed fields); Assigned to X or Reassigned from X to Y; Claimed by X; Unassigned from X; Status changed from A to B; Priority changed from A to B; Category changed from A to B; Agent replied or Customer replied; Internal note added (staff only); SLA paused while waiting for the customer; SLA resumed; SLA breach approaching; Ticket escalated: SLA breached; Ticket imported from CSV (with the row number when known).

**Domain errors**
- Invalid status change (422): the move is not in the allowed list.
- Already claimed (409): another agent claimed the ticket first.
- Agent not eligible (422): the chosen user is not an available agent for the ticket.
- Ticket closed (422): a closed ticket no longer accepts changes.
- Invalid import header (422): the CSV columns do not match what is expected.

**Imports**
- An import is Pending, Processing, Completed, Completed with errors or Failed.

**Demo data**
- Seeding creates an admin (admin@example.com), four agents (agent1 to agent4@example.com), and five customers (customer1 to customer5@example.com), all with the password "password".
- There are three categories: Billing, Technical Support and General Inquiry. Agent 4 is unavailable.
- Sixty tickets are created with sequential numbers, mixed statuses and priorities, and some breached or approaching. Open tickets are unassigned; all others are assigned to an available agent who handles the ticket's category.
- Running the seed again when the demo data already exists adds nothing.

## SLA calculation (T02)
SLA calculation turns a ticket's priority into a due time and a warning time, counted only in open business time. It also says which escalation level a ticket should be at right now. It only calculates; other features decide when to call it.

**Business hours and closed days**
- The business is open Sunday to Thursday from 09:00 to 18:00. Friday and Saturday are closed.
- Business time is worked out in the business timezone, Asia/Dhaka by default. Times are stored in UTC and converted for the calculation.
- Any date can be marked as a holiday. On a holiday the business is closed for the whole day, whatever the weekly hours say.
- The list of holidays is cached. Adding, changing or removing a holiday takes effect for new calculations only once the cached list is refreshed.
- The weekly hours and the timezone are configurable; holidays are a stored list of dates. Invalid settings (an unknown timezone, a window that ends before it starts, overlapping windows, a malformed time or holiday date) are rejected rather than guessed at.

**How a due time is computed**
- Only open business time counts. Time outside opening hours, on closed days and on holidays is skipped.
- If counting starts outside business hours, it begins at the next opening.
- An amount of time that exactly fills the rest of a day ends at closing time on that day, not at opening time on the next one.
- Zero minutes of business time means the due time is the start itself.
- Times are treated to the whole second.
- Safety limit: if no open business time is found within 366 days, the calculation fails with an error instead of looping forever. This only happens if the hours or holidays leave the business closed for a year.
- Example: a Critical ticket created on Thursday at 17:00 has 1 hour left that day (until 18:00). Friday and Saturday are closed, so the remaining hour is counted from Sunday 09:00, and the ticket is due Sunday at 10:00.

**Resolution times and the warning point**
- Each priority has a resolution time in business time: Low 48 business hours, Medium 24, High 8, Critical 2.
- The warning point is 75% of the resolution time, rounded down to whole minutes, and is counted in business time from the same start as the due time.
- The resolution times and the warning percentage are configurable. Every priority must have a positive whole number of minutes and the percentage must be between 0 and 100, otherwise the setup is rejected.

**New tickets**
- A new ticket gets its start time, due time and warning time from its priority and its start.

**Pause and resume**
- Pausing records when the pause began. Pausing a ticket that is already paused changes nothing.
- On resume, the business time that was left at the moment of the pause, both to the due time and to the warning point, is carried over. The remaining time is rounded down to whole minutes. The new due and warning times are that remaining time counted forward from the moment of resuming.
- If the ticket was already past its due time (or warning point) when it was paused, nothing is left, so the new time is simply the moment of resuming.
- Resuming a ticket that is not paused changes nothing.

**Priority change**
- The due and warning times are recomputed for the new priority, counting from the ticket's original start.
- A pause that is still going on is kept as it is.
- Time that an earlier, finished pause had credited is not kept: the recalculation starts over from the original start, so those credits are lost.

**Escalation level**
- The calculation can say which level the ticket should have now: none, approaching (at or after the warning point) or breached (at or after the due time).
- While a ticket is paused, the level is judged at the moment the pause began, so it is frozen and does not worsen during the pause.
- Recalculating after a priority change, and resuming, only ever lower a ticket's stored level, for example when a longer deadline moves it back from breached to approaching. They never raise it.

## Ticket numbering (T03)
Every ticket gets a unique, human-readable number. This feature produces that number.

**Format**
- A number reads like `TKT-2026-000042`: a prefix, the year, and a sequence number padded to six digits. The prefix is configurable and defaults to TKT.
- Once the sequence passes 999999 the number simply gets longer (seven digits, and so on). It never wraps or restarts mid-year.

**Counting**
- There is one counter per year. Each new number is the previous one plus one, so numbers within a year never repeat. They are normally consecutive; a gap can appear only in the rare case where saving the ticket fails at the very last step.
- The first ticket of a year starts at 1, and the previous year's counter is left untouched.
- The year is the year in the business timezone, not UTC. A ticket created at 18:00 UTC on 31 December is already in the next year for a business in Dhaka.
- If the business timezone is missing or invalid, no number is issued and an error is raised instead of quietly falling back to UTC.

**Safe under load**
- A number is meant to be taken while the ticket is being saved, inside the same database transaction. If saving fails and the transaction is rolled back, the counter is rolled back too, so that number is given back and the next ticket reuses it.
- When several tickets are created at the same moment, they wait their turn for the year's counter. Each gets a different number. If one has to wait too long for its turn, it fails with an error instead of receiving a duplicate number.

## Authorization (T04)
Every action is allowed or refused by a rule that combines what the user's role permits with who owns the ticket and what state it is in. The same rules are used for web pages and API requests.

**Roles and what they can do**
- Customers can create tickets, see only the tickets they opened, reply on them and edit them.
- Agents can create tickets, including on behalf of a customer, and see every ticket. They can reply, write internal notes, claim tickets, and manage the tickets assigned to them, including handing those over. They can open the dashboard.
- Admins can do everything an agent can, except hand over their own tickets (they can assign any ticket instead), and they can update any ticket. They alone manage categories, holidays, users and CSV imports.

**Seeing tickets**
- Staff see all tickets. A customer sees only their own.
- When a customer asks for a ticket that is not theirs, the answer is "not found", never "forbidden", so they cannot tell whether it exists. The same applies to any other action on a ticket they cannot see.
- Anyone who can see a ticket, customers included, but may not perform the action gets "forbidden".

**Acting on a ticket**
- Editing: staff who manage the ticket can always edit it. A customer can edit their own ticket only while it is Open.
- Changing status: staff who manage the ticket can change it. A customer can act only on their own Resolved ticket, and only to close it or to reopen it (move it back to In Progress).
- Changing priority: staff who manage the ticket only. Customers never can.
- Managing a ticket means being an admin, or being the agent it is currently assigned to.
- Claiming: any agent or admin can claim an active ticket (Open, In Progress or Waiting for customer), whether or not someone else holds it. Resolved and Closed tickets cannot be claimed.
- Assigning: admins can assign any ticket. An agent can hand over only a ticket currently assigned to them.
- Replying: customers on their own tickets, and staff, can reply. Nobody can reply, public or internal, on a Closed ticket.
- Internal notes: only staff can write them, and not on a Closed ticket.

**Internal replies and attachments**
- Internal replies and internal attachments are visible to staff only.
- A customer who asks for one gets "not found", exactly as if it did not exist. A reply or attachment of a ticket the customer cannot see is also "not found".

**Admin-only areas**
- Categories, holidays, users and CSV imports are open to admins only. Agents and customers are refused for every operation on them.
- An admin cannot delete their own account.

**Dashboard**
- Agents and admins can open the dashboard. Customers cannot.

**What the interface is told**
- Each page receives a set of yes/no flags: may view the dashboard, manage imports, manage users, manage categories, manage holidays, and create tickets on behalf of a customer. They come from the same rules as above, so menus and buttons match what the server will allow.
- Guests receive all of them as "no".

## Ticket lifecycle (T05)
_Pending._

## Assignment (T06)
_Pending._

## Conversation and attachments (T07)
_Pending._

## Search and filtering (T08)
_Pending._

## SLA escalation (T09)
_Pending._

## Notifications (T10)
_Pending._

## CSV import (T11)
_Pending._

## Dashboard (T12)
_Pending._

## Administration (T13)
_Pending._

## Security and performance (T14)
_Pending._
