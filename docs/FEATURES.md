# Feature logic

What each feature does and the rules it follows, in plain language, for a reader who hasn't read the code. One section per feature. Each section describes only behaviour the code actually has, and holds no file, class or test references.

Each task's documenter replaces its own section's `Pending` line when the task is done. If a later task changes a feature's logic, that task's documenter updates the affected section in place and changes nothing else.

## Platform foundation
The application serves two front doors over the same rules: web pages for people and a versioned JSON API for programs. Both use the same validation, the same business operations and the same permission checks.

**Web and API**
- Web pages use a server-side session. The API lives under /api/v1, always answers in JSON, and authenticates with bearer tokens.
- When a business rule refuses an action, the API answers with the matching HTTP status and a body holding a message and a stable error code that clients can branch on. A web page goes back to where the user was and shows the message.
- Business rule refusals are expected outcomes, so they are not reported as application errors.

**Limits**
- API requests are limited to 60 per minute per signed-in user (per address for guests). File uploads are limited to 20 per minute per user. Token requests are limited to 5 per minute per email and address pair.

**Passwords**
- In production a password needs at least 12 characters with upper and lower case letters, numbers, symbols and no known leak. Elsewhere only the basic length rule applies.

**Scheduled work**
- A scan for SLA deadlines runs every minute (see SLA escalation). Background work such as notifications, automatic assignment and imports runs on the queue.

**Safety**
- Destructive database commands are blocked in production.

## Authentication
Authentication is plain email-and-password sign-in with a server-side session. There are no extra security layers on top of it.

**Logging in**
- A user signs in with an email and a password, and can tick "remember me" to stay signed in beyond the browser session.
- Wrong credentials give one generic error on the email field. It does not reveal whether the email exists.
- Sign-in attempts are limited to 5 per minute from the same address. After that the user must wait before trying again.
- After a successful sign-in the session is renewed. The user goes back to the page they originally asked for, or to the home page. The home page sends guests to the login page, customers to their ticket list, and staff (agents and admins) to the dashboard.

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
- Registration is limited to 5 attempts per minute from the same address.

**What is not included**
- No two-factor authentication, passkeys, email verification, password confirmation step or account settings pages.
- The colour scheme simply follows the operating system's light or dark preference. There is no appearance setting.

**API tokens**
- API clients get a token by posting an email, a password and a device name. The same generic error is returned for bad credentials.
- Token requests are limited to 5 per minute for each email and address combination.
- A verified email is not required to obtain a token. The token carries the user's current permissions.
- A client can revoke the token it is using.
- A client holding a token can fetch the details of the user the token belongs to.

## Data model
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
- Ticket created; Ticket updated (with the changed fields); Assigned to X or Reassigned from X to Y; Claimed by X; Status changed from A to B; Priority changed from A to B; Category changed from A to B; Agent replied or Customer replied; Internal note added (staff only); SLA paused while waiting for the customer; SLA resumed; SLA breach approaching; Ticket escalated: SLA breached; Ticket imported from CSV.

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

## SLA calculation
SLA calculation turns a ticket's priority into a due time and a warning time, counted only in open business time. It also says which escalation level a ticket should be at right now. It only calculates; other features decide when to call it.

**Business hours and closed days**
- The business is open Sunday to Thursday from 09:00 to 18:00. Friday and Saturday are closed.
- Business time is worked out in the business timezone, Asia/Dhaka by default. Times are stored in UTC and converted for the calculation.
- Any date can be marked as a holiday. On a holiday the business is closed for the whole day, whatever the weekly hours say.
- The list of holidays is cached. Adding, changing or removing a holiday takes effect immediately for new calculations, because the cached list is cleared on every change.
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

## Ticket numbering
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

## Authorization
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
- Claiming: any agent or admin may try to claim an active ticket (Open, In Progress or Waiting for Customer), and the claim succeeds only while nobody holds it. Resolved and Closed tickets cannot be claimed.
- Assigning: admins can assign any ticket. An agent can hand over only a ticket currently assigned to them.
- Replying: customers on their own tickets, and staff, can reply. Nobody can reply, public or internal, on a Closed ticket.
- Internal notes: only staff can write them, and not on a Closed ticket.

