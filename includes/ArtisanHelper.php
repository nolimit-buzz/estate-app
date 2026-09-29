<?php
// includes/ArtisanHelper.php - Artisan Onboarding, Multi-Channel Notifications & Verification Helper
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/WhatsApp.php';

class ArtisanHelper {
    
    /**
     * Master list of supported trades with display icons and colors
     */
    public static function getTradeCategories() {
        return [
            'plumbing' => [
                'label' => 'Plumbing Services',
                'short' => 'Plumber',
                'icon'  => 'fa-solid fa-wrench',
                'color' => '#0284c7',
                'bg'    => '#e0f2fe'
            ],
            'electrical' => [
                'label' => 'Electrical & Wiring',
                'short' => 'Electrician',
                'icon'  => 'fa-solid fa-bolt',
                'color' => '#d97706',
                'bg'    => '#fef3c7'
            ],
            'hvac' => [
                'label' => 'HVAC & AC Servicing',
                'short' => 'AC / HVAC Tech',
                'icon'  => 'fa-solid fa-snowflake',
                'color' => '#0891b2',
                'bg'    => '#cffafe'
            ],
            'carpentry' => [
                'label' => 'Carpentry & Woodwork',
                'short' => 'Carpenter',
                'icon'  => 'fa-solid fa-hammer',
                'color' => '#b45309',
                'bg'    => '#ffedd5'
            ],
            'painting' => [
                'label' => 'Painting & POP Finishing',
                'short' => 'Painter / POP',
                'icon'  => 'fa-solid fa-paint-roller',
                'color' => '#7c3aed',
                'bg'    => '#ede9fe'
            ],
            'masonry' => [
                'label' => 'Masonry, Tiling & Brickwork',
                'short' => 'Mason / Tiler',
                'icon'  => 'fa-solid fa-trowel-bricks',
                'color' => '#db2777',
                'bg'    => '#fce7f3'
            ],
            'generator' => [
                'label' => 'Generator & Solar Inverter',
                'short' => 'Power & Generator',
                'icon'  => 'fa-solid fa-plug-circle-bolt',
                'color' => '#059669',
                'bg'    => '#d1fae5'
            ],
            'gardening' => [
                'label' => 'Gardening & Landscaping',
                'short' => 'Landscaper',
                'icon'  => 'fa-solid fa-tree',
                'color' => '#15803d',
                'bg'    => '#dcfce7'
            ],
            'cleaning' => [
                'label' => 'Deep Cleaning & Fumigation',
                'short' => 'Cleaning / Pest',
                'icon'  => 'fa-solid fa-broom',
                'color' => '#0d9488',
                'bg'    => '#ccfbf1'
            ],
            'welding' => [
                'label' => 'Welding & Gate Fabrication',
                'short' => 'Welder / Metalwork',
                'icon'  => 'fa-solid fa-fire-burner',
                'color' => '#ea580c',
                'bg'    => '#ffedd5'
            ],
            'appliance' => [
                'label' => 'Home Appliance Repairs',
                'short' => 'Appliance Tech',
                'icon'  => 'fa-solid fa-tv',
                'color' => '#475569',
                'bg'    => '#f1f5f9'
            ],
            'handyman' => [
                'label' => 'General Maintenance & Handyman',
                'short' => 'General Handyman',
                'icon'  => 'fa-solid fa-screwdriver-wrench',
                'color' => '#2563eb',
                'bg'    => '#eff6ff'
            ]
        ];
    }

    /**
     * Get trade category details
     */
    public static function getTradeInfo($key) {
        $cats = self::getTradeCategories();
        $slug = strtolower(trim($key));
        return $cats[$slug] ?? [
            'label' => ucfirst($key),
            'short' => ucfirst($key),
            'icon'  => 'fa-solid fa-screwdriver-wrench',
            'color' => '#3b82f6',
            'bg'    => '#eff6ff'
        ];
    }

