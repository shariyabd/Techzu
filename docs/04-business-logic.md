# 04 · Business logic

What each feature does and the rules it follows, in plain language, for a reader who hasn't read the code. One section per feature. Each section describes only behaviour the code actually has, and holds no file, class or test references. Where to find the code is in [12 · Traceability matrix](12-traceability-matrix.md); why it works this way is in [11 · Technical decisions](11-technical-decisions.md).

If a later task changes a feature's logic, that task's documenter updates the affected section in place and changes nothing else.

**Contents**

1. [Platform foundation](#platform-foundation)
2. [Authentication](#authentication)
3. [Profile and avatars](#profile-and-avatars)
4. [Data model](#data-model)
5. [SLA calculation](#sla-calculation)
6. [SLA rules and business hours administration](#sla-rules-and-business-hours-administration)
7. [Ticket numbering](#ticket-numbering)
8. [Authorization](#authorization)
9. [Ticket lifecycle](#ticket-lifecycle)
10. [Assignment](#assignment)
11. [Conversation and attachments](#conversation-and-attachments)
12. [Saved replies](#saved-replies)
13. [Chat inbox, presence and typing](#chat-inbox-presence-and-typing)
14. [Realtime updates](#realtime-updates)
15. [Search and filtering](#search-and-filtering)
16. [Customer lookup](#customer-lookup)
17. [SLA escalation](#sla-escalation)
18. [Notifications and inbox](#notifications-and-inbox)
19. [CSV ticket import](#csv-ticket-import)
20. [Categories and holidays administration](#categories-and-holidays-administration)
21. [Dashboard statistics](#dashboard-statistics)
22. [Agents directory](#agents-directory)
23. [Security and performance](#security-and-performance)
24. [Password reset](#password-reset)
25. [User and role management](#user-and-role-management)
26. [Permission matrix](#permission-matrix)

## Platform foundation
The application serves two front doors over the same rules: web pages for people and a versioned JSON API for programs. Both use the same validation, the same business operations and the same permission checks.

**Web and API**
- Web pages use a server-side session. The API lives under /api/v1, always answers in JSON, and authenticates with bearer tokens.
- When a business rule refuses an action, the API answers with the matching HTTP status and a body holding a message and a stable error code that clients can branch on. A web page goes back to where the user was and shows the message.
- Business rule refusals are expected outcomes, so they are not reported as application errors.

**Limits**
- API requests are limited to 60 per minute per signed-in user (per address for guests). File uploads (ticket creation, replies, imports, profile pictures) are limited to 20 per minute per user. Token requests are limited to 5 per minute per email and address pair. Password changes from the profile are limited to 6 per minute. Customer lookups and the saved-reply picker are limited to 60 per minute.

**Passwords**
- In production a password needs at least 12 characters with upper and lower case letters, numbers, symbols and no known leak. Elsewhere only the basic length rule applies.

**Scheduled and background work**
- A scan for SLA deadlines runs every minute (see SLA escalation). Background work such as notifications, automatic assignment, SLA recalculation, live-update messages and imports runs on the queue.

**Live updates**
- Pages update live when a realtime server is running (see Realtime updates). Without it every page still works; it simply does not refresh by itself.

**Safety**
- Destructive database commands are blocked in production.

## Authentication
Authentication is plain email-and-password sign-in with a server-side session. There are no extra security layers on top of it.

**Logging in**
- A user signs in with an email and a password, and can tick "remember me" to stay signed in beyond the browser session.
- Wrong credentials give one generic error on the email field. It does not reveal whether the email exists.
- Passwords longer than 72 characters are refused, because the password hashing only looks at the first 72 bytes and would silently ignore the rest.
- Simple mistakes (an empty field, an email that is not an email, a password that is too short or too long, two passwords that differ) are caught in the browser and shown under the field before anything is sent. The server still checks everything again; checks that need the server (wrong credentials, an email already taken, a leaked password) are only shown after submitting.
- Sign-in attempts are limited to 5 per minute from the same address. After that the user must wait before trying again.
- After a successful sign-in the session is renewed. The user goes back to the page they originally asked for, or to the home page. The home page sends guests to the login page, customers to their ticket list, and staff (agents and admins) to the dashboard.

**Signed-in users and the guest pages**
- A signed-in user who opens the login or register page is sent to the home page instead.

**Logging out**
- Logging out ends the session, renews the form-protection (CSRF) token and returns the user to the login page. Only signed-in users can log out.
- Page data kept in the browser history is encrypted, and logging out (or signing in or registering) throws the key away. Pressing Back after logging out therefore cannot show the previous user's pages: the browser has to ask the server again, and the server sends a guest to the login page. Encryption needs a secure origin (HTTPS or localhost).

**Customer self-registration**
- Anyone can register with a name, an email and a password typed twice. The name and email may be up to 255 characters. The email must be entered in lower case: an address with capital letters is rejected, not converted.
- The email must be unique.
- The password rule depends on the environment. In production it needs at least 12 characters with upper and lower case letters, numbers and symbols, and it must not appear in known data breaches. Outside production the default minimum of 8 characters applies. In every environment a password may be at most 72 characters.
- The new account is signed in straight away and sent to the home page.
- Self-registration gives the new account the customer role.
- Registration is limited to 5 attempts per minute from the same address.

**What is not included**
- No two-factor authentication, passkeys, email verification or password confirmation step. Account settings are limited to the profile page (see Profile and avatars).
- The colour scheme simply follows the operating system's light or dark preference. There is no appearance setting.

**API tokens**
- API clients get a token by posting an email, a password and a device name. The same generic error is returned for bad credentials.
- Token requests are limited to 5 per minute for each email and address combination.
- A verified email is not required to obtain a token. The token carries the user's current permissions.
- A client can revoke the token it is using.
- A client holding a token can fetch the details of the user the token belongs to.

## Profile and avatars
Every signed-in user, whatever their role, has a profile page, and the same actions exist in the API. These actions always apply to the signed-in user, so there is nothing to choose and nothing another user could reach.

**Name and email**
- The name can be changed (up to 255 characters, surrounding spaces trimmed). The email is shown but cannot be changed here; only an admin can change it.

**Password**
- Changing the password needs the current password, the new one typed twice, and the new one must differ from the current one. The usual password rule applies.
- On success every other signed-in session of the user ends, the "remember me" login is invalidated and the user's other API tokens are revoked. The session (or token) that made the change stays signed in.
- Password changes are limited to 6 per minute.

**Profile picture**
- A picture can be JPG, PNG or WebP, up to 2 MB, and between 64 and 4096 pixels on each side. The type is checked from the file content as well as the name.
- The picture is cropped to the centre square and resized to 256 by 256 pixels. Re-encoding it this way also drops any hidden data the original file carried, such as camera or location details. If the server lacks the image library, the validated original is stored as it is.
- Pictures are stored privately under a random name and are only shown to signed-in users: everyone sees their own picture, users who may view all tickets see everyone's, and customers see only staff pictures. Asking for any other picture answers "not found". Pictures are served with headers that stop browsers from guessing a different type or running anything inside them. The link to a picture changes whenever the picture changes, so browsers never show an old one.
- Uploading a new picture replaces the old one; removing it brings back the initials placeholder.
- Admins can also set or remove another user's picture when editing that user.

## Data model
The data model is the shared vocabulary every other feature builds on.

**Records and how they relate**
- Users are customers, agents or admins. Every user may have a profile picture and a "last seen" time. Agents have an availability flag and a set of categories they handle.
- Every ticket belongs to one category and one customer, records who created it (the customer or a staff member acting on their behalf), and may be assigned to one agent. It has a unique ticket number and an optional external reference that must also be unique. A ticket that came from a CSV import remembers which import it came from.
- A ticket has replies. A reply is written by one user and is either public or an internal note. Attachments belong to a ticket, may belong to a specific reply, and can be marked internal.
- Holidays are dates on which the business is closed. CSV imports record who ran them, their progress and a list of per-row errors.
- The SLA rules (resolution time per priority, warning percentage, business timezone and weekly opening hours) are stored records that admins edit.
- Saved replies are reusable reply texts, either personal (owned by one user) or shared with the team, optionally tied to a category.
- There is one counter per year for ticket numbers.
- Each ticket also has a history of activity entries; each entry reads as one timeline line (listed below).

**Roles**
- Customers can create tickets, see and update their own tickets and reply to them.
- Agents can create tickets (including on behalf of a customer), see all tickets, reply, add internal notes, claim tickets, update tickets assigned to them, hand off their own tickets, view the dashboard and the agents directory, and use saved replies.
- Admins can do everything agents can except hand off, and additionally update and assign any ticket, run CSV imports, manage users, categories, holidays and the SLA rules, and manage team saved replies.
- Each role's permissions are seeded to exactly this list; re-running the setup neither duplicates permissions nor leaves stale ones. Admins can change the list afterwards (see Permission matrix).

**Statuses**
- Open, In Progress, Waiting for Customer, Resolved and Closed. Open, In Progress and Waiting for Customer are active; Resolved and Closed are finished.
- Allowed changes: Open can go to In Progress, Waiting for Customer or Resolved. In Progress can go to Waiting for Customer or Resolved. Waiting for Customer can go to In Progress or Resolved. Resolved can go to Closed or back to In Progress. Closed is final and allows no change.

**Priorities and assignment methods**
- Priorities from lowest to highest are Low, Medium, High and Critical, and they sort in that order.
- A ticket is assigned manually, claimed by an agent, or assigned automatically.

**SLA state and escalation**
- A ticket's SLA state is worked out from its due time and its warning time. It is Stopped once the ticket is Resolved or Closed, and Paused while the SLA clock is paused. Otherwise it is Breached at or after the due time, Approaching at or after the warning time, and Within before that. Both limits are inclusive.
- Escalation level is 0 (none), 1 (approaching) or 2 (breached). Separately, the ticket remembers the highest level that has already been announced, so an announcement is never sent twice (see SLA escalation).

**Who can see what**
- What a user can see follows their permissions, not their role name: someone who may view all tickets sees every ticket; someone who may view only their own sees the tickets where they are the customer. The same rule drives ticket lists, queue counts, the ticket page, the conversation, attachments and the timeline, so these can never disagree.
- Only users with the internal-notes permission see internal notes, their attachments and the "Internal note added" entry. In the default setup those are agents and admins.
- Escalation is staff information: the escalation level and the "SLA breach approaching" and "Ticket escalated" timeline entries are shown only to users who may view all tickets and hold the internal-notes permission respectively. Customers still see their ticket's SLA state and due time.

**Fields that cannot be set directly**
- A ticket's number, assignee, status, SLA fields and escalation level cannot be filled in from user input. Only the subject, description, priority, category, customer, creator and external reference can. Role assignment never comes from user input either.

**Deleting records**
- Nothing is soft-deleted; deletion is permanent.
- Deleting a ticket deletes its replies and attachments. Deleting a reply deletes its attachments.
- A user who is a ticket's customer or creator, a reply author, an attachment uploader or an importer, and a category that tickets use, cannot be deleted.
- Deleting an agent leaves their tickets unassigned. Deleting a user deletes their personal saved replies.
- Deleting an agent or category removes the agent-category links. Deleting a category keeps saved replies tied to it, without a category.

**Timeline lines**
- Ticket created; Ticket imported from CSV (row N); Ticket updated (with the changed fields); Assigned to X or Reassigned from X to Y; Claimed by X; Unassigned from X; Status changed from A to B; Priority changed from A to B; Category changed from A to B; Agent replied or Customer replied; Internal note added (staff only); SLA paused while waiting for the customer; SLA resumed; Deadline updated (after an SLA rules recalculation); SLA breach approaching (staff only); Ticket escalated: SLA breached (staff only).

**Domain errors**
- Invalid status change (422): the move is not in the allowed list.
- Already claimed (409): another agent claimed the ticket first.
- Agent unavailable (422): an agent marked as unavailable tried to claim a ticket.
- Internal note not allowed (403): someone without the internal-notes permission asked for an internal note; nothing is saved.
- Agent not eligible (422): the chosen user is not an available agent, or does not handle the ticket's category.
- Ticket closed (422): a finished ticket no longer accepts the change.
- Invalid import header (422): the CSV columns do not match what is expected.
- Cannot change own role (422), cannot remove user management from admins (422), customers cannot receive internal notes (422).
- Invalid SLA settings (422): the submitted SLA rules break a validation rule.

**Imports**
- An import is Pending, Processing, Completed, Completed with errors or Failed.

**Demo data**
- Seeding creates a demo help desk with an admin, six agents, twelve customers, six categories (one inactive), holidays, saved replies, 88 tickets with real conversations and a finished CSV import. Every demo account uses the password "password". [13 · Demo data](13-demo-data.md) lists the accounts and the scenarios.
- Running the seed again when the demo data already exists adds nothing.

## SLA calculation
SLA calculation turns a ticket's priority into a due time and a warning time, counted only in open business time. It also says which escalation level a ticket should be at right now. It only calculates; other features decide when to call it.

**Business hours and closed days**
- By default the business is open Sunday to Thursday from 09:00 to 18:00, in the Asia/Dhaka timezone. Friday and Saturday are closed. Admins can change the hours and the timezone (see SLA rules and business hours administration).
- Business time is worked out in the business timezone. Times are stored in UTC and converted for the calculation. A day may have several opening windows, for example around a lunch break.
- Any date can be marked as a holiday. On a holiday the business is closed for the whole day, whatever the weekly hours say.
- The list of holidays is cached. Adding, changing or removing a holiday takes effect immediately for new calculations: every change marks the cached list as out of date once it is saved, so the next calculation reads the list again. A calculation that was reading the old list at the same moment cannot leave that old list behind in the cache.

**How a due time is computed**
- Only open business time counts. Time outside opening hours, on closed days and on holidays is skipped.
- If counting starts outside business hours, it begins at the next opening.
- An amount of time that exactly fills the rest of a day ends at closing time on that day, not at opening time on the next one.
- Zero minutes of business time means the due time is the start itself.
- Times are treated to the whole second. Remaining and paused business time is also counted to the whole second, so many short pauses never add up to a drift.
- Safety limit: if no open business time is found within 366 days, the calculation fails with an error instead of looping forever. Admin validation makes this practically impossible, because the week must keep at least one open window.
- Example: a Critical ticket created on Thursday at 17:00 has 1 hour left that day (until 18:00). Friday and Saturday are closed, so the remaining hour is counted from Sunday 09:00, and the ticket is due Sunday at 10:00.

**Resolution times and the warning point**
- Each priority has a resolution time in business time. The defaults are Low 48 business hours, Medium 24, High 8, Critical 2.
- The warning point is a percentage of the resolution time (75% by default), rounded down to whole minutes, and is counted in business time from the same start as the due time.

**New tickets**
- A new ticket gets its start time, due time and warning time from its priority and its start, using the SLA rules in force at that moment.

**Paused time is remembered**
- Each ticket keeps a running total of the business seconds its clock has been stopped: while waiting for the customer, and while resolved before a reopen. Every recalculation counts from the original start, adds the resolution time, and adds this total. So a pause credited once is never taken back later.

**Pause and resume**
- Pausing records when the pause began. Pausing a ticket that is already paused changes nothing.
- On resume, the business time that was left at the moment of the pause, both to the due time and to the warning point, is carried over to the second. The new due and warning times are that remaining time counted forward from the moment of resuming. The business time spent paused is added to the ticket's stopped total, also to the second.
- If the ticket was already past its due time (or warning point) when it was paused, nothing is left, so the new time is simply the moment of resuming.
- Resuming a ticket that is not paused changes nothing.

**Reopening a resolved ticket**
- Time spent resolved does not count against the SLA. On reopen, the business time that was left when the ticket was resolved is counted forward from the moment of reopening, exactly as a resume does, and the time spent resolved is added to the stopped total.
- A ticket that was already breached when it was resolved has nothing left, so it is still breached when reopened.
- The timeline records "SLA resumed" with the new due and warning times.

**Priority change**
- The due and warning times are recomputed for the new priority, counting from the ticket's original start plus the time already credited for pauses and resolved periods. A priority change never takes back credited time.
- A pause that is still going on is kept as it is.

**Escalation level**
- The calculation can say which level the ticket should have now: none, approaching (at or after the warning point) or breached (at or after the due time).
- While a ticket is paused, the level is judged at the moment the pause began, so it is frozen and does not worsen during the pause. A finished ticket is judged at the moment it was resolved.
- Recalculating (after a priority change, a change of SLA rules, a resume or a reopen) only ever lowers a ticket's stored level, for example when a longer deadline moves it back from breached to approaching. It never raises it; raising is the escalation scan's job.

**The countdown on screen**
- Every active ticket shows how much SLA time is left, counted in business time only, so it matches how the deadline itself was worked out. The wall clock is never used for this: a Critical ticket created on Thursday at 17:00 has one business hour left on Thursday evening, even though Sunday 10:00 is more than 60 wall-clock hours away.
- The countdown only moves while the business is open. During opening hours it counts down second by second on the ticket page, with a small pulsing dot and the note "business hours". Outside opening hours, on closed days and on holidays it stands still and says when the clock resumes (the next opening, shown in the business timezone).
- When the deadline has passed, the countdown turns into "Overdue by …", which also grows only during business hours.
- While a ticket is waiting for the customer, the countdown is frozen at the time that was left when the pause began, and reads "… left when the customer replies".
- Resolved and closed tickets show no countdown.
- Ticket lists, the dashboard and the agents directory show the same countdown to the minute ("1h 20m left", "Overdue 40m"), without seconds. The exact due time stays in the tooltip, and the ticket page keeps its "SLA due" line.
- Durations of a day or more are shown as days and hours, where one day means 24 business hours, not one calendar day.
- The server works out the remaining business time and the next moment the clock starts or stops each time the page loads; the browser only counts between those moments. Any change to the ticket refreshes the figures from the server.
- Customers see the countdown on their own tickets too; it only reveals the deadline they can already see.

## SLA rules and business hours administration
Admins with the SLA permission edit the SLA rules on one settings page, and through the API. Everyone else is refused with "forbidden".

**What can be edited**
- The resolution time for each priority, in business minutes, from 1 minute up to 720 hours.
- The warning percentage, from 1 to 99.
- The business timezone, which must be a real timezone name.
- The weekly opening hours: for each day, up to 6 windows written as HH:MM. A window can also run until midnight ("Until midnight", stored as 24:00), so a round-the-clock day counts every minute; 24:00 can only be an end time. Each window must end after it starts, windows on the same day may not overlap, and the week must keep at least one open window.
- A change that breaks any of these rules is refused as a whole; nothing is saved.

**Where the rules come from**
- The stored settings are the single source every calculation reads, and they are cached until the next change. A saved change marks the cached settings as out of date, so a reader that loaded the old settings at the same moment cannot keep them in the cache. The configuration file supplies the defaults: setting up the database copies them into the stored settings once, and they are used only if no stored settings exist.

**What a change affects**
- New tickets use the new rules at once.
- Tickets that already exist keep their stored deadlines, unless the admin ticks "Recalculate active tickets". Then a background job walks through every active ticket, one at a time with the ticket locked, and recomputes its due and warning times from its original start, its credited paused time and the new rules. A ticket whose due time moved gets a "Deadline updated" timeline entry. A recalculation can lower a ticket's escalation level but never raises it; the next escalation scan raises it if needed.
- Without the option, an active ticket keeps its stored deadlines until something recalculates it anyway: a priority change recalculates it with the new rules, and a paused clock that restarts counts the time left in the new business hours.
- The same "Recalculate active tickets" option is offered when a holiday is added, changed or removed.
- Only one recalculation runs at a time.
- Every screen, list, filter, count and email reads the stored deadlines, so they always agree with each other, whether or not a recalculation ran.
- The business timezone chosen here is the one used everywhere: for business-hours math, for the year in ticket numbers, for date filters, for the dashboard's days, for times in emails and for dates in imported files.

## Ticket numbering
Every ticket gets a unique, human-readable number. This feature produces that number.

**Format**
- A number reads like `TKT-2026-000042`: a prefix, the year, and a sequence number padded to six digits. The prefix is configurable and defaults to TKT.
- Once the sequence passes 999999 the number simply gets longer (seven digits, and so on). It never wraps or restarts mid-year.

**Counting**
- There is one counter per year. Each new number is the previous one plus one, so numbers within a year never repeat. They are normally consecutive; a gap can appear only in the rare case where saving the ticket fails at the very last step.
- The first ticket of a year starts at 1, and the previous year's counter is left untouched.
- The year is the year in the business timezone, not UTC. A ticket created at 18:00 UTC on 31 December is already in the next year for a business in Dhaka. An imported ticket is numbered in the year of its own creation date.
- If the business timezone is missing or invalid, no number is issued and an error is raised instead of quietly falling back to UTC.

**Safe under load**
- A number is taken while the ticket is being saved, inside the same database transaction. If saving fails and the transaction is rolled back, the counter is rolled back too, so that number is given back and the next ticket reuses it.
- When several tickets are created at the same moment, they wait their turn for the year's counter. Each gets a different number. If one has to wait too long for its turn, it fails with an error instead of receiving a duplicate number.
- File uploads never happen while the counter is held (see Ticket lifecycle), so the wait stays short.

## Authorization
Every action is allowed or refused by a rule that combines what the user's permissions allow with who owns the ticket and what state it is in. The same rules are used for web pages and API requests. [06 · Security and authorization](06-security-and-authorization.md) has the full matrix.

**Roles and what they can do**
- Customers can create tickets, see only the tickets they opened, reply on them and edit them.
- Agents can create tickets, including on behalf of a customer, and see every ticket. They can reply, write internal notes, claim tickets, and manage the tickets assigned to them, including handing those over. They can open the dashboard and the agents directory, look up customers and use saved replies.
- Admins can do everything an agent can, except hand over their own tickets (they can assign any ticket instead), and they can update any ticket. They alone manage categories, holidays, the SLA rules, users and roles, CSV imports and team saved replies.

**Seeing tickets**
- Visibility follows the permissions: "view all tickets" sees everything, "view own tickets" sees only tickets where the user is the customer.
- When someone asks for a ticket they may not see, the answer is "not found", never "forbidden", so they cannot tell whether it exists. The same applies to any other action on a ticket they cannot see.
- Anyone who can see a ticket, customers included, but may not perform the action gets "forbidden".

**Acting on a ticket**
- A Closed ticket is final: nobody can edit it, change its priority or move it to another status.
- Editing: staff who manage the ticket can edit its subject and category, also while it is Resolved. Only the ticket's own customer can edit the description (their own message), and only while it is Open.
- Changing status: staff who manage the ticket can change it. A customer can act only on their own Resolved ticket, and only to close it or to reopen it (move it back to In Progress).
- Changing priority: staff who manage the ticket, and only while the ticket is active. A Resolved ticket's priority cannot change. Customers never can.
- Managing a ticket means holding "update any ticket" (admins), or "update assigned tickets" while being the agent it is currently assigned to.
- Claiming: any agent or admin may try to claim an active ticket (Open, In Progress or Waiting for Customer), and the claim succeeds only while nobody holds it. Resolved and Closed tickets cannot be claimed.
- Assigning: admins can assign any active ticket. An agent can hand over only an active ticket currently assigned to them. Only admins may knowingly assign outside the ticket's category (see Assignment).
- Replying: customers on their own tickets, and staff, can reply. Nobody can reply, public or internal, on a Closed ticket.
- Internal notes: only users with the internal-notes permission can write them, and not on a Closed ticket.

**Internal replies and attachments**
- Internal replies and internal attachments are visible only to users with the internal-notes permission.
- Anyone else who asks for one gets "not found", exactly as if it did not exist. A reply or attachment of a ticket the user cannot see is also "not found".

**Staff-only areas**
- The dashboard, the agents directory and the customer lookup are for users who may view all tickets (the lookup also for anyone who may create tickets on behalf of customers). Customers get "forbidden".
- Saved replies are for agents and admins. Customers get "forbidden"; another user's personal reply is "not found".
- Categories, holidays, SLA rules, users, roles and CSV imports are open to admins only. Agents and customers are refused for every operation on them.
- Users cannot be deleted from the application.

**What the interface is told**
- Each page receives a set of yes/no flags: may view the dashboard, manage imports, manage users, manage categories, manage holidays, manage SLA rules, view the agents directory, use saved replies, and create tickets on behalf of a customer. They come from the same rules as above, so menus and buttons match what the server will allow.
- Guests receive all of them as "no".

## Ticket lifecycle
Ticket lifecycle covers creating a ticket, editing it, moving it through its statuses, changing its priority, recording what happened on a timeline, and listing tickets. The same rules apply on the web pages and in the API.

**Creating a ticket**
- Customers, agents and admins can create tickets. A ticket needs a subject (up to 255 characters), a description (up to 20,000 characters), a priority and an active category.
- A customer always creates a ticket for themselves. Staff always create a ticket on behalf of a customer and must name that customer by email address; on the web page they pick the customer from a search box (see Customer lookup). The email must belong to an existing customer account. A customer who sends a customer email is refused, so a customer cannot open tickets in someone else's name.
- The ticket records both who it is for (the customer) and who opened it.
- A new ticket always starts as Open and unassigned. It gets its human-readable number, and its SLA starts at once: the start time is the moment of creation, and the due and warning times come from the priority.
- Up to 5 files can be attached at creation, each up to 10 MB (10240 KB). Allowed types are pdf, png, jpg, jpeg, gif, txt, csv, doc, docx, xls, xlsx and zip. These limits are configurable.
- Files are stored on a private disk under a random name, in a folder per month, and keep their original name, type and size on record. They are never publicly reachable. Attachments added at creation are not internal.
- Creation is all or nothing. The files are written first, before the database work starts, so the ticket-number counter is never held while files are being written. Then the ticket, its number and its attachment records are saved in one short transaction. If anything fails, nothing is kept, the number is given back, and the files already written are deleted.
- Once the ticket is saved, a "ticket created" event is announced; the timeline entry, the automatic assignment and the notifications follow from that event.

**Editing a ticket**
- The subject can be edited by staff who manage the ticket (an admin, or the agent it is assigned to). A customer can edit the subject and description of their own ticket only while it is Open.
- The description is the customer's own words, so staff can never rewrite it, just as no one can edit someone else's chat message. Staff who need to clarify a ticket change its subject or category, or add a reply or internal note. A staff attempt to change the description is refused and nothing is saved.
- The category can be changed only by staff who manage the ticket, and only to an active category. A customer who sends a category is refused.
- Only fields that really changed are saved. If nothing changed, nothing is saved and nothing is recorded.
- Status, priority, assignee and SLA fields cannot be changed through editing.
- A Closed ticket cannot be edited at all; a Resolved one can still have its subject and category edited.
- Editing does not touch the SLA.

**Status changes**
- Allowed moves: Open to In Progress, Waiting for Customer or Resolved; In Progress to Waiting for Customer or Resolved; Waiting for Customer to In Progress or Resolved; Resolved to Closed or In Progress (reopening). Closed is final. Any other move is refused with a clear error and nothing changes.
- Staff who manage the ticket can make any allowed move. A customer can act only on their own Resolved ticket, and only to close it or reopen it.
- Moving to Waiting for Customer pauses the SLA at that moment. Leaving Waiting for Customer, to any status, resumes it (see SLA calculation).
- Moving to Resolved records the resolved time and stops the SLA. Moving to Closed records the closed time. Reopening (Resolved to In Progress) restarts the SLA with the business time that was left at resolution, and clears the resolved time.
- A ticket that is Resolved or Closed no longer counts as running against its SLA.
- Concurrent changes to the same ticket are serialized, and each change is checked against the latest status.
- A resolved announcement is made in addition to the status change when a ticket becomes Resolved.

**Priority changes**
- Only staff who manage the ticket can change priority, and only while the ticket is active (not Resolved or Closed); customers never can. The refusal is checked both in the permission rule and in the change itself.
- The due and warning times are recalculated for the new priority, counted from the ticket's original SLA start plus its credited paused time, not from the moment of the change.
- A pause in progress is kept. If the new deadline is later than the old one, the escalation level may be lowered (for example from breached back to approaching); it is never raised by a priority change.
- Choosing the priority the ticket already has changes nothing and records nothing.

**Activity timeline**
- Every ticket has a timeline, newest first. Entries are written only in response to events, never directly by the actions.
- Recorded: ticket created (or imported), fields updated, category changed (with old and new names), assigned, claimed, unassigned, status changed, priority changed (with the new due time), SLA paused, SLA resumed (with the new due and warning times), deadline updated (after a recalculation of the SLA rules, with the old and new due times), replied, internal note added, SLA approaching and escalated (SLA breached).
- Each entry has a plain-language description such as "Status changed from Open to In Progress", a type and some details.
- "SLA resumed" is recorded only when the clock really restarts: when a ticket leaves Waiting for Customer for another active status, or when a Resolved ticket is reopened. Resolving or closing a waiting ticket records only the status change, because its clock stays stopped.
- The actor shown is the user who did it. When a customer's reply ends a wait or reopens a ticket, the status change and "SLA resumed" entries show the customer. Entries that the system produces on its own (SLA approaching, escalated, deadline updated, automatic assignment) have no actor.
- When a claim or an assignment moves an Open ticket to In Progress, the timeline shows the assignment first, then the status change.
- A subject edit records the old and new subject; a description edit records only that the description changed, not its text.
- Without the internal-notes permission a viewer never sees "Internal note added", "SLA breach approaching" or "Ticket escalated" entries. Every other entry is visible to the customer who owns the ticket.
- If writing a timeline entry fails, the failure is reported but the change itself is not undone.
- The ticket page shows the newest entries first, one page at a time (20 by default), with a "Load older activity" button for the rest. The API serves the timeline in pages, 20 by default and at most 100 per page.

**Ticket lists**
- There are three lists. Active shows Open, In Progress and Waiting for Customer tickets (the default). Resolved shows Resolved and Closed tickets. All shows every status.
- Newest tickets come first.
- Each viewer sees only the tickets the visibility rule allows (see Authorization).
- Pages hold 20 tickets by default; a request can ask for 1 to 100 per page, and anything above 100 is rejected.
- Each row shows number, subject, status, priority, category, customer, assigned agent, SLA state and due time, and a reply count. Each row also says whether anyone other than the customer has replied publicly yet. Only viewers who may view all tickets also see the escalation level. For customers the reply count excludes internal replies.
- The SLA state of a ticket is one of: Within SLA, Approaching breach, Breached, Paused (waiting for the customer) or Stopped (resolved or closed).
- Queue counts (how many tickets are in each list and status) are counted with the same visibility rule. They are kept briefly in a cache that is thrown away whenever a ticket is created, assigned, unassigned, escalated, has its deadline recalculated, or changes status, priority or category, so they are fresh after every change; a 60-second limit backs this up.

**Viewing a ticket**
- The ticket page shows the details, the conversation, the attachments, the timeline, and the actions the viewer may take (edit, change status, change priority, claim, assign, reply, internal note). The allowed status choices offered are only the moves that are both legal and permitted for that viewer.
- The conversation is shown one page of the newest replies at a time (20 by default, oldest of those first), with a "Load older replies" button for the rest. Internal-note visibility is the same on every page.
- SLA due times and pause times are shown in the business timezone with a zone label (for example "Nov 2, 11:36 AM GMT+6"), whatever the viewer's own computer is set to. Relative times ("in 3 hours") are unaffected.
- The details show who opened the ticket when that is someone other than the customer (for example an agent filing on the customer's behalf). The ticket list shows each ticket's category.
- The API ticket response includes the ticket's own attachments (name, type, size and download link), and who created it. Internal attachments are left out for viewers without the internal-notes permission.
- Customers receive only public replies and public attachments, both on the ticket and on each reply.
- Someone who asks for a ticket they may not see, or tries any action on it, gets "not found" rather than "forbidden", so they cannot learn whether it exists. A customer who can see a ticket but is not allowed an action on it (for example changing its priority) gets "forbidden".

## Assignment
Every ticket has at most one assigned agent. A ticket can get its agent in three ways: an agent claims it, a person with the right permission assigns or hands it off, or the system picks one automatically. Customers cannot assign tickets, and no assignment can be set through ticket creation or edit forms.

**Who can be assigned**
- The chosen person must have the agent role and be marked available, and must handle the ticket's category. Anyone else is refused with a "not an eligible agent" error that says which rule failed.
- The assignment panel groups agents into those who handle the ticket's category and, for admins, a separate "Outside this category" group.
- Only someone with the assign-any permission (admins) may knowingly pick an agent outside the category; the request has to say so explicitly. The role and availability rules can never be overridden.

**Claiming**
- An agent claims an unassigned ticket for themselves. Only active tickets (not resolved or closed) can be claimed, and the agent needs the claim permission and must be able to see the ticket.
- The claim only succeeds if the ticket is still unassigned at that moment. If another agent or the automatic assignment got there first, the claim is refused with an "already claimed" error and nothing changes. Two agents claiming at the same time can never both win.
- Claiming a ticket that is Open moves it to In Progress. A ticket in any other active status keeps its status.
- An agent who is marked unavailable cannot claim; the claim is refused with an "agent unavailable" error and nothing changes.
- A claim does not require the agent to handle the ticket's category. Claiming is a deliberate choice by the agent to take a ticket from the shared unassigned queue, so the category rule (which protects agents from being handed work they did not pick) does not apply.

**Manual assignment and hand-off**
- Someone with the assign-any permission can assign any active ticket to an eligible agent or reassign it. An agent with the hand-off permission can pass on only the tickets currently assigned to them, to an eligible agent who handles the category.
- Resolved and closed tickets cannot be assigned; the attempt is refused and nothing changes.
- Assigning a ticket to the agent who already has it changes nothing and records nothing.
- Assigning an Open ticket moves it to In Progress, exactly as a claim does. A ticket in any other active status keeps its status.
- An assignment locks the ticket while it works, so a claim or another assignment arriving at the same moment waits and then sees the new state; a claim that loses this way gets "already claimed". After taking the lock, the assignment checks the permission again against the ticket as it is now, so an agent's hand-off that was checked before an admin reassigned the ticket is refused as forbidden instead of overwriting the admin's choice. The ticket always ends with exactly one agent and a matching history.
- When an administrator changes an agent to another role, that agent's active tickets become unassigned (an "unassigned" entry is added to each ticket's history) so other agents can claim them. Their finished tickets are left as they are.
- Someone who may not view the ticket gets "not found"; a viewer who may see it but not assign it gets "forbidden".

**Automatic assignment**
- When a ticket is created, it is queued for automatic assignment, unless the setting for automatic assignment is turned off, the ticket already has an agent, or it is not active.
- Tickets that arrive through a CSV import are not assigned one by one as they are created. If the admin ticked "Auto-assign active tickets" for that import (and automatic assignment is turned on), the import's active, unassigned tickets are assigned after the import finishes, in batches of 100, with the same rules as below. Imported Resolved and Closed tickets are never assigned.
- Assignment runs in the background and is attempted up to three times in total if it fails, with a growing wait between attempts (10 seconds, then 1 minute, then 5 minutes). Only one assignment job per ticket is queued at a time.
- Before assigning, the job checks again that the ticket still exists, is still unassigned and is still active. Otherwise it does nothing.
- The candidates are available agents who are assigned to the ticket's category. The agent with the fewest active tickets wins. Ties go to the agent who was assigned a ticket least recently, with agents who were never assigned anything first, then to the lowest user id. This spreads work evenly and rotates between equally busy agents.
- If no agent is eligible, the ticket simply stays in the unassigned queue and a log entry notes it. This is not an error, and agents can still claim it. All admins are also told, by inbox and email, that the ticket needs manual assignment; the customer is not told. For imported tickets this message is not sent.
- If someone claimed the ticket while the job was running, the automatic assignment backs off and changes nothing.
- Like a claim, an automatic assignment moves an Open ticket to In Progress.

**What gets recorded**
- Every assignment records the agent's "last assigned" time, which feeds the rotation rule above. The ticket's timeline shows who assigned it to whom and whether it was a claim, a manual assignment or an automatic one. The status change from Open to In Progress is recorded as its own timeline entry.

## Conversation and attachments
A ticket carries a conversation of replies. A reply is either public or an internal note, and may carry attachments.

**Posting a reply**
- Anyone who can see a ticket and holds the reply permission can post a reply, except on a closed ticket. A closed ticket accepts no replies or notes: the permission check refuses the attempt as forbidden before anything is saved. The "ticket closed" error only appears in the rare case where the ticket closes while the reply is being saved. Resolved tickets still accept replies.
- The reply text is required and can be up to 20,000 characters. Up to 5 files can be attached to one reply.
- The author is always the signed-in user. Posting a public reply also bumps the ticket's last-updated time. An internal note does not, so a customer cannot tell from the ticket's "last updated" time or the list order that staff wrote something. The "recently updated" sort therefore reflects public activity and ticket changes for everyone, staff included; staff see internal notes in the conversation itself.
- Posting is limited to 20 requests per minute per user.
- Files are written first, before the database work. If the reply itself cannot be saved, nothing is kept and the files are removed, including when the database refuses the final commit (for example on a deadlock). Once the reply is committed, its attachments are kept, even if something later fails, such as sending a notification.

**Public replies and internal notes**
- Users with the internal-notes permission can mark a reply as an internal note. If anyone else asks for an internal note, whether a customer or a staff member whose role lost the permission (for example from a page opened before the change), the request is refused as forbidden and nothing is saved or sent. It is never quietly posted as a public reply, so a note meant for staff can never reach the customer by mistake.
- Internal notes, and any attachment on an internal note, are never visible to customers: they are left out of the conversation list, the ticket page, reply counts and attachment lists, notifications, the timeline and live updates, and are not downloadable. Staff see everything.
- The timeline records a public reply and an internal note as different kinds of entry, without the text.

**A customer reply moves the ticket back to work**
- When the ticket's own customer posts a public reply while the ticket is Waiting for Customer, the ticket moves back to In Progress and the paused SLA clock resumes.
- When the customer posts a public reply on a Resolved ticket, the ticket is reopened: it moves to In Progress and its SLA restarts with the time that was left at resolution (see SLA calculation).
- A reply by anyone else, or an internal note, does neither. A Closed ticket refuses replies, so it can never be reopened this way.

**Attachments**
- Allowed types are PDF, PNG, JPG, JPEG, GIF, TXT, CSV, DOC, DOCX, XLS, XLSX and ZIP. A file may be up to 10 MB, and the type and the extension must both be allowed. These limits come from configuration.
- Files are stored privately under a random name, never in a publicly reachable place. The original file name is kept for display and for the download. An attachment is public or internal following the reply it belongs to.

**Downloading**
- Files are downloaded only through a permission-checked route, never by a direct link. The viewer must be allowed to see the ticket, and an internal attachment is only for users with the internal-notes permission.
- PNG, JPEG and GIF images also have a permission-checked preview route that shows the image in the page instead of downloading it. The same visibility rules apply. Any other type, including SVG, is never shown inline. The conversation shows image attachments as thumbnails that open a larger preview with a download button; other files stay as download chips.
- When picking files to attach, chosen images show a small thumbnail, and files with a type that is not allowed, over the size limit or over the file count are refused in the browser with a message before anything is uploaded.
- Someone who may not see the ticket or the attachment gets "not found", so they cannot tell it exists. The file is sent as a download under its original name with its recorded type, and browsers are told not to guess its content type.

**Reading the conversation**
- The conversation is listed oldest first in pages (20 by default, up to 100 per page), and customers receive only the public part. The web ticket page also shows the upload limits to the user.
- The conversation reads like a messaging app (see Chat inbox, presence and typing). The viewer's side is on the right: for staff, every staff message is on the right and the customer's messages (including the ticket's opening message) are on the left; for the customer it is the other way round. Each message has the author's picture, or initials when there is none.

## Saved replies
Agents and admins keep a library of reusable replies so common answers are typed once. Customers have no access ("forbidden").

**Personal and team replies**
- A personal reply belongs to the person who wrote it and only they see it. A team reply is shared with every agent and admin.
- Anyone who may use saved replies can create, edit and delete their own personal replies. Only admins (the team-replies permission) can create, edit or delete team replies.
- The owner is always the signed-in person; it is never taken from the request. Whether a reply is personal or shared is decided when it is created and cannot be changed afterwards.
- Opening someone else's personal reply answers "not found", so its existence is not revealed.

**Content**
- A saved reply has a title (up to 120 characters), a body (up to 5,000 characters) and an optional category.
- The body may use placeholders that are filled in when the reply is inserted: `{customer_name}`, `{customer_first_name}`, `{ticket_number}`, `{ticket_subject}`, `{agent_name}` and `{agent_first_name}` (the agent is the person inserting the reply). A body with any other `{placeholder}` is refused on save, naming the unknown one.

**Using a saved reply**
- The saved replies page has "My replies" and "Team replies" tabs, a search over title and body, and a live preview of the placeholders filled with example values.
- In the ticket's reply box a picker lists the replies the person may use, searchable, up to 50 at a time. Replies tied to the ticket's category come first.
- Choosing a reply fills in the placeholders for that ticket and inserts the text at the cursor. It works in both reply and internal-note mode; nothing is sent until the person sends the message.

## Chat inbox, presence and typing
The ticket page is laid out like a chat inbox, and shows who else is looking at the ticket and who is typing.

**Layout**
- The ticket details column scrolls on its own. The conversation stays in place, with the message box pinned at the bottom.
- The conversation opens at the newest message. Loading older messages keeps the reader's place instead of jumping.
- New messages animate in. A "New" divider marks where unread messages start, and when the reader has scrolled up, a "N new messages" button jumps down to them. A message being sent shows at once as "Sending…" until the server confirms it. People who ask their system for reduced motion get no animations.

**Presence**
- Each ticket has its own presence group, open to everyone who may view the ticket. Members are shown with their name, picture and side (customer or staff). There is no site-wide presence.
- A person is "Active now" (green dot) while they have the ticket open, or if they were seen anywhere in the application in the last 5 minutes. Otherwise they show as "Active N minutes / hours / days ago", or "Offline" if they were never seen.
- "Last seen" is updated when a signed-in person uses the web pages or the API, at most once a minute per person.

**Typing indicator**
- While someone types, the others on the ticket see "… is typing". Typing signals are sent at most every 2 seconds and fade after 4 seconds without a new one.
- Typing a public reply is shared with everyone on the ticket. The name shown comes from the presence member list, not from what the sender claims.
- Typing an internal note is shared only on the staff channel, so a customer never learns that a note is being written.
- Only members of a channel may send signals on it.

## Realtime updates
When the realtime server runs, pages refresh themselves as things happen: a new ticket appears in the list, a reply appears in the conversation, the status, priority, assignee or SLA state updates, the notification count goes up.

**What is sent**
- A live message carries only the ticket number, the kind of change and the time, never ticket content. On receiving one, the page asks the server again through its normal, permission-checked requests, so a live message can never show anyone something they may not see.

**Who receives what**
- Changes to a ticket go to everyone who has that ticket open, to the staff queue that everyone who may view all tickets listens to, and to the ticket's customer on their own personal channel. That is how a customer's ticket list updates live, including tickets created after the page was opened. Customers never get a message for an internal note.
- A recalculated deadline counts as a change, so open pages refresh.
- A page refreshes at most once at a time; when many changes arrive together it does one more refresh at the end. A refresh reloads only the parts that can have changed.
- A new internal note is announced only on the ticket's staff channel, which needs the internal-notes permission.
- A new notification is announced only on the recipient's own channel, so their unread count updates.
- Every channel checks permission when someone joins it, using the same rules as the pages.
- The creation of tickets by a CSV import is not announced ticket by ticket; the staff queue is told once when the import finishes.

**Without the realtime server**
- Every page works exactly the same; it just does not update until it is reloaded or the person navigates.

## Search and filtering
The ticket list can be narrowed and sorted. It always shows only tickets the viewer is allowed to see, so a customer's filters can never reveal anyone else's tickets, and staff filters work inside the same visibility rules.

**Lists and scopes**
- The list has three scopes. Active shows tickets that are Open, In Progress or Waiting for Customer. Resolved shows Resolved and Closed tickets. All shows every status. Active is the default.
- Lists are paginated: 20 per page by default, never more than 100 per page. Filters stay in the page links.

**Filters** (all optional and combinable)
- Ticket number: matches numbers that start with what was typed, in any letter case.
- Keyword: searches the subject and the description. Each word matches words that start with it (for example "print" finds "printer"). Words shorter than three characters are ignored by the search, because the database does not index words that short. At most 10 words are used, up to 200 characters are accepted, and search operator characters such as quotes, plus, minus and asterisks are stripped so typing them cannot change how the search behaves. Where full-text search is not available, a plain "contains" match is used instead.
- Status and priority: one or several values. The status choices offered depend on the scope.
- Category, assigned agent: by id. A "no agent" option shows only unassigned tickets.
- Customer: staff pick a customer from a search box (see Customer lookup). The choice shows as a removable chip, and a shared link with a customer filter shows the customer's name again.
- Created from and to: calendar dates inclusive of both days, interpreted in the business time zone. The end date cannot be before the start date.
- SLA state: shows tickets in the chosen SLA state, using the same definition of the state as the rest of the system.
- Response: "Unresponded" shows tickets that have no public reply yet from anyone other than the customer. "Awaiting reply" shows tickets whose latest public message is the customer's. Internal notes never count as a response.
- Unknown values for status, priority, SLA state, response or sort are rejected, and a category must exist.

**Sorting**
- Newest first (default), oldest first, recently updated, least recently updated, due soonest, due latest, highest priority, lowest priority. Priority sorts by importance (urgent first when highest), not alphabetically. Ties are broken by ticket id so paging is stable. Any other sort value is rejected.

**What the page offers**
- The filter form offers the statuses for the current scope, priorities, SLA states, sorts and active categories. Staff also get the agent, customer and response filters; customers do not see those.

## Customer lookup
Staff find a customer by typing, both when filtering the ticket list and when creating a ticket on a customer's behalf.

- The lookup matches customers whose name or email starts with what was typed, and returns at most 10, with their name and email. It can also look up one customer by id, which is how a shared link shows the customer's name again.
- Only customer accounts are returned.
- It is open to users who may view all tickets or create tickets on behalf of customers. Customers get "forbidden".
- It is limited to 60 requests per minute.

## SLA escalation
A background scan runs every minute and raises the escalation level of tickets whose SLA clock is running out, then makes sure each escalation is announced. The scan only ever raises a level. The level is lowered elsewhere: when deadlines are recalculated (a resume, a reopen, a priority change, a change of SLA rules) the level drops if the ticket is no longer close to its new deadline, so it can escalate again later.

**Which tickets are scanned**
- Only active tickets whose SLA clock is running. Tickets whose clock is paused (Waiting for Customer), and resolved or closed tickets, are never escalated.
- Approaching: the warning time has passed but the due time has not. The ticket moves to the "approaching" level.
- Breached: the due time has passed. The ticket moves to the "breached" level. A ticket that was never flagged as approaching (for example because the scan was down) goes straight to breached.
- The moment of escalation is recorded on the ticket at every escalation, approaching or breached.
- Scans do not overlap and run on only one server at a time. Two scans running at once cannot raise the same ticket twice: raising the level and announcing it happen together, inside one database transaction with the tickets locked.

**Announcing an escalation, at least once**
- Each escalation is announced: a timeline entry with no person attached, because the system did it ("SLA breach approaching" or "Ticket escalated: SLA breached", with the due time), and notifications (see Notifications and inbox): approaching warns the assigned agent, or the admins when nobody is assigned; breached tells the assigned agent and all admins.
- The ticket remembers the highest level already announced. The announcement locks the ticket, skips a level that was already announced, and then writes the timeline entry, queues the notifications and records the announced level all in one transaction. Announcing the same level twice therefore does nothing.
- If an announcement was lost (for example the process stopped between the escalation and its announcement), the next scans find tickets whose announced level is behind their escalation level and whose escalation is older than a short grace period (2 minutes by default), and announce them again. The result is that every escalation is announced at least once, and never twice.
- When a ticket's level was lowered and later rises again, the new escalation is announced again.

**Who sees it**
- Escalations are staff information. Customers do not see the escalation level or the escalation timeline entries, and never receive escalation notifications.

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
- Tickets created by a CSV import send no per-ticket messages: no creation confirmation, no "needs assignment" message and no assignment message to the customer or the agent. The admin who ran the import gets one import summary. When the import's tickets were auto-assigned, each agent who received some gets one summary message (by email and inbox) saying how many imported tickets are now theirs.
- The same person is never notified twice for one event, and the person who caused the event is not notified about their own action. The only exception is the confirmation a customer receives for a ticket they opened themselves.

**Channels**
- Ticket notifications go by email and into the inbox. Internal notes and import results go to the inbox only. Each notification has a title, a short text and a link to the ticket (or the import); a ticket notification carries the ticket number. Deadlines in messages are shown in the business time zone.
- When the realtime server runs, a new inbox notification also updates the unread count live.

**The inbox**
- Anyone who can view tickets has an inbox of their own notifications, newest first, 20 per page (up to 100). It can be limited to unread ones. The unread count is shown across the application.
- A user can mark one notification read or mark all read. Marking someone else's notification is answered with "not found", so notifications can never be read or changed across users.
- When a staff member is changed to the customer role, every notification they received as staff is deleted, because those notifications quote internal notes or name other customers' tickets.
- Internal-note alerts are also hidden at the moment the inbox is read from anyone who no longer has the internal-notes permission, for example after an admin removed it from their role. They are left out of the inbox list and the unread count, and their text is never shown to such a viewer.

## CSV ticket import
Administrators can bring existing tickets in from a CSV file. The import runs in the background, so large files do not block the page, and its progress can be followed on screen or through the API. Only people with the import permission can start or view imports, and uploads are limited to 20 per minute per user.

**The file**
- It must be a CSV (or text) file, up to 50 MB by default. The first row is the header.
- Columns: external reference, subject, description, priority, category, customer email, status, created at. The required columns are subject, description, priority, category and customer email. Header names are matched without regard to case, and a leading byte-order mark is ignored.
- A header with a missing required column, an unknown column or a repeated column fails the whole import at once with a message saying which. An empty file fails the same way.
- Blank lines are skipped. Rows are worked through in chunks (500 rows by default), each starting directly at its place in the file, and a chunk failing unexpectedly is recorded without stopping the others.
- The file can live on any storage disk. On a local disk it is read in place; on any other disk (for example cloud storage) each step first copies it to a temporary local file, reads that copy and then deletes it.

**Row rules** (each row is checked on its own, and bad rows never stop good ones)
- Subject is required (up to 255 characters), description is required, priority must be a valid priority, and category must be the short name (slug) of an active category. Priority, status, category and email are read without regard to case, and surrounding spaces are trimmed.
- The customer email must belong to an existing customer.
- The external reference is optional (up to 64 characters) and must be unique among all tickets. This stops the same row from being imported twice, including when two imports race.
- Status is optional and defaults to Open. Created at is optional and defaults to now; it is read in the business time zone, must be a valid date and must not be in the future.
- A row with the wrong number of columns is rejected.

**Duplicates without an external reference**
- A row with a created-at date is a duplicate when the same customer already has a ticket with the same subject and created-at time ("Duplicate of an existing ticket."), or when an earlier row in the same chunk has the same customer, subject and created-at time ("Duplicate of row N.").
- A row with neither an external reference nor a created-at date is compared by a fingerprint: the customer, the subject (ignoring letter case and surrounding spaces) and the description (ignoring letter case and differences in spacing). A match against an earlier row in the same chunk, or against an existing ticket of the same customer with the same subject and description, is rejected the same way.
- A row is only remembered for the "Duplicate of row N." check once it was actually imported, so that message never points at a row that failed.
- Every imported ticket without an external reference also stores its fingerprint (customer, subject and either the created-at time or the description), and the database refuses a second ticket with the same fingerprint. So two identical rows can never both be imported, even when they sit in different chunks handled at the same moment by different workers; the second is reported as "Duplicate of an existing ticket.". Uploading the same file again imports nothing new.

**What an imported ticket gets**
- It receives a normal ticket number, counted in the year of its created-at date, and its SLA clock starts from that created-at date, so an old ticket may already be approaching or breached when it arrives. Its escalation level is set to match, and that level counts as already announced, so an import does not trigger a wave of escalation messages.
- Waiting for Customer starts paused; Resolved and Closed get their resolved (and closed) time set to the time of import and have no running SLA.
- It is created by the importing admin on behalf of the customer. Its timeline shows that it was imported, with the row number.
- If the admin chose to auto-assign the import, its active tickets are assigned once the import has finished (see Assignment); Resolved and Closed ones are never assigned. No per-ticket emails are sent (see Notifications and inbox).

**Progress and result**
- An import goes Pending, then Processing, then ends as Completed (all rows imported), Completed with errors (some rows rejected) or Failed (bad header, an unexpected failure, or a chunk that crashed). A preparation step that is interrupted is retried and carries on; only a final failure marks the import Failed. Total, processed, imported and failed row counts and the start and finish times are kept.
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
- Adding, changing or removing a holiday takes effect immediately for new SLA calculations: the cached list of holidays used by the business calendar is cleared.
- Deadlines already stored on existing tickets stay as they are, unless "Recalculate active tickets" is ticked; then they are recalculated in the background as described in SLA rules and business hours administration.

## Dashboard statistics
Users who may view the dashboard (agents and admins) have one; customers do not, and anyone without access gets "forbidden". People who can open it are sent there when they open the home page.

**Totals** (for all tickets, not only the viewer's)
- Open: active tickets (Open, In Progress, Waiting for Customer).
- Unassigned: active tickets with no agent.
- Critical: active tickets with critical priority.
- Breached: active tickets with a running SLA clock past their due time. Approaching: running tickets past their warning time but not yet due. Paused tickets are in neither.
- Resolved: tickets resolved or closed in the last 30 days by default (configurable).

**Breakdowns**
- SLA health: how many active tickets are within SLA, approaching, breached or paused.
- By status and by priority: the number of active tickets for each, most urgent priority first, with zero shown where there are none.
- By agent: every agent with their picture, active ticket count and breached count, busiest first, then by name.
- Trend: tickets created and resolved per day over the last 14 days, with days counted in the business time zone.

**My tickets**
- The viewer's own assigned active tickets, the five most due soonest, in the same form as the ticket list.

**Freshness**
- The totals and breakdowns are shared by all viewers and cached in one fixed entry, so the cache never grows. The cached figures are replaced whenever a ticket is created, assigned, unassigned, escalated, has its deadline recalculated, or changes status, priority or category, and when the business timezone changes, so the figures are fresh after every change that affects them. A 60-second limit backs this up, which also keeps time-based counts (a ticket crossing its due time with no event) at most a minute behind. The viewer's own ticket list is always current.

## Agents directory
Staff can browse the support team and see each agent's workload. The directory is open to users who may view all tickets (agents and admins); customers get "forbidden".

**Layout**
- The page works like an inbox. On the left is a scrollable list of people; selecting one shows their details and tickets on the right. On a phone the list and the agent page are shown one at a time, with a link back to the list.
- With nobody selected, the right side shows a team summary: how many agents there are, how many are available, and the total unresponded and breached tickets.

**The list**
- Shows agents by default; a filter menu switches to admins or all staff, filters by availability and category, and sorts by name, most active tickets, most unresponded or most SLA breaches. A search box matches the start of a name or email.
- Each row shows the person's picture with a green dot when they were active in the last 5 minutes, when they were last active, an "On leave" tag when unavailable, and "active / assigned" counts. A red dot marks someone with breached tickets, an amber dot someone with unresponded tickets.
- The list shows 25 people at a time with a "Load more" button, up to 100.

**The numbers for each agent**
- Assigned: all tickets currently assigned to them, in any status.
- Active: their active tickets.
- Unresponded: their active tickets that have no public reply yet from anyone other than the customer.
- Awaiting reply: their active tickets whose latest public message is the customer's.
- Breached: their active tickets with a running SLA clock past the due time (paused tickets are not counted).
- Resolved: their tickets resolved or closed in the last 30 days.

**Agent page**
- Selecting an agent shows their picture, last-active status, email, availability and categories, and for admins an "Edit profile" button.
- The numbers appear as tabs: Active, Unresponded, Awaiting reply, SLA breached, Resolved (30 days) and All assigned. Choosing a tab lists exactly those tickets, so the list length always equals the number on the tab.
- Each ticket row shows the customer, the ticket number, the subject with a one-line preview of the latest public message (internal notes are never previewed), and the status and SLA badges. Rows open the ticket.
- Opening the page of someone who is not an agent or admin answers "not found".
- The same list and pages are available in the API.

**Performance**
- The numbers are counted only for the agents shown on the current page, in one grouped query, so the directory runs the same number of queries whether it shows three agents or a hundred.
- The summary and the per-agent figures used by the workload sorts are counted across all active tickets, so they are cached for a short time (60 seconds by default, configurable). The cache is thrown away as soon as ticket data changes (created, assigned, unassigned, status, priority, category or SLA changes), when an agent's availability or categories change, when someone's role changes, and when staff post a public reply (which can answer an unresponded ticket). Breaches that happen simply because time passes show up within the cache time or at the next escalation scan.

## Security and performance
**Who can do what**
Every action on tickets, replies, attachments, saved replies and the admin areas is decided by a policy that combines the user's permissions with ownership: a customer only deals with their own tickets, an agent with what their permissions and assignments allow. The same rules apply on the web and through the API.

**Hiding what a user may not see**
When someone is not allowed to view a ticket, the answer is "not found", never "forbidden", so the existence of other people's tickets is not revealed. Staff who can see a ticket but lack a specific permission get a normal "forbidden" for that action. Staff-only areas such as the dashboard, agents directory and customer lookup answer "forbidden" to customers: those areas exist for everyone, so there is nothing to hide.

**Internal notes**
Internal replies and internal attachments are visible only to users with the internal-notes permission. Anyone else who asks for one directly gets "not found". Customers never receive them in ticket pages, API responses, notifications, the activity history, live updates or typing signals.

**Uploads**
- Files are stored on a private disk, never in a publicly served folder, under a random generated name, so the original name cannot be guessed or used to overwrite anything. The original name is kept only for display and download.
- Only the allowed file types are accepted: pdf, png, jpg, jpeg, gif, txt, csv, doc, docx, xls, xlsx and zip. The check looks at the extension and the real content type of the file, not only its name.
- By default a single file may be up to 10 MB and one ticket or reply may carry up to 5 files. Both limits are configurable. A request that breaks a limit is rejected and nothing is stored; if storing fails part-way, the files already written are removed.
- Downloads go through a check of the same rules as viewing the ticket, so internal attachments stay hidden from customers. The file is sent as a download with its original name, the stored content type and a header that stops browsers from guessing a different type.
- Profile pictures follow their own rules (see Profile and avatars).

**Rate limits**
- API requests: 60 per minute, counted per signed-in user (per address when not signed in).
- Uploads: 20 per minute per user, applied to creating a ticket, posting a reply, uploading an import file and uploading a profile picture, on the web and through the API.
- API token requests: 5 per minute per email and address combination.
- Login, registration, password-reset requests and password resets: 5 per minute.
- Profile password changes: 6 per minute. Customer lookup and the saved-reply picker: 60 per minute.
Going over a limit returns a "too many requests" response.

**API documentation**
The interactive API documentation is available to signed-in users who may manage users, which in practice means administrators. In the local environment it is open to everyone.

**Query efficiency**
Loading a related record on demand is blocked outside production, so a page that would run one query per row fails during development and testing instead of quietly slowing down. Ticket, user, agent and notification lists run the same number of queries whether they show a few rows or many, and every list is paginated with a capped page size of 100.

**Indexes**
The tickets table has indexes for the common filters and for the SLA checks: status with due date, status with warning date, assignee with status, customer with creation date, customer with subject (import duplicate checks), category with status, status with category and assignee (queue counts), priority with status, status with resolution date, creation date, last update, the import a ticket came from, and one for finding escalations that still need announcing; ticket numbers and external references are unique; subject and description have a full-text index for search. Notifications are indexed by recipient and creation date, so the inbox shows the newest first without sorting. Saved replies are indexed by owner and by category, each with the title. A test runs the real customer and staff lists, the filters, the SLA scan, the dashboard, the activity timeline and the inbox against a few thousand tickets and checks with the database's execution plan that none of them scans a whole table.

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
- Before the page opens, the link itself is checked. A link that was already used, has expired, was never issued or does not match the email sends the user back to the request page with the message that the link is invalid, expired or already used, so a used link cannot even show the form again.
- The password follows the same rule as registration (stricter in production, 8 characters minimum elsewhere).
- If the link is wrong, expired, already used or does not match the email, the reset fails with one error on the email field. Nothing is changed.
- On success the password is replaced, the "remember me" login is invalidated, and every API token of that user is revoked, so old devices must sign in again. The user is sent to the login page with the message "Your password has been reset. You can log in now." They are not signed in automatically.
- Reset attempts are limited to 5 per minute from the same address.

## User and role management
Admins manage accounts from an admin users page and from the matching API. Only users who hold the user-management permission (admins) can open any of it; everyone else is refused. Accounts are never deleted, so there is no delete option.

**Listing users**
- The list is paginated (20 per page by default, at most 100) and sorted by name.
- It can be filtered by role and searched by typing the beginning of a name or an email. The search matches from the start of the text only, not from the middle.
- Each row shows the user's picture and role and, for agents, their categories and availability.

**Creating a user**
- An admin enters a name, an email, a password and one role (admin, agent or customer). The email must be lower case and unique, the password follows the registration rule.
- For an agent the admin can also pick the categories the agent handles and whether the agent is available for automatic assignment. A new agent is available unless said otherwise. These fields are ignored for other roles.
- The new account can sign in straight away.

**Editing a user**
- An admin can change name, email, role, the profile picture, and for agents the categories and availability.
- The password field is optional. Left empty, the password is unchanged. When filled in, the password is replaced, the "remember me" login is invalidated, all of the user's API tokens are revoked and all of their signed-in web sessions end. An admin changing their own password this way keeps their current session.
- For an agent, categories and availability that are not sent keep their current values.

**Changing a role**
- Each user has exactly one role. Choosing the role they already have changes nothing.
- An admin cannot change their own role, so an admin cannot lock themselves out by accident. The attempt is refused with a clear error.
- When a role really changes, all of the user's API tokens are revoked, because tokens carry the permissions the user had when they were issued. A user who stops being an agent also loses their category assignments, and their active tickets become unassigned.

## Permission matrix
Admins can open a page, and an API list, showing the three fixed roles (admin, agent, customer) in that order, with the permissions each one has.
- The page shows a table of every permission against every role; each role's cell is a checkbox for that permission.
- Admins who can manage users can check or uncheck a permission for a role. The change applies to every signed-in user immediately, without a new sign-in. Because visibility follows permissions, the lists, counts, pages and live updates all follow the new matrix together. The roles themselves, their names, and the exact list of possible permissions are fixed and cannot be created, edited or removed from the application.
- Two permissions cannot be moved: the customer role can never receive the internal-notes permission, and the admin role always keeps user management, so the gate for managing the matrix itself can never be revoked.
- The API mirrors the page: an authorized manager can submit the complete permission list for one role and gets the updated list back, and any attempt to grant internal notes to customers or to strip user management from admins is refused with a clear error while the role keeps its previous permissions.
- The page and the API are open to the same people who can manage users.