**Internal replies and attachments**
- Internal replies and internal attachments are visible to staff only.
- A customer who asks for one gets "not found", exactly as if it did not exist. A reply or attachment of a ticket the customer cannot see is also "not found".

**Admin-only areas**
- Categories, holidays, users and CSV imports are open to admins only. Agents and customers are refused for every operation on them.
- Users cannot be deleted from the application.

**Dashboard**
- Agents and admins can open the dashboard. Customers cannot.

**What the interface is told**
- Each page receives a set of yes/no flags: may view the dashboard, manage imports, manage users, manage categories, manage holidays, and create tickets on behalf of a customer. They come from the same rules as above, so menus and buttons match what the server will allow.
- Guests receive all of them as "no".

## Ticket lifecycle
Ticket lifecycle covers creating a ticket, editing it, moving it through its statuses, changing its priority, recording what happened on a timeline, and listing tickets. The same rules apply on the web pages and in the API.

**Creating a ticket**
- Customers, agents and admins can create tickets. A ticket needs a subject (up to 255 characters), a description (up to 20,000 characters), a priority and an active category.
- A customer always creates a ticket for themselves. Staff (agents and admins) always create a ticket on behalf of a customer and must give that customer's email address; they cannot create a ticket without naming a customer, and the email must belong to an existing customer account. A customer who sends a customer email is refused, so a customer cannot open tickets in someone else's name.
- The ticket records both who it is for (the customer) and who opened it.
- A new ticket always starts as Open and unassigned. It gets its human-readable number, and its SLA starts at once: the start time is the moment of creation, and the due and warning times come from the priority.
- Up to 5 files can be attached at creation, each up to 10 MB (10240 KB). Allowed types are pdf, png, jpg, jpeg, gif, txt, csv, doc, docx, xls, xlsx and zip. These limits are configurable.
- Files are stored on a private disk under a random name, grouped by ticket, and keep their original name, type and size on record. They are never publicly reachable. Attachments added at creation are not internal.
- Creation is all or nothing. The ticket, its number and its attachment records are saved in one transaction. If anything fails, nothing is kept, the number is given back, and any files already written are deleted.
- Once the ticket is saved, a "ticket created" event is announced; the timeline entry is written from that event.

**Editing a ticket**
- Subject and description can be edited by staff who manage the ticket (an admin, or the agent it is assigned to). A customer can edit their own ticket only while it is Open.
- The category can be changed only by staff who manage the ticket, and only to an active category. A customer who sends a category is refused.
- Only fields that really changed are saved. If nothing changed, nothing is saved and nothing is recorded.
- Status, priority, assignee and SLA fields cannot be changed through editing.
- Editing does not touch the SLA.

**Status changes**
- Allowed moves: Open to In Progress, Waiting for Customer or Resolved; In Progress to Waiting for Customer or Resolved; Waiting for Customer to In Progress or Resolved; Resolved to Closed or In Progress (reopening). Closed is final. Any other move is refused with a clear error and nothing changes.
- Staff who manage the ticket can make any allowed move. A customer can act only on their own Resolved ticket, and only to close it or reopen it.
- Moving to Waiting for Customer pauses the SLA at that moment. Leaving Waiting for Customer, to any status, resumes it: the business time that was left on the due and warning times at the moment of the pause is counted forward from the moment of resuming. If the escalation level is now too high for the new times, it is lowered, never raised.
- Moving to Resolved records the resolved time. Moving to Closed records the closed time. Reopening (Resolved to In Progress) clears the resolved time.
- A ticket that is Resolved or Closed no longer counts as running against its SLA.
- Concurrent changes to the same ticket are serialized, and each change is checked against the latest status.
- A resolved announcement is made in addition to the status change when a ticket becomes Resolved.

