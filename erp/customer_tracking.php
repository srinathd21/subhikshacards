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
    if ($status === 'payment_pending') return 'payment';
    if ($status === 'cancelled') return 'cancel';
    return 'pending';
}

function ctStatusLabel(string $status): string
{
    $status = strtolower(trim($status));
    return ucwords(str_replace('_', ' ', $status !== '' ? $status : 'pending'));
}

function ctIsSendToDispatchStep(array $step): bool
{
    $key = strtolower(trim((string)($step['step_key'] ?? '')));
    $name = strtolower(trim((string)($step['step_name'] ?? '')));

    $keyText = str_replace(['-', '_'], ' ', $key);

    return in_array($key, ['send_to_dispatch', 'send_for_dispatch', 'send_dispatch', 'sent_to_dispatch', 'send_to_despatch', 'send_for_despatch'], true)
        || in_array($name, ['send to dispatch', 'send for dispatch', 'sent to dispatch', 'send to despatch', 'send for despatch'], true)
        || ((strpos($keyText, 'send') !== false || strpos($keyText, 'sent') !== false) && (strpos($keyText, 'dispatch') !== false || strpos($keyText, 'despatch') !== false))
        || ((strpos($name, 'send') !== false || strpos($name, 'sent') !== false) && (strpos($name, 'dispatch') !== false || strpos($name, 'despatch') !== false));
}

function ctIsReadyForDispatchStep(array $step): bool
{
    $key = strtolower(trim((string)($step['step_key'] ?? '')));
    $name = strtolower(trim((string)($step['step_name'] ?? '')));

    return in_array($key, ['ready_for_dispatch', 'ready_to_dispatch', 'ready_for_despatch', 'ready_to_despatch'], true)
        || in_array($name, ['ready for dispatch', 'ready to dispatch', 'ready for despatch', 'ready to despatch'], true);
}

function ctIsInternalDispatchStep(array $step): bool
{
    return ctIsSendToDispatchStep($step) || ctIsReadyForDispatchStep($step);
}

