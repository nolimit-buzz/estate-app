# Estate Management SaaS — Production Modularization Specification

## Project Context

The Estate Management application already exists and is approaching production. This is **not a request to rebuild the application from scratch**.

The objective is to modularize, harden, and complete the existing application so it can operate as a scalable multi-estate SaaS platform.

### Core Principles

- Preserve existing working functionality and business logic.
- Do not redesign working features unnecessarily.
- Reuse existing components where practical.
- Do not introduce duplicate functionality.
- Build new functionality as clearly separated modules.
- Keep configuration out of hardcoded frontend values where possible.
- Maintain strict tenant/estate data isolation.
- Make all important administrative actions auditable.
- Make the system responsive and production-ready on desktop and mobile.
- Any implementation should first inspect the existing codebase, database schema, routes, permissions, and current modules before making destructive changes.

---

# Module 01 — Public Landing Page & Marketing Website

## Objective

Create a public-facing landing page that feels like a professional SaaS website rather than an internal application dashboard.

## Requirements

The landing page should include:

- Hero section explaining the Estate Management platform.
- Clear Sign Up and Login actions.
- Overview of the platform.
- Key benefits for estate owners and management.
- Features overview.
- Modules overview.
- Pricing/subscription section.
- Security and reliability information.
- Support information.
- Frequently Asked Questions where appropriate.
- Contact information.
- Company/developer information.
- Company website and social media links.
- Clear call-to-action sections.

## Marketing Section

Create a dedicated section explaining the company that built and operates the application.

It should be possible to configure:

- Company name.
- Company description.
- Company logo.
- Website.
- Social media links.
- Contact email.
- Support information.
- Other relevant company information.

These should preferably be configurable rather than hardcoded.

## Design Requirements

The landing page should:

- Look modern and premium.
- Feel like a commercial SaaS product.
- Have generous spacing.
- Avoid excessive cards.
- Avoid visual clutter.
- Be responsive.
- Work properly on mobile.
- Maintain consistency with the existing application branding.

---

# Module 02 — Estate Registration & Onboarding

## Objective

Create a controlled registration workflow for new estates.

## Registration Flow

The intended flow is:

1. Visitor opens the landing page.
2. Visitor selects Sign Up.
3. Estate information is collected.
4. Estate registration begins.
5. A new isolated estate environment/database is created.
6. The database starts completely empty.
7. The first Central Admin begins account registration.
8. Email verification is required.
9. OTP verification is required.
10. Central Admin completes onboarding.
11. Support/onboarding ticket is created.
12. Subscription plan is selected.
13. Payment is completed.
14. Estate becomes Active.

## Empty Estate Database Requirement

When a new estate is created:

- Its database/data environment must start empty.
- Do not create fake residents.
- Do not create demo users.
- Do not copy another estate's users.
- Do not copy another estate's residents.
- Do not copy another estate's financial data.
- Do not pre-populate Central Admin accounts unless explicitly required by the architecture.

The first real Central Admin must be registered and verified through the onboarding process.

---

# Module 03 — Multi-Tenant Architecture & Data Isolation

## Objective

Ensure every estate operates as an independent tenant.

## Requirements

Every estate must have strict data isolation.

Estate A must never be able to access:

- Estate B users.
- Estate B residents.
- Estate B properties.
- Estate B payments.
- Estate B visitors.
- Estate B financial records.
- Estate B support tickets.
- Estate B modules.
- Estate B subscription information.

## Database Strategy

The existing database architecture should be inspected before implementation.

Where the application uses separate databases per estate, ensure:

- A new estate receives a clean database.
- Database credentials/configuration are securely managed.
- Database provisioning is automated.
- Database deletion is controlled.
- Backups are isolated.
- One estate cannot restore or access another estate's data.

If the existing architecture uses another tenant-isolation strategy, preserve it where secure and production-ready rather than changing it unnecessarily.

---

# Module 04 — Central Admin Registration & Verification

## Objective

Securely establish the first administrative user for each estate.

## Requirements

