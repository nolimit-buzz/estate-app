<?php
// includes/Entitlements.php - the single source of truth for "may this estate use this module?".
//
// Decision hierarchy (first match wins):
//   1. Module unknown to the catalogue            -> allowed (legacy / unmanaged feature)
//   2. GLOBAL status OFF                          -> DENIED   (nothing can re-enable a globally disabled module)
//   3. ESTATE OVERRIDE (Super Admin) ON / OFF     -> that decision
//   4. Estate lifecycle gates                     -> pending/onboarding: core modules only; trial: trial plan;
//                                                    suspended / retention / deleted: nothing
//   5. PLAN included (grandfathered snapshot)     -> allowed
//   6. Purchased add-on / optional module         -> allowed
//   7. otherwise                                  -> DENIED
require_once __DIR__ . '/Tenancy.php';
require_once __DIR__ . '/PlatformSettings.php';

class Entitlements {
    private static $cache = [];

    public static function flush($estateId = null) { if ($estateId === null) self::$cache = []; else unset(self::$cache[intval($estateId)]); if (class_exists('FeatureCatalog', false)) FeatureCatalog::flush(); }

    /** The plan terms that apply to the estate: grandfathered subscription snapshot, else the live plan. */
    public static function planTerms($estateId) {
        $estateId = intval($estateId);
        $p = Tenancy::platform();
        $r = $p->query("SELECT s.*, e.status AS estate_status FROM subscriptions s JOIN estates e ON e.id = s.estate_id
            WHERE s.estate_id = $estateId AND s.status IN ('active','renewed','grace_period','past_due','suspended','pending_payment') ORDER BY s.id DESC LIMIT 1");
        $sub = $r ? $r->fetch_assoc() : null;
        if (!$sub) return null;
        $snap = $sub['plan_snapshot_json'] ? json_decode($sub['plan_snapshot_json'], true) : null;
        if (!$snap) {
            $snap = Plans::snapshot($sub['plan_id']);
        }
        return ['subscription' => $sub, 'plan' => $snap['plan'] ?? [], 'modules' => $snap['modules'] ?? [], 'features' => $snap['features'] ?? null];
    }

    private static function load($estateId) {
        $estateId = intval($estateId);
        if (isset(self::$cache[$estateId])) return self::$cache[$estateId];
        $p = Tenancy::platform();
        $d = ['catalog' => [], 'overrides' => [], 'plan' => [], 'addons' => [], 'status' => null, 'trial' => []];
        $r = $p->query("SELECT module_key, global_enabled, is_core FROM modules");
        while ($r && $row = $r->fetch_assoc()) $d['catalog'][$row['module_key']] = $row;
        $r = $p->query("SELECT module_key, state FROM estate_module_overrides WHERE estate_id = $estateId");
        while ($r && $row = $r->fetch_assoc()) $d['overrides'][$row['module_key']] = $row['state'];
        $r = $p->query("SELECT module_key FROM estate_addon_modules WHERE estate_id = $estateId");
        while ($r && $row = $r->fetch_assoc()) $d['addons'][$row['module_key']] = true;
        $r = $p->query("SELECT status FROM estates WHERE id = $estateId");
        $d['status'] = ($r && $row = $r->fetch_assoc()) ? $row['status'] : null;

        $terms = self::planTerms($estateId);
        if ($terms) foreach ($terms['modules'] as $m) if (($m['inclusion'] ?? 'included') === 'included') $d['plan'][$m['module_key']] = true;
        if ($d['status'] === 'trial') {
            $slug = PlatformSettings::get('trial_plan_slug', 'professional');
            $tp = Plans::bySlug($slug);
            if ($tp) { $snap = Plans::snapshot($tp['id']); foreach ($snap['modules'] as $m) if ($m['inclusion'] === 'included') $d['trial'][$m['module_key']] = true; }
        }
        return self::$cache[$estateId] = $d;
    }

    /** @return array{allowed:bool,source:string} */
    public static function decide($estateId, $key) {
        $d = self::load($estateId);
        if (!isset($d['catalog'][$key])) return ['allowed' => true, 'source' => 'unmanaged'];
        $m = $d['catalog'][$key];
        if (!intval($m['global_enabled'])) return ['allowed' => false, 'source' => 'global_off'];
        if (isset($d['overrides'][$key])) return ['allowed' => $d['overrides'][$key] === 'on', 'source' => 'override_' . $d['overrides'][$key]];
        $status = $d['status'];
        $supportView = !empty($_SESSION['platform_user_id']) && intval($_SESSION['impersonated_estate_id'] ?? 0) === intval($estateId);
        if (!$supportView && in_array($status, ['suspended', 'retention', 'deletion_pending', 'deleted', 'archived'], true)) return ['allowed' => false, 'source' => 'estate_' . $status];
        if (in_array($status, ['pending', 'onboarding'], true)) return ['allowed' => (bool)intval($m['is_core']), 'source' => 'onboarding_core_only'];
        if ($status === 'trial') return ['allowed' => !empty($d['trial'][$key]) || intval($m['is_core']), 'source' => 'trial_plan'];
        if (!empty($d['plan'][$key])) return ['allowed' => true, 'source' => 'plan'];
        if (!empty($d['addons'][$key])) return ['allowed' => true, 'source' => 'addon'];
        return ['allowed' => false, 'source' => 'not_in_plan'];
    }

    public static function allowed($estateId, $key) { return self::decide($estateId, $key)['allowed']; }

    public static function enabledKeys($estateId) {
        $out = [];
        foreach (array_keys(self::load($estateId)['catalog']) as $k) if (self::allowed($estateId, $k)) $out[] = $k;
        return $out;
    }

    /** Full matrix for UI (global / plan / override / final). */
    public static function matrix($estateId) {
        $d = self::load($estateId);
        $rows = [];
        $r = Tenancy::platform()->query("SELECT * FROM modules ORDER BY sort_order");
        while ($m = $r->fetch_assoc()) {
            $dec = self::decide($estateId, $m['module_key']);
            $rows[] = $m + [
                'in_plan' => !empty($d['plan'][$m['module_key']]),
                'addon' => !empty($d['addons'][$m['module_key']]),
                'override' => $d['overrides'][$m['module_key']] ?? null,
                'allowed' => $dec['allowed'], 'source' => $dec['source'],
            ];
        }
        return $rows;
    }

    // ------------------------------------------------------------------ feature (permission-slug) entitlements
    /** @return array{allowed:bool,source:string} */
    public static function featureDecide($estateId, $slug) {
        $estateId = intval($estateId);
        $cat = FeatureCatalog::all();
        if (!isset($cat[$slug])) return ['allowed' => true, 'source' => 'unmanaged'];
        $f = $cat[$slug];
        $d = self::load($estateId);
        $mod = $f['module_key'];
        if ($mod && isset($d['catalog'][$mod]) && !intval($d['catalog'][$mod]['global_enabled'])) return ['allowed' => false, 'source' => 'global_off'];
        $ov = FeatureCatalog::overrides($estateId);
        if (isset($ov[$slug])) return ['allowed' => $ov[$slug] === 'on', 'source' => 'override_' . $ov[$slug]];
        $status = $d['status'];
        $supportView = !empty($_SESSION['platform_user_id']) && intval($_SESSION['impersonated_estate_id'] ?? 0) === $estateId;
        if (!$supportView && in_array($status, ['suspended', 'retention', 'deletion_pending', 'deleted', 'archived'], true)) return ['allowed' => false, 'source' => 'estate_' . $status];
        if (!$mod) return ['allowed' => true, 'source' => 'core'];
        if (in_array($status, ['pending', 'onboarding'], true)) return ['allowed' => !empty($d['catalog'][$mod]['is_core']), 'source' => 'onboarding_core_only'];
        if ($status === 'trial') {
            $tp = Plans::bySlug(PlatformSettings::get('trial_plan_slug', 'professional'));
            if ($tp && in_array($slug, FeatureCatalog::planFeatures($tp['id']), true)) return ['allowed' => true, 'source' => 'trial_plan'];
            return ['allowed' => !empty($d['catalog'][$mod]['is_core']), 'source' => 'trial_plan'];
        }
        $terms = self::planTerms($estateId);
        if ($terms) {
            $feat = $terms['features'];
            if ($feat === null) { // legacy snapshot: derive from the modules it included
                $incl = [];
                foreach ($terms['modules'] as $m) if (($m['inclusion'] ?? 'included') === 'included') $incl[$m['module_key']] = true;
                if (!empty($incl[$mod])) return ['allowed' => true, 'source' => 'plan'];
            } elseif (in_array($slug, $feat, true)) return ['allowed' => true, 'source' => 'plan'];
        }
        if (!empty($d['addons'][$mod])) return ['allowed' => true, 'source' => 'addon'];
        return ['allowed' => false, 'source' => 'not_in_plan'];
    }

    public static function featureAllowed($estateId, $slug) { return self::featureDecide($estateId, $slug)['allowed']; }
    public static function sourceLabel($s) {
        return [
            'global_off' => 'Disabled globally', 'override_on' => 'Super Admin override: ON', 'override_off' => 'Super Admin override: OFF',
            'plan' => 'Included in plan', 'addon' => 'Purchased add-on', 'not_in_plan' => 'Not in plan', 'trial_plan' => 'Trial plan',
            'onboarding_core_only' => 'Onboarding (core only)', 'unmanaged' => 'Unmanaged', 'core' => 'Core feature',
        ][$s] ?? ucfirst(str_replace('_', ' ', $s));
    }
}

class Plans {
    public static function get($id) {
        $id = intval($id);
        $r = Tenancy::platform()->query("SELECT * FROM plans WHERE id = $id");
        return $r ? $r->fetch_assoc() : null;
    }
    public static function bySlug($slug) {
        $st = Tenancy::platform()->prepare("SELECT * FROM plans WHERE slug = ? LIMIT 1");
        $st->bind_param('s', $slug);
        $st->execute();
        return $st->get_result()->fetch_assoc() ?: null;
    }
    public static function modules($planId) {
        $planId = intval($planId);
        $out = [];
        $r = Tenancy::platform()->query("SELECT pm.module_key, pm.inclusion, COALESCE(pm.module_price, m.default_price) AS price FROM plan_modules pm JOIN modules m ON m.module_key = pm.module_key WHERE pm.plan_id = $planId");
        while ($r && $row = $r->fetch_assoc()) $out[] = $row;
        return $out;
    }
    /** Frozen copy of the plan terms (stored on a subscription so later plan edits never silently change it). */
    public static function snapshot($planId) {
        return ['plan' => self::get($planId), 'modules' => self::modules($planId), 'features' => FeatureCatalog::planFeatures($planId)];
    }
}

/** Grouped feature catalogue (feature = tenant permission slug) shared by plans, estate overrides and the estate roles UI. */
class FeatureCatalog {
    private static $all = null, $overrides = [];

