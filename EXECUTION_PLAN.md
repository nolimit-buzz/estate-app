# Executing Plan: Estate SaaS (follows ROADMAP.md)

## Context
`C:\xampp\htdocs\Estate` is a PHP 8 / mysqli app on XAMPP. M0–M6 of ROADMAP.md are done. M7 (SaaS control plane) is partly built, and M8–M9 are not started. The exploration found that tenancy is only half enforced: a second paying estate cannot be onboarded safely until the gaps below are closed. This plan is written for another AI agent to execute task by task. Do the phases in order. Each task lists files, what to do, and an acceptance check. Do not start a phase until the previous phase's acceptance checks pass.

## Ground rules for the executing agent
- No framework, no composer app. Match the existing style: procedural PHP, `$conn` global mysqli, helpers in `config.php`, extensionless routes via root `.htaccess`.
- New code uses prepared statements. Do not mass-rewrite old queries in a drive-by; fix them in the phase that names them.
- Every tenant query filters by `estate_id = get_estate_id()` (`config.php:142`). Never trust an id from GET/POST without joining it to `estate_id`.
- Schema changes go in new idempotent scripts under `database/` (pattern: `database/migrate_saas.php`: `SHOW COLUMNS`/`CREATE TABLE IF NOT EXISTS`, prints `[OK]/[SKIP]/[ERR]`). Never edit `estate (6).sql`.
- Commit per task; end commits with the required `Co-Authored-By` line. Do not push.
- Update ROADMAP.md status text when a phase completes.

## Current-state facts that drive the plan
- `get_estate_id()` order: impersonation (superadmin only) → session → subdomain via `estates.domain_prefix` → **falls back to 1** (`config.php:142-172`). `custom_domain` is unused.
- Login looks up users by email only, not scoped to an estate (`includes/auth_helper.php:37`).
- Module flags: 10 keys in `getAllPlatformModules()` (`config.php:194`), table `estate_modules`, missing row means enabled (`config.php:282`). `requireModule()` (`config.php:287`) is called only in `admin/africastalking.php` and `admin/security.php`. The admin sidebar (`includes/sidebar.php`) hides links; zone, resident and staff sidebars check nothing. `vehicle_registry` is never checked.
- CSRF helpers exist (`includes/auth_guard.php:10-33`) but `verifyCSRFToken` is called in 4 files; superadmin pages use none.
- Plan limits `max_residents`/`max_guards` are stored and shown, never enforced. No subscription billing, no signup, no auto-expiry (`subscription_expires_at` unread).
- Gateway secrets live in `system_settings` per estate, plaintext. `api/paystack_webhook.php` hardcodes estate 1 and skips signature check when the header is absent. `api/ussd.php` and `api/get_*` resolve the estate via subdomain else 1. `api/kapso_webhook.php` POST has no signature check.
- `admin/resident_timeline.php` has no `estate_id` filter (cross-tenant IDOR). `includes/auth_guard.php:125,137` zone lookups are unscoped.
- `hasPermission` is defined twice (`config.php:375`, `includes/auth_guard.php:190`); the `config.php` copy wins.
- Tables without `estate_id` are child tables (`invoice_items`, `payment_transactions`, `role_permissions`), global lookups, and `estate_settings` (legacy). Everything else is scoped.
- No tests, no `manifest.json`, no service worker, no `.htaccess` in `uploads/`.

## Phase 1: Close tenant-isolation holes (blocks everything else)
1. **Remove the estate-1 fallback.** `config.php:get_estate_id()`: for unauthenticated requests resolve by `domain_prefix` or `custom_domain`; if nothing matches return 0/null and let callers fail closed (public pages show a "unknown estate" page). Keep a single-estate dev escape hatch: a `DEFAULT_ESTATE_ID` constant in `config.php`, used only when the host is `localhost`/an IP. Check: a request with an unknown host gets no estate data.
2. **Estate-scoped login.** `includes/auth_helper.php:authenticatePortalUser`: resolve the estate first, look up `WHERE email=? AND estate_id=?`; superadmin accounts (estate-less or estate 1) log in on a separate path. Reuse `isEmailTakenInEstate` (`config.php:536`). Check: same email in two estates logs into the right one.
3. **Fix IDORs.** Add `estate_id` filters in `admin/resident_timeline.php` (:19, :87) and the zone lookups in `includes/auth_guard.php` (:125, :137). Then audit pages the exploration flagged as thin: `admin/billing_config.php`, `zone/index.php`, `resident/property.php`, and every `WHERE <table>.id = $id` in `admin/`, `zone/`, `resident/`, `staff/` (grep `WHERE .*\.id *= *\$`). Scope child tables through their parent join.
4. **Dedupe `hasPermission`.** Keep one definition, in `includes/auth_guard.php`; delete the `config.php:375-399` copy only after confirming every portal loads auth_guard (otherwise move it to `config.php`). Check: all portals still render.
5. **Harden API endpoints.**
   - `api/paystack_webhook.php`: resolve the estate from the transaction/invoice reference first, load that estate's secret, reject when the header is missing or the signature is wrong.
   - `api/kapso_webhook.php`: verify the signature on POST, scope the update by estate.
   - `api/ussd.php`: route by service code/shortcode → estate (`at_shortcode` / `at_ussd_code` in `system_settings`), not by host; reject unknown codes.
   - `api/get_buildings|get_flats|get_commercial_fields`, `api/payment_methods.php`: require a login or a resolved estate; no GET without either.
   - `api/sync_offline.php`: confirm `$_SESSION['user_id']` is enforced on GET and POST.
