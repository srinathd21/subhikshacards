<?php
require_once __DIR__ . '/includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function ctTableExists(mysqli $conn, string $table): bool
{
    try {
        $table = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '{$table}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) $res->free();
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

function ctDate($value): string
{
    return !empty($value) ? date('d M Y', strtotime((string)$value)) : '-';
}

function ctDateTime($value): string
{
    return !empty($value) ? date('d M Y, h:i A', strtotime((string)$value)) : '-';
}

function ctMoney($value): string
{
    return '₹' . number_format((float)$value, 2);
}

function ctStatusClass(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === 'completed' || $status === 'skipped') return 'done';
    if ($status === 'in_progress' || $status === 'progress') return 'live';
    if ($status === 'delayed') return 'delay';
    if ($status === 'cancelled') return 'cancel';
    return 'pending';
}

function ctStatusLabel(string $status): string
{
    $status = strtolower(trim($status));
    return ucwords(str_replace('_', ' ', $status !== '' ? $status : 'pending'));
}

function ctIsDispatchStep(array $step): bool
{
    $key = strtolower(trim((string)($step['step_key'] ?? '')));
    $name = strtolower(trim((string)($step['step_name'] ?? '')));

    // Only merge the customer-facing final dispatch stages.
    // "Send to Dispatch" is a production handover stage and stays separate.
    return $key === 'dispatch'
        || in_array($key, ['ready_for_dispatch', 'dispatched'], true)
        || in_array($name, ['dispatch', 'ready for dispatch', 'dispatched'], true)
        || (strpos($key, 'dispatch') !== false && strpos($key, 'send_to') === false);
}

function ctFirstNonEmpty(array $rows, string $field): string
{
    foreach ($rows as $row) {
        $value = trim((string)($row[$field] ?? ''));
        if ($value !== '') return $value;
    }
    return '';
}

function ctMinDateTime(array $rows, string $field): ?string
{
    $min = null;
    foreach ($rows as $row) {
        $value = trim((string)($row[$field] ?? ''));
        if ($value === '') continue;
        $ts = strtotime($value);
        if ($ts === false) continue;
        if ($min === null || $ts < strtotime($min)) $min = $value;
    }
    return $min;
}

function ctMaxDateTime(array $rows, string $field): ?string
{
    $max = null;
    foreach ($rows as $row) {
        $value = trim((string)($row[$field] ?? ''));
        if ($value === '') continue;
        $ts = strtotime($value);
        if ($ts === false) continue;
        if ($max === null || $ts > strtotime($max)) $max = $value;
    }
    return $max;
}

function ctMergedStatus(array $rows): string
{
    $hasPending = false;
    $hasProgress = false;
    $hasDelayed = false;
    $hasCompleted = false;
    $allClosed = true;

    foreach ($rows as $row) {
        $status = strtolower(trim((string)($row['status'] ?? 'pending')));
        if ($status === 'delayed') $hasDelayed = true;
        if (in_array($status, ['in_progress', 'progress'], true)) $hasProgress = true;
        if (in_array($status, ['completed', 'skipped'], true)) $hasCompleted = true;
        if (!in_array($status, ['completed', 'skipped', 'cancelled'], true)) {
            $allClosed = false;
            if ($status === 'pending') $hasPending = true;
        }
    }

    if ($hasDelayed) return 'delayed';
    if ($allClosed && $hasCompleted) return 'completed';
    if ($hasProgress || $hasCompleted) return 'in_progress';
    return 'pending';
}

function ctMergeDispatchRows(array $rows): array
{
    if (!$rows) return [];

    $base = null;
    foreach ($rows as $row) {
        $status = strtolower(trim((string)($row['status'] ?? 'pending')));
        if (!in_array($status, ['completed', 'skipped', 'cancelled'], true)) {
            $base = $row;
            break;
        }
    }
    if (!$base) $base = $rows[0];

    $base['step_name'] = 'Dispatch';
    $base['step_key'] = 'dispatch';
    $base['role_name'] = ctFirstNonEmpty($rows, 'role_name') ?: 'Sales / Dispatch';
    $base['responsible_user_name'] = ctFirstNonEmpty($rows, 'responsible_user_name') ?: '-';
    $base['completed_by_name'] = ctFirstNonEmpty(array_reverse($rows), 'completed_by_name') ?: '-';
    $base['planned_start_date'] = ctMinDateTime($rows, 'planned_start_date');
    $base['planned_completion_date'] = ctMaxDateTime($rows, 'planned_completion_date');
    $base['actual_start_at'] = ctMinDateTime($rows, 'actual_start_at');
    $base['actual_completed_at'] = ctMaxDateTime($rows, 'actual_completed_at');
    $base['status'] = ctMergedStatus($rows);

    $remarks = [];
    foreach ($rows as $row) {
        $text = trim((string)($row['remarks'] ?? ''));
        if ($text !== '') $remarks[] = $text;
    }
    $base['remarks'] = $remarks ? implode(' | ', array_unique($remarks)) : ($base['remarks'] ?? '');

    return $base;
}

function ctNormalizeCustomerSteps(array $steps): array
{
    $out = [];
    $dispatchRows = [];
    $dispatchPosition = null;

    foreach ($steps as $step) {
        if (ctIsDispatchStep($step)) {
            if ($dispatchPosition === null) {
                $dispatchPosition = count($out);
                $out[] = ['__dispatch_placeholder' => true];
            }
            $dispatchRows[] = $step;
            continue;
        }
        $out[] = $step;
    }

    if ($dispatchPosition !== null) {
        $out[$dispatchPosition] = ctMergeDispatchRows($dispatchRows);
    }

    return array_values(array_filter($out, static function ($row) {
        return empty($row['__dispatch_placeholder']);
    }));
}

function ctPaymentSnapshot(mysqli $conn, array $job): array
{
    $final = (float)($job['final_amount'] ?? 0);
    $storedAdvance = (float)($job['advance_amount'] ?? 0);
    $storedBalance = (float)($job['balance_amount'] ?? 0);
    $paid = $storedAdvance;
    $usedLedger = false;

    $proformaId = (int)($job['proforma_bill_id'] ?? 0);
    if ($proformaId > 0 && ctTableExists($conn, 'payments')) {
        try {
            $stmt = $conn->prepare("\n                SELECT\n                    COUNT(*) AS cnt,\n                    COALESCE(SUM(CASE WHEN payment_type = 'refund' THEN -amount ELSE amount END), 0) AS paid_amount\n                FROM payments\n                WHERE proforma_bill_id = ?\n                  AND COALESCE(is_cancelled, 0) = 0\n            ");
            $stmt->bind_param('i', $proformaId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && (int)($row['cnt'] ?? 0) > 0) {
                $paid = (float)($row['paid_amount'] ?? 0);
                $usedLedger = true;
            }
        } catch (Throwable $e) {
            $paid = $storedAdvance;
        }
    }

    if ($final <= 0 && $storedBalance > 0) {
        $balance = $storedBalance;
    } else {
        $balance = max(0, $final - $paid);
    }

    $isPaid = ($final > 0 && $balance <= 0.01) || ($final <= 0 && $storedBalance <= 0.01 && $storedAdvance > 0);
    $label = 'Pending';
    if ($isPaid) {
        $label = 'Paid';
    } elseif ($paid > 0) {
        $label = 'Advance Paid';
    }

    return [
        'final_amount' => $final,
        'paid_amount' => $paid,
        'balance_amount' => $balance,
        'is_paid' => $isPaid,
        'label' => $label,
        'used_ledger' => $usedLedger,
    ];
}

function ctPaymentLabel(array $job): string
{
    $balance = (float)($job['balance_amount'] ?? 0);
    $advance = (float)($job['advance_amount'] ?? 0);
    $final = (float)($job['final_amount'] ?? 0);

    if ($balance <= 0 && $final > 0) {
        return 'Paid';
    }

    if ($advance > 0) {
        return 'Advance Paid';
    }

    return 'Pending';
}

function ctCurrentStatusText(array $steps): string
{
    foreach ($steps as $step) {
        $status = strtolower((string)($step['status'] ?? 'pending'));
        if (in_array($status, ['in_progress', 'delayed'], true)) {
            return (string)($step['step_name'] ?? '-');
        }
    }

    foreach ($steps as $step) {
        $status = strtolower((string)($step['status'] ?? 'pending'));
        if (!in_array($status, ['completed', 'skipped', 'cancelled'], true)) {
            return (string)($step['step_name'] ?? '-');
        }
    }

    return $steps ? 'Completed' : '-';
}

function ctPublicStatusLabel(array $job, array $steps): string
{
    $statusKey = strtolower((string)($job['status_key'] ?? ''));
    $statusName = trim((string)($job['status_name'] ?? ''));

    if (in_array($statusKey, ['delivered', 'completed'], true) || stripos($statusName, 'completed') !== false) {
        return 'Completed';
    }

    if (in_array($statusKey, ['cancelled'], true)) {
        return 'Cancelled';
    }

    if ($steps) {
        return 'In Production';
    }

    return $statusName !== '' ? $statusName : 'In Production';
}

$token = trim((string)($_GET['token'] ?? ''));
$jobCardNo = trim((string)($_GET['job_card_no'] ?? $_POST['job_card_no'] ?? ''));
$message = '';
$messageType = 'info';
$job = null;
$steps = [];

if (!ctTableExists($conn, 'job_cards') || !ctTableExists($conn, 'job_tracking')) {
    $message = 'Tracking system is not available.';
    $messageType = 'danger';
} elseif ($token === '' && $jobCardNo === '') {
    $message = 'Enter your job card number to track your order.';
    $messageType = 'info';
} else {
    try {
        $hasTrackingLinks = ctTableExists($conn, 'customer_tracking_links');
        $trackingSelect = $hasTrackingLinks
            ? 'ctl.expires_at AS tracking_expires_at, ctl.is_active AS tracking_is_active'
            : 'NULL AS tracking_expires_at, NULL AS tracking_is_active';
        $trackingJoin = $hasTrackingLinks
            ? 'LEFT JOIN customer_tracking_links ctl ON ctl.job_card_id = jc.id' . ($token !== '' ? ' AND ctl.tracking_token = ?' : '')
            : '';

        if ($token !== '') {
            $where = 'WHERE jc.tracking_token = ?' . ($hasTrackingLinks ? ' OR ctl.tracking_token = ?' : '');
        } else {
            $where = 'WHERE UPPER(TRIM(jc.job_card_no)) = UPPER(TRIM(?))';
        }

        $sql = "
            SELECT
                jc.*,
                pb.proforma_no,
                ft.function_name,
                pt.printing_name,
                pst.sub_type_name,
                jcs.status_name,
                jcs.status_key,
                {$trackingSelect}
            FROM job_cards jc
            LEFT JOIN proforma_bills pb ON pb.id = jc.proforma_bill_id
            LEFT JOIN function_types ft ON ft.id = jc.function_type_id
            LEFT JOIN printing_types pt ON pt.id = jc.printing_type_id
            LEFT JOIN printing_sub_types pst ON pst.id = jc.printing_sub_type_id
            LEFT JOIN job_card_statuses jcs ON jcs.id = jc.job_card_status_id
            {$trackingJoin}
            {$where}
            ORDER BY jc.id DESC
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);

        if ($token !== '') {
            if ($hasTrackingLinks) {
                $stmt->bind_param('sss', $token, $token, $token);
            } else {
                $stmt->bind_param('s', $token);
            }
        } else {
            $stmt->bind_param('s', $jobCardNo);
        }

        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$job) {
            $message = $token !== '' ? 'Tracking link not found.' : 'No order found for this job card number.';
            $messageType = 'danger';
        } elseif ($token !== '' && isset($job['tracking_is_active']) && $job['tracking_is_active'] !== null && (int)$job['tracking_is_active'] !== 1) {
            $message = 'This tracking link is inactive.';
            $messageType = 'danger';
            $job = null;
        } elseif ($token !== '' && !empty($job['tracking_expires_at']) && strtotime((string)$job['tracking_expires_at']) < time()) {
            $message = 'This tracking link has expired.';
            $messageType = 'danger';
            $job = null;
        }
    } catch (Throwable $e) {
        $message = 'Unable to load tracking details.';
        $messageType = 'danger';
        $job = null;
    }
}