The first Central Admin must complete:

1. Account registration.
2. Email verification.
3. OTP verification.
4. Password setup.
5. Required profile information.
6. Estate onboarding.

## OTP Requirements

OTP should have:

- Expiration time.
- Maximum verification attempts.
- Resend controls.
- Rate limiting.
- Secure generation.
- Secure storage/validation.
- Appropriate failure messages.

The account must not become fully active until verification is successfully completed.

---

# Module 05 — User Invitation & Onboarding

## Objective

Prevent fake or unauthorized accounts from being added to estates.

## Invitation Requirements

When an administrator invites a user:

- An email must be sent.
- The invitation must contain a secure token/link.
- The invitation must expire.
- The token must not be reusable.
- The invited user must accept the invitation.
- Email verification must be enforced.
- The account should not become active before the invitation is accepted.

## Invitation States

Support states such as:

- Pending
- Accepted
- Expired
- Declined
- Cancelled

## Invitation Audit

Record:

- Who sent the invitation.
- Who received it.
- Estate.
- Date/time.
- Invitation status.
- Acceptance date/time.
- Expiration.
- Relevant security information.

---

# Module 06 — Modular Application Architecture

## Objective

Convert the application into a clearly modular system without unnecessarily rewriting existing functionality.

## Possible Modules

The final module structure should reflect the application's existing features, but may include:

- Resident Management
- Property/Unit Management
- Estate Management
- Estate Finance
- Payments
- Visitor Management
- Gate Management
- Security/SOS
- Complaints
- Service Requests
- Announcements
- Facility Management
- Staff Management
- Notifications
- Reports & Analytics
- Documents
- Communication
- Subscription Management
- Support Tickets

Do not remove existing working modules simply to satisfy the new architecture.

## Module Requirements

Each module should have clear separation between:

- UI
- Business logic
- Database operations
- Permissions
- Configuration
- Notifications
- Reports where applicable

New modules should be possible to add without rewriting the entire platform.

---

# Module 07 — Module Management System

## Objective

Allow modules to be dynamically enabled or disabled.

## Requirements

The system should distinguish between:

1. Global module status.
2. Subscription-plan module access.
3. Estate-specific module override.

## Super Admin Controls

Super Admin should be able to:

- Enable a module globally.
- Disable a module globally.
- Configure module pricing.
- Assign modules to subscription plans.
- Override module access for individual estates.
- View which estates are using a module.

## Important Rule

A globally disabled module must not become available simply because a subscription plan includes it.

Every module change must be logged.

---

# Module 08 — Module Pricing & Demo Pricing

## Objective

Display module/demo prices while keeping complete control with Super Admin.

## Requirements

Each module can have:

- Module name.
- Description.
- Demo price.
- Monthly price where applicable.
- Annual price where applicable.
- Included/free status.
- Availability status.
- Subscription-plan associations.

## Pricing Control

Prices must not be permanently hardcoded in frontend code.

Super Admin should be able to:

- Create module pricing.
- Edit module pricing.
- Disable module pricing.
- Change demo prices.
- Change recurring module prices.
- Determine whether a module is included in a plan.

---

# Module 09 — Subscription Plans

## Objective

Create a configurable SaaS subscription system.

## Subscription Tiers

Support configurable plans such as:

- Basic
- Standard/Professional
- Premium

The exact names must be configurable by Super Admin.

## Billing Cycles

Support:

- Monthly.
- Annual/Yearly.

## Plan Configuration

Super Admin should be able to configure:

- Plan name.
- Description.
- Monthly price.
- Annual price.
- Resident limit.
- User limit where applicable.
- Included modules.
- Optional modules.
- Module pricing.
- Storage limits where applicable.
- Support level.
- Other feature restrictions.

---

# Module 10 — Resident-Based Subscription System

## Objective

Make subscription pricing and access dependent on the number of residents.

## Requirements

The system should support resident capacity/count tiers.

For example:

- Up to X residents.
- X–Y residents.
- Y–Z residents.
- Custom configurable limits.