6. **Isolation test script.** Add `tests/isolation_check.php` (CLI): create two estates, then request a list of known endpoints/pages as estate A and assert estate B's ids return nothing. Seed it from `superadmin/create_estate.php` logic. Check: script exits 0.

## Phase 2: Real module enforcement
1. Add a map `ROUTE_MODULES` (page path → module key) in `config.php` and a single call in `includes/auth_guard.php:requireLogin()` that runs `requireModule()` for the current script. This avoids editing 50 pages.
   Mapping: finance/charges/billing_config/receipt(s)/pay_invoice → `billing_invoicing`; broadcasts → `broadcast_messaging`; policies → `bylaws_policies`; artisans → `artisan_marketplace`; emergency + `api/emergency` → `emergency_sos`; roster/security → `security_patrol`; gate_pass/visitors/verify_code → `visitor_passes`; car_sticker/`api/search_vehicle` → `vehicle_registry`; africastalking/`api/ussd`/`api/sync_offline` → `ussd_offline_sync`; `zone/*` and `admin/zones` → `zonal_divisions`.
2. Make `requireModule()` return JSON 403 for `/api/` and a friendly page otherwise (its current default redirect is `../admin/index`).
3. Apply the same checks to `zone/sidebar.php`, `resident/sidebar.php`, `staff/sidebar.php` (reuse `isModuleEnabled`, `config.php:260`) and add the `vehicle_registry` check in `includes/sidebar.php`.
4. Seed `estate_modules` rows for every estate in `create_estate.php` (already done at :89; verify) and in a migration for existing estates. Keep "missing row = enabled".
5. Check: with a module off, direct URL and API calls return 403/blocked for admin, zone, resident and staff; add to `tests/isolation_check.php`.

## Phase 3: Finish M7 control plane
1. **CSRF everywhere in `superadmin/`.** Use `renderCSRFField()` in each form and `verifyCSRFToken()` in each POST handler: `create_estate`, `edit_estate`, `ajax_update_estate`, `admins`, `gateways`, `modules`, `ajax_toggle_module`, `ajax_toggle_estate_status`. For AJAX send the token as a header/field from a `<meta>` tag. Make `impersonate.php` and `exit_impersonation.php` POST + CSRF (change the link in `superadmin/index.php:298` to a small form).
2. **One auth check.** Make all `ajax_*`, `impersonate.php`, `exit_impersonation.php` include `superadmin/includes/super_auth.php` instead of their weaker role-only checks. Block impersonating `suspended`/`expired` estates unless explicitly confirmed.
3. **Small fixes** (from review): reject negative `max_*` (`ajax_update_estate.php`); require `bulk_action` ∈ {enable_all, disable_all} in `modules.php:18`; enforce a minimum password length and drop the `AdminPass123!` default (`create_estate.php:30`); verify `plan`/`billing_cycle` against the enum on create; protect user id 1 from reset in `admins.php`.
4. **Plan limits.** New `includes/plan_limits.php`: `assertWithinLimit($estate_id, 'residents'|'guards')`, returns a flash error when `count >= max_*`. Call from resident-creation in `admin/residents.php`, `zone/residents.php`, and guard/staff creation in `admin/staff.php`/`admin/users.php`. Check: creating resident #max+1 is refused.
5. **Plans table.** Migration `database/migrate_plans.php`: `plans(key, name, price_monthly, price_annual, max_residents, max_guards)` and `plan_modules(plan_key, module_key)`. Applying a plan on estate create/edit sets the limits and seeds `estate_modules`; the superadmin can still override per estate.
6. **Gateways per estate.** `superadmin/gateways.php` currently writes everything to estate 1 and echoes the API key. Change it to a per-estate selector, mask stored keys (show last 4), and keep blank = unchanged. Add `includes/secrets.php` with `encryptSetting()/decryptSetting()` (libsodium or openssl AES-256-GCM, key from an env var / file outside webroot) and use it for `paystack_secret_key`, `at_api_key`, `kapso_api_key`, `smtp_pass`. Write a migration that encrypts existing values; update the readers (`includes/Paystack.php:12`, `AfricasTalking.php:94`, `WhatsApp.php`, `Mailer.php:188`).
7. **Cron for expiry.** Add `cron/expire_estates.php`: set `status='expired'` where `subscription_expires_at < NOW()` and status is active/trial; log to `audit_logs`. Document the XAMPP/Windows Task Scheduler line. Also check expiry in `auth_guard.php:63-77`.
8. Add `trial` and `maintenance` handling to the suspended gate (show banner for trial, block for maintenance).
9. Check: acceptance for M7 in ROADMAP.md: two estates, own branding/modules, off module blocked on every route, isolation script green. Then mark M7 Done in ROADMAP.md and commit the currently-uncommitted superadmin files (stage by name, not `git add -A`).

