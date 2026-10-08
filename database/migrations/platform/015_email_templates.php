<?php
// Add missing email templates
return function ($db) {
    $templates = [
        [
            'template_key' => 'email_verification',
            'name' => 'Email Verification',
            'subject' => 'Verify Your Email Address - {{estate_name}}',
            'body' => <<<'HTML'
<p>Hello {{user_name}},</p>
<p>Thank you for signing up with {{company_name}}. Please verify your email address to activate your account.</p>
<p><strong>Your Verification Code:</strong></p>
<p style="font-size: 24px; font-weight: bold; letter-spacing: 2px; color: #3b82f6;">{{otp_code}}</p>
<p>This code will expire in {{otp_expiry}} minutes.</p>
<p>If you did not sign up for this account, you can safely ignore this email.</p>
<p>Best regards,<br>{{company_name}} Team</p>
HTML,
        ],
        [
            'template_key' => 'deletion_completed',
            'name' => 'Account Deletion Confirmed',
            'subject' => 'Estate Account Deleted - {{estate_name}}',
            'body' => <<<'HTML'
<p>Hello {{central_admin_name}},</p>
<p>Your estate account <strong>{{estate_name}}</strong> has been permanently deleted as of {{deletion_date}}.</p>
<p><strong>What was deleted:</strong></p>
<ul>
<li>All resident and property records</li>
<li>User accounts and permissions</li>
<li>Financial records and invoices</li>
<li>Visitor passes and gate records</li>
<li>Communications and announcements</li>
</ul>
<p><strong>What was preserved (for legal compliance):</strong></p>
<ul>
<li>Financial transaction history ({{retention_years}} years)</li>
<li>Audit logs of administrative actions</li>
</ul>
<p>A final backup was taken before deletion and is retained per our data retention policy.</p>
<p>If you have any questions about this deletion, please contact {{support_email}}.</p>
<p>Best regards,<br>{{company_name}} Team</p>
HTML,
        ],
        [
            'template_key' => 'contact_inquiry_received',
            'name' => 'Contact Form Received',
            'subject' => 'We Received Your Message - {{company_name}}',
            'body' => <<<'HTML'
<p>Hello {{contact_name}},</p>
<p>Thank you for contacting {{company_name}}. We have received your inquiry about <strong>{{contact_subject}}</strong>.</p>
<p>Our support team will review your message and get back to you shortly. Your reference number is: <strong>{{inquiry_id}}</strong></p>
<p><strong>Your Message:</strong></p>
<p style="background: #f3f4f6; padding: 15px; border-left: 4px solid #3b82f6;">{{contact_message}}</p>
<p>Expected response time: {{support_hours}}</p>
<p>If your inquiry is urgent, please call {{support_phone}}.</p>
<p>Best regards,<br>{{company_name}} Team</p>
HTML,
        ],
    ];

    foreach ($templates as $tmpl) {
        $key = $tmpl['template_key'];
        $res = $db->query("SELECT id FROM email_templates WHERE template_key = '$key' LIMIT 1");
        if (!$res || $res->num_rows === 0) {
            $name = $db->real_escape_string($tmpl['name']);
            $subject = $db->real_escape_string($tmpl['subject']);
            $body = $db->real_escape_string($tmpl['body']);
            $db->query("INSERT INTO email_templates (template_key, name, subject, html_body, is_active, version) VALUES ('$key', '$name', '$subject', '$body', 1, 1)");
        }
    }

    return true;
};
