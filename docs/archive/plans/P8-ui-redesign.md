# P8 — UI redesign

> **Self-driving plan.** The session that runs this file is the controller. Read `docs/plans/ROADMAP.md` and `CLAUDE.md` first, then use the `subagent-driven-development` skill: run the **Waves** table below in order, dispatching every subtask of a wave as its own subagent in one message, with the model the table names, and wait for the whole wave before starting the next. Then gate once → parallel review → fix → report (ROADMAP steps 4–8). **Don't commit until the owner approves.**

**Goal:** give the app a support-desk look: a slim top bar with global search, an icon rail with a queue panel, ticket rows as cards, and a two-column ticket page with a chat-style conversation.
**Depends on:** P1–P7 (done).
**Outcome:** the same features and routes, in a new layout. Backend changes are limited to the queue counts in P8.2.

## Reference
Three screenshots from a competitor-style helpdesk are in `/tmp/claude-1000/-home-shariya-techzu/47972180-3268-417b-8ad6-d62adfa77cc5/scratchpad/` (`1.png` ticket page, `2.png` agents queue, `3.png` my tickets). Read them with the Read tool. Take the layout ideas only: never copy their logo, brand name, colours verbatim or copy.

What to borrow:
- **Top bar:** logo at the left, a wide centred search field with a `Ctrl K` hint, and notification bell, theme toggle and avatar menu at the right.
- **Left icon rail:** narrow, with a stacked icon and a small label for each main area.
- **Queue panel:** a second narrow column on the tickets pages with a dropdown for the current view, and a list of queues (All, Assigned to me, Unassigned, Resolved and closed, then categories) with open/total counts.
- **List:** a light-grey page, and each ticket is a white rounded row-card with columns Client (avatar, name, category tag, time ago), Ticket number, Conversation (bold subject and a short snippet), Assigned to (avatar and name), badges, and a `⋮` actions menu. Unread or needs-attention rows get a soft green tint.
- **Ticket page:** two columns. Left: stacked cards (ticket number with copy button, created date, status badge; requester card; status control; a Details / Activity / Files tab card with assignee and category). Right: one tall card with chat bubbles (staff on the right, customer on the left) and a composer docked at the bottom with attach and a blue Send button.

## Global constraints
- No new packages, no backend changes except P8.2's queue counts. Reuse the existing shadcn-vue components, Tailwind and Wayfinder imports. If a shadcn component is missing, add it with `npx shadcn-vue@latest add <name>` and list the files it creates.
- Inertia 3 rules: pages use `defineOptions({ layout })` or the callback form for breadcrumbs, never wrap in a layout component, no `v-html`. Keep `data-test` attributes that tests use.
- Keep every route, prop name and permission gate. Customers must never see staff navigation, queues of other users, or internal notes (internal notes get a distinct amber style and an "Internal note" label, only rendered when the server sends them).
- Light and dark mode both work: colours are tokens in `resources/css/app.css`, no hard-coded hex in components. Accessible: visible focus, labelled icon buttons, 4.5:1 contrast. Responsive down to 375px: the icon rail becomes the existing mobile sheet, and the two-column ticket page stacks.
- No comments in code. Format with `npx vp fmt <files>`. Implementers do not run `npm run build`, `npm run check` or `vue-tsc`; only P8.5 and the session gate do.

## Waves
| Wave | Subtask | Model | Runs in parallel with |
|---|---|---|---|
| W1 | P8.1 App shell: top bar, global search, icon rail, theme tokens | sonnet | — |
| W2 | P8.2 Ticket list: queue panel, row cards, filter bar | sonnet | P8.3, P8.4 |
| W2 | P8.3 Ticket page: two columns, chat bubbles, docked composer | sonnet | P8.2, P8.4 |
| W2 | P8.4 Dashboard, notifications, admin and auth pages restyle | sonnet | P8.2, P8.3 |
| W3 | P8.5 Consistency pass and browser verification per role | opus | — |