function ctIsDispatchStep(array $step): bool
{
    // Customer tracking should not show internal handover stages.
    // Only actual Dispatch / Dispatched is shown as the customer-facing Dispatch row.
    if (ctIsInternalDispatchStep($step)) {
        return false;
    }

    $key = strtolower(trim((string)($step['step_key'] ?? '')));
    $name = strtolower(trim((string)($step['step_name'] ?? '')));

    return $key === 'dispatch'
        || in_array($key, ['dispatched', 'despatch', 'delivered'], true)
        || in_array($name, ['dispatch', 'dispatched', 'despatch', 'delivered'], true)
        || strpos($key, 'dispatch') !== false
        || strpos($key, 'despatch') !== false;
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
        // Internal handover stages. Customer does not need to see these.
        if (ctIsInternalDispatchStep($step)) {
            continue;
        }

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

function ctDispatchPaymentPending(array $step, array $paymentSnapshot): bool
{
    $status = strtolower(trim((string)($step['status'] ?? 'pending')));

    return ctIsDispatchStep($step)
        && !in_array($status, ['completed', 'skipped', 'cancelled'], true)
        && empty($paymentSnapshot['is_paid'])
        && (float)($paymentSnapshot['balance_amount'] ?? 0) > 0.01;
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

/*
 * Customer-facing timeline runs from BOTTOM to TOP:
 * first workflow stage (Enquiry) is shown at the bottom,
 * latest/final stage is shown at the top.
 *
 * IMPORTANT: $steps remains in normal workflow order for all calculations.
 */
$displaySteps = array_reverse($steps);
$displayOpenStepIndex = -1;

if ($totalSteps > 0) {
    if ($openStepIndex >= 0) {
        $displayOpenStepIndex = ($totalSteps - 1) - $openStepIndex;
    } else {
        // Fully completed workflow: current/final visible stage is at the top.
        $displayOpenStepIndex = 0;
    }
}

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
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0a438c">
    <title>Customer Tracking - Subhiksha Cards</title>

    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    :root {
        --brand-blue: #0a438c;
        --brand-blue-dark: #07366f;
        --brand-blue-soft: #edf5ff;

        --brand-orange: #f47a00;
        --brand-orange-light: #ff9b17;
        --brand-orange-soft: #fff3e7;

        --green: #16a34a;
        --green-dark: #08752d;
        --green-soft: #dcfce7;

        --danger: #dc2626;
        --danger-soft: #fef2f2;

        --warning: #d97706;
        --warning-soft: #fff7e8;

        --ink: #10213f;
        --muted: #62738e;
        --line: #d9e3ee;
        --soft: #f8fafc;
        --pending: #aab4c0;
        --card: #ffffff;
        --body: #f5f8fc;
    }

    html {
        width: 100%;
        max-width: 100%;
        overflow-x: hidden;
        -webkit-text-size-adjust: 100%;
    }

    body {
        width: 100%;
        max-width: 100%;
        min-height: 100vh;
        overflow-x: hidden;
        font-family: Inter, Arial, sans-serif;
        color: var(--ink);
        background:
            radial-gradient(circle at 15% 0%, rgba(10, 67, 140, .08), transparent 34%),
            linear-gradient(180deg, #f9fcff 0%, var(--body) 100%);
    }

    button,
    input {
        font: inherit;
    }

    img,
    svg {
        max-width: 100%;
    }

    .page {
        width: 100%;
        max-width: 760px;
        min-height: 100vh;
        margin: 0 auto;
        padding: 12px 12px 24px;
    }

    .card {
        width: 100%;
        min-width: 0;
        background: rgba(255, 255, 255, .98);
        border: 1px solid var(--line);
        border-radius: 22px;
        box-shadow: 0 10px 30px rgba(20, 40, 60, .06);
    }

    /* ---------------------------------------------------------
           Brand Header
           Updated shade so the Subhiksha logo is clearly visible
        --------------------------------------------------------- */
    .tracking-header {
        position: relative;
        overflow: hidden;
        width: 100%;
        padding: 24px;
        border-radius: 28px;
        color: #fff;
        background:
            radial-gradient(circle at 12% 28%, rgba(255, 255, 255, .16), transparent 18%),
            radial-gradient(circle at 88% 18%, rgba(255, 255, 255, .08), transparent 26%),
            linear-gradient(135deg, #2b70c8 0%, var(--brand-blue) 46%, var(--brand-blue-dark) 100%);
        box-shadow: 0 18px 44px rgba(10, 67, 140, .18);
    }

    .tracking-header::before,
    .tracking-header::after {
        content: "";
        position: absolute;
        border-radius: 50%;
        pointer-events: none;
    }

    .tracking-header::before {
        width: 260px;
        height: 260px;
        right: -95px;
        top: -135px;
        border: 1px solid rgba(255, 255, 255, .10);
        box-shadow:
            0 0 0 24px rgba(255, 255, 255, .025),
            0 0 0 54px rgba(255, 255, 255, .016);
    }

    .tracking-header::after {
        width: 180px;
        height: 180px;
        left: -72px;
        bottom: -98px;
        background: radial-gradient(circle, rgba(255, 196, 92, .26) 0%, rgba(244, 122, 0, .12) 42%, rgba(244, 122, 0, 0) 75%);
    }

    .header-inner {
        position: relative;
        z-index: 2;
        display: grid;
        grid-template-columns: 166px minmax(0, 1fr);
        align-items: center;
        gap: 20px;
    }

    .logo-panel {
        width: 100%;
        min-width: 0;
        min-height: 112px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 10px;
        border-radius: 20px;
        background: linear-gradient(180deg, rgba(255, 255, 255, .99) 0%, rgba(255, 248, 240, .99) 100%);
        border: 1px solid rgba(255, 255, 255, .85);
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, .95),
            0 12px 28px rgba(0, 0, 0, .10);
    }

    .brand-logo {
        display: block;
        width: 146px;
        height: auto;
        object-fit: contain;
        filter: drop-shadow(0 2px 8px rgba(255, 255, 255, .35));
    }

    .header-copy {
        min-width: 0;
    }

    .header-copy h4 {
        margin-bottom: 8px;
        font-size: 14px;
        line-height: 1.35;
        font-weight: 800;
        opacity: .96;
    }

    .header-copy h1 {
        font-size: clamp(30px, 5.3vw, 46px);
        line-height: 1.06;
        font-weight: 950;
        letter-spacing: -.8px;
        overflow-wrap: anywhere;
    }

    /* ---------------------------------------------------------
           Search
        --------------------------------------------------------- */
    .search-card {
        margin-top: 16px;
        padding: 16px;
    }

    .search-card label {
        display: block;
        margin-bottom: 8px;
        color: var(--brand-blue);
        font-size: 13px;
        font-weight: 900;
    }

    .search-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 102px;
        gap: 9px;
        width: 100%;
        min-width: 0;
    }

    .search-row input {
        width: 100%;
        min-width: 0;
        height: 48px;
        padding: 0 13px;
        border: 1px solid #ced9e6;
        border-radius: 14px;
        outline: none;
        background: #f9fbfd;
        color: var(--ink);
        font-size: 15px;
        font-weight: 900;
        text-transform: uppercase;
    }

    .search-row input:focus {
        border-color: var(--brand-blue);
        box-shadow: 0 0 0 3px rgba(10, 67, 140, .10);
    }

    .search-row button {
        min-width: 0;
        height: 48px;
        border: 0;
        border-radius: 14px;
        color: #fff;
        background: linear-gradient(135deg, var(--brand-orange-light), var(--brand-orange));
        font-size: 14px;
        font-weight: 900;
        cursor: pointer;
        box-shadow: 0 9px 20px rgba(244, 122, 0, .20);
    }

    .search-row button:hover {
        filter: brightness(.98);
    }

    /* ---------------------------------------------------------
           Messages
        --------------------------------------------------------- */
    .message {
        width: 100%;
        margin-top: 14px;
        padding: 13px 14px;
        border: 1px solid #bfdbfe;
        border-radius: 16px;
        color: #1d4ed8;
        background: #eff6ff;
        font-size: 13px;
        line-height: 1.45;
        font-weight: 800;
        overflow-wrap: anywhere;
    }

    .message.danger {
        color: #991b1b;
        border-color: #fecaca;
        background: #fef2f2;
    }

    /* ---------------------------------------------------------
           Order Summary
        --------------------------------------------------------- */
    .order-card {
        margin-top: 14px;
        padding: 18px;
    }

    .order-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        min-width: 0;
    }

    .order-head h2 {
        min-width: 0;
        margin: 0;
        color: var(--ink);
        font-size: clamp(22px, 4.5vw, 30px);
        line-height: 1.16;
        font-weight: 950;
        letter-spacing: -.4px;
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    .status-badge {
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        max-width: 150px;
        padding: 7px 10px;
        border-radius: 999px;
        background: var(--green-soft);
        color: var(--green-dark);
        font-size: 10px;
        line-height: 1.25;
        font-weight: 900;
        text-align: center;
        white-space: normal;
    }

    .status-badge::before {
        content: "";
        width: 7px;
        height: 7px;
        flex: 0 0 7px;
        border-radius: 50%;
        background: currentColor;
    }

    .status-badge.cancel,
    .status-badge.delay {
        color: #991b1b;
        background: #fee2e2;
    }

    .status-badge.payment {
        color: #92400e;
        background: #fef3c7;
    }

    .product {
        margin-top: 8px;
        color: var(--muted);
        font-size: 14px;
        line-height: 1.4;
        font-weight: 750;
        overflow-wrap: anywhere;
    }

    .progress-heading {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        margin-top: 18px;
        color: var(--muted);
        font-size: 12px;
        font-weight: 900;
    }

    .progress-percent {
        color: var(--brand-blue);
        font-size: 18px;
        font-weight: 950;
    }

    .progress {
        width: 100%;
        height: 9px;
        margin-top: 7px;
        overflow: hidden;
        border-radius: 999px;
        background: #e2e8f0;
    }

    .progress-fill {
        width: 0;
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, var(--brand-orange), var(--brand-orange-light));
        transition: width 1.4s cubic-bezier(.22, .75, .18, 1);
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 9px;
        margin-top: 16px;
    }

    .info-box {
        min-width: 0;
        min-height: 66px;
        padding: 11px 12px;
        border: 1px solid #dde6ef;
        border-radius: 14px;
        background: var(--soft);
    }

    .info-box span {
        display: block;
        margin-bottom: 5px;
        color: var(--muted);
        font-size: 10px;
        line-height: 1.3;
        font-weight: 900;
    }

    .info-box h3 {
        color: var(--ink);
        font-size: 14px;
        line-height: 1.35;
        font-weight: 950;
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    /* ---------------------------------------------------------
           Animated Workflow
        --------------------------------------------------------- */
    .timeline {
        position: relative;
        width: 100%;
        margin-top: 14px;
        padding: 12px 0 8px;
    }

    .timeline-route {
        position: absolute;
        z-index: 1;
        left: 37px;
        top: 33px;
        bottom: 36px;
        width: 4px;
        border-radius: 999px;
        background: #d3dbe4;
    }

    .timeline-route-fill {
        position: absolute;
        left: 0;
        bottom: 0;
        width: 100%;
        height: 0;
        border-radius: inherit;
        background: linear-gradient(0deg,
                var(--green) 0%,
                var(--green) 72%,
                var(--brand-orange) 100%);
        transition: height 1.55s cubic-bezier(.22, .75, .18, 1);
    }

    .paper-plane {
        position: absolute;
        z-index: 8;
        left: -1px;
        bottom: 14px;
        width: 74px;
        height: 58px;
        pointer-events: none;
        will-change: transform;
    }

    .paper-plane-float {
        width: 100%;
        height: 100%;
        transform-origin: 50% 50%;
        animation: planeFloat 2s ease-in-out infinite;
    }

    .paper-plane svg {
        width: 100%;
        height: 100%;
        overflow: visible;
        filter: drop-shadow(0 9px 10px rgba(244, 122, 0, .18));
    }

    @keyframes planeFloat {

        0%,
        100% {
            transform: translateY(0) rotate(-5deg);
        }

        50% {
            transform: translateY(-5px) rotate(2deg);
        }
    }

    .plane-trail {
        position: absolute;
        z-index: 0;
        left: 23px;
        bottom: 50px;
        width: 58px;
        height: 130px;
        opacity: .62;
        pointer-events: none;
    }

    .plane-trail::before,
    .plane-trail::after {
        content: "";
        position: absolute;
        border: 2px dashed rgba(244, 122, 0, .33);
        border-color: rgba(244, 122, 0, .33) transparent transparent rgba(244, 122, 0, .33);
        border-radius: 50%;
    }

    .plane-trail::before {
        width: 42px;
        height: 86px;
        left: 0;
        bottom: 0;
        transform: rotate(-17deg);
    }

    .plane-trail::after {
        width: 34px;
        height: 66px;
        left: 19px;
        bottom: 52px;
        transform: rotate(31deg);
    }

    .tracking-step {
        position: relative;
        z-index: 2;
        width: auto;
        min-width: 0;
        margin: 0 0 10px 76px;
        opacity: 0;
        transform: translateY(10px);
        animation: stepReveal .48s ease forwards;
    }

    @keyframes stepReveal {
        to {
            opacity: 1;
            transform: none;
        }
    }

    .step-node {
        position: absolute;
        z-index: 4;
        left: -53px;
        top: 15px;
        width: 30px;
        height: 30px;
        display: grid;
        place-items: center;
        border: 4px solid var(--pending);
        border-radius: 50%;
        background: #fff;
        color: var(--pending);
        font-size: 12px;
        font-weight: 950;
    }

    .tracking-step.done .step-node {
        color: #fff;
        border-color: var(--green);
        background: var(--green);
    }

    .tracking-step.live .step-node {
        color: var(--brand-orange);
        border-color: var(--brand-orange);
        background: #fff;
        box-shadow:
            0 0 0 7px rgba(244, 122, 0, .08),
            0 0 20px rgba(244, 122, 0, .16);
        animation: currentPulse 1.8s ease-in-out infinite;
    }

    .tracking-step.live .step-node::after {
        content: "";
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--brand-orange);
    }

    .tracking-step.delay .step-node,
    .tracking-step.cancel .step-node {
        color: #fff;
        border-color: var(--danger);
        background: var(--danger);
    }

    .tracking-step.payment .step-node {
        color: #fff;
        border-color: var(--warning);
        background: var(--warning);
        box-shadow: 0 0 0 7px rgba(217, 119, 6, .08);
    }

    @keyframes currentPulse {

        0%,
        100% {
            box-shadow:
                0 0 0 6px rgba(244, 122, 0, .07),
                0 0 13px rgba(244, 122, 0, .14);
        }

        50% {
            box-shadow:
                0 0 0 12px rgba(244, 122, 0, .025),
                0 0 28px rgba(244, 122, 0, .28);
        }
    }

    .step-card {
        width: 100%;
        min-width: 0;
        overflow: hidden;
        border: 1px solid var(--line);
        border-radius: 16px;
        background: #fff;
        box-shadow: 0 7px 20px rgba(20, 40, 60, .045);
    }

    .tracking-step.is-active .step-card {
        border-color: #ffd0a5;
        background: linear-gradient(90deg, #fff6ed 0%, #fff 76%);
        box-shadow: 0 9px 25px rgba(244, 122, 0, .10);
    }

    .tracking-step.delay .step-card,
    .tracking-step.cancel .step-card {
        border-color: #fecaca;
        background: #fffafa;
    }

    .tracking-step.payment .step-card {
        border-color: #fde68a;
        background: #fffdf6;
    }

    .step-button {
        width: 100%;
        min-width: 0;
        min-height: 58px;
        padding: 12px 13px;
        border: 0;
        background: transparent;
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto 18px;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        text-align: left;
    }

    .step-title-wrap {
        min-width: 0;
    }

    .step-title {
        display: block;
        min-width: 0;
        color: var(--ink);
        font-size: 15px;
        line-height: 1.25;
        font-weight: 950;
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    .tracking-step.is-active .step-title {
        color: var(--brand-blue);
    }

    .step-date-line {
        display: block;
        margin-top: 4px;
        color: var(--muted);
        font-size: 10.5px;
        line-height: 1.4;
        font-weight: 800;
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    .step-payment-note {
        display: block;
        margin-top: 4px;
        color: #92400e;
        font-size: 10.5px;
        line-height: 1.4;
        font-weight: 900;
        overflow-wrap: anywhere;
    }

    .step-status {
        max-width: 115px;
        padding: 6px 8px;
        border-radius: 999px;
        color: #5d6c7d;
        background: #f0f3f6;
        font-size: 9.5px;
        line-height: 1.2;
        font-weight: 900;
        white-space: normal;
        text-align: center;
    }

    .step-status.done {
        color: var(--green-dark);
        background: var(--green-soft);
    }

    .step-status.live {
        color: #d45f00;
        background: var(--brand-orange-soft);
    }

    .step-status.delay,
    .step-status.cancel {
        color: #991b1b;
        background: #fee2e2;
    }

    .step-status.payment {
        color: #92400e;
        background: #fef3c7;
    }

    .step-arrow {
        color: var(--muted);
        font-size: 17px;
        line-height: 1;
        font-weight: 900;
        transition: transform .24s ease;
    }

    .tracking-step.is-open .step-arrow {
        transform: rotate(180deg);
    }

    .step-details {
        display: grid;
        grid-template-rows: 0fr;
        transition: grid-template-rows .28s ease;
    }

    .step-details>div {
        overflow: hidden;
    }

    .tracking-step.is-open .step-details {
        grid-template-rows: 1fr;
    }

    .step-details-inner {
        padding: 0 13px;
    }

    .tracking-step.is-open .step-details-inner {
        padding-bottom: 13px;
    }

    .detail-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
        padding-top: 10px;
        border-top: 1px solid #edf1f5;
    }

    .detail-box {
        min-width: 0;
        padding: 9px;
        border: 1px solid #e3eaf1;
        border-radius: 12px;
        background: var(--soft);
    }

    .detail-box small {
        display: block;
        margin-bottom: 4px;
        color: var(--muted);
        font-size: 9px;
        line-height: 1.3;
        font-weight: 900;
        text-transform: uppercase;
    }

    .detail-box strong {
        display: block;
        color: var(--ink);
        font-size: 11px;
        line-height: 1.4;
        font-weight: 850;
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    .remarks {
        margin-top: 8px;
        padding: 10px;
        border-radius: 12px;
        color: #1d4ed8;
        background: #eff6ff;
        font-size: 11px;
        line-height: 1.45;
        font-weight: 800;
        overflow-wrap: anywhere;
    }

    .remarks.delay {
        color: #991b1b;
        border: 1px solid #fecaca;
        background: var(--danger-soft);
    }

    .remarks.payment {
        color: #92400e;
        border: 1px solid #fde68a;
        background: var(--warning-soft);
    }

    .footer-note {
        margin-top: 12px;
        padding: 13px 14px;
        color: var(--muted);
        background: rgba(255, 255, 255, .84);
        border: 1px solid var(--line);
        border-radius: 15px;
        font-size: 11.5px;
        line-height: 1.5;
        font-weight: 800;
        text-align: center;
    }

    /* ---------------------------------------------------------
           Strict Mobile Responsiveness
        --------------------------------------------------------- */
    @media (max-width: 560px) {
        .page {
            padding: 8px 8px 20px;
        }

        .tracking-header {
            padding: 18px 14px 20px;
            border-radius: 21px;
        }

        .header-inner {
            grid-template-columns: 84px minmax(0, 1fr);
            gap: 11px;
        }

        .logo-panel {
            min-height: 76px;
            padding: 6px;
            border-radius: 15px;
        }

        .brand-logo {
            width: 78px;
        }

        .header-copy h4 {
            margin-bottom: 5px;
            font-size: 10.5px;
        }

        .header-copy h1 {
            font-size: clamp(24px, 8vw, 34px);
            line-height: 1.04;
            letter-spacing: -.4px;
        }

        .search-card,
        .order-card {
            padding: 12px;
            border-radius: 17px;
        }

        .search-card {
            margin-top: 10px;
        }

        .search-row {
            grid-template-columns: minmax(0, 1fr) 74px;
            gap: 7px;
        }

        .search-row input,
        .search-row button {
            height: 43px;
            border-radius: 12px;
        }

        .search-row input {
            padding: 0 10px;
            font-size: 12.5px;
        }

        .search-row button {
            font-size: 12px;
        }

        .order-card {
            margin-top: 10px;
        }

        .order-head {
            align-items: flex-start;
        }

        .order-head h2 {
            font-size: clamp(19px, 6.3vw, 25px);
        }

        .status-badge {
            max-width: 104px;
            padding: 5px 7px;
            font-size: 8.5px;
        }

        .product {
            font-size: 12px;
        }

        .progress-heading {
            margin-top: 13px;
            font-size: 10.5px;
        }

        .progress-percent {
            font-size: 15px;
        }

        .info-grid {
            gap: 7px;
            margin-top: 12px;
        }

        .info-box {
            min-height: 58px;
            padding: 9px;
            border-radius: 12px;
        }

        .info-box span {
            font-size: 9px;
        }

        .info-box h3 {
            font-size: 12px;
        }

        .timeline {
            margin-top: 10px;
            padding-top: 8px;
        }

        .timeline-route {
            left: 25px;
            top: 27px;
            bottom: 30px;
        }

        .tracking-step {
            margin-left: 57px;
            margin-bottom: 8px;
        }

        .step-node {
            left: -44px;
            top: 14px;
            width: 25px;
            height: 25px;
            border-width: 3px;
            font-size: 10px;
        }

        .step-button {
            min-height: 54px;
            padding: 10px 9px;
            grid-template-columns: minmax(0, 1fr) auto 15px;
            gap: 6px;
        }

        .step-title {
            font-size: 13px;
        }

        .step-date-line,
        .step-payment-note {
            font-size: 9.3px;
        }

        .step-status {
            max-width: 84px;
            padding: 5px 6px;
            font-size: 8px;
        }

        .step-arrow {
            font-size: 14px;
        }

        .paper-plane {
            left: -8px;
            width: 58px;
            height: 46px;
        }

        .plane-trail {
            left: 14px;
            width: 44px;
            height: 104px;
        }

        .detail-grid {
            gap: 6px;
        }

        .detail-box {
            padding: 8px;
        }

        .detail-box small {
            font-size: 8px;
        }

        .detail-box strong {
            font-size: 10px;
        }
    }

    @media (max-width: 390px) {
        .order-head {
            flex-wrap: wrap;
        }

        .status-badge {
            max-width: 100%;
        }

        .step-button {
            grid-template-columns: minmax(0, 1fr) 15px;
        }

        .step-status {
            grid-column: 1;
            grid-row: 2;
            justify-self: start;
            max-width: 100%;
            margin-top: 2px;
        }

        .step-arrow {
            grid-column: 2;
            grid-row: 1 / span 2;
        }
    }

    @media (max-width: 340px) {
        .header-inner {
            grid-template-columns: 1fr;
        }

        .logo-panel {
            width: 96px;
            min-height: 68px;
            justify-self: start;
        }

        .brand-logo {
            width: 86px;
        }

        .search-row {
            grid-template-columns: 1fr;
        }

        .search-row button {
            width: 100%;
        }

        .info-grid,
        .detail-grid {
            grid-template-columns: 1fr;
        }

        .tracking-step {
            margin-left: 54px;
        }

        .timeline-route {
            left: 23px;
        }

        .step-node {
            left: -42px;
        }

        .paper-plane {
            left: -10px;
            width: 54px;
        }
    }

    @media (prefers-reduced-motion: reduce) {

        *,
        *::before,
        *::after {
            scroll-behavior: auto !important;
            animation-duration: .01ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: .01ms !important;
        }
    }

    /* Loaded tracking number is display-only. */
    #job_card_no[readonly] {
        background: #f3f6fa;
        color: #334155;
        cursor: default;
        user-select: text;
        caret-color: transparent;
    }
    </style>
</head>

<body>
    <main class="page">

        <section class="tracking-header">
            <div class="header-inner">
                <div class="logo-panel">
                    <?php if (is_file(__DIR__ . '/assets/subhiksha-logo.png')): ?>
                    <img src="assets/subhiksha-logo.png" alt="Subhiksha Cards" class="brand-logo">
                    <?php else: ?>
                    <strong style="color:#0a438c;font-size:14px;text-align:center">Subhiksha Cards</strong>
                    <?php endif; ?>
                </div>

                <div class="header-copy">
                    <h4>Subhiksha Cards Customer Portal</h4>
                    <h1>Track Your Invitation Order</h1>
                </div>
            </div>
        </section>

        <form method="get" class="search-card card" autocomplete="off">
            <label for="job_card_no">Enter Job Card Number</label>

            <div class="search-row">
                <input type="text" id="job_card_no" name="job_card_no"
                    value="<?= e($job['job_card_no'] ?? $displayJobNo) ?>" placeholder="Example: SC-JOB-260807-0001"
                    <?= $job ? 'readonly aria-readonly="true"' : '' ?> required>
                <button type="submit">Track</button>
            </div>
        </form>

        <?php if ($message !== ''): ?>
        <div class="message <?= e($messageType === 'danger' ? 'danger' : '') ?>">
            <?= e($message) ?>
        </div>
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

                <div class="status-badge <?= e($mainStatusClass) ?>">
                    <?= e($publicStatus) ?>
                </div>
            </div>

            <div class="product"><?= e($orderTypeText) ?></div>

            <div class="progress-heading">
                <span>Overall Progress</span>
                <span class="progress-percent">
                    <span id="progressCounter">0</span>%
                </span>
            </div>

            <div class="progress">
                <div class="progress-fill" id="animatedProgress" data-progress="<?= (int)$progressPercent ?>"></div>
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
                        <br>
                        <small style="color:#d45f00;font-weight:900">
                            Balance <?= e(ctMoney($paymentSnapshot['balance_amount'])) ?>
                        </small>
                        <?php endif; ?>
                    </h3>
                </div>
            </div>
        </section>

        <section class="timeline" id="trackingTimeline" data-current-index="<?= (int)$displayOpenStepIndex ?>">
            <?php if (!$steps): ?>

            <div class="message">No tracking stages found for this job card.</div>

            <?php else: ?>

            <div class="timeline-route" aria-hidden="true">
                <div class="timeline-route-fill" id="timelineRouteFill"></div>
            </div>

            <div class="plane-trail" id="planeTrail" aria-hidden="true"></div>

            <div class="paper-plane" id="paperPlane" aria-hidden="true">
                <div class="paper-plane-float">
                    <svg viewBox="0 0 90 70">
                        <defs>
                            <linearGradient id="planeOrange" x1="0" y1="0" x2="1" y2="1">
                                <stop offset="0%" stop-color="#ffad20" />
                                <stop offset="52%" stop-color="#f47a00" />
                                <stop offset="100%" stop-color="#d95d00" />
                            </linearGradient>

                            <linearGradient id="planeFold" x1="0" y1="0" x2="1" y2="1">
                                <stop offset="0%" stop-color="#ffd28a" />
                                <stop offset="100%" stop-color="#f47a00" />
                            </linearGradient>
                        </defs>

                        <path d="M5 34 L83 5 L58 61 L40 45 L28 58 L31 40 Z" fill="url(#planeOrange)" />

                        <path d="M31 40 L83 5 L40 45 Z" fill="#ffbf56" opacity=".96" />

                        <path d="M40 45 L58 61 L48 43 Z" fill="url(#planeFold)" />

                        <path d="M31 40 L28 58 L40 45 Z" fill="#da6000" opacity=".92" />
                    </svg>
                </div>
            </div>

            <?php foreach ($displaySteps as $index => $step): ?>
            <?php
                $status = strtolower((string)($step['status'] ?? 'pending'));
                $isOpen = $index === $displayOpenStepIndex;
                $isDispatchPaymentPending = ctDispatchPaymentPending($step, $paymentSnapshot);

                $displayStatus = ($isOpen && $status === 'pending')
                    ? 'in_progress'
                    : $status;

                if ($isDispatchPaymentPending) {
                    $displayStatus = 'payment_pending';
                }

                $class = ctStatusClass($displayStatus);
                $isDone = ctStatusClass($status) === 'done';

                $isActive =
                    in_array($class, ['live', 'delay', 'payment'], true)
                    || $isOpen;

                // Display is reversed, but workflow numbering stays 1..N from bottom to top.
                $workflowNumber = max(1, $totalSteps - $index);
                $icon = $isDone ? '✓' : (string)$workflowNumber;

                $statusText = $isDispatchPaymentPending
                    ? 'Payment Pending'
                    : ctStatusLabel($displayStatus);

                $summaryParts = [];

                if (!empty($step['actual_start_at'])) {
                    $summaryParts[] = 'Start: ' . ctDateTime($step['actual_start_at']);
                }

                if ($isDone && !empty($step['actual_completed_at'])) {
                    $summaryParts[] = 'Completed: ' . ctDateTime($step['actual_completed_at']);
                } elseif (!$isDone && !empty($step['planned_completion_date'])) {
                    $summaryParts[] = 'Expected: ' . ctDate($step['planned_completion_date']);
                }

                $summaryText = implode(' · ', $summaryParts);
            ?>

            <article
                class="tracking-step <?= e($isActive ? 'is-active' : '') ?> <?= e($class) ?> <?= e($isOpen ? 'is-open' : '') ?>"
                data-step-index="<?= (int)$index ?>"
                style="animation-delay: <?= number_format($index * 0.07, 2, '.', '') ?>s">
                <span class="step-node">
                    <?= $isDone ? '✓' : e($icon) ?>
                </span>

                <div class="step-card">
                    <button type="button" class="step-button" aria-expanded="<?= $isOpen ? 'true' : 'false' ?>">
                        <span class="step-title-wrap">
                            <span class="step-title">
                                <?= e($step['step_name'] ?? '-') ?>
                            </span>

                            <?php if ($summaryText !== ''): ?>
                            <span class="step-date-line">
                                <?= e($summaryText) ?>
                            </span>
                            <?php endif; ?>

                            <?php if ($isDispatchPaymentPending): ?>
                            <span class="step-payment-note">
                                Balance payment pending:
                                <?= e(ctMoney($paymentSnapshot['balance_amount'])) ?>
                            </span>
                            <?php endif; ?>
                        </span>

                        <span class="step-status <?= e($class) ?>">
                            <?= e($statusText) ?>
                        </span>

                        <span class="step-arrow">⌄</span>
                    </button>

                    <div class="step-details">
                        <div>
                            <div class="step-details-inner">
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

                                <?php if ($isDispatchPaymentPending): ?>
                                <div class="remarks payment">
                                    Payment Pending:
                                    Balance <?= e(ctMoney($paymentSnapshot['balance_amount'])) ?>
                                    must be paid before dispatch completion.
                                </div>
                                <?php endif; ?>

                                <?php if ($status === 'delayed' || (int)($step['is_delayed'] ?? 0) === 1): ?>
                                <div class="remarks delay">
                                    Delay Alert:
                                    <?= e($step['delay_reason_name'] ?? 'Reason not updated') ?>
                                    <?= !empty($step['delay_days']) ? ' | Delay Days: ' . e($step['delay_days']) : '' ?>
                                    <?= !empty($step['delay_remarks']) ? ' | Remark: ' . e($step['delay_remarks']) : '' ?>
                                </div>

                                <?php elseif (!empty($step['remarks'])): ?>
                                <div class="remarks">
                                    Update: <?= e($step['remarks']) ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </article>

            <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <p class="footer-note">
            Tracking information is updated as your order moves forward.
            Please contact Subhiksha Cards for urgent changes.
        </p>

        <?php endif; ?>
    </main>

    <script>
    (function() {
        const progressBar = document.getElementById('animatedProgress');
        const progressCounter = document.getElementById('progressCounter');

        function animateProgress() {
            if (!progressBar || !progressCounter) {
                return;
            }

            const target = Math.max(
                0,
                Math.min(
                    100,
                    parseInt(progressBar.dataset.progress || '0', 10)
                )
            );

            progressBar.style.width = '0%';
            progressCounter.textContent = '0';

            requestAnimationFrame(function() {
                requestAnimationFrame(function() {
                    progressBar.style.width = target + '%';
                });
            });

            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                progressCounter.textContent = target;
                return;
            }

            let value = 0;
            const stepTime = Math.max(12, Math.floor(900 / Math.max(target, 1)));

            const timer = setInterval(function() {
                value += 1;

                if (value >= target) {
                    value = target;
                    clearInterval(timer);
                }

                progressCounter.textContent = value;
            }, stepTime);
        }

        const timeline = document.getElementById('trackingTimeline');
        const plane = document.getElementById('paperPlane');
        const trail = document.getElementById('planeTrail');
        const routeFill = document.getElementById('timelineRouteFill');

        function resolveCurrentStep() {
            if (!timeline) {
                return null;
            }

            const allSteps = Array.from(
                timeline.querySelectorAll('.tracking-step')
            );

            if (!allSteps.length) {
                return null;
            }

            let index = parseInt(
                timeline.dataset.currentIndex || '-1',
                10
            );

            /*
             * If every stage is already completed, there is no open index.
             * In that case, use the final customer-visible stage.
             */
            if (index < 0 || index >= allSteps.length) {
                index = allSteps.length - 1;
            }

            return allSteps[index] || null;
        }

        function positionPlane(animate) {
            if (!timeline || !plane || !routeFill) {
                return;
            }

            const currentStep = resolveCurrentStep();

            if (!currentStep) {
                plane.style.display = 'none';

                if (trail) {
                    trail.style.display = 'none';
                }

                return;
            }

            plane.style.display = '';
            if (trail) {
                trail.style.display = '';
            }

            /*
             * The paper plane MUST start from the BOTTOM and fly UP.
             *
             * startTop  = bottom of complete timeline
             * targetTop = header/node area of current workflow stage
             */
            const planeHeight = plane.offsetHeight || 58;
            const startTop = Math.max(
                0,
                timeline.offsetHeight - planeHeight - 10
            );

            const currentNodeCenter =
                currentStep.offsetTop + 29;

            const targetTop = Math.max(
                0,
                currentNodeCenter - Math.round(planeHeight / 2)
            );

            /*
             * Timeline is displayed bottom -> top.
             * Fill the route from the bottom (Enquiry) up to the current stage.
             */
            const routeTop = 33;
            const routeBottom = Math.max(routeTop, timeline.offsetHeight - 36);
            const routeHeight = Math.max(0, routeBottom - routeTop);
            const fillHeight = Math.max(
                0,
                Math.min(routeHeight, routeBottom - currentNodeCenter)
            );

            routeFill.style.height = fillHeight + 'px';

            plane.style.transition = 'none';
            plane.style.bottom = 'auto';
            plane.style.top = startTop + 'px';
            plane.style.transform = 'translateY(0)';

            if (trail) {
                trail.style.transition = 'none';
                trail.style.bottom = 'auto';
                trail.style.top = Math.min(
                    timeline.offsetHeight - 110,
                    startTop - 55
                ) + 'px';
                trail.style.transform = 'translateY(0)';
            }

            void plane.offsetWidth;

            plane.style.transition = animate ?
                'transform 1.9s cubic-bezier(.22,.78,.16,1)' :
                'none';

            if (trail) {
                trail.style.transition = animate ?
                    'transform 1.9s cubic-bezier(.22,.78,.16,1)' :
                    'none';
            }

            requestAnimationFrame(function() {
                plane.style.transform =
                    'translateY(' + (targetTop - startTop) + 'px)';

                if (trail) {
                    const trailStartTop = parseFloat(trail.style.top || '0');
                    const trailTargetTop = Math.max(
                        0,
                        targetTop + 18
                    );

                    trail.style.transform =
                        'translateY(' + (trailTargetTop - trailStartTop) + 'px)';
                }
            });
        }

        function initializePlane() {
            if (!timeline) {
                return;
            }

            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                positionPlane(false);
                return;
            }

            /*
             * A very small delay lets the customer first see the workflow,
             * then clearly see the plane fly from bottom to current stage.
             */
            setTimeout(function() {
                positionPlane(true);
            }, 180);
        }

        document.querySelectorAll('.step-button').forEach(function(button) {
            button.addEventListener('click', function() {
                const item = button.closest('.tracking-step');

                if (!item) {
                    return;
                }

                const isOpen = item.classList.toggle('is-open');

                button.setAttribute(
                    'aria-expanded',
                    isOpen ? 'true' : 'false'
                );

                /*
                 * Recalculate after expand/collapse so mobile layout and
                 * the paper plane remain perfectly aligned.
                 */
                setTimeout(function() {
                    positionPlane(false);
                }, 300);
            });
        });

        let resizeTimer = null;

        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);

            resizeTimer = setTimeout(function() {
                positionPlane(false);
            }, 120);
        });

        window.addEventListener('orientationchange', function() {
            setTimeout(function() {
                positionPlane(false);
            }, 240);
        });

        window.addEventListener('load', function() {
            animateProgress();
            initializePlane();
        });
    })();
    </script>
</body>

</html>