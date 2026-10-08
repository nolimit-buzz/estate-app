# Estate Platform - Operations Guide

## Architecture in one paragraph
One **control-plane database** (`estate_platform`) holds estates, plans, modules, subscriptions, invoices, the immutable payment ledger, support tickets, invitations, platform audit/login logs, settings and email templates. Every estate has its **own isolated database** (`estate_t_<id>`) with its own MySQL user (granted only that database). The password of each tenant DB user is stored AES-256-GCM encrypted in the control plane (key = `APP_KEY` in `.env`). A tenant database is created **empty** from `database/tenant_schema.sql` + `database/tenant_seed.json` (lookup/system rows only - no users, residents or demo data).

Request flow: `config.php` -> `Tenancy::boot()` resolves the estate (super-admin impersonation -> signed-in session -> host/subdomain -> `?estate=` / estate-code step on the login page). There is **no default estate**. `$conn` = the tenant DB, `$platform` = control plane.

## First install / upgrade
```
copy .env.example .env      # set APP_KEY (php -r "echo 'base64:'.base64_encode(random_bytes(32));"), DB_*, SMTP, Paystack
php database/bootstrap_platform.php                 # creates estate_platform, runs migrations, creates first super admin (password printed once)
php database/bootstrap_platform.php --adopt-legacy  # one-off: copies the old single-tenant DB (LEGACY_DB) into estate #1
php database/bootstrap_platform.php --migrate-tenants   # after adding files to database/migrations/tenant
```
Platform migrations: `database/migrations/platform/NNN_*.sql|php`. Tenant migrations: `database/migrations/tenant/`. Both are idempotent and tracked in `schema_migrations`.

## Scheduled jobs (required)
Run **every 15 minutes**:
- Windows Task Scheduler: `C:\xampp\php\php.exe C:\xampp\htdocs\Estate\cron\run.php`
- Linux: `*/15 * * * * php /var/www/estate/cron/run.php`
- No shell cron? call `https://host/cron/run.php?token=<CRON_TOKEN>`.

It processes trials, renewal invoices & reminders, grace periods, suspension, retention, deletion-pending, unverified sign-ups, invitation expiry, the email queue, resident counts, and (02:00-05:00) nightly per-estate backups.

## Lifecycle (all durations are Super Admin settings)
`Active -> (due date passes) Grace Period [grace_period_days] -> Suspended -> Retention [until suspended + retention_days] -> Deletion Pending -> Deleted`
- Reminders: `reminder_days_before_due`, due-day notice, grace reminders, suspension warning, retention warnings, final deletion warning.
- **Nothing is deleted automatically.** At the end of retention an estate becomes *Deletion Pending*; a Super Admin must approve (type-to-confirm). Approval takes a final backup, drops the tenant DB/user, and keeps invoices/payments/audit rows for `financial_retention_years`.
- Paying (or a manual reactivation) at any point before deletion restores the estate.

## Roles
- **Platform** (`platform_users`): superadmin / support / finance. Sign in at `/superadmin/login`.
- **Estate**: `admin` = Central Admin (first account, created by sign-up + OTP), `manager`, `zone_admin`, staff roles, `resident`. A tenant can never hold a platform role.

## Access to modules (decision order)
1. Global module OFF -> denied. 2. Estate override (Super Admin) ON/OFF. 3. Lifecycle gate (suspended = none; onboarding = core only; trial = trial plan). 4. Plan (grandfathered snapshot) 5. Purchased add-on. Enforced centrally by `ModuleRegistry` + `requireLogin()`.

## Backups / restore / export
- `storage/backups/<estate>/*.sql.gz` (outside the web root rules, denied by `.htaccess`). Each backup is bound to one estate (checksum + embedded `estate_id`/`db`); restore refuses any other estate and takes a safety backup first.
- Central Admin export (ZIP of CSVs): Estate admin -> Data Export (works while suspended/retained).
- Keep `.env` (APP_KEY!) backed up separately: without it stored tenant DB passwords cannot be decrypted.

## Security checklist before go-live
- HTTPS + `APP_ENV=production`; wildcard subdomain + `BASE_DOMAIN` if using `estate.example.com`.
- Rotate any credential that ever lived in git history (the old SMTP password was committed in `includes/Mailer.php` and the SQL dump).
- Set `PLATFORM_SMTP_*`, `PLATFORM_PAYSTACK_SECRET`, `KAPSO_WEBHOOK_SECRET`; point Paystack webhook to `/api/platform_webhook.php`.
- Create a dedicated MySQL admin for provisioning (`DB_ADMIN_USER`) and a least-privilege `DB_USER` for the control plane.
- Run `php tests/isolation_check.php` and `php tests/lifecycle_test.php` on a **dev** DB after upgrades.

## Troubleshooting
- Unknown estate on login: enter the estate code (the sign-up "code"), or use `login?estate=<code>`.
- Emails in local mode are written to `storage/logs/mail.log`.
- Super Admin -> System monitoring lists failed jobs, payment/webhook errors and email/invitation failures with retry.