**Priority changes**
- Only staff who manage the ticket can change priority; customers never can.
- The due and warning times are recalculated for the new priority, counted from the ticket's original SLA start, not from the moment of the change.
- A pause in progress is kept. If the new deadline is later than the old one, the escalation level may be lowered (for example from breached back to approaching); it is never raised by a priority change.
- Choosing the priority the ticket already has changes nothing and records nothing.

**Activity timeline**
- Every ticket has a timeline, newest first. Entries are written only in response to events, never directly by the actions.
- Recorded: ticket created (or imported), fields updated, category changed (with old and new names), assigned, claimed, status changed, priority changed (with the new due time), SLA paused, SLA resumed (with the new due and warning times), replied, internal note added, SLA approaching and escalated (SLA breached).
- Each entry has a plain-language description such as "Status changed from Open to In Progress", a type and some details.
- The actor shown is the user who did it. Entries that the system produces on its own (SLA approaching, escalated) have no actor.
- A subject edit records the old and new subject; a description edit records only that the description changed, not its text.
- Customers never see internal-note entries. Every other entry is visible to the customer who owns the ticket.
- If writing a timeline entry fails, the failure is reported but the change itself is not undone.
- The ticket page shows the newest entries first, one page at a time (20 by default), with a "Load older activity" button for the rest. The API serves the timeline in pages, 20 by default and at most 100 per page.

**Ticket lists**
- There are two lists. Active shows Open, In Progress and Waiting for Customer tickets (the default). Resolved shows Resolved and Closed tickets.
- Newest tickets come first.
- Staff see all tickets. A customer sees only the tickets they are the customer on.
- Pages hold 20 tickets by default; a request can ask for 1 to 100 per page, and anything above 100 is rejected.
- Each row shows number, subject, status, priority, category, customer, assigned agent, SLA state and due time, escalation level and a reply count. For customers the reply count excludes internal replies.
- The SLA state of a ticket is one of: Within SLA, Approaching breach, Breached, Paused (waiting for the customer) or Stopped (resolved or closed).

**Viewing a ticket**
- The ticket page shows the details, the conversation, the attachments, the timeline, and the actions the viewer may take (edit, change status, change priority, claim, assign, reply, internal note). The allowed status choices offered are only the moves that are both legal and permitted for that viewer.
- The conversation is shown one page of the newest replies at a time (20 by default, oldest of those first), with a "Load older replies" button for the rest. Internal-note visibility is the same on every page.
- The details show who opened the ticket when that is someone other than the customer (for example an agent filing on the customer's behalf). The ticket list shows each ticket's category.
- The API ticket response includes the ticket's own attachments (name, type, size and download link), and who created it. Internal attachments are left out for customers.
- Customers receive only public replies and public attachments, both on the ticket and on each reply. Internal replies and internal attachments are left out of what they receive.
- A customer who asks for a ticket that is not theirs, or tries any action on it, gets "not found" rather than "forbidden", so they cannot learn whether it exists. A customer who can see a ticket but is not allowed an action on it (for example changing its priority) gets "forbidden".

## Assignment
Every ticket has at most one assigned agent. A ticket can get its agent in three ways: an agent claims it, a person with the right permission assigns or hands it off, or the system picks one automatically. Customers cannot assign tickets, and no assignment can be set through ticket creation or edit forms.

**Claiming**
- An agent claims an unassigned ticket for themselves. Only active tickets (not resolved or closed) can be claimed, and the agent needs the claim permission and must be able to see the ticket.
- The claim only succeeds if the ticket is still unassigned at that moment. If another agent or the automatic assignment got there first, the claim is refused with an "already claimed" error and nothing changes. Two agents claiming at the same time can never both win.
- Claiming a ticket that is Open moves it to In Progress. A ticket in any other active status keeps its status.

**Manual assignment and hand-off**
- Someone with the assign-any permission can assign any ticket to an agent or reassign it. An agent with the hand-off permission can pass on only the tickets currently assigned to them.
- The chosen person must be an available agent: they must have the agent role and be marked available. Anyone else is refused with a "not an eligible agent" error. Manual assignment does not check the agent's categories.
- Resolved and closed tickets cannot be assigned; the attempt is refused and nothing changes.
- Assigning a ticket to the agent who already has it changes nothing and records nothing.
- When an administrator changes an agent to another role, that agent's active tickets become unassigned (an "unassigned" entry is added to each ticket's history) so other agents can claim them. Their finished tickets are left as they are.
- Manual assignment does not change the ticket's status.
- A customer who may not view the ticket gets "not found"; a viewer who may see it but not assign it gets "forbidden".

