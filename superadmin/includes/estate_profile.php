<?php
// superadmin/includes/estate_profile.php - single, validated path for editing an estate's profile from the superadmin portal.
// Used by estates.php (quick edit) and estate.php (full edit) so both behave identically.

/**
 * Validate and apply a profile edit. Throws Exception with a user-facing message on bad input.
 * Optional fields (state, country, organisation_type, custom_domain, trial_ends_at) are only touched when present in $in.
 * Status changes go through EstateService::setStatus so suspension/retention timestamps and the tenant mirror stay correct.
 */
function sa_update_estate_profile($platform, $id, array $in) {
    $id = intval($id);
    $cur = Tenancy::find($id);
    if (!$cur) throw new Exception('Estate not found.');

    $name = trim($in['name'] ?? '');
    if ($name === '') throw new Exception('Estate name is required.');

    $email = trim($in['contact_email'] ?? '');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Contact email is not a valid address.');

    $prefix = strtolower(trim($in['domain_prefix'] ?? $cur['domain_prefix']));
    if (!preg_match('/^[a-z0-9]([a-z0-9-]{1,61}[a-z0-9])?$/', $prefix)) {
        throw new Exception('Estate code must be 3-63 characters: lowercase letters, numbers and hyphens only.');
    }
    if ($prefix !== $cur['domain_prefix']) {
        if (!empty($cur['provisioned_at'])) throw new Exception('The estate code cannot be changed after the estate has been provisioned (it is tied to its database and sign-in address).');
        $dup = $platform->prepare('SELECT id FROM estates WHERE domain_prefix = ? AND id <> ? LIMIT 1');
        $dup->bind_param('si', $prefix, $id);
        $dup->execute();
        if ($dup->get_result()->num_rows) throw new Exception("The estate code '$prefix' is already taken.");
    }

    $custom = null;
    if (array_key_exists('custom_domain', $in)) {
        $custom = strtolower(trim($in['custom_domain']));
        if ($custom !== '') {
            if (!preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $custom)) throw new Exception('Custom domain is not a valid hostname.');
            $dup = $platform->prepare('SELECT id FROM estates WHERE custom_domain = ? AND id <> ? LIMIT 1');
            $dup->bind_param('si', $custom, $id);
            $dup->execute();
            if ($dup->get_result()->num_rows) throw new Exception("The domain '$custom' is already used by another estate.");
        }
    }

    $trial = null;
    if (array_key_exists('trial_ends_at', $in) && trim($in['trial_ends_at']) !== '') {
        $ts = strtotime($in['trial_ends_at']);
        if (!$ts) throw new Exception('Trial end date is not a valid date.');
        $trial = date('Y-m-d H:i:s', $ts);
    }

    $sets = ['name = ?', 'domain_prefix = ?', 'contact_email = ?', 'contact_phone = ?', 'city = ?', 'address = ?', 'notes = ?'];
    $types = 'sssssss';
    $vals = [$name, $prefix, $email, trim($in['contact_phone'] ?? ''), trim($in['city'] ?? ''), trim($in['address'] ?? ''), trim($in['notes'] ?? '')];
    foreach (['state', 'country', 'organisation_type'] as $f) {
        if (array_key_exists($f, $in)) { $sets[] = "$f = ?"; $types .= 's'; $vals[] = trim($in[$f]); }
    }
    if ($custom !== null) { $sets[] = 'custom_domain = ?'; $types .= 's'; $vals[] = $custom === '' ? null : $custom; }
    if ($trial !== null) { $sets[] = 'trial_ends_at = ?'; $types .= 's'; $vals[] = $trial; }
    $sets[] = 'updated_at = NOW()';
    $types .= 'i'; $vals[] = $id;

    $st = $platform->prepare('UPDATE estates SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $st->bind_param($types, ...$vals);
    $st->execute();

    $statusChanged = false;
    $newStatus = trim($in['status'] ?? '');
    if ($newStatus !== '' && $newStatus !== $cur['status']) {
        if (!in_array($newStatus, EstateService::ESTATE_STATUSES, true)) throw new Exception('Unknown estate status.');
        EstateService::setStatus($id, $newStatus, [], 'Changed from superadmin estate editor');
        $statusChanged = true;
    }

    Audit::log('estate.profile_updated', [
        'estate_id' => $id, 'module' => 'estates', 'entity_type' => 'estate', 'entity_id' => $id,
        'details' => "Updated estate profile: $name ($prefix)" . ($statusChanged ? " - status {$cur['status']} -> $newStatus" : ''),
        'before' => ['name' => $cur['name'], 'domain_prefix' => $cur['domain_prefix'], 'status' => $cur['status']],
        'after' => ['name' => $name, 'domain_prefix' => $prefix, 'status' => $statusChanged ? $newStatus : $cur['status']],
    ]);
    return $name;
}
