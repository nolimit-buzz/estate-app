<?php
// resident/receipts.php
require_once '../config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index");
    exit;
}

$user_id = intval($_SESSION['user_id']);
$estate_id = get_estate_id();

// Fetch Resident Receipts
$query = "SELECT r.id as receipt_id, r.receipt_number, r.amount as receipt_amount, r.issued_at,
                 p.id as payment_id, p.payment_method, p.payment_reference, p.transaction_ref, p.paid_at,
                 i.id as invoice_id, i.invoice_number, i.title as invoice_title
          FROM receipts r
          JOIN payments p ON r.payment_id = p.id
          LEFT JOIN invoices i ON p.invoice_id = i.id
          WHERE r.resident_id = $user_id AND r.estate_id = $estate_id
          ORDER BY r.issued_at DESC";
$receipts_res = $conn->query($query);

// Aggregate stats
$stats_query = "SELECT COUNT(r.id) as total_receipts, COALESCE(SUM(r.amount), 0) as total_spent, MAX(r.issued_at) as latest_receipt_date
                FROM receipts r
                WHERE r.resident_id = $user_id AND r.estate_id = $estate_id";
$stats = $conn->query($stats_query)->fetch_assoc();

include 'header.php';
include 'sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h4 font-bold text-slate-800 m-0"><i class="fa-solid fa-receipt text-primary me-2"></i> My Official Receipts</h2>
        <p class="text-secondary small mb-0">View, download, and print official receipts issued for your estate payments.</p>
    </div>
    <a href="finance" class="btn btn-outline-primary btn-sm fw-semibold" style="border-radius: 0.5rem; padding: 0.5rem 1rem;">
        <i class="fa-solid fa-file-invoice-dollar me-1"></i> Bills & Invoices
    </a>
</div>