**Automatic assignment**
- When a ticket is created, it is queued for automatic assignment, unless the ticket came from a CSV import, the setting for automatic assignment is turned off, or it already has an agent.
- Assignment runs in the background and is attempted up to three times in total if it fails, with a growing wait between attempts (10 seconds, then 1 minute, then 5 minutes). Only one assignment job per ticket is queued at a time.
- Before assigning, the job checks again that the ticket still exists, is still unassigned and is still active. Otherwise it does nothing.
- The candidates are available agents who are assigned to the ticket's category. The agent with the fewest active tickets wins. Ties go to the agent who was assigned a ticket least recently, with agents who were never assigned anything first, then to the lowest user id. This spreads work evenly and rotates between equally busy agents.
- If no agent is eligible, the ticket simply stays in the unassigned queue and a log entry notes it. This is not an error, and agents can still claim it. All admins are also told, by inbox and email, that the ticket needs manual assignment; the customer is not told.
- If someone claimed the ticket while the job was running, the automatic assignment backs off and changes nothing.
- Like a claim, an automatic assignment moves an Open ticket to In Progress.

**What gets recorded**
- Every assignment records the agent's "last assigned" time, which feeds the rotation rule above. The ticket's timeline shows who assigned it to whom and whether it was a claim, a manual assignment or an automatic one. The status change from Open to In Progress is recorded as its own timeline entry.

## Conversation and attachments
A ticket carries a conversation of replies. A reply is either public or an internal note, and may carry attachments.

**Posting a reply**
- Anyone who can see a ticket and holds the reply permission can post a reply, except on a closed ticket. A closed ticket accepts no replies or notes: the permission check refuses the attempt as forbidden before anything is saved. The "ticket closed" error only appears in the rare case where the ticket closes while the reply is being saved. Resolved tickets still accept replies.
- The reply text is required and can be up to 20,000 characters. Up to 5 files can be attached to one reply.
- The author is always the signed-in user. Posting a reply also bumps the ticket's last-updated time.
- Posting is limited to 20 requests per minute per user.
- Everything happens in one step: if the reply or any attachment fails, no reply is saved and files already written are removed.

**Public replies and internal notes**
- Staff with the internal-notes permission can mark a reply as an internal note. If anyone else sends the internal flag, it is ignored and the reply is saved as a public reply, so a customer can never create or see an internal note.
- Internal notes, and any attachment on an internal note, are never visible to customers: they are left out of the conversation list, the ticket page, reply counts and attachment lists, and are not downloadable. Staff see everything.
- The timeline records a public reply and an internal note as different kinds of entry, without the text.

**Customer reply resumes the SLA**
- When the ticket's own customer posts a public reply while the ticket is Waiting for Customer, the ticket moves back to In Progress and the paused SLA clock resumes, with the due time pushed out by the time spent waiting. A reply by anyone else, or an internal note, does not do this.

**Attachments**
- Allowed types are PDF, PNG, JPG, JPEG, GIF, TXT, CSV, DOC, DOCX, XLS, XLSX and ZIP. A file may be up to 10 MB, and the type and the extension must both be allowed. These limits come from configuration.
- Files are stored privately under a random name, never in a publicly reachable place. The original file name is kept for display and for the download. An attachment is public or internal following the reply it belongs to.

**Downloading**
- Files are downloaded only through a permission-checked route, never by a direct link. The viewer must be allowed to see the ticket, and an internal attachment is only for staff.
- A customer who may not see the ticket or the attachment gets "not found", so they cannot tell it exists. The file is sent as a download under its original name with its recorded type, and browsers are told not to guess its content type.

