<?php
// zone/receipt.php - Official Printable Zonal Payment Receipt
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth_guard.php';

requireZoneAccess();

$zone_id = get_current_zone_id();
$estate_id = get_estate_id();
$receipt_no = isset($_GET['no']) ? $conn->real_escape_string(trim($_GET['no'])) : (isset($_GET['receipt_number']) ? $conn->real_escape_string(trim($_GET['receipt_number'])) : '');
$inv_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : 0);

$where_clause = !empty($receipt_no) ? "r.receipt_number = '$receipt_no'" : "i.id = $inv_id";

$sql = "SELECT r.*, r.id as receipt_id, r.receipt_number, r.issued_at, r.issued_by,
               i.id as invoice_id, i.invoice_number, i.title as invoice_title, i.amount as invoice_amount, i.status as invoice_status, i.due_date,
               u.name as resident_name, u.email as resident_email, u.phone as resident_phone,
               p.transaction_ref, p.payment_method, p.paid_at, p.amount as payment_amount,
               f.number as flat_number, b.name as building_name, s.name as street_name,
               u_staff.name as issued_by_name,
               z.name as zone_name, z.code as zone_code
        FROM receipts r
        JOIN payments p ON r.payment_id = p.id
        JOIN invoices i ON p.invoice_id = i.id
        JOIN users u ON r.resident_id = u.id
        LEFT JOIN flats f ON i.flat_id = f.id
        LEFT JOIN buildings b ON f.building_id = b.id
        LEFT JOIN streets s ON b.street_id = s.id
        LEFT JOIN zones z ON s.zone_id = z.id
        LEFT JOIN users u_staff ON r.issued_by = u_staff.id
        WHERE ($where_clause) AND (i.zone_id = $zone_id OR s.zone_id = $zone_id) AND r.estate_id = $estate_id
        LIMIT 1";

