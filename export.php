<?php
/**
 * export.php — PawnHub Data Export (Print-to-PDF)
 * Accessible by: admin (Enterprise only), manager (Enterprise only)
 * Records: Pawn Tickets, Customers, Inventory, Audit Logs, Payment History
 */

if (!file_exists(__DIR__ . '/session_helper.php')) {
    die('session_helper.php not found. Please upload it.');
}
require_once __DIR__ . '/session_helper.php';

// Try admin session first
pawnhub_session_start('admin');
$u = $_SESSION['user'] ?? null;

// If no admin session, try manager session
if (!$u || !in_array($u['role'] ?? '', ['admin', 'manager'])) {
    session_write_close();
    pawnhub_session_start('manager');
    $u = $_SESSION['user'] ?? null;
}

// If still no valid session, redirect to login
if (!$u || !in_array($u['role'] ?? '', ['admin', 'manager'])) {
    header('Location: login.php'); exit;
}

$tid = $u['tenant_id'];

require 'db.php';
require 'theme_helper.php';

// ── Check Enterprise plan ─────────────────────────────────────
$plan_row = $pdo->prepare("SELECT plan, business_name, phone, address FROM tenants WHERE id=? LIMIT 1");
$plan_row->execute([$tid]);
$tenant_row  = $plan_row->fetch();
$tenant_plan = strtolower($tenant_row['plan'] ?? 'starter');
$business    = $tenant_row['business_name'] ?? 'PawnHub Branch';

if ($tenant_plan !== 'enterprise') {
    $redirect = ($u['role'] === 'manager') ? 'manager.php' : 'tenant.php';
    header("Location: $redirect"); exit;
}

$theme    = getTenantTheme($pdo, $tid);
$primary  = $theme['primary_color']   ?? '#2563eb';
$secondary= $theme['secondary_color'] ?? '#1e3a8a';

// ── What to export ────────────────────────────────────────────
$type      = $_GET['type'] ?? 'tickets';
$date_from = $_GET['from'] ?? date('Y-m-01');
$date_to   = $_GET['to']   ?? date('Y-m-d');
$valid_types = ['tickets', 'customers', 'inventory', 'audit', 'payments'];
if (!in_array($type, $valid_types)) $type = 'tickets';

// ── Fetch data based on type ──────────────────────────────────
$rows    = [];
$columns = [];
$title   = '';

switch ($type) {
    case 'tickets':
        $title   = 'Pawn Tickets';
        $columns = ['Ticket No.', 'Customer', 'Contact', 'Item', 'Category', 'Loan Amount', 'Total Redeem', 'Maturity Date', 'Expiry Date', 'Status', 'Date Created'];
        $stmt    = $pdo->prepare("SELECT ticket_no, customer_name, contact_number, item_name, item_category, loan_amount, total_redeem, maturity_date, expiry_date, status, created_at FROM pawn_transactions WHERE tenant_id=? AND DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC");
        $stmt->execute([$tid, $date_from, $date_to]);
        $rows = $stmt->fetchAll();
        break;

    case 'customers':
        $title   = 'Customer Records';
        $columns = ['Full Name', 'Contact', 'Email', 'Gender', 'Address', 'ID Type', 'ID Number', 'Registered'];
        $stmt    = $pdo->prepare("SELECT full_name, contact_number, email, gender, address, valid_id_type, valid_id_number, registered_at FROM customers WHERE tenant_id=? AND DATE(registered_at) BETWEEN ? AND ? ORDER BY full_name ASC");
        $stmt->execute([$tid, $date_from, $date_to]);
        $rows = $stmt->fetchAll();
        break;

    case 'inventory':
        $title   = 'Item Inventory';
        $columns = ['Ticket No.', 'Item Name', 'Category', 'Appraisal Value', 'Loan Amount', 'Status', 'Date Received'];
        $stmt    = $pdo->prepare("SELECT ticket_no, item_name, item_category, appraisal_value, loan_amount, status, received_at FROM item_inventory WHERE tenant_id=? AND DATE(received_at) BETWEEN ? AND ? ORDER BY received_at DESC");
        $stmt->execute([$tid, $date_from, $date_to]);
        $rows = $stmt->fetchAll();
        break;

    case 'audit':
        $title   = 'Audit Logs';
        $columns = ['Date & Time', 'Actor', 'Role', 'Action', 'Ref #', 'Message', 'IP Address'];
        $stmt    = $pdo->prepare("SELECT created_at, actor_username, actor_role, action, entity_id, message, ip_address FROM audit_logs WHERE tenant_id=? AND actor_role IN ('manager','staff','cashier') AND DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC");
        $stmt->execute([$tid, $date_from, $date_to]);
        $rows = $stmt->fetchAll();
        break;

    case 'payments':
        $title   = 'Payment History';
        $columns = ['Date', 'Ticket No.', 'Action', 'OR No.', 'Amount Due', 'Cash Received', 'Change', 'Staff', 'Role'];
        $stmt    = $pdo->prepare("SELECT created_at, ticket_no, action, or_no, amount_due, cash_received, change_amount, staff_username, staff_role FROM payment_transactions WHERE tenant_id=? AND DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC");
        $stmt->execute([$tid, $date_from, $date_to]);
        $rows = $stmt->fetchAll();
        break;
}

