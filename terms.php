<?php
require_once 'config.php';
require_once 'includes/public_layout.php';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms of Service - <?php echo htmlspecialchars(PlatformSettings::get('site_company_name', 'Estate Management')); ?></title>
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
            border-bottom: 2px solid #3b82f6;
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
            color: #3b82f6;
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
            <h1>Terms of Service</h1>
            <p>Last Updated: <?php echo date('F j, Y'); ?></p>
        </div>

        <div class="legal-section">
            <h2>1. Acceptance of Terms</h2>
            <p>By accessing and using this Estate Management platform (the "Service"), you accept and agree to be bound by the terms and provision of this agreement. If you do not agree to abide by the above, please do not use this service.</p>
        </div>

        <div class="legal-section">
            <h2>2. Use License</h2>
            <p>Permission is granted to temporarily download one copy of the materials (information or software) on <?php echo htmlspecialchars(PlatformSettings::get('site_company_name', 'Estate Management')); ?>'s website for personal, non-commercial transitory viewing only. This is the grant of a license, not a transfer of title, and under this license you may not:</p>
            <ul>
                <li>Modify or copy the materials</li>
                <li>Use the materials for any commercial purpose or for any public display</li>
                <li>Attempt to decompile or reverse engineer any software contained on the Service</li>
                <li>Remove any copyright or other proprietary notations from the materials</li>
                <li>Transfer the materials to another person or "mirror" the materials on any other server</li>
                <li>Use the Service to provide services to third parties without authorization</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2>3. Disclaimer</h2>
            <p>The materials on <?php echo htmlspecialchars(PlatformSettings::get('site_company_name', 'Estate Management')); ?>'s website are provided on an 'as is' basis. <?php echo htmlspecialchars(PlatformSettings::get('site_company_name', 'Estate Management')); ?> makes no warranties, expressed or implied, and hereby disclaims and negates all other warranties including, without limitation, implied warranties or conditions of merchantability, fitness for a particular purpose, or non-infringement of intellectual property or other violation of rights.</p>
        </div>

        <div class="legal-section">
            <h2>4. Limitations</h2>
            <p>In no event shall <?php echo htmlspecialchars(PlatformSettings::get('site_company_name', 'Estate Management')); ?> or its suppliers be liable for any damages (including, without limitation, damages for loss of data or profit, or due to business interruption) arising out of the use or inability to use the materials on the Service, even if we have been notified orally or in writing of the possibility of such damage.</p>
        </div>

        <div class="legal-section">
            <h2>5. Accuracy of Materials</h2>
            <p>The materials appearing on the Service could include technical, typographical, or photographic errors. The Company does not warrant that any of the materials on its website are accurate, complete, or current. The Company may make changes to the materials contained on its website at any time without notice.</p>
        </div>

        <div class="legal-section">
            <h2>6. Links</h2>
            <p>The Company has not reviewed all of the sites linked to its website and is not responsible for the contents of any such linked site. The inclusion of any link does not imply endorsement by the Company of the site. Use of any such linked website is at the user's own risk.</p>
        </div>

        <div class="legal-section">
            <h2>7. Modifications</h2>
            <p>The Company may revise these terms of service for its website at any time without notice. By using this website, you are agreeing to be bound by the then current version of these terms of service.</p>
        </div>

        <div class="legal-section">
            <h2>8. User Accounts</h2>
            <p>If you create an account on this Service, you are responsible for maintaining the confidentiality of your account information and password and for restricting access to your computer. You agree to accept responsibility for all activities that occur under your account or password.</p>
        </div>

        <div class="legal-section">
            <h2>9. Data Privacy</h2>
            <p>Your use of the Service is also governed by our <a href="privacy" style="color: #3b82f6;">Privacy Policy</a>. Please review our Privacy Policy to understand our practices.</p>
        </div>

        <div class="legal-section">
            <h2>10. Governing Law</h2>
            <p>These terms and conditions are governed by and construed in accordance with the laws of the jurisdiction in which <?php echo htmlspecialchars(PlatformSettings::get('site_company_name', 'Estate Management')); ?> is located, and you irrevocably submit to the exclusive jurisdiction of the courts in that location.</p>
        </div>

        <div class="footer-link">
            <a href="/">← Back to Home</a> | <a href="privacy">Privacy Policy</a>
        </div>
    </div>

    <?php public_footer(); ?>
</body>
</html>
