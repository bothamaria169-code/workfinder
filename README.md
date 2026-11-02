# WorkFinder

Job hunting for South Africa: search jobs, companies, industries and skills, see your skills match on every advert, apply by email with your CV, and keep a copy of every application.
PHP 8.1+ · MySQL/MariaDB · no build step. Same stack and design system as InvoiceChat.

| Area | Where |
|---|---|
| Landing page | `index.php` |
| Sign in / sign up / reset password / Google | `login.php`, `register.php`, `forgot-password.php`, `reset-password.php`, `auth/google.php` |
| Onboarding, profile, CVs | `onboarding.php`, `profile.php`, `cv.php` (private CV download), `includes/profile.php` |
| Find | `jobs.php`, `job.php`, `companies.php`, `company.php`, `industries.php`, `skills.php`, `includes/jobs.php` |
| My hunt | `saved.php`, `tracker.php` (board), `emails.php` (sent emails), `alerts.php` (job alerts), `account.php` (POPIA download/delete) |
| Apply by email | `apply.php`, `includes/applications.php` (sending, copies to the user, limits, opt-outs, alerts, reminders), `open.php` (open tracking), `unsubscribe.php` (employer opt-out) |
| Job APIs | `sources/sources.php` — Adzuna, Careerjet, Jooble, Remotive, Arbeitnow |
| Admin dashboard | `admin/` — Dashboard, Users (edit, suspend, make admin, log in as, export), Emails, Jobs (post, CSV import, demo data), Companies (merge), Industries & skills, Job sources, Settings, Audit log |
| Scheduled work | `cron.php` — job sync, alerts, follow-up reminders |
| Database schema | `database/migrate.php` — runs itself on the first page load |
| Setup guide | **`docs/SETUP-GUIDE.md`** |

## Deploy (cPanel + GitHub)

1. **cPanel → Domains → Create a New Domain**: `workfinder.swiftfusion.co.za`, document root `/home/swiftfusionco/workfinder.swiftfusion.co.za`.
2. **cPanel → Git™ Version Control → Create** → Clone URL `https://github.com/bothamaria169-code/workfinder.git`, repository path `/home/swiftfusionco/repositories/workfinder` (a *different* folder from the site).
3. **Manage → Pull or Deploy → Update from Remote → Deploy HEAD Commit.** `.cpanel.yml` copies the files into the site folder.
4. **First time only:** upload `config/secrets.php` with File Manager to `/home/swiftfusionco/workfinder.swiftfusion.co.za/config/`. It holds the database password, the admin password and the mailbox password, and is never in git.
5. Open https://workfinder.swiftfusion.co.za once. Tables are created and the admin account `admin@swiftfusion.co.za` is made from `admin_seed_password` in `secrets.php`.
6. **cPanel → Cron Jobs** → every 15 minutes:
   ```bash
   /usr/local/bin/php /home/swiftfusionco/workfinder.swiftfusion.co.za/cron.php >/dev/null 2>&1
   ```

Afterwards, every change is: push to GitHub → cPanel **Update from Remote** → **Deploy HEAD Commit**.

## After deploying — switch things on (Admin → …)

- **Settings → Email delivery → Send test email.** The mailbox is `workfinder@swiftfusion.co.za`.
- **Settings → Job APIs:** free keys for Adzuna (best SA coverage), Careerjet and Jooble. Then **Job sources → Sync all now**.
- **Jobs → Load demo data** to try everything before the APIs are connected; **Remove demo data** when you go live.
- **Settings → Google sign-in** (optional).

## How apply-by-email works

The email goes from the WorkFinder mailbox, shown as “*Applicant name* via WorkFinder”, with **Reply-To set to the applicant**, so employers reply to them directly. After a successful send:
1. it is saved in `sent_emails` (shown on the user's **Sent emails** page and in **Admin → Emails**);
2. the user gets a **copy of exactly what was sent** plus a confirmation, with the CV attached;
3. the job moves to **Applied** on their board with a follow-up reminder in 7 days.

Guard rails: a per-user daily limit (default 20), no repeat to the same address for the same job within 7 days, and employers can opt out from a link in every application.

## Security notes

- Secrets only in `config/secrets.php`; `.htaccess` blocks `config/`, `includes/`, `sources/`, `database/`, `vendor/`, `docs/`, `uploads/cvs/` and notes/dumps.
- CVs get random file names and open only through `cv.php` for their owner or an admin.
- CSRF tokens on every form and AJAX POST; sign-in is rate-limited; reset links are single-use, hashed and expire after 60 minutes.
- Every admin action (including “log in as”) is written to the audit log.

## Local development

XAMPP at `http://localhost/WorkFinder`. On localhost the `local_db_*` settings from `secrets.php` are used (`root`, no password, database `swiftfusionco_workfinder`).
