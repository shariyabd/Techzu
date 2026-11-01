# Techzu Support: Support Ticket & SLA Management System

## 1. What this is

Techzu Support is a help desk web application. Customers open **tickets** (support requests) and chat with the support team inside each ticket. Support agents pick up tickets, answer them and resolve them before a deadline called the **SLA** (service level agreement), which only counts the company's business hours. Administrators manage the team, the deadlines, holidays and bulk imports, and see everything on a dashboard.

**Main features**
- Tickets with numbers like `TKT-2026-000001`, five statuses, priorities and categories.
- Three roles (Admin, Agent, Customer), each seeing only what they are allowed to see.
- Automatic assignment to the right agent, manual assignment, and "claim" with protection against two agents grabbing the same ticket.
- A chat-style conversation with file attachments, private **internal notes** for staff, and **saved replies** (reusable answers).
- SLA deadlines that respect business hours and holidays, pause while waiting for the customer, and escalate automatically with email and in-app notifications.
- Live updates: new messages, "who is viewing" and "is typing" appear without reloading the page.
- Search and filters, a dashboard, an agents directory, CSV import of old tickets, an activity history for every ticket, and a REST API with documentation.

---

## 2. Choose how to run it

| | Option A: Standard setup | Option B: Docker |
|---|---|---|
| Status | **Available now** | Coming soon |
| You install | Git, PHP, Composer, Node.js and MySQL on your computer | Docker Desktop only |
| Good for | Everyone today | Later, once it is ready |
| Time needed | 30–60 minutes the first time | |

---

## 3. Option A: Standard setup, step by step

A **terminal** is the text window where you type commands: on macOS open the **Terminal** app; on Windows open **PowerShell** (press the Windows key, type `PowerShell`, press Enter). Type or paste each command, then press **Enter**. Lines in the boxes below that start with `#` are explanations; you don't need to type them.

### Step 1: Install the tools

You need five tools. If you already have one, skip it. After installing, **close and reopen the terminal** so it finds the new tools.

| Tool | What it is for | Version |
|---|---|---|
| **Git** | downloads the code | any recent |
| **PHP** | runs the application (the server side) | 8.3 or newer |
| **Composer** | installs PHP libraries | 2.x |
| **Node.js** (includes **npm**) | builds the screens (the browser side) | 24 LTS recommended (22.18 or newer works) |
| **MySQL** | stores the data (the **database**) | 8.x |

PHP needs these **extensions** (add-ons): `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `curl`, `xml`, `dom`, `tokenizer`, `ctype`, and `gd` (for profile pictures). The easy routes below include all of them.

#### macOS

**Easy route: Laravel Herd (PHP + Composer) and DBngin (MySQL)**
1. Download and install **Herd** from <https://herd.laravel.com>. It installs PHP and Composer.
2. Download and install **DBngin** from <https://dbngin.com>. Open it, click **+**, choose **MySQL 8**, keep port **3306**, click **Create**, then **Start**. The user is `root` with no password.
3. Install **Node.js 24 LTS** from <https://nodejs.org> (the macOS installer).
4. Git: run `git --version`. If macOS offers to install the "command line developer tools", click **Install**.

**Alternative: MAMP** (<https://www.mamp.info>) gives you MySQL with a click: start MAMP, and MySQL runs on port **8889** with user `root` and password `root`. You still need PHP 8.3+ on the command line (Herd, or `brew install php`), Composer and Node.js.

**Alternative: Homebrew** (<https://brew.sh>), for people comfortable with the terminal:
```bash
brew install git php composer node@24 mysql@8.0
brew services start mysql@8.0
```

#### Windows

**Easy route: Laragon** (PHP + Composer + MySQL in one installer)
1. Download **Laragon Full** from <https://laragon.org> and install it. Open Laragon and click **Start All**. MySQL runs on port **3306** with user `root` and no password. Make sure Laragon's PHP version is 8.3 or newer (menu → PHP → version).
2. Use Laragon's **Terminal** button for the commands below; it already knows where PHP, Composer and MySQL are.
3. Install **Node.js 24 LTS** from <https://nodejs.org> (the Windows installer).
4. Install **Git** from <https://git-scm.com/download/win> (accept the defaults).

**Alternative: Laravel Herd for Windows** (<https://herd.laravel.com/windows>) for PHP and Composer, plus the **MySQL Installer** (<https://dev.mysql.com/downloads/installer/>) for MySQL 8. Remember the root password you choose.

XAMPP ships MariaDB instead of MySQL. The app is built and tested on MySQL 8, so prefer one of the options above.

#### Check that everything is installed

```bash
git --version
php -v
composer --version
node -v
npm -v
php -m
```

What you should see: a version number for each (`PHP 8.3…` or higher, `Composer version 2…`, `v24…` or `v22.18…` or higher). `php -m` prints a list of extensions; look for `pdo_mysql`, `mbstring` and `gd` in it.

### Step 2: Get the code

```bash
git clone <repository-url> techzu
cd techzu
```

Replace `<repository-url>` with the address of this repository (on GitHub, the green **Code** button shows it, for example `https://github.com/<owner>/<repo>.git`).