$res = $conn->query($sql);
if (!$res || $res->num_rows === 0) {
    die("<div style='font-family:sans-serif; text-align:center; padding:4rem;'>
            <h3>Receipt Not Found</h3>
            <p>The requested receipt could not be found or does not belong to your assigned zone.</p>
            <a href='finance' style='color:#7e22ce;'>Return to Zone Invoices</a>
         </div>");
}

$data = $res->fetch_assoc();

// Fetch Estate Branding Settings
$sys = [];
$settings_res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE estate_id = $estate_id");
if ($settings_res) {
    while ($row = $settings_res->fetch_assoc()) {
        $sys[$row['setting_key']] = $row['setting_value'];
    }
}
$estate_name = !empty($sys['estate_name']) ? $sys['estate_name'] : 'Estate Management';
$estate_logo = $sys['estate_logo'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt #<?php echo htmlspecialchars($data['receipt_number']); ?> - <?php echo htmlspecialchars($data['zone_name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Outfit', sans-serif; background: #f8fafc; color: #1e293b; margin: 0; padding: 2rem 1rem; }
        .receipt-card { max-width: 680px; margin: 0 auto; background: white; border-radius: 1rem; box-shadow: 0 10px 25px rgba(0,0,0,0.05); padding: 2.5rem; border: 1px solid #e2e8f0; }
        .receipt-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px dashed #e2e8f0; padding-bottom: 1.5rem; margin-bottom: 1.5rem; }
        .receipt-title { font-size: 1.5rem; font-weight: 800; color: #4c1d95; margin: 0 0 0.25rem; }
        .badge-paid { background: #dcfce7; color: #166534; font-weight: 700; padding: 0.35rem 0.85rem; border-radius: 9999px; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 0.35rem; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem; }
        .info-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; font-weight: 600; margin-bottom: 0.25rem; }
        .info-value { font-size: 0.95rem; font-weight: 600; color: #0f172a; }
        .item-table { width: 100%; border-collapse: collapse; margin-bottom: 1.5rem; }
        .item-table th { background: #f8fafc; padding: 0.75rem 1rem; text-align: left; font-size: 0.8rem; text-transform: uppercase; color: #64748b; border-bottom: 1px solid #e2e8f0; }
        .item-table td { padding: 1rem; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; }
        .total-strip { background: #faf5ff; border-radius: 0.75rem; padding: 1.25rem; display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border: 1px solid #f3e8ff; }
        .total-amount { font-size: 1.75rem; font-weight: 800; color: #6b21a8; }
        .action-bar { display: flex; justify-content: flex-end; gap: 1rem; max-width: 680px; margin: 1.5rem auto 0; }
        .btn { padding: 0.65rem 1.25rem; border-radius: 0.5rem; font-weight: 600; font-size: 0.9rem; cursor: pointer; border: none; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; }
        .btn-print { background: #6b21a8; color: white; }
        .btn-back { background: #e2e8f0; color: #1e293b; }
        @media print { .action-bar { display: none; } body { background: white; padding: 0; } .receipt-card { box-shadow: none; border: none; padding: 0; } }
    </style>
</head>
<body>

<div class="action-bar">
    <a href="finance" class="btn btn-back"><i class="fa-solid fa-arrow-left"></i> Back</a>
    <button onclick="window.print()" class="btn btn-print"><i class="fa-solid fa-print"></i> Print Receipt</button>
</div>

<div class="receipt-card">
    <div class="receipt-header">
        <div>
            <h1 class="receipt-title"><?php echo htmlspecialchars($estate_name); ?></h1>
            <div style="font-weight: 600; color: #7e22ce; font-size: 0.9rem;">
                <i class="fa-solid fa-layer-group me-1"></i> <?php echo htmlspecialchars($data['zone_name']); ?> (<?php echo htmlspecialchars($data['zone_code']); ?>)
            </div>
            <small style="color: #64748b;">Official Zonal Collection Receipt</small>
        </div>
        <div style="text-align: right;">
            <div class="badge-paid"><i class="fa-solid fa-circle-check"></i> PAYMENT CONFIRMED</div>
            <div style="font-family: monospace; font-weight: 700; margin-top: 0.5rem; color: #0f172a; font-size: 1rem;">
                #<?php echo htmlspecialchars($data['receipt_number']); ?>
            </div>
        </div>
    </div>

    <div class="info-grid">
        <div>
            <div class="info-label">Paid By (Resident)</div>
            <div class="info-value"><?php echo htmlspecialchars($data['resident_name']); ?></div>
            <div style="font-size: 0.85rem; color: #64748b;"><?php echo htmlspecialchars($data['resident_email']); ?> • <?php echo htmlspecialchars($data['resident_phone']); ?></div>
        </div>
        <div>
            <div class="info-label">Unit & Location</div>
            <div class="info-value">Unit <?php echo htmlspecialchars($data['flat_number'] ?: 'N/A'); ?></div>
            <div style="font-size: 0.85rem; color: #64748b;"><?php echo htmlspecialchars($data['building_name'] . ', ' . $data['street_name']); ?></div>
        </div>
        <div>
            <div class="info-label">Payment Date & Method</div>
            <div class="info-value"><?php echo date('M d, Y - h:i A', strtotime($data['paid_at'] ?: $data['issued_at'])); ?></div>
            <div style="font-size: 0.85rem; color: #64748b;"><?php echo htmlspecialchars($data['payment_method'] ?: 'Direct Transfer'); ?></div>
        </div>
        <div>
            <div class="info-label">Transaction Reference</div>
            <div class="info-value font-monospace" style="font-size: 0.85rem;"><?php echo htmlspecialchars($data['transaction_ref'] ?: 'N/A'); ?></div>
            <div style="font-size: 0.85rem; color: #64748b;">Invoice: #<?php echo htmlspecialchars($data['invoice_number']); ?></div>
        </div>
    </div>

    <table class="item-table">
        <thead>
            <tr>
                <th>Description / Purpose</th>
                <th style="text-align: right;">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <strong><?php echo htmlspecialchars($data['invoice_title']); ?></strong>
                    <div style="color: #64748b; font-size: 0.8rem;">Zonal collection under <?php echo htmlspecialchars($data['zone_name']); ?></div>
                </td>
                <td style="text-align: right; font-weight: 700; font-size: 1rem;">
                    ₦<?php echo number_format($data['payment_amount'], 2); ?>
                </td>
            </tr>
        </tbody>
    </table>

    <div class="total-strip">
        <div>
            <div style="font-size: 0.8rem; text-transform: uppercase; color: #6b21a8; font-weight: 700;">Total Amount Paid</div>
            <small style="color: #64748b;">Balance remaining: ₦0.00</small>
        </div>
        <div class="total-amount">
            ₦<?php echo number_format($data['payment_amount'], 2); ?>
        </div>
    </div>

    <div style="border-top: 1px solid #f1f5f9; padding-top: 1.25rem; display: flex; justify-content: space-between; font-size: 0.8rem; color: #94a3b8;">
        <div>Issued By: <?php echo htmlspecialchars($data['issued_by_name'] ?: 'Zone Administrator'); ?></div>
        <div>Generated Electronically • Valid Zonal Receipt</div>
    </div>
</div>

</body>
</html>
