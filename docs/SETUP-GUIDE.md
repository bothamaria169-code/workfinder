# WorkFinder — setup guide

Everything you click, in order. Live site: **https://workfinder.swiftfusion.co.za**.

## 1. Subdomain

cPanel → **Domains** → **Create a New Domain**
- Domain: `workfinder.swiftfusion.co.za`
- Untick “Share document root”. Document root: `workfinder.swiftfusion.co.za` (that is `/home/swiftfusionco/workfinder.swiftfusion.co.za`).

cPanel → **SSL/TLS Status** → tick the new subdomain → **Run AutoSSL**, so it opens on https.

## 2. Database

Already created:
- Database `swiftfusionco_workfinder`
- User `swiftfusionco_workuser`, added to the database with **All Privileges** (cPanel → MySQL Databases → Add User To Database).

No SQL import is needed. Tables are created on the first page load.

## 3. Mailbox

`workfinder@swiftfusion.co.za` (cPanel → Email Accounts). Outgoing server `mail.swiftfusion.co.za`, port 465 (SSL). It sends applications, the copies users receive, job alerts and password resets.

For good delivery: cPanel → **Email Deliverability** → make sure SPF and DKIM are **Valid** for swiftfusion.co.za.

## 4. Code (GitHub → cPanel)

cPanel → **Git™ Version Control** → **Create**
- Clone URL: `https://github.com/bothamaria169-code/workfinder.git`
- Repository path: `/home/swiftfusionco/repositories/workfinder`

Then **Manage** → **Pull or Deploy** → **Update from Remote** → **Deploy HEAD Commit**.

## 5. secrets.php (once)

File Manager → `/home/swiftfusionco/workfinder.swiftfusion.co.za/config/` → **Upload** your local `config/secrets.php`.
It contains the database, admin and mailbox passwords plus two random keys. It is in `.gitignore`, so it is never pushed to GitHub, and deploys never overwrite it.

## 6. First visit

Open https://workfinder.swiftfusion.co.za → **Sign in**:
- Email: `admin@swiftfusion.co.za`
- Password: the `admin_seed_password` value in `secrets.php`

Change it any time under Admin → **My account**. Locked out? Put a new password in `admin_reset_password` in `secrets.php` and load any page once.

## 7. Cron

cPanel → **Cron Jobs** → Common settings “Every 15 minutes”, command:

```bash
/usr/local/bin/php /home/swiftfusionco/workfinder.swiftfusion.co.za/cron.php >/dev/null 2>&1
```

It pulls new jobs (every 60 minutes by default), sends job alerts and follow-up reminders. Admin → Settings shows when it last ran.

## 8. Job APIs (free)

| Source | Where to get it | Paste into |
|---|---|---|
| Adzuna — best South African coverage | developer.adzuna.com → Register → App ID + App Key | Admin → Settings → Job APIs |
| Careerjet | careerjet.co.za/partners → affiliate ID | Admin → Settings → Job APIs |
| Jooble | jooble.org/api/about → request a key | Admin → Settings → Job APIs |
| Remotive, Arbeitnow | no key needed (remote jobs) | — |

Then Admin → **Job sources** → **Sync all now**. Edit the search terms on the same page.

## 9. Try it with demo data

Admin → **Jobs** → **Load demo data** adds 20 sample companies and about 90 jobs (emails at `example.co.za`, which never deliver). **Remove demo data** clears them.

## 10. Google sign-in (optional)

Google Cloud Console → APIs & Services → Credentials → **Create OAuth client ID** (Web application).
Authorised redirect URI: `https://workfinder.swiftfusion.co.za/auth/google.php`.
Paste the Client ID and secret into Admin → Settings → Google sign-in.

## 11. AI cover letters (free)

1. Sign in at https://console.groq.com/keys → **Create API Key** → copy it.
2. Admin → **Settings → AI cover letters** → paste the key → **Save** → **Test the key**.

Users then see **Write it with AI**, **Tailor to the advert**, **Shorter**, **More formal** and **Warmer** under the cover letter when applying. The AI only uses the user's profile and the advert, and never invents experience.

## 12. Plans & payments

Admin → **Payments**:
- **Prices & plans** — defaults: Free = 10 application emails, 5 AI letters and 2 job alerts a month; Pro = R49/month for 300 emails, 100 AI letters and 20 alerts; credits R10 = 10 application emails (never expire). Untick **Plans are on** to make everything free.
- **How you get paid** — tick bank deposit (EFT), OTT voucher and/or 1Voucher, and add your bank account for deposits. Each customer's deposit reference is WF + their number (e.g. WF00042).
- **To verify** — every payment waits here. Check the deposit in your bank (or redeem the voucher PIN in your OTT / 1Voucher merchant portal), then **Approve** or **Reject**. The user is emailed either way.

You can also give credits or Pro by hand on any user's page (Admin → Users → the user → Plan & credits).