What you should see: Git prints `Cloning into 'techzu'...`, and `cd techzu` moves you into the project folder. **Run every following command from this folder.**

### Step 3: Create the database

The app needs one empty database called `techzu`. Pick one way.

**With a few clicks**
- **MAMP**: open MAMP → **WebStart** → **Tools** → **phpMyAdmin**. Click **New** in the left column, type `techzu` as the name, choose `utf8mb4_unicode_ci`, click **Create**.
- **Laragon**: click **Database** to open HeidiSQL, right-click the connection → **Create new** → **Database**, name it `techzu`, collation `utf8mb4_unicode_ci`, **OK**.
- **DBngin**: click the database's **Open** button (opens TablePlus if installed), then create a database named `techzu`.

**With a command**

```bash
mysql -u root -p -e "CREATE DATABASE techzu CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Press Enter at the password prompt if your MySQL has no password (MAMP: type `root`). With MAMP the `mysql` program lives at `/Applications/MAMP/Library/bin/mysql80/bin/mysql`, and you add `-P 8889 -h 127.0.0.1`.

What you should see: no error. (If you see `database exists`, it is already there, which is fine.)

### Step 4: Configure the app

The app reads its settings from a file called `.env`. Make it from the example:

macOS:
```bash
cp .env.example .env
```

Windows (PowerShell):
```powershell
copy .env.example .env
```

Open `.env` in any text editor (TextEdit, Notepad, VS Code) and set the database lines to match your MySQL:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=techzu
DB_USERNAME=root
DB_PASSWORD=
```

| Setting | Meaning | Typical value |
|---|---|---|
| `DB_HOST` | the computer MySQL runs on; `127.0.0.1` means "this computer" | `127.0.0.1` |
| `DB_PORT` | MySQL's "door number" | `3306` (MAMP: `8889`) |
| `DB_DATABASE` | the database you created in step 3 | `techzu` |
| `DB_USERNAME` | your MySQL user | `root` |
| `DB_PASSWORD` | that user's password; leave empty if there is none | empty (MAMP: `root`) |

Leave everything else as it is. The file already contains working settings for live updates (the `REVERB_…` lines), so there is nothing to generate for them.

### Step 5: Install the libraries, create the key, build the screens

Run these four commands one after the other, in this order:

```bash
composer install
php artisan key:generate
npm install
npm run build
```

- `composer install` downloads the PHP libraries into a `vendor` folder. It takes a minute or two. You should see `Generating optimized autoload files` near the end and no red errors.
- `php artisan key:generate` creates the app's secret encryption key and writes it into `.env`. You should see `INFO  Application key set successfully.` (It only works after `composer install`.)
- `npm install` downloads the JavaScript libraries into `node_modules`. You should see `added … packages`. Yellow `warn` lines are normal; `EBADENGINE` warnings mean your Node.js version is not one of the recommended ones (24 LTS or 22.18+).
- `npm run build` builds the screens. You should see `✓ built in …`.

### Step 6: Create the tables and the demo data

```bash
php artisan migrate --seed
```

This creates all the tables (**migrate**) and fills them with a realistic demo help desk (**seed**): an admin, six agents, twelve customers, 88 tickets with conversations and attachments, saved replies, holidays and a finished CSV import. When your computer is online it also downloads profile photos for the demo people (from Unsplash); offline it simply skips them and shows initials instead.