    /**
     * Generate next official Estate Artisan Badge Code (e.g. ART-2026-0001)
     */
    public static function generateArtisanCode($conn, $estate_id = 1) {
        $year = date('Y');
        $prefix = "ART-{$year}-";
        $res = $conn->query("SELECT artisan_code FROM artisans WHERE estate_id = $estate_id AND artisan_code LIKE '{$prefix}%' ORDER BY id DESC LIMIT 1");
        $nextNum = 1;
        if ($res && $row = $res->fetch_assoc()) {
            $lastCode = $row['artisan_code'] ?? '';
            $parts = explode('-', $lastCode);
            if (count($parts) >= 3) {
                $lastNum = intval(end($parts));
                $nextNum = $lastNum + 1;
            }
        }
        return sprintf("%s%04d", $prefix, $nextNum);
    }

    /**
     * Trigger Multi-Channel Alert to Central Admin upon new artisan registration:
     * 1. In-App Notification (Top Bell icon counter updates for all estate admins)
     * 2. Direct Email via EstateMailer to Office Email and Admins
     * 3. WhatsApp Notification via EstateWhatsApp to Office Phone
     * 4. Auto-response confirmation to Applicant
     */
    public static function notifyAdminNewRegistration($conn, $artisan_id, $data) {
        $estate_id = intval($data['estate_id'] ?? (function_exists('get_estate_id') ? get_estate_id() : 1));
        $full_name = htmlspecialchars($data['full_name'] ?? 'New Applicant');
        $trade = htmlspecialchars($data['trade_category'] ?? 'General Handyman');
        $phone = htmlspecialchars($data['phone'] ?? '');
        $email = htmlspecialchars($data['email'] ?? '');
        $experience = intval($data['years_experience'] ?? 1);
        $referred_by_type = htmlspecialchars($data['referred_by_type'] ?? 'public');
        $base_url = EstateMailer::getBaseUrl();
        $adminReviewUrl = $base_url . 'admin/artisans.php?id=' . intval($artisan_id);

        $tradeInfo = self::getTradeInfo($trade);
        $tradeLabel = $tradeInfo['label'];

        // 1. IN-APP NOTIFICATIONS for all Superadmin and Estate Admin accounts
        $adminUsers = $conn->query("SELECT id, name, email, phone FROM users WHERE role IN ('admin', 'superadmin') AND (estate_id = $estate_id OR role = 'superadmin')");
        $adminList = [];
        if ($adminUsers) {
            while ($adm = $adminUsers->fetch_assoc()) {
                $adminList[] = $adm;
                $adm_uid = intval($adm['id']);
                $n_title = $conn->real_escape_string("🛠️ New Artisan Application: {$full_name} ({$tradeLabel})");
                $n_msg = $conn->real_escape_string("A new artisan registration was submitted from the {$referred_by_type} portal. Central Admin confirmation is required before this artisan is onboarded to the resident directory.");
                $conn->query("INSERT INTO notifications (estate_id, user_id, title, message, type, reference_id, is_read, created_at) 
                             VALUES ($estate_id, $adm_uid, '$n_title', '$n_msg', 'artisan_registration', $artisan_id, 0, NOW())");
            }
        }

        // Fetch Estate System Settings for Email/WhatsApp targets
        $sys_res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE estate_id = $estate_id");
        $sys = [];
        if ($sys_res) {
            while ($r = $sys_res->fetch_assoc()) {
                $sys[$r['setting_key']] = $r['setting_value'];
            }
        }
        $office_email = !empty($sys['office_email']) ? $sys['office_email'] : '';
        $office_phone = !empty($sys['office_phone']) ? $sys['office_phone'] : '';
        $estate_name  = !empty($sys['estate_name']) ? $sys['estate_name'] : 'Estate Central Administration';

        // 2. DISPATCH EMAIL TO CENTRAL ADMIN (Office Email + Primary Admins)
        $emailSubject = "⚠️ Action Required: New Artisan Onboarding Application - {$full_name} ({$tradeLabel})";
        $emailBody = "
        <div style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, sans-serif; max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);'>
            <div style='background: linear-gradient(135deg, #1e293b, #0f172a); padding: 24px; color: #ffffff; text-align: center;'>
                <div style='display: inline-block; background: rgba(59, 130, 246, 0.2); color: #60a5fa; padding: 6px 14px; border-radius: 9999px; font-size: 12px; font-weight: 700; text-transform: uppercase; margin-bottom: 8px;'>
                    Artisan Onboarding Vetting Gate
                </div>
                <h2 style='margin: 0; font-size: 20px; font-weight: 800; color: #ffffff;'>New Artisan Application Submitted</h2>
                <p style='margin: 6px 0 0 0; color: #94a3b8; font-size: 13px;'>Central Admin verification is required before record is published to residents.</p>
            </div>
            
            <div style='padding: 24px;'>
                <div style='background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 20px;'>
                    <table style='width: 100%; border-collapse: collapse; font-size: 14px;'>
                        <tr>
                            <td style='padding: 8px 0; color: #64748b; font-weight: 600; width: 140px;'>Applicant Name:</td>
                            <td style='padding: 8px 0; color: #0f172a; font-weight: 700;'>{$full_name}</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; color: #64748b; font-weight: 600;'>Trade / Skill:</td>
                            <td style='padding: 8px 0; color: #0284c7; font-weight: 700;'>{$tradeLabel}</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; color: #64748b; font-weight: 600;'>Phone Number:</td>
                            <td style='padding: 8px 0; color: #0f172a; font-weight: 700;'><a href='tel:{$phone}' style='color: #2563eb; text-decoration: none;'>{$phone}</a></td>
                        </tr>
                        " . (!empty($email) ? "
                        <tr>
                            <td style='padding: 8px 0; color: #64748b; font-weight: 600;'>Email:</td>
                            <td style='padding: 8px 0; color: #0f172a;'><a href='mailto:{$email}' style='color: #2563eb; text-decoration: none;'>{$email}</a></td>
                        </tr>" : "") . "
                        <tr>
                            <td style='padding: 8px 0; color: #64748b; font-weight: 600;'>Experience:</td>
                            <td style='padding: 8px 0; color: #0f172a;'>{$experience} Years</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; color: #64748b; font-weight: 600;'>Application Source:</td>
                            <td style='padding: 8px 0; color: #0f172a; text-transform: capitalize;'>{$referred_by_type} Portal</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; color: #64748b; font-weight: 600;'>Current Status:</td>
                            <td style='padding: 8px 0;'><span style='background: #fef3c7; color: #b45309; padding: 3px 8px; border-radius: 4px; font-weight: 700; font-size: 12px;'>Pending Verification (Quarantined)</span></td>
                        </tr>
                    </table>
                </div>

                <div style='text-align: center; margin: 24px 0;'>
                    <a href='{$adminReviewUrl}' style='display: inline-block; background: #2563eb; color: #ffffff; padding: 12px 28px; border-radius: 8px; font-weight: 700; text-decoration: none; font-size: 14px; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.4);'>
                        Inspect Credentials &amp; Verify Artisan &rarr;
                    </a>
                </div>

                <p style='color: #64748b; font-size: 12px; line-height: 1.5; margin: 0; text-align: center;'>
                    This artisan will <strong>not</strong> be visible to estate residents or available for booking until approved by a Central Administrator in the portal.
                </p>
            </div>
            
            <div style='background: #f1f5f9; padding: 16px; text-align: center; font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0;'>
                {$estate_name} • Security &amp; Facility Operations Desk
            </div>
        </div>
        ";

        // Send to Office Email
        if (!empty($office_email)) {
            EstateMailer::sendCustomEmail($conn, $office_email, 'Estate Central Admin', $emailSubject, $emailBody, 'artisan_onboarding', $artisan_id, $estate_id);
        }
        // Send to primary admin users
        foreach ($adminList as $adm) {
            if (!empty($adm['email']) && $adm['email'] !== $office_email) {
                EstateMailer::sendCustomEmail($conn, $adm['email'], $adm['name'], $emailSubject, $emailBody, 'artisan_onboarding', $artisan_id, $estate_id);
            }
        }

        // 3. DISPATCH WHATSAPP TO CENTRAL ADMIN
        $waBody = "🔔 *[{$estate_name}] New Artisan Registration*\n\n" .
                  "👤 *Name:* {$full_name}\n" .
                  "🛠️ *Trade:* {$tradeLabel}\n" .
                  "📞 *Phone:* {$phone}\n" .
                  "⏳ *Status:* Pending Central Admin Verification\n\n" .
                  "Confirmation is required before onboarding to Resident Directory.\n" .
                  "🔗 *Review Application:* {$adminReviewUrl}";

        // Send to office phone via Kapso / EstateWhatsApp
        if (!empty($office_phone) && class_exists('EstateWhatsApp') && method_exists('EstateWhatsApp', 'sendTextMessage')) {
            EstateWhatsApp::sendTextMessage($conn, $office_phone, $waBody, 'artisan_alert', $artisan_id, 'Central Admin Office', $estate_id);
        }
        foreach ($adminList as $adm) {
            if (!empty($adm['phone']) && $adm['phone'] !== $office_phone && class_exists('EstateWhatsApp') && method_exists('EstateWhatsApp', 'sendTextMessage')) {
                EstateWhatsApp::sendTextMessage($conn, $adm['phone'], $waBody, 'artisan_alert', $artisan_id, $adm['name'], $estate_id);
            }
        }

        // 4. CONFIRMATION TO ARTISAN APPLICANT (Email & WhatsApp)
        if (!empty($email)) {
            $artisanSubj = "Application Received - {$estate_name} Artisan Accreditation";
            $artisanBody = "
            <div style='font-family: sans-serif; max-width: 550px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px;'>
                <h3 style='color: #0f172a; margin-top: 0;'>Hello {$full_name},</h3>
                <p style='color: #334155; line-height: 1.6;'>
                    Thank you for applying to be an accredited service provider for <strong>{$estate_name}</strong> in the category of <strong>{$tradeLabel}</strong>.
                </p>
                <div style='background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px; margin: 16px 0; border-radius: 4px;'>
                    <strong style='color: #92400e; font-size: 13px;'>Status: Application Under Central Admin Review</strong>
                    <p style='margin: 4px 0 0 0; color: #78350f; font-size: 12px;'>
                        Our estate facility and security team will review your submitted identification and credentials. You will be notified immediately once verified.
                    </p>
                </div>
                <p style='color: #64748b; font-size: 13px;'>If you have questions, contact the estate office at {$office_phone} or {$office_email}.</p>
            </div>";
            if (class_exists('EstateMailer') && method_exists('EstateMailer', 'sendCustomEmail')) {
                EstateMailer::sendCustomEmail($conn, $email, $full_name, $artisanSubj, $artisanBody, 'artisan_applicant_received', $artisan_id, $estate_id);
            }
        }

        if (!empty($phone) && class_exists('EstateWhatsApp') && method_exists('EstateWhatsApp', 'sendTextMessage')) {
            $artisanWa = "Hello *{$full_name}*,\nYour application to join *{$estate_name}* as a verified *{$tradeLabel}* has been received. Our Central Admin is reviewing your submitted credentials. You will be notified once accredited!";
            EstateWhatsApp::sendTextMessage($conn, $phone, $artisanWa, 'artisan_received', $artisan_id, $full_name, $estate_id);
        }

        return true;
    }