**Reading the conversation**
- The conversation is listed oldest first in pages (20 by default, up to 100 per page), and customers receive only the public part. The web ticket page also shows the upload limits to the user.

## Search and filtering
The ticket list can be narrowed and sorted. It always shows only tickets the viewer is allowed to see, so a customer's filters can never reveal anyone else's tickets, and staff filters work inside the same visibility rules.

**Lists and scopes**
- The list has two scopes. Active shows tickets that are Open, In Progress or Waiting for Customer. Resolved shows Resolved and Closed tickets. Active is the default.
- Lists are paginated: 20 per page by default, never more than 100 per page. Filters stay in the page links.

**Filters** (all optional and combinable)
- Ticket number: matches numbers that start with what was typed, in any letter case.
- Keyword: searches the subject and the description. Each word matches words that start with it (for example "print" finds "printer"). Words shorter than three characters are ignored by the search, because the database does not index words that short. At most 10 words are used, up to 200 characters are accepted, and search operator characters such as quotes, plus, minus and asterisks are stripped so typing them cannot change how the search behaves. Where full-text search is not available, a plain "contains" match is used instead.
- Status and priority: one or several values. The status choices offered depend on the scope.
- Category, assigned agent, customer: by id. A "no agent" option shows only unassigned tickets.
- Created from and to: calendar dates inclusive of both days, interpreted in the business time zone. The end date cannot be before the start date.
- SLA state: shows tickets in the chosen SLA state, using the same definition of the state as the rest of the system.
- Unknown values for status, priority, SLA state or sort are rejected, and a category must exist.

**Sorting**
- Newest first (default), oldest first, recently updated, least recently updated, due soonest, due latest, highest priority, lowest priority. Priority sorts by importance (urgent first when highest), not alphabetically. Ties are broken by ticket id so paging is stable. Any other sort value is rejected.

**What the page offers**
- The filter form offers the statuses for the current scope, priorities, SLA states, sorts and active categories. Staff also get a list of agents to filter by and the customer and agent filters; customers do not see those.

## SLA escalation
A background scan runs every minute and raises the escalation level of tickets whose SLA clock is running out. The scan only ever raises a level. The level is lowered elsewhere: when a ticket leaves Waiting for Customer or its priority changes, the deadlines are recalculated and the level drops if the ticket is no longer close to its new deadline, so it can escalate again later.

**Which tickets are scanned**
- Only active tickets whose SLA clock is running. Tickets whose clock is paused (Waiting for Customer), and resolved or closed tickets, are never escalated.
- Approaching: the warning time has passed but the due time has not. The ticket moves to the "approaching" level.
- Breached: the due time has passed. The ticket moves to the "breached" level and the moment of escalation is recorded on it. A ticket that was never flagged as approaching (for example because the scan was down) goes straight to breached.
- A ticket is escalated once per level. Scanning it again changes nothing and sends nothing, and two scans running at once cannot escalate the same ticket twice. Scans do not overlap and run on only one server at a time.

**What happens on escalation**
- The timeline gets an entry, with no person attached because the system did it: "SLA approaching" or "escalated", with the due time.
- Notifications go out (see Notifications and inbox): approaching warns the assigned agent, or the admins when nobody is assigned; breached tells the assigned agent and all admins.

## Notifications and inbox
People are told about ticket events by email and in an in-app inbox. Every notification is sent in the background after the change has been saved, so a failed save never produces a message.

**Who is told what**
- A ticket is created: the customer gets a confirmation. When automatic assignment is turned off, the admins are also told that a new ticket is waiting to be assigned (except the admin who created it).
- Automatic assignment finds no eligible agent: all admins are told the ticket needs manual assignment.
- A ticket is assigned (claim, manual or automatic): the agent is told it is theirs, and the customer is told which agent is handling it. The person who made the assignment is not told about their own action.
- A public reply: if the author is not the customer, the customer is told. If the customer wrote it, the assigned agent is told, or the admins when nobody is assigned. The author is never notified of their own reply.
- An internal note: the assigned agent is told (not the author), by inbox only. The customer is never told about internal notes and never receives their text.
- A ticket is resolved: the customer is told, unless they resolved it themselves.
- SLA approaching and breached: see SLA escalation.
- An import finishes: the admin who started it is told how many tickets were imported and how many rows failed, by inbox only, with a link to the import.
- Tickets created by a CSV import send no creation notifications.
- The same person is never notified twice for one event, and the person who caused the event is not notified about their own action. The only exception is the confirmation a customer receives for a ticket they opened themselves.