What you should see: a list of migrations marked `DONE`, then `Seeding database.` and three seeders marked `DONE` (`RolesAndPermissionsSeeder`, `SlaDefaultsSeeder`, `DemoSeeder`). Seeding takes about 10–30 seconds.

**To start over at any time** (this deletes all data and creates fresh demo data):

```bash
php artisan migrate:fresh --seed
```

> Tip: SLA states (on time, approaching, breached) depend on the current time, so they drift as hours pass. Run `php artisan migrate:fresh --seed` shortly before you show the app to someone.

### Step 7: Start the app

```bash
composer run dev
```

This one command starts everything the app needs, side by side:

| Part | What it does |
|---|---|
| **server** | the website itself, at http://127.0.0.1:8000 |
| **queue** | a background worker that sends emails, assigns tickets and runs imports, so pages don't wait for them |
| **scheduler** | a clock that checks SLA deadlines every minute and escalates late tickets |
| **reverb** | the live-updates server (port 8080), for new messages, "is typing" and who is online |
| **logs** | shows what the app writes to its log (macOS and Linux only) |
| **vite** | serves the screens and refreshes them when the code changes |

Open **http://127.0.0.1:8000** in your browser. You should see the sign-in page.

Keep the terminal window open while you use the app. To stop everything, click into the terminal and press **Ctrl+C** (on Windows confirm with `Y` if asked).

### Step 8: Sign in

Every demo account uses the password **`password`**.

| Role | Email | Name | Notes |
|---|---|---|---|
| Admin | `admin@example.com` | Ada Mensah | can do everything |
| Agent | `agent1@example.com` | Liam Carter | Billing, Technical Support |
| Agent | `agent2@example.com` | Priya Sharma | Technical Support |
| Agent | `agent3@example.com` | Marcus Chen | General Inquiry |
| Agent | `agent4@example.com` | Sofia Rossi | Billing, General Inquiry; **on leave** (unavailable), so never auto-assigned |
| Agent | `agent5@example.com` | Daniel Okafor | Account & Access, Technical Support |
| Agent | `agent6@example.com` | Hannah Weber | Feature Requests, General Inquiry, Billing |
| Customer | `customer1@example.com` … `customer12@example.com` | Emma Johnson, Noah Williams, … | each sees only their own tickets |

What each role can try:
- **Customer**: open a ticket with a file, reply, close or reopen a resolved ticket, edit your profile and picture.
- **Agent**: work the queue, claim tickets, reply with saved replies, write internal notes, change status and priority, see the dashboard and the agents directory.
- **Admin**: everything an agent does, plus assign any ticket, manage users and roles, categories, holidays, SLA rules, and import tickets from CSV.

### Step 9: A guided tour

Use two different browsers (for example Chrome and Firefox, or one normal and one private window) so you can be signed in as two people at once.

1. **Customer creates a ticket.** In browser 1 sign in as `customer1@example.com`. Click **New ticket**, fill in a subject and description, choose a priority and category, attach a small PDF or image, and submit. Note the ticket number and its SLA due time.
2. **Agent sees it live.** In browser 2 sign in as `admin@example.com` and open **Tickets**. The new ticket appears without reloading. If automatic assignment found a matching agent, it already shows an assignee.
3. **Typing indicator.** Both open the same ticket. Start typing a reply in one browser: the other shows "… is typing", and both show who is viewing the ticket.
4. **Saved reply.** As the admin, click **Saved replies** in the reply box, pick a reply, and watch the placeholders (customer name, ticket number) fill in. Send it: the customer's browser shows the new message at once.
5. **Internal note.** As the admin, switch the reply box to **Internal note** and send one. The customer never sees it, not even in the history.
6. **SLA settings.** As the admin, open **Admin → SLA & business hours**. Change the Critical target or the business hours, tick **Recalculate active tickets** if you want open tickets to follow, and save.
7. **Import a CSV.** As the admin, open **Imports → New import** and upload [`docs/samples/tickets-import-sample.csv`](docs/samples/tickets-import-sample.csv). Tick **Auto-assign active tickets** (off by default) if you want the imported open tickets handed to agents once the import finishes. Watch the progress; the error report shows the two rows that are rejected on purpose (an email that belongs to no customer, and a duplicate of the row above it).
8. **Agents directory.** Open **Agents** to see each agent's workload. Click a number, for example "Unresponded", to jump to exactly those tickets.