if ($job) {
    try {
        $stmt = $conn->prepare("
            SELECT
                jt.*,
                ws.step_name,
                ws.step_key,
                ws.sort_order,
                rr.role_name,
                ru.username AS responsible_user_name,
                cu.username AS completed_by_name,
                dr.reason_name AS delay_reason_name
            FROM job_tracking jt
            LEFT JOIN workflow_steps ws ON ws.id = jt.workflow_step_id
            LEFT JOIN roles rr ON rr.id = jt.responsible_role_id
            LEFT JOIN users ru ON ru.id = jt.responsible_user_id
            LEFT JOIN users cu ON cu.id = jt.completed_by
            LEFT JOIN delay_reasons dr ON dr.id = jt.delay_reason_id
            WHERE jt.job_card_id = ?
              AND COALESCE(ws.is_customer_visible, 1) = 1
            ORDER BY ws.sort_order ASC, jt.id ASC
        ");
        $jobId = (int)$job['id'];
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $steps[] = $row;
        }
        $stmt->close();
    } catch (Throwable $e) {
        $steps = [];
    }

    $steps = ctNormalizeCustomerSteps($steps);
}

$totalSteps = count($steps);
$completedSteps = 0;
$openStepIndex = -1;

foreach ($steps as $index => $s) {
    $status = strtolower((string)($s['status'] ?? 'pending'));
    if (in_array($status, ['completed', 'skipped'], true)) {
        $completedSteps++;
    }
    if ($openStepIndex === -1 && in_array($status, ['in_progress', 'delayed'], true)) {
        $openStepIndex = $index;
    }
}

