# P5 — UI

> **Self-driving plan.** The session that runs this file is the controller. Read `docs/plans/ROADMAP.md` and `CLAUDE.md` first, then use the `subagent-driven-development` skill: run the **Waves** table below in order, dispatching every subtask of a wave as its own subagent in one message, with the model the table names, and wait for the whole wave before starting the next. Session steps (S0, S1) are done by the controller itself. Then gate once → parallel review → fix → report (ROADMAP steps 4–8). **Don't commit until the owner approves.**

**Goal:** finish every remaining screen in one consistent, responsive pass over the Inertia 3 + Vue 3 frontend.  **Depends on:** P4 (and through it P2, P3).  **Outcome:** admins, agents and customers can do everything the backend allows from the browser. That covers the dashboard, the filtered ticket list, ticket creation, the full ticket detail (assignment, conversation with internal notes and attachments, activity, status and priority), the notification bell and inbox, CSV import with live progress, and the category and holiday admin. Every page shares one layout, one set of empty, loading and error states, flash toasts and inline validation errors. Each page works at 375 px wide without horizontal page scroll, and customers never see internal notes or admin navigation.

## Schema needs for P1
None.

## Consumes from P2/P3/P4 (verify before starting)

The session checks every row before wave 1, with `php artisan route:list --except-vendor --name=<name>` for routes and `grep -rn "Inertia::render('<page>'" app/Http/Controllers` for page props. If a name or prop differs, stop and report. Don't adapt the UI silently.