Emails are not really sent in this setup: they are written to the log file `storage/logs/laravel.log` (see Troubleshooting).

---

## 4. Option B: Docker (coming soon)

> **Not available yet.** Instructions for running the app with Docker will be added here. Until then, please use Option A.

---

## 5. Troubleshooting

| Problem | What to do |
|---|---|
| **"Address already in use" / port 8000 or 8080 is busy** | Another program uses that port. Stop it, or start the website on another port with `php artisan serve --port=8001` (and open http://127.0.0.1:8001). For port 8080 change `REVERB_PORT` and `REVERB_SERVER_PORT` in `.env`, then run `npm run build` again. |
| **"Connection refused" (SQLSTATE[HY000] [2002])** | MySQL is not running, or the port is wrong. Start MySQL (DBngin, MAMP, Laragon) and check `DB_PORT` in `.env` (MAMP uses `8889`). |
| **"Access denied for user" (SQLSTATE[HY000] [1045])** | The username or password in `.env` is wrong. Fix `DB_USERNAME` / `DB_PASSWORD`, then run `php artisan config:clear`. |
| **"Unknown database 'techzu'"** | Create the database (step 3). |
| **"could not find driver"** or **"requires ext-…"** | A PHP extension is missing. Run `php -m` to see which are on. With Herd, MAMP or Laragon they are included; make sure the terminal uses that PHP (`php -v`). With Homebrew PHP, `brew reinstall php`. On Windows with a manual PHP, enable the extension in `php.ini` (remove the `;` before `extension=pdo_mysql`, `extension=gd`, …). |
| **`npm install` fails or warns about the engine** | Check `node -v`. Install Node.js 24 LTS and run `npm install` again. If it still fails, delete the `node_modules` folder and run `npm install` again. |
| **The page has no styles, or says "Vite manifest not found"** | Run `npm run build`, then reload the page. |
| **Nothing updates live (no "is typing", new messages only after a reload)** | The live-updates server is not running. Use `composer run dev`, which starts it. If you start parts by hand, also run `php artisan reverb:start` and a queue worker (`php artisan queue:work`). |
| **Tickets are never assigned, emails never arrive in the log** | The queue worker is not running. Use `composer run dev`. |
| **Nothing ever escalates** | The scheduler is not running. Use `composer run dev`, or run `php artisan sla:scan` by hand. |
| **Where are the emails?** | In development they are written to `storage/logs/laravel.log`. Open it in a text editor and search for the subject. Password-reset links appear there too. |
| **Profile photos are missing in the demo** | The computer was offline while seeding, or the image server could not be reached. Run `php artisan migrate:fresh --seed` again when online. |
| **Something is badly wrong; start over** | `php artisan migrate:fresh --seed` rebuilds the database with fresh demo data. Then `php artisan optimize:clear`. |

---

## 6. Running the tests

The tests use a separate, empty database called `techzu_testing`. Create it once:

```bash
mysql -u root -p -e "CREATE DATABASE techzu_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Then run:

```bash
php artisan test --compact
npm run test:js
```

What a pass looks like: the PHP run ends with a green line such as `Tests: 1874 passed`, and the JavaScript run (`npm run test:js`, the frontend tests) ends with `Tests  49 passed`. A failing test is shown in red with the file and line. The PHP suite takes a few minutes; `./vendor/bin/pest --parallel` is faster (it creates extra `techzu_testing_test_N` databases, so your MySQL user needs permission to create databases).

---

## 7. For developers

The full documentation starts at **[docs/README.md](docs/README.md)**: requirements, product requirements, architecture, business logic, database design, security, API, background processing and realtime, testing, operations, the decision log, the traceability matrix and the demo data. Contributor and AI-agent rules are in [CLAUDE.md](CLAUDE.md).

Quick links:
- API documentation in the running app: http://127.0.0.1:8000/docs/api (open to everyone in the `local` environment).
- Quality checks before a commit: `./vendor/bin/pint --test`, `./vendor/bin/phpstan analyse --memory-limit=1G`, `./vendor/bin/pest --parallel`, `npm run build && npm run test:js && npm run check && npx vue-tsc --noEmit`.