Do not hardcode the limits.

Super Admin must be able to modify resident thresholds.

The system should display:

- Current plan.
- Resident limit.
- Current resident count.
- Remaining capacity.
- Billing cycle.
- Next payment date.
- Subscription status.

---

# Module 11 — Subscription-to-Module Automation

## Objective

Automatically determine module availability from the estate's subscription.

## Requirements

When a subscription becomes active:

- Included modules are enabled automatically.
- Restricted modules remain unavailable.
- Optional modules follow their configured rules.
- Resident limits are enforced.
- Plan restrictions are enforced.

## Super Admin Override

Super Admin must still be able to:

- Enable a module for an estate.
- Disable a module for an estate.
- Override the default plan configuration.

All overrides must be visible and auditable.

---

# Module 12 — Support Ticket & Onboarding Workflow

## Objective

Create a structured support process after estate registration.

## Workflow

A new estate should progress through stages such as:

- Registration Started
- Email Verification Pending
- Central Admin Verification Pending
- Onboarding Pending
- Support Review
- Plan Selection
- Payment Pending
- Payment Confirmed
- Active

## Support Tickets

Central Admin should be able to:

- Create tickets.
- View tickets.
- Reply to tickets.
- Attach relevant information where supported.
- Track ticket status.

Support staff/Super Admin should be able to:

- Assign tickets.
- Reply.
- Change priority.
- Change status.
- Add internal notes where appropriate.
- Close/reopen tickets.

---

# Module 13 — Subscription Payment & Billing

## Objective

Provide reliable payment and billing management.

## Requirements

Support:

- Payment initiation.
- Payment confirmation.
- Pending payments.
- Failed payments.
- Renewals.
- Refunds where applicable.
- Subscription activation.
- Subscription renewal.
- Subscription cancellation.

## Billing History

Each estate should have a complete billing history containing:

- Invoice/reference.
- Plan.
- Amount.
- Billing cycle.
- Payment status.
- Payment date.
- Renewal date.
- Failed payment records.
- Refund records where applicable.

Payment records should not be casually editable by estate administrators.

---

# Module 14 — Payment Grace Period

## Objective

Prevent immediate service interruption when a subscription payment is missed.

## Default

For monthly subscriptions, use a configurable grace period of approximately:

**15–20 days**

The exact value must be configurable by Super Admin.

## During Grace Period

The application remains accessible.

Display:

- Payment warnings.
- Outstanding amount.
- Due date.
- Remaining grace period.
- Renewal/payment action.

Send appropriate notifications.

## Notifications

Send reminders:

- Before payment is due.
- On payment due date.
- When grace period starts.
- During grace period.
- Before suspension.

---

# Module 15 — Suspension & 45-Day Data Retention

## Objective

Protect estate data after subscription suspension.

## Lifecycle

Use:

**Active → Grace Period → Suspended → Retention → Deletion Pending → Deleted**

## Suspension

If payment remains unpaid after the grace period:

- Estate becomes Suspended.
- Normal application access is disabled/restricted.
- Data is preserved.
- Central Admin sees the subscription suspension message.
- Renewal/reactivation remains possible.

## Retention Period

Keep the estate data securely preserved for:

**45 days**

The 45-day period should be configurable by Super Admin.

During this period:

- Estate can renew.
- Data can be restored.
- Super Admin can see the retention countdown.
- Warnings should be sent.

---

# Module 16 — Final Data Deletion

## Objective

Prevent accidental permanent deletion.

## Requirements

Before deletion:

- Send warning notifications.
- Display remaining retention period.
- Move estate to Deletion Pending.
- Require appropriate authorization for permanent deletion.
- Create a final audit record.

After the retention period expires, permanently delete data according to the platform's configured retention policy.

## Important

Financial/legal records may require a different retention policy. Do not automatically destroy records that must legally be retained.

---

# Module 17 — Subscription Upgrade & Downgrade

## Objective

Allow estates to change plans safely.

## Requirements

Support:

- Upgrade.
- Downgrade.
- Monthly → Annual.
- Annual → Monthly where permitted.
- Resident-capacity changes.
- Module changes.

The system should clearly handle:

- Effective date.
- Payment difference.
- New resident limits.
- Module changes.
- Billing adjustments.

---

# Module 18 — Super Admin Portal

## Objective

Provide complete platform-wide control.

## Dashboard

Display:

- Total estates.
- Active estates.
- Trial estates.
- Estates in grace period.
- Suspended estates.
- Retention estates.
- Pending deletion.
- Total residents.
- Subscription revenue where applicable.
- Monthly recurring revenue where applicable.
- Annual subscriptions.
- Failed payments.
- Pending payments.
- Open support tickets.
- Recent registrations.
- Recent logins.
- Security alerts.

## Super Admin Capabilities

Super Admin should control:

- Estates.
- Users.
- Modules.
- Plans.
- Pricing.
- Subscriptions.
- Payments.
- Support tickets.
- Marketing content.
- System settings.
- Audit logs.
- Security logs.
- Data retention.
- Estate lifecycle.

---

# Module 19 — Complete Login & Audit Logging

## Objective

Create complete platform-wide visibility of important activity.

## Login Logs

Record:

- User.
- Estate.
- Role.
- Date/time.
- Login success/failure.
- Logout where available.
- IP address where appropriate.
- Browser/device information where appropriate.
- Relevant security information.

## Audit Logs

Record important actions such as:

- Estate creation.
- User creation.
- User invitation.
- Invitation acceptance.
- Role changes.
- Subscription changes.
- Pricing changes.
- Module changes.
- Payment events.
- Estate suspension.
- Estate reactivation.
- Data deletion.
- Super Admin actions.

Each audit event should identify:

**WHO → WHAT → WHEN → WHICH ESTATE**

## Filtering

Super Admin should be able to filter logs by:

- Estate.
- User.
- Role.
- Event type.
- Date range.
- Login status.

Audit logs should be protected from normal estate-level users.

---

# Module 20 — Estate Lifecycle Management

## Objective

Create a consistent lifecycle for every estate.

## Estate Statuses

Support statuses such as:

- Pending
- Onboarding
- Trial
- Active
- Grace Period
- Suspended
- Retention
- Deletion Pending
- Deleted
- Archived

## Subscription Statuses

Support:

- Pending Payment
- Active
- Past Due
- Grace Period
- Suspended
- Cancelled
- Expired
- Renewed

These statuses must be visible to Super Admin.

---

# Module 21 — Notification & Email System

## Objective

Create reliable transactional communication.

## Important Emails

Support emails for:

- Registration confirmation.
- Email verification.
- OTP.
- Invitation.
- Invitation acceptance.
- Subscription activation.
- Payment confirmation.
- Payment failure.
- Upcoming renewal.
- Payment due.
- Grace period.
- Suspension warning.
- Account suspension.
- Retention warning.
- Final deletion warning.
- Support ticket updates.

## Email Templates

Where practical, Super Admin should be able to manage:

- Subject.
- Email body.
- Branding.
- Platform name.
- Support details.

---

# Module 22 — Security & Authentication

## Objective

Make the application production-ready from a security perspective.

## Requirements

Implement or verify:

- Secure password hashing.
- Password reset.
- Email verification.
- OTP verification.
- OTP expiration.
- Rate limiting.
- Login attempt protection.
- Session management.
- Role-based access control.
- CSRF protection where applicable.
- Input validation.
- Secure database access.
- Tenant isolation.
- Secure invitation tokens.
- Secure subscription/payment handling.

Sensitive information must never be exposed to estate-level users.

Never expose:

- Database credentials.
- API secrets.
- Payment secrets.
- Internal configuration.
- Super Admin controls.

---

# Module 23 — Role-Based Access Control

## Objective

Ensure users only access functions appropriate to their role.

## Requirements

Separate:

- Super Admin.
- Central Admin.
- Estate-level administrators.
- Residents.
- Gate/security users.
- Staff.
- Other existing application roles.