| Source | Route name (method, URI) | Inertia page and props / payload |
|---|---|---|
| T05 (main) | `tickets.index` (GET `/tickets`) | `tickets/Index`: `tickets: Paginated<TicketListItem>`, `scope: 'active'\|'resolved'`, `filters: object` |
| P3.3 | same route | adds `filterOptions: { statuses: Option[], priorities: Option[], slaStates: Option[], sorts: Option[], categories: {id,name}[], agents: AgentOption[], showPeopleFilters: boolean }` (`statuses` = current scope only; default sort `-created_at`); query params `scope, number, q, status[], priority[], category_id, assigned_agent_id, unassigned, customer_id, created_from, created_to, sla_state, sort, page` (TD-080) |
| T05 (main) | `tickets.create`, `tickets.store` (POST multipart: `subject, description, priority, category_id, customer_email?, attachments[]`) | `tickets/Create`: `options: TicketFormOptions`, `canCreateOnBehalf: boolean`, plus P3.4's `attachmentRules: { extensions: string[], max_kb: number, max_files: number }` |
| T05 (main) | `tickets.show`, `tickets.update` (PATCH), `tickets.status` (PATCH `status`), `tickets.priority` (PATCH `priority`) | `tickets/Show`: `ticket, replies, attachments, activities, agents, can, options`, plus P3.4's `attachmentRules` (same shape); `replies` is `TicketReplyResource` with T05's `ReplyItem` shape |
| P3.1 | `tickets.claim` (POST, no body), `tickets.assign` (POST `agent_id`) | redirect back; flash `success` or `error` |
| P3.2 | `tickets.replies.store` (POST multipart `body, is_internal?, attachments[]?`), `attachments.download` (GET) | redirect to the ticket with flash `success` ("Reply sent." / "Internal note added."); errors on `body`, `attachments`, `attachments.N`; the UI downloads through `AttachmentItem.download_url` |
| P4 | `notifications.index` (GET `?unread=1`), `notifications.read` (POST `/notifications/{id}/read`), `notifications.read-all` (POST) | `notifications/Index`: `items: Paginated<NotificationItem>`, `unreadOnly: boolean`; shared `notifications.unread_count: number` (real value) |
| P4 | `dashboard` (GET, staff only) | `dashboard/Index`: `stats: DashboardStats`, `myTickets: TicketListItem[]` (the viewer's five soonest active assigned tickets) |
| P4 | `admin.imports.index`, `.create`, `.store` (POST multipart `file`), `.show` (`{import}` = id), `admin.imports.errors.download` | `admin/imports/Index`: `imports: Paginated<TicketImportItem>`; `admin/imports/Create`: `maxUploadKb: number`, `columns: {required: string[], optional: string[]}`; `admin/imports/Show`: `ticketImport: TicketImportItem`, `importErrors: Paginated<TicketImportErrorItem>` (closures) |
| P4 | `admin.categories.{index,create,store,edit,update,destroy}` (`{category}` = id) | `admin/categories/Index`: `categories: Paginated<AdminCategory>`; `Create`: none; `Edit`: `category: AdminCategory` |
| P4 | `admin.holidays.{index,create,store,edit,update,destroy}` (`{holiday}` = id, index takes `?year=`) | `admin/holidays/Index`: `holidays: Paginated<HolidayItem>`, `year: number`; `Create`: none; `Edit`: `holiday: HolidayItem` |
| P2.3 | `admin.users.*`, `admin.roles.index`, `login`, `logout`, `register`, the password-reset routes | P2's pages (`auth/{Login,Register,ForgotPassword,ResetPassword}`, `admin/users/{Index,Create,Edit}`, `admin/roles/Index`), polished in P5.8 only; `config/navigation/admin.ts` with Users (72) and Roles & permissions (73) |
| T04 (main) | shared `auth.can.{viewDashboard, manageImports, manageUsers, manageCategories, manageHolidays, createOnBehalf}`, `flash.{success,error}` | — |

Resource shapes P5 types against:
- `NotificationResource`: `id, type, ticket_number, title, body, url, read_at, created_at`.
- `TicketImportResource`: `id, original_name, status{value,label}, is_finished, total_rows, processed_rows, succeeded_rows, failed_rows, progress_percent, started_at, finished_at, created_at, user?{id,name}`.
- `TicketImportErrorResource`: `id, row_number, errors: Record<string,string[]>, raw: Record<string,string|null>, created_at`.
- `AdminCategoryResource`: `id, name, slug, is_active, tickets_count`.
- `HolidayResource`: `id, date (Y-m-d), weekday, name`.

P4 creates no Vue files. Its page tests call `->component('<page>', false)`, which skips `ensure_pages_exist`, so every P4 page is created here. "Create or replace" in the Files lists covers a file that turns out to exist anyway.

## Global constraints
- Read `CLAUDE.md` §Code style (Vue) and the "UI conventions" below before writing any code. The conventions are what P5.8 reviews against.
- Wayfinder: P5.1 runs `php artisan wayfinder:generate --with-form` once, after P3/P4's routes exist. Wave 2 imports from `@/routes/...` and doesn't regenerate. Import a route's functions from its name: `tickets.claim` → `import { claim } from '@/routes/tickets'`, `tickets.replies.store` → `import { store } from '@/routes/tickets/replies'`, `notifications.read-all` → `readAll`. Pass `.url(...)` to `router.*` and `form.*`; pass the definition object itself to `<Link :href>` and to breadcrumbs. Check a signature in `resources/js/routes/**` when unsure.
- The only literal URLs allowed are server-provided ones: pagination `links.*`, `AttachmentItem.download_url`, `NotificationItem.url`.
- After writing, run `npx vp fmt <your files>` and `npx vp lint <your files>` (the JS equivalents of Pint). Don't run `npm run build`, `npm run check` or `vue-tsc`; P5.8 and the session's gate do.
- There are no JS tests and no PHP tests in this plan (TD-030: frontend checks are type-check, lint and the P5.8 browser walk). If a page needs a server prop that isn't in the Consumes table, stop and report. Don't add it.
- Files under `resources/js/components/ui/**` are generated by shadcn-vue and keep their generated form (CLAUDE.md "kit files"). The single edit to `NativeSelect.vue` in P5.1 is the exception.
- Never `window.confirm` or `window.alert`. Destructive or final actions use `ConfirmDialog`.

### UI conventions (binding for every page)
1. **Page shell.** Wrap the page in `<div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-6">`. Single-form pages use `max-w-2xl`, and the notification inbox uses `max-w-3xl`. `PageHeader` comes first. Every page sets `<Head :title>`.
2. **Layout props only.** Static breadcrumbs go in `defineOptions({ layout: { breadcrumbs } })`. Breadcrumbs that depend on props use `setLayoutProps({ breadcrumbs })` inside `watchEffect`. Pages never import `AppLayout`.
3. **Cards.** Use `Card` with `CardHeader` and `<CardTitle class="text-base">`. Section titles are sentence case.
4. **Tables.** Use the shadcn `Table` inside `<div class="overflow-x-auto rounded-md border">`. Secondary columns get `hidden md:table-cell` or `hidden lg:table-cell`, and the first column carries the primary link. Numbers use `tabular-nums text-right`.
5. **Empty state.** Show `EmptyState` with an icon, a title and, when the user can do something about it, an action. A filtered list with no hits says so and offers to clear the filters.
6. **Pagination.** Use `ResourcePagination` under every paginated list.
7. **Forms.** Each field is `<div class="grid gap-2">` containing a `Label`, the control and an `InputError`. Single selects use `NativeSelect`, booleans use `Switch` or `Checkbox`, long text uses `Textarea` and files use `AttachmentPicker`. Submit with `SubmitButton :processing="form.processing"`, plus a ghost "Cancel" link back to the list.
8. **Feedback.** Server flash `success`/`error` shows as a toast automatically (P5.1). Never render flash inline. Validation errors appear under their field. While a visit is in flight, the button that started it is disabled and shows a spinner.
9. **Dates and sizes.** Use `formatDateTime`, `formatDate`, `formatRelative` and `formatBytes` from `@/lib/format`. A relative time carries the absolute time in its `title`.
10. **Badges.** Ticket status, priority and SLA always render through `StatusBadge`, `PriorityBadge` and `SlaBadge`.
11. **Icons.** Icons come from `@lucide/vue`, have `aria-hidden="true"`, and are sized by their container (`[&_svg]` rules on `Button`/`Badge`) or with `size-4`.
12. **Responsive.** Every page works at 375 px wide. Grids collapse to one column, action rows wrap (`flex-wrap`), and tables scroll inside their border. Interactive targets are at least `h-9`.
13. **Colour.** Prefer theme tokens (`text-muted-foreground`, `text-destructive`, `bg-muted`). A hard-coded palette colour always comes with its `dark:` pair.

## Waves
| Wave | Subtask | Model | Runs in parallel with |
|---|---|---|---|
| W1 | P5.1 Shared UI foundation | sonnet | — |
| W2 | P5.2 Dashboard page | sonnet | P5.3–P5.7 |
| W2 | P5.3 Ticket list filter bar and create page | sonnet | P5.2, P5.4–P5.7 |
| W2 | P5.4 Ticket detail | sonnet | P5.2, P5.3, P5.5–P5.7 |
| W2 | P5.5 Notification bell and inbox | sonnet | P5.2–P5.4, P5.6, P5.7 |
| W2 | P5.6 CSV import pages | sonnet | P5.2–P5.5, P5.7 |
| W2 | P5.7 Category and holiday admin pages | sonnet | P5.2–P5.6 |
| W3 | P5.8 Consistency pass and browser verification | opus | — |

---

## P5.1 — Shared UI foundation

**Files:**
- Create (CLI): `resources/js/components/ui/native-select/{NativeSelect,NativeSelectOptGroup,NativeSelectOption}.vue` + `index.ts`; `resources/js/components/ui/alert-dialog/{AlertDialog,AlertDialogAction,AlertDialogCancel,AlertDialogContent,AlertDialogDescription,AlertDialogFooter,AlertDialogHeader,AlertDialogTitle,AlertDialogTrigger}.vue` + `index.ts`; `resources/js/components/ui/switch/Switch.vue` + `index.ts`; `resources/js/components/ui/progress/Progress.vue` + `index.ts`; `resources/js/components/ui/empty/{Empty,EmptyContent,EmptyDescription,EmptyHeader,EmptyMedia,EmptyTitle}.vue` + `index.ts`; `resources/js/components/ui/tabs/{Tabs,TabsContent,TabsList,TabsTrigger}.vue` + `index.ts`.
- Create: `resources/js/types/pagination.ts`, `resources/js/types/dashboard.ts`, `resources/js/types/notifications.ts`, `resources/js/types/imports.ts`, `resources/js/types/admin.ts`, `resources/js/lib/format.ts`, `resources/js/lib/forms.ts`, `resources/js/components/PageHeader.vue`, `resources/js/components/EmptyState.vue`, `resources/js/components/ResourcePagination.vue`, `resources/js/components/SubmitButton.vue`, `resources/js/components/ConfirmDialog.vue`, `resources/js/components/tickets/AttachmentPicker.vue`, `resources/js/config/navigation/dashboard.ts`, `resources/js/config/navigation/notifications.ts`, `resources/js/config/navigation/imports.ts`.
- Modify: `resources/js/types/tickets.ts`, `resources/js/config/navigation.ts`, `resources/js/config/navigation/tickets.ts`, `resources/js/config/navigation/admin.ts` (P2's file: append two entries, and add `group: 'admin'` to P2's entries), `resources/js/components/AppSidebar.vue`, `resources/js/layouts/AppLayout.vue`, `resources/js/layouts/app/AppSidebarLayout.vue`, `resources/js/lib/flashToast.ts`, `resources/js/components/tickets/{StatusBadge,PriorityBadge,SlaBadge}.vue`, `resources/js/components/ui/native-select/NativeSelect.vue` (one class).

**Produces (wave 2 relies on these exact names):**
- Types: `Paginated<T>`, `PaginationLinks`, `PaginationMeta`, `PaginationLink` (`@/types/pagination`, re-exported from `@/types/tickets`); `TicketScope`, `TicketFilterOptions`, `AttachmentRules` (`@/types/tickets`); `DashboardStats`, `DashboardTotals`, `PriorityCount`, `AgentLoad`; `NotificationItem`; `ImportStatusValue`, `TicketImportItem`, `TicketImportErrorItem`, `ImportColumns`; `AdminCategory`, `HolidayItem`.
- `@/lib/format`: `formatDateTime(iso)`, `formatDate(iso)`, `formatRelative(iso, now?)`, `formatBytes(bytes)`. `@/lib/forms`: `errorsFor(errors, prefix): string[]`.
- Components: `PageHeader {title, description?}` with `#meta` and `#actions` slots; `EmptyState {title, description?, icon?}` with a default slot for actions; `ResourcePagination {links, meta, only?, noun?}`; `SubmitButton {processing?, disabled?, variant?}`; `ConfirmDialog {title, description, confirmLabel, destructive?}` with a default slot as the trigger, emitting `confirm`; `AttachmentPicker` (`v-model: File[]`, props `rules`, `id?`, `errors?`).
- Badges: `StatusBadge {status}`, `PriorityBadge {priority}`, `SlaBadge {sla, showDue?}`. The props are unchanged from T05, and `showDue` is added.
- Navigation: `NavItem.group?: NavGroup` (`'support' | 'admin'`), `groupedNavigationFor(props)`.
- Flash toasts: every successful Inertia visit whose page carries `flash.success` or `flash.error` raises a sonner toast. A network failure raises an error toast.

**Steps:**

1. Regenerate Wayfinder, which writes the gitignored `resources/js/{routes,actions,wayfinder}` for P2–P4's routes:
   ```bash
   php artisan wayfinder:generate --with-form
   ls resources/js/routes/tickets resources/js/routes/notifications resources/js/routes/admin
   ```
   Expected: `routes/tickets/index.ts` exports `claim` and `assign`, `routes/tickets/replies/index.ts` exists, and `routes/admin/{categories,holidays,imports}` exist. If any is missing, a P3/P4 route is missing: stop and report.

2. Add the shadcn-vue components:
   ```bash
   npx shadcn-vue@latest add native-select alert-dialog switch progress empty tabs --yes
   git status --porcelain
   ```
   Expected: only the six new `resources/js/components/ui/<name>/` directories listed under Files. If the CLI changed anything else (`package.json`, `app.css`, an existing `ui/` component), stop and report. Then make the one allowed edit in `NativeSelect.vue`: in the wrapper `<div data-slot="native-select-wrapper">`, replace `w-fit` with `w-full`, so selects fill their grid cell.

3. Create the types.

   `resources/js/types/pagination.ts`:
   ```ts
   export type PaginationLink = { url: string | null; label: string; active: boolean };
   export type PaginationLinks = { first: string | null; last: string | null; prev: string | null; next: string | null };
   export type PaginationMeta = {
       current_page: number;
       from: number | null;
       last_page: number;
       links: PaginationLink[];
       path: string;
       per_page: number;
       to: number | null;
       total: number;
   };
   export type Paginated<T> = { data: T[]; links: PaginationLinks; meta: PaginationMeta };
   ```
   In `resources/js/types/tickets.ts`, delete the `PaginationLink` and `Paginated<T>` definitions. Add `export type { Paginated, PaginationLink } from '@/types/pagination';` at the top, and append:
   ```ts
   export type TicketScope = 'active' | 'resolved';

   export type TicketFilterOptions = {
       statuses: Option[];
       priorities: Option[];
       slaStates: Option[];
       sorts: Option[];
       categories: Pick<CategorySummary, 'id' | 'name'>[];
       agents: AgentOption[];
       showPeopleFilters: boolean;
   };

   export type AttachmentRules = { extensions: string[]; max_kb: number; max_files: number };
   ```
   `resources/js/types/dashboard.ts`:
   ```ts
   import type { TicketPriorityValue } from '@/types/tickets';

   export type DashboardTotals = { open: number; unassigned: number; critical: number; breached: number; approaching: number; resolved: number };
   export type PriorityCount = { priority: TicketPriorityValue; count: number };
   export type AgentLoad = { agent_id: number; name: string; active: number; breached: number };
   export type DashboardStats = { totals: DashboardTotals; by_priority: PriorityCount[]; by_agent: AgentLoad[] };
   ```
   `resources/js/types/notifications.ts`:
   ```ts
   export type NotificationItem = {
       id: string;
       type: string;
       ticket_number: string | null;
       title: string;
       body: string;
       url: string | null;
       read_at: string | null;
       created_at: string | null;
   };
   ```
   `resources/js/types/imports.ts`:
   ```ts
   import type { EnumValue, UserSummary } from '@/types/tickets';

   export type ImportStatusValue = 'pending' | 'processing' | 'completed' | 'completed_with_errors' | 'failed';
   export type TicketImportItem = {
       id: number;
       original_name: string;
       status: EnumValue<ImportStatusValue>;
       is_finished: boolean;
       total_rows: number;
       processed_rows: number;
       succeeded_rows: number;
       failed_rows: number;
       progress_percent: number;
       started_at: string | null;
       finished_at: string | null;
       created_at: string | null;
       user?: UserSummary | null;
   };
   export type TicketImportErrorItem = {
       id: number;
       row_number: number;
       errors: Record<string, string[]>;
       raw: Record<string, string | null>;
       created_at: string | null;
   };
   export type ImportColumns = { required: string[]; optional: string[] };
   ```
   `resources/js/types/admin.ts` (P2's user types stay in `types/users.ts`):
   ```ts
   export type AdminCategory = { id: number; name: string; slug: string; is_active: boolean; tickets_count: number };
   export type HolidayItem = { id: number; date: string; weekday: string; name: string };
   ```

4. Create `resources/js/lib/format.ts`. The `Intl` formatters are built once and use the browser's locale and time zone:
   ```ts
   const dateTimeFormat = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });
   const dateFormat = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' });
   const relativeFormat = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

   const relativeUnits: [Intl.RelativeTimeFormatUnit, number][] = [
       ['year', 31_536_000],
       ['month', 2_592_000],
       ['week', 604_800],
       ['day', 86_400],
       ['hour', 3_600],
       ['minute', 60],
   ];

   export function formatDateTime(iso: string | null | undefined): string {
       return iso ? dateTimeFormat.format(new Date(iso)) : '—';
   }

   export function formatDate(iso: string | null | undefined): string {
       return iso ? dateFormat.format(new Date(iso)) : '—';
   }

   export function formatRelative(iso: string | null | undefined, now: number = Date.now()): string {
       if (!iso) {
           return '—';
       }

       const seconds = Math.round((new Date(iso).getTime() - now) / 1000);

       for (const [unit, size] of relativeUnits) {
           if (Math.abs(seconds) >= size) {
               return relativeFormat.format(Math.round(seconds / size), unit);
           }
       }

       return relativeFormat.format(seconds, 'second');
   }

   export function formatBytes(bytes: number): string {
       if (bytes < 1024) {
           return `${bytes} B`;
       }

       if (bytes < 1024 * 1024) {
           return `${(bytes / 1024).toFixed(1)} KB`;
       }

       return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
   }
   ```
   And `resources/js/lib/forms.ts`, which collects array-field errors such as `attachments.0` and `attachments.1`:
   ```ts
   export function errorsFor(errors: Partial<Record<string, string>>, prefix: string): string[] {
       return Object.entries(errors)
           .filter((entry): entry is [string, string] => entry[0].startsWith(prefix) && typeof entry[1] === 'string')
           .map(([, message]) => message);
   }
   ```

5. Create the shared components.

   `resources/js/components/PageHeader.vue`:
   ```vue
   <script setup lang="ts">
   defineProps<{ title: string; description?: string }>();
   </script>

   <template>
       <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
           <div class="min-w-0 space-y-1">
               <h1 class="text-xl font-semibold tracking-tight break-words">{{ title }}</h1>
               <p v-if="description" class="text-sm text-muted-foreground">{{ description }}</p>
               <slot name="meta" />
           </div>
           <div v-if="$slots.actions" class="flex shrink-0 flex-wrap items-center gap-2">
               <slot name="actions" />
           </div>
       </div>
   </template>
   ```
   `resources/js/components/EmptyState.vue`:
   ```vue
   <script setup lang="ts">
   import type { Component } from 'vue';
   import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';

   defineProps<{ title: string; description?: string; icon?: Component }>();
   </script>

   <template>
       <Empty class="border border-dashed">
           <EmptyHeader>
               <EmptyMedia v-if="icon" variant="icon"><component :is="icon" aria-hidden="true" /></EmptyMedia>
               <EmptyTitle>{{ title }}</EmptyTitle>
               <EmptyDescription v-if="description">{{ description }}</EmptyDescription>
           </EmptyHeader>
           <EmptyContent v-if="$slots.default"><slot /></EmptyContent>
       </Empty>
   </template>
   ```
   `resources/js/components/ResourcePagination.vue`. It follows the server's `links`, so query-string filters survive paging:
   ```vue
   <script setup lang="ts">
   import { Link } from '@inertiajs/vue3';
   import { ChevronLeft, ChevronRight } from '@lucide/vue';
   import { Button } from '@/components/ui/button';
   import type { PaginationLinks, PaginationMeta } from '@/types/pagination';

   const { only, noun = 'results' } = defineProps<{
       links: PaginationLinks;
       meta: PaginationMeta;
       only?: string[];
       noun?: string;
   }>();
   </script>

   <template>
       <nav v-if="meta.total > 0" class="flex flex-col items-center justify-between gap-2 text-sm sm:flex-row" aria-label="Pagination">
           <p class="text-muted-foreground tabular-nums">{{ meta.from }}–{{ meta.to }} of {{ meta.total }} {{ noun }}</p>
           <div v-if="meta.last_page > 1" class="flex items-center gap-2">
               <Button v-if="links.prev" variant="outline" size="sm" as-child>
                   <Link :href="links.prev" :only="only" preserve-scroll preserve-state><ChevronLeft aria-hidden="true" />Previous</Link>
               </Button>
               <Button v-else variant="outline" size="sm" disabled><ChevronLeft aria-hidden="true" />Previous</Button>
               <span class="px-1 text-muted-foreground tabular-nums">{{ meta.current_page }} / {{ meta.last_page }}</span>
               <Button v-if="links.next" variant="outline" size="sm" as-child>
                   <Link :href="links.next" :only="only" preserve-scroll preserve-state>Next<ChevronRight aria-hidden="true" /></Link>
               </Button>
               <Button v-else variant="outline" size="sm" disabled>Next<ChevronRight aria-hidden="true" /></Button>
           </div>
       </nav>
   </template>
   ```
   `resources/js/components/SubmitButton.vue`:
   ```vue
   <script setup lang="ts">
   import { Button } from '@/components/ui/button';
   import { Spinner } from '@/components/ui/spinner';

   const { processing = false, disabled = false, variant = 'default' } = defineProps<{
       processing?: boolean;
       disabled?: boolean;
       variant?: 'default' | 'destructive' | 'outline' | 'secondary';
   }>();
   </script>

   <template>
       <Button type="submit" :variant="variant" :disabled="processing || disabled" :aria-busy="processing">
           <Spinner v-if="processing" />
           <slot />
       </Button>
   </template>
   ```
   `resources/js/components/ConfirmDialog.vue`. The default slot is the trigger, and `confirm` fires only from the dialog's action button:
   ```vue
   <script setup lang="ts">
   import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle, AlertDialogTrigger } from '@/components/ui/alert-dialog';
   import { buttonVariants } from '@/components/ui/button';

   const { destructive = true } = defineProps<{
       title: string;
       description: string;
       confirmLabel: string;
       destructive?: boolean;
   }>();

   const emit = defineEmits<{ confirm: [] }>();
   </script>

   <template>
       <AlertDialog>
           <AlertDialogTrigger as-child><slot /></AlertDialogTrigger>
           <AlertDialogContent>
               <AlertDialogHeader>
                   <AlertDialogTitle>{{ title }}</AlertDialogTitle>
                   <AlertDialogDescription>{{ description }}</AlertDialogDescription>
               </AlertDialogHeader>
               <AlertDialogFooter>
                   <AlertDialogCancel>Cancel</AlertDialogCancel>
                   <AlertDialogAction :class="buttonVariants({ variant: destructive ? 'destructive' : 'default' })" @click="emit('confirm')">{{ confirmLabel }}</AlertDialogAction>
               </AlertDialogFooter>
           </AlertDialogContent>
       </AlertDialog>
   </template>
   ```
   `resources/js/components/tickets/AttachmentPicker.vue`, used by the create form (P5.3) and the reply composer (P5.4). The real file input is visually hidden, so both a `<Label :for="id">` and the button open the chooser. Picking more files adds to the list instead of replacing it. The limits come from P3's `attachmentRules` prop, which the server builds from `config/attachments.php` (TD-130: nothing hard-coded in the client). They drive the `accept` filter and the hint, and the server still validates and returns its messages through `errors`.
   ```vue
   <script setup lang="ts">
   import { Paperclip, X } from '@lucide/vue';
   import { computed, ref } from 'vue';
   import InputError from '@/components/InputError.vue';
   import { Button } from '@/components/ui/button';
   import { formatBytes } from '@/lib/format';
   import type { AttachmentRules } from '@/types/tickets';

   const files = defineModel<File[]>({ required: true });
   const { rules, id = 'attachments', errors = [] } = defineProps<{ rules: AttachmentRules; id?: string; errors?: string[] }>();

   const input = ref<HTMLInputElement | null>(null);
   const accept = computed(() => rules.extensions.map((extension) => `.${extension}`).join(','));
   const hint = computed(
       () => `Up to ${rules.max_files} files, ${formatBytes(rules.max_kb * 1024)} each. Allowed: ${rules.extensions.join(', ')}.`,
   );

   function add(event: Event): void {
       const target = event.target as HTMLInputElement;
       files.value = [...files.value, ...Array.from(target.files ?? [])];
       target.value = '';
   }

   function remove(index: number): void {
       files.value = files.value.filter((_, position) => position !== index);
   }
   </script>

   <template>
       <div class="grid gap-2">
           <input :id="id" ref="input" type="file" multiple :accept="accept" class="sr-only" :aria-describedby="`${id}-hint`" @change="add" />
           <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
               <Button type="button" variant="outline" size="sm" :disabled="files.length >= rules.max_files" @click="input?.click()">
                   <Paperclip aria-hidden="true" />
                   Attach files
               </Button>
               <p :id="`${id}-hint`" class="text-xs text-muted-foreground">{{ hint }}</p>
           </div>
           <ul v-if="files.length > 0" class="grid gap-1">
               <li v-for="(file, index) in files" :key="`${file.name}-${index}`" class="flex items-center gap-2 rounded-md border px-2 py-1 text-sm">
                   <span class="min-w-0 flex-1 truncate">{{ file.name }}</span>
                   <span class="shrink-0 text-xs text-muted-foreground">{{ formatBytes(file.size) }}</span>
                   <Button type="button" variant="ghost" size="icon" class="size-7" :aria-label="`Remove ${file.name}`" @click="remove(index)">
                       <X aria-hidden="true" />
                   </Button>
               </li>
           </ul>
           <InputError v-for="message in errors" :key="message" :message="message" />
       </div>
   </template>
   ```

6. Replace the three badges, keeping T05's props, so every page shows the same colours. Each badge is `Badge variant="outline"` plus a tone class, and `cn` lets the tone override the outline colours. `StatusBadge.vue`:
   ```vue
   <script setup lang="ts">
   import { Badge } from '@/components/ui/badge';
   import type { EnumValue, TicketStatusValue } from '@/types/tickets';

   defineProps<{ status: EnumValue<TicketStatusValue> }>();

   const tones: Record<TicketStatusValue, string> = {
       open: 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-800 dark:bg-sky-950 dark:text-sky-200',
       in_progress: 'border-indigo-200 bg-indigo-50 text-indigo-800 dark:border-indigo-800 dark:bg-indigo-950 dark:text-indigo-200',
       waiting_for_customer: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200',
       resolved: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
       closed: 'border-border bg-muted text-muted-foreground',
   };
   </script>

   <template>
       <Badge variant="outline" :class="tones[status.value]" :data-status="status.value">{{ status.label }}</Badge>
   </template>
   ```
   `PriorityBadge.vue` follows the same template, with `defineProps<{ priority: EnumValue<TicketPriorityValue> }>()`, `:data-priority` and these tones:
   ```ts
   const tones: Record<TicketPriorityValue, string> = {
       low: 'border-border bg-muted text-muted-foreground',
       medium: 'border-slate-300 bg-slate-50 text-slate-800 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200',
       high: 'border-orange-200 bg-orange-50 text-orange-800 dark:border-orange-800 dark:bg-orange-950 dark:text-orange-200',
       critical: 'border-transparent bg-red-600 text-white dark:bg-red-700',
   };
   ```
   `SlaBadge.vue` shows the due time relative ("due in 3 hours"), with the absolute time as the tooltip:
   ```vue
   <script setup lang="ts">
   import { computed } from 'vue';
   import { Badge } from '@/components/ui/badge';
   import { formatDateTime, formatRelative } from '@/lib/format';
   import type { SlaStateValue, TicketSla } from '@/types/tickets';

   const { sla, showDue = true } = defineProps<{ sla: Pick<TicketSla, 'state' | 'due_at'>; showDue?: boolean }>();

   const tones: Record<SlaStateValue, string> = {
       within: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
       approaching: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200',
       breached: 'border-transparent bg-red-600 text-white dark:bg-red-700',
       paused: 'border-slate-300 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300',
       stopped: 'border-border bg-muted text-muted-foreground',
   };

   const showsDue = computed(
       () => showDue && sla.due_at !== null && !['stopped', 'paused'].includes(sla.state.value),
   );
   </script>

   <template>
       <span class="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
           <Badge variant="outline" :class="tones[sla.state.value]" :data-sla="sla.state.value">{{ sla.state.label }}</Badge>
           <span v-if="showsDue" class="text-xs text-muted-foreground" :title="formatDateTime(sla.due_at)">due {{ formatRelative(sla.due_at) }}</span>
       </span>
   </template>
   ```

7. Group the navigation. In `resources/js/config/navigation.ts`, replace the `NavItem` type and append the helper, leaving the glob and `navigationFor` unchanged:
   ```ts
   export type NavGroup = 'support' | 'admin';

   export type NavItem = {
       title: string;
       href: string;
       icon?: Component;
       order: number;
       group?: NavGroup;
       visible: (page: SharedProps) => boolean;
   };
   ```
   ```ts
   const groupLabels: Record<NavGroup, string> = { support: 'Support', admin: 'Administration' };

   export type NavSection = { group: NavGroup; label: string; items: NavItem[] };

   export function groupedNavigationFor(props: SharedProps): NavSection[] {
       const visible = navigationFor(props);

       return (Object.keys(groupLabels) as NavGroup[])
           .map((group) => ({
               group,
               label: groupLabels[group],
               items: visible.filter((item) => (item.group ?? 'support') === group),
           }))
           .filter((section) => section.items.length > 0);
   }
   ```
   Every module follows this shape (`navigation/dashboard.ts`):
   ```ts
   import { LayoutGrid } from '@lucide/vue';
   import type { NavItem } from '@/config/navigation';
   import { dashboard } from '@/routes';

   const items: NavItem[] = [
       {
           title: 'Dashboard',
           href: dashboard.url(),
           icon: LayoutGrid,
           order: 10,
           group: 'support',
           visible: (page) => page.auth.can.viewDashboard,
       },
   ];

   export default items;
   ```
   | File | Title | Icon | `href` from | Order | Group | `visible` |
   |---|---|---|---|---|---|---|
   | `navigation/dashboard.ts` (create) | Dashboard | `LayoutGrid` | `dashboard.url()` (`@/routes`) | 10 | support | `page.auth.can.viewDashboard` |
   | `navigation/tickets.ts` (rewrite) | Tickets | `Ticket` | `index.url()` (`@/routes/tickets`) | 20 | support | `() => true` |
   | `navigation/notifications.ts` (create) | Notifications | `Bell` | `index.url()` (`@/routes/notifications`) | 30 | support | `() => true` |
   | `navigation/admin.ts` (P2's, append) | Categories | `Tags` | `index.url()` as `categoriesIndex` (`@/routes/admin/categories`) | 70 | admin | `page.auth.can.manageCategories` |
   | `navigation/admin.ts` (P2's, append) | Holidays | `CalendarDays` | `index.url()` as `holidaysIndex` (`@/routes/admin/holidays`) | 71 | admin | `page.auth.can.manageHolidays` |
   | `navigation/imports.ts` (create) | Imports | `FileUp` | `index.url()` (`@/routes/admin/imports`) | 80 | admin | `page.auth.can.manageImports` |

   In `navigation/admin.ts`, add `group: 'admin',` to P2's two entries (Users 72, Roles & permissions 73) and change nothing else in them. Customers see none of the admin group, because every admin entry is gated by an `auth.can` flag they lack.

   In `resources/js/components/AppSidebar.vue`, replace `navigationFor`/`items` with `const sections = computed(() => groupedNavigationFor(page.props));`, and render one `SidebarGroup` per section, so a customer (support items only) sees no "Administration" label:
   ```vue
   <SidebarContent>
       <SidebarGroup v-for="section in sections" :key="section.group" class="px-2 py-0">
           <SidebarGroupLabel>{{ section.label }}</SidebarGroupLabel>
           <SidebarMenu>
               <SidebarMenuItem v-for="item in section.items" :key="item.href">
                   <SidebarMenuButton as-child :is-active="isCurrentOrParentUrl(item.href)" :tooltip="item.title">
                       <Link :href="item.href">
                           <component :is="item.icon" v-if="item.icon" />
                           <span>{{ item.title }}</span>
                       </Link>
                   </SidebarMenuButton>
               </SidebarMenuItem>
           </SidebarMenu>
       </SidebarGroup>
   </SidebarContent>
   ```

8. Move flash to toasts. `resources/js/layouts/AppLayout.vue` keeps its `breadcrumbs` prop and drops the inline banners (and `usePage`/`computed`), so its template is only `<AppSidebarLayout :breadcrumbs="breadcrumbs"><slot /></AppSidebarLayout>`. In `resources/js/layouts/app/AppSidebarLayout.vue`, change `<Toaster />` to `<Toaster rich-colors close-button position="top-right" />`.

   Then rewrite `resources/js/lib/flashToast.ts`. The backend flashes with `->with('success'|'error')`, which is shared as `flash.{success,error}`. The kit's `flash.toast` listener never fired, because the backend never calls `Inertia::flash()`. Listening to `success` (not `navigate`) means a back/forward history visit never replays an old toast. Partial reloads, such as a poll, carry no `flash` key, hence the optional cast:
   ```ts
   import { router } from '@inertiajs/vue3';
   import { toast } from 'vue-sonner';
   import type { SharedProps } from '@/types/shared';

   export function initializeFlashToast(): void {
       router.on('success', (event) => {
           const flash = event.detail.page.props.flash as SharedProps['flash'] | undefined;

           if (flash?.success) {
               toast.success(flash.success);
           }

           if (flash?.error) {
               toast.error(flash.error);
           }
       });

       router.on('networkError', () => {
           toast.error('The server could not be reached. Check your connection and try again.');
       });
   }
   ```
   The event names and `event.detail.page` are those of `node_modules/@inertiajs/core/types/types.d.ts` (Inertia 3.9). Confirm them there or with Boost `search-docs` before writing.

9. Format and lint:
   ```bash
   npx vp fmt resources/js/types resources/js/lib resources/js/config resources/js/layouts resources/js/components/tickets/{AttachmentPicker,StatusBadge,PriorityBadge,SlaBadge}.vue resources/js/components/{PageHeader,EmptyState,ResourcePagination,SubmitButton,ConfirmDialog,AppSidebar}.vue
   npx vp lint resources/js/types resources/js/lib resources/js/config resources/js/layouts resources/js/components/tickets/{AttachmentPicker,StatusBadge,PriorityBadge,SlaBadge}.vue resources/js/components/{PageHeader,EmptyState,ResourcePagination,SubmitButton,ConfirmDialog,AppSidebar}.vue
   ```
   Expected: no lint errors in these files.

**Proposed commit:** `feat(ui): add shared ui foundation, grouped navigation and flash toasts`, `Refs: P5.1`, `Implements: TD-002`

---

## P5.2 — Dashboard page

**Files:** create or replace `resources/js/pages/dashboard/Index.vue`; create `resources/js/components/dashboard/StatCard.vue`. Delete `resources/js/pages/Dashboard.vue` and `resources/js/components/PlaceholderPattern.vue`, but only if `grep -rn "'Dashboard'\|PlaceholderPattern" app routes tests resources/js --include=*.php --include=*.vue --include=*.ts` shows no remaining reference. If anything still references them, keep them and report.

**Consumes:** `dashboard/Index` props `stats: DashboardStats`, `myTickets: TicketListItem[]`; `dashboard` and `tickets.index`/`tickets.show` (Wayfinder); P5.1 `PageHeader`, `EmptyState`, the badges, `formatRelative`.

The tiles link to the filtered ticket list (TD-080 parameters), so every number on the dashboard leads to exactly those tickets. The server caches the figures for 60 s, so the page polls every 60 s, reloading only its two props. `myTickets` uses `TicketListItem`, so the shared badges render it directly.

**Steps:**

1. Create `resources/js/components/dashboard/StatCard.vue`:
   ```vue
   <script setup lang="ts">
   import type { InertiaLinkProps } from '@inertiajs/vue3';
   import { Link } from '@inertiajs/vue3';
   import type { Component } from 'vue';
   import { Card, CardContent } from '@/components/ui/card';

   const { tone = 'default' } = defineProps<{
       label: string;
       value: number;
       href: NonNullable<InertiaLinkProps['href']>;
       icon: Component;
       tone?: 'default' | 'warning' | 'danger' | 'success';
   }>();

   const tones = {
       default: 'text-foreground',
       warning: 'text-amber-600 dark:text-amber-400',
       danger: 'text-red-600 dark:text-red-400',
       success: 'text-emerald-600 dark:text-emerald-400',
   } as const;
   </script>

   <template>
       <Link :href="href" class="block rounded-xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
           <Card class="h-full gap-0 py-4 transition-colors hover:bg-muted/50">
               <CardContent class="flex items-start justify-between gap-2 px-4">
                   <div class="flex flex-col gap-1">
                       <span class="text-sm text-muted-foreground">{{ label }}</span>
                       <span class="text-3xl font-semibold tabular-nums" :class="tones[tone]">{{ value }}</span>
                   </div>
                   <component :is="icon" class="size-5 text-muted-foreground" aria-hidden="true" />
               </CardContent>
           </Card>
       </Link>
   </template>
   ```

2. Create or replace `resources/js/pages/dashboard/Index.vue`:
   ```vue
   <script setup lang="ts">
   import { Head, Link, usePoll } from '@inertiajs/vue3';
   import { AlarmClock, CheckCircle2, CircleDot, Flame, Inbox, ShieldAlert, UserX } from '@lucide/vue';
   import { computed } from 'vue';
   import StatCard from '@/components/dashboard/StatCard.vue';
   import EmptyState from '@/components/EmptyState.vue';
   import PageHeader from '@/components/PageHeader.vue';
   import PriorityBadge from '@/components/tickets/PriorityBadge.vue';
   import SlaBadge from '@/components/tickets/SlaBadge.vue';
   import StatusBadge from '@/components/tickets/StatusBadge.vue';
   import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
   import { Progress } from '@/components/ui/progress';
   import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
   import { dashboard } from '@/routes';
   import { index as ticketsIndex, show as ticketShow } from '@/routes/tickets';
   import type { DashboardStats } from '@/types/dashboard';
   import type { TicketListItem, TicketPriorityValue } from '@/types/tickets';

   defineOptions({
       layout: { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }] },
   });

   const props = defineProps<{ stats: DashboardStats; myTickets: TicketListItem[] }>();

   usePoll(60_000, { only: ['stats', 'myTickets'] });

   const active = (query: Record<string, string | string[]> = {}) =>
       ticketsIndex({ query: { scope: 'active', ...query } });

   const cards = computed(() => [
       { label: 'Open', value: props.stats.totals.open, href: active(), icon: CircleDot, tone: 'default' as const },
       { label: 'Unassigned', value: props.stats.totals.unassigned, href: active({ unassigned: '1' }), icon: UserX, tone: 'warning' as const },
       { label: 'Critical', value: props.stats.totals.critical, href: active({ priority: ['critical'] }), icon: Flame, tone: 'danger' as const },
       { label: 'SLA breached', value: props.stats.totals.breached, href: active({ sla_state: 'breached' }), icon: ShieldAlert, tone: 'danger' as const },
       { label: 'Approaching SLA', value: props.stats.totals.approaching, href: active({ sla_state: 'approaching' }), icon: AlarmClock, tone: 'warning' as const },
       { label: 'Resolved (30 days)', value: props.stats.totals.resolved, href: ticketsIndex({ query: { scope: 'resolved' } }), icon: CheckCircle2, tone: 'success' as const },
   ]);

   const priorityLabels: Record<TicketPriorityValue, string> = {
       critical: 'Critical',
       high: 'High',
       medium: 'Medium',
       low: 'Low',
   };

   const totalActive = computed(() =>
       props.stats.by_priority.reduce((sum, row) => sum + row.count, 0),
   );

   const share = (count: number): number =>
       totalActive.value === 0 ? 0 : Math.round((count / totalActive.value) * 100);

   </script>

   <template>
       <Head title="Dashboard" />

       <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-6">
           <PageHeader title="Dashboard" description="Live ticket workload. Figures refresh every minute." />

           <section aria-label="Totals" class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
               <StatCard v-for="card in cards" :key="card.label" v-bind="card" />
           </section>

           <Card>
               <CardHeader><CardTitle class="text-base">My tickets</CardTitle></CardHeader>
               <CardContent>
                   <EmptyState v-if="myTickets.length === 0" :icon="Inbox" title="Nothing assigned to you" description="Claim a ticket from the unassigned queue to start working on it." />
                   <ul v-else class="divide-y">
                       <li v-for="ticket in myTickets" :key="ticket.number" class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                           <Link :href="ticketShow(ticket.number)" class="min-w-0 underline-offset-4 hover:underline">
                               <span class="font-mono text-xs text-muted-foreground">{{ ticket.number }}</span>
                               <span class="ml-2 font-medium break-words">{{ ticket.subject }}</span>
                           </Link>
                           <div class="flex shrink-0 flex-wrap items-center gap-2">
                               <StatusBadge :status="ticket.status" />
                               <PriorityBadge :priority="ticket.priority" />
                               <SlaBadge :sla="ticket.sla" />
                           </div>
                       </li>
                   </ul>
               </CardContent>
           </Card>

           <div class="grid gap-6 lg:grid-cols-2">
               <Card>
                   <CardHeader><CardTitle class="text-base">Active tickets by priority</CardTitle></CardHeader>
                   <CardContent>
                       <ul class="flex flex-col gap-3">
                           <li v-for="row in stats.by_priority" :key="row.priority" class="grid gap-1">
                               <div class="flex items-center justify-between text-sm">
                                   <Link :href="active({ priority: [row.priority] })" class="underline-offset-4 hover:underline">{{ priorityLabels[row.priority] }}</Link>
                                   <span class="text-muted-foreground tabular-nums">{{ row.count }} · {{ share(row.count) }}%</span>
                               </div>
                               <Progress :model-value="share(row.count)" :aria-label="`${priorityLabels[row.priority]}: ${share(row.count)}% of active tickets`" />
                           </li>
                       </ul>
                   </CardContent>
               </Card>

               <Card>
                   <CardHeader><CardTitle class="text-base">Active tickets by agent</CardTitle></CardHeader>
                   <CardContent>
                       <EmptyState v-if="stats.by_agent.length === 0" title="No agents yet" description="Agents appear here once an admin creates them." />
                       <div v-else class="overflow-x-auto rounded-md border">
                           <Table>
                               <TableHeader>
                                   <TableRow>
                                       <TableHead>Agent</TableHead>
                                       <TableHead class="text-right">Active</TableHead>
                                       <TableHead class="text-right">Breached</TableHead>
                                   </TableRow>
                               </TableHeader>
                               <TableBody>
                                   <TableRow v-for="row in stats.by_agent" :key="row.agent_id">
                                       <TableCell>
                                           <Link :href="active({ assigned_agent_id: String(row.agent_id) })" class="underline-offset-4 hover:underline">{{ row.name }}</Link>
                                       </TableCell>
                                       <TableCell class="text-right tabular-nums">{{ row.active }}</TableCell>
                                       <TableCell
                                           class="text-right tabular-nums"
                                           :class="row.breached > 0 ? 'font-semibold text-red-600 dark:text-red-400' : 'text-muted-foreground'"
                                           >{{ row.breached }}</TableCell
                                       >
                                   </TableRow>
                               </TableBody>
                           </Table>
                       </div>
                   </CardContent>
               </Card>
           </div>
       </div>
   </template>
   ```

3. Delete the kit placeholder, after the grep from **Files** comes back clean:
   ```bash
   rm resources/js/pages/Dashboard.vue resources/js/components/PlaceholderPattern.vue
   ```

4. `npx vp fmt resources/js/pages/dashboard resources/js/components/dashboard && npx vp lint resources/js/pages/dashboard resources/js/components/dashboard`

**Proposed commit:** `feat(dashboard): add dashboard page with linked totals and workload`, `Refs: P5.2`, `Implements: TD-100`

---

## P5.3 — Ticket list filter bar and create page

**Files:** modify `resources/js/pages/tickets/Index.vue`, `resources/js/pages/tickets/Create.vue`; replace `resources/js/components/tickets/TicketFilterBar.vue`.

**Consumes:** `tickets/Index` props, including P3's `filterOptions`; `tickets/Create` props, including P3's `attachmentRules`; the TD-080 query parameters; Wayfinder `@/routes/tickets` (`index`, `create`, `store`, `show`); the P5.1 components, `errorsFor` and `AttachmentPicker`.

The filter bar keeps the URL as the single source of truth. Every option list (statuses of the current scope, priorities, SLA states, sorts, categories, agents) comes from P3's `filterOptions`, so the bar holds no enum labels of its own. Its state is initialised from the `filters` prop, and every change is debounced by 300 ms and sent as a `replace` + `preserveState` GET. A reload, a shared link and the Back button all restore the same list. The keyword, ticket ID and sort are always visible. The other filters sit behind a "Filters" toggle, which shows how many are active and starts open whenever one is set. On a phone, the bar is then one row tall until the user asks for more.

**Steps:**

1. Replace `resources/js/components/tickets/TicketFilterBar.vue`:
   ```vue
   <script setup lang="ts">
   import { router } from '@inertiajs/vue3';
   import { RotateCcw, Search, SlidersHorizontal } from '@lucide/vue';
   import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
   import { Button } from '@/components/ui/button';
   import { Checkbox } from '@/components/ui/checkbox';
   import { Input } from '@/components/ui/input';
   import { Label } from '@/components/ui/label';
   import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
   import { index } from '@/routes/tickets';
   import type { TicketFilterOptions, TicketScope } from '@/types/tickets';

   const props = defineProps<{
       scope: TicketScope;
       filters: Record<string, unknown>;
       options: TicketFilterOptions;
       errors: Partial<Record<string, string>>;
   }>();

   const DEFAULT_SORT = '-created_at';


   type FilterState = {
       number: string;
       q: string;
       status: string[];
       priority: string[];
       category_id: string;
       assigned_agent_id: string;
       unassigned: boolean;
       customer_id: string;
       created_from: string;
       created_to: string;
       sla_state: string;
       sort: string;
   };

   const ADVANCED: (keyof FilterState)[] = [
       'status', 'priority', 'category_id', 'assigned_agent_id', 'unassigned',
       'customer_id', 'created_from', 'created_to', 'sla_state',
   ];
   const SCALARS: (keyof FilterState)[] = [
       'number', 'q', 'category_id', 'assigned_agent_id', 'customer_id',
       'created_from', 'created_to', 'sla_state',
   ];

   const text = (value: unknown): string =>
       typeof value === 'string' || typeof value === 'number' ? String(value) : '';
   const list = (value: unknown): string[] =>
       Array.isArray(value) ? value.filter((v): v is string => typeof v === 'string') : [];
   const flag = (value: unknown): boolean =>
       value === true || value === '1' || value === 1 || value === 'true';

   function fromFilters(filters: Record<string, unknown>): FilterState {
       return {
           number: text(filters.number),
           q: text(filters.q),
           status: list(filters.status),
           priority: list(filters.priority),
           category_id: text(filters.category_id),
           assigned_agent_id: text(filters.assigned_agent_id),
           unassigned: flag(filters.unassigned),
           customer_id: text(filters.customer_id),
           created_from: text(filters.created_from),
           created_to: text(filters.created_to),
           sla_state: text(filters.sla_state),
           sort: text(filters.sort) || DEFAULT_SORT,
       };
   }

   const state = reactive<FilterState>(fromFilters(props.filters));

   function toQuery(s: FilterState): Record<string, string | string[]> {
       const query: Record<string, string | string[]> = { scope: props.scope };

       for (const key of SCALARS) {
           const value = String(s[key]).trim();
           if (value !== '') {
               query[key] = value;
           }
       }
       if (s.status.length > 0) {
           query.status = [...s.status];
       }
       if (s.priority.length > 0) {
           query.priority = [...s.priority];
       }
       if (s.unassigned) {
           query.unassigned = '1';
       }
       if (s.sort !== DEFAULT_SORT) {
           query.sort = s.sort;
       }

       return query;
   }

   const activeAdvanced = computed(
       () => ADVANCED.filter((key) => key in toQuery(state)).length,
   );
   const hasFilters = computed(
       () => Object.keys(toQuery(state)).some((key) => key !== 'scope'),
   );
   const showAdvanced = ref(activeAdvanced.value > 0);

   let timer: ReturnType<typeof setTimeout> | undefined;

   watch(
       state,
       () => {
           clearTimeout(timer);
           timer = setTimeout(() => {
               router.get(index.url(), toQuery(state), {
                   preserveState: true,
                   preserveScroll: true,
                   replace: true,
               });
           }, 300);
       },
       { deep: true },
   );

   onBeforeUnmount(() => clearTimeout(timer));

   function toggle(values: string[], value: string): void {
       const position = values.indexOf(value);
       if (position === -1) {
           values.push(value);
       } else {
           values.splice(position, 1);
       }
   }

   function setUnassigned(value: boolean | 'indeterminate'): void {
       state.unassigned = value === true;
       if (state.unassigned) {
           state.assigned_agent_id = '';
       }
   }

   function reset(): void {
       Object.assign(state, fromFilters({}));
   }

   const chipClass = (active: boolean): string =>
       [
           'h-8 rounded-full border px-3 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
           active
               ? 'border-primary bg-primary text-primary-foreground'
               : 'border-input bg-background hover:bg-accent',
       ].join(' ');
   </script>

   <template>
       <section aria-label="Filter tickets" class="flex flex-col gap-4 rounded-lg border bg-card p-4">
           <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_12rem_12rem_auto] md:items-end">
               <div class="grid gap-2">
                   <Label for="filter-q">Keyword</Label>
                   <div class="relative">
                       <Search class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                       <Input id="filter-q" v-model="state.q" type="search" placeholder="Subject or description" class="pl-8" maxlength="200" />
                   </div>
                   <p v-if="errors.q" class="text-sm text-destructive">{{ errors.q }}</p>
               </div>
               <div class="grid gap-2">
                   <Label for="filter-number">Ticket ID</Label>
                   <Input id="filter-number" v-model="state.number" placeholder="TKT-2026-000123" maxlength="32" />
               </div>
               <div class="grid gap-2">
                   <Label for="filter-sort">Sort</Label>
                   <NativeSelect id="filter-sort" v-model="state.sort">
                       <NativeSelectOption v-for="option in options.sorts" :key="option.value" :value="option.value">{{ option.label }}</NativeSelectOption>
                   </NativeSelect>
               </div>
               <Button type="button" variant="outline" :aria-expanded="showAdvanced" aria-controls="ticket-filters-advanced" @click="showAdvanced = !showAdvanced">
                   <SlidersHorizontal aria-hidden="true" />
                   Filters<span v-if="activeAdvanced > 0" class="tabular-nums"> ({{ activeAdvanced }})</span>
               </Button>
           </div>

           <div v-show="showAdvanced" id="ticket-filters-advanced" class="flex flex-col gap-4">
               <fieldset class="flex flex-wrap items-center gap-2">
                   <legend class="mb-2 text-sm font-medium">Status</legend>
                   <button
                       v-for="option in options.statuses"
                       :key="option.value"
                       type="button"
                       :class="chipClass(state.status.includes(option.value))"
                       :aria-pressed="state.status.includes(option.value)"
                       @click="toggle(state.status, option.value)"
                   >{{ option.label }}</button>
               </fieldset>

               <fieldset class="flex flex-wrap items-center gap-2">
                   <legend class="mb-2 text-sm font-medium">Priority</legend>
                   <button
                       v-for="option in options.priorities"
                       :key="option.value"
                       type="button"
                       :class="chipClass(state.priority.includes(option.value))"
                       :aria-pressed="state.priority.includes(option.value)"
                       @click="toggle(state.priority, option.value)"
                   >{{ option.label }}</button>
               </fieldset>

               <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                   <div v-if="options.categories.length > 0" class="grid gap-2">
                       <Label for="filter-category">Category</Label>
                       <NativeSelect id="filter-category" v-model="state.category_id">
                           <NativeSelectOption value="">All categories</NativeSelectOption>
                           <NativeSelectOption v-for="category in options.categories" :key="category.id" :value="String(category.id)">{{ category.name }}</NativeSelectOption>
                       </NativeSelect>
                   </div>
                   <div class="grid gap-2">
                       <Label for="filter-sla">SLA</Label>
                       <NativeSelect id="filter-sla" v-model="state.sla_state">
                           <NativeSelectOption value="">Any SLA state</NativeSelectOption>
                           <NativeSelectOption v-for="option in options.slaStates" :key="option.value" :value="option.value">{{ option.label }}</NativeSelectOption>
                       </NativeSelect>
                   </div>
                   <div class="grid gap-2">
                       <Label for="filter-from">Created from</Label>
                       <Input id="filter-from" v-model="state.created_from" type="date" />
                   </div>
                   <div class="grid gap-2">
                       <Label for="filter-to">Created to</Label>
                       <Input id="filter-to" v-model="state.created_to" type="date" :min="state.created_from || undefined" />
                       <p v-if="errors.created_to" class="text-sm text-destructive">{{ errors.created_to }}</p>
                   </div>
               </div>

               <div v-if="options.showPeopleFilters" class="grid gap-3 sm:grid-cols-3 sm:items-end">
                   <div v-if="options.agents.length > 0" class="grid gap-2">
                       <Label for="filter-agent">Assigned agent</Label>
                       <NativeSelect id="filter-agent" v-model="state.assigned_agent_id" :disabled="state.unassigned">
                           <NativeSelectOption value="">Any agent</NativeSelectOption>
                           <NativeSelectOption v-for="agent in options.agents" :key="agent.id" :value="String(agent.id)">{{ agent.name }}</NativeSelectOption>
                       </NativeSelect>
                   </div>
                   <div class="flex h-9 items-center gap-2">
                       <Checkbox id="filter-unassigned" :model-value="state.unassigned" @update:model-value="setUnassigned" />
                       <Label for="filter-unassigned">Unassigned only</Label>
                   </div>
                   <div class="grid gap-2">
                       <Label for="filter-customer">Customer ID</Label>
                       <Input id="filter-customer" v-model="state.customer_id" type="number" min="1" inputmode="numeric" />
                   </div>
               </div>
           </div>

           <div v-if="hasFilters" class="flex justify-end">
               <Button type="button" variant="ghost" size="sm" @click="reset">
                   <RotateCcw aria-hidden="true" />
                   Clear filters
               </Button>
           </div>
       </section>
   </template>
   ```
   Check the installed `Checkbox` model binding in `resources/js/components/ui/checkbox/Checkbox.vue` (reka-ui 2 uses `modelValue`). If it still uses `checked`, bind `:checked` / `@update:checked` instead.

2. Rewrite `resources/js/pages/tickets/Index.vue`. The scope switch is a two-link segmented control. On phones the table keeps the ticket, status and SLA columns, and the rest appear from `md`/`lg` up. The customer column is shown to staff only (`filterOptions.showPeopleFilters`). With no hits, the empty state tells a filtered search apart from an empty queue.
   ```vue
   <script setup lang="ts">
   import { Head, Link, usePage } from '@inertiajs/vue3';
   import { Inbox, Plus, SearchX } from '@lucide/vue';
   import { computed } from 'vue';
   import EmptyState from '@/components/EmptyState.vue';
   import PageHeader from '@/components/PageHeader.vue';
   import ResourcePagination from '@/components/ResourcePagination.vue';
   import PriorityBadge from '@/components/tickets/PriorityBadge.vue';
   import SlaBadge from '@/components/tickets/SlaBadge.vue';
   import StatusBadge from '@/components/tickets/StatusBadge.vue';
   import TicketFilterBar from '@/components/tickets/TicketFilterBar.vue';
   import { Button } from '@/components/ui/button';
   import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
   import { formatDate, formatDateTime } from '@/lib/format';
   import { create, index, show } from '@/routes/tickets';
   import type { Paginated, TicketFilterOptions, TicketListItem, TicketScope } from '@/types/tickets';

   defineOptions({
       layout: { breadcrumbs: [{ title: 'Tickets', href: index() }] },
   });

   const props = defineProps<{
       tickets: Paginated<TicketListItem>;
       scope: TicketScope;
       filters: Record<string, unknown>;
       filterOptions: TicketFilterOptions;
   }>();

   const page = usePage();
   const errors = computed(() => page.props.errors as Partial<Record<string, string>>);

   const scopes: { value: TicketScope; label: string }[] = [ { value: 'active', label: 'Active' }, { value: 'resolved', label: 'Resolved & closed' } ];

   const isFiltered = computed(() =>
       Object.keys(props.filters).some((key) => !['scope', 'page', 'per_page'].includes(key)),
   );
   const showCustomer = computed(() => props.filterOptions.showPeopleFilters);
   </script>

   <template>
       <Head title="Tickets" />

       <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-6">
           <PageHeader title="Tickets" description="Search, filter and open support tickets.">
               <template #actions>
                   <Button as-child>
                       <Link :href="create()"><Plus aria-hidden="true" />New ticket</Link>
                   </Button>
               </template>
           </PageHeader>

           <nav aria-label="Ticket scope" class="inline-flex w-fit rounded-md border bg-muted p-0.5">
               <Link
                   v-for="item in scopes"
                   :key="item.value"
                   :href="index({ query: { scope: item.value } })"
                   :aria-current="scope === item.value ? 'page' : undefined"
                   :class="[
                       'rounded px-3 py-1.5 text-sm font-medium transition-colors',
                       scope === item.value ? 'bg-background shadow-xs' : 'text-muted-foreground hover:text-foreground',
                   ]"
                   >{{ item.label }}</Link
               >
           </nav>

           <TicketFilterBar :key="scope" :scope="scope" :filters="filters" :options="filterOptions" :errors="errors" />

           <EmptyState v-if="tickets.data.length === 0 && isFiltered" :icon="SearchX" title="No tickets match these filters" description="Try a broader keyword or clear the filters.">
               <Button variant="outline" as-child><Link :href="index({ query: { scope } })">Clear filters</Link></Button>
           </EmptyState>
           <EmptyState
               v-else-if="tickets.data.length === 0"
               :icon="Inbox"
               :title="scope === 'active' ? 'No active tickets' : 'No resolved tickets yet'"
               description="New tickets appear here as soon as they are created."
           >
               <Button v-if="scope === 'active'" as-child><Link :href="create()">New ticket</Link></Button>
           </EmptyState>

           <template v-else>
               <div class="overflow-x-auto rounded-md border">
                   <Table>
                       <TableHeader>
                           <TableRow>
                               <TableHead>Ticket</TableHead>
                               <TableHead>Status</TableHead>
                               <TableHead class="hidden md:table-cell">Priority</TableHead>
                               <TableHead>SLA</TableHead>
                               <TableHead v-if="showCustomer" class="hidden lg:table-cell">Customer</TableHead>
                               <TableHead class="hidden md:table-cell">Agent</TableHead>
                               <TableHead class="hidden lg:table-cell">Created</TableHead>
                           </TableRow>
                       </TableHeader>
                       <TableBody>
                           <TableRow v-for="ticket in tickets.data" :key="ticket.number">
                               <TableCell class="max-w-[16rem] sm:max-w-md">
                                   <Link :href="show(ticket.number)" class="group block min-w-0">
                                       <span class="block font-mono text-xs text-muted-foreground">{{ ticket.number }}</span>
                                       <span class="block truncate font-medium group-hover:underline">{{ ticket.subject }}</span>
                                   </Link>
                               </TableCell>
                               <TableCell><StatusBadge :status="ticket.status" /></TableCell>
                               <TableCell class="hidden md:table-cell"><PriorityBadge :priority="ticket.priority" /></TableCell>
                               <TableCell><SlaBadge :sla="ticket.sla" /></TableCell>
                               <TableCell v-if="showCustomer" class="hidden lg:table-cell">{{ ticket.customer?.name ?? '—' }}</TableCell>
                               <TableCell class="hidden md:table-cell">
                                   <span :class="ticket.assigned_agent ? '' : 'text-muted-foreground'">{{ ticket.assigned_agent?.name ?? 'Unassigned' }}</span>
                               </TableCell>
                               <TableCell class="hidden lg:table-cell" :title="formatDateTime(ticket.created_at)">{{ formatDate(ticket.created_at) }}</TableCell>
                           </TableRow>
                       </TableBody>
                   </Table>
               </div>
               <ResourcePagination :links="tickets.links" :meta="tickets.meta" noun="tickets" />
           </template>
       </div>
   </template>
   ```
   `:key="scope"` remounts the filter bar when the scope changes, so the status chips reset to the new scope's statuses.

3. Rewrite `resources/js/pages/tickets/Create.vue`. T05's props, `useForm` fields, `attachmentErrors` and `transform` (dropping `customer_email` unless `canCreateOnBehalf`) stay. These change:
   - Props: add `attachmentRules: AttachmentRules`.
   - Breadcrumbs: `{ title: 'Tickets', href: index() }, { title: 'New ticket', href: create() }`.
   - The shell is `max-w-2xl`, with `<PageHeader title="New ticket" :description="canCreateOnBehalf ? 'Open a ticket for a customer.' : 'Tell us what you need help with. We reply by email and here.'" />`. The form sits in one `Card` > `CardContent` as `<form class="flex flex-col gap-5">`.
   - Both selects become `NativeSelect`. The category select gets `required` and a first `<NativeSelectOption value="" disabled>Choose a category</NativeSelectOption>`:
     ```vue
     <NativeSelect id="priority" v-model="form.priority">
         <NativeSelectOption v-for="option in options.priorities" :key="option.value" :value="option.value">{{ option.label }}</NativeSelectOption>
     </NativeSelect>
     ```
   - The file input and `onFiles` are replaced by `<AttachmentPicker id="attachments" v-model="form.attachments" :rules="attachmentRules" :errors="attachmentErrors" />`, where `const attachmentErrors = computed(() => errorsFor(form.errors, 'attachments'))`.
   - The submit is `.post(store.url(), { forceFormData: true })`. The footer is `<div class="flex flex-wrap items-center gap-2"><SubmitButton :processing="form.processing">Create ticket</SubmitButton>` followed by a ghost `Button as-child` wrapping `<Link :href="index()">Cancel</Link>`.
   - `customer_email` gets `autocomplete="off"`.

4. `npx vp fmt resources/js/pages/tickets/Index.vue resources/js/pages/tickets/Create.vue resources/js/components/tickets/TicketFilterBar.vue`, then `npx vp lint` on the same files.

**Proposed commit:** `feat(search): add ticket filter bar and polish list and create pages`, `Refs: P5.3`, `Implements: TD-080`

---

## P5.4 — Ticket detail

**Files:** modify `resources/js/pages/tickets/Show.vue`; replace `resources/js/components/tickets/{AssignmentPanel,ConversationPanel,AttachmentList,ActivityTimeline,TicketStateControls,TicketEditForm}.vue`; create `resources/js/components/tickets/ReplyComposer.vue`, `resources/js/components/tickets/TicketDetailsCard.vue`.

**Consumes:** `tickets/Show` props (`ticket, replies, attachments, activities, agents, can, options, attachmentRules`); Wayfinder `@/routes/tickets` (`index`, `show`, `update`, `status`, `priority`, `claim`, `assign`) and `@/routes/tickets/replies` (`store`); P5.1's `ConfirmDialog`, `SubmitButton`, `EmptyState`, `AttachmentPicker`, the badges, `@/lib/format` and `@/lib/forms`; `@/composables/useInitials`.

The component props from the old contract are kept (`AssignmentPanel {ticket, agents, can}`, `ConversationPanel {ticket, replies, can}`, `AttachmentList {attachments}`). `ConversationPanel` gains `attachmentRules`, which it passes through `ReplyComposer` to `AttachmentPicker`. Visibility is decided on the server: `replies`, `attachments` and `activities` already exclude internal items for customers, and the UI only marks what it receives. The page shows the internal-note toggle only when `can.addInternalNote` is true, and the claim/assign controls only when `can.claim`/`can.assign` are true. Errors from claim, assign, status and reply (`TicketAlreadyClaimed`, `AgentNotEligible`, `InvalidStatusTransition`, `TicketClosed`) arrive as flash `error` toasts, with nothing inline.

Layout: on `lg` and up, the conversation sits in the main two-thirds and the details, actions and assignment sit in a right column. On a phone, the right column moves **above** the description (`order-first lg:order-last`), so the status and claim actions aren't buried below a long thread. The conversation and the activity history share one `Tabs` card.

**Steps:**

1. Create `resources/js/components/tickets/TicketDetailsCard.vue` (`defineProps<{ ticket: TicketDetail }>()`). It is a `Card` titled "Details" holding `<dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2 text-sm">`, with each `dt` in `text-muted-foreground`. Rows in order:

   | Term | Value | Shown when |
   |---|---|---|
   | Customer | `ticket.customer?.name ?? '—'` (`break-words`) | always |
   | Category | `ticket.category?.name ?? '—'` | always |
   | Agent | `ticket.assigned_agent?.name ?? 'Unassigned'` | always |
   | Created / Updated | `formatRelative(...)`, with `:title="formatDateTime(...)"` | always |
   | SLA due | `formatDateTime(ticket.sla.due_at)` | `due_at` set and state not `stopped` |
   | Paused since | `formatDateTime(ticket.sla.paused_at)` | `paused_at` set |
   | Escalation | `{ 1: 'SLA warning sent', 2: 'Escalated (breached)' }[escalation_level]` in `text-destructive` | `escalation_level > 0` |
   | Resolved / Closed | `formatDateTime(...)` | the timestamp is set |

2. Replace `resources/js/components/tickets/TicketStateControls.vue`. Customers on a resolved ticket get "Reopen" and "Close ticket" (`allowedStatuses` already contains only what the policy allows). Closing asks for confirmation, because a closed ticket accepts no more replies. Priority changes are sent as soon as a new value is picked.
   ```vue
   <script setup lang="ts">
   import { router } from '@inertiajs/vue3';
   import { computed, ref } from 'vue';
   import ConfirmDialog from '@/components/ConfirmDialog.vue';
   import { Button } from '@/components/ui/button';
   import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
   import { Label } from '@/components/ui/label';
   import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
   import { Spinner } from '@/components/ui/spinner';
   import { priority as priorityRoute, status as statusRoute } from '@/routes/tickets';
   import type { Option, TicketCan, TicketDetail } from '@/types/tickets';

   const props = defineProps<{ ticket: TicketDetail; can: TicketCan; statuses: Option[]; priorities: Option[] }>();

   const pending = ref<string | null>(null);

   const label = (value: string): string => {
       if (value === 'closed') {
           return 'Close ticket';
       }
       if (value === 'in_progress' && ['resolved', 'closed'].includes(props.ticket.status.value)) {
           return 'Reopen';
       }
       const name = props.statuses.find((s) => s.value === value)?.label ?? value;
       return `Mark as ${name.toLowerCase()}`;
   };

   const targets = computed(() => props.can.allowedStatuses.filter((value) => value !== 'closed'));
   const canClose = computed(() => props.can.allowedStatuses.includes('closed'));

   const visit = (key: string) => ({
       preserveScroll: true,
       onStart: () => { pending.value = key; },
       onFinish: () => { pending.value = null; },
   });

   function changeStatus(value: string): void {
       router.patch(statusRoute.url(props.ticket.number), { status: value }, visit(value));
   }

   function changePriority(value: unknown): void {
       const next = String(value ?? '');
       if (next === '' || next === props.ticket.priority.value) {
           return;
       }
       router.patch(priorityRoute.url(props.ticket.number), { priority: next }, visit('priority'));
   }
   </script>

   <template>
       <Card v-if="(can.changeStatus && can.allowedStatuses.length > 0) || can.changePriority">
           <CardHeader><CardTitle class="text-base">Actions</CardTitle></CardHeader>
           <CardContent class="flex flex-col gap-4">
               <div v-if="can.changeStatus" class="flex flex-wrap gap-2">
                   <Button v-for="value in targets" :key="value" size="sm" variant="outline" :disabled="pending !== null" :data-status-target="value" @click="changeStatus(value)">
                       <Spinner v-if="pending === value" />
                       {{ label(value) }}
                   </Button>
                   <ConfirmDialog v-if="canClose" title="Close this ticket?" description="A closed ticket can no longer receive replies." confirm-label="Close ticket" @confirm="changeStatus('closed')">
                       <Button size="sm" variant="outline" :disabled="pending !== null" data-status-target="closed">
                           <Spinner v-if="pending === 'closed'" />
                           Close ticket
                       </Button>
                   </ConfirmDialog>
               </div>
               <div v-if="can.changePriority" class="grid gap-2">
                   <Label for="ticket-priority">Priority</Label>
                   <NativeSelect id="ticket-priority" :model-value="ticket.priority.value" :disabled="pending !== null" @update:model-value="changePriority">
                       <NativeSelectOption v-for="option in priorities" :key="option.value" :value="option.value">{{ option.label }}</NativeSelectOption>
                   </NativeSelect>
               </div>
           </CardContent>
       </Card>
   </template>
   ```

3. Replace `resources/js/components/tickets/AssignmentPanel.vue`. This is T06's panel with `NativeSelect`, Wayfinder and toasts in place of the inline flash. "Claim" shows only for an unassigned ticket. "Hand off" lists every available agent except the current one.
   ```vue
   <script setup lang="ts">
   import { router } from '@inertiajs/vue3';
   import { computed, ref } from 'vue';
   import InputError from '@/components/InputError.vue';
   import { Button } from '@/components/ui/button';
   import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
   import { Label } from '@/components/ui/label';
   import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
   import { Spinner } from '@/components/ui/spinner';
   import { assign, claim } from '@/routes/tickets';
   import type { AgentOption, TicketCan, TicketDetail } from '@/types/tickets';

   const props = defineProps<{ ticket: TicketDetail; agents: AgentOption[]; can: TicketCan }>();

   const selectedAgent = ref('');
   const busy = ref<'claim' | 'assign' | null>(null);
   const error = ref<string | undefined>();

   const isAssigned = computed(() => props.ticket.assigned_agent !== null);
   const candidates = computed(() => props.agents.filter((agent) => agent.id !== props.ticket.assigned_agent?.id));

   function claimTicket(): void {
       router.post(claim.url(props.ticket.number), {}, {
           preserveScroll: true,
           onStart: () => { busy.value = 'claim'; },
           onFinish: () => { busy.value = null; },
       });
   }

   function assignTicket(): void {
       router.post(assign.url(props.ticket.number), { agent_id: Number(selectedAgent.value) }, {
           preserveScroll: true,
           onStart: () => { busy.value = 'assign'; },
           onFinish: () => { busy.value = null; },
           onSuccess: () => {
               selectedAgent.value = '';
               error.value = undefined;
           },
           onError: (errors) => { error.value = errors.agent_id; },
       });
   }
   </script>

   <template>
       <Card v-if="can.claim || can.assign || isAssigned">
           <CardHeader><CardTitle class="text-base">Assignment</CardTitle></CardHeader>
           <CardContent class="flex flex-col gap-4">
               <p class="text-sm">
                   <span class="text-muted-foreground">Assigned to</span>
                   <span class="ml-1 font-medium" data-testid="assigned-agent">{{ ticket.assigned_agent?.name ?? 'Nobody yet' }}</span>
               </p>

               <Button v-if="can.claim && !isAssigned" class="w-full" :disabled="busy !== null" @click="claimTicket">
                   <Spinner v-if="busy === 'claim'" />
                   Claim ticket
               </Button>

               <form v-if="can.assign && candidates.length > 0" class="grid gap-2" @submit.prevent="assignTicket">
                   <Label for="assign-agent">{{ isAssigned ? 'Hand off to' : 'Assign to' }}</Label>
                   <div class="flex flex-col gap-2 sm:flex-row">
                       <NativeSelect id="assign-agent" v-model="selectedAgent" class="flex-1">
                           <NativeSelectOption value="" disabled>Choose an agent</NativeSelectOption>
                           <NativeSelectOption v-for="agent in candidates" :key="agent.id" :value="String(agent.id)">{{ agent.name }}</NativeSelectOption>
                       </NativeSelect>
                       <Button type="submit" variant="outline" :disabled="busy !== null || selectedAgent === ''">
                           <Spinner v-if="busy === 'assign'" />
                           {{ isAssigned ? 'Hand off' : 'Assign' }}
                       </Button>
                   </div>
                   <InputError :message="error" />
               </form>
               <p v-else-if="can.assign" class="text-sm text-muted-foreground">No other agents are available.</p>
           </CardContent>
       </Card>
   </template>
   ```

4. Replace `resources/js/components/tickets/AttachmentList.vue`. The links are the server's `download_url` and work as plain downloads, not Inertia visits. Internal attachments (staff only) carry a lock.
   ```vue
   <script setup lang="ts">
   import { Download, Lock, Paperclip } from '@lucide/vue';
   import { formatBytes } from '@/lib/format';
   import type { AttachmentItem } from '@/types/tickets';

   defineProps<{ attachments: AttachmentItem[] }>();
   </script>

   <template>
       <ul v-if="attachments.length > 0" class="flex flex-wrap gap-2" aria-label="Attachments">
           <li v-for="attachment in attachments" :key="attachment.id" class="max-w-full">
               <a
                   :href="attachment.download_url"
                   class="group inline-flex max-w-full items-center gap-2 rounded-md border bg-background px-2.5 py-1.5 text-sm hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
               >
                   <Lock v-if="attachment.is_internal" class="size-4 shrink-0 text-amber-600 dark:text-amber-400" aria-label="Internal" />
                   <Paperclip v-else class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                   <span class="truncate">{{ attachment.original_name }}</span>
                   <span class="shrink-0 text-xs text-muted-foreground">{{ formatBytes(attachment.size_bytes) }}</span>
                   <Download class="size-4 shrink-0 text-muted-foreground group-hover:text-foreground" aria-hidden="true" />
                   <span class="sr-only">Download</span>
               </a>
           </li>
       </ul>
   </template>
   ```

5. Create `resources/js/components/tickets/ReplyComposer.vue`. When "Internal note" is on, the box turns amber and the button says "Add internal note", so staff can't mistake a note for a reply. The toggle exists only when `can.addInternalNote` is true, and `is_internal` is forced to `false` otherwise. The form resets after a successful post.
   ```vue
   <script setup lang="ts">
   import { useForm } from '@inertiajs/vue3';
   import { Lock } from '@lucide/vue';
   import { computed } from 'vue';
   import InputError from '@/components/InputError.vue';
   import SubmitButton from '@/components/SubmitButton.vue';
   import AttachmentPicker from '@/components/tickets/AttachmentPicker.vue';
   import { Label } from '@/components/ui/label';
   import { Switch } from '@/components/ui/switch';
   import { Textarea } from '@/components/ui/textarea';
   import { errorsFor } from '@/lib/forms';
   import { store } from '@/routes/tickets/replies';
   import type { AttachmentRules, TicketCan, TicketDetail } from '@/types/tickets';

   const props = defineProps<{ ticket: TicketDetail; can: TicketCan; attachmentRules: AttachmentRules }>();

   const form = useForm<{ body: string; is_internal: boolean; attachments: File[] }>({
       body: '',
       is_internal: false,
       attachments: [],
   });

   const attachmentErrors = computed(() => errorsFor(form.errors, 'attachments'));

   function submit(): void {
       form.transform((data) => ({
           ...data,
           is_internal: props.can.addInternalNote ? data.is_internal : false,
       })).post(store.url(props.ticket.number), {
           forceFormData: true,
           preserveScroll: true,
           onSuccess: () => form.reset(),
       });
   }
   </script>

   <template>
       <form class="flex flex-col gap-4 border-t pt-4" @submit.prevent="submit">
           <div class="grid gap-2">
               <div class="flex flex-wrap items-center justify-between gap-2">
                   <Label for="reply-body">{{ form.is_internal ? 'Internal note' : 'Reply' }}</Label>
                   <div v-if="can.addInternalNote" class="flex items-center gap-2">
                       <Switch id="reply-internal" v-model="form.is_internal" />
                       <Label for="reply-internal" class="flex items-center gap-1 text-sm font-normal">
                           <Lock class="size-3.5" aria-hidden="true" />
                           Internal note (staff only)
                       </Label>
                   </div>
               </div>
               <Textarea
                   id="reply-body"
                   v-model="form.body"
                   rows="5"
                   required
                   maxlength="20000"
                   :placeholder="form.is_internal ? 'Visible to staff only' : 'Write a reply'"
                   :class="form.is_internal ? 'border-amber-400 bg-amber-50/50 dark:border-amber-600 dark:bg-amber-950/30' : ''"
               />
               <InputError :message="form.errors.body" />
           </div>
           <AttachmentPicker id="reply-attachments" v-model="form.attachments" :rules="attachmentRules" :errors="attachmentErrors" />
           <div class="flex justify-end">
               <SubmitButton :processing="form.processing" :disabled="form.body.trim() === ''">
                   {{ form.is_internal ? 'Add internal note' : 'Send reply' }}
               </SubmitButton>
           </div>
       </form>
   </template>
   ```

6. Replace `resources/js/components/tickets/ConversationPanel.vue`. It is the thread plus the composer, without a card (the page's tabs supply the card). The customer's own messages are tinted differently from staff messages. Internal notes get a dashed amber frame and the "Internal note · not visible to the customer" label.
   ```vue
   <script setup lang="ts">
   import { Lock, MessageSquare } from '@lucide/vue';
   import { computed } from 'vue';
   import EmptyState from '@/components/EmptyState.vue';
   import AttachmentList from '@/components/tickets/AttachmentList.vue';
   import ReplyComposer from '@/components/tickets/ReplyComposer.vue';
   import { Avatar, AvatarFallback } from '@/components/ui/avatar';
   import { getInitials } from '@/composables/useInitials';
   import { formatDateTime, formatRelative } from '@/lib/format';
   import type { AttachmentRules, ReplyItem, TicketCan, TicketDetail } from '@/types/tickets';

   const props = defineProps<{ ticket: TicketDetail; replies: ReplyItem[]; can: TicketCan; attachmentRules: AttachmentRules }>();

   const isClosed = computed(() => props.ticket.status.value === 'closed');
   const isCustomer = (reply: ReplyItem): boolean => reply.author.id === props.ticket.customer?.id;
   </script>

   <template>
       <div class="flex flex-col gap-4">
           <EmptyState v-if="replies.length === 0" :icon="MessageSquare" title="No replies yet" :description="can.reply ? 'Start the conversation below.' : undefined" />
           <ol v-else class="flex flex-col gap-3" aria-label="Conversation">
               <li
                   v-for="reply in replies"
                   :key="reply.id"
                   :data-internal="reply.is_internal ? 'true' : undefined"
                   :class="[
                       'flex gap-3 rounded-lg border p-3 sm:p-4',
                       reply.is_internal
                           ? 'border-dashed border-amber-400 bg-amber-50 dark:border-amber-600 dark:bg-amber-950/40'
                           : isCustomer(reply)
                             ? 'bg-muted/40'
                             : 'bg-card',
                   ]"
               >
                   <Avatar class="size-8 shrink-0">
                       <AvatarFallback class="text-xs">{{ getInitials(reply.author.name) }}</AvatarFallback>
                   </Avatar>
                   <div class="min-w-0 flex-1">
                       <div class="mb-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                           <span class="font-medium">{{ reply.author.name }}</span>
                           <span v-if="isCustomer(reply)" class="text-xs text-muted-foreground">Customer</span>
                           <time :datetime="reply.created_at ?? undefined" :title="formatDateTime(reply.created_at)" class="text-xs text-muted-foreground">{{ formatRelative(reply.created_at) }}</time>
                           <span v-if="reply.is_internal" class="inline-flex items-center gap-1 rounded-full bg-amber-200 px-2 py-0.5 text-xs font-medium text-amber-900 dark:bg-amber-800 dark:text-amber-100">
                               <Lock class="size-3" aria-hidden="true" />
                               Internal note · not visible to the customer
                           </span>
                       </div>
                       <p class="text-sm break-words whitespace-pre-line">{{ reply.body }}</p>
                       <AttachmentList v-if="reply.attachments.length > 0" class="mt-3" :attachments="reply.attachments" />
                   </div>
               </li>
           </ol>

           <p v-if="isClosed" class="rounded-md border bg-muted px-3 py-2 text-sm text-muted-foreground">
               This ticket is closed and no longer accepts replies.
           </p>
           <ReplyComposer v-else-if="can.reply" :ticket="ticket" :can="can" :attachment-rules="attachmentRules" />
       </div>
   </template>
   ```
   Confirm that `getInitials` is exported from `@/composables/useInitials` (it is on main) and that `Avatar`/`AvatarFallback` are exported from `@/components/ui/avatar`.

7. Replace `resources/js/components/tickets/ActivityTimeline.vue`. It renders without a card (it lives in a tab) and gives each `ActivityType` an icon. The server has already removed `internal_note` entries for customers (`activitiesVisibleTo`).
   ```vue
   <script setup lang="ts">
   import { AlarmClock, ArrowRightLeft, CirclePlus, FileUp, Flag, Hand, History, Lock, MessageSquare, Pause, Pencil, Play, ShieldAlert, Tag, UserCheck, UserMinus } from '@lucide/vue';
   import type { Component } from 'vue';
   import EmptyState from '@/components/EmptyState.vue';
   import { formatDateTime, formatRelative } from '@/lib/format';
   import type { ActivityItem } from '@/types/tickets';

   defineProps<{ activities: ActivityItem[] }>();

   const icons: Record<string, Component> = {
       created: CirclePlus,
       updated: Pencil,
       assigned: UserCheck,
       claimed: Hand,
       unassigned: UserMinus,
       status_changed: ArrowRightLeft,
       priority_changed: Flag,
       category_changed: Tag,
       replied: MessageSquare,
       internal_note: Lock,
       sla_paused: Pause,
       sla_resumed: Play,
       sla_approaching: AlarmClock,
       escalated: ShieldAlert,
       imported: FileUp,
   };
   </script>

   <template>
       <EmptyState v-if="activities.length === 0" :icon="History" title="No activity yet" />
       <ol v-else class="relative flex flex-col gap-4 border-l pl-6" aria-label="Activity history">
           <li v-for="activity in activities" :key="activity.id" class="relative" :data-activity="activity.type">
               <span class="absolute top-0 -left-[2.1rem] flex size-6 items-center justify-center rounded-full border bg-background">
                   <component :is="icons[activity.type] ?? History" class="size-3.5 text-muted-foreground" aria-hidden="true" />
               </span>
               <p class="text-sm break-words">{{ activity.description }}</p>
               <p class="text-xs text-muted-foreground">
                   {{ activity.causer?.name ?? 'System' }} ·
                   <time :datetime="activity.created_at ?? undefined" :title="formatDateTime(activity.created_at)">{{ formatRelative(activity.created_at) }}</time>
               </p>
           </li>
       </ol>
   </template>
   ```

8. Rewrite `resources/js/components/tickets/TicketEditForm.vue` as an inline editor inside the description card. It keeps T05's props (`ticket, can, categories`), its `useForm` fields and its `transform` (the category is sent only when `can.changePriority`), with these changes:
   - no `Card` wrapper: the root is the `<form class="flex flex-col gap-4">`
   - the category `Select` becomes `<NativeSelect id="edit-category" v-model="form.category_id">` with one `NativeSelectOption` per category
   - the URL is `update.url(props.ticket.number)` (`@/routes/tickets`), with `onSuccess: () => emit('done')`
   - `const emit = defineEmits<{ done: [] }>()`; the footer is `<SubmitButton :processing="form.processing" :disabled="!form.isDirty">Save changes</SubmitButton>` plus `<Button type="button" variant="ghost" @click="emit('done')">Cancel</Button>`
   - the description `Textarea` uses `rows="6"`

9. Rewrite `resources/js/pages/tickets/Show.vue`, with P3's props.
   ```vue
   <script setup lang="ts">
   import { Head, setLayoutProps } from '@inertiajs/vue3';
   import { Pencil } from '@lucide/vue';
   import { ref, watchEffect } from 'vue';
   import PageHeader from '@/components/PageHeader.vue';
   import ActivityTimeline from '@/components/tickets/ActivityTimeline.vue';
   import AssignmentPanel from '@/components/tickets/AssignmentPanel.vue';
   import AttachmentList from '@/components/tickets/AttachmentList.vue';
   import ConversationPanel from '@/components/tickets/ConversationPanel.vue';
   import PriorityBadge from '@/components/tickets/PriorityBadge.vue';
   import SlaBadge from '@/components/tickets/SlaBadge.vue';
   import StatusBadge from '@/components/tickets/StatusBadge.vue';
   import TicketDetailsCard from '@/components/tickets/TicketDetailsCard.vue';
   import TicketEditForm from '@/components/tickets/TicketEditForm.vue';
   import TicketStateControls from '@/components/tickets/TicketStateControls.vue';
   import { Button } from '@/components/ui/button';
   import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
   import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
   import { index, show } from '@/routes/tickets';
   import type { ActivityItem, AgentOption, AttachmentItem, AttachmentRules, ReplyItem, TicketCan, TicketDetail, TicketFormOptions } from '@/types/tickets';

   const props = defineProps<{
       ticket: TicketDetail;
       replies: ReplyItem[];
       attachments: AttachmentItem[];
       activities: ActivityItem[];
       agents: AgentOption[];
       can: TicketCan;
       options: TicketFormOptions;
       attachmentRules: AttachmentRules;
   }>();

   watchEffect(() => {
       setLayoutProps({
           breadcrumbs: [
               { title: 'Tickets', href: index() },
               { title: props.ticket.number, href: show(props.ticket.number) },
           ],
       });
   });

   const editing = ref(false);
   </script>

   <template>
       <Head :title="`${ticket.number} · ${ticket.subject}`" />

       <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-6">
           <PageHeader :title="ticket.subject">
               <template #meta>
                   <div class="flex flex-wrap items-center gap-2 pt-1">
                       <span class="font-mono text-sm text-muted-foreground">{{ ticket.number }}</span>
                       <StatusBadge :status="ticket.status" />
                       <PriorityBadge :priority="ticket.priority" />
                       <SlaBadge :sla="ticket.sla" />
                   </div>
               </template>
           </PageHeader>

           <div class="grid gap-6 lg:grid-cols-3">
               <div class="flex min-w-0 flex-col gap-6 lg:col-span-2">
                   <Card>
                       <CardHeader class="flex flex-row items-center justify-between gap-2">
                           <CardTitle class="text-base">Description</CardTitle>
                           <Button v-if="can.update && !editing" variant="ghost" size="sm" @click="editing = true">
                               <Pencil aria-hidden="true" />
                               Edit
                           </Button>
                       </CardHeader>
                       <CardContent class="flex flex-col gap-4">
                           <TicketEditForm v-if="editing" :ticket="ticket" :can="can" :categories="options.categories" @done="editing = false" />
                           <p v-else class="text-sm break-words whitespace-pre-line">{{ ticket.description }}</p>
                           <AttachmentList :attachments="attachments" />
                       </CardContent>
                   </Card>

                   <Card>
                       <CardContent>
                           <Tabs default-value="conversation">
                               <TabsList>
                                   <TabsTrigger value="conversation">Conversation ({{ replies.length }})</TabsTrigger>
                                   <TabsTrigger value="activity">Activity ({{ activities.length }})</TabsTrigger>
                               </TabsList>
                               <TabsContent value="conversation" class="pt-4">
                                   <ConversationPanel :ticket="ticket" :replies="replies" :can="can" :attachment-rules="attachmentRules" />
                               </TabsContent>
                               <TabsContent value="activity" class="pt-4">
                                   <ActivityTimeline :activities="activities" />
                               </TabsContent>
                           </Tabs>
                       </CardContent>
                   </Card>
               </div>

               <aside class="order-first flex min-w-0 flex-col gap-6 lg:order-last">
                   <TicketStateControls :ticket="ticket" :can="can" :statuses="options.statuses" :priorities="options.priorities" />
                   <AssignmentPanel :ticket="ticket" :agents="agents" :can="can" />
                   <TicketDetailsCard :ticket="ticket" />
               </aside>
           </div>
       </div>
   </template>
   ```

10. `npx vp fmt resources/js/pages/tickets/Show.vue resources/js/components/tickets/{AssignmentPanel,ConversationPanel,AttachmentList,ActivityTimeline,TicketStateControls,TicketEditForm,ReplyComposer,TicketDetailsCard}.vue`, then `npx vp lint` on the same files. Don't format the whole `components/tickets` directory, because P5.3 owns `TicketFilterBar.vue` in the same wave.

**Proposed commit:** `feat(tickets): build ticket detail with conversation, assignment and history`, `Refs: P5.4`, `Implements: TD-026, TD-050, TD-060, TD-061`

---

## P5.5 — Notification bell and inbox

**Files:** create `resources/js/components/notifications/NotificationBell.vue`; modify `resources/js/components/AppSidebarHeader.vue`; create or replace `resources/js/pages/notifications/Index.vue`.

**Consumes:** shared `notifications.unread_count`; `notifications/Index` props `items: Paginated<NotificationItem>`, `unreadOnly: boolean`; Wayfinder `@/routes/notifications` (`index`, `read`, `readAll`); P5.1's `PageHeader`, `EmptyState`, `ResourcePagination`, `formatRelative`.

The bell is part of the app header, on every page and at every width. It's a link to the inbox, with a red count badge (capped at "99+") and an `aria-label` that carries the count. Opening an unread item marks it read and then visits its `url`. Its other actions are "Mark read", "Mark all read" and an All/Unread switch. Every mark-read reloads the page props, so the badge drops at once.

**Steps:**

1. Create `resources/js/components/notifications/NotificationBell.vue`:
   ```vue
   <script setup lang="ts">
   import { Link, usePage } from '@inertiajs/vue3';
   import { Bell } from '@lucide/vue';
   import { computed } from 'vue';
   import { Button } from '@/components/ui/button';
   import { index } from '@/routes/notifications';

   const page = usePage();
   const unread = computed(() => page.props.notifications.unread_count);
   const badge = computed(() => (unread.value > 99 ? '99+' : String(unread.value)));
   const label = computed(() =>
       unread.value === 0 ? 'Notifications' : `Notifications, ${unread.value} unread`,
   );
   </script>

   <template>
       <Button variant="ghost" size="icon" class="relative" as-child>
           <Link :href="index()" :aria-label="label" data-testid="notification-bell">
               <Bell aria-hidden="true" />
               <span v-if="unread > 0" class="absolute -top-0.5 -right-0.5 min-w-4 rounded-full bg-red-600 px-1 text-center text-[10px] leading-4 font-semibold text-white" aria-hidden="true">{{ badge }}</span>
           </Link>
       </Button>
   </template>
   ```

2. Modify `resources/js/components/AppSidebarHeader.vue`. Add `justify-between` to the `<header>` class list, import `NotificationBell`, and add this right after the existing breadcrumb `<div>` (inside `<header>`):
   ```vue
   <div class="flex items-center gap-1">
       <NotificationBell />
   </div>
   ```
   Also add `min-w-0` to the breadcrumb wrapper `div`, so long ticket numbers truncate instead of pushing the bell off-screen at 375 px.

3. Create or replace `resources/js/pages/notifications/Index.vue`:
   ```vue
   <script setup lang="ts">
   import { Head, Link, router } from '@inertiajs/vue3';
   import { BellOff, CheckCheck } from '@lucide/vue';
   import { computed, ref } from 'vue';
   import EmptyState from '@/components/EmptyState.vue';
   import PageHeader from '@/components/PageHeader.vue';
   import ResourcePagination from '@/components/ResourcePagination.vue';
   import { Button } from '@/components/ui/button';
   import { Card, CardContent } from '@/components/ui/card';
   import { Spinner } from '@/components/ui/spinner';
   import { formatDateTime, formatRelative } from '@/lib/format';
   import { index, read, readAll } from '@/routes/notifications';
   import type { NotificationItem } from '@/types/notifications';
   import type { Paginated } from '@/types/pagination';

   defineOptions({
       layout: { breadcrumbs: [{ title: 'Notifications', href: index() }] },
   });

   const props = defineProps<{ items: Paginated<NotificationItem>; unreadOnly: boolean }>();

   const busy = ref<string | null>(null);
   const hasUnread = computed(() => props.items.data.some((item) => item.read_at === null));

   const tabs = computed(() => [
       { label: 'All', href: index(), active: !props.unreadOnly },
       { label: 'Unread', href: index({ query: { unread: 1 } }), active: props.unreadOnly },
   ]);

   function markRead(item: NotificationItem, then?: () => void): void {
       router.post(read.url(item.id), {}, {
           preserveScroll: true,
           onStart: () => { busy.value = item.id; },
           onFinish: () => { busy.value = null; },
           onSuccess: () => then?.(),
       });
   }

   function open(item: NotificationItem): void {
       const go = (): void => {
           if (item.url !== null) {
               router.visit(item.url);
           }
       };

       if (item.read_at === null) {
           markRead(item, go);
       } else {
           go();
       }
   }

   function markAll(): void {
       router.post(readAll.url(), {}, {
           preserveScroll: true,
           onStart: () => { busy.value = 'all'; },
           onFinish: () => { busy.value = null; },
       });
   }
   </script>

   <template>
       <Head title="Notifications" />

       <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
           <PageHeader title="Notifications" description="Updates about tickets you are involved in.">
               <template #actions>
                   <Button variant="outline" size="sm" :disabled="!hasUnread || busy !== null" @click="markAll">
                       <Spinner v-if="busy === 'all'" />
                       <CheckCheck v-else aria-hidden="true" />
                       Mark all read
                   </Button>
               </template>
           </PageHeader>

           <nav aria-label="Filter notifications" class="inline-flex w-fit rounded-md border bg-muted p-0.5">
               <Link
                   v-for="tab in tabs"
                   :key="tab.label"
                   :href="tab.href"
                   preserve-state
                   :aria-current="tab.active ? 'page' : undefined"
                   :class="['rounded px-3 py-1.5 text-sm font-medium', tab.active ? 'bg-background shadow-xs' : 'text-muted-foreground hover:text-foreground']"
                   >{{ tab.label }}</Link
               >
           </nav>

           <EmptyState
               v-if="items.data.length === 0"
               :icon="BellOff"
               :title="unreadOnly ? 'You are all caught up' : 'No notifications yet'"
               :description="unreadOnly ? 'There are no unread notifications.' : 'You will be notified about assignments, replies and SLA warnings.'"
           />

           <Card v-else class="py-0">
               <CardContent class="px-0">
                   <ul class="divide-y">
                       <li v-for="item in items.data" :key="item.id" class="flex items-start gap-3 px-4 py-3">
                           <span :class="['mt-2 size-2 shrink-0 rounded-full', item.read_at === null ? 'bg-primary' : 'bg-transparent']" aria-hidden="true" />
                           <button type="button" class="min-w-0 flex-1 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded-sm" @click="open(item)">
                               <span :class="['block text-sm break-words', item.read_at === null ? 'font-semibold' : '']">{{ item.title }}</span>
                               <span class="block text-sm break-words text-muted-foreground">{{ item.body }}</span>
                               <time :datetime="item.created_at ?? undefined" :title="formatDateTime(item.created_at)" class="block pt-0.5 text-xs text-muted-foreground">{{ formatRelative(item.created_at) }}</time>
                           </button>
                           <Button v-if="item.read_at === null" variant="ghost" size="sm" class="shrink-0" :disabled="busy !== null" @click="markRead(item)">
                               <Spinner v-if="busy === item.id" />
                               Mark read
                           </Button>
                       </li>
                   </ul>
               </CardContent>
           </Card>

           <ResourcePagination :links="items.links" :meta="items.meta" noun="notifications" />
       </div>
   </template>
   ```

4. `npx vp fmt resources/js/components/notifications resources/js/components/AppSidebarHeader.vue resources/js/pages/notifications`, then `npx vp lint` on the same paths.

**Proposed commit:** `feat(notifications): add header bell and notification inbox`, `Refs: P5.5`, `Implements: TD-071`

---

## P5.6 — CSV import pages

**Files:** create `resources/js/components/imports/ImportStatusBadge.vue`; create or replace `resources/js/pages/admin/imports/Index.vue`, `resources/js/pages/admin/imports/Create.vue`, `resources/js/pages/admin/imports/Show.vue`.

**Consumes:** the three `admin/imports/*` page props; Wayfinder `@/routes/admin/imports` (`index`, `create`, `store`, `show`) and `@/routes/admin/imports/errors` (`download`); P5.1's `PageHeader`, `EmptyState`, `ResourcePagination`, `SubmitButton`, `Progress`, `@/lib/format`.

`Show` polls every 2 s with `usePoll`, reloading only `ticketImport` and `importErrors`, and stops once `is_finished` is true. With `QUEUE_CONNECTION=sync` the import has usually finished before the redirect, in which case the poll never starts. The error CSV downloads through a plain `<a href>`, because it is a file response.

**Steps:**

1. Create `resources/js/components/imports/ImportStatusBadge.vue` with the same template as P5.1's `StatusBadge`, using `defineProps<{ status: EnumValue<ImportStatusValue> }>()`, rendering `{{ status.label }}` with `:class="tones[status.value]"` and `:data-import-status="status.value"`, and these tones:
   ```ts
   const tones: Record<ImportStatusValue, string> = {
       pending: 'border-border bg-muted text-muted-foreground',
       processing: 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-800 dark:bg-sky-950 dark:text-sky-200',
       completed: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
       completed_with_errors: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200',
       failed: 'border-transparent bg-red-600 text-white dark:bg-red-700',
   };
   ```

2. Create or replace `resources/js/pages/admin/imports/Index.vue`:
   ```vue
   <script setup lang="ts">
   import { Head, Link } from '@inertiajs/vue3';
   import { FileUp, Plus } from '@lucide/vue';
   import EmptyState from '@/components/EmptyState.vue';
   import ImportStatusBadge from '@/components/imports/ImportStatusBadge.vue';
   import PageHeader from '@/components/PageHeader.vue';
   import ResourcePagination from '@/components/ResourcePagination.vue';
   import { Button } from '@/components/ui/button';
   import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
   import { formatDateTime, formatRelative } from '@/lib/format';
   import { create, index, show } from '@/routes/admin/imports';
   import type { TicketImportItem } from '@/types/imports';
   import type { Paginated } from '@/types/pagination';

   defineOptions({
       layout: { breadcrumbs: [{ title: 'Imports', href: index() }] },
   });

   defineProps<{ imports: Paginated<TicketImportItem> }>();
   </script>

   <template>
       <Head title="Ticket imports" />

       <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-6">
           <PageHeader title="Ticket imports" description="Bulk-create tickets from a CSV file. Every upload is listed here, newest first.">
               <template #actions>
                   <Button as-child><Link :href="create()"><Plus aria-hidden="true" />New import</Link></Button>
               </template>
           </PageHeader>

           <EmptyState v-if="imports.data.length === 0" :icon="FileUp" title="No imports yet" description="Upload a CSV file to create many tickets at once.">
               <Button as-child><Link :href="create()">Upload a CSV</Link></Button>
           </EmptyState>

           <template v-else>
               <div class="overflow-x-auto rounded-md border">
                   <Table>
                       <TableHeader>
                           <TableRow>
                               <TableHead>File</TableHead>
                               <TableHead>Status</TableHead>
                               <TableHead class="hidden text-right md:table-cell">Rows</TableHead>
                               <TableHead class="text-right">Imported</TableHead>
                               <TableHead class="text-right">Rejected</TableHead>
                               <TableHead class="hidden lg:table-cell">Uploaded by</TableHead>
                               <TableHead class="hidden md:table-cell">Uploaded</TableHead>
                           </TableRow>
                       </TableHeader>
                       <TableBody>
                           <TableRow v-for="item in imports.data" :key="item.id">
                               <TableCell class="max-w-[12rem] sm:max-w-xs">
                                   <Link :href="show(item.id)" class="block truncate font-medium underline-offset-4 hover:underline">{{ item.original_name }}</Link>
                               </TableCell>
                               <TableCell><ImportStatusBadge :status="item.status" /></TableCell>
                               <TableCell class="hidden text-right tabular-nums md:table-cell">{{ item.total_rows }}</TableCell>
                               <TableCell class="text-right tabular-nums">{{ item.succeeded_rows }}</TableCell>
                               <TableCell class="text-right tabular-nums" :class="item.failed_rows > 0 ? 'text-destructive' : ''">{{ item.failed_rows }}</TableCell>
                               <TableCell class="hidden lg:table-cell">{{ item.user?.name ?? '—' }}</TableCell>
                               <TableCell class="hidden md:table-cell" :title="formatDateTime(item.created_at)">{{ formatRelative(item.created_at) }}</TableCell>
                           </TableRow>
                       </TableBody>
                   </Table>
               </div>
               <ResourcePagination :links="imports.links" :meta="imports.meta" noun="imports" />
           </template>
       </div>
   </template>
   ```

3. Create or replace `resources/js/pages/admin/imports/Create.vue`. The drop area is a large label for the hidden file input, which works for clicks, keyboards and touch. Drag-and-drop sets the same field. The upload shows `form.progress`.
   ```vue
   <script setup lang="ts">
   import { Head, Link, useForm } from '@inertiajs/vue3';
   import { FileSpreadsheet, UploadCloud } from '@lucide/vue';
   import { computed, ref } from 'vue';
   import InputError from '@/components/InputError.vue';
   import PageHeader from '@/components/PageHeader.vue';
   import SubmitButton from '@/components/SubmitButton.vue';
   import { Button } from '@/components/ui/button';
   import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
   import { Progress } from '@/components/ui/progress';
   import { formatBytes } from '@/lib/format';
   import { create, index, store } from '@/routes/admin/imports';
   import type { ImportColumns } from '@/types/imports';

   defineOptions({
       layout: {
           breadcrumbs: [
               { title: 'Imports', href: index() },
               { title: 'New import', href: create() },
           ],
       },
   });

   const props = defineProps<{ maxUploadKb: number; columns: ImportColumns }>();

   const form = useForm<{ file: File | null }>({ file: null });
   const dragging = ref(false);

   const maxSize = computed(() => formatBytes(props.maxUploadKb * 1024));
   const headerExample = computed(() => [...props.columns.required, ...props.columns.optional].join(','));

   function pick(event: Event): void {
       form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
   }

   function drop(event: DragEvent): void {
       dragging.value = false;
       form.file = event.dataTransfer?.files[0] ?? null;
   }

   function submit(): void {
       form.post(store.url(), { forceFormData: true });
   }
   </script>

   <template>
       <Head title="New ticket import" />

       <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
           <PageHeader title="Import tickets from CSV" :description="`One ticket per row. Files up to ${maxSize}.`" />

           <Card>
               <CardContent>
                   <form class="flex flex-col gap-4" @submit.prevent="submit">
                       <label
                           for="file"
                           :class="[
                               'flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed px-4 py-10 text-center transition-colors focus-within:ring-2 focus-within:ring-ring',
                               dragging ? 'border-primary bg-primary/5' : 'border-input hover:bg-muted/50',
                           ]"
                           @dragover.prevent="dragging = true"
                           @dragleave.prevent="dragging = false"
                           @drop.prevent="drop"
                       >
                           <UploadCloud class="size-8 text-muted-foreground" aria-hidden="true" />
                           <span v-if="form.file" class="flex items-center gap-2 text-sm font-medium">
                               <FileSpreadsheet class="size-4" aria-hidden="true" />
                               {{ form.file.name }} · {{ formatBytes(form.file.size) }}
                           </span>
                           <span v-else class="text-sm"><span class="font-medium">Choose a CSV file</span> or drag it here</span>
                           <input id="file" type="file" accept=".csv,text/csv,text/plain" class="sr-only" @change="pick" />
                       </label>
                       <InputError :message="form.errors.file" />
                       <Progress v-if="form.progress" :model-value="form.progress.percentage ?? 0" aria-label="Upload progress" />
                       <div class="flex flex-wrap items-center gap-2">
                           <SubmitButton :processing="form.processing" :disabled="form.file === null">Start import</SubmitButton>
                           <Button variant="ghost" as-child><Link :href="index()">Cancel</Link></Button>
                       </div>
                   </form>
               </CardContent>
           </Card>

           <Card>
               <CardHeader><CardTitle class="text-base">File format</CardTitle></CardHeader>
               <CardContent class="flex flex-col gap-3 text-sm">
                   <p>The first row is a header. Columns may appear in any order.</p>
                   <p class="flex flex-wrap items-center gap-1">
                       <span class="font-medium">Required:</span>
                       <code v-for="column in columns.required" :key="column" class="rounded bg-muted px-1.5 py-0.5 text-xs">{{ column }}</code>
                   </p>
                   <p class="flex flex-wrap items-center gap-1">
                       <span class="font-medium">Optional:</span>
                       <code v-for="column in columns.optional" :key="column" class="rounded bg-muted px-1.5 py-0.5 text-xs">{{ column }}</code>
                   </p>
                   <ul class="list-disc space-y-1 pl-5 text-muted-foreground">
                       <li><code>priority</code>: low, medium, high or critical.</li>
                       <li><code>category</code>: the slug of an active category.</li>
                       <li><code>customer_email</code>: an existing customer account.</li>
                       <li><code>status</code>: open (default), in_progress, waiting_for_customer, resolved or closed.</li>
                       <li><code>created_at</code>: a past date such as <code>2026-10-29 17:00</code>, read in business time. The SLA is measured from it.</li>
                       <li><code>external_reference</code>: a row whose reference was already imported is reported as a duplicate.</li>
                   </ul>
                   <pre class="overflow-x-auto rounded bg-muted p-2 text-xs">{{ headerExample }}</pre>
               </CardContent>
           </Card>
       </div>
   </template>
   ```

4. Create or replace `resources/js/pages/admin/imports/Show.vue`:
   ```vue
   <script setup lang="ts">
   import { Head, setLayoutProps, usePoll } from '@inertiajs/vue3';
   import { CheckCircle2, Download } from '@lucide/vue';
   import { computed, watch, watchEffect } from 'vue';
   import EmptyState from '@/components/EmptyState.vue';
   import ImportStatusBadge from '@/components/imports/ImportStatusBadge.vue';
   import PageHeader from '@/components/PageHeader.vue';
   import ResourcePagination from '@/components/ResourcePagination.vue';
   import { Button } from '@/components/ui/button';
   import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
   import { Progress } from '@/components/ui/progress';
   import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
   import { formatDateTime } from '@/lib/format';
   import { index, show } from '@/routes/admin/imports';
   import { download } from '@/routes/admin/imports/errors';
   import type { TicketImportErrorItem, TicketImportItem } from '@/types/imports';
   import type { Paginated } from '@/types/pagination';

   const props = defineProps<{ ticketImport: TicketImportItem; importErrors: Paginated<TicketImportErrorItem> }>();

   watchEffect(() => {
       setLayoutProps({
           breadcrumbs: [
               { title: 'Imports', href: index() },
               { title: props.ticketImport.original_name, href: show(props.ticketImport.id) },
           ],
       });
   });

   const { stop } = usePoll(2000, { only: ['ticketImport', 'importErrors'] }, { autoStart: !props.ticketImport.is_finished });

   watch(
       () => props.ticketImport.is_finished,
       (finished) => {
           if (finished) {
               stop();
           }
       },
   );

   const counters = computed(() => [
       { label: 'Rows in file', value: props.ticketImport.total_rows },
       { label: 'Processed', value: props.ticketImport.processed_rows },
       { label: 'Imported', value: props.ticketImport.succeeded_rows },
       { label: 'Rejected', value: props.ticketImport.failed_rows },
   ]);

   const canDownload = computed(() => props.ticketImport.failed_rows > 0 || props.ticketImport.status.value === 'failed');

   const messages = (error: TicketImportErrorItem): string[] => Object.values(error.errors).flat();
   const subjectOf = (error: TicketImportErrorItem): string => error.raw.subject ?? '—';
   </script>

   <template>
       <Head :title="`Import: ${ticketImport.original_name}`" />

       <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-6">
           <PageHeader :title="ticketImport.original_name">
               <template #meta>
                   <div class="flex flex-wrap items-center gap-2 pt-1 text-sm text-muted-foreground">
                       <ImportStatusBadge :status="ticketImport.status" />
                       <span v-if="ticketImport.user">by {{ ticketImport.user.name }}</span>
                       <span>· {{ formatDateTime(ticketImport.created_at) }}</span>
                   </div>
               </template>
               <template #actions>
                   <Button v-if="canDownload" variant="outline" as-child>
                       <a :href="download.url(ticketImport.id)"><Download aria-hidden="true" />Download errors (CSV)</a>
                   </Button>
               </template>
           </PageHeader>

           <Card>
               <CardHeader>
                   <CardTitle class="text-base">Progress</CardTitle>
                   <CardDescription>{{ ticketImport.is_finished ? `Finished ${formatDateTime(ticketImport.finished_at)}` : 'Updating every few seconds…' }}</CardDescription>
               </CardHeader>
               <CardContent class="flex flex-col gap-4">
                   <Progress :model-value="ticketImport.progress_percent" :aria-label="`${ticketImport.progress_percent}% processed`" />
                   <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                       <div v-for="counter in counters" :key="counter.label" class="rounded-lg border p-3">
                           <dt class="text-xs text-muted-foreground">{{ counter.label }}</dt>
                           <dd class="text-2xl font-semibold tabular-nums">{{ counter.value }}</dd>
                       </div>
                   </dl>
               </CardContent>
           </Card>

           <Card>
               <CardHeader>
                   <CardTitle class="text-base">Rejected rows</CardTitle>
                   <CardDescription>Row numbers match the spreadsheet (the header is row 1). Fix these rows and upload them again.</CardDescription>
               </CardHeader>
               <CardContent class="flex flex-col gap-4">
                   <EmptyState v-if="importErrors.data.length === 0" :icon="CheckCircle2" title="No rejected rows" />
                   <template v-else>
                       <div class="overflow-x-auto rounded-md border">
                           <Table>
                               <TableHeader>
                                   <TableRow>
                                       <TableHead class="w-16 text-right">Row</TableHead>
                                       <TableHead class="hidden md:table-cell">Subject</TableHead>
                                       <TableHead>Problems</TableHead>
                                   </TableRow>
                               </TableHeader>
                               <TableBody>
                                   <TableRow v-for="error in importErrors.data" :key="error.id" class="align-top">
                                       <TableCell class="text-right tabular-nums">{{ error.row_number }}</TableCell>
                                       <TableCell class="hidden max-w-xs truncate md:table-cell">{{ subjectOf(error) }}</TableCell>
                                       <TableCell class="whitespace-normal">
                                           <ul class="list-disc pl-4">
                                               <li v-for="message in messages(error)" :key="message">{{ message }}</li>
                                           </ul>
                                       </TableCell>
                                   </TableRow>
                               </TableBody>
                           </Table>
                       </div>
                       <ResourcePagination :links="importErrors.links" :meta="importErrors.meta" :only="['importErrors']" noun="rejected rows" />
                   </template>
               </CardContent>
           </Card>
       </div>
   </template>
   ```

5. `npx vp fmt resources/js/components/imports resources/js/pages/admin/imports`, then `npx vp lint` on the same paths.

**Proposed commit:** `feat(import): add csv import pages with live progress and error table`, `Refs: P5.6`, `Implements: TD-090`

---

## P5.7 — Category and holiday admin pages

**Files:** create `resources/js/components/admin/CategoryForm.vue`, `resources/js/components/admin/HolidayForm.vue`; create or replace `resources/js/pages/admin/categories/{Index,Create,Edit}.vue`, `resources/js/pages/admin/holidays/{Index,Create,Edit}.vue`.

**Consumes:** the `admin/categories/*` and `admin/holidays/*` page props; Wayfinder `@/routes/admin/categories` and `@/routes/admin/holidays` (`index`, `create`, `store`, `edit`, `update`, `destroy`); P5.1's `PageHeader`, `EmptyState`, `ResourcePagination`, `SubmitButton`, `ConfirmDialog`, `Switch`. P2's users pages and `components/admin/UserForm.vue` are not touched.

Deleting a category that still has tickets deactivates it instead (P4's `DeleteCategory` returns false). The button and the confirmation say so in advance, and the server's flash confirms the outcome. The holiday list is per year, with previous/next year links (`?year=`).

**Steps:**

1. Create `resources/js/components/admin/CategoryForm.vue`. While the slug hasn't been edited by hand, it follows the name:
   ```vue
   <script setup lang="ts">
   import { Link, useForm } from '@inertiajs/vue3';
   import { ref, watch } from 'vue';
   import InputError from '@/components/InputError.vue';
   import SubmitButton from '@/components/SubmitButton.vue';
   import { Button } from '@/components/ui/button';
   import { Input } from '@/components/ui/input';
   import { Label } from '@/components/ui/label';
   import { Switch } from '@/components/ui/switch';
   import { index, store, update } from '@/routes/admin/categories';
   import type { AdminCategory } from '@/types/admin';

   const props = defineProps<{ category?: AdminCategory }>();

   const form = useForm({
       name: props.category?.name ?? '',
       slug: props.category?.slug ?? '',
       is_active: props.category?.is_active ?? true,
   });

   const slugTouched = ref(props.category !== undefined);

   const slugify = (value: string): string =>
       value.toLowerCase().normalize('NFKD').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');

   watch(
       () => form.name,
       (name) => {
           if (!slugTouched.value) {
               form.slug = slugify(name);
           }
       },
   );

   function submit(): void {
       if (props.category) {
           form.patch(update.url(props.category.id));
       } else {
           form.post(store.url());
       }
   }
   </script>

   <template>
       <form class="flex flex-col gap-5" @submit.prevent="submit">
           <div class="grid gap-2">
               <Label for="name">Name</Label>
               <Input id="name" v-model="form.name" required maxlength="100" />
               <InputError :message="form.errors.name" />
           </div>
           <div class="grid gap-2">
               <Label for="slug">Slug</Label>
               <Input id="slug" v-model="form.slug" required maxlength="100" class="font-mono" @input="slugTouched = true" />
               <p class="text-xs text-muted-foreground">Lowercase letters, numbers and dashes. CSV imports refer to categories by slug.</p>
               <InputError :message="form.errors.slug" />
           </div>
           <div class="flex items-start gap-3">
               <Switch id="is_active" v-model="form.is_active" />
               <div class="grid gap-1">
                   <Label for="is_active">Active</Label>
                   <p class="text-xs text-muted-foreground">Only active categories are offered when creating tickets.</p>
               </div>
           </div>
           <InputError :message="form.errors.is_active" />
           <div class="flex flex-wrap gap-2">
               <SubmitButton :processing="form.processing">{{ category ? 'Save changes' : 'Create category' }}</SubmitButton>
               <Button variant="ghost" as-child><Link :href="index()">Cancel</Link></Button>
           </div>
       </form>
   </template>
   ```

2. Create `resources/js/components/admin/HolidayForm.vue`. It follows `CategoryForm` exactly: the props are `defineProps<{ holiday?: HolidayItem }>()`, `useForm({ date: props.holiday?.date ?? '', name: props.holiday?.name ?? '' })`, and `submit()` patches `update.url(props.holiday.id)` or posts `store.url()` (`@/routes/admin/holidays`). The fields sit side by side from `sm` up (`grid gap-5 sm:grid-cols-[12rem_minmax(0,1fr)]`): `date` (`Input type="date" required`) and `name` (`Input required maxlength="100"`), each with its `InputError`. Below them goes the hint `<p class="text-xs text-muted-foreground">New SLA deadlines skip this day. Deadlines already calculated for existing tickets don't change.</p>`, then the `SubmitButton` ("Save changes" / "Add holiday") and a ghost "Cancel" link to `index()`.

3. Create or replace `resources/js/pages/admin/categories/Index.vue`:
   ```vue
   <script setup lang="ts">
   import { Head, Link, router } from '@inertiajs/vue3';
   import { Pencil, Plus, Tags } from '@lucide/vue';
   import ConfirmDialog from '@/components/ConfirmDialog.vue';
   import EmptyState from '@/components/EmptyState.vue';
   import PageHeader from '@/components/PageHeader.vue';
   import ResourcePagination from '@/components/ResourcePagination.vue';
   import { Badge } from '@/components/ui/badge';
   import { Button } from '@/components/ui/button';
   import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
   import { create, destroy, edit, index } from '@/routes/admin/categories';
   import type { AdminCategory } from '@/types/admin';
   import type { Paginated } from '@/types/pagination';

   defineOptions({
       layout: { breadcrumbs: [{ title: 'Categories', href: index() }] },
   });

   defineProps<{ categories: Paginated<AdminCategory> }>();

   const inUse = (category: AdminCategory): boolean => category.tickets_count > 0;

   function remove(category: AdminCategory): void {
       router.delete(destroy.url(category.id), { preserveScroll: true });
   }
   </script>

   <template>
       <Head title="Categories" />

       <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-6">
           <PageHeader title="Categories" description="Ticket categories, used for routing tickets to agents and for CSV imports.">
               <template #actions>
                   <Button as-child><Link :href="create()"><Plus aria-hidden="true" />New category</Link></Button>
               </template>
           </PageHeader>

           <EmptyState v-if="categories.data.length === 0" :icon="Tags" title="No categories yet" description="Customers need at least one active category to open a ticket.">
               <Button as-child><Link :href="create()">Create a category</Link></Button>
           </EmptyState>

           <template v-else>
               <div class="overflow-x-auto rounded-md border">
                   <Table>
                       <TableHeader>
                           <TableRow>
                               <TableHead>Name</TableHead>
                               <TableHead class="hidden md:table-cell">Slug</TableHead>
                               <TableHead>Status</TableHead>
                               <TableHead class="hidden text-right sm:table-cell">Tickets</TableHead>
                               <TableHead class="text-right"><span class="sr-only">Actions</span></TableHead>
                           </TableRow>
                       </TableHeader>
                       <TableBody>
                           <TableRow v-for="category in categories.data" :key="category.id">
                               <TableCell class="font-medium">{{ category.name }}</TableCell>
                               <TableCell class="hidden font-mono text-xs md:table-cell">{{ category.slug }}</TableCell>
                               <TableCell>
                                   <Badge variant="outline" :class="category.is_active ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' : 'bg-muted text-muted-foreground'">{{ category.is_active ? 'Active' : 'Inactive' }}</Badge>
                               </TableCell>
                               <TableCell class="hidden text-right tabular-nums sm:table-cell">{{ category.tickets_count }}</TableCell>
                               <TableCell>
                                   <div class="flex justify-end gap-2">
                                       <Button variant="outline" size="sm" as-child>
                                           <Link :href="edit(category.id)"><Pencil aria-hidden="true" /><span class="sr-only sm:not-sr-only">Edit</span></Link>
                                       </Button>
                                       <ConfirmDialog
                                           v-if="category.is_active || !inUse(category)"
                                           :title="inUse(category) ? `Deactivate “${category.name}”?` : `Delete “${category.name}”?`"
                                           :description="inUse(category) ? 'It has tickets, so it is deactivated instead of deleted. Existing tickets keep it; new tickets can no longer choose it.' : 'This category has no tickets and will be removed permanently.'"
                                           :confirm-label="inUse(category) ? 'Deactivate' : 'Delete'"
                                           @confirm="remove(category)"
                                       >
                                           <Button variant="outline" size="sm" class="text-destructive">{{ inUse(category) ? 'Deactivate' : 'Delete' }}</Button>
                                       </ConfirmDialog>
                                   </div>
                               </TableCell>
                           </TableRow>
                       </TableBody>
                   </Table>
               </div>
               <ResourcePagination :links="categories.links" :meta="categories.meta" noun="categories" />
           </template>
       </div>
   </template>
   ```

4. Create or replace `resources/js/pages/admin/categories/Create.vue` and `Edit.vue`. These follow the single-form page pattern: `max-w-2xl` shell, `PageHeader` and a `Card` holding the form.
   ```vue
   <script setup lang="ts">
   import { Head } from '@inertiajs/vue3';
   import CategoryForm from '@/components/admin/CategoryForm.vue';
   import PageHeader from '@/components/PageHeader.vue';
   import { Card, CardContent } from '@/components/ui/card';
   import { create, index } from '@/routes/admin/categories';

   defineOptions({
       layout: {
           breadcrumbs: [
               { title: 'Categories', href: index() },
               { title: 'New category', href: create() },
           ],
       },
   });
   </script>

   <template>
       <Head title="New category" />
       <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4 md:p-6">
           <PageHeader title="New category" />
           <Card><CardContent><CategoryForm /></CardContent></Card>
       </div>
   </template>
   ```
   `Edit.vue` takes `defineProps<{ category: AdminCategory }>()`, sets breadcrumbs `Categories → {category.name}` (href `edit(props.category.id)`) with `setLayoutProps` inside `watchEffect`, uses `<Head :title="`Edit ${category.name}`" />` and `<PageHeader :title="`Edit ${category.name}`" />`, and renders `<CategoryForm :category="category" />` in the same shell.

5. Create or replace `resources/js/pages/admin/holidays/Index.vue`. It is built like the categories index, with these differences:
   - props `defineProps<{ holidays: Paginated<HolidayItem>; year: number }>()`, breadcrumbs `[{ title: 'Holidays', href: index() }]` (`@/routes/admin/holidays`), `<Head title="Holidays" />`
   - `<PageHeader title="Holidays" description="SLA deadlines skip these dates, just like weekends and out-of-hours time.">`, with an "Add holiday" action (`Plus` icon, `create()`)
   - a year switcher between the header and the table:
     ```vue
     <nav aria-label="Year" class="flex items-center gap-2">
         <Button variant="outline" size="icon" as-child>
             <Link :href="index({ query: { year: year - 1 } })" :aria-label="`Show ${year - 1}`"><ChevronLeft aria-hidden="true" /></Link>
         </Button>
         <span class="min-w-16 text-center text-lg font-semibold tabular-nums">{{ year }}</span>
         <Button variant="outline" size="icon" as-child>
             <Link :href="index({ query: { year: year + 1 } })" :aria-label="`Show ${year + 1}`"><ChevronRight aria-hidden="true" /></Link>
         </Button>
     </nav>
     ```
   - empty state: `CalendarDays` icon, `` `No holidays in ${year}` ``, "Business days are counted normally for this year.", with an outline "Add holiday" link
   - columns: Date (`font-mono text-xs whitespace-nowrap`, the raw `holiday.date`), Day (`weekday`, `hidden sm:table-cell`), Name (`break-words`), Actions
   - actions: Edit (`edit(holiday.id)`), plus Delete behind `ConfirmDialog` with title `` `Delete “${holiday.name}”?` ``, description `` `${holiday.weekday} ${holiday.date} becomes a normal business day for new SLA deadlines.` ``, label "Delete", confirm `router.delete(destroy.url(holiday.id), { preserveScroll: true })`
   - `ResourcePagination` with `noun="holidays"`

6. Create or replace `resources/js/pages/admin/holidays/Create.vue` and `Edit.vue`, using the same pattern as step 4: breadcrumbs `Holidays → New holiday` / `Holidays → {holiday.name}`, titles "Add holiday" / `Edit ${holiday.name}`, and `<HolidayForm />` / `<HolidayForm :holiday="holiday" />`. `Edit.vue` takes `defineProps<{ holiday: HolidayItem }>()` and sets its breadcrumbs with `setLayoutProps` in `watchEffect`.

7. `npx vp fmt resources/js/components/admin/CategoryForm.vue resources/js/components/admin/HolidayForm.vue resources/js/pages/admin/categories resources/js/pages/admin/holidays`, then `npx vp lint` on the same paths.

**Proposed commit:** `feat(admin): add category and holiday management pages`, `Refs: P5.7`, `Implements: TD-041, TD-130`

---

## P5.8 — Consistency pass and browser verification

**Files:** may modify any file created or modified by P5.1–P5.7. It may also modify P2's frontend files: `resources/js/pages/auth/{Login,Register,ForgotPassword,ResetPassword}.vue`, `resources/js/pages/admin/users/{Index,Create,Edit}.vue`, `resources/js/pages/admin/roles/Index.vue`, `resources/js/components/admin/UserForm.vue` and `resources/js/layouts/auth/*.vue`. And it may modify the kit shell files `resources/js/components/{AppSidebar,AppSidebarHeader,NavUser,UserMenuContent,Breadcrumbs,AppLogo,AppLogoIcon}.vue`. It may **not** modify any PHP file, anything under `resources/js/components/ui/**`, or `resources/js/types/**`. A prop or type mismatch with the server is a report item, not a fix.

**Consumes:** everything above; the seeded demo accounts (`admin@example.com`, `agent1@example.com`…`agent4@example.com`, `customer1@example.com`…`customer5@example.com`, password `password`); the Playwright MCP.

This subtask runs alone, so it is the one subtask that builds. The browser walk needs compiled assets, and type errors from wave 2 have to be fixed before the walk means anything.

**Steps:**

1. Build and statically check, then fix every error in the files this subtask may touch:
   ```bash
   npm run build && npm run check && npx vue-tsc --noEmit
   ```
   Expected: all three exit 0. If an error is in a file outside the Files list (PHP, `components/ui`, `types`), stop and report it.

2. Run the convention audit. Check every page under `resources/js/pages/**` (P2's, T05's and P5's) against the **UI conventions** in Global constraints, and fix the deviations. These commands find the common ones; each hit is either fixed or justified in the report:
   ```bash
   grep -rn "AppLayout" resources/js/pages
   grep -rn "window\.\(confirm\|alert\)\|v-html" resources/js
   grep -rnE "href=\"/|href=\`/|\('/(tickets|admin|notifications|dashboard)" resources/js/pages resources/js/components --include=*.vue
   grep -rn "toLocale\(Date\|Time\)\?String\|new Date(" resources/js/pages resources/js/components --include=*.vue
   grep -rn "<select\|<textarea\|type=\"checkbox\"" resources/js/pages resources/js/components --include=*.vue | grep -v "components/ui"
   grep -rnL "<Head" resources/js/pages
   grep -rn "flash\." resources/js/pages resources/js/components --include=*.vue
   grep -rnE "//|/\*|<!--" resources/js/pages resources/js/components resources/js/lib resources/js/config resources/js/layouts --include=*.vue --include=*.ts | grep -v "components/ui" | grep -v "https\?://"
   ```
   P2's auth pages keep `AuthLayout` (applied by `app.ts` to `auth/*`). Check them for the same field pattern (`grid gap-2`, `InputError`, `SubmitButton` or the kit's spinner button), sentence-case copy and a visible link between login, register and forgot-password. P2's users pages must use `PageHeader`, `ResourcePagination`, `EmptyState`, `NativeSelect` and `ConfirmDialog` like the P5.7 pages. If P2's auth pages show a status message (for example "We emailed a reset link") inline, keep it inline: `AuthLayout` has no `Toaster`, and this pass doesn't add one.

3. Start the app for the browser walk:
   ```bash
   php artisan serve --port=8010
   ```
   Run it in the background and note its PID. Log in through `http://127.0.0.1:8010/login`. If the demo accounts don't log in, stop and report (the session reseeds with `php artisan migrate:fresh --seed`). Save screenshots under the session scratchpad only.

4. Walk every page with the Playwright MCP, at 1280×800 and again at 375×812 (`browser_resize`). On each page, check: one sidebar and one header, breadcrumbs correct, no horizontal page scroll (`browser_evaluate`: `document.documentElement.scrollWidth <= window.innerWidth`), no console errors (`browser_console_messages`), loading spinners on submit, the empty state where there is no data, and validation errors under their fields after a deliberately empty submit.

   | Role | Pages and actions |
   |---|---|
   | admin | dashboard (tiles link to the right filtered list); tickets list (keyword, a status chip, a priority chip, SLA, agent, unassigned; reload keeps the filters; Back leaves the page); ticket create on behalf of `customer1@example.com` with one attachment; ticket detail: assign to agent1, hand off to agent2, change priority, edit the description, open the Activity tab; notifications inbox; imports: upload a small valid CSV and one with a bad row, see the progress and the rejected-rows table, download the error CSV; categories: create, edit, deactivate one in use, delete an unused one; holidays: add, edit, previous/next year, delete; P2's users and roles pages |
   | agent1 | dashboard (My tickets); claim an unassigned ticket (toast); reply with an attachment; add an internal note (amber frame, label); move to waiting for customer; the bell shows the assignment notification; mark it read and see the badge drop |
   | customer1 | lands on the ticket list; no Dashboard, Imports, Categories, Holidays, Users or Roles in the sidebar and no "Administration" group; create a ticket; open the ticket agent1 replied to; reply; close a resolved ticket through the confirm dialog |

5. Check the security boundaries in the browser, as customer1:
   - On the ticket where agent1 added the internal note, `browser_evaluate` with `document.body.innerText.includes('<note text>')` returns `false`, and there are no `[data-internal]` elements, no "Internal note" text and no internal-note toggle.
   - The Activity tab has no internal-note entry.
   - Visiting `/admin/categories`, `/admin/imports` and `/dashboard` directly gives 403, not the page.
   - Opening a ticket number that belongs to customer2 gives 404.

6. Fix what the walk finds (only within Files), rerun step 1 and re-check the affected pages.

7. Stop the server (kill the PID from step 3) and close the Playwright browser (`browser_close`). Then confirm nothing is left running:
   ```bash
   pgrep -af "artisan serve|php -S|playwright"
   ```
   Expected: no process started by this subtask.

8. Report:
   - each fix, as file and one line
   - the role × page matrix with pass/fail
   - the security checks
   - anything outside this subtask's Files that still needs a change

**Proposed commit:** `refactor(ui): align layouts, states and responsive behaviour across pages`, `Refs: P5.8`, `Implements: TD-002, TD-141`

---

## Acceptance criteria

1. No page imports or wraps itself in `AppLayout`. Every page sets breadcrumbs through `defineOptions({ layout })` or `setLayoutProps`, and every page has a `<Head>` title.
2. `npm run build`, `npm run check` and `npx vue-tsc --noEmit` all exit 0.
3. `grep -rn "v-html\|window.confirm\|window.alert" resources/js` returns nothing, and no file this plan wrote contains a code comment.
4. Every app route used from the frontend comes from a Wayfinder import. The only literal URLs are server-provided ones (pagination links, `download_url`, notification `url`).
5. Every successful action that flashes `success` or `error` shows exactly one toast, and no page renders the flash inline. Validation errors show under their fields.
6. The sidebar groups items into "Support" and "Administration". Each item is gated by `auth.can` (Dashboard: `viewDashboard`; Imports: `manageImports`; Categories: `manageCategories`; Holidays: `manageHolidays`; P2's Users/Roles: `manageUsers`). A customer sees only Tickets and Notifications.
7. The header bell is visible on every app page at 375 px and 1280 px wide. Its badge equals `notifications.unread_count`, shows "99+" above 99 and drops after mark-read or mark-all.
8. The dashboard shows the six totals, active tickets by priority, active tickets by agent and My tickets. Each total links to the matching filtered ticket list, and the page refreshes its two props every 60 s.
9. The ticket list filter bar round-trips every TD-080 parameter through the URL: a reload restores it, Back leaves the page, and switching scope resets the status chips. The people filters render only when `filterOptions.showPeopleFilters` is true.
10. Ticket detail: claim shows only for an unassigned ticket when `can.claim`; assign/hand-off only when `can.assign`; the internal-note toggle only when `can.addInternalNote`. Internal notes render with the amber frame and the "not visible to the customer" label. Closing asks for confirmation, and a closed ticket shows the closed notice instead of the composer.
11. As a customer, the ticket page contains no internal-note text, no `[data-internal]` element, no internal toggle and no internal activity. `/dashboard` and `/admin/*` return 403, and another customer's ticket returns 404 (P5.8 step 5).
12. The import show page polls while the import runs and stops when it has finished. It shows progress, counters and the rejected rows, and the error CSV downloads.
13. Category delete says "Deactivate" for a category with tickets and "Delete" otherwise, behind a confirm dialog. The holiday list switches years. Create and edit forms show server validation errors.
14. Every page passes P5.8's 375 px check: no horizontal page scroll, tables scroll inside their border, and action rows wrap.
15. No PHP file is changed by this plan, and no new server prop is introduced.
16. P5.8's report lists the role × page matrix with every row passing, and no server it started is still running.