if ($openStepIndex === -1) {
    foreach ($steps as $index => $s) {
        $status = strtolower((string)($s['status'] ?? 'pending'));
        if (!in_array($status, ['completed', 'skipped', 'cancelled'], true)) {
            $openStepIndex = $index;
            break;
        }
    }
}

$progressPercent = $totalSteps > 0 ? round(($completedSteps / $totalSteps) * 100) : 0;
$progressPercent = max(0, min(100, $progressPercent));
$currentStage = $steps ? ctCurrentStatusText($steps) : '-';
$publicStatus = $job ? ctPublicStatusLabel($job, $steps) : '';
$paymentSnapshot = $job ? ctPaymentSnapshot($conn, $job) : ['label' => '-', 'balance_amount' => 0, 'paid_amount' => 0, 'is_paid' => false];
$paymentLabel = (string)($paymentSnapshot['label'] ?? '-');
$displayJobNo = $jobCardNo !== '' ? $jobCardNo : ($job['job_card_no'] ?? '');
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Customer Tracking - Subhiksha Cards</title>

    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    :root {
        --ink: #172033;
        --muted: #60718a;
        --line: #dce7ef;
        --soft: #f8fafc;
        --green: #0f766e;
        --green-2: #16a34a;
        --success-bg: #dcfce7;
        --danger: #dc2626;
        --warning: #f59e0b;
        --blue: #2563eb;
        --card: rgba(255, 255, 255, 0.94);
    }

    body {
        font-family: Inter, Arial, sans-serif;
        background: linear-gradient(180deg, #eef7f6 0%, #f7f9fc 48%, #eef2f7 100%);
        color: var(--ink);
        min-height: 100vh;
    }

    .page {
        width: 100%;
        max-width: 760px;
        margin: 0 auto;
        min-height: 100vh;
        padding-bottom: 22px;
    }

    .header {
        background: linear-gradient(135deg, #0f766e, #0b5f59);
        color: #fff;
        padding: 28px 24px 32px;
        border-bottom-left-radius: 30px;
        border-bottom-right-radius: 30px;
        box-shadow: 0 18px 45px rgba(15, 118, 110, 0.18);
    }

    .header h4 {
        font-size: 15px;
        font-weight: 800;
        opacity: 0.96;
        margin-bottom: 9px;
    }

    .header h1 {
        font-size: clamp(26px, 4vw, 38px);
        line-height: 1.15;
        font-weight: 900;
        letter-spacing: -0.7px;
    }

    .card {
        background: var(--card);
        border: 1px solid var(--line);
        border-radius: 22px;
        box-shadow: 0 10px 30px rgba(20, 40, 60, 0.06);
    }

    .search-card {
        margin: 18px 14px;
        padding: 16px;
    }

    .search-card label {
        display: block;
        font-size: 14px;
        font-weight: 900;
        color: var(--muted);
        margin-bottom: 9px;
    }

    .search-row {
        display: flex;
        gap: 10px;
    }

    .search-row input {
        flex: 1;
        min-width: 0;
        height: 48px;
        border: 1px solid var(--line);
        border-radius: 15px;
        padding: 0 14px;
        font-size: 16px;
        font-weight: 800;
        color: var(--ink);
        background: var(--soft);
        outline: none;
        text-transform: uppercase;
    }

    .search-row input:focus {
        border-color: var(--green);
        box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.12);
    }

    .search-row button {
        width: 88px;
        height: 48px;
        border: none;
        border-radius: 15px;
        background: var(--green);
        color: #fff;
        font-size: 15px;
        font-weight: 900;
        cursor: pointer;
        box-shadow: 0 8px 18px rgba(15, 118, 110, 0.22);
    }

    .search-row button:hover {
        background: #0b5f59;
    }

    .message {
        margin: 0 14px 16px;
        padding: 14px 16px;
        border-radius: 18px;
        font-size: 14px;
        font-weight: 800;
        border: 1px solid #bfdbfe;
        background: #eff6ff;
        color: #1d4ed8;
    }

    .message.danger {
        border-color: #fecaca;
        background: #fef2f2;
        color: #991b1b;
    }

    .order-card {
        margin: 0 14px 16px;
        padding: 18px;
    }

    .order-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 8px;
    }

    .order-head h2 {
        font-size: clamp(20px, 4vw, 28px);
        font-weight: 900;
        letter-spacing: -0.5px;
        line-height: 1.2;
    }

    .status-badge {
        background: var(--success-bg);
        color: #166534;
        font-size: 12px;
        font-weight: 900;
        padding: 8px 12px;
        border-radius: 999px;
        white-space: nowrap;
    }

    .status-badge.cancel,
    .status-badge.delay {
        background: #fee2e2;
        color: #991b1b;
    }

    .product {
        font-size: 16px;
        color: var(--muted);
        margin-bottom: 15px;
        font-weight: 700;
    }

    .progress-wrap {
        display: grid;
        grid-template-columns: 1fr auto;
        align-items: center;
        gap: 12px;
        margin-bottom: 12px;
    }

    .progress {
        height: 9px;
        background: #e2e8f0;
        border-radius: 20px;
        overflow: hidden;
    }

    .progress-fill {
        width: <?=(int)$progressPercent ?>%;
        height: 100%;
        background: linear-gradient(90deg, var(--green), var(--green-2));
        border-radius: 20px;
        transition: width .25s ease;
    }

    .progress-percent {
        color: var(--ink);
        font-size: 18px;
        font-weight: 900;
    }

    .current-status {
        font-size: 15px;
        color: var(--muted);
        margin-bottom: 15px;
        line-height: 1.4;
    }

    .current-status strong {
        color: #44546a;
        font-weight: 900;
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .info-box {
        background: var(--soft);
        border: 1px solid #dde7ef;
        border-radius: 16px;
        padding: 13px;
        min-height: 68px;
    }

    .info-box span {
        display: block;
        color: var(--muted);
        font-size: 12px;
        font-weight: 900;
        margin-bottom: 6px;
    }

    .info-box h3 {
        font-size: 15px;
        font-weight: 900;
        color: var(--ink);
        line-height: 1.3;
        word-break: break-word;
    }

    .timeline {
        padding: 0 14px 18px;
    }

    .step {
        background: rgba(255, 255, 255, 0.96);
        border: 1px solid var(--line);
        border-radius: 17px;
        margin-bottom: 10px;
        overflow: hidden;
        box-shadow: 0 7px 20px rgba(20, 40, 60, 0.045);
    }

    .step.active {
        background: linear-gradient(135deg, #ecfffb, #f4fffb);
        border-color: #65e6d5;
        box-shadow: 0 8px 24px rgba(15, 118, 110, 0.12);
    }

    .step.delay {
        border-color: #fecaca;
        background: #fffafa;
    }

    .step-button {
        width: 100%;
        border: 0;
        background: transparent;
        min-height: 58px;
        padding: 11px 13px;
        display: grid;
        grid-template-columns: 36px minmax(0, 1fr) auto 20px;
        align-items: center;
        gap: 12px;
        cursor: pointer;
        text-align: left;
    }

    .step-icon,
    .step-number {
        width: 34px;
        height: 34px;
        min-width: 34px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 900;
    }

    .step-icon {
        background: var(--success-bg);
        color: #15803d;
        font-size: 18px;
    }

    .step-number {
        background: #e6edf5;
        color: var(--muted);
        font-size: 14px;
    }

    .step.active .step-number,
    .step .step-number.live {
        background: var(--green);
        color: #fff;
        box-shadow: 0 6px 15px rgba(15, 118, 110, 0.25);
    }

    .step .step-number.delay {
        background: var(--danger);
        color: #fff;
    }

    .step-main {
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .step-title {
        min-width: 0;
        font-size: 15.5px;
        font-weight: 900;
        color: var(--ink);
        line-height: 1.25;
    }

    .step-dates {
        display: flex;
        flex-wrap: wrap;
        gap: 5px 12px;
        color: var(--muted);
        font-size: 11.5px;
        font-weight: 800;
        line-height: 1.25;
    }

    .step-dates span {
        display: inline-flex;
        align-items: center;
        min-width: 0;
    }

    .step-dates b {
        color: var(--ink);
        font-weight: 900;
        margin-left: 4px;
    }

    .step.done .step-dates {
        color: #15803d;
    }

    .step-status {
        font-size: 13px;
        color: var(--muted);
        font-weight: 800;
        white-space: nowrap;
    }

    .step-status.done {
        color: #15803d;
    }

    .step-status.live {
        color: var(--green);
    }

    .step-status.delay {
        color: var(--danger);
    }

    .step-status.cancel {
        color: #7f1d1d;
    }

    .step-arrow {
        color: var(--muted);
        font-weight: 900;
        transition: transform .2s ease;
    }

    .step.open .step-arrow {
        transform: rotate(180deg);
    }

    .step-details {
        display: none;
        padding: 0 13px 14px 61px;
    }

    .step.open .step-details {
        display: block;
    }

    .detail-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
        margin-top: 2px;
    }

    .detail-box {
        background: var(--soft);
        border: 1px solid #e2e8f0;
        border-radius: 13px;
        padding: 10px;
    }

    .detail-box small {
        display: block;
        color: var(--muted);
        font-size: 10px;
        font-weight: 900;
        margin-bottom: 5px;
        text-transform: uppercase;
        letter-spacing: .02em;
    }

    .detail-box strong {
        display: block;
        font-size: 12px;
        color: var(--ink);
        line-height: 1.35;
        word-break: break-word;
    }

    .remarks {
        margin-top: 8px;
        padding: 10px 11px;
        border-radius: 13px;
        background: #eff6ff;
        color: #1d4ed8;
        font-size: 12px;
        font-weight: 800;
        line-height: 1.45;
    }

    .remarks.delay {
        background: #fef2f2;
        color: #991b1b;
        border: 1px solid #fecaca;
    }

    .footer-note {
        color: var(--muted);
        font-size: 12px;
        font-weight: 800;
        text-align: center;
        padding: 4px 16px 0;
        line-height: 1.5;
    }

    @media (min-width: 768px) {
        .page {
            padding-top: 18px;
        }

        .header,
        .search-card,
        .order-card,
        .timeline {
            margin-left: 18px;
            margin-right: 18px;
        }

        .header {
            border-radius: 30px;
            padding: 34px 32px 38px;
        }

        .search-card,
        .order-card {
            padding: 20px;
        }

        .timeline {
            padding-left: 18px;
            padding-right: 18px;
        }

        .step-button {
            grid-template-columns: 40px minmax(0, 1fr) auto 24px;
            min-height: 62px;
            padding: 13px 15px;
        }

        .step-details {
            padding-left: 70px;
            padding-right: 15px;
            padding-bottom: 16px;
        }

        .detail-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
    }

    @media (max-width: 430px) {
        .page {
            max-width: 100%;
        }

        .header {
            padding: 22px 18px 26px;
        }

        .header h1 {
            font-size: 25px;
        }

        .search-row {
            gap: 8px;
        }

        .search-row button {
            width: 74px;
            font-size: 14px;
        }

        .order-head {
            align-items: center;
        }

        .status-badge {
            font-size: 11px;
            padding: 7px 10px;
        }

        .info-grid {
            gap: 8px;
        }

        .step-button {
            grid-template-columns: 34px minmax(0, 1fr) auto 16px;
            gap: 10px;
            padding: 10px 11px;
        }

        .step-title {
            font-size: 14.5px;
        }

        .step-dates {
            flex-direction: column;
            gap: 3px;
            font-size: 10.5px;
        }

        .step-status {
            font-size: 12px;
        }

        .step-details {
            padding-left: 56px;
        }

        .detail-grid {
            grid-template-columns: 1fr 1fr;
        }
    }

    @media (max-width: 350px) {
        .search-row {
            flex-direction: column;
        }

        .search-row button {
            width: 100%;
        }

        .info-grid,
        .detail-grid {
            grid-template-columns: 1fr;
        }

        .step-button {
            grid-template-columns: 34px minmax(0, 1fr) 16px;
        }

        .step-status {
            display: none;
        }
    }
    </style>
</head>

<body>
    <main class="page">
        <section class="header">
            <h4>Subhiksha Cards Customer Portal</h4>
            <h1>Track Your Invitation Order</h1>
        </section>

        <form method="get" class="search-card card" autocomplete="off">
            <label for="job_card_no">Enter Job Card Number</label>
            <div class="search-row">
                <input type="text" id="job_card_no" name="job_card_no"
                    value="<?= e($job['job_card_no'] ?? $displayJobNo) ?>" placeholder="Example: JC-000125" required>
                <button type="submit">Track</button>
            </div>
        </form>

        <?php if ($message !== ''): ?>
        <div class="message <?= e($messageType === 'danger' ? 'danger' : '') ?>"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if ($job): ?>
        <?php
                $orderTypeText = trim((string)($job['product_name'] ?? '-'));
                $qtyText = trim((string)($job['qty'] ?? $job['quantity'] ?? ''));
                if ($qtyText !== '') {
                    $orderTypeText .= ' · ' . $qtyText . ' Qty';
                }

                $mainStatusClass = ctStatusClass((string)($job['status_key'] ?? ''));
            ?>
        <section class="order-card card">
            <div class="order-head">
                <h2>Order #<?= e($job['job_card_no'] ?? '-') ?></h2>
                <div class="status-badge <?= e($mainStatusClass) ?>"><?= e($publicStatus) ?></div>
            </div>

            <div class="product"><?= e($orderTypeText) ?></div>

            <div class="progress-wrap">
                <div class="progress">
                    <div class="progress-fill"></div>
                </div>
                <div class="progress-percent"><?= (int)$progressPercent ?>%</div>
            </div>

            <div class="current-status">
                Current Status: <strong><?= e($currentStage) ?></strong>
            </div>

            <div class="info-grid">
                <div class="info-box">
                    <span>Customer</span>
                    <h3><?= e($job['customer_name'] ?? '-') ?></h3>
                </div>

                <div class="info-box">
                    <span>Delivery</span>
                    <h3><?= e(ctDate($job['delivery_date'] ?? null)) ?></h3>
                </div>

                <div class="info-box">
                    <span>Function</span>
                    <h3><?= e($job['function_name'] ?? '-') ?></h3>
                </div>

                <div class="info-box">
                    <span>Payment</span>
                    <h3>
                        <?= e($paymentLabel) ?>
                        <?php if (!$paymentSnapshot['is_paid'] && (float)$paymentSnapshot['balance_amount'] > 0): ?>
                        · Balance <?= e(ctMoney($paymentSnapshot['balance_amount'])) ?>
                        <?php endif; ?>
                    </h3>
                </div>
            </div>
        </section>

        <section class="timeline" id="trackingTimeline">
            <?php if (!$steps): ?>
            <div class="message">No tracking stages found for this job card.</div>
            <?php else: ?>
            <?php foreach ($steps as $index => $step): ?>
            <?php
                            $status = strtolower((string)($step['status'] ?? 'pending'));
                            $isOpen = $index === $openStepIndex;
                            $displayStatus = ($isOpen && $status === 'pending') ? 'in_progress' : $status;
                            $class = ctStatusClass($displayStatus);
                            $isDone = ctStatusClass($status) === 'done';
                            $isActive = in_array($class, ['live', 'delay'], true) || $isOpen;
                            $icon = $isDone ? '✓' : (string)($index + 1);
                            $statusText = ctStatusLabel($displayStatus);
                        ?>
            <article class="step <?= e($isActive ? 'active' : '') ?> <?= e($class) ?> <?= e($isOpen ? 'open' : '') ?>">
                <button type="button" class="step-button" aria-expanded="<?= $isOpen ? 'true' : 'false' ?>">
                    <?php if ($isDone): ?>
                    <span class="step-icon">✓</span>
                    <?php else: ?>
                    <span class="step-number <?= e($class) ?>"><?= e($icon) ?></span>
                    <?php endif; ?>

                    <span class="step-main">
                        <span class="step-title"><?= e($step['step_name'] ?? '-') ?></span>

                        <?php if ($isDone): ?>
                        <span class="step-dates">
                            <span>Start:<b><?= e(ctDateTime($step['actual_start_at'] ?? null)) ?></b></span>
                            <span>Completed:<b><?= e(ctDateTime($step['actual_completed_at'] ?? null)) ?></b></span>
                        </span>
                        <?php elseif (in_array($displayStatus, ['in_progress', 'delayed'], true)): ?>
                        <span class="step-dates">
                            <span>Start:<b><?= e(ctDateTime($step['actual_start_at'] ?? null)) ?></b></span>
                            <span>Expected:<b><?= e(ctDate($step['revised_completion_date'] ?? $step['planned_completion_date'] ?? null)) ?></b></span>
                        </span>
                        <?php endif; ?>
                    </span>

                    <span class="step-status <?= e($class) ?>"><?= e($statusText) ?></span>
                    <span class="step-arrow">⌄</span>
                </button>

                <div class="step-details">
                    <div class="detail-grid">
                        <div class="detail-box">
                            <small>Department</small>
                            <strong><?= e($step['role_name'] ?? '-') ?></strong>
                        </div>

                        <div class="detail-box">
                            <small>Responsible User</small>
                            <strong><?= e($step['responsible_user_name'] ?? '-') ?></strong>
                        </div>

                        <div class="detail-box">
                            <small>Planned Start</small>
                            <strong><?= e(ctDate($step['planned_start_date'] ?? null)) ?></strong>
                        </div>

                        <div class="detail-box">
                            <small>Expected Completion</small>
                            <strong><?= e(ctDate($step['planned_completion_date'] ?? null)) ?></strong>
                        </div>

                        <div class="detail-box">
                            <small>Actual Start</small>
                            <strong><?= e(ctDateTime($step['actual_start_at'] ?? null)) ?></strong>
                        </div>

                        <div class="detail-box">
                            <small>Completed At</small>
                            <strong><?= e(ctDateTime($step['actual_completed_at'] ?? null)) ?></strong>
                        </div>

                        <div class="detail-box">
                            <small>Completed By</small>
                            <strong><?= e($step['completed_by_name'] ?? '-') ?></strong>
                        </div>

                        <div class="detail-box">
                            <small>Status</small>
                            <strong><?= e(ctStatusLabel($displayStatus)) ?></strong>
                        </div>
                    </div>

                    <?php if ($status === 'delayed' || (int)($step['is_delayed'] ?? 0) === 1): ?>
                    <div class="remarks delay">
                        Delay Alert: <?= e($step['delay_reason_name'] ?? 'Reason not updated') ?>
                        <?= !empty($step['delay_days']) ? ' | Delay Days: ' . e($step['delay_days']) : '' ?>
                        <?= !empty($step['delay_remarks']) ? ' | Remark: ' . e($step['delay_remarks']) : '' ?>
                    </div>
                    <?php elseif (!empty($step['remarks'])): ?>
                    <div class="remarks">Update: <?= e($step['remarks']) ?></div>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <p class="footer-note">This is a live customer tracking page from Subhiksha Cards. Please contact the team for
            urgent changes.</p>
        <?php endif; ?>
    </main>

    <script>
    document.querySelectorAll('.step-button').forEach(function(button) {
        button.addEventListener('click', function() {
            const item = button.closest('.step');
            const isOpen = item.classList.toggle('open');
            button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    });
    </script>
</body>

</html>