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

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-4">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <h1 class="h4 font-bold text-slate-900 m-0" style="letter-spacing: -0.02em;">
                <i class="fa-solid fa-receipt text-primary me-2"></i> Official Receipts Repository
            </h1>
            <span class="mature-badge mature-badge-emerald"><i class="fa-solid fa-shield-check me-1"></i>Verified Ledger</span>
        </div>
        <p class="text-secondary small mb-0">View, download, and print official verified receipts issued for your estate assessment settlements.</p>
    </div>
    <a href="finance" class="btn btn-outline-primary btn-sm rounded-pill px-3 fw-semibold">
        <i class="fa-solid fa-file-invoice-dollar me-1"></i> Bills & Invoices
    </a>
</div>

<!-- ==========================================
     RECEIPTS SUMMARY KPI CARDS
     ========================================== -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
        <div class="resident-kpi-card kpi-accent-success h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="kpi-title">Total Receipts Issued</span>
                <div class="kpi-icon-wrap" style="background: rgba(16, 185, 129, 0.1); color: #10b981; border-color: rgba(16, 185, 129, 0.25);">
                    <i class="fa-solid fa-receipt"></i>
                </div>
            </div>
            <div class="kpi-value text-success" style="font-size: 2rem; font-weight: 800; letter-spacing: -0.02em;">
                <?= number_format($stats['total_receipts'] ?? 0) ?>
            </div>
            <div class="kpi-meta justify-content-between mt-2 pt-2 border-top border-light-subtle">
                <span>Verified Gateway Proofs</span>
                <span class="mature-badge mature-badge-emerald">All Verified</span>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-4">
        <div class="resident-kpi-card kpi-accent-primary h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="kpi-title">Total Amount Settled</span>
                <div class="kpi-icon-wrap" style="background: rgba(59, 130, 246, 0.1); color: #3b82f6; border-color: rgba(59, 130, 246, 0.25);">
                    <i class="fa-solid fa-coins"></i>
                </div>
            </div>
            <div class="kpi-value text-primary" style="font-size: 2rem; font-weight: 800; letter-spacing: -0.02em;">
                ₦<?= number_format($stats['total_spent'] ?? 0, 2) ?>
            </div>
            <div class="kpi-meta justify-content-between mt-2 pt-2 border-top border-light-subtle">
                <span>Total Assessment Payments</span>
                <span class="mature-badge mature-badge-sky">Audit Ready</span>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-4">
        <div class="resident-kpi-card kpi-accent-purple h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="kpi-title">Most Recent Payment</span>
                <div class="kpi-icon-wrap" style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6; border-color: rgba(139, 92, 246, 0.25);">
                    <i class="fa-regular fa-clock"></i>
                </div>
            </div>
            <div class="kpi-value" style="color: #6d28d9; font-size: 1.35rem; font-weight: 700; margin: 0.35rem 0;">
                <?= !empty($stats['latest_receipt_date']) ? date('M j, Y', strtotime($stats['latest_receipt_date'])) : 'No receipts yet' ?>
            </div>
            <div class="kpi-meta justify-content-between mt-2 pt-2 border-top border-light-subtle">
                <span>Last Gateway Timestamp</span>
                <span class="mature-badge mature-badge-purple">Reconciled</span>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     RECEIPTS DATA TABLE PANEL
     ========================================== -->
<div class="resident-glass-panel">
    <div class="resident-card-header flex-wrap gap-2">
        <div class="resident-card-title">
            <i class="fa-solid fa-file-invoice text-primary"></i> All Issued Proofs of Payment
        </div>
        <div style="min-width: 260px;">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-transparent border-end-0 text-secondary"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input type="text" id="receiptSearch" placeholder="Search receipt or invoice #..." class="form-control border-start-0 rounded-end-pill" onkeyup="filterReceipts()">
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table id="receiptsTable" class="table dashboard-table align-middle mb-0">
            <thead>
                <tr>
                    <th>Receipt ID</th>
                    <th>Linked Invoice #</th>
                    <th>Billing Purpose</th>
                    <th>Amount Paid</th>
                    <th>Payment Method</th>
                    <th>Date Issued</th>
                    <th style="text-align: right;">Action</th>
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
                        <tr>
                            <td>
                                <span class="mature-badge mature-badge-sky font-monospace" style="font-size: 0.78rem;">
                                    <i class="fa-solid fa-receipt me-1"></i><?= htmlspecialchars($rec_no) ?>
                                </span>
                            </td>
                            <td>
                                <span class="font-monospace text-secondary small">
                                    <?= htmlspecialchars($inv_no) ?>
                                </span>
                            </td>
                            <td>
                                <div class="fw-semibold text-slate-900"><?= htmlspecialchars($r['invoice_title'] ?? 'Estate Assessment Dues') ?></div>
                            </td>
                            <td>
                                <span class="fw-bold text-success font-monospace" style="font-size: 0.95rem;">
                                    ₦<?= number_format($r['receipt_amount'], 2) ?>
                                </span>
                            </td>
                            <td>
                                <span class="mature-badge mature-badge-slate">
                                    <?= htmlspecialchars($method_label) ?>
                                </span>
                            </td>
                            <td class="small text-secondary">
                                <i class="fa-regular fa-calendar-check me-1"></i>
                                <?= date('M j, Y h:i A', strtotime($r['issued_at'])) ?>
                            </td>
                            <td style="text-align: right;">
                                <a href="receipt?receipt_no=<?= urlencode($rec_no) ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-semibold">
                                    <i class="fa-solid fa-print me-1"></i> Print / PDF
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-secondary small">
                            <i class="fa-solid fa-receipt fa-2x mb-2 text-muted" style="opacity: 0.35; display: block;"></i>
                            No official receipts issued to your account yet. Complete an invoice payment to generate your verified receipt.
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