Super Admin permissions are global.

Estate-level administrators can only manage their own estate.

Residents and staff must only access the features assigned to their roles and subscription.

---

# Module 24 — Backup & Disaster Recovery

## Objective

Protect estate data against system failure.

## Requirements

Create a reliable backup strategy.

Backups must:

- Be isolated by estate.
- Be securely stored.
- Be recoverable.
- Prevent cross-estate restoration mistakes.
- Have appropriate retention rules.

Restoration must be carefully controlled.

A backup from Estate A must never accidentally overwrite or expose Estate B.

---

# Module 25 — Data Export

## Objective

Give estates controlled access to their own information.

Where appropriate, provide data export for:

- Resident data.
- Property/unit data.
- Financial information.
- Visitor records.
- Reports.
- Other estate-owned information.

Exports should respect permissions and privacy requirements.

---

# Module 26 — Error, Job & System Monitoring

## Objective

Give Super Admin visibility into technical problems.

Track important system failures such as:

- Failed emails.
- Failed invitations.
- Failed OTPs.
- Failed payments.
- Failed background jobs.
- Subscription processing errors.
- Database errors.
- Notification errors.

Provide enough information for administrators/developers to diagnose problems without exposing sensitive information.

---

# Module 27 — System Configuration

## Objective

Move important platform rules into configurable settings.

Super Admin should be able to configure where appropriate:

- Grace period.
- Data-retention period.
- OTP expiration.
- Invitation expiration.
- Subscription settings.
- Resident limits.
- Module pricing.
- Subscription pricing.
- Notification settings.
- Marketing information.
- Company information.

Avoid hardcoding these values into the frontend.

---

# Module 28 — High-Risk Action Protection

## Objective

Prevent accidental destructive administrative actions.

Require confirmation for:

- Deleting estates.
- Permanently deleting data.
- Disabling modules globally.
- Suspending estates.
- Changing subscription configuration.
- Changing critical pricing.
- Other destructive actions.

High-risk actions must create audit records.

Where appropriate, require an additional confirmation step for permanent deletion.

---

# Module 29 — Mobile & Responsive Experience

## Objective

Make the entire platform production-ready on mobile.

Do not simply shrink desktop screens.

Mobile interfaces should be designed specifically for:

- Dashboard.
- Navigation.
- Tables.
- Forms.
- Subscription management.
- Module management.
- Support tickets.
- Notifications.
- User management.
- Super Admin screens.

Avoid:

- Excessive icons.
- Crowded layouts.
- Tiny controls.
- Too many cards.
- Unnecessary visual elements.

The mobile UI should feel clean, mature, modern, and professional.

---

# Module 30 — UI/UX Consistency

## Objective

Create one coherent product experience.

Maintain:

- Existing brand identity.
- Existing working design patterns.
- Consistent typography.
- Consistent spacing.
- Consistent buttons.
- Consistent forms.
- Consistent tables.
- Consistent status indicators.
- Consistent navigation.

Do not redesign the application merely for visual novelty.

Prioritize usability and clarity over excessive decoration.

---

# Module 31 — Reporting & Analytics

## Objective

Give Super Admin and estate administrators useful operational information.

## Super Admin Reports

Include where applicable:

- Estate registrations.
- Active estates.
- Subscription revenue.
- Subscription status.
- Resident counts.
- Module usage.
- Payment status.
- Failed payments.
- Support tickets.
- Login activity.
- Audit activity.

## Estate Reports

Respect the estate's permissions and subscription.

---

# Module 32 — Trial & Demo System

## Objective

Allow prospective estates to understand the platform before purchasing.

If a trial is implemented:

- Trial duration must be configurable.
- Trial limits must be configurable.
- Trial modules must be configurable.
- Trial expiration must be enforced.
- Trial conversion to paid subscription must be clear.

Demo pricing on the public website must remain controlled by Super Admin.

---

# Module 33 — Production Readiness Review

Before deployment, perform a complete review of:

## Architecture

- Tenant isolation.
- Database provisioning.
- Module boundaries.
- Subscription architecture.
- Configuration management.

