# Estate Management System - Hierarchical Zonal RBAC Upgrade

A single-estate, single-database multi-tiered management application featuring strict **Zonal Isolation** and **Role-Based Access Control (RBAC)**.

---

## 🏛️ System Hierarchy & Architecture

```
                                  ┌────────────────────────┐
                                  │     Central Admin      │
                                  │ (Super Admin/Executive)│
                                  └───────────┬────────────┘
                                              │
                    ┌─────────────────────────┴─────────────────────────┐
                    │                                                   │
        ┌───────────▼────────────┐                          ┌───────────▼────────────┐
        │  Zone 1 Administration  │                          │  Zone 2 Administration  │
        │  (Scoped to Zone #1)   │                          │  (Scoped to Zone #2)   │
        └───────────┬────────────┘                          └───────────┬────────────┘
                    │                                                   │
        ┌───────────┴────────────┐                          ┌───────────┴────────────┐
        │ Streets, Buildings,    │                          │ Streets, Buildings,    │
        │ Flats, Residents,      │                          │ Flats, Residents,      │
        │ Invoices & Levies      │                          │ Invoices & Levies      │
        └────────────────────────┘                          └────────────────────────┘
```

---

## 🏢 Central Admin: Zone Creation & Administration

Central Management has full administrative visibility over the estate, estate-wide settings, security guardhouse, and zone configuration.

### Access:
- **Navigation**: Sidebar &rarr; `Real Estate & Assets` &rarr; `Zones & Sectors` (`admin/zones.php`)
- **Quick Action**: Command Toolbar &rarr; `Zones & Sectors` button on the Admin Dashboard (`admin/index.php`)

### Zone Creation Form Fields:
The Central Admin can create new zones using the **Create New Estate Zone** modal, which captures:
1. **Zone Name** (e.g., `Zone 2 - West Sector`, `Zone 3 - Hilltop Enclave`)
2. **Zone Code** (*Auto-Generated*, e.g., `ZN-01`, `ZN-02`, `ZN-03` with optional unlock/customization)
3. **Official Contact Email** (Official zonal secretariat email, e.g., `zone1@estate.com`)
4. **Official Phone / Helpline** (Zonal office telephone or emergency line)
5. **Zone Motto / Slogan** (e.g., *"Unity, Peace & Neighborhood Progress"*)
6. **Registration Date** (Official commissioning/incorporation date, defaults to current date)
7. **Office / Secretariat Address** (Physical zonal office location within the estate)
8. **Sector Boundaries & Description** (Geographic borders, landmarks, street boundaries)
9. **Status** (`Active` / `Inactive`)
10. **Zone Portal Login Credentials** (*Directly on the creation form*):
    - **Zone Login Email** (Synchronizes automatically with the Zone Official Email)
    - **Zone Login Password** (Default: `zonepass123`, with Show/Hide eye and 1-click password generator)
    - **Admin Display Name** (Defaults to `[Zone Name] Admin`)
    - **Advanced Option**: Assign an existing user account instead if preferred.

---

## 🔒 Zonal RBAC & Strict Data Boundary

Zone Administrators have zero access or visibility outside their assigned sector:
- **Zero Access to Security & Gate Pass**: Blocked from `admin/security.php`, `gate_pass.php`, visitor logs, and guardhouse check-ins.
- **Zero Access to Central Settings**: Cannot modify estate-wide settings, staff management, or other zones.
- **Strict Data Scoping**: All database queries for streets, buildings, flats, residents, invoices, and payments filter strictly by `zone_id = $_SESSION['zone_id']`.

### Dedicated Zonal Portal (`/zone/`):
- **URL**: `http://localhost/Estate/zone/login`
- **Dashboard**: `zone/index.php` (features zonal KPI metrics ribbon, zonal contact banner, motto, recent residents, and invoices).
- **Zonal Streets & Properties**: `zone/properties.php` (manage streets, buildings, and units locked to the zone).
- **Zonal Residents Directory & Multi-Entity Registry**: `zone/residents.php` (replicates the full Central Admin resident onboarding experience with 4 tabs for Residents, Vehicles, Pets, and Domestic Staff; 4-pillar KPI ribbon; multi-entity modal to attach family members, domestic staff, vehicles, and pets in a single onboarding flow; photo uploads with live preview; cascading Street->Building->Flat selection; welcome credentials email dispatch; and `zone/resident_timeline.php` for resident dossiers).
- **Zonal Property Owners**: `zone/owners.php` (view and register property owners with units in the zone).
- **Local Zonal Charges & Dues**: `zone/charges.php` (configure local zonal levies).
- **Zonal Invoicing & Payments**: `zone/finance.php` (generate invoices, record offline payments, print official receipts).
- **Zonal Reports & Analytics**: `zone/reports.php` (occupancy rates, delinquency, street census, financial collection velocity).

---

## 🚀 Portals Overview

| Portal | URL | Authorized Roles |
|---|---|---|
| **Central Admin** | `/admin/login` or `/login` | `superadmin`, `admin` |
| **Zonal Portal** | `/zone/login` or `/login` | `zone_admin` |
| **Resident Portal** | `/resident/login` or `/login` | `resident` |
| **Guardhouse / Gate**| `/admin/security` | `security`, `superadmin` |