$total_rows   = count($rows);
$generated_at = date('F j, Y g:i A');
$period       = date('M d, Y', strtotime($date_from)) . ' — ' . date('M d, Y', strtotime($date_to));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?=htmlspecialchars($business)?> — <?=htmlspecialchars($title)?> Export</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', Arial, sans-serif; background: #f8fafc; color: #1e293b; font-size: 13px; }

/* ── CONTROLS (hidden on print) ── */
.controls {
    position: fixed; top: 0; left: 0; right: 0; z-index: 100;
    background: #fff; border-bottom: 1px solid #e2e8f0;
    padding: 12px 24px; display: flex; align-items: center;
    gap: 12px; flex-wrap: wrap; box-shadow: 0 2px 8px rgba(0,0,0,.06);
}
.controls-left { display: flex; align-items: center; gap: 12px; flex: 1; flex-wrap: wrap; }
.ctrl-label { font-size: .72rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .06em; }
.ctrl-select, .ctrl-input {
    padding: 7px 11px; border: 1.5px solid #e2e8f0; border-radius: 8px;
    font-size: .82rem; color: #1e293b; background: #f8fafc; outline: none;
    font-family: inherit; cursor: pointer;
}
.ctrl-select:focus, .ctrl-input:focus { border-color: <?=$primary?>; }
.btn-print {
    padding: 9px 22px; background: linear-gradient(135deg, <?=$secondary?>, <?=$primary?>);
    color: #fff; border: none; border-radius: 9px; font-size: .85rem;
    font-weight: 700; cursor: pointer; display: flex; align-items: center;
    gap: 6px; font-family: inherit; box-shadow: 0 3px 12px <?=$primary?>44;
}
.btn-print:hover { filter: brightness(1.1); }
.btn-back {
    padding: 9px 16px; background: #f1f5f9; color: #475569;
    border: 1.5px solid #e2e8f0; border-radius: 9px; font-size: .82rem;
    font-weight: 600; cursor: pointer; text-decoration: none;
    display: flex; align-items: center; gap: 5px; font-family: inherit;
}

/* ── DOCUMENT ── */
.document { max-width: 1100px; margin: 80px auto 40px; background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,.08); }

/* ── HEADER ── */
.doc-header {
    background: linear-gradient(135deg, <?=$secondary?>, <?=$primary?>);
    padding: 28px 36px; color: #fff;
    display: flex; justify-content: space-between; align-items: flex-start;
}
.doc-header-left .biz-name { font-size: 1.2rem; font-weight: 800; margin-bottom: 4px; }
.doc-header-left .biz-sub  { font-size: .75rem; opacity: .7; }
.doc-header-left .biz-contact { font-size: .72rem; opacity: .6; margin-top: 8px; }
.doc-header-right { text-align: right; }
.doc-title { font-size: 1.4rem; font-weight: 800; margin-bottom: 4px; }
.doc-period { font-size: .75rem; opacity: .7; }
.doc-generated { font-size: .68rem; opacity: .5; margin-top: 4px; }

