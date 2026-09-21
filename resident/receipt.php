<?php
// resident/receipt.php
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index");
    exit;
}

$estate_id = get_estate_id();
$receipt_no = $_GET['receipt_no'] ?? null;
$invoice_id = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : null;
$payment_id = isset($_GET['payment_id']) ? intval($_GET['payment_id']) : null;

// Query receipt or payment
$receipt = null;

if ($receipt_no) {
    $receipt_no = $conn->real_escape_string($receipt_no);
    $res = $conn->query("SELECT r.*, p.payment_reference, p.payment_method, p.paid_at, p.transaction_ref, p.description as payment_desc, 
                                u.name as resident_name, u.email, u.phone, 
                                u_staff.name as issued_by_name, u_staff.role as issued_by_role,
                                i.id as invoice_id, i.invoice_number, i.title as invoice_title, i.created_at as invoice_date, i.due_date, i.amount as invoice_amount 
                         FROM receipts r 
                         JOIN payments p ON r.payment_id = p.id 
                         JOIN users u ON r.resident_id = u.id 
                         LEFT JOIN invoices i ON p.invoice_id = i.id 
                         LEFT JOIN users u_staff ON r.issued_by = u_staff.id
                         WHERE r.receipt_number = '$receipt_no' AND r.estate_id = $estate_id");
    if ($res && $res->num_rows > 0) $receipt = $res->fetch_assoc();
} elseif ($invoice_id) {
    $res = $conn->query("SELECT r.id as receipt_id, r.receipt_number, r.issued_at, r.issued_by,
                                p.id as payment_id, p.payment_reference, p.payment_method, p.paid_at, p.transaction_ref, p.description as payment_desc, p.amount,
                                u.id as user_id, u.name as resident_name, u.email, u.phone, 
                                u_staff.name as issued_by_name, u_staff.role as issued_by_role,
                                i.id as invoice_id, i.invoice_number, i.title as invoice_title, i.created_at as invoice_date, i.due_date, i.property_id 
                         FROM invoices i 
                         JOIN payments p ON p.invoice_id = i.id 
                         JOIN users u ON i.user_id = u.id 
                         LEFT JOIN receipts r ON r.payment_id = p.id 
                         LEFT JOIN users u_staff ON r.issued_by = u_staff.id
                         WHERE i.id = $invoice_id AND i.estate_id = $estate_id");
    if ($res && $res->num_rows > 0) {
        $data = $res->fetch_assoc();
        if (!$data['receipt_number']) {
            // Generate receipt on the fly if missing
            $gen_no = generateReceiptNumber($conn);
            $prop_id = !empty($data['property_id']) ? intval($data['property_id']) : "NULL";
            $conn->query("INSERT INTO receipts (estate_id, receipt_number, payment_id, resident_id, property_id, amount, issued_at) 
                          VALUES ($estate_id, '$gen_no', {$data['payment_id']}, {$data['user_id']}, $prop_id, {$data['amount']}, NOW())");
            $data['receipt_number'] = $gen_no;
            $data['issued_at'] = date('Y-m-d H:i:s');
            $data['resident_id'] = $data['user_id'];
        } else {
            $data['resident_id'] = $data['user_id'];
        }
        $receipt = $data;
    }
}

if (!$receipt) {
    die("<div style='font-family:sans-serif; text-align:center; padding:4rem;'><h2>Receipt Not Found</h2><p>The requested receipt record does not exist or payment is still pending.</p><a href='finance' style='color:#2563eb;'>Return to Bills & Invoices</a></div>");
}

// Fetch Resident Property Info
$res_user_id = intval($receipt['resident_id'] ?? $receipt['user_id'] ?? 0);
$res_info = $conn->query("SELECT r.*, f.number as flat_number, b.name as building_name, s.name as street_name 
                          FROM residents r 
                          LEFT JOIN flats f ON r.flat_id = f.id 
                          LEFT JOIN buildings b ON f.building_id = b.id 
                          LEFT JOIN streets s ON b.street_id = s.id 
                          WHERE r.user_id = $res_user_id AND r.estate_id = $estate_id 
                          LIMIT 1")->fetch_assoc();

// Fetch System Settings
$sys = [];
$s_res = $conn->query("SELECT * FROM system_settings WHERE estate_id = $estate_id");
if ($s_res) {
    while ($r = $s_res->fetch_assoc()) $sys[$r['setting_key']] = $r['setting_value'];
}
$estate_name = $sys['estate_name'] ?? 'Estate Management';
$estate_location = $sys['estate_location'] ?? 'Estate Administrative Office';
$estate_phone = $sys['office_phone'] ?? '';
$estate_email = $sys['office_email'] ?? '';
$estate_logo = $sys['estate_logo'] ?? '';
$inv_number = $receipt['invoice_number'] ?: ('INV-' . str_pad($receipt['invoice_id'] ?? 0, 5, '0', STR_PAD_LEFT));
$receipt_number = $receipt['receipt_number'];
$channel_clean = strtoupper(str_replace(['paystack_', '_'], ['', ' '], $receipt['payment_method'] ?? 'Online Payment'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt <?= htmlspecialchars($receipt_number) ?> - <?= htmlspecialchars($estate_name) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Outfit', sans-serif;
            background: #f1f5f9;
            margin: 0;
            padding: 2.5rem 1rem;
            color: #1e293b;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .no-print {
            max-width: 820px;
            margin: 0 auto 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.65rem 1.25rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.9rem;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }
        .btn-print { background: #0284c7; color: white; box-shadow: 0 4px 6px -1px rgba(2, 132, 199, 0.3); }
        .btn-print:hover { background: #0369a1; }
        .btn-back { background: #ffffff; color: #475569; border: 1px solid #cbd5e1; }
        .btn-back:hover { background: #f8fafc; color: #0f172a; }

        /* Standard Receipt Document */
        .receipt-document {
            max-width: 820px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 1rem;
            box-shadow: 0 20px 25px -5px rgba(15, 23, 42, 0.08), 0 8px 10px -6px rgba(15, 23, 42, 0.04);
            border: 1px solid #e2e8f0;
            position: relative;
            overflow: hidden;
        }

        /* Top Accent Bar */
        .receipt-top-bar {
            height: 6px;
            background: linear-gradient(90deg, #10b981 0%, #0284c7 100%);
        }

        .receipt-inner {
            padding: 3rem 3rem 2.5rem;
            position: relative;
        }

        /* Watermark */
        .watermark-paid {
            position: absolute;
            top: 52%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-25deg);
            font-family: 'Outfit', sans-serif;
            font-size: 9rem;
            font-weight: 900;
            color: rgba(16, 185, 129, 0.045);
            letter-spacing: 0.15em;
            pointer-events: none;
            user-select: none;
            z-index: 0;
            border: 12px solid rgba(16, 185, 129, 0.045);
            padding: 0.5rem 3rem;
            border-radius: 1.5rem;
        }

        /* Header */
        .receipt-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 2rem;
            margin-bottom: 2rem;
            position: relative;
            z-index: 1;
        }
        .brand-box {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .brand-logo {
            width: 56px;
            height: 56px;
            border-radius: 0.75rem;
            object-fit: cover;
            border: 1px solid #e2e8f0;
        }
        .brand-logo-fallback {
            width: 56px;
            height: 56px;
            border-radius: 0.75rem;
            background: #eff6ff;
            color: #0284c7;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
        }
        .estate-info h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.45rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 0.25rem;
        }
        .estate-meta {
            font-size: 0.85rem;
            color: #64748b;
            line-height: 1.4;
        }

        .receipt-title-box {
            text-align: right;
        }
        .doc-tag {
            font-family: 'Outfit', sans-serif;
            font-size: 2rem;
            font-weight: 800;
            letter-spacing: 0.03em;
            color: #0f172a;
            margin: 0;
            text-transform: uppercase;
        }
        .receipt-number-badge {
            display: inline-block;
            font-family: monospace;
            font-size: 1rem;
            font-weight: 700;
            color: #0284c7;
            background: #eff6ff;
            padding: 4px 10px;
            border-radius: 6px;
            margin-top: 0.35rem;
            border: 1px solid #bfdbfe;
        }
        .status-badge-paid {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            background: #dcfce7;
            color: #15803d;
            font-weight: 700;
            font-size: 0.78rem;
            padding: 3px 10px;
            border-radius: 9999px;
            margin-top: 0.4rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* 2-Column Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
            position: relative;
            z-index: 1;
        }
        .info-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 1.25rem 1.5rem;
        }
        .info-card-title {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.45rem;
            font-size: 0.88rem;
        }
        .info-row:last-child { margin-bottom: 0; }
        .info-label { color: #64748b; }
        .info-val { font-weight: 600; color: #0f172a; text-align: right; }

        /* Items Statement Table */
        .statement-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2rem;
            position: relative;
            z-index: 1;
        }
        .statement-table th {
            background: #f8fafc;
            color: #475569;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 0.85rem 1rem;
            border-top: 1px solid #e2e8f0;
            border-bottom: 2px solid #cbd5e1;
            text-align: left;
        }
        .statement-table td {
            padding: 1.15rem 1rem;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.95rem;
            color: #334155;
        }
        .text-right { text-align: right !important; }

        /* Total Summary Box */
        .total-summary-card {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 2.5rem;
            position: relative;
            z-index: 1;
        }
        .total-box {
            width: 100%;
            max-width: 380px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 1.25rem 1.5rem;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.35rem 0;
            font-size: 0.9rem;
            color: #64748b;
        }
        .total-row.grand-total {
            border-top: 2px dashed #cbd5e1;
            margin-top: 0.5rem;
            padding-top: 0.75rem;
            color: #0f172a;
        }
        .grand-total .amount-text {
            font-family: 'Outfit', sans-serif;
            font-size: 1.65rem;
            font-weight: 800;
            color: #10b981;
        }

        /* Bottom Footer / Verification Box */
        .receipt-footer-box {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid #e2e8f0;
            padding-top: 1.5rem;
            position: relative;
            z-index: 1;
        }
        .verification-note {
            max-width: 460px;
            font-size: 0.78rem;
            color: #64748b;
            line-height: 1.5;
        }
        .authorized-stamp {
            text-align: right;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }
        .stamp-circle {
            border: 2px dashed #10b981;
            color: #10b981;
            padding: 0.35rem 0.85rem;
            border-radius: 0.5rem;
            font-family: 'Outfit', sans-serif;
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        /* Print Media Styles */
        @media print {
            body {
                background: #ffffff;
                padding: 0;
                margin: 0;
            }
            .no-print {
                display: none !important;
            }
            .receipt-document {
                border: none;
                box-shadow: none;
                max-width: 100%;
                border-radius: 0;
            }
            .receipt-inner {
                padding: 1.5rem 1.5rem 1rem;
            }
            .watermark-paid {
                opacity: 0.08 !important;
            }
        }
    </style>
</head>
<body>

    <div class="no-print">
        <a href="finance" class="btn-action btn-back">
            <i class="fa-solid fa-arrow-left"></i> Return to Bills & Invoices
        </a>
        <button onclick="window.print()" class="btn-action btn-print">
            <i class="fa-solid fa-print"></i> Print Official Receipt
        </button>
    </div>

    <div class="receipt-document">
        <div class="receipt-top-bar"></div>
        <div class="watermark-paid">PAID</div>

        <div class="receipt-inner">
            <!-- Header Section -->
            <div class="receipt-header">
                <div class="brand-box">
                    <?php if (!empty($estate_logo) && (file_exists($estate_logo) || file_exists('../' . ltrim($estate_logo, './')))): ?>
                        <?php $logo_src = file_exists($estate_logo) ? $estate_logo : ('../' . ltrim($estate_logo, './')); ?>
                        <img src="<?= htmlspecialchars($logo_src) ?>" alt="Estate Logo" class="brand-logo">
                    <?php else: ?>
                        <div class="brand-logo-fallback"><i class="fa-solid fa-building-shield"></i></div>
                    <?php endif; ?>
                    <div class="estate-info">
                        <h2><?= htmlspecialchars($estate_name) ?></h2>
                        <div class="estate-meta">
                            <div><i class="fa-solid fa-location-dot me-1"></i> <?= htmlspecialchars($estate_location) ?></div>
                            <?php if ($estate_email || $estate_phone): ?>
                                <div><?= htmlspecialchars($estate_email) ?><?= ($estate_email && $estate_phone) ? ' &bull; ' : '' ?><?= htmlspecialchars($estate_phone) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="receipt-title-box">
                    <h1 class="doc-tag">RECEIPT</h1>
                    <div><span class="receipt-number-badge"><?= htmlspecialchars($receipt_number) ?></span></div>
                    <div><span class="status-badge-paid"><i class="fa-solid fa-circle-check"></i> Payment Completed</span></div>
                </div>
            </div>

            <!-- 2-Column Info Grid -->
            <div class="info-grid">
                <!-- Received From -->
                <div class="info-card">
                    <div class="info-card-title"><i class="fa-solid fa-user-check text-primary"></i> Received From (Resident)</div>
                    <div class="info-row">
                        <span class="info-label">Full Name:</span>
                        <span class="info-val" style="color: #0f172a; font-size: 1rem;"><?= htmlspecialchars($receipt['resident_name']) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Property Unit:</span>
                        <span class="info-val"><?= htmlspecialchars(($res_info['street_name'] ?? 'Main Street') . ' - ' . ($res_info['building_name'] ?? 'Block') . ' Flat ' . ($res_info['flat_number'] ?? 'N/A')) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Resident ID:</span>
                        <span class="info-val" style="font-family: monospace;"><?= htmlspecialchars($res_info['custom_id'] ?? ('RES-' . str_pad($res_user_id, 5, '0', STR_PAD_LEFT))) ?></span>
                    </div>
                    <?php if (!empty($receipt['phone'])): ?>
                    <div class="info-row">
                        <span class="info-label">Contact Phone:</span>
                        <span class="info-val"><?= htmlspecialchars($receipt['phone']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Transaction Details -->
                <div class="info-card">
                    <div class="info-card-title"><i class="fa-solid fa-receipt text-primary"></i> Payment Details</div>
                    <div class="info-row">
                        <span class="info-label">Invoice Ref:</span>
                        <span class="info-val" style="font-family: monospace; color: #2563eb;"><?= htmlspecialchars($inv_number) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Payment Date:</span>
                        <span class="info-val"><?= date('M j, Y h:i A', strtotime($receipt['issued_at'] ?? $receipt['paid_at'] ?? 'now')) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Payment Channel:</span>
                        <span class="info-val"><span style="background: #e2e8f0; padding: 2px 7px; border-radius: 4px; font-size: 0.78rem; text-transform: uppercase;"><?= htmlspecialchars($channel_clean) ?></span></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Transaction Ref:</span>
                        <span class="info-val" style="font-family: monospace; font-size: 0.8rem;"><?= htmlspecialchars($receipt['payment_reference'] ?? $receipt['transaction_ref'] ?? ('TXN-' . $receipt['payment_id'])) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Processed By:</span>
                        <span class="info-val">
                            <?php if (!empty($receipt['issued_by_name'])): ?>
                                <strong style="color: #0f172a;"><i class="fa-solid fa-user-shield text-primary me-1"></i><?= htmlspecialchars($receipt['issued_by_name']) ?></strong> 
                                <span style="background: #e0f2fe; color: #0369a1; font-size: 0.72rem; font-weight: 600; padding: 2px 6px; border-radius: 4px;"><?= htmlspecialchars(ucfirst($receipt['issued_by_role'] ?? 'Staff')) ?></span>
                            <?php else: ?>
                                <span style="color: #059669; font-weight: 600;"><i class="fa-solid fa-bolt me-1"></i>Electronic Payment (Paystack)</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Itemized Table -->
            <table class="statement-table">
                <thead>
                    <tr>
                        <th style="width: 8%;">#</th>
                        <th style="width: 52%;">Billing Description</th>
                        <th style="width: 20%;">Invoice Number</th>
                        <th style="width: 20%;" class="text-right">Amount Paid</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="font-weight: 700; color: #64748b;">01</td>
                        <td>
                            <div style="font-weight: 700; color: #0f172a; font-size: 1rem;"><?= htmlspecialchars($receipt['invoice_title'] ?? 'Estate Service Charge') ?></div>
                            <div style="font-size: 0.8rem; color: #64748b; margin-top: 2px;"><?= htmlspecialchars($receipt['payment_desc'] ?? 'Verified electronic estate billing payment') ?></div>
                        </td>
                        <td style="font-family: monospace; font-weight: 600; color: #2563eb;">
                            <?= htmlspecialchars($inv_number) ?>
                        </td>
                        <td class="text-right" style="font-weight: 700; color: #0f172a; font-size: 1.05rem;">
                            ₦<?= number_format($receipt['amount'], 2) ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Summary Card -->
            <div class="total-summary-card">
                <div class="total-box">
                    <div class="total-row">
                        <span>Invoice Subtotal:</span>
                        <span style="font-weight: 600; color: #0f172a;">₦<?= number_format($receipt['amount'], 2) ?></span>
                    </div>
                    <div class="total-row">
                        <span>Processing / Gateway Fee:</span>
                        <span style="font-weight: 600; color: #0f172a;">₦0.00</span>
                    </div>
                    <div class="total-row grand-total">
                        <span style="font-weight: 700; font-size: 1.05rem;">Total Paid:</span>
                        <span class="amount-text">₦<?= number_format($receipt['amount'], 2) ?></span>
                    </div>
                    <div class="total-row" style="margin-top: 0.35rem; font-size: 0.8rem; color: #10b981; font-weight: 700; justify-content: flex-end;">
                        <i class="fa-solid fa-circle-check me-1"></i> Balance Due: ₦0.00 (Settled in Full)
                    </div>
                </div>
            </div>

            <!-- Footer & Signature Stamp -->
            <div class="receipt-footer-box">
                <div class="verification-note">
                    <div style="font-weight: 700; color: #334155; margin-bottom: 2px;"><i class="fa-solid fa-lock text-success me-1"></i> Verified Electronic Document</div>
                    This official electronic receipt is issued by <?= htmlspecialchars($estate_name) ?> Financial Portal. Retain this receipt as valid legal proof of payment for the stated estate billing period.
                </div>
                <div class="authorized-stamp">
                    <div class="stamp-circle">
                        <i class="fa-solid fa-stamp"></i> Officially Verified
                    </div>
                    <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.35rem;">
                        <?= !empty($receipt['issued_by_name']) ? 'Receipt Issued by: ' . htmlspecialchars($receipt['issued_by_name']) . ' (' . ucfirst($receipt['issued_by_role'] ?? 'Staff') . ')' : 'Estate Financial Administration' ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

</body>
</html>
