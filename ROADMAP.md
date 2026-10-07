# Roadmap

The canonical plan for the PHP estate app (`Estate`) as a multi-tenant SaaS. Each milestone matches a GitHub milestone of the same name; each deliverable is a GitHub issue.

**Product shape:** one deployment serves many estates. Each estate gets its own branding, its own data (scoped by `estate_id`), and only the modules it is switched on for. The web app is mobile-first (`css/mobile_app.css`, `js/mobile_app.js`); a native app (M9) follows after the first few clients, on the same API.

**Architecture:** PHP and MySQL on XAMPP. Tenancy is a shared database, with every tenant table scoped by `estate_id`. Per-estate feature flags live in `estate_modules`, and the Super Admin console under `superadmin/` is the control plane. Roles are `superadmin`, `admin`/`manager`, `zone_admin`, `staff` and `resident`, each with its own portal folder.

**Order of work:** each milestone is marked **Done**, **In progress** or **Planned**. The first deployment (Main Estate) runs on this codebase today.

---

## M0 — Foundation (Done)

Repo, schema and base app shell.

- Core PHP app, `config.php`, shared `includes/` (header, sidebar, footer, auth guard, mobile nav)
- Initial MySQL schema (`estate (6).sql`)
- Public landing page (`index.php`) with per-estate name, motto, logo and theme colour
- README with the zonal hierarchy and portal overview

**Exit:** the app runs locally on XAMPP and the landing page shows the estate's branding.

## M1 — Zonal RBAC and role portals (Done)

- Separate portals: central admin (`admin/`), zone admin (`zone/`), staff (`staff/`), resident (`resident/`)
- Zone creation with auto-generated codes and zone login credentials
- Strict zone scoping: zone admins see only their own streets, residents and invoices; blocked from security, gate pass and central settings
- Login, logout, forgot and reset password, forced password change
- Multi-profile role switcher

**Exit:** a zone admin cannot read or change data outside their zone, in any portal page.

## M2 — Core estate data (Done)

- Zones, streets, buildings and flats (`properties`, `property_details`)
- Residents with family members, vehicles, pets and domestic staff; resident timeline
- Property owners
- Directory, archives, ID card generation
- Policies, bylaws and penalties, with a public showcase on the landing page

**Exit:** a full resident can be onboarded in one flow, from street to flat to household.

## M3 — Finance (Done)

- Charges, billing configuration, installments
- Invoices, offline payment recording, printable receipts
- Paystack checkout, payment verification and webhook (`pay_invoice.php`, `verify_payment.php`, `api/paystack_webhook.php`)
- Bank account resolution and payment methods
- Finance dashboards for admin and zone admin; resident finance and receipts

**Exit:** a resident pays an invoice with Paystack, and the receipt and ledger update.

## M4 — Security and gate (Done)

- Visitor gate passes with codes and QR (`gate_pass.php`, `verify_code.php`)
- Staff gatehouse console: verification, check-in and check-out logs
- Vehicle register, car stickers and vehicle search at the gate
- Security posts, roster and shifts; incidents
- Offline sync for the gatehouse (`api/sync_offline.php`, `js/estate_offline_sync.js`)

**Exit:** a guard verifies a pass in under 5 seconds, and offline check-ins sync afterwards.

## M5 — Communication and emergency (Done)

- Notices, broadcasts and in-app notifications
- WhatsApp (Kapso webhook), email (PHPMailer) and SMS delivery, with logs
- Panic button, emergency roster and alarm screen (`api/emergency.php`)
- Community chat

**Exit:** a panic alert reaches the guard screen and notifies stakeholders.

## M6 — Artisans, maintenance and USSD (Done)

- Artisan registration, accreditation, reviews and gate passes
- Maintenance requests and issue reporting
- Africa's Talking USSD gateway, with walk-in visitor registration and an auto-dialing simulator (`api/ussd.php`)
- Audit log

**Exit:** an artisan is vetted and listed, and a visitor registers through USSD.

## M7 — SaaS control plane (In progress)

The Super Admin console and per-estate tenancy.

Done:
- Super Admin command center and `estates` table with plan, status, limits and custom domain (`database/migrate_saas.php`)
- Per-estate module flags in `estate_modules`, with a live toggle matrix
- One-click impersonation and exit
- Estate suspension (`suspended.php`)
- White-label branding (logo, theme colour, per-estate settings)
- Role auto-refresh from the database for the primary admin and superadmin

In progress (uncommitted work in the tree):
- Estate create and edit flows (`superadmin/create_estate.php`, `edit_estate.php`, `ajax_update_estate.php`)
- Estate admins management (`superadmin/admins.php`)
- Gateways page for Paystack, USSD and WhatsApp keys per estate (`superadmin/gateways.php`)
- Platform audit view (`superadmin/audit.php`)
- Modules matrix page (`superadmin/modules.php`) and the Super Admin sidebar and topbar

To do:
- Enforce `max_residents` and `max_guards` plan limits
- Move gateway credentials into per-estate settings, with encryption at rest
- Cross-estate isolation test pass over every portal page and every `api/` endpoint
- Plans table and plan-to-module mapping

**Exit:** two estates run side by side, each with its own branding and modules, and a switched-off module is blocked on every route. No query returns another estate's rows.

## M8 — SaaS launch (Planned)

- Self-service estate signup and onboarding wizard (zones, first admin, branding, modules)
- Subscriptions billed through Paystack, with suspension and expiry on non-payment
- Per-estate subdomains or custom domains
- Hardening: prepared statements throughout, CSRF tokens, upload validation, rate limiting on login
- Backups, error monitoring, uptime checks, support-access policy

**Exit:** a second, new estate has signed up and paid, and Main Estate stays untouched.

## M9 — PWA and native app (Future)

Starts after the first few clients are live.

- Installable PWA: manifest, service worker, update prompt, Web Push
- REST API with token auth, extracted from the page-bound `api/` scripts
- Native app (Expo or similar) on the same API, with QR scanning for guards
- Store releases

**Exit:** a resident installs the app, receives a push notification, and a guard scans a pass natively.
