<?php
require_once 'config.php';
require_once 'includes/public_layout.php';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy - <?php echo htmlspecialchars(PlatformSettings::get('site_company_name', 'Estate Management')); ?></title>
    <link rel="stylesheet" href="css/saas.css?v=<?php echo ASSET_VER; ?>">
    <style>
        body {
            background: linear-gradient(135deg, #f3f4f6 0%, #ffffff 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #374151;
            line-height: 1.8;
        }
        .legal-container {
            max-width: 900px;
            margin: 40px auto;
            padding: 40px 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.07);
        }
        .legal-header {
            margin-bottom: 40px;
            border-bottom: 2px solid #10b981;
            padding-bottom: 20px;
        }
        .legal-header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            color: #1f2937;
            margin: 0 0 10px 0;
        }
        .legal-header p {
            color: #6b7280;
            font-size: 1.05rem;
            margin: 0;
        }
        .legal-section {
            margin-bottom: 35px;
        }
        .legal-section h2 {
            font-size: 1.4rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 15px;
            margin-top: 30px;
        }
        .legal-section h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #374151;
            margin-top: 20px;
            margin-bottom: 10px;
        }
        .legal-section ul, .legal-section ol {
            margin: 15px 0;
            padding-left: 20px;
        }
        .legal-section li {
            margin-bottom: 8px;
        }
        .legal-section p {
            margin: 12px 0;
        }
        .footer-link {
            text-align: center;
            margin-top: 50px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }
        .footer-link a {
            color: #10b981;
            text-decoration: none;
            font-weight: 500;
        }
        .footer-link a:hover {
            text-decoration: underline;
        }
    </style>
    <link rel="stylesheet" href="css/dashboard-theme-public.css?v=<?php echo defined('ASSET_VER') ? ASSET_VER : time(); ?>">
</head>
<body>
    <?php public_header(); ?>

    <div class="legal-container">
        <div class="legal-header">
            <h1>Privacy Policy</h1>
            <p>Last Updated: <?php echo date('F j, Y'); ?></p>
        </div>

        <div class="legal-section">
            <h2>1. Introduction</h2>
            <p><?php echo htmlspecialchars(PlatformSettings::get('site_company_name', 'Estate Management')); ?> ("we", "us", "our", or "Company") operates the Estate Management platform. This page informs you of our policies regarding the collection, use, and disclosure of personal data when you use our Service and the choices you have associated with that data.</p>
        </div>

        <div class="legal-section">
            <h2>2. Information Collection and Use</h2>
            <h3>Types of Data Collected</h3>
            <ul>
                <li><strong>Personal Data:</strong> When you create an account, we collect information such as your name, email address, phone number, and other profile information.</li>
                <li><strong>Estate Information:</strong> Property details, resident information, financial records, and other data related to estate management.</li>
                <li><strong>Usage Data:</strong> We automatically collect information about how you interact with the Service, including IP addresses, browser type, access times, and pages visited.</li>
                <li><strong>Cookies:</strong> We use cookies to track user preferences and improve your experience.</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2>3. Use of Data</h2>
            <p>The Company uses the collected data for various purposes:</p>
            <ul>
                <li>To provide and maintain the Service</li>
                <li>To notify you about changes to our Service</li>
                <li>To allow you to participate in interactive features of our Service</li>
                <li>To provide customer support and respond to your requests</li>
                <li>To gather analysis or valuable information so that we can improve the Service</li>
                <li>To monitor the usage of the Service</li>
                <li>To detect, prevent and address technical issues and fraudulent activity</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2>4. Security of Data</h2>
            <p>The security of your data is important to us, but remember that no method of transmission over the Internet or method of electronic storage is 100% secure. While we strive to use commercially acceptable means to protect your Personal Data, we cannot guarantee its absolute security.</p>
            <ul>
                <li>Data is encrypted in transit using HTTPS</li>
                <li>Sensitive data is encrypted at rest using AES-256-GCM</li>
                <li>Database access is restricted to authorized personnel only</li>
                <li>Regular security audits and updates are performed</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2>5. Data Retention</h2>
            <p>The Company will retain your Personal Data only for as long as necessary for the purposes set out in this Privacy Policy. We will retain and use your Personal Data to the extent necessary to comply with our legal obligations.</p>
            <p>Financial and billing records are retained for a minimum of <?php echo htmlspecialchars(PlatformSettings::int('financial_retention_years', 7)); ?> years as required by law.</p>
        </div>

        <div class="legal-section">
            <h2>6. Data Isolation</h2>
            <p>Each estate's data is stored in a completely isolated database. Your data cannot be accessed by other estates or users who are not authorized members of your estate. Cross-estate data access is strictly prohibited and technically prevented.</p>
        </div>

        <div class="legal-section">
            <h2>7. Your Rights</h2>
            <p>You have the right to:</p>
            <ul>
                <li>Access your personal data</li>
                <li>Request correction of inaccurate data</li>
                <li>Request deletion of your data (subject to legal retention requirements)</li>
                <li>Export your estate's data</li>
                <li>Withdraw consent at any time</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2>8. Changes to This Privacy Policy</h2>
            <p>We may update our Privacy Policy from time to time. We will notify you of any changes by posting the new Privacy Policy on this page and updating the "Last Updated" date at the top of this Privacy Policy.</p>
        </div>

        <div class="legal-section">
            <h2>9. Contact Us</h2>
            <p>If you have any questions about this Privacy Policy, please contact us at:</p>
            <p>
                <strong><?php echo htmlspecialchars(PlatformSettings::get('site_company_name', 'Estate Management')); ?></strong><br>
                Email: <a href="mailto:<?php echo htmlspecialchars(PlatformSettings::get('site_support_email', 'support@example.com')); ?>" style="color: #10b981;"><?php echo htmlspecialchars(PlatformSettings::get('site_support_email', 'support@example.com')); ?></a><br>
                <?php if ($phone = PlatformSettings::get('site_support_phone')): ?>
                    Phone: <?php echo htmlspecialchars($phone); ?><br>
                <?php endif; ?>
                <?php if ($addr = PlatformSettings::get('site_address')): ?>
                    Address: <?php echo htmlspecialchars($addr); ?>
                <?php endif; ?>
            </p>
        </div>

        <div class="footer-link">
            <a href="/">← Back to Home</a> | <a href="terms">Terms of Service</a>
        </div>
    </div>

    <?php public_footer(); ?>
</body>
</html>