Shared files, one owner per wave: `resources/css/app.css`, `layouts/**`, `components/App*.vue`, `components/Nav*.vue`, `config/navigation*` → P8.1 (W1) only. `types/tickets.ts` → P8.2. `components/PageHeader.vue`, `StatCard.vue`, `EmptyState.vue`, `ResourcePagination.vue` → P8.4.

---

## P8.1 — App shell
**Files:** `resources/css/app.css`, `resources/js/layouts/app/*.vue`, `resources/js/layouts/AppLayout.vue`, `resources/js/components/{AppShell,AppContent,AppSidebar,AppSidebarHeader,AppHeader,AppLogo,AppLogoIcon,NavMain,NavUser,NavFooter,NotificationBell}.vue`, `resources/js/components/GlobalSearch.vue` (new), `resources/js/config/navigation.ts`, `resources/js/config/navigation/*.ts`, `resources/js/types/navigation.ts`.
**Steps:**
1. Tokens: soft grey page background (`--background`/a new `--surface`), white cards, a blue primary, a darker slate icon-rail colour, rounded 10–12px cards, subtle borders. Both themes.
2. Top bar across the full width: logo mark and "Techzu Support" text at the left, `GlobalSearch` centred, bell, theme toggle (reuse the existing appearance logic only if it exists; otherwise a simple light/dark toggle persisted in `localStorage` with a try/catch), user menu at the right. Breadcrumbs move into a thin row under the bar, or into page headers, whichever keeps pages simple.
3. `GlobalSearch`: pressing `Ctrl K` or `/` focuses it; Enter on a value goes to `tickets.index` with `q=<text>` (or the exact ticket number when it matches `TKT-`), through the Wayfinder route helper. Hide it for users without a tickets permission.
4. Icon rail replaces the sidebar: icon above a 11px label for each top-level group from the navigation config (Tickets, Dashboard, Imports, Admin), active state highlighted, collapsed to the mobile sheet on small screens. Entries still come from `config/navigation` and stay gated by `auth.can`.
**Proposed commit:** `refactor(ui): replace the sidebar shell with a top bar and icon rail` · `Refs: P8.1`

## P8.2 — Ticket list
**Files:** `resources/js/pages/tickets/Index.vue`, `resources/js/components/tickets/{TicketFilterBar,TicketRow,TicketQueuePanel,PriorityBadge,StatusBadge,SlaBadge}.vue`, `resources/js/types/tickets.ts`, `app/Services/Tickets/TicketQueueCounts.php` (new), `app/Http/Controllers/Web/TicketController.php` (index only), `tests/Feature/Tickets/TicketQueueCountsTest.php` (new), `tests/Feature/Performance/TicketQueryCountTest.php` (only to adjust a ceiling if the counts add one query).
**Steps:**
1. Backend, test-first: `TicketQueueCounts::for(User $viewer)` returns, with one grouped query on the viewer's visible tickets, the open/total counts for: all active, assigned to me, unassigned, resolved/closed, and per category. Staff only; customers get "all active" and "resolved/closed" for their own tickets. Pass it as the `queues` prop on the web index. Counts must respect `visibleTo`, and must use existing indexes (record in TD-021 only if a new one is needed; prefer none).
2. `TicketQueuePanel`: second column with a view dropdown and the queue list with count badges; each item links to the existing filter query (`scope`, `assigned=me`, `unassigned`, `category_id`). The active queue is highlighted from the current filters.
3. `TicketRow`: the row-card layout described above, using the existing resource fields only (client name and initials avatar, category tag, relative created time, number, subject, status/priority/SLA badges, assignee avatar and name). The `⋮` menu offers "Open" (and "Claim" when `abilities.claim`). Rows for unassigned or breached tickets get a soft tint.
4. Keep the filter bar, search, sort and pagination working in the new layout (compact filter bar above the rows). Empty state kept.
**Proposed commit:** `feat(tickets): add queue panel and row-card ticket list` · `Refs: P8.2`