## Security

- Authentication.
- Authorization.
- OTP.
- Invitations.
- Sessions.
- Rate limiting.
- Data isolation.
- Secrets.

## Billing

- Payment success.
- Payment failure.
- Grace period.
- Suspension.
- Renewal.
- Upgrade.
- Downgrade.
- Refund handling.

## Data

- Backup.
- Restore.
- Retention.
- Export.
- Deletion.

## UX

- Landing page.
- Registration.
- Onboarding.
- Dashboards.
- Mobile experience.
- Subscription screens.
- Support tickets.

## Administration

- Super Admin.
- Module management.
- Plan management.
- Pricing management.
- Audit logs.
- Login logs.
- System settings.

---

# Module 34 — Recommended Final Estate Lifecycle

The complete lifecycle should be:

```text
PUBLIC LANDING PAGE
        ↓
ESTATE SIGN UP
        ↓
ESTATE REGISTRATION
        ↓
ISOLATED EMPTY ESTATE DATABASE
        ↓
CENTRAL ADMIN REGISTRATION
        ↓
EMAIL VERIFICATION
        ↓
OTP VERIFICATION
        ↓
ONBOARDING
        ↓
SUPPORT / ONBOARDING TICKET
        ↓
PLAN SELECTION
        ↓
PAYMENT
        ↓
PAYMENT CONFIRMED
        ↓
SUBSCRIPTION ACTIVE
        ↓
MODULES AUTOMATICALLY ENABLED
        ↓
CENTRAL ADMIN INVITES USERS
        ↓
EMAIL INVITATION
        ↓
INVITATION ACCEPTANCE
        ↓
USER ONBOARDING
        ↓
NORMAL ESTATE OPERATION
        ↓
RENEWAL REMINDERS
        ↓
PAYMENT DUE
        ↓
GRACE PERIOD (15–20 DAYS, CONFIGURABLE)
        ↓
IF PAID → ACTIVE
        ↓
IF NOT PAID → SUSPENDED
        ↓
45-DAY DATA RETENTION
        ↓
IF RENEWED → REACTIVATE
        ↓
IF NOT RENEWED → DELETION PENDING
        ↓
FINAL AUTHORIZED DELETION
```

---

# Module 35 — Implementation Rules for the Existing Application

Before changing code:

1. Inspect the existing application.
2. Inspect the database schema.
3. Identify all existing modules.
4. Identify existing roles and permissions.
5. Identify existing authentication.
6. Identify existing estate/resident relationships.
7. Identify existing payment functionality.
8. Identify existing notification/email functionality.
9. Identify existing visitor/security functionality.
10. Identify existing UI components.
11. Identify reusable components.
12. Identify areas that are already production-ready.
13. Identify areas requiring migration.
14. Identify database changes required.
15. Produce a migration/implementation plan before destructive changes.

## Critical Rule

Do not break existing working functionality simply to implement the new architecture.

Where an existing feature already satisfies a requirement, improve or integrate it rather than replacing it unnecessarily.

---

# Final Production Goal

The finished application should function as a mature Estate Management SaaS platform where:

- Estates are isolated tenants.
- New estate databases start empty.
- Central Admin registration is verified.
- User invitations require real email acceptance.
- Modules are independently managed.
- Subscription plans control module access.
- Super Admin can override module access.
- Pricing is configurable.
- Resident limits affect subscriptions.
- Monthly and annual billing are supported.
- Payment grace periods are enforced.
- Suspended estates retain data for 45 days.
- Permanent deletion is controlled and auditable.
- Super Admin has complete platform visibility.
- Login and audit activity is fully logged.
- Support and onboarding are integrated.
- Marketing and company information are presented publicly.
- Mobile and desktop experiences are polished.
- Security and tenant isolation are treated as production-critical.
- Routine platform configuration does not require code changes.

The implementation should prioritize **security, tenant isolation, maintainability, scalability, usability, auditability, and production stability** over unnecessary visual or architectural changes.