<style>
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.25rem; margin-bottom: 2rem; }
    .kpi-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 1rem; padding: 1.25rem; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
    .card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 1rem; padding: 1.5rem; }
    .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 0.75rem; }
    .card-title { font-family: 'Outfit', sans-serif; font-size: 1.1rem; font-weight: 700; color: #1e293b; margin: 0; }
    .badge-method { font-size: 0.75rem; background: #f1f5f9; color: #475569; padding: 3px 8px; border-radius: 6px; font-weight: 600; text-transform: uppercase; }
    .btn-receipt { padding: 0.45rem 0.9rem; border-radius: 0.5rem; background: #2563eb; color: white; text-decoration: none; font-weight: 600; font-size: 0.82rem; display: inline-flex; align-items: center; gap: 0.4rem; transition: all 0.2s; }
    .btn-receipt:hover { background: #1d4ed8; color: white; }
    .search-box { padding: 0.55rem 1rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; font-size: 0.85rem; outline: none; width: 250px; }
    .search-box:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }
</style>

<!-- KPI Cards -->
<div class="kpi-grid">
    <div class="kpi-card" style="border-left: 4px solid #10b981;">
        <div style="color: var(--text-muted); font-weight: 600; font-size: 0.8rem; text-transform: uppercase;">Total Receipts Issued</div>
        <div style="font-family: 'Outfit'; font-size: 2rem; font-weight: 700; color: #059669; margin-top: 0.25rem;">
            <?= number_format($stats['total_receipts'] ?? 0) ?>
        </div>
    </div>
    <div class="kpi-card" style="border-left: 4px solid #3b82f6;">
        <div style="color: var(--text-muted); font-weight: 600; font-size: 0.8rem; text-transform: uppercase;">Total Amount Settled</div>
        <div style="font-family: 'Outfit'; font-size: 2rem; font-weight: 700; color: #1d4ed8; margin-top: 0.25rem;">
            ₦<?= number_format($stats['total_spent'] ?? 0, 2) ?>
        </div>
    </div>
    <div class="kpi-card" style="border-left: 4px solid #8b5cf6;">
        <div style="color: var(--text-muted); font-weight: 600; font-size: 0.8rem; text-transform: uppercase;">Most Recent Payment</div>
        <div style="font-family: 'Outfit'; font-size: 1.15rem; font-weight: 700; color: #6d28d9; margin-top: 0.45rem;">
            <?= !empty($stats['latest_receipt_date']) ? date('M j, Y', strtotime($stats['latest_receipt_date'])) : 'No receipts yet' ?>
        </div>
    </div>
</div>

<!-- Receipts Table Card -->
<div class="card">
    <div class="card-header flex-wrap gap-2">
        <h3 class="card-title"><i class="fa-solid fa-file-invoice" style="color: #2563eb; margin-right: 6px;"></i> All Issued Receipts & Proof of Payment</h3>
        <div>
            <input type="text" id="receiptSearch" placeholder="Search by receipt or invoice #..." class="search-box" onkeyup="filterReceipts()">
        </div>
    </div>

    <div style="overflow-x: auto;">
        <table id="receiptsTable" style="width: 100%; border-collapse: collapse; font-size: 0.92rem;">
            <thead>
                <tr style="border-bottom: 2px solid #e2e8f0; text-align: left; color: #64748b;">
                    <th style="padding: 12px 10px;">Receipt ID</th>
                    <th style="padding: 12px 10px;">Linked Invoice #</th>
                    <th style="padding: 12px 10px;">Billing Purpose</th>
                    <th style="padding: 12px 10px;">Amount Paid</th>
                    <th style="padding: 12px 10px;">Payment Method</th>
                    <th style="padding: 12px 10px;">Date Issued</th>
                    <th style="padding: 12px 10px; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($receipts_res && $receipts_res->num_rows > 0): ?>
                    <?php while ($r = $receipts_res->fetch_assoc()): ?>
                        <?php 
                        $rec_no = $r['receipt_number'] ?: ('REC-' . sprintf("%04d", $r['receipt_id']));
                        $inv_no = $r['invoice_number'] ?: ($r['invoice_id'] ? ('INV-' . sprintf("%04d", $r['invoice_id'])) : 'Direct Payment');
                        $method_label = strtoupper(str_replace(['paystack_', '_'], ['', ' '], $r['payment_method'] ?? 'ONLINE'));
                        ?>
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 12px 10px; font-family: monospace; font-weight: 700; color: #0284c7;">
                                <i class="fa-solid fa-receipt me-1" style="font-size: 0.75rem; opacity: 0.7;"></i><?= htmlspecialchars($rec_no) ?>
                            </td>
                            <td style="padding: 12px 10px; font-family: monospace; font-weight: 600; color: #64748b;">
                                <?= htmlspecialchars($inv_no) ?>
                            </td>
                            <td style="padding: 12px 10px; font-weight: 600; color: #1e293b;">
                                <?= htmlspecialchars($r['invoice_title'] ?? 'Estate Assessment Dues') ?>
                            </td>
                            <td style="padding: 12px 10px; font-weight: 700; color: #15803d; font-size: 0.95rem;">
                                ₦<?= number_format($r['receipt_amount'], 2) ?>
                            </td>
                            <td style="padding: 12px 10px;">
                                <span class="badge-method"><?= htmlspecialchars($method_label) ?></span>
                            </td>
                            <td style="padding: 12px 10px; color: #64748b; font-size: 0.85rem;">
                                <?= date('M j, Y h:i A', strtotime($r['issued_at'])) ?>
                            </td>
                            <td style="padding: 12px 10px; text-align: right;">
                                <a href="receipt?receipt_no=<?= urlencode($rec_no) ?>" class="btn-receipt">
                                    <i class="fa-solid fa-print"></i> View / Print Receipt
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 3rem 1rem;">
                            <i class="fa-solid fa-receipt fa-2x mb-2" style="opacity: 0.3; display: block;"></i>
                            No official receipts issued to your account yet. Complete an invoice payment to receive your receipt.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function filterReceipts() {
    const input = document.getElementById("receiptSearch");
    const filter = input.value.toUpperCase();
    const table = document.getElementById("receiptsTable");
    const tr = table.getElementsByTagName("tr");

    for (let i = 1; i < tr.length; i++) {
        let textContent = tr[i].textContent || tr[i].innerText;
        if (textContent.toUpperCase().indexOf(filter) > -1) {
            tr[i].style.display = "";
        } else {
            tr[i].style.display = "none";
        }
    }
}
</script>

<?php include 'footer.php'; ?>