    /** @return array slug => [slug,name,description,group_key,group_name,icon,module_key] */
    public static function all() {
        if (self::$all !== null) return self::$all;
        self::$all = [];
        try {
            $r = Tenancy::platform()->query("SELECT f.slug, f.name, f.description, f.group_key, g.name AS group_name, g.icon, g.module_key
                FROM features f JOIN feature_groups g ON g.group_key = f.group_key ORDER BY g.sort_order, f.sort_order");
            while ($r && $row = $r->fetch_assoc()) self::$all[$row['slug']] = $row;
        } catch (Throwable $e) { /* migration 018 not applied yet: nothing is feature-gated */ }
        return self::$all;
    }

    /** Features grouped for rendering: group_name => ['icon'=>..., 'features'=>[...]] */
    public static function grouped() {
        $out = [];
        foreach (self::all() as $f) {
            $out[$f['group_name']]['icon'] = $f['icon'];
            $out[$f['group_name']]['features'][] = $f;
        }
        return $out;
    }

    public static function planFeatures($planId) {
        $planId = intval($planId); $out = [];
        try {
            $r = Tenancy::platform()->query("SELECT feature_slug FROM plan_features WHERE plan_id = $planId");
            while ($r && $row = $r->fetch_assoc()) $out[] = $row['feature_slug'];
        } catch (Throwable $e) {}
        return $out;
    }

    public static function overrides($estateId) {
        $estateId = intval($estateId);
        if (isset(self::$overrides[$estateId])) return self::$overrides[$estateId];
        $out = [];
        try {
            $r = Tenancy::platform()->query("SELECT feature_slug, state FROM estate_feature_overrides WHERE estate_id = $estateId");
            while ($r && $row = $r->fetch_assoc()) $out[$row['feature_slug']] = $row['state'];
        } catch (Throwable $e) {}
        return self::$overrides[$estateId] = $out;
    }

    public static function flush() { self::$overrides = []; }

    /** Module keys implied by a set of feature slugs (keeps route/sidebar module gating consistent with plan features). */
    public static function modulesFor(array $slugs) {
        $cat = self::all(); $out = [];
        foreach ($slugs as $s) if (!empty($cat[$s]['module_key'])) $out[$cat[$s]['module_key']] = true;
        return array_keys($out);
    }
}