    /**
     * Send notification to Artisan when Central Admin approves or rejects application
     */
    public static function notifyArtisanVerificationOutcome($conn, $artisan_id, $outcome, $notes = '') {
        $art_q = $conn->query("SELECT * FROM artisans WHERE id = " . intval($artisan_id) . " LIMIT 1");
        if (!$art_q || $art_q->num_rows == 0) return false;
        $art = $art_q->fetch_assoc();

        $estate_id = intval($art['estate_id'] ?? 1);
        $full_name = htmlspecialchars($art['full_name']);
        $trade = htmlspecialchars($art['trade_category']);
        $code = htmlspecialchars($art['artisan_code'] ?? 'PENDING');
        $phone = $art['phone'] ?? '';
        $email = $art['email'] ?? '';

        $branding = get_estate_branding($conn);
        $estate_name = $branding['estate_name'] ?? 'Main Estate';

        if ($outcome === 'verified') {
            $subj = "🎉 Congratulations! You are now a Verified Estate Artisan at {$estate_name}";
            $msgHtml = "
            <div style='font-family: sans-serif; max-width: 550px; margin: 0 auto; padding: 24px; border: 1px solid #10b981; border-radius: 12px; background: #ffffff;'>
                <div style='text-align: center; margin-bottom: 20px;'>
                    <span style='background: #d1fae5; color: #047857; font-weight: 800; font-size: 13px; padding: 6px 14px; border-radius: 9999px; text-transform: uppercase;'>
                        ✓ Accredited &amp; Verified
                    </span>
                    <h2 style='color: #0f172a; margin: 12px 0 4px;'>Welcome to {$estate_name}</h2>
                    <p style='color: #64748b; font-size: 14px; margin: 0;'>Your artisan onboarding has been confirmed by Central Admin.</p>
                </div>
                
                <div style='background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 20px; text-align: center;'>
                    <div style='font-size: 12px; color: #64748b; font-weight: 700; text-transform: uppercase;'>Official Estate Artisan Badge Code</div>
                    <div style='font-size: 24px; font-weight: 800; color: #0f172a; letter-spacing: 2px; margin: 6px 0;'>{$code}</div>
                    <div style='font-size: 13px; color: #059669; font-weight: 600;'>Trade: " . ucfirst($trade) . "</div>
                </div>

                <p style='color: #334155; font-size: 14px; line-height: 1.6;'>
                    Your profile is now live in the <strong>Resident Maintenance Directory</strong>. Residents can now contact you directly or book maintenance services through the estate portal.
                </p>
                " . (!empty($notes) ? "<p style='color: #475569; font-size: 13px; background: #f1f5f9; padding: 10px; border-radius: 6px;'><strong>Admin Note:</strong> " . nl2br(htmlspecialchars($notes)) . "</p>" : "") . "
            </div>";

            $waMsg = "🎉 *Congratulations {$full_name}!* \n\nYour application has been *APPROVED* by Central Admin. You are now officially onboarded as an accredited *{$trade}* at *{$estate_name}*.\n\n" .
                     "🏷️ *Artisan ID:* {$code}\n" .
                     "Your profile is now published in the Resident Directory for service requests and gate passes!";
            
            if (!empty($email) && class_exists('EstateMailer') && method_exists('EstateMailer', 'sendCustomEmail')) {
                EstateMailer::sendCustomEmail($conn, $email, $full_name, $subj, $msgHtml, 'artisan_approved', $artisan_id, $estate_id);
            }
            if (!empty($phone) && class_exists('EstateWhatsApp') && method_exists('EstateWhatsApp', 'sendTextMessage')) {
                EstateWhatsApp::sendTextMessage($conn, $phone, $waMsg, 'artisan_approved', $artisan_id, $full_name, $estate_id);
            }
        } elseif ($outcome === 'rejected') {
            $subj = "Update regarding your Artisan Application at {$estate_name}";
            $msgHtml = "
            <div style='font-family: sans-serif; max-width: 550px; margin: 0 auto; padding: 24px; border: 1px solid #ef4444; border-radius: 12px; background: #ffffff;'>
                <h3 style='color: #b91c1c; margin-top: 0;'>Application Status Update</h3>
                <p style='color: #334155; line-height: 1.6;'>
                    Hello {$full_name}, thank you for applying to {$estate_name}. Following our security and credential review, your onboarding could not be confirmed at this time.
                </p>
                " . (!empty($notes) ? "<div style='background: #fef2f2; border: 1px solid #fee2e2; padding: 12px; border-radius: 6px; color: #991b1b; font-size: 13px;'><strong>Reason / Note:</strong> " . nl2br(htmlspecialchars($notes)) . "</div>" : "") . "
                <p style='color: #64748b; font-size: 13px; margin-top: 16px;'>For clarifications or to re-submit updated documents, please contact the central office.</p>
            </div>";

            $waMsg = "Hello *{$full_name}*, this is an update regarding your artisan application at *{$estate_name}*. Regrettably, your application was not confirmed by Central Admin.\n" .
                     (!empty($notes) ? "Note: {$notes}\n" : "") .
                     "Please contact the estate office for inquiries.";
            
            if (!empty($email) && class_exists('EstateMailer') && method_exists('EstateMailer', 'sendCustomEmail')) {
                EstateMailer::sendCustomEmail($conn, $email, $full_name, $subj, $msgHtml, 'artisan_rejected', $artisan_id, $estate_id);
            }
            if (!empty($phone) && class_exists('EstateWhatsApp') && method_exists('EstateWhatsApp', 'sendTextMessage')) {
                EstateWhatsApp::sendTextMessage($conn, $phone, $waMsg, 'artisan_rejected', $artisan_id, $full_name, $estate_id);
            }
        }

        return true;
    }
}