## Phase 4: SaaS launch (M8)
1. **Signup + onboarding.** New public `signup.php` (estate name, subdomain check against `domain_prefix`, admin name/email/password, plan) → creates the estate in `trial` status, reusing the transaction in `superadmin/create_estate.php` (extract it to `includes/estate_provisioning.php:createEstate($data)` and call from both). Onboarding wizard in `admin/` for first login: zones, branding (`admin/branding.php`), modules.
2. **Subscription billing.** Platform-level Paystack keys (platform settings, under the superadmin gateways page). New tables `estate_subscriptions`/`estate_invoices`. Flow: `billing/subscribe.php` initialises a Paystack charge for the plan; a **separate** platform webhook `api/platform_webhook.php` verifies the signature, extends `subscription_expires_at`, sets status `active`. Superadmin view of tenant invoices. Reuse `includes/Paystack.php` (parameterise the key source).
3. **Domains.** Wire `custom_domain` into `get_estate_id()`; document wildcard DNS plus Apache vhost setup.
4. **Hardening.** Add `uploads/.htaccess` (no PHP execution), validate upload MIME/extension in the upload handlers (grep `move_uploaded_file`), login rate limiting (table `login_attempts`), roll CSRF out to the remaining POST handlers (grep `$_SERVER['REQUEST_METHOD'] === 'POST'` in `admin/`, `zone/`, `resident/`, `staff/`; do it in a shared helper called from `requireLogin()` for non-API POSTs, with the token auto-injected via `js/` for existing forms). Move DB credentials out of `config.php` into a git-ignored `config.local.php`.
5. **Ops.** Backup script (mysqldump + uploads zip), error logging to a file, a `/health.php` endpoint, a support-access policy note in README (impersonation is audited).
6. Check: a brand-new estate signs up, pays with Paystack test keys, gets modules per plan; Main Estate unaffected.

## Phase 5: PWA and API (M9, optional, after clients)
1. `manifest.json`, icons, service worker with offline shell (reuse `js/estate_offline_sync.js` for gate sync), update prompt, Web Push.
2. A versioned JSON API (`api/v1/`) with token auth, wrapping existing logic; extract shared logic from page scripts into `includes/`.
3. Native app is a separate repo. Out of scope here.

## Critical files
`config.php`, `includes/auth_guard.php`, `includes/auth_helper.php`, `includes/sidebar.php`, `zone|resident|staff/sidebar.php`, `superadmin/*`, `api/paystack_webhook.php`, `api/ussd.php`, `api/kapso_webhook.php`, `includes/Paystack.php`, `AfricasTalking.php`, `WhatsApp.php`, `Mailer.php`, `database/migrate_saas.php` (pattern for new migrations).

## Existing code to reuse
`generateCSRFToken/renderCSRFField/verifyCSRFToken` (`auth_guard.php:10-33`), `isModuleEnabled/requireModule/getAllPlatformModules` (`config.php`), `isEmailTakenInEstate` (`config.php:536`), `logAudit/setFlashMessage/redirectWithFlash`, `superadmin/includes/super_auth.php`, the transaction in `superadmin/create_estate.php`, `includes/Paystack.php`.

## Verification (end to end)
1. Run migrations: `php database/migrate_saas.php`, then each new migration; they must be re-runnable.
2. `php tests/isolation_check.php` exits 0 (grows each phase).
3. Manual on XAMPP: create two estates in the superadmin; log in to each; confirm no cross-estate rows, module toggles block direct URLs, plan limit refuses an extra resident, impersonation requires POST+CSRF, a suspended estate lands on `/suspended`.
4. Webhooks: send a Paystack test event with a bad/missing signature (rejected) and a valid one (accepted, correct estate).
5. `php -l` every changed PHP file.

## Open assumptions (change if wrong)
- Tenants are identified by subdomain, with a localhost default estate for dev.
- Emails need only be unique per estate.
- Secrets are encrypted with a key from an environment variable.
- Subscriptions are billed through the platform's own Paystack account.
