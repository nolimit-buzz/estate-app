<?php
require_once 'config.php';
require_once 'includes/public_layout.php';
require_once 'includes/Mailer.php';
require_once 'includes/Notifier.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $body = trim($_POST['message'] ?? '');

    // Validation
    if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$subject || !$body) {
        $error = 'Please fill in all required fields with valid information.';
    } elseif (strlen($body) < 10) {
        $error = 'Your message must be at least 10 characters long.';
    } elseif (RateLimit::hit('contact_form', $_SERVER['REMOTE_ADDR'] ?? '0', 3, 3600, 3600)) {
        $error = 'You have submitted too many contact requests. Please try again in 1 hour.';
    } else {
        try {
            // Log to platform database
            $platform = Tenancy::platform();
            $stmt = $platform->prepare("INSERT INTO contact_inquiries (name, email, phone, subject, message, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            $stmt->bind_param('sssssss', $name, $email, $phone, $subject, $body, $ip, $ua);
            $stmt->execute();

            // Send to support email
            $support_email = PlatformSettings::get('site_support_email', 'support@example.com');
            $reply_to = $email;

            $html_body = <<<HTML
<h2>New Contact Inquiry</h2>
<p><strong>From:</strong> $name ($email)</p>
<p><strong>Phone:</strong> $phone</p>
<p><strong>Subject:</strong> $subject</p>
<p><strong>Message:</strong></p>
<p>$body</p>
<hr>
<p><small>Submitted at: {$_SERVER['REQUEST_TIME_FLOAT']}</small></p>
HTML;

            $mailer = new EstateMailer(Tenancy::platform());
            $mailer->sendHtml(
                $support_email,
                "New Contact Inquiry: $subject",
                $html_body,
                $reply_to
            );

            $message = 'Thank you for your inquiry! We have received your message and will get back to you shortly.';
        } catch (Throwable $e) {
            error_log('Contact form error: ' . $e->getMessage());
            $error = 'An error occurred while submitting your inquiry. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Us - <?php echo htmlspecialchars(PlatformSettings::get('site_company_name', 'Estate Management')); ?></title>
    <link rel="stylesheet" href="css/saas.css?v=<?php echo ASSET_VER; ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #f3f4f6 0%, #ffffff 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .contact-container {
            max-width: 1200px;
            margin: 60px auto;
            padding: 20px;
        }
        .contact-header {
            text-align: center;
            margin-bottom: 60px;
        }
        .contact-header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            color: #1f2937;
            margin: 0 0 15px 0;
        }
        .contact-header p {
            font-size: 1.1rem;
            color: #6b7280;
            margin: 0;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }
        .contact-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            margin-bottom: 60px;
        }
        @media (max-width: 768px) {
            .contact-grid {
                grid-template-columns: 1fr;
                gap: 30px;
            }
        }
        .contact-form {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.07);
        }
        .contact-form h2 {
            font-size: 1.4rem;
            font-weight: 600;
            color: #1f2937;
            margin: 0 0 25px 0;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-weight: 500;
            color: #374151;
            margin-bottom: 8px;
            font-size: 0.95rem;
        }
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-family: inherit;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .form-group textarea {
            resize: vertical;
            min-height: 120px;
        }
        .form-submit {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        .form-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(59, 130, 246, 0.4);
        }
        .alert {
            padding: 15px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        .alert-success {
            background: #d1fae5;
            border: 1px solid #6ee7b7;
            color: #065f46;
        }
        .alert-danger {
            background: #fee2e2;
            border: 1px solid #fca5a5;
            color: #7f1d1d;
        }
        .contact-info {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.07);
        }
        .contact-info h2 {
            font-size: 1.4rem;
            font-weight: 600;
            color: #1f2937;
            margin: 0 0 25px 0;
        }
        .info-item {
            margin-bottom: 30px;
            display: flex;
            gap: 15px;
        }
        .info-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        .info-content h3 {
            font-weight: 600;
            color: #1f2937;
            margin: 0 0 5px 0;
            font-size: 1rem;
        }
        .info-content p {
            color: #6b7280;
            margin: 0;
            font-size: 0.95rem;
        }
        .info-content a {
            color: #3b82f6;
            text-decoration: none;
        }
        .info-content a:hover {
            text-decoration: underline;
        }
    </style>
    <link rel="stylesheet" href="css/dashboard-theme-public.css?v=<?php echo defined('ASSET_VER') ? ASSET_VER : time(); ?>">
</head>
<body>
    <?php public_header(); ?>

    <div class="contact-container">
        <div class="contact-header">
            <h1>Get in Touch</h1>
            <p>Have a question or feedback? We'd love to hear from you. Send us a message and we'll respond as soon as possible.</p>
        </div>

        <div class="contact-grid">
            <div class="contact-form">
                <h2>Send Us a Message</h2>

                <?php if ($message): ?>
                    <div class="alert alert-success">
                        <i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fa-solid fa-circle-xmark"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-group">
                        <label for="name">Full Name *</label>
                        <input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address *</label>
                        <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone Number (Optional)</label>
                        <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="subject">Subject *</label>
                        <input type="text" id="subject" name="subject" required value="<?php echo htmlspecialchars($_POST['subject'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="message">Message *</label>
                        <textarea id="message" name="message" required><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
                    </div>

                    <button type="submit" class="form-submit">
                        <i class="fa-solid fa-paper-plane"></i> Send Message
                    </button>
                </form>
            </div>

            <div class="contact-info">
                <h2>Contact Information</h2>

                <?php if ($email = PlatformSettings::get('site_support_email')): ?>
                <div class="info-item">
                    <div class="info-icon">
                        <i class="fa-solid fa-envelope"></i>
                    </div>
                    <div class="info-content">
                        <h3>Email</h3>
                        <p><a href="mailto:<?php echo htmlspecialchars($email); ?>"><?php echo htmlspecialchars($email); ?></a></p>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($phone = PlatformSettings::get('site_support_phone')): ?>
                <div class="info-item">
                    <div class="info-icon">
                        <i class="fa-solid fa-phone"></i>
                    </div>
                    <div class="info-content">
                        <h3>Phone</h3>
                        <p><a href="tel:<?php echo htmlspecialchars($phone); ?>"><?php echo htmlspecialchars($phone); ?></a></p>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($addr = PlatformSettings::get('site_address')): ?>
                <div class="info-item">
                    <div class="info-icon">
                        <i class="fa-solid fa-map-pin"></i>
                    </div>
                    <div class="info-content">
                        <h3>Address</h3>
                        <p><?php echo htmlspecialchars($addr); ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($hours = PlatformSettings::get('site_support_hours')): ?>
                <div class="info-item">
                    <div class="info-icon">
                        <i class="fa-solid fa-clock"></i>
                    </div>
                    <div class="info-content">
                        <h3>Business Hours</h3>
                        <p><?php echo htmlspecialchars($hours); ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                    <h3 style="font-weight: 600; color: #1f2937; margin: 0 0 10px 0;">Quick Links</h3>
                    <ul style="list-style: none; padding: 0; margin: 0;">
                        <li style="margin-bottom: 10px;"><a href="/" style="color: #3b82f6; text-decoration: none;">Back to Home</a></li>
                        <li style="margin-bottom: 10px;"><a href="terms" style="color: #3b82f6; text-decoration: none;">Terms of Service</a></li>
                        <li style="margin-bottom: 10px;"><a href="privacy" style="color: #3b82f6; text-decoration: none;">Privacy Policy</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <?php public_footer(); ?>
</body>
</html>
