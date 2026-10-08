<?php
// Seeds the module catalogue and three starter plans. All values (names, prices, limits, inclusion)
// are editable from the Super Admin portal; these are first-install defaults, not code constants.
return function (mysqli $db) {
    // key, name, category, description, purpose, capabilities (|-separated), icon, default monthly price, core, sort
    $modules = [
        ['resident_management', 'Resident Management', 'Community', 'Resident directory, profiles, household and ID cards.', 'Keep an accurate register of everyone living in the estate.', 'Resident profiles|Household members & pets|Resident ID cards|Move-in / move-out history', 'fa-users', 0, 1, 10],
        ['property_management', 'Property & Unit Management', 'Community', 'Streets, buildings, flats, owners and tenancies.', 'Model the estate layout and who owns or occupies each unit.', 'Streets, buildings & flats|Owner and tenant records|Ownership history|Commercial units', 'fa-building', 0, 1, 20],
        ['billing_invoicing', 'Estate Finance', 'Finance', 'Dues, levies, invoices, installments and receipts.', 'Bill residents and track collections without spreadsheets.', 'Recurring charges|Invoices & installments|Receipts|Outstanding balances', 'fa-file-invoice-dollar', 5000, 0, 30],
        ['online_payments', 'Online Payments', 'Finance', 'Residents pay invoices online with card or transfer.', 'Collect dues faster with automatic reconciliation.', 'Paystack checkout|Automatic receipts|Payment verification|Bank account lookup', 'fa-credit-card', 4000, 0, 40],
        ['visitor_passes', 'Visitor Management', 'Security & Access', 'Pre-register visitors and issue gate pass codes.', 'Know who is coming in before they arrive.', 'Visitor passcodes & QR|Check-in / check-out log|Resident approval|Email pass delivery', 'fa-ticket', 4000, 0, 50],
        ['gate_management', 'Gate Management', 'Security & Access', 'Guard console for verifying passes and logging entry.', 'Give security officers a fast, reliable gate tool.', 'Pass verification|Vehicle lookups|Shift gate logs|Offline-tolerant terminal', 'fa-door-open', 3000, 0, 60],
        ['vehicle_registry', 'Vehicle Registry & Stickers', 'Security & Access', 'Resident vehicle records and car stickers.', 'Identify resident vehicles at the gate.', 'Vehicle registration|Sticker issuance|Gate vehicle logs|Plate search', 'fa-car-side', 3000, 0, 70],
        ['security_patrol', 'Security, Roster & Incidents', 'Security & Access', 'Security posts, guard rosters and incident reports.', 'Plan guard coverage and keep an incident trail.', 'Posts & shifts|Guard roster|Incident reporting|Severity tracking', 'fa-user-shield', 5000, 0, 80],
        ['emergency_sos', 'Emergency SOS & Panic Alerts', 'Security & Access', 'One-tap resident panic alerts to guards.', 'Mobilise help in seconds during an emergency.', 'Panic button|Alert categories & actions|Emergency contacts|Alert audit trail', 'fa-bell', 4000, 0, 90],
        ['maintenance_complaints', 'Complaints & Service Requests', 'Operations', 'Residents report issues; technicians resolve them.', 'Track every complaint from report to resolution.', 'Issue reporting|Assignment to technicians|Status tracking|Resident updates', 'fa-screwdriver-wrench', 3000, 0, 100],
        ['announcements', 'Announcements', 'Communication', 'Estate notice board for residents.', 'Share news and notices in one place.', 'Notice board|Targeted notices|Read tracking', 'fa-bullhorn', 2000, 0, 110],
        ['broadcast_messaging', 'Communication & Broadcasts', 'Communication', 'Email, SMS and WhatsApp broadcasts.', 'Reach every resident instantly.', 'Email broadcasts|SMS & WhatsApp|Zone targeting|Delivery logs', 'fa-paper-plane', 5000, 0, 120],
        ['community_chat', 'Community Chat', 'Communication', 'Group chat between residents and management.', 'Keep community conversation organised.', 'Estate chat|Zone chat|Staff chat', 'fa-comments', 2000, 0, 130],
        ['staff_management', 'Staff Management', 'Operations', 'Estate staff accounts, roles and permissions.', 'Delegate work with fine-grained permissions.', 'Staff directory|Custom roles|Permission control|Activity trail', 'fa-id-badge', 3000, 0, 140],
        ['artisan_marketplace', 'Artisan & Service Directory', 'Community', 'Vetted artisans with gate passes.', 'Control which service providers enter the estate.', 'Artisan registration|Approval workflow|Reviews|Gate passes', 'fa-wrench', 3000, 0, 150],
        ['bylaws_policies', 'Bylaws & Policy Penalties', 'Governance', 'Publish estate rules and issue violation penalties.', 'Enforce rules transparently.', 'Policy library|Penalty notices|Fines to invoices|Dispute handling', 'fa-book', 3000, 0, 160],
        ['zonal_divisions', 'Zonal Sub-Committees', 'Governance', 'Zone administrators with their own billing and residents.', 'Delegate large estates into manageable zones.', 'Zone admin accounts|Zonal levies|Zone reports|Zone broadcasts', 'fa-layer-group', 5000, 0, 170],
        ['ussd_offline_sync', 'USSD & Offline Terminal', 'Telephony', 'Africa\'s Talking USSD shortcode and offline sync.', 'Serve residents with no internet.', 'USSD visitor registration|Offline gate terminal|Sync logs', 'fa-tower-cell', 6000, 0, 180],
        ['reports_analytics', 'Reports & Analytics', 'Insights', 'Zone and estate reports.', 'Make data-driven decisions.', 'Operational reports|Finance summaries|Exports', 'fa-chart-line', 3000, 0, 190],
    ];
    $ms = $db->prepare("INSERT IGNORE INTO modules (module_key, name, category, description, purpose, capabilities, icon, default_price, is_core, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)");
    foreach ($modules as $m) {
        $ms->bind_param('sssssssdii', $m[0], $m[1], $m[2], $m[3], $m[4], $m[5], $m[6], $m[7], $m[8], $m[9]);
        $ms->execute();
    }

    // name, slug, desc, monthly, annual, residents, users, storageMB, support, featured, included modules, optional modules
    $all = array_column($modules, 0);
    $core = ['resident_management', 'property_management'];
    $basic = array_merge($core, ['billing_invoicing', 'visitor_passes', 'maintenance_complaints', 'announcements']);
    $pro = array_merge($basic, ['online_payments', 'gate_management', 'vehicle_registry', 'security_patrol', 'emergency_sos', 'community_chat', 'staff_management', 'artisan_marketplace', 'bylaws_policies']);
    $plans = [
        ['Basic', 'basic', 'Essentials for small estates getting organised.', 25000, 250000, 150, 10, 1024, 'email', 0, $basic, array_diff($pro, $basic)],
        ['Professional', 'professional', 'Full security and finance toolkit for growing estates.', 60000, 600000, 500, 30, 5120, 'priority', 1, $pro, array_diff($all, $pro)],
        ['Premium', 'premium', 'Every module, highest capacity and dedicated support.', 120000, 1200000, 2000, 0, 0, 'dedicated', 0, $all, []],
    ];
    $ps = $db->prepare("INSERT IGNORE INTO plans (name, slug, description, monthly_price, annual_price, resident_limit, user_limit, storage_limit_mb, support_level, is_featured, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $pm = $db->prepare("INSERT IGNORE INTO plan_modules (plan_id, module_key, inclusion) VALUES (?,?,?)");
    foreach ($plans as $i => $p) {
        $sort = ($i + 1) * 10;
        $ps->bind_param('sssddiiisii', $p[0], $p[1], $p[2], $p[3], $p[4], $p[5], $p[6], $p[7], $p[8], $p[9], $sort);
        $ps->execute();
        $pid = $db->insert_id;
        if (!$pid) continue; // already seeded
        foreach ($p[10] as $k) { $inc = 'included'; $pm->bind_param('iss', $pid, $k, $inc); $pm->execute(); }
        foreach ($p[11] as $k) { $inc = 'optional'; $pm->bind_param('iss', $pid, $k, $inc); $pm->execute(); }
    }
};
