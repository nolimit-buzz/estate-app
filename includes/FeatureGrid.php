<?php
// includes/FeatureGrid.php - shared grouped-feature card grid (Finance, Properties, Security & Incidents ...)
// used by the Plan builder, the per-estate Modules tab and the estate Roles & Permissions screen.

function feature_grid_css() {
    static $done = false;
    if ($done) return ''; $done = true;
    return '<style>
.fg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
.fg-card{border:1px solid var(--border-color,#d8dce3);border-radius:8px;padding:14px 16px;background:rgba(148,163,184,.05)}
.fg-head{display:flex;justify-content:space-between;align-items:center;font-weight:700;font-size:.78rem;letter-spacing:.04em;text-transform:uppercase;padding-bottom:8px;margin-bottom:10px;border-bottom:1px solid var(--border-color,#d8dce3)}
.fg-head i{color:var(--primary-color,#4f46e5);margin-right:6px}
.fg-head button{border:0;background:none;font-size:.72rem;color:var(--primary-color,#4f46e5);padding:0}
.fg-row{display:flex;gap:8px;align-items:flex-start;margin-bottom:10px;cursor:pointer}
.fg-row input[type=checkbox]{flex:0 0 18px;width:18px;height:18px;min-width:18px;margin:1px 0 0;padding:0;border-radius:4px}
.fg-row>span{flex:1 1 auto;min-width:0}
.fg-name{font-weight:600;font-size:.84rem;line-height:1.2}
.fg-desc{font-size:.72rem;color:var(--text-muted,#6b7280)}
.fg-row.fg-locked{opacity:.4;cursor:not-allowed}
.fg-row.fg-locked .fg-desc::after{content:" \00B7  Not in your plan";font-weight:600}
</style>';
}

/**
 * Render grouped feature cards.
 * $opts: 'name' => checkbox name prefix (feat), 'checked' => [slug=>true], 'locked' => [slug=>true] (faint + disabled),
 *        'value' => callable(feature) => value attribute (default slug), 'extra' => callable(feature) => html appended to row.
 */
function render_feature_grid(array $grouped, array $opts = []) {
    $name = $opts['name'] ?? 'feat';
    $checked = $opts['checked'] ?? [];
    $locked = $opts['locked'] ?? [];
    $valueFn = $opts['value'] ?? null;
    $extraFn = $opts['extra'] ?? null;
    $named = $opts['array_style'] ?? true; // feat[slug] vs feat[]=value
    $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $out = feature_grid_css() . '<div class="fg-grid">';
    foreach ($grouped as $group => $g) {
        $out .= '<div class="fg-card"><div class="fg-head"><span><i class="fa-solid ' . $h($g['icon']) . '"></i>' . $h($group) . '</span>'
              . '<button type="button" onclick="this.closest(\'.fg-card\').querySelectorAll(\'input[type=checkbox]:not(:disabled)\').forEach(function(c){c.checked=!c.checked})">Toggle all</button></div>';
        foreach ($g['features'] as $f) {
            $slug = $f['slug'];
            $isLocked = !empty($locked[$slug]);
            $isChecked = !$isLocked && !empty($checked[$slug]);
            $val = $valueFn ? $valueFn($f) : $slug;
            $fieldName = $named ? $name . '[' . $h($slug) . ']' : $name . '[]';
            $out .= '<label class="fg-row' . ($isLocked ? ' fg-locked' : '') . '"' . ($isLocked ? ' title="Not in your plan"' : '') . '>'
                  . '<input type="checkbox" class="form-check-input" name="' . $fieldName . '" value="' . $h($val) . '"'
                  . ($isChecked ? ' checked' : '') . ($isLocked || !empty($opts['readonly']) ? ' disabled' : '') . '>'
                  . '<span><span class="fg-name">' . $h($f['name']) . '</span><br><span class="fg-desc">' . $h($f['description']) . '</span>'
                  . ($extraFn ? $extraFn($f) : '') . '</span></label>';
        }
        $out .= '</div>';
    }
    return $out . '</div>';
}