## P8.3 — Ticket page
**Files:** `resources/js/pages/tickets/Show.vue`, `resources/js/components/tickets/{ConversationPanel,ReplyComposer,TicketDetailsCard,AssignmentPanel,TicketStateControls,ActivityTimeline,AttachmentList,AttachmentPicker,TicketEditForm}.vue`.
**Steps:**
1. Two-column layout on `lg`: left column (about 340px) of stacked cards: ticket number with copy button and created date and status badge; requester (avatar, name, email); status control (`TicketStateControls`); a tab card with Details (assignee via `AssignmentPanel`, category, priority, SLA due and state, created by), Activity (`ActivityTimeline`) and Files (`AttachmentList`). The right column is one tall card with the conversation.
2. Chat bubbles: the viewer's own side is right-aligned with a soft green tint; the other party left with white; each shows author, role and time and attachments. Internal notes: amber dashed bubble with an "Internal note" label, rendered only for items the server sent. "Load older" buttons stay at the top.
3. The composer docks at the bottom of the card: a textarea, an attach button with the picker, a "Internal note" switch (only when allowed), and a blue Send button; Enter-to-send is not required. Keep the existing form logic, validation errors and `attachmentRules`.
4. Mobile: columns stack, the composer stays at the bottom of the conversation card.
**Proposed commit:** `feat(tickets): rebuild the ticket page with a chat-style conversation` · `Refs: P8.3`

## P8.4 — Other pages
**Files:** `resources/js/pages/dashboard/Index.vue`, `resources/js/pages/notifications/Index.vue`, `resources/js/pages/admin/**`, `resources/js/pages/auth/*.vue`, `resources/js/layouts/auth/*.vue`, `resources/js/layouts/AuthLayout.vue`, `resources/js/components/{PageHeader,EmptyState,ResourcePagination}.vue`, `resources/js/components/dashboard/*.vue`, `resources/js/components/admin/*.vue`.
**Steps:** apply the new look consistently: page header with title and action, white rounded cards on the grey page, tables become row-cards or card-wrapped tables with the same spacing, stat cards with tinted icons, the notifications inbox as a list of row-cards, import and admin forms in cards, and the login, register and password pages as a clean centred card with the logo mark. No behaviour changes.
**Proposed commit:** `refactor(ui): restyle dashboard, notifications, admin and auth pages` · `Refs: P8.4`

## P8.5 — Consistency and browser verification
**Files:** any file under `resources/js/**` for small consistency fixes.
**Steps:** run `npm run build`, `npm run check` and `npx vue-tsc --noEmit` and fix errors. Start `DB_DATABASE=techzu_testing_agent8 php artisan serve --port=8010` after `migrate:fresh --seed`, then with the Playwright MCP walk every page as admin, agent and customer at 1280px and 375px in light and dark mode. Check spacing, alignment, focus states, empty states and that customers see no staff navigation or internal notes. Fix what you find, screenshots to the scratchpad only. Stop the server and close the browser afterwards.
**Proposed commit:** `fix(ui): align spacing and states across the redesigned pages` · `Refs: P8.5`

## Acceptance criteria
1. The top bar with global search (`Ctrl K`), bell, theme toggle and user menu shows on every authenticated page; the rail shows only the groups the user may see.
2. The ticket list shows queue counts and row-cards; every existing filter, sort and pagination still works; customers see only their own queues.
3. The ticket page has two columns on desktop and stacks on mobile; internal notes are visually distinct and absent for customers; reply, attach, status, assign and edit all still work.
4. Dashboard, notifications, admin and auth pages share the new look in light and dark mode.
5. No new packages, no comments in code, and no horizontal scroll at 375px.
6. The gate passes (pint, phpstan, pest, build, check, vue-tsc).