**Channels**
- Ticket notifications go by email and into the inbox. Internal notes and import results go to the inbox only. Each notification has a title, a short text and a link to the ticket (or the import); a ticket notification carries the ticket number. Deadlines in messages are shown in the business time zone.

**The inbox**
- Anyone who can view tickets (customers see their own, staff see all) has an inbox of their own notifications, newest first, 20 per page (up to 100). It can be limited to unread ones. The unread count is shown across the application.
- A user can mark one notification read or mark all read. Marking someone else's notification is answered with "not found", so notifications can never be read or changed across users.

## CSV ticket import
Administrators can bring existing tickets in from a CSV file. The import runs in the background, so large files do not block the page, and its progress can be followed on screen or through the API. Only people with the import permission can start or view imports, and uploads are limited to 20 per minute per user.

**The file**
- It must be a CSV (or text) file, up to 50 MB by default. The first row is the header.
- Columns: external reference, subject, description, priority, category, customer email, status, created at. The required columns are subject, description, priority, category and customer email. Header names are matched without regard to case, and a leading byte-order mark is ignored.
- A header with a missing required column, an unknown column or a repeated column fails the whole import at once with a message saying which. An empty file fails the same way.
- Blank lines are skipped. Rows are worked through in chunks (500 rows by default), each starting directly at its place in the file, and a chunk failing unexpectedly is recorded without stopping the others.

**Row rules** (each row is checked on its own, and bad rows never stop good ones)
- Subject is required (up to 255 characters), description is required, priority must be a valid priority, and category must be the short name (slug) of an active category. Priority, status, category and email are read without regard to case, and surrounding spaces are trimmed.
- The customer email must belong to an existing customer.
- The external reference is optional (up to 64 characters) and must be unique among all tickets. This stops the same row from being imported twice, including when two imports race.
- Status is optional and defaults to Open. Created at is optional and defaults to now; it is read in the business time zone, must be a valid date and must not be in the future.
- A row with the wrong number of columns is rejected.
- A row without an external reference but with a created-at date is rejected as a duplicate when the same customer already has a ticket with the same subject and created-at time ("Duplicate of an existing ticket."), or when an earlier row in the same chunk of the file has the same customer, subject and created-at time ("Duplicate of row N."). A row is only remembered for the "Duplicate of row N." check once it was actually imported, so that message never points at a row that failed. The check is a read made before the insert: identical rows that sit in different chunks of the file and are processed in parallel by several workers can both be imported. Uploading the same file again imports nothing new when the earlier rows were already imported by the time the new upload is processed. A row without a created-at date takes the time of import, so it cannot be recognised as a duplicate.

**What an imported ticket gets**
- It receives a normal ticket number, counted in the year of its created-at date, and its SLA clock starts from that created-at date, so an old ticket may already be approaching or breached when it arrives. Waiting for Customer starts paused; Resolved and Closed get their resolved (and closed) time set to the time of import and have no running SLA.
- It is created by the importing admin on behalf of the customer, is not auto-assigned and sends no creation notifications. Its timeline shows that it was imported.

**Progress and result**
- An import goes Pending, then Processing, then ends as Completed (all rows imported), Completed with errors (some rows rejected) or Failed (bad header, an unexpected failure, or a chunk that crashed). Total, processed, imported and failed row counts and the start and finish times are kept.
- Each rejected row is stored with its line number, the reasons and the original values. The error list can be paged through, and downloaded as a CSV that repeats the original columns beside the reasons. Cells that could start a spreadsheet formula are neutralised in that download.
- When the import ends, the admin who started it is notified.

## Categories and holidays administration
Administrators with the matching permission maintain the lists that drive ticket handling. Everyone else gets "forbidden".

