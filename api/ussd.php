<?php
// api/ussd.php - Africa's Talking USSD Gateway Callback Webhook Handler
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/AfricasTalking.php';
require_once __DIR__ . '/../includes/Mailer.php';

// Ensure tables exist
EstateAfricasTalking::ensureDatabaseTables($conn);

// Set Plain Text Content-Type as required by Africa's Talking USSD protocol
header("Content-Type: text/plain; charset=UTF-8");

// Fetch POST parameters sent by Africa's Talking Telecom Gateway
$sessionId   = trim($_POST['sessionId'] ?? ($_GET['sessionId'] ?? ''));
$serviceCode = trim($_POST['serviceCode'] ?? ($_GET['serviceCode'] ?? ''));
$phoneNumber = trim($_POST['phoneNumber'] ?? ($_GET['phoneNumber'] ?? ''));
$text        = trim($_POST['text'] ?? ($_GET['text'] ?? ''));
$networkCode = trim($_POST['networkCode'] ?? ($_GET['networkCode'] ?? ''));

// Fallback for JSON requests if tested via API clients
if (empty($sessionId)) {
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $json = json_decode($rawInput, true);
        if (is_array($json)) {
            $sessionId   = trim($json['sessionId'] ?? '');
            $serviceCode = trim($json['serviceCode'] ?? '');
            $phoneNumber = trim($json['phoneNumber'] ?? '');
            $text        = trim($json['text'] ?? '');
            $networkCode = trim($json['networkCode'] ?? '');
        }
    }
}

if (empty($sessionId) || empty($phoneNumber)) {
    echo "END Invalid USSD request parameters.";
    exit;
}

// Global Estate ID Context
$estate_id = function_exists('get_estate_id') ? get_estate_id() : 1;
$estate_branding = get_estate_branding($conn, $estate_id);
$estate_name = $estate_branding['estate_name'] ?? 'Estate Admin';

// Check if USSD is enabled in system_settings
$at_config = EstateAfricasTalking::getConfig($conn, $estate_id);
if (!$at_config['enabled']) {
    $resp = "END " . $estate_name . " USSD service is currently undergoing scheduled maintenance. Please contact estate management.";
    EstateAfricasTalking::logUSSD($conn, $estate_id, $sessionId, $phoneNumber, $serviceCode, $networkCode, $text, 'END', $resp);
    echo $resp;
    exit;
}

// -----------------------------------------------------------------------------
// USER IDENTIFICATION & ROLE RESOLUTION
// -----------------------------------------------------------------------------
// Normalize incoming phone for database matching
$phoneVariants = [];
$cleanDigits = preg_replace('/[^0-9]/', '', $phoneNumber);
$phoneVariants[] = $conn->real_escape_string($phoneNumber);
$phoneVariants[] = $conn->real_escape_string($cleanDigits);
if (strlen($cleanDigits) >= 10) {
    $phoneVariants[] = $conn->real_escape_string(substr($cleanDigits, -10)); // last 10 digits
    $phoneVariants[] = $conn->real_escape_string('0' . substr($cleanDigits, -10)); // local 080...
    $phoneVariants[] = $conn->real_escape_string('+234' . substr($cleanDigits, -10)); // international
    $phoneVariants[] = $conn->real_escape_string('234' . substr($cleanDigits, -10));
}
$phoneVariants = array_unique($phoneVariants);
$phoneListSql = "'" . implode("','", $phoneVariants) . "'";

// Find user in database
$userQuery = "SELECT u.*, r.id as resident_id, r.flat_id, r.type as resident_type, 
                     f.number as flat_number, b.name as building_name
              FROM users u
              LEFT JOIN residents r ON u.id = r.user_id AND r.estate_id = $estate_id AND r.status = 'active'
              LEFT JOIN flats f ON r.flat_id = f.id
              LEFT JOIN buildings b ON f.building_id = b.id
              WHERE u.estate_id = $estate_id AND u.status = 'active' AND (
                  u.phone IN ($phoneListSql)
                  OR REPLACE(REPLACE(u.phone, ' ', ''), '-', '') IN ($phoneListSql)
              )
              ORDER BY (CASE WHEN u.role = 'security' THEN 1 WHEN r.id IS NOT NULL THEN 2 ELSE 3 END) ASC
              LIMIT 1";

$userRes = $conn->query($userQuery);
$user = ($userRes && $userRes->num_rows > 0) ? $userRes->fetch_assoc() : null;

// Determine Role
$isResident = false;
$isSecurity = false;
$userName   = 'Resident';

if ($user) {
    $userName = !empty($user['first_name']) ? $user['first_name'] : (!empty($user['name']) ? explode(' ', $user['name'])[0] : 'User');
    if ($user['role'] === 'security' || $user['role'] === 'staff') {
        $isSecurity = true;
    } elseif (!empty($user['resident_id']) || $user['role'] === 'resident') {
        $isResident = true;
    }
}

