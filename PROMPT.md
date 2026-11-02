# Build prompt — WorkFinder (Job Hunter app)

Copy everything below the line into a new Claude Code session opened in `C:\xampp\htdocs\WorkFinder`.

---

Build **WorkFinder**, a job-hunting web app for South African job seekers. Users search jobs, companies, industries and skills in one place, save what interests them, and send applications (CV + cover letter) by email straight from the app. Every email sent is logged, and the user gets a copy and a confirmation in their own inbox.

## Reuse InvoiceChat — don't start from zero

`C:\xampp\htdocs\Invoicechat` is my existing app. Read it first and reuse its stack, structure and look so both apps feel like one product family:

- **Stack:** PHP 8.1+, MySQL/MariaDB, PDO, Composer (PHPMailer, dompdf). No build step and no JS framework: plain PHP pages, `ajax/` endpoints, vanilla JS.
- **Copy and adapt** these files: `config/config.php`, `config/db.php`, `config/secrets.example.php`, `includes/auth.php`, `includes/security.php` (CSRF, rate limiting), `includes/functions.php`, `includes/settings.php`, `includes/mailer.php`, `includes/layout.php`, `admin/_shell.php`, `database/migrate.php` (self-running migrations), `auth/google.php`, `cron.php`, `.htaccess`, `.cpanel.yml`, `.gitignore`.
- **Design:** use `assets/css/style.css` and `assets/css/admin.css` as the base: same tokens, fonts (Bricolage Grotesque + Plus Jakarta Sans), Font Awesome 6, light/dark theme switch, cards, `kpi` tiles, `tbl` tables, pills and the split-screen auth pages. Change only the brand: `--brand` becomes a deep teal or emerald (I'll confirm), the wordmark becomes **Work*Finder***, with a new `mark.svg`.
- **User dashboard:** same layout as InvoiceChat's `dashboard.php` (sidebar, `adm-head` page header, KPI row, then cards and tables) and the same mobile behaviour (tables turn into cards via `data-label`).

## Features

### 1. Public site
- Landing page (`index.php`): hero with a search bar (job title / skill + location), live job count, top industries, how it works, sign-up call to action.
- Sign-up / sign-in with email + password or Google, forgot/reset password, terms, privacy. Same security as InvoiceChat.

### 2. Onboarding + profile
- Short wizard after sign-up: desired job titles, skills (tag input with suggestions), industries, preferred locations (SA provinces/cities + Remote), experience level, salary range, work type (full-time, contract, remote, hybrid).
- Upload a CV (PDF/DOCX, max 5 MB, stored outside the public web path, served via signed link like InvoiceChat's `pdf.php`).
- Profile page to edit all of this.

### 3. Find
One search page with four tabs:
- **Jobs:** keyword, location, industry, skill, salary, work type, date posted. Results show title, company, location, salary, source, posted date, and a **skills match %** against the user's profile.
- **Companies:** name, industry, location, size, website, careers page, HR/careers email, open jobs. Company detail page lists its jobs.
- **Industries:** browse industries → companies and jobs in each.
- **Skills:** browse skills → jobs needing them, and how in-demand each skill is (job count trend).

**Job data sources** — use only official/free APIs, no scraping of sites that forbid it (no LinkedIn/Indeed scraping):
- Adzuna API (has South Africa, country `za`) — primary
- Careerjet, Jooble — secondary (API keys in admin settings)
- Remotive / Arbeitnow — remote jobs, no key
- Jobs and companies the admin adds manually or imports by CSV

Build each source as a small class behind one interface (`JobSource::fetch(array $query): array`) so more sources can be added later. `cron.php` pulls fresh jobs every hour, de-duplicates them (same title + company + location), extracts skills from the description against a `skills` dictionary, and expires jobs older than 45 days.

### 4. Save & track (my pipeline)
- Save jobs and follow companies.
- Application tracker with statuses: Saved → Applied → Interview → Offer → Rejected, plus notes and follow-up dates. Show it as a kanban board and as a table.
- Dashboard KPIs: new matching jobs this week, applications sent, replies/interviews, follow-ups due.

### 5. Apply by email
- From any job or company: **Apply by email** opens a composer with the recipient (the job's apply email or the company HR email), a subject and a cover letter pre-filled from a template (with an **AI draft** button using Groq, reused from InvoiceChat's `includes/ai.php`), plus the user's CV attached.
- Sent through the app's SMTP (`admin@swiftfusion.co.za` / a `jobs@` mailbox) with **Reply-To set to the user's own email**, so employers reply to the user directly.
- After every successful send:
  1. the email is saved in `sent_emails` (to, subject, body, attachment, status, timestamps, SMTP error if any);
  2. the user gets a **copy of the sent email** plus a "Your application to X was sent" confirmation in their own inbox;
  3. the job moves to **Applied** in the tracker.
- **Sent emails** page: list of everything sent, with status (sent / failed / bounced), re-send for failures, and open count (tracking pixel, like InvoiceChat's `doc_event.php`).
- Limits to protect the mail server's reputation: max 20 application emails per user per day (set in admin), unsubscribe/opt-out link for companies, never send to the same address twice in 7 days for the same job.

### 6. Job alerts
- Saved searches with an alert frequency (instant / daily / weekly). `cron.php` emails matching new jobs as a clean digest with one-click "Save" and "Apply" links. Unsubscribe link in every email.

### 7. Plans (optional, same model as InvoiceChat billing)
- Free: 10 application emails/month, 2 alerts. Pro: R49/month, unlimited alerts, 300 emails, AI cover letters. Reuse `includes/billing.php` and OTT voucher approval. Make it switchable off in settings.

## Admin dashboard (`/admin`)
Same shell as InvoiceChat's admin. Sidebar:
- **Overview:** Dashboard (sign-ups, active users, jobs in database by source, emails sent today/failed, top searched skills and industries, charts for the last 30 days).
- **Users:** search, view profile/CV/applications/sent emails, edit, suspend, delete, reset password, make admin, impersonate ("log in as" with an audit-log entry), export CSV.
- **Jobs:** list/filter all jobs, add/edit/delete, feature a job, CSV import, per-source sync status and "Sync now".
- **Companies & industries:** add/edit/merge duplicate companies, set HR emails, manage the industry list.
- **Skills:** manage the skills dictionary and synonyms (e.g. "JS" = "JavaScript").
- **Emails:** every email the system sent (applications, alerts, system), with filters, failures, bounces, and the per-user daily limit.
- **Payments** (if plans are on).
- **Settings:** site name, SMTP + "Send test email", job API keys (Adzuna, Careerjet, Jooble), Groq key, Google sign-in, sending limits, plans on/off.
- **Audit log:** admin actions with who, what and when.

## Admin account
- Seed it exactly like InvoiceChat: on the first page load, `migrate.php` creates the admin **`admin@swiftfusion.co.za`** with the password from `admin_seed_password` in `config/secrets.php` (bcrypt hash, never stored in plain text, never committed).
- Include `admin_reset_password` / `admin_reset_emails` recovery in `secrets.php`, same as InvoiceChat.
- Roles: `user`, `admin`. Every `/admin` page calls `requireAdmin()`.

## Hosting: swiftfusion.co.za (cPanel)
- Live URL: **https://workfinder.swiftfusion.co.za** (document root `/home/swiftfusionco/workfinder.swiftfusion.co.za/`).
- Database: `swiftfusionco_workfinder`, user `swiftfusionco_workfinder` (I'll create them in cPanel → MySQL Databases and put the password in `secrets.php`).
- Local: XAMPP at `http://localhost/WorkFinder`, database `swiftfusionco_workfinder`, user `root`, no password (the `local_db_*` keys).
- Deploy via GitHub + cPanel Git Version Control with a `.cpanel.yml` like InvoiceChat's (repo `bothamaria169-code/workfinder`, cloned to `/home/swiftfusionco/repositories/workfinder`). `secrets.php`, `uploads/` and CVs are never in git.
- Cron every 15 minutes: `/usr/local/bin/php /home/swiftfusionco/workfinder.swiftfusion.co.za/cron.php`.

## Database (create with migrate.php)
`users`, `user_profiles`, `user_skills`, `cvs`, `companies`, `industries`, `skills`, `skill_synonyms`, `jobs`, `job_skills`, `job_sources`, `saved_jobs`, `followed_companies`, `applications`, `sent_emails`, `email_events` (opens/bounces), `saved_searches`, `alerts_log`, `settings`, `payments`, `audit_log`, `password_resets`, `login_attempts`. Use foreign keys, `utf8mb4`, and indexes on every search filter (FULLTEXT on job title + description).

## Security & POPIA
- CSRF on every form and AJAX POST, prepared statements only, output escaped with `e()`, sign-in rate limiting, single-use hashed reset tokens.
- CVs are private: blocked by `.htaccess`, served only to their owner or an admin.
- POPIA: consent checkbox at sign-up, "Download my data" and "Delete my account" in settings.

## Deliver
1. Working app on XAMPP at `http://localhost/WorkFinder` with demo data (20 companies, 100 jobs, 5 industries, 60 skills) behind a "Load demo data" button in admin.
2. `README.md` (same style as InvoiceChat's) and `docs/SETUP-GUIDE.md`: cPanel database, subdomain, mailbox, API keys, cron, deploy — step by step.
3. Work in this order and show me each step working before the next: (1) foundation + auth + admin seed, (2) profile + CV, (3) jobs/companies/search + one API source, (4) apply by email + sent emails + copies, (5) tracker + alerts, (6) admin pages, (7) billing, docs, deploy files.