**Categories**
- The list shows every category, active or not, with how many tickets use it, alphabetically.
- A category has a name (up to 100 characters), a unique slug (lower case letters and digits separated by single hyphens, up to 100 characters; entered in upper case it is lowercased first) and an active flag, which is on by default.
- Only active categories are offered when creating tickets or filtering, and only active ones are accepted by the import. Deactivating a category does not touch tickets that already use it.
- Deleting a category that no ticket uses removes it. Deleting one that tickets use does not remove it: it is deactivated instead and the response shows it as inactive.

**Holidays**
- A holiday is a date with a name (up to 100 characters). Each date can exist only once. Dates must be written as year-month-day.
- The list shows one year at a time, in date order, 50 per page; the current year in the business time zone is the default, and years from 2000 to 2100 are accepted.
- Adding, changing or removing a holiday takes effect immediately for SLA calculation: the cached list of holidays used by the business calendar is cleared. Deadlines already stored on existing tickets are not recalculated.

## Dashboard statistics
Agents and admins have a dashboard; customers do not, and anyone without access gets "forbidden". People who can open it are sent there when they open the home page.

**Totals** (for all tickets, not only the viewer's)
- Open: active tickets (Open, In Progress, Waiting for Customer).
- Unassigned: active tickets with no agent.
- Critical: active tickets with critical priority.
- Breached: active tickets with a running SLA clock past their due time. Approaching: running tickets past their warning time but not yet due. Paused tickets are in neither.
- Resolved: tickets resolved or closed in the last 30 days by default (configurable).

**Breakdowns**
- By priority: the number of active tickets for each priority, most urgent first, with zero shown where there are none.
- By agent: every agent with their active ticket count and breached count, busiest first, then by name.

**My tickets**
- The viewer's own assigned active tickets, the five most due soonest, in the same form as the ticket list.

**Freshness**
- The totals and breakdowns are shared by all viewers and kept for 60 seconds, so they can lag behind reality by up to a minute. The viewer's own ticket list is always current.

## Security and performance
**Who can do what**
Every action on tickets, replies, attachments and the admin areas is decided by a policy that combines the user's permissions with ownership: a customer only deals with their own tickets, an agent with what their permissions and assignments allow. The same rules apply on the web and through the API.

**Hiding what a customer may not see**
When a customer is not allowed to view a ticket, the answer is "not found", never "forbidden", so the existence of other people's tickets is not revealed. Staff who can see a ticket but lack a specific permission get a normal "forbidden" for that action.

**Internal notes**
Internal replies and internal attachments are visible only to users with the internal-notes permission. A customer who asks for one directly gets "not found". Customers never receive them in ticket pages, API responses, notifications or the activity history.

**Uploads**
- Files are stored on a private disk, never in a publicly served folder, under a random generated name, so the original name cannot be guessed or used to overwrite anything. The original name is kept only for display and download.
- Only the allowed file types are accepted: pdf, png, jpg, jpeg, gif, txt, csv, doc, docx, xls, xlsx and zip. The check looks at the extension and the real content type of the file, not only its name.
- By default a single file may be up to 10 MB and one ticket or reply may carry up to 5 files. Both limits are configurable. A request that breaks a limit is rejected and nothing is stored; if storing fails part-way, the files already written are removed.
- Downloads go through a check of the same rules as viewing the ticket, so internal attachments stay hidden from customers. The file is sent as a download with its original name, the stored content type and a header that stops browsers from guessing a different type.

**Rate limits**
- API requests: 60 per minute, counted per signed-in user (per address when not signed in).
- Uploads: 20 per minute per user, applied to creating a ticket, posting a reply and uploading an import file, on the web and through the API.
- API token requests: 5 per minute per email and address combination.
- Login, password-reset requests and password resets: 5 per minute.
Going over a limit returns a "too many requests" response.

**API documentation**
The interactive API documentation is available to signed-in users who may manage users, which in practice means administrators. In the local environment it is open to everyone.

**Query efficiency**
Loading a related record on demand is blocked outside production, so a page that would run one query per row fails during development and testing instead of quietly slowing down. Ticket, user and notification lists run the same number of queries whether they show a few rows or many, and every list is paginated with a capped page size of 100.

**Indexes**
The tickets table has indexes for the common filters and for the SLA checks: status with due date, status with warning date, assignee with status, customer with creation date, category with status, priority with status, status with resolution date, creation date, and last update; ticket numbers and external references are unique; subject and description have a full-text index for search. Notifications are indexed by recipient and creation date, so the inbox shows the newest first without sorting. A test runs the real customer and staff lists, the filters, the SLA scan, the dashboard, the activity timeline and the inbox against a few thousand tickets and checks with the database's execution plan that none of them scans a whole table.

**Housekeeping**
Growing tables are cleaned up automatically every night. Activity entries older than a year are removed, read notifications older than 90 days are removed (unread ones are kept), and finished queue batches and failed jobs older than a week are removed. Removing old activity entries and old read notifications is done in small batches so it never locks the table for long. Removing finished queue batches and failed jobs is done by Laravel's own cleanup commands, each as a single delete that is not batched. The periods are configurable.

## Password reset
Guests who forgot their password can set a new one through a link sent by email. The login page has a "Forgot password?" link to the request page.

**Requesting a link**
- The user enters their email. The answer is always the same message, "If an account exists for that email, a password reset link has been sent.", whether or not the email belongs to an account. No error is shown for an unknown email, so nobody can find out who has an account.
- Only an existing account actually receives the email. In development the email is written to the application log instead of being delivered.
- A link stays valid for 60 minutes. Asking again for the same email within 60 seconds does not send another message.
- Requests are limited to 5 per minute from the same address.
- Only guests can use these pages. A signed-in user is sent to the home page.

**Setting the new password**
- The link opens a page with the email filled in. The user types the new password twice.
- The password follows the same rule as registration (stricter in production, 8 characters minimum elsewhere).
- If the link is wrong, expired, already used or does not match the email, the reset fails with one error on the email field. Nothing is changed.
- On success the password is replaced, the "remember me" login is invalidated, and every API token of that user is revoked, so old devices must sign in again. The user is sent to the login page with the message "Your password has been reset. You can log in now." They are not signed in automatically.
- Reset attempts are limited to 5 per minute from the same address.

## User and role management
Admins manage accounts from an admin users page and from the matching API. Only users who hold the user-management permission (admins) can open any of it; everyone else is refused. Accounts are never deleted, so there is no delete option.

**Listing users**
- The list is paginated (20 per page by default, at most 100) and sorted by name.
- It can be filtered by role and searched by typing the beginning of a name or an email. The search matches from the start of the text only, not from the middle.
- Each row shows the user's role and, for agents, their categories and availability.

**Creating a user**
- An admin enters a name, an email, a password and one role (admin, agent or customer). The email must be lower case and unique, the password follows the registration rule.
- For an agent the admin can also pick the categories the agent handles and whether the agent is available for automatic assignment. A new agent is available unless said otherwise. These fields are ignored for other roles.
- The new account can sign in straight away.

**Editing a user**
- An admin can change name, email, role, and for agents the categories and availability.
- The password field is optional. Left empty, the password is unchanged. When filled in, the password is replaced and all of the user's API tokens are revoked.
- For an agent, categories and availability that are not sent keep their current values.

**Changing a role**
- Each user has exactly one role. Choosing the role they already have changes nothing.
- An admin cannot change their own role, so an admin cannot lock themselves out by accident. The attempt is refused with a clear error.
- When a role really changes, all of the user's API tokens are revoked, because tokens carry the permissions the user had when they were issued. A user who stops being an agent also loses their category assignments.

## Permission matrix
Admins can open a read-only page, and an API list, showing the three fixed roles (admin, agent, customer) in that order, with the permissions each one has.
- The page shows a table of every permission against every role, with a mark where the role has it.
- It reflects what was granted when the system was set up. Roles and permissions cannot be created, edited or removed from the application.
- It is open to the same people who can manage users.