// Break down the USSD input string (e.g., "1*John Doe*08012345678" -> array)
$inputs = ($text === '') ? [] : explode('*', $text);
$level  = count($inputs);

$response = "";

// =============================================================================
// ROUTE 1: RESIDENT USSD MENU
// =============================================================================
if ($isResident) {
    $resident_id = intval($user['resident_id'] ?? 0);
    $flat_id = intval($user['flat_id'] ?? 0);
    $flat_desc = trim(($user['building_name'] ?? '') . ' - ' . ($user['flat_number'] ?? 'Unit'));

    if ($level === 0) {
        // Main Resident Menu
        $response = "CON Welcome $userName to " . substr($estate_name, 0, 15) . "\n";
        $response .= "1. Create Visitor Pass\n";
        $response .= "2. Quick Cab / Delivery\n";
        $response .= "3. Emergency SOS Panic\n";
        $response .= "4. Check Outstanding Bills\n";
        $response .= "5. My Flat & Gate Hotline";
    } 
    // --- OPTION 1: CREATE VISITOR GATE PASS ---
    elseif ($inputs[0] === '1') {
        if ($level === 1) {
            $response = "CON Enter Visitor's Full Name:";
        } elseif ($level === 2) {
            $response = "CON Enter Visitor's Phone Number:\n(Or 0 to skip phone)";
        } elseif ($level === 3) {
            $visitorName = trim($inputs[1]);
            $visitorPhone = trim($inputs[2]);
            if ($visitorPhone === '0') $visitorPhone = '';

            // Generate secure 6-digit access code
            do {
                $visitorCode = strval(mt_rand(100000, 999999));
                $chkCode = $conn->query("SELECT id FROM visitors WHERE visitor_code = '$visitorCode' AND status IN ('pre_registered', 'entered') LIMIT 1");
            } while ($chkCode && $chkCode->num_rows > 0);

            $visNameEsc = $conn->real_escape_string($visitorName);
            $visPhoneEsc = $conn->real_escape_string($visitorPhone);
            $userIdVal = intval($user['id']);

            $insSql = "INSERT INTO visitors (estate_id, resident_id, flat_id, name, phone, visitor_code, purpose, status, expected_arrival)
                       VALUES ($estate_id, " . ($resident_id ?: "NULL") . ", " . ($flat_id ?: "NULL") . ", '$visNameEsc', '$visPhoneEsc', '$visitorCode', 'USSD Resident Pass', 'pre_registered', NOW())";

            if ($conn->query($insSql)) {
                $newVisId = $conn->insert_id;

                // Send instant SMS via Africa's Talking to Visitor if phone provided
                if (!empty($visitorPhone) && $at_config['sms_enabled'] && $at_config['notify_pass_on_ussd']) {
                    $smsBody = "GATE PASS [{$estate_name}]: Your access code for visiting {$userName} is: {$visitorCode}. Valid for 24 hours. Present to security gate.";
                    EstateAfricasTalking::sendSMS($conn, $visitorPhone, $smsBody, $estate_id);
                }

                // Send instant confirmation SMS to Host Resident as well
                if ($at_config['sms_enabled'] && $at_config['notify_pass_on_ussd']) {
                    $resSms = "PASS GENERATED: Code {$visitorCode} created for {$visitorName}. Valid 24hrs at {$estate_name} gate.";
                    EstateAfricasTalking::sendSMS($conn, $phoneNumber, $resSms, $estate_id);
                }

                logAudit($conn, "USSD Pass Created", "Security", "Resident {$userName} (Phone: {$phoneNumber}) generated pass {$visitorCode} for visitor {$visitorName} via USSD.");

                $response = "END Gate Pass Created!\n";
                $response .= "Visitor: $visitorName\n";
                $response .= "Code: $visitorCode\n";
                $response .= "Valid: 24 Hours\n";
                $response .= (!empty($visitorPhone) ? "SMS sent to visitor." : "Share code with guest.");
            } else {
                $response = "END System error generating gate pass. Please try again or use the resident web portal.";
            }
        }
    }
    // --- OPTION 2: QUICK CAB / DELIVERY PASS ---
    elseif ($inputs[0] === '2') {
        if ($level === 1) {
            $response = "CON Select Quick Pass Type:\n";
            $response .= "1. Uber / Bolt / Taxi\n";
            $response .= "2. Food / Parcel Courier\n";
            $response .= "3. Service Artisan";
        } elseif ($level === 2) {
            $typeMap = [
                '1' => ['Cab / Ride Hail', 'Quick Ride Drop-off'],
                '2' => ['Courier Delivery', 'Package Delivery'],
                '3' => ['Artisan Service', 'Maintenance Service']
            ];
            $selectedType = $typeMap[$inputs[1]] ?? ['Quick Guest', 'Drop-off'];

            do {
                $quickCode = strval(mt_rand(100000, 999999));
                $chk = $conn->query("SELECT id FROM visitors WHERE visitor_code = '$quickCode' AND status IN ('pre_registered', 'entered') LIMIT 1");
            } while ($chk && $chk->num_rows > 0);

            $qName = $conn->real_escape_string($selectedType[0]);
            $qPurp = $conn->real_escape_string($selectedType[1]);

            $conn->query("INSERT INTO visitors (estate_id, resident_id, flat_id, name, visitor_code, purpose, status, expected_arrival)
                          VALUES ($estate_id, " . ($resident_id ?: "NULL") . ", " . ($flat_id ?: "NULL") . ", '$qName', '$quickCode', '$qPurp', 'pre_registered', NOW())");

            logAudit($conn, "USSD Quick Pass", "Security", "Resident {$userName} generated 2-hour {$selectedType[0]} pass {$quickCode} via USSD.");

            $response = "END Quick Pass Created!\n";
            $response .= "Type: {$selectedType[0]}\n";
            $response .= "Passcode: $quickCode\n";
            $response .= "Valid for 2 Hours.\n";
            $response .= "Driver presents code at gate.";
        }
    }
    // --- OPTION 3: EMERGENCY SOS PANIC ALERT ---
    elseif ($inputs[0] === '3') {
        if ($level === 1) {
            $response = "CON EMERGENCY SOS ALERT\n";
            $response .= "Select Emergency Category:\n";
            $response .= "1. Security / Intruder Threat\n";
            $response .= "2. Medical Emergency\n";
            $response .= "3. Fire Outbreak\n";
            $response .= "4. Cancel";
        } elseif ($level === 2) {
            if ($inputs[1] === '4') {
                $response = "END Emergency alert cancelled.";
            } else {
                $catMap = [
                    '1' => 'Security Threat',
                    '2' => 'Medical Emergency',
                    '3' => 'Fire Outbreak'
                ];
                $catName = $catMap[$inputs[1]] ?? 'Security Emergency';
                $alertCode = 'SOS-' . strtoupper(substr(uniqid(), -6));
                $headline = "USSD EMERGENCY ALERT from " . ($user['flat_number'] ?? 'Unit');
                $senderPhoneEsc = $conn->real_escape_string($phoneNumber);
                $userNameEsc = $conn->real_escape_string($user['name'] ?? $userName);
                $bldgEsc = $conn->real_escape_string($user['building_name'] ?? '');
                $flatNoEsc = $conn->real_escape_string($user['flat_number'] ?? '');
                $userIdVal = intval($user['id']);

                $conn->query("INSERT INTO estate_emergency_alerts (
                    estate_id, alert_code, sender_type, sender_id, sender_name, sender_phone,
                    flat_id, building_name, flat_number, category_name, headline, note, status, sound_alarm
                ) VALUES (
                    $estate_id, '$alertCode', 'resident', $userIdVal, '$userNameEsc', '$senderPhoneEsc',
                    " . ($flat_id ?: "NULL") . ", '$bldgEsc', '$flatNoEsc', '$catName', '$headline',
                    'High priority alert triggered by resident via GSM USSD phone dialer.', 'active', 1
                )");

                // Dispatch SMS Alert to Estate Security Hotline/Duty Guards
                if ($at_config['sms_enabled'] && $at_config['notify_emergency_on_ussd']) {
                    $guardNumbersRes = $conn->query("SELECT phone FROM users WHERE estate_id = $estate_id AND role = 'security' AND status = 'active' AND phone IS NOT NULL AND phone != '' LIMIT 3");
                    if ($guardNumbersRes) {
                        while ($gRow = $guardNumbersRes->fetch_assoc()) {
                            $gSms = "URGENT SOS! {$catName} reported at {$bldgEsc} Flat {$flatNoEsc} by {$userName} ({$phoneNumber}). Respond immediately!";
                            EstateAfricasTalking::sendSMS($conn, $gRow['phone'], $gSms, $estate_id);
                        }
                    }
                }

                logAudit($conn, "USSD SOS Triggered", "Emergency", "Resident {$userName} fired {$catName} SOS from flat {$flatNoEsc} via USSD.");

                $response = "END EMERGENCY DISPATCHED!\n";
                $response .= "Category: $catName\n";
                $response .= "Unit: Flat $flatNoEsc\n";
                $response .= "Gate guards and patrol unit have been mobilized. Stay in a safe position.";
            }
        }
    }
    // --- OPTION 4: CHECK OUTSTANDING BILLS ---
    elseif ($inputs[0] === '4') {
        $invRes = $conn->query("SELECT COUNT(*) as unpaid_count, COALESCE(SUM(total_amount - amount_paid), 0) as total_due 
                                FROM invoices 
                                WHERE estate_id = $estate_id AND (flat_id = $flat_id OR resident_id = $resident_id) AND status != 'paid'");
        $due = ($invRes) ? $invRes->fetch_assoc() : ['unpaid_count' => 0, 'total_due' => 0];
        $totalDue = floatval($due['total_due'] ?? 0);
        $unpaidCnt = intval($due['unpaid_count'] ?? 0);

        $currency = 'NGN';
        $currRes = $conn->query("SELECT setting_value FROM system_settings WHERE estate_id = $estate_id AND setting_key = 'currency_symbol' LIMIT 1");
        if ($currRes && $cRow = $currRes->fetch_assoc()) $currency = $cRow['setting_value'] ?: 'NGN';

        $response = "END Billing Summary:\n";
        $response .= "Flat: " . ($user['flat_number'] ?? 'Unit') . "\n";
        $response .= "Unpaid Invoices: $unpaidCnt\n";
        $response .= "Total Due: " . $currency . " " . number_format($totalDue, 2) . "\n";
        $response .= ($totalDue > 0 ? "Pay via resident web portal or estate account." : "Your account is fully in good standing!");
    }
    // --- OPTION 5: MY FLAT & GATE HOTLINE ---
    elseif ($inputs[0] === '5') {
        $secPhoneRes = $conn->query("SELECT setting_value FROM system_settings WHERE estate_id = $estate_id AND setting_key = 'office_phone' LIMIT 1");
        $hotline = ($secPhoneRes && $spRow = $secPhoneRes->fetch_assoc()) ? $spRow['setting_value'] : '0800-ESTATE-SEC';

        $response = "END Estate Contact & Info:\n";
        $response .= "Resident: $userName\n";
        $response .= "Unit: " . ($user['building_name'] ?? 'Block') . " " . ($user['flat_number'] ?? '') . "\n";
        $response .= "Gate Control: $hotline\n";
        $response .= "Powered by " . ($estate_branding['app_company_name'] ?? 'Estate Platform');
    } else {
        $response = "END Invalid option selected. Please redial.";
    }
}

// =============================================================================
// ROUTE 2: SECURITY GUARD / GATE STAFF USSD MENU
// =============================================================================
elseif ($isSecurity) {
    if ($level === 0) {
        $response = "CON " . substr($estate_name, 0, 15) . " Security Desk\n";
        $response .= "Officer: $userName\n";
        $response .= "1. Verify & Check-In Pass\n";
        $response .= "2. Register Walk-In Visitor\n";
        $response .= "3. Process Departure Exit\n";
        $response .= "4. Verify Resident Vehicle\n";
        $response .= "5. Check Passcode Info";
    }
    // --- OPTION 1: VERIFY & CHECK-IN PASS ---
    elseif ($inputs[0] === '1') {
        if ($level === 1) {
            $response = "CON Enter 6-digit Visitor Passcode:";
        } elseif ($level === 2) {
            $passcode = strtoupper(trim($inputs[1]));
            $passEsc = $conn->real_escape_string($passcode);

            $vRes = $conn->query("SELECT v.*, f.number as flat_number, b.name as building_name, u.name as resident_name, u.phone as res_phone 
                                  FROM visitors v
                                  LEFT JOIN flats f ON v.flat_id = f.id
                                  LEFT JOIN buildings b ON f.building_id = b.id
                                  LEFT JOIN residents r ON v.resident_id = r.id
                                  LEFT JOIN users u ON r.user_id = u.id
                                  WHERE v.estate_id = $estate_id AND (v.visitor_code = '$passEsc' OR v.id = " . intval($passcode) . ")
                                  LIMIT 1");

            if ($vRes && $vRes->num_rows > 0) {
                $vis = $vRes->fetch_assoc();
                $vId = intval($vis['id']);

                if (in_array($vis['status'], ['pre_registered', 'confirmed'])) {
                    // Perform Entry Check-In
                    $guardUid = intval($user['id']);
                    $conn->query("UPDATE visitors SET 
                                  status = 'entered', 
                                  entry_time = NOW(), 
                                  entry_processed_by = $guardUid, 
                                  entry_gate = 'GSM USSD Gate',
                                  guard_notes = 'Checked in via Africa\'s Talking USSD'
                                  WHERE id = $vId AND estate_id = $estate_id");

                    // Notify resident via email/alert
                    EstateMailer::sendVisitorArrivalAlert($conn, $vId, 'GSM USSD Gate');

                    logAudit($conn, "USSD Check-In", "Security", "Officer {$userName} checked in visitor {$vis['name']} (Code: $passcode) via USSD.");

                    $response = "END PASS APPROVED & CHECKED IN!\n";
                    $response .= "Visitor: {$vis['name']}\n";
                    $response .= "Visiting: " . ($vis['resident_name'] ?: 'Resident') . "\n";
                    $response .= "Destination: " . ($vis['building_name'] ?: '') . " " . ($vis['flat_number'] ?: 'Unit') . "\n";
                    $response .= "Status: Entry Recorded.";
                } elseif ($vis['status'] === 'entered') {
                    $entryTime = !empty($vis['entry_time']) ? date('H:i, d M', strtotime($vis['entry_time'])) : 'earlier';
                    $response = "END PASS ALREADY ACTIVE!\nVisitor {$vis['name']} already checked in at {$entryTime}.\nFlat: " . ($vis['flat_number'] ?: '');
                } elseif ($vis['status'] === 'checked_out') {
                    $response = "END EXPIRED PASS: Visitor {$vis['name']} has already checked out and departed.";
                } else {
                    $response = "END PASS INACTIVE: Passcode status is '{$vis['status']}'. Access denied.";
                }
            } else {
                $response = "END INVALID PASSCODE: Code '$passcode' not found in system. Please verify with host.";
            }
        }
    }
    // --- OPTION 2: REGISTER WALK-IN VISITOR & GENERATE PASS CODE ---
    elseif ($inputs[0] === '2') {
        if ($level === 1) {
            $response = "CON Enter Visitor's Full Name:";
        } elseif ($level === 2) {
            $response = "CON Enter Visitor's Phone:\n(Or 0 to skip phone)";
        } elseif ($level === 3) {
            $response = "CON Enter Destination Flat No:\n(e.g. 101, 204, B2)";
        } elseif ($level === 4) {
            $response = "CON Select Purpose of Visit:\n";
            $response .= "1. Personal Guest\n";
            $response .= "2. Delivery / Dispatch\n";
            $response .= "3. Artisan / Service\n";
            $response .= "4. Cab / Uber Drop-off";
        } elseif ($level === 5) {
            $visName = trim($inputs[1]);
            $visPhone = trim($inputs[2]);
            if ($visPhone === '0') $visPhone = '';
            $flatInput = trim($inputs[3]);
            $flatEsc = $conn->real_escape_string($flatInput);

            $purposeMap = [
                '1' => 'Personal Guest',
                '2' => 'Delivery / Dispatch',
                '3' => 'Artisan / Service',
                '4' => 'Cab / Uber Drop-off'
            ];
            $purpose = $purposeMap[$inputs[4]] ?? 'Walk-In Guest';

            // Lookup flat and resident
            $fRes = $conn->query("SELECT f.id as flat_id, f.number as flat_number, b.name as building_name, 
                                         r.id as resident_id, u.name as resident_name, u.phone as resident_phone
                                  FROM flats f 
                                  LEFT JOIN buildings b ON f.building_id = b.id 
                                  LEFT JOIN residents r ON r.flat_id = f.id AND r.estate_id = $estate_id AND r.status = 'active'
                                  LEFT JOIN users u ON r.user_id = u.id 
                                  WHERE f.estate_id = $estate_id AND (f.number = '$flatEsc' OR f.custom_id = '$flatEsc' OR f.number LIKE '%$flatEsc%') 
                                  ORDER BY (CASE WHEN f.number = '$flatEsc' THEN 1 ELSE 2 END) ASC
                                  LIMIT 1");

            $flatId = null;
            $residentId = null;
            $hostName = 'Resident';
            $hostPhone = '';
            $flatDisplay = "Flat $flatInput";

            if ($fRes && $fRes->num_rows > 0) {
                $fRow = $fRes->fetch_assoc();
                $flatId = intval($fRow['flat_id']);
                $residentId = !empty($fRow['resident_id']) ? intval($fRow['resident_id']) : null;
                $hostName = $fRow['resident_name'] ?: 'Resident';
                $hostPhone = $fRow['resident_phone'] ?: '';
                $flatDisplay = ($fRow['building_name'] ? $fRow['building_name'] . ' ' : '') . "Flat " . $fRow['flat_number'];
            }

            // Generate unique 6-digit access code
            do {
                $visitorCode = strval(mt_rand(100000, 999999));
                $chkCode = $conn->query("SELECT id FROM visitors WHERE visitor_code = '$visitorCode' AND status IN ('pre_registered', 'entered') LIMIT 1");
            } while ($chkCode && $chkCode->num_rows > 0);

            $guardUid = intval($user['id']);
            $visNameEsc = $conn->real_escape_string($visName);
            $visPhoneEsc = $conn->real_escape_string($visPhone);
            $purposeEsc = $conn->real_escape_string($purpose);
            $notesEsc = $conn->real_escape_string("Walk-in registered by Officer {$userName} via GSM USSD for {$flatDisplay}");

            $insSql = "INSERT INTO visitors (estate_id, resident_id, flat_id, name, phone, purpose, visitor_code, status, entry_time, entry_gate, guard_notes, entry_processed_by, created_at)
                       VALUES ($estate_id, " . ($residentId ?: "NULL") . ", " . ($flatId ?: "NULL") . ", '$visNameEsc', '$visPhoneEsc', '$purposeEsc', '$visitorCode', 'entered', NOW(), 'GSM USSD Gate', '$notesEsc', $guardUid, NOW())";

            if ($conn->query($insSql)) {
                $newVisId = $conn->insert_id;

                // Send instant arrival SMS alert to host resident
                if (!empty($hostPhone) && $at_config['sms_enabled'] && $at_config['notify_pass_on_ussd']) {
                    $hostSms = "GATE ENTRY ALERT [{$estate_name}]: Walk-in visitor '{$visName}' was registered & cleared by Officer {$userName}. Passcode: {$visitorCode}.";
                    EstateAfricasTalking::sendSMS($conn, $hostPhone, $hostSms, $estate_id);
                }

                // Send SMS to visitor if phone provided
                if (!empty($visPhone) && $at_config['sms_enabled'] && $at_config['notify_pass_on_ussd']) {
                    $visSms = "GATE PASS [{$estate_name}]: Your access code is {$visitorCode} for {$flatDisplay}. Checked in by security.";
                    EstateAfricasTalking::sendSMS($conn, $visPhone, $visSms, $estate_id);
                }

                logAudit($conn, "USSD Walk-In Entry", "Security", "Officer {$userName} registered & checked in visitor {$visName} (Code: {$visitorCode}) to {$flatDisplay} via USSD.");

                $response = "END VISITOR REGISTERED & ENTERED!\n";
                $response .= "Code: $visitorCode\n";
                $response .= "Visitor: $visName\n";
                $response .= "Destination: $flatDisplay\n";
                $response .= "Host: $hostName\n";
                $response .= "Status: Checked In.";
            } else {
                $response = "END Error creating pass. Please try again.";
            }
        }
    }
    // --- OPTION 3: PROCESS VISITOR EXIT / DEPARTURE ---
    elseif ($inputs[0] === '3') {
        if ($level === 1) {
            $response = "CON Enter Visitor Passcode to Check-Out:";
        } elseif ($level === 2) {
            $passcode = strtoupper(trim($inputs[1]));
            $passEsc = $conn->real_escape_string($passcode);

            $vRes = $conn->query("SELECT * FROM visitors WHERE estate_id = $estate_id AND (visitor_code = '$passEsc' OR id = " . intval($passcode) . ") LIMIT 1");
            if ($vRes && $vRes->num_rows > 0) {
                $vis = $vRes->fetch_assoc();
                $vId = intval($vis['id']);

                if ($vis['status'] === 'entered') {
                    $guardUid = intval($user['id']);
                    $conn->query("UPDATE visitors SET 
                                  status = 'checked_out', 
                                  exit_time = NOW(), 
                                  exit_processed_by = $guardUid, 
                                  exit_gate = 'GSM USSD Gate',
                                  exit_reason = 'Departed via USSD Gate'
                                  WHERE id = $vId AND estate_id = $estate_id");

                    logAudit($conn, "USSD Check-Out", "Security", "Officer {$userName} checked out visitor {$vis['name']} via USSD.");

                    $response = "END CHECK-OUT RECORDED\n";
                    $response .= "Visitor: {$vis['name']}\n";
                    $response .= "Exit Time: " . date('H:i') . "\n";
                    $response .= "Passcode closed successfully.";
                } else {
                    $response = "END NOTICE: Visitor is currently listed as '{$vis['status']}', not inside estate.";
                }
            } else {
                $response = "END INVALID CODE: Passcode not found.";
            }
        }
    }
    // --- OPTION 4: VERIFY RESIDENT VEHICLE ---
    elseif ($inputs[0] === '4') {
        if ($level === 1) {
            $response = "CON Enter Vehicle Plate Number:\n(e.g. ABC123XY)";
        } elseif ($level === 2) {
            $plate = strtoupper(trim(str_replace(' ', '', $inputs[1])));
            $plateEsc = $conn->real_escape_string($plate);

            $vehRes = $conn->query("SELECT v.*, f.number as flat_number, b.name as building_name, u.name as resident_name
                                    FROM vehicles v
                                    LEFT JOIN flats f ON v.flat_id = f.id
                                    LEFT JOIN buildings b ON f.building_id = b.id
                                    LEFT JOIN residents r ON r.flat_id = f.id AND r.estate_id = $estate_id
                                    LEFT JOIN users u ON r.user_id = u.id
                                    WHERE v.estate_id = $estate_id AND (
                                        REPLACE(v.reg_number, ' ', '') = '$plateEsc'
                                        OR REPLACE(COALESCE(v.sticker_number, ''), ' ', '') = '$plateEsc'
                                    ) LIMIT 1");

            if ($vehRes && $vehRes->num_rows > 0) {
                $veh = $vehRes->fetch_assoc();
                $response = "END APPROVED RESIDENT VEHICLE\n";
                $response .= "Plate: {$veh['reg_number']}\n";
                $response .= "Model: {$veh['model']} ({$veh['color']})\n";
                $response .= "Unit: " . ($veh['building_name'] ?: '') . " Flat " . ($veh['flat_number'] ?: '') . "\n";
                $response .= "Status: REGISTERED & CLEAR";
            } else {
                $response = "END UNREGISTERED VEHICLE\nPlate '$plate' is not registered in estate registry.\nDirect driver to security clearance.";
            }
        }
    }
    // --- OPTION 5: CHECK PASSCODE INFO ---
    elseif ($inputs[0] === '5') {
        if ($level === 1) {
            $response = "CON Enter Passcode to Look Up:";
        } elseif ($level === 2) {
            $passcode = strtoupper(trim($inputs[1]));
            $passEsc = $conn->real_escape_string($passcode);

            $vRes = $conn->query("SELECT v.*, f.number as flat_number, u.name as resident_name 
                                  FROM visitors v
                                  LEFT JOIN flats f ON v.flat_id = f.id
                                  LEFT JOIN residents r ON v.resident_id = r.id
                                  LEFT JOIN users u ON r.user_id = u.id
                                  WHERE v.estate_id = $estate_id AND (v.visitor_code = '$passEsc' OR v.id = " . intval($passcode) . ") LIMIT 1");

            if ($vRes && $vRes->num_rows > 0) {
                $vis = $vRes->fetch_assoc();
                $response = "END PASSCODE STATUS\n";
                $response .= "Visitor: {$vis['name']}\n";
                $response .= "Host: " . ($vis['resident_name'] ?: 'Resident') . " (" . ($vis['flat_number'] ?: 'Unit') . ")\n";
                $response .= "Status: " . strtoupper($vis['status']) . "\n";
                $response .= "Expected: " . date('d M, H:i', strtotime($vis['expected_arrival'] ?: $vis['created_at']));
            } else {
                $response = "END Passcode not found in records.";
            }
        }
    } else {
        $response = "END Invalid option selected.";
    }
}

// =============================================================================
// ROUTE 3: UNREGISTERED / GUEST / PUBLIC CALLER
// =============================================================================
else {
    if ($level === 0) {
        $response = "CON Welcome to $estate_name\n";
        $response .= "1. Verify My Gate Pass Code\n";
        $response .= "2. Register as Walk-In Guest\n";
        $response .= "3. Security Emergency Desk\n";
        $response .= "4. Estate Office & Enquiries";
    } elseif ($inputs[0] === '1') {
        if ($level === 1) {
            $response = "CON Enter your 6-digit Gate Pass Code:";
        } elseif ($level === 2) {
            $code = strtoupper(trim($inputs[1]));
            $codeEsc = $conn->real_escape_string($code);

            $vRes = $conn->query("SELECT v.*, f.number as flat_number, b.name as building_name, u.name as host_name 
                                  FROM visitors v
                                  LEFT JOIN flats f ON v.flat_id = f.id
                                  LEFT JOIN buildings b ON f.building_id = b.id
                                  LEFT JOIN residents r ON v.resident_id = r.id
                                  LEFT JOIN users u ON r.user_id = u.id
                                  WHERE v.estate_id = $estate_id AND v.visitor_code = '$codeEsc' LIMIT 1");

            if ($vRes && $vRes->num_rows > 0) {
                $vis = $vRes->fetch_assoc();
                if (in_array($vis['status'], ['pre_registered', 'confirmed'])) {
                    $response = "END PASS IS VALID & ACTIVE!\n";
                    $response .= "Guest: {$vis['name']}\n";
                    $response .= "Host: " . ($vis['host_name'] ?: 'Resident') . "\n";
                    $response .= "Destination: " . ($vis['building_name'] ?: '') . " " . ($vis['flat_number'] ?: '') . "\n";
                    $response .= "Present this code at Main Gate.";
                } else {
                    $response = "END PASS INACTIVE: Status is '{$vis['status']}'. Contact your host for a fresh code.";
                }
            } else {
                $response = "END INVALID CODE: Passcode '$code' not recognized. Please confirm with your host.";
            }
        }
    } elseif ($inputs[0] === '2') {
        // Unregistered caller registers as walk-in guest
        if ($level === 1) {
            $response = "CON Enter Your Full Name:";
        } elseif ($level === 2) {
            $response = "CON Enter Destination Flat No:\n(e.g. 101, 204, B4)";
        } elseif ($level === 3) {
            $response = "CON Purpose of Visit:\n";
            $response .= "1. Personal Guest\n";
            $response .= "2. Delivery / Dispatch\n";
            $response .= "3. Artisan / Service";
        } elseif ($level === 4) {
            $guestName = trim($inputs[1]);
            $flatInput = trim($inputs[2]);
            $flatEsc = $conn->real_escape_string($flatInput);

            $purposeMap = [
                '1' => 'Personal Guest',
                '2' => 'Delivery / Dispatch',
                '3' => 'Artisan / Service'
            ];
            $purpose = $purposeMap[$inputs[3]] ?? 'Guest Visit';

            // Flat lookup
            $fRes = $conn->query("SELECT f.id as flat_id, f.number as flat_number, b.name as building_name, 
                                         r.id as resident_id, u.name as resident_name, u.phone as resident_phone
                                  FROM flats f 
                                  LEFT JOIN buildings b ON f.building_id = b.id 
                                  LEFT JOIN residents r ON r.flat_id = f.id AND r.estate_id = $estate_id AND r.status = 'active'
                                  LEFT JOIN users u ON r.user_id = u.id 
                                  WHERE f.estate_id = $estate_id AND (f.number = '$flatEsc' OR f.custom_id = '$flatEsc' OR f.number LIKE '%$flatEsc%') 
                                  LIMIT 1");

            $flatId = null;
            $residentId = null;
            $hostName = 'Resident';
            $hostPhone = '';
            $flatDisplay = "Flat $flatInput";

            if ($fRes && $fRes->num_rows > 0) {
                $fRow = $fRes->fetch_assoc();
                $flatId = intval($fRow['flat_id']);
                $residentId = !empty($fRow['resident_id']) ? intval($fRow['resident_id']) : null;
                $hostName = $fRow['resident_name'] ?: 'Resident';
                $hostPhone = $fRow['resident_phone'] ?: '';
                $flatDisplay = ($fRow['building_name'] ? $fRow['building_name'] . ' ' : '') . "Flat " . $fRow['flat_number'];
            }

            do {
                $visitorCode = strval(mt_rand(100000, 999999));
                $chkCode = $conn->query("SELECT id FROM visitors WHERE visitor_code = '$visitorCode' AND status IN ('pre_registered', 'entered') LIMIT 1");
            } while ($chkCode && $chkCode->num_rows > 0);

            $gNameEsc = $conn->real_escape_string($guestName);
            $gPhoneEsc = $conn->real_escape_string($phoneNumber);
            $purposeEsc = $conn->real_escape_string($purpose);

            $insSql = "INSERT INTO visitors (estate_id, resident_id, flat_id, name, phone, purpose, visitor_code, status, expected_arrival, created_at)
                       VALUES ($estate_id, " . ($residentId ?: "NULL") . ", " . ($flatId ?: "NULL") . ", '$gNameEsc', '$gPhoneEsc', '$purposeEsc', '$visitorCode', 'pre_registered', NOW(), NOW())";

            if ($conn->query($insSql)) {
                if (!empty($hostPhone) && $at_config['sms_enabled'] && $at_config['notify_pass_on_ussd']) {
                    $hostSms = "VISITOR ARRIVAL REQUEST [{$estate_name}]: {$guestName} is at the gate for {$flatDisplay}. Pass Code: {$visitorCode}.";
                    EstateAfricasTalking::sendSMS($conn, $hostPhone, $hostSms, $estate_id);
                }

                $response = "END PASS CODE GENERATED!\n";
                $response .= "Code: $visitorCode\n";
                $response .= "Guest: $guestName\n";
                $response .= "Host: $hostName ($flatDisplay)\n";
                $response .= "Present this code to the gate officer for entry.";
            } else {
                $response = "END Error creating request. Please contact estate security.";
            }
        }
    } elseif ($inputs[0] === '3') {
        $secPhoneRes = $conn->query("SELECT setting_value FROM system_settings WHERE estate_id = $estate_id AND setting_key = 'office_phone' LIMIT 1");
        $hotline = ($secPhoneRes && $spRow = $secPhoneRes->fetch_assoc()) ? $spRow['setting_value'] : '0800-ESTATE-SEC';
        $response = "END ESTATE GATE EMERGENCY:\nHotline: $hotline\nAvailable 24/7 at Main Security House.";
    } elseif ($inputs[0] === '4') {
        $officeEmailRes = $conn->query("SELECT setting_value FROM system_settings WHERE estate_id = $estate_id AND setting_key = 'office_email' LIMIT 1");
        $email = ($officeEmailRes && $er = $officeEmailRes->fetch_assoc()) ? $er['setting_value'] : 'admin@estate.com';
        $response = "END $estate_name Office:\nEmail: $email\nVisiting Hours: 8:00 AM - 6:00 PM";
    } else {
        $response = "END Invalid option.";
    }
}

// -----------------------------------------------------------------------------
// LOGGING & FINAL PROTOCOL OUTPUT
// -----------------------------------------------------------------------------
$responseType = (strpos($response, 'END') === 0) ? 'END' : 'CON';
EstateAfricasTalking::logUSSD($conn, $estate_id, $sessionId, $phoneNumber, $serviceCode, $networkCode, $text, $responseType, $response, 'success');

echo $response;
exit;
