<?php
// includes/ModuleRegistry.php - maps application routes to the modules (feature flags) that govern them.
// One enforcement point (requireLogin / API bootstrap) replaces scattered sidebar checks, so a disabled
// module is unreachable by URL, API or any portal. Module metadata and pricing live in the DB, not here.
class ModuleRegistry {
    /** route (relative to app root, no .php) => module key(s). Folder-wide rules use a trailing "/*". */
    private static $map = [
        // Resident management
        'admin/residents' => 'resident_management', 'admin/resident_timeline' => 'resident_management', 'admin/generate_id' => 'resident_management',
        'admin/directory' => 'resident_management', 'admin/archives' => 'resident_management', 'zone/residents' => 'resident_management',
        'zone/resident_timeline' => 'resident_management', 'zone/directory' => 'resident_management', 'zone/archives' => 'resident_management',
        'staff/residents' => 'resident_management', 'staff/directory' => 'resident_management', 'resident/directory' => 'resident_management',
        // Property
        'admin/properties' => 'property_management', 'admin/property_details' => 'property_management', 'admin/owners' => 'property_management',
        'zone/properties' => 'property_management', 'zone/owners' => 'property_management', 'resident/property' => 'property_management',
        // Finance
        'admin/finance' => 'billing_invoicing', 'admin/charges' => 'billing_invoicing', 'admin/billing_config' => 'billing_invoicing', 'admin/receipt' => 'billing_invoicing',
        'zone/finance' => 'billing_invoicing', 'zone/charges' => 'billing_invoicing', 'zone/billing_config' => 'billing_invoicing', 'zone/receipt' => 'billing_invoicing',
        'staff/finance' => 'billing_invoicing', 'resident/finance' => 'billing_invoicing', 'resident/receipt' => 'billing_invoicing', 'resident/receipts' => 'billing_invoicing',
        // Online payments
        'pay_invoice' => 'online_payments', 'verify_payment' => 'online_payments', 'api/payment_methods' => 'online_payments', 'api/resolve_bank' => 'online_payments',
        // Visitors & gate
        'gate_pass' => 'visitor_passes', 'admin/gate_pass' => 'visitor_passes', 'resident/gate_pass' => 'visitor_passes', 'resident/visitors' => 'visitor_passes',
        'staff/security' => 'gate_management',
        'car_sticker' => 'vehicle_registry', 'api/search_vehicle' => 'vehicle_registry',
        // Security
        'admin/security' => 'security_patrol', 'admin/roster' => 'security_patrol', 'admin/incidents' => 'security_patrol', 'staff/roster' => 'security_patrol',
        'staff/incidents' => 'security_patrol', 'api/roster_query' => 'security_patrol', 'api/incident_query' => 'security_patrol',
        'admin/emergency' => 'emergency_sos', 'zone/emergency' => 'emergency_sos', 'resident/emergency' => 'emergency_sos', 'api/emergency' => 'emergency_sos',
        // Operations
        'admin/maintenance' => 'maintenance_complaints', 'staff/maintenance' => 'maintenance_complaints', 'resident/report_issue' => 'maintenance_complaints',
        'admin/staff' => 'staff_management',
        // Communication
        'resident/notices' => 'announcements',
        'admin/broadcasts' => 'broadcast_messaging', 'zone/broadcasts' => 'broadcast_messaging', 'admin/email_logs' => 'broadcast_messaging', 'admin/whatsapp_logs' => 'broadcast_messaging',
        'admin/community_chat' => 'community_chat', 'zone/community_chat' => 'community_chat', 'staff/community_chat' => 'community_chat', 'resident/community_chat' => 'community_chat',
        // Community / governance
        'admin/artisans' => 'artisan_marketplace', 'zone/artisans' => 'artisan_marketplace', 'staff/artisans' => 'artisan_marketplace', 'resident/artisans' => 'artisan_marketplace', 'artisan_register' => 'artisan_marketplace',
        'admin/policies' => 'bylaws_policies', 'zone/policies' => 'bylaws_policies', 'staff/policies' => 'bylaws_policies', 'resident/policies' => 'bylaws_policies',
        'admin/zones' => 'zonal_divisions', 'zone/*' => 'zonal_divisions',
        'zone/reports' => ['zonal_divisions', 'reports_analytics'],
        // Telephony
        'admin/africastalking' => 'ussd_offline_sync', 'api/sync_offline' => 'ussd_offline_sync', 'api/ussd' => 'ussd_offline_sync',
    ];

    public static function currentRoute() {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $root = str_replace('\\', '/', rtrim(parse_url(env_get('APP_URL', 'http://localhost/Estate'), PHP_URL_PATH) ?: '', '/'));
        if ($root !== '' && strpos($script, $root . '/') === 0) $script = substr($script, strlen($root) + 1);
        return preg_replace('/\.php$/', '', ltrim($script, '/'));
    }

    /** @return string[] module keys that must all be enabled for this route */
    public static function modulesFor($route) {
        $keys = [];
        if (isset(self::$map[$route])) $keys = (array)self::$map[$route];
        $dir = strpos($route, '/') !== false ? substr($route, 0, strpos($route, '/')) : '';
        if ($dir !== '' && isset(self::$map[$dir . '/*'])) $keys = array_merge($keys, (array)self::$map[$dir . '/*']);
        return array_values(array_unique($keys));
    }

    /** Enforce all modules governing the current route. */
    public static function enforceCurrent() {
        foreach (self::modulesFor(self::currentRoute()) as $k) requireModule($k);
    }
}