/* ── META STRIP ── */
.doc-meta {
    padding: 12px 36px; background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex; align-items: center; gap: 24px; flex-wrap: wrap;
}
.meta-item { font-size: .74rem; color: #64748b; display: flex; align-items: center; gap: 5px; }
.meta-item strong { color: #1e293b; }
.meta-badge {
    padding: 3px 10px; border-radius: 100px; font-size: .68rem;
    font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
}
.badge-tickets  { background: #dbeafe; color: #1d4ed8; }
.badge-customers{ background: #dcfce7; color: #15803d; }
.badge-inventory{ background: #fef9c3; color: #854d0e; }
.badge-audit    { background: #fce7f3; color: #9d174d; }
.badge-payments { background: #ede9fe; color: #6d28d9; }

/* ── TABLE ── */
.table-wrap { padding: 20px 36px 32px; overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
thead tr { background: <?=$primary?>18; }
th {
    padding: 10px 12px; font-size: .68rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .07em;
    color: <?=$secondary?>; text-align: left;
    border-bottom: 2px solid <?=$primary?>33;
    white-space: nowrap;
}
td {
    padding: 10px 12px; font-size: .8rem; color: #374151;
    border-bottom: 1px solid #f1f5f9; vertical-align: middle;
}
tr:last-child td { border-bottom: none; }
tr:hover td { background: <?=$primary?>08; }
tr:nth-child(even) td { background: #fafafa; }

.ticket-tag { font-family: monospace; font-weight: 700; color: <?=$primary?>; font-size: .78rem; }
.status-badge {
    display: inline-block; padding: 2px 8px; border-radius: 100px;
    font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
}
.s-stored    { background: #dbeafe; color: #1d4ed8; }
.s-released  { background: #dcfce7; color: #15803d; }
.s-renewed   { background: #fef9c3; color: #854d0e; }
.s-voided    { background: #fee2e2; color: #b91c1c; }
.s-auctioned { background: #ede9fe; color: #6d28d9; }
.s-pawned    { background: #dbeafe; color: #1d4ed8; }
.s-redeemed  { background: #dcfce7; color: #15803d; }

/* ── EMPTY ── */
.empty-state { text-align: center; padding: 60px 20px; color: #94a3b8; }
.empty-state .icon { font-size: 3rem; margin-bottom: 12px; }

/* ── FOOTER ── */
.doc-footer {
    padding: 14px 36px; background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    display: flex; justify-content: space-between; align-items: center;
    font-size: .71rem; color: #94a3b8;
}

/* ── PRINT STYLES ── */
@media print {
    .controls { display: none !important; }
    body { background: #fff; font-size: 11px; }
    .document { margin: 0; border-radius: 0; box-shadow: none; }
    .doc-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    thead tr { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    table { page-break-inside: auto; }
    tr { page-break-inside: avoid; page-break-after: auto; }
    thead { display: table-header-group; }
    .table-wrap { padding: 16px 24px 24px; }
}
</style>
</head>
<body>

<!-- Controls (hidden on print) -->
<div class="controls">
    <a href="<?= $u['role'] === 'manager' ? 'manager.php' : 'tenant.php' ?>?page=export" class="btn-back">
        ← Back
    </a>
    <div class="controls-left">
        <div>
            <div class="ctrl-label">Report Type</div>
            <form method="GET" id="filter-form" style="display:inline;">
                <input type="hidden" name="from" id="hid-from" value="<?=htmlspecialchars($date_from)?>">
                <input type="hidden" name="to" id="hid-to" value="<?=htmlspecialchars($date_to)?>">
                <select name="type" class="ctrl-select" onchange="document.getElementById('filter-form').submit()">
                    <option value="tickets"   <?=$type==='tickets'  ?'selected':''?>>📋 Pawn Tickets</option>
                    <option value="customers" <?=$type==='customers'?'selected':''?>>👥 Customers</option>
                    <option value="inventory" <?=$type==='inventory'?'selected':''?>>📦 Inventory</option>
                    <option value="audit"     <?=$type==='audit'    ?'selected':''?>>🔍 Audit Logs</option>
                    <option value="payments"  <?=$type==='payments' ?'selected':''?>>💳 Payment History</option>
                </select>
            </form>
        </div>
        <div>
            <div class="ctrl-label">Date From</div>
            <input type="date" class="ctrl-input" value="<?=htmlspecialchars($date_from)?>" onchange="updateDate('from',this.value)">
        </div>
        <div>
            <div class="ctrl-label">Date To</div>
            <input type="date" class="ctrl-input" value="<?=htmlspecialchars($date_to)?>" onchange="updateDate('to',this.value)">
        </div>
    </div>
    <button class="btn-print" onclick="window.print()">
        🖨️ Print / Save as PDF
    </button>
</div>

<!-- Document -->
<div class="document">

    <!-- Header -->
    <div class="doc-header">
        <div class="doc-header-left">
            <div class="biz-name"><?=htmlspecialchars($business)?></div>
            <div class="biz-sub">PawnHub — Branch Report</div>
            <?php if(!empty($tenant_row['phone']) || !empty($tenant_row['address'])):?>
            <div class="biz-contact">
                <?php if(!empty($tenant_row['phone'])):?>📞 <?=htmlspecialchars($tenant_row['phone'])?><?php endif;?>
                <?php if(!empty($tenant_row['address'])):?> &nbsp;·&nbsp; 📍 <?=htmlspecialchars($tenant_row['address'])?><?php endif;?>
            </div>
            <?php endif;?>
        </div>
        <div class="doc-header-right">
            <div class="doc-title"><?=htmlspecialchars($title)?></div>
            <div class="doc-period">📅 <?=$period?></div>
            <div class="doc-generated">Generated: <?=$generated_at?></div>
        </div>
    </div>

    <!-- Meta -->
    <div class="doc-meta">
        <div class="meta-item">
            <span class="meta-badge badge-<?=$type?>"><?=htmlspecialchars($title)?></span>
        </div>
        <div class="meta-item">Total Records: <strong><?=$total_rows?></strong></div>
        <div class="meta-item">Period: <strong><?=$period?></strong></div>
        <div class="meta-item">Prepared by: <strong><?=htmlspecialchars($u['name'])?></strong> (<?=ucfirst($u['role'])?>)</div>
        <div class="meta-item">Branch: <strong><?=htmlspecialchars($business)?></strong></div>
    </div>

    <!-- Table -->
    <div class="table-wrap">
        <?php if(empty($rows)):?>
        <div class="empty-state">
            <div class="icon">📭</div>
            <p style="font-weight:600;color:#475569;margin-bottom:6px;">No records found</p>
            <p style="font-size:.8rem;">No <?=strtolower($title)?> found for the selected date range.</p>
        </div>
        <?php else:?>
        <table>
            <thead>
                <tr><?php foreach($columns as $col):?><th><?=htmlspecialchars($col)?></th><?php endforeach;?></tr>
            </thead>
            <tbody>
            <?php foreach($rows as $row): $vals = array_values($row); ?>
            <tr>
                <?php foreach($vals as $i => $val):
                    $col = $columns[$i] ?? '';
                    $display = htmlspecialchars($val ?? '—');
                ?>
                <td>
                <?php
                // Format specific columns nicely
                if(str_contains(strtolower($col), 'ticket no') || str_contains(strtolower($col), 'ticket_no')):
                    echo '<span class="ticket-tag">' . $display . '</span>';
                elseif(str_contains(strtolower($col), 'status')):
                    $sc = strtolower($val ?? '');
                    echo '<span class="status-badge s-' . $sc . '">' . $display . '</span>';
                elseif(str_contains(strtolower($col), 'amount') || str_contains(strtolower($col), 'loan') || str_contains(strtolower($col), 'redeem') || str_contains(strtolower($col), 'cash') || str_contains(strtolower($col), 'change') || str_contains(strtolower($col), 'appraisal')):
                    echo '₱' . number_format((float)($val ?? 0), 2);
                elseif(str_contains(strtolower($col), 'date') || str_contains(strtolower($col), 'created') || str_contains(strtolower($col), 'registered') || str_contains(strtolower($col), 'received')):
                    echo $val ? date('M d, Y', strtotime($val)) : '—';
                elseif(str_contains(strtolower($col), 'time') || str_contains(strtolower($col), 'at')):
                    echo $val ? date('M d, Y h:i A', strtotime($val)) : '—';
                else:
                    echo $display ?: '—';
                endif;
                ?>
                </td>
                <?php endforeach;?>
            </tr>
            <?php endforeach;?>
            </tbody>
        </table>
        <?php endif;?>
    </div>

    <!-- Footer -->
    <div class="doc-footer">
        <span>© <?=date('Y')?> <?=htmlspecialchars($business)?> · Powered by PawnHub</span>
        <span>Total: <?=$total_rows?> record<?=$total_rows!==1?'s':''?> · <?=$generated_at?></span>
    </div>

</div>

<script>
function updateDate(field, value) {
    document.getElementById('hid-' + field).value = value;
    document.getElementById('filter-form').submit();
}
// Auto-print if ?print=1 in URL
if (new URLSearchParams(window.location.search).get('print') === '1') {
    window.addEventListener('load', () => setTimeout(() => window.print(), 500));
}
</script>
</body>
</html>