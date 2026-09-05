<?php

require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'job_cards.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Job Card Timezone Policy
|--------------------------------------------------------------------------
| Database timestamps are stored in UTC.
| All ERP/system display times use Asia/Kolkata (IST).
| Keeping storage and display timezone separate prevents the 5:30 mismatch.
*/
if (!defined('JCV_SYSTEM_TIMEZONE')) {
    define('JCV_SYSTEM_TIMEZONE', 'Asia/Kolkata');
}

if (!defined('JCV_DATABASE_TIMEZONE')) {
    define('JCV_DATABASE_TIMEZONE', 'UTC');
}

/* Make all PHP date()/strtotime() operations on this page use ERP local time. */
date_default_timezone_set(JCV_SYSTEM_TIMEZONE);

/*
 * Keep this MySQL connection in UTC so CURRENT_TIMESTAMP/default timestamp
 * values created from this page also remain consistent with UTC storage.
 */
try {
    $conn->query("SET time_zone = '+00:00'");
} catch (Throwable $e) {
    // UTC_TIMESTAMP() is also used explicitly below, so writes remain safe.
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function jcvTableExists(mysqli $conn, string $table): bool
{
    try {
        $table = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '{$table}'");
        $ok = $res && $res->num_rows > 0;

        if ($res) {
            $res->free();
        }

        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

function jcvValidProductName($value): bool
{
    $value = trim((string)$value);
    if ($value === '') return false;

    return !in_array(strtolower($value), ['0', '-', 'null', 'n/a', 'na'], true);
}

function jcvResolvedProductName(mysqli $conn, array $job): string
{
    if (jcvValidProductName($job['product_name'] ?? '')) {
        return trim((string)$job['product_name']);
    }

    $jobId = (int)($job['id'] ?? 0);
    $productIds = [];

    if (!empty($job['product_id'])) {
        $productIds[] = (int)$job['product_id'];
    }

    // Older converted jobs can contain the sentinel text "0" in
    // job_cards.product_name. Prefer the actual job-card item when present.
    if ($jobId > 0 && jcvTableExists($conn, 'job_card_items')) {
        try {
            $stmt = $conn->prepare(
                'SELECT product_id, item_name
                 FROM job_card_items
                 WHERE job_card_id = ?
                 ORDER BY id ASC'
            );
            $stmt->bind_param('i', $jobId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                if (!empty($row['product_id'])) {
                    $productIds[] = (int)$row['product_id'];
                }
                if (jcvValidProductName($row['item_name'] ?? '')) {
                    $name = trim((string)$row['item_name']);
                    $stmt->close();
                    return $name;
                }
            }
            $stmt->close();
        } catch (Throwable $e) {
            // Continue to the originating Proforma fallback.
        }
    }

    // For Proforma-converted jobs, recover the product from the source item.
    $proformaId = (int)($job['proforma_bill_id'] ?? 0);
    if ($proformaId > 0 && jcvTableExists($conn, 'proforma_bill_items')) {
        try {
            $stmt = $conn->prepare(
                'SELECT product_id, item_name
                 FROM proforma_bill_items
                 WHERE proforma_bill_id = ?
                 ORDER BY sort_order ASC, id ASC'
            );
            $stmt->bind_param('i', $proformaId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                if (!empty($row['product_id'])) {
                    $productIds[] = (int)$row['product_id'];
                }
                if (jcvValidProductName($row['item_name'] ?? '')) {
                    $name = trim((string)$row['item_name']);
                    $stmt->close();
                    return $name;
                }
            }
            $stmt->close();
        } catch (Throwable $e) {
            // Continue to product-master lookup.
        }
    }

    if (jcvTableExists($conn, 'products')) {
        foreach (array_values(array_unique(array_filter($productIds))) as $productId) {
            try {
                $stmt = $conn->prepare('SELECT product_name FROM products WHERE id = ? LIMIT 1');
                $stmt->bind_param('i', $productId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($row && jcvValidProductName($row['product_name'] ?? '')) {
                    return trim((string)$row['product_name']);
                }
            } catch (Throwable $e) {
                // Try the next available product id.
            }
        }
    }

    // Never expose the old database sentinel value "0" to the customer.
    return 'Cards';
}


/**
 * Return every product/item belonging to this Job Card.
 *
 * New Proforma logic creates ONE Job Card with multiple job_card_items.
 * This reader is display-only and does not change workflow/status logic.
 * It falls back to proforma_bill_items for older converted jobs if needed.
 */
function jcvFetchJobItems(mysqli $conn, array $job): array
{
    $jobId = (int)($job['id'] ?? 0);
    $proformaId = (int)($job['proforma_bill_id'] ?? 0);
    $items = [];

    if ($jobId > 0 && jcvTableExists($conn, 'job_card_items')) {
        try {
            $stmt = $conn->prepare("
                SELECT
                    id,
                    product_id,
                    item_name,
                    description,
                    qty,
                    rate,
                    amount,
                    size_text,
                    gsm_thickness,
                    lamination_required,
                    lamination_type,
                    printing_side,
                    screening_type,
                    finishing_required
                FROM job_card_items
                WHERE job_card_id = ?
                ORDER BY id ASC
            ");
            $stmt->bind_param('i', $jobId);
            $stmt->execute();
            $res = $stmt->get_result();

            while ($row = $res->fetch_assoc()) {
                $items[] = $row;
            }

            $stmt->close();
        } catch (Throwable $e) {
            $items = [];
        }
    }

    /*
     * Backward-compatible fallback:
     * If an older Proforma-created Job Card has no job_card_items yet,
     * show the original Proforma items instead of showing only the header product.
     */
    if (!$items && $proformaId > 0 && jcvTableExists($conn, 'proforma_bill_items')) {
        try {
            $stmt = $conn->prepare("
                SELECT
                    id,
                    product_id,
                    item_name,
                    description,
                    qty,
                    rate,
                    amount,
                    size_text,
                    gsm_thickness,
                    lamination_required,
                    lamination_type,
                    printing_side,
                    screening_type,
                    finishing_required
                FROM proforma_bill_items
                WHERE proforma_bill_id = ?
                ORDER BY sort_order ASC, id ASC
            ");
            $stmt->bind_param('i', $proformaId);
            $stmt->execute();
            $res = $stmt->get_result();

            while ($row = $res->fetch_assoc()) {
                $items[] = $row;
            }

            $stmt->close();
        } catch (Throwable $e) {
            $items = [];
        }
    }

    /*
     * Very old single-product Job Cards may have no item row.
     * Keep the existing header product visible in that case.
     */
    if (!$items && $jobId > 0) {
        $name = jcvResolvedProductName($conn, $job);

        $items[] = [
            'id' => 0,
            'product_id' => !empty($job['product_id']) ? (int)$job['product_id'] : null,
            'item_name' => $name,
            'description' => (string)($job['notes'] ?? ''),
            'qty' => 0,
            'rate' => 0,
            'amount' => 0,
            'size_text' => '',
            'gsm_thickness' => '',
            'lamination_required' => 0,
            'lamination_type' => null,
            'printing_side' => null,
            'screening_type' => null,
            'finishing_required' => 0,
        ];
    }

    return $items;
}

function jcvProductItemsSummary(array $items): string
{
    $names = [];

    foreach ($items as $item) {
        $name = trim((string)($item['item_name'] ?? ''));
        if (jcvValidProductName($name)) {
            $names[] = $name;
        }
    }

    $names = array_values(array_unique($names));

    if (!$names) {
        return 'Cards';
    }

    if (count($names) === 1) {
        return $names[0];
    }

    return $names[0] . ' +' . (count($names) - 1) . ' more';
}

function jcvDate($value): string
{
    return !empty($value) ? date('d-m-Y', strtotime($value)) : '-';
}

function jcvDateTime($value): string
{
    if (empty($value)) {
        return '-';
    }

    try {
        $dbTz = new DateTimeZone(JCV_DATABASE_TIMEZONE);
        $systemTz = new DateTimeZone(JCV_SYSTEM_TIMEZONE);

        /*
         * MySQL DATETIME values do not carry timezone information.
         * Treat stored job timestamps as UTC, then convert them to the
         * ERP/system timezone only when displaying them.
         */
        $dt = DateTime::createFromFormat(
            'Y-m-d H:i:s',
            trim((string)$value),
            $dbTz
        );

        if (!$dt) {
            $dt = new DateTime((string)$value, $dbTz);
        }

        $dt->setTimezone($systemTz);

        return $dt->format('d-m-Y h:i A');
    } catch (Throwable $e) {
        return (string)$value;
    }
}

function jcvMoney($value): string
{
    return '₹' . number_format((float)$value, 2);
}

function jcvIsSendToDispatchStageKey(?string $stepKey, ?string $stepName = ''): bool
{
    $key = strtolower(trim((string)$stepKey));
    $name = strtolower(trim((string)$stepName));
    $keyText = str_replace(['-', '_'], ' ', $key);

    return in_array($key, ['send_to_dispatch', 'send_for_dispatch', 'send_dispatch', 'sent_to_dispatch', 'send_to_despatch', 'send_for_despatch'], true)
        || in_array($name, ['send to dispatch', 'send for dispatch', 'sent to dispatch', 'send to despatch', 'send for despatch'], true)
        || ((strpos($keyText, 'send') !== false || strpos($keyText, 'sent') !== false) && (strpos($keyText, 'dispatch') !== false || strpos($keyText, 'despatch') !== false))
        || ((strpos($name, 'send') !== false || strpos($name, 'sent') !== false) && (strpos($name, 'dispatch') !== false || strpos($name, 'despatch') !== false));
}

function jcvIsReadyForDispatchStageKey(?string $stepKey, ?string $stepName = ''): bool
{
    $key = strtolower(trim((string)$stepKey));
    $name = strtolower(trim((string)$stepName));

    return in_array($key, ['ready_for_dispatch', 'ready_to_dispatch', 'ready_for_despatch', 'ready_to_despatch'], true)
        || in_array($name, ['ready for dispatch', 'ready to dispatch', 'ready for despatch', 'ready to despatch'], true);
}

function jcvIsInternalDispatchStageKey(?string $stepKey, ?string $stepName = ''): bool
{
    return jcvIsSendToDispatchStageKey($stepKey, $stepName)
        || jcvIsReadyForDispatchStageKey($stepKey, $stepName);
}

function jcvIsDispatchStageKey(?string $stepKey, ?string $stepName = ''): bool
{
    $key = strtolower(trim((string)$stepKey));
    $name = strtolower(trim((string)$stepName));

    // Final customer-facing dispatch only. Internal handover stages like
    // Send to Dispatch / Ready for Dispatch stay updateable but do not trigger
    // WhatsApp, payment lock, or customer-tracking display as Dispatch.
    if (jcvIsInternalDispatchStageKey($key, $name)) {
        return false;
    }

    return $key === 'dispatch'
        || in_array($key, ['dispatched', 'despatch', 'delivered'], true)
        || in_array($name, ['dispatch', 'dispatched', 'despatch', 'delivered'], true)
        || strpos($key, 'dispatch') !== false
        || strpos($key, 'despatch') !== false;
}


function jcvPaymentSnapshot(mysqli $conn, array $job): array
{
    $final = (float)($job['final_amount'] ?? 0);
    $storedAdvance = (float)($job['advance_amount'] ?? 0);
    $storedBalance = (float)($job['balance_amount'] ?? 0);
    $paid = $storedAdvance;
    $usedLedger = false;

    $proformaId = (int)($job['proforma_bill_id'] ?? 0);
    if ($proformaId > 0 && jcvTableExists($conn, 'payments')) {
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

    return [
        'final_amount' => $final,
        'paid_amount' => $paid,
        'balance_amount' => $balance,
        'is_paid' => $isPaid,
        'used_ledger' => $usedLedger,
    ];
}

function jcvAssertDispatchPaymentAllowed(mysqli $conn, array $job, array $step, string $newStatus): void
{
    if ($newStatus !== 'completed') return;

    if (!jcvIsDispatchStageKey($step['step_key'] ?? '', $step['step_name'] ?? '')) {
        return;
    }

    $payment = jcvPaymentSnapshot($conn, $job);
    if (!empty($payment['is_paid'])) {
        return;
    }

    throw new RuntimeException(
        'Payment Pending: Balance amount ' . jcvMoney($payment['balance_amount'] ?? 0) .
        ' must be collected before completing Dispatch.'
    );
}

function jcvCompleteDispatchGroup(mysqli $conn, int $jobId, int $userId, string $remarks = ''): void
{
    if ($jobId <= 0 || !jcvTableExists($conn, 'job_tracking') || !jcvTableExists($conn, 'workflow_steps')) {
        return;
    }

    $stmt = $conn->prepare("\n        UPDATE job_tracking jt\n        INNER JOIN workflow_steps ws\n            ON ws.id = jt.workflow_step_id\n        SET\n            jt.status = 'completed',\n            jt.actual_start_at = COALESCE(jt.actual_start_at, UTC_TIMESTAMP()),\n            jt.actual_completed_at = COALESCE(jt.actual_completed_at, UTC_TIMESTAMP()),\n            jt.completed_by = COALESCE(jt.completed_by, ?),\n            jt.remarks = CASE\n                WHEN TRIM(COALESCE(jt.remarks, '')) = '' THEN ?\n                ELSE jt.remarks\n            END,\n            jt.updated_at = UTC_TIMESTAMP()\n        WHERE jt.job_card_id = ?\n          AND jt.status NOT IN ('completed', 'skipped', 'cancelled')\n          AND (\n                ws.step_key IN ('ready_for_dispatch', 'dispatched', 'dispatch')\n                OR LOWER(ws.step_name) IN ('ready for dispatch', 'dispatched', 'dispatch')\n                OR (ws.step_key LIKE '%dispatch%' AND ws.step_key NOT LIKE 'send_to%')\n          )\n    ");
    $stmt->bind_param('isi', $userId, $remarks, $jobId);
    $stmt->execute();
    $stmt->close();
}

function jcvAutoStartNextPendingStage(mysqli $conn, int $jobId, int $trackingId): void
{
    if ($jobId <= 0 || $trackingId <= 0 || !jcvTableExists($conn, 'job_tracking')) {
        return;
    }

    $join = jcvTableExists($conn, 'workflow_steps')
        ? 'LEFT JOIN workflow_steps ws ON ws.id = jt.workflow_step_id'
        : '';
    $sortSelect = jcvTableExists($conn, 'workflow_steps')
        ? 'COALESCE(ws.sort_order, jt.id) AS sort_order'
        : 'jt.id AS sort_order';
    $sortSql = jcvTableExists($conn, 'workflow_steps')
        ? 'ORDER BY COALESCE(ws.sort_order, jt.id) ASC, jt.id ASC'
        : 'ORDER BY jt.id ASC';

    $stmt = $conn->prepare("\n        SELECT jt.id, jt.status, {$sortSelect}\n        FROM job_tracking jt\n        {$join}\n        WHERE jt.job_card_id = ?\n        {$sortSql}\n    ");
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    $foundCurrent = false;
    $nextId = 0;
    foreach ($rows as $row) {
        if ((int)($row['id'] ?? 0) === $trackingId) {
            $foundCurrent = true;
            continue;
        }

        if ($foundCurrent && strtolower(trim((string)($row['status'] ?? 'pending'))) === 'pending') {
            $nextId = (int)$row['id'];
            break;
        }
    }

    if ($nextId <= 0) return;

    $stmt = $conn->prepare("\n        UPDATE job_tracking\n        SET status = 'in_progress',\n            actual_start_at = COALESCE(actual_start_at, UTC_TIMESTAMP()),\n            updated_at = UTC_TIMESTAMP()\n        WHERE id = ?\n          AND job_card_id = ?\n          AND status = 'pending'\n    ");
    $stmt->bind_param('ii', $nextId, $jobId);
    $stmt->execute();
    $stmt->close();
}

function jcvFirstNonEmpty(array $rows, string $field): string
{
    foreach ($rows as $row) {
        $value = trim((string)($row[$field] ?? ''));
        if ($value !== '') return $value;
    }
    return '';
}

function jcvMinDateValue(array $rows, string $field): ?string
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

function jcvMaxDateValue(array $rows, string $field): ?string
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

function jcvMergedDispatchStatus(array $rows): string
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

function jcvMergeDispatchRows(array $rows): array
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
    $base['responsible_role_name'] = jcvFirstNonEmpty($rows, 'responsible_role_name') ?: 'Sales / Dispatch';
    $base['responsible_user_name'] = jcvFirstNonEmpty($rows, 'responsible_user_name') ?: '-';
    $base['completed_by_name'] = jcvFirstNonEmpty(array_reverse($rows), 'completed_by_name') ?: '-';
    $base['planned_start_date'] = jcvMinDateValue($rows, 'planned_start_date');
    $base['planned_completion_date'] = jcvMaxDateValue($rows, 'planned_completion_date');
    $base['actual_start_at'] = jcvMinDateValue($rows, 'actual_start_at');
    $base['actual_completed_at'] = jcvMaxDateValue($rows, 'actual_completed_at');
    $base['status'] = jcvMergedDispatchStatus($rows);

    $remarks = [];
    foreach ($rows as $row) {
        $text = trim((string)($row['remarks'] ?? ''));
        if ($text !== '') $remarks[] = $text;
    }
    if ($remarks) $base['remarks'] = implode(' | ', array_unique($remarks));

    return $base;
}

function jcvBuildDisplayTrackingRows(array $rows): array
{
    $out = [];
    $dispatchRows = [];
    $dispatchPosition = null;

    foreach ($rows as $row) {
        if (jcvIsDispatchStageKey($row['step_key'] ?? '', $row['step_name'] ?? '')) {
            if ($dispatchPosition === null) {
                $dispatchPosition = count($out);
                $out[] = ['__dispatch_placeholder' => true];
            }
            $dispatchRows[] = $row;
            continue;
        }
        $out[] = $row;
    }

    if ($dispatchPosition !== null) {
        $out[$dispatchPosition] = jcvMergeDispatchRows($dispatchRows);
    }

    return array_values(array_filter($out, static function ($row) {
        return empty($row['__dispatch_placeholder']);
    }));
}

function jcvRoleLabel(string $roleKey): string
{
    $labels = [
        'admin' => 'Admin',
        'sales' => 'Sales',
        'designing_proofing' => 'Designing / Proofing',
        'offset_printing' => 'Offset Printing',
        'screen_printing' => 'Screen Printing',
        'digital_printing' => 'Digital Printing',
        'multicolor_offset_printing' => 'Multicolor Offset Printing',
        'printing' => 'Printing'
    ];

    return $labels[$roleKey] ?? ucfirst(str_replace('_', ' ', $roleKey));
}

function jcvOrderBadgeClass(string $orderType): string
{
    return strtolower($orderType) === 'customized' ? 'customized' : 'readymade';
}

function jcvStatusClass(string $status): string
{
    $status = strtolower(trim($status));

    if ($status === 'completed') {
        return 'completed';
    }

    if (in_array($status, ['in_progress', 'progress'], true)) {
        return 'progress';
    }

    if (in_array($status, ['delayed', 'cancelled'], true)) {
        return 'danger';
    }

    return 'pending';
}

function jcvCanUpdateStep(
    string $roleKey,
    ?string $stepRoleKey,
    ?string $jobPrintingRoleKey,
    ?string $jobPrintingTypeRoleKey,
    bool $canUpdateJob,
    string $stepStatus
): bool {
    $roleKey = strtolower(trim($roleKey));
    $stepRoleKey = strtolower(trim((string)$stepRoleKey));
    $jobPrintingRoleKey = strtolower(trim((string)$jobPrintingRoleKey));
    $jobPrintingTypeRoleKey = strtolower(trim((string)$jobPrintingTypeRoleKey));
    $stepStatus = strtolower(trim($stepStatus));

    if (!$canUpdateJob) {
        return false;
    }

    if ($roleKey === 'admin') {
        return true;
    }

    if (in_array($stepStatus, ['completed', 'cancelled'], true)) {
        return false;
    }

    if ($stepRoleKey === $roleKey) {
        return true;
    }

    $printingRoles = [
        'offset_printing',
        'screen_printing',
        'digital_printing',
        'multicolor_offset_printing'
    ];

    if (in_array($roleKey, $printingRoles, true)) {
        if ($stepRoleKey === 'printing') {
            return $jobPrintingRoleKey === $roleKey || $jobPrintingTypeRoleKey === $roleKey;
        }

        return $stepRoleKey === $roleKey;
    }

    if ($roleKey === 'printing') {
        return $stepRoleKey === 'printing' || in_array($stepRoleKey, $printingRoles, true);
    }

    return false;
}

function jcvPreviousStepStatus(mysqli $conn, int $jobId, int $trackingId): ?array
{
    if ($jobId <= 0 || $trackingId <= 0 || !jcvTableExists($conn, 'job_tracking')) {
        return null;
    }

    /*
     | Return the first unfinished previous stage in the same order shown on the page.
     | Earlier logic checked only one immediate previous row. If workflow sort orders were
     | duplicate or mismatched, a later stage could still open while an earlier stage was
     | in_progress/pending. This checks every previous row before the current tracking row.
     */
    try {
        $join = jcvTableExists($conn, 'workflow_steps')
            ? 'LEFT JOIN workflow_steps ws ON ws.id = jt.workflow_step_id'
            : '';

        $select = jcvTableExists($conn, 'workflow_steps')
            ? 'ws.step_name, ws.sort_order'
            : 'NULL AS step_name, jt.id AS sort_order';

        $sort = jcvTableExists($conn, 'workflow_steps')
            ? 'ORDER BY COALESCE(ws.sort_order, jt.id) ASC, jt.id ASC'
            : 'ORDER BY jt.id ASC';

        $stmt = $conn->prepare("
            SELECT
                jt.id,
                jt.status,
                jt.workflow_step_id,
                {$select}
            FROM job_tracking jt
            {$join}
            WHERE jt.job_card_id = ?
            {$sort}
        ");
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $res = $stmt->get_result();

        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }

        $stmt->close();

        $currentIndex = null;
        foreach ($rows as $index => $row) {
            if ((int)($row['id'] ?? 0) === $trackingId) {
                $currentIndex = $index;
                break;
            }
        }

        if ($currentIndex === null || $currentIndex === 0) {
            return null;
        }

        for ($i = 0; $i < $currentIndex; $i++) {
            $status = strtolower(trim((string)($rows[$i]['status'] ?? 'pending')));
            if (!in_array($status, ['completed', 'skipped'], true)) {
                return $rows[$i];
            }
        }

        return null;
    } catch (Throwable $e) {
        return [
            'id' => 0,
            'status' => 'pending',
            'step_name' => 'Previous stage'
        ];
    }
}

function jcvPreviousStepAllowsUpdate(?array $previousStep): bool
{
    // jcvPreviousStepStatus now returns only a blocking unfinished previous stage.
    return !$previousStep;
}

function jcvIsInternalMasterCopyStage(array $step): bool
{
    $stepKey = strtolower(trim((string)($step['step_key'] ?? '')));
    $stepName = strtolower(trim((string)($step['step_name'] ?? '')));

    return in_array($stepKey, ['master_copy', 'master_copy_received'], true)
        || strpos($stepKey, 'master_copy') !== false
        || strpos($stepName, 'master copy') !== false;
}

function jcvIsApprovalStage(array $step): bool
{
    if (jcvIsInternalMasterCopyStage($step)) {
        return false;
    }

    $stepKey = strtolower(trim((string)($step['step_key'] ?? '')));
    return (int)($step['is_approval_step'] ?? 0) === 1
        || in_array($stepKey, ['proofing_approval', 'design_approval'], true);
}

function jcvApprovalTypeForStep(array $step): string
{
    $stepKey = strtolower(trim((string)($step['step_key'] ?? '')));

    if ($stepKey === 'proofing_approval') {
        return 'proof_approval';
    }

    if ($stepKey === 'design_approval') {
        return 'design_approval';
    }

    return 'confirmation';
}

function jcvApprovalIsDone(?array $approval): bool
{
    if (!$approval) {
        return false;
    }

    return strtolower((string)($approval['status'] ?? '')) === 'approved'
        || (int)($approval['approved_by_customer'] ?? 0) === 1
        || (int)($approval['approved_by_call'] ?? 0) === 1;
}

function jcvRandomToken(): string
{
    try {
        return bin2hex(random_bytes(24));
    } catch (Throwable $e) {
        return sha1(uniqid('approval_', true) . mt_rand());
    }
}

function jcvGetCustomerApproval(mysqli $conn, int $jobId, int $workflowStepId, string $approvalType): ?array
{
    if (!jcvTableExists($conn, 'customer_approvals')) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT *
        FROM customer_approvals
        WHERE job_card_id = ?
          AND workflow_step_id = ?
          AND approval_type = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param('iis', $jobId, $workflowStepId, $approvalType);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function jcvSaveManualCustomerApproval(
    mysqli $conn,
    int $jobId,
    int $workflowStepId,
    string $approvalType,
    string $customerName,
    string $mobile,
    int $userId,
    string $remarks
): void {
    if (!jcvTableExists($conn, 'customer_approvals')) {
        throw new RuntimeException('customer_approvals table is missing.');
    }

    $existing = jcvGetCustomerApproval($conn, $jobId, $workflowStepId, $approvalType);

    if ($existing) {
        $approvalId = (int)$existing['id'];
        $stmt = $conn->prepare("
            UPDATE customer_approvals
            SET
                status = 'approved',
                approved_by_call = 1,
                call_confirmed_by = ?,
                internal_remarks = ?,
                approved_at = COALESCE(approved_at, UTC_TIMESTAMP()),
                updated_at = UTC_TIMESTAMP()
            WHERE id = ?
        ");
        $stmt->bind_param('isi', $userId, $remarks, $approvalId);
        $stmt->execute();
        $stmt->close();
        return;
    }

    $token = jcvRandomToken();
    $stmt = $conn->prepare("
        INSERT INTO customer_approvals
        (
            job_card_id,
            workflow_step_id,
            approval_type,
            approval_token,
            customer_name,
            mobile,
            status,
            approved_by_customer,
            approved_by_call,
            call_confirmed_by,
            internal_remarks,
            approved_at,
            created_at
        )
        VALUES
        (?, ?, ?, ?, ?, ?, 'approved', 0, 1, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
    ");
    $stmt->bind_param('iissssis', $jobId, $workflowStepId, $approvalType, $token, $customerName, $mobile, $userId, $remarks);
    $stmt->execute();
    $stmt->close();
}

function jcvIsDesignProofingStage(array $step, string $stepRoleKey = ''): bool
{
    $roleKey = strtolower(trim($stepRoleKey));
    $defaultRoleKey = strtolower(trim((string)($step['default_owner_role_key'] ?? '')));
    $responsibleRoleKey = strtolower(trim((string)($step['responsible_role_key'] ?? '')));
    $stepKey = strtolower(trim((string)($step['step_key'] ?? '')));
    $stepName = strtolower(trim((string)($step['step_name'] ?? '')));

    /*
     * Design Received is an internal production handover stage.
     * It should NOT ask for proof/design photo upload and should NOT send
     * customer approval link. It can still send the normal tracking link.
     */
    if (
        in_array($stepKey, ['design_received', 'design_recieved', 'designing_received', 'designing_recieved'], true) ||
        strpos($stepKey, 'received') !== false ||
        strpos($stepKey, 'recieved') !== false ||
        strpos($stepName, 'received') !== false ||
        strpos($stepName, 'recieved') !== false
    ) {
        return false;
    }

    /* Approval stages are handled separately. */
    if (jcvIsApprovalStage($step)) {
        return false;
    }

    $designRoles = ['designing_proofing', 'design_proofing', 'designing', 'proofing', 'designer'];

    $isDesignRole = in_array($roleKey, $designRoles, true)
        || in_array($defaultRoleKey, $designRoles, true)
        || in_array($responsibleRoleKey, $designRoles, true);

    if ($isDesignRole) {
        return true;
    }

    /* Only exact production stages should trigger proof/design photo approval. */
    return strpos($stepKey, 'proofing') !== false
        || strpos($stepKey, 'designing') !== false
        || strpos($stepName, 'proofing') !== false
        || strpos($stepName, 'designing') !== false;
}

function jcvRequiresDesignPhotoUpload(array $step, string $stepRoleKey = ''): bool
{
    // Master Copy / Master Copy Received are internal production stages.
    // No customer approval photo upload is required.
    if (jcvIsInternalMasterCopyStage($step)) {
        return false;
    }

    if (jcvIsApprovalStage($step)) {
        return false;
    }

    $stepKey = strtolower(trim((string)($step['step_key'] ?? '')));
    $stepName = strtolower(trim((string)($step['step_name'] ?? '')));

    /*
     * Design Received / Design Recieved should never ask for photos
     * and should never send approval link to customer.
     */
    if (
        in_array($stepKey, ['design_received', 'design_recieved', 'designing_received', 'designing_recieved'], true) ||
        strpos($stepKey, 'received') !== false ||
        strpos($stepKey, 'recieved') !== false ||
        strpos($stepName, 'received') !== false ||
        strpos($stepName, 'recieved') !== false
    ) {
        return false;
    }

    if (in_array($stepKey, ['proofing_approval', 'design_approval'], true)) {
        return false;
    }

    return jcvIsDesignProofingStage($step, $stepRoleKey);
}

function jcvEnsureTrackingPhotosTable(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS job_tracking_photos (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_card_id INT NOT NULL,
            job_tracking_id INT NOT NULL,
            workflow_step_id INT NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) DEFAULT NULL,
            mime_type VARCHAR(120) DEFAULT NULL,
            file_size INT DEFAULT 0,
            uploaded_by INT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_job_card_id (job_card_id),
            KEY idx_job_tracking_id (job_tracking_id),
            KEY idx_workflow_step_id (workflow_step_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function jcvHasUploadedTrackingPhotos(string $fieldName): bool
{
    if (empty($_FILES[$fieldName]) || empty($_FILES[$fieldName]['name']) || !is_array($_FILES[$fieldName]['name'])) {
        return false;
    }

    foreach ($_FILES[$fieldName]['name'] as $index => $name) {
        if (trim((string)$name) !== '' && (int)($_FILES[$fieldName]['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            return true;
        }
    }

    return false;
}

function jcvSaveTrackingPhotos(mysqli $conn, int $jobId, int $trackingId, int $workflowStepId, int $userId, string $fieldName = 'tracking_photos'): void
{
    if (!jcvHasUploadedTrackingPhotos($fieldName)) {
        return;
    }

    jcvEnsureTrackingPhotosTable($conn);

    $baseDir = __DIR__ . '/uploads/job_tracking_photos';
    if (!is_dir($baseDir) && !mkdir($baseDir, 0755, true)) {
        throw new RuntimeException('Unable to create tracking photo upload folder.');
    }

    $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'];
    $allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];

    foreach ($_FILES[$fieldName]['name'] as $index => $originalName) {
        $originalName = trim((string)$originalName);
        $error = (int)($_FILES[$fieldName]['error'][$index] ?? UPLOAD_ERR_NO_FILE);

        if ($originalName === '' && $error === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Proof/design file upload failed. Please upload a valid image or PDF file.');
        }

        $tmpPath = (string)($_FILES[$fieldName]['tmp_name'][$index] ?? '');
        $fileSize = (int)($_FILES[$fieldName]['size'][$index] ?? 0);
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new RuntimeException('Invalid uploaded proof/design file.');
        }

        if ($fileSize <= 0) {
            throw new RuntimeException('Uploaded proof/design file is empty.');
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            throw new RuntimeException('Only JPG, PNG, WEBP, GIF or PDF files are allowed.');
        }

        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string)finfo_file($finfo, $tmpPath);
                finfo_close($finfo);
            }
        }
        if ($mime !== '' && !in_array($mime, $allowedMime, true)) {
            throw new RuntimeException('Invalid proof/design file type uploaded.');
        }

        $safeName = 'tracking_' . $jobId . '_' . $trackingId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $targetPath = $baseDir . '/' . $safeName;
        $relativePath = 'uploads/job_tracking_photos/' . $safeName;

        if (!move_uploaded_file($tmpPath, $targetPath)) {
            throw new RuntimeException('Unable to save uploaded proof/design file.');
        }

        $stmt = $conn->prepare("
            INSERT INTO job_tracking_photos
                (job_card_id, job_tracking_id, workflow_step_id, file_path, original_name, mime_type, file_size, uploaded_by, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
        ");
        $stmt->bind_param('iiisssii', $jobId, $trackingId, $workflowStepId, $relativePath, $originalName, $mime, $fileSize, $userId);
        $stmt->execute();
        $stmt->close();
    }
}


function jcvWaMobile($mobile): string
{
    $mobile = preg_replace('/\D+/', '', (string)$mobile);
    if ($mobile === '') return '';
    if (strlen($mobile) === 10) return '91' . $mobile;
    return $mobile;
}

function jcvBaseUrl(mysqli $conn): string
{
    $setting = '';
    try {
        if (jcvTableExists($conn, 'system_settings')) {
            $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key IN ('site_url','base_url','app_url') AND TRIM(setting_value) <> '' ORDER BY FIELD(setting_key,'site_url','base_url','app_url') LIMIT 1");
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $setting = trim((string)($row['setting_value'] ?? ''));
        }
    } catch (Throwable $e) {}

    if ($setting !== '') return rtrim($setting, '/');

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return rtrim($scheme . '://' . $host . ($dir === '' || $dir === '/' ? '' : $dir), '/');
}

function jcvEnsurePhotoApprovalTable(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS job_tracking_photo_approvals (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_card_id INT NOT NULL,
            job_tracking_id INT NOT NULL,
            workflow_step_id INT NOT NULL,
            approval_token VARCHAR(96) NOT NULL,
            customer_name VARCHAR(150) DEFAULT NULL,
            mobile VARCHAR(30) DEFAULT NULL,
            status ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
            customer_remarks TEXT DEFAULT NULL,
            responded_at DATETIME DEFAULT NULL,
            ip_address VARCHAR(80) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            link_sent_at DATETIME DEFAULT NULL,
            link_sent_by INT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_photo_approval_token (approval_token),
            KEY idx_job_card_id (job_card_id),
            KEY idx_job_tracking_id (job_tracking_id),
            KEY idx_workflow_step_id (workflow_step_id),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function jcvGetOrCreatePhotoApproval(mysqli $conn, int $jobId, int $trackingId, int $workflowStepId, string $customerName, string $mobile, bool $forceNew = false): ?array
{
    jcvEnsurePhotoApprovalTable($conn);

    $stmt = $conn->prepare("
        SELECT *
        FROM job_tracking_photo_approvals
        WHERE job_card_id = ?
          AND job_tracking_id = ?
          AND workflow_step_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param('iii', $jobId, $trackingId, $workflowStepId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    /*
     * Normal view: return the latest link.
     * Save Completed with new proof photos: reuse only an open pending link.
     * If the latest link was already approved/rejected/expired, create a fresh pending link
     * for the newly uploaded proof copy.
     */
    if ($row && (!$forceNew || strtolower((string)($row['status'] ?? 'pending')) === 'pending')) {
        return $row;
    }

    $token = jcvRandomToken();
    $stmt = $conn->prepare("
        INSERT INTO job_tracking_photo_approvals
            (job_card_id, job_tracking_id, workflow_step_id, approval_token, customer_name, mobile, status, created_at)
        VALUES
            (?, ?, ?, ?, ?, ?, 'pending', UTC_TIMESTAMP())
    ");
    $stmt->bind_param('iiisss', $jobId, $trackingId, $workflowStepId, $token, $customerName, $mobile);
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();

    return [
        'id' => $id,
        'job_card_id' => $jobId,
        'job_tracking_id' => $trackingId,
        'workflow_step_id' => $workflowStepId,
        'approval_token' => $token,
        'customer_name' => $customerName,
        'mobile' => $mobile,
        'status' => 'pending'
    ];
}

function jcvPhotoApprovalUrl(mysqli $conn, string $token): string
{
    return jcvBaseUrl($conn) . '/customer_design_photo_approval.php?token=' . rawurlencode($token);
}

function jcvDesignPhotoWhatsappUrl(mysqli $conn, array $job, array $step, array $approval): string
{
    $mobile = jcvWaMobile($job['mobile'] ?? '');
    if ($mobile === '') return '#';

    $link = jcvPhotoApprovalUrl($conn, (string)($approval['approval_token'] ?? ''));
    $customer = trim((string)($job['customer_name'] ?? 'Customer'));
    $jobNo = trim((string)($job['job_card_no'] ?? '-'));
    $stage = trim((string)($step['step_name'] ?? 'Designing / Proofing'));

    $message = "Hi {$customer},\n\n"
        . "Subhiksha Cards has uploaded {$stage} photos for your job card {$jobNo}.\n\n"
        . "Please open the link below to view the photos and Approve or Reject.\n"
        . "{$link}\n\n"
        . "Images and PDF files are available inside the approval link. Customer can review all uploaded files before Approve / Reject.\n\n"
        . "Thank you,\nSubhiksha Cards";

    return 'https://wa.me/' . $mobile . '?text=' . rawurlencode($message);
}


function jcvHasTrackingPhotos(mysqli $conn, int $jobId, int $trackingId): bool
{
    if ($jobId <= 0 || $trackingId <= 0 || !jcvTableExists($conn, 'job_tracking_photos')) {
        return false;
    }

    try {
        $stmt = $conn->prepare("SELECT id FROM job_tracking_photos WHERE job_card_id = ? AND job_tracking_id = ? LIMIT 1");
        $stmt->bind_param('ii', $jobId, $trackingId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (bool)$row;
    } catch (Throwable $e) {
        return false;
    }
}


function jcvTrackingFileIsPdf(array $file): bool
{
    $mime = strtolower(trim((string)($file['mime_type'] ?? '')));
    $name = trim((string)($file['original_name'] ?? ''));
    $path = trim((string)($file['file_path'] ?? ''));

    if ($mime === 'application/pdf') return true;

    $candidate = $name !== '' ? $name : $path;
    return strtolower(pathinfo($candidate, PATHINFO_EXTENSION)) === 'pdf';
}


function jcvCustomerTrackingUrl(mysqli $conn, array $job): string
{
    $token = trim((string)($job['tracking_token'] ?? ''));
    if ($token === '') return '';
    return jcvBaseUrl($conn) . '/customer_tracking.php?token=' . rawurlencode($token);
}

function jcvApprovalTemplateKey(array $job, array $step): string
{
    // This is the active/approved Meta template used for both readymade
    // proof approval and customized design approval.
    return 'design_approval';
}

function jcvSendPhotoApprovalByApi(mysqli $conn, array $job, array $step, array $approval, int $sentBy = 0): array
{
    $apiFile = __DIR__ . '/includes/whatsapp-api.php';
    if (!is_file($apiFile)) {
        return ['success' => false, 'message' => 'includes/whatsapp-api.php not found.'];
    }

    require_once $apiFile;

    if (!function_exists('subhiksha_send_template_whatsapp') && !function_exists('subhiksha_send_whatsapp')) {
        return ['success' => false, 'message' => 'WhatsApp API functions are missing.'];
    }

    $mobile = (string)($job['mobile'] ?? '');
    $approvalLink = jcvPhotoApprovalUrl($conn, (string)($approval['approval_token'] ?? ''));
    $trackingLink = jcvCustomerTrackingUrl($conn, $job);
    $templateKey = jcvApprovalTemplateKey($job, $step);

    $variables = [
        'customer_name' => (string)($job['customer_name'] ?? 'Customer'),
        'job_card_no' => (string)($job['job_card_no'] ?? '-'),
        'stage_name' => (string)($step['step_name'] ?? 'Proofing'),
        'product_name' => jcvResolvedProductName($conn, $job),
        'delivery_date' => !empty($job['delivery_date']) ? date('d-m-Y', strtotime((string)$job['delivery_date'])) : '-',
        'approval_link' => $approvalLink,
        'tracking_link' => $trackingLink,
    ];

    $meta = [
        'related_module' => 'Job Tracking',
        'related_id' => (int)($step['id'] ?? 0),
        'job_card_id' => (int)($job['id'] ?? 0),
        'customer_id' => !empty($job['customer_id']) ? (int)$job['customer_id'] : null,
        'sent_by' => $sentBy ?: (int)($_SESSION['user_id'] ?? 0),
    ];

    if (function_exists('subhiksha_send_template_whatsapp')) {
        $result = subhiksha_send_template_whatsapp($conn, $templateKey, $mobile, $variables, $meta);
    } else {
        $meta['mobile'] = $mobile;
        $meta['template_key'] = $templateKey;
        $meta['variables'] = $variables;
        $result = subhiksha_send_whatsapp($conn, $meta);
    }

    if (!empty($result['success']) && !empty($approval['id']) && jcvTableExists($conn, 'job_tracking_photo_approvals')) {
        try {
            $approvalId = (int)$approval['id'];
            $stmt = $conn->prepare("UPDATE job_tracking_photo_approvals SET link_sent_at = UTC_TIMESTAMP(), link_sent_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?");
            $stmt->bind_param('ii', $meta['sent_by'], $approvalId);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {}
    }

    $result['template_key'] = $templateKey;
    $result['approval_link'] = $approvalLink;
    $result['tracking_link'] = $trackingLink;
    return $result;
}



function jcvSendJobCompletedReviewWhatsapp(mysqli $conn, array $job, int $sentBy = 0): array
{
    $apiFile = __DIR__ . '/includes/whatsapp-api.php';
    if (!is_file($apiFile)) {
        return ['success' => false, 'message' => 'includes/whatsapp-api.php not found.'];
    }

    require_once $apiFile;

    if (!function_exists('subhiksha_send_template_whatsapp') && !function_exists('subhiksha_send_whatsapp')) {
        return ['success' => false, 'message' => 'WhatsApp API functions are missing.'];
    }

    $mobile = (string)($job['mobile'] ?? '');
    if (trim($mobile) === '') {
        return ['success' => false, 'message' => 'Customer mobile number missing.'];
    }

    $variables = [
        'customer_name' => (string)($job['customer_name'] ?? 'Customer'),
        'job_card_no' => (string)($job['job_card_no'] ?? '-'),
        'google_review_link' => 'https://g.page/r/Cbl_oKAsDotpEBE/review',
    ];

    $meta = [
        'related_module' => 'Job Card Completed Review',
        'related_id' => (int)($job['id'] ?? 0),
        'job_card_id' => (int)($job['id'] ?? 0),
        'customer_id' => !empty($job['customer_id']) ? (int)$job['customer_id'] : null,
        'sent_by' => $sentBy ?: (int)($_SESSION['user_id'] ?? 0),
    ];

    if (function_exists('subhiksha_send_template_whatsapp')) {
        return subhiksha_send_template_whatsapp(
            $conn,
            'google_review_link',
            $mobile,
            $variables,
            $meta
        );
    }

    $meta['mobile'] = $mobile;
    $meta['template_key'] = 'google_review_link';
    $meta['variables'] = $variables;

    return subhiksha_send_whatsapp($conn, $meta);
}

function jcvStageStatusLabel(string $status): string
{
    $status = strtolower(trim($status));
    return ucwords(str_replace('_', ' ', $status !== '' ? $status : 'pending'));
}

function jcvTrackingTemplateKey(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === 'in_progress') return 'job_card_status';
    if ($status === 'completed') return 'job_stage_completed';
    if ($status === 'delayed') return 'job_stage_delayed';
    if ($status === 'cancelled') return 'job_stage_cancelled_';
    return 'job_stage_updated';
}

function jcvDelayReasonName(mysqli $conn, int $delayReasonId): string
{
    if ($delayReasonId <= 0 || !jcvTableExists($conn, 'delay_reasons')) return '';

    try {
        $stmt = $conn->prepare("SELECT reason_name FROM delay_reasons WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $delayReasonId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return trim((string)($row['reason_name'] ?? ''));
    } catch (Throwable $e) {
        return '';
    }
}

function jcvSendTrackingUpdateByApi(
    mysqli $conn,
    array $job,
    array $step,
    string $newStatus,
    string $remarks = '',
    int $delayReasonId = 0,
    int $delayDays = 0,
    int $sentBy = 0,
    string $updatedStageName = ''
): array {
    $apiFile = __DIR__ . '/includes/whatsapp-api.php';
    if (!is_file($apiFile)) {
        return ['success' => false, 'message' => 'includes/whatsapp-api.php not found.'];
    }

    require_once $apiFile;

    if (!function_exists('subhiksha_send_template_whatsapp') && !function_exists('subhiksha_send_whatsapp')) {
        return ['success' => false, 'message' => 'WhatsApp API functions are missing.'];
    }

    $mobile = (string)($job['mobile'] ?? '');
    $trackingLink = jcvCustomerTrackingUrl($conn, $job);
    if (trim($trackingLink) === '') {
        return ['success' => false, 'message' => 'Tracking token is missing for this job card.'];
    }

    $statusLabel = jcvStageStatusLabel($newStatus);
    $delayReason = jcvDelayReasonName($conn, $delayReasonId);
    $templateKey = jcvTrackingTemplateKey($newStatus);

    $variables = [
        'customer_name' => (string)($job['customer_name'] ?? 'Customer'),
        'job_card_no' => (string)($job['job_card_no'] ?? '-'),
        // Always send the stage whose tracking row was updated. Do not use
        // job_cards.current_workflow_step_id here because it may already point
        // to the next open stage after a completion update.
        'stage_name' => trim($updatedStageName) !== ''
            ? trim($updatedStageName)
            : (string)($step['step_name'] ?? 'Job Stage'),
        'status_name' => $statusLabel,
        'product_name' => jcvResolvedProductName($conn, $job),
        'delivery_date' => !empty($job['delivery_date']) ? date('d-m-Y', strtotime((string)$job['delivery_date'])) : '-',
        'remarks' => trim($remarks) !== '' ? trim($remarks) : '-',
        'delay_reason' => $delayReason !== '' ? $delayReason : '-',
        'delay_days' => (string)$delayDays,
        'tracking_link' => $trackingLink,
    ];

    $meta = [
        'related_module' => 'Job Tracking Status',
        'related_id' => (int)($step['id'] ?? 0),
        'job_card_id' => (int)($job['id'] ?? 0),
        'customer_id' => !empty($job['customer_id']) ? (int)$job['customer_id'] : null,
        'sent_by' => $sentBy ?: (int)($_SESSION['user_id'] ?? 0),
    ];

    if (function_exists('subhiksha_send_template_whatsapp')) {
        $result = subhiksha_send_template_whatsapp($conn, $templateKey, $mobile, $variables, $meta);
    } else {
        $message = "Hi {$variables['customer_name']},\n\n"
            . "Your job card status has been updated.\n\n"
            . "Job Card No: {$variables['job_card_no']}\n"
            . "Current Stage: {$variables['stage_name']}\n"
            . "Status: {$variables['status_name']}\n";

        if ($newStatus === 'delayed') {
            $message .= "Delay Reason: {$variables['delay_reason']}\n"
                . "Delay Days: {$variables['delay_days']}\n";
        }

        if ($variables['remarks'] !== '-') {
            $message .= "Remarks: {$variables['remarks']}\n";
        }

        $message .= "\nTrack your order here:\n{$variables['tracking_link']}\n\n"
            . "Thank you,\nSubhiksha Cards";

        $meta['mobile'] = $mobile;
        $meta['message'] = $message;
        $meta['message_body'] = $message;
        $meta['template_key'] = $templateKey;
        $meta['variables'] = $variables;
        $result = subhiksha_send_whatsapp($conn, $meta);
    }

    $result['template_key'] = $templateKey;
    $result['tracking_link'] = $trackingLink;
    return $result;
}

function jcvGetTrackingPhotos(mysqli $conn, int $jobId): array
{
    $photos = [];
    if ($jobId <= 0 || !jcvTableExists($conn, 'job_tracking_photos')) return $photos;

    try {
        $stmt = $conn->prepare("
            SELECT *
            FROM job_tracking_photos
            WHERE job_card_id = ?
            ORDER BY id ASC
        ");
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $tid = (int)($row['job_tracking_id'] ?? 0);
            if (!isset($photos[$tid])) $photos[$tid] = [];
            $photos[$tid][] = $row;
        }
        $stmt->close();
    } catch (Throwable $e) {}

    return $photos;
}

function jcvApprovalTypeFromStepKey(string $stepKey): string
{
    $stepKey = strtolower(trim($stepKey));
    if ($stepKey === 'proofing_approval') return 'proof_approval';
    if ($stepKey === 'design_approval') return 'design_approval';
    return 'confirmation';
}

function jcvFindApprovalWorkflowStepForPhoto(mysqli $conn, array $job, array $sourceStep): ?array
{
    if (!jcvTableExists($conn, 'workflow_steps') || !jcvTableExists($conn, 'job_tracking')) {
        return null;
    }

    $jobId = (int)($job['id'] ?? 0);
    if ($jobId <= 0) return null;

    $orderType = strtolower(trim((string)($job['order_type'] ?? ($sourceStep['order_type'] ?? ''))));
    $sourceStepId = (int)($sourceStep['workflow_step_id'] ?? 0);
    $sourceSort = (int)($sourceStep['sort_order'] ?? 0);
    $preferredStepKey = $orderType === 'readymade' ? 'proofing_approval' : 'design_approval';

    try {
        /* Use the approval row that actually exists in this Job Card. */
        $stmt = $conn->prepare("
            SELECT
                jt.workflow_step_id AS id,
                ws.step_key,
                ws.step_name,
                ws.sort_order
            FROM job_tracking jt
            INNER JOIN workflow_steps ws ON ws.id = jt.workflow_step_id
            WHERE jt.job_card_id = ?
              AND ws.step_key = ?
            ORDER BY jt.id ASC
            LIMIT 1
        ");
        $stmt->bind_param('is', $jobId, $preferredStepKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return $row;

        $stmt = $conn->prepare("
            SELECT
                jt.workflow_step_id AS id,
                ws.step_key,
                ws.step_name,
                ws.sort_order
            FROM job_tracking jt
            INNER JOIN workflow_steps ws ON ws.id = jt.workflow_step_id
            WHERE jt.job_card_id = ?
              AND jt.workflow_step_id <> ?
              AND (
                    COALESCE(ws.is_approval_step, 0) = 1
                    OR ws.step_key IN ('proofing_approval','design_approval')
                    OR LOWER(COALESCE(ws.step_name, '')) LIKE '%approval%'
                  )
              AND (? <= 0 OR ws.sort_order >= ?)
            ORDER BY ws.sort_order ASC, jt.id ASC
            LIMIT 1
        ");
        $stmt->bind_param('iiii', $jobId, $sourceStepId, $sourceSort, $sourceSort);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    } catch (Throwable $e) {
        error_log('jcvFindApprovalWorkflowStepForPhoto: ' . $e->getMessage());
        return null;
    }
}


/**
 * Repair / reconcile approved customer proof/design responses with the
 * dedicated Proofing Approval / Design Approval workflow stage.
 *
 * This also repairs older records where the public photo approval was saved
 * as Approved but a later sync error left the approval stage Pending.
 */
function jcvReconcileApprovedPhotoApprovals(mysqli $conn, array $job): void
{
    $jobId = (int)($job['id'] ?? 0);

    if (
        $jobId <= 0 ||
        !jcvTableExists($conn, 'job_tracking_photo_approvals') ||
        !jcvTableExists($conn, 'job_tracking') ||
        !jcvTableExists($conn, 'workflow_steps') ||
        !jcvTableExists($conn, 'customer_approvals')
    ) {
        return;
    }

    try {
        $stmt = $conn->prepare("
            SELECT
                pa.job_card_id,
                pa.workflow_step_id,
                pa.customer_name,
                pa.mobile,
                pa.customer_remarks,
                pa.responded_at,
                ws.step_key,
                ws.step_name,
                ws.sort_order,
                ws.order_type
            FROM job_tracking_photo_approvals pa
            LEFT JOIN workflow_steps ws
                ON ws.id = pa.workflow_step_id
            WHERE pa.job_card_id = ?
              AND LOWER(COALESCE(pa.status, '')) = 'approved'
            ORDER BY pa.id ASC
        ");
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $res = $stmt->get_result();

        $approvedRows = [];
        while ($row = $res->fetch_assoc()) {
            $approvedRows[] = $row;
        }
        $stmt->close();

        if (!$approvedRows) {
            return;
        }

        $changed = false;
        $conn->begin_transaction();

        foreach ($approvedRows as $photoApproval) {
            $sourceStep = [
                'workflow_step_id' => (int)($photoApproval['workflow_step_id'] ?? 0),
                'step_key' => (string)($photoApproval['step_key'] ?? ''),
                'step_name' => (string)($photoApproval['step_name'] ?? ''),
                'sort_order' => (int)($photoApproval['sort_order'] ?? 0),
                'order_type' => (string)($photoApproval['order_type'] ?? ($job['order_type'] ?? ''))
            ];

            $target = jcvFindApprovalWorkflowStepForPhoto($conn, $job, $sourceStep);
            if (!$target) {
                continue;
            }

            $approvalWorkflowStepId = (int)($target['id'] ?? 0);
            if ($approvalWorkflowStepId <= 0) {
                continue;
            }

            $approvalType = jcvApprovalTypeFromStepKey((string)($target['step_key'] ?? ''));
            $customerName = trim((string)($photoApproval['customer_name'] ?? ''));
            if ($customerName === '') {
                $customerName = trim((string)($job['customer_name'] ?? ''));
            }

            $mobile = trim((string)($photoApproval['mobile'] ?? ''));
            if ($mobile === '') {
                $mobile = trim((string)($job['mobile'] ?? ''));
            }

            $customerRemarks = trim((string)($photoApproval['customer_remarks'] ?? ''));

            $stmt = $conn->prepare("
                SELECT id, status, approved_by_customer, approved_by_call
                FROM customer_approvals
                WHERE job_card_id = ?
                  AND workflow_step_id = ?
                  AND approval_type = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->bind_param('iis', $jobId, $approvalWorkflowStepId, $approvalType);
            $stmt->execute();
            $existingApproval = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existingApproval) {
                $approvalId = (int)$existingApproval['id'];
                $stmt = $conn->prepare("
                    UPDATE customer_approvals
                    SET status = 'approved',
                        customer_name = ?,
                        mobile = ?,
                        approved_by_customer = 1,
                        customer_remarks = CASE
                            WHEN ? <> '' THEN ?
                            ELSE customer_remarks
                        END,
                        approved_at = COALESCE(approved_at, UTC_TIMESTAMP()),
                        rejected_at = NULL,
                        updated_at = UTC_TIMESTAMP()
                    WHERE id = ?
                ");
                $stmt->bind_param('ssssi', $customerName, $mobile, $customerRemarks, $customerRemarks, $approvalId);
                $stmt->execute();
                if ($stmt->affected_rows > 0) {
                    $changed = true;
                }
                $stmt->close();
            } else {
                $approvalToken = jcvRandomToken();
                $stmt = $conn->prepare("
                    INSERT INTO customer_approvals
                    (
                        job_card_id,
                        workflow_step_id,
                        approval_type,
                        approval_token,
                        customer_name,
                        mobile,
                        status,
                        approved_by_customer,
                        approved_by_call,
                        customer_remarks,
                        approved_at,
                        created_at,
                        updated_at
                    )
                    VALUES
                    (?, ?, ?, ?, ?, ?, 'approved', 1, 0, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())
                ");
                $stmt->bind_param(
                    'iisssss',
                    $jobId,
                    $approvalWorkflowStepId,
                    $approvalType,
                    $approvalToken,
                    $customerName,
                    $mobile,
                    $customerRemarks
                );
                $stmt->execute();
                $stmt->close();
                $changed = true;
            }

            $trackingRemark = $customerRemarks !== ''
                ? $customerRemarks
                : 'Customer approved uploaded design/proofing photos. Approval stage completed automatically.';

            $stmt = $conn->prepare("
                UPDATE job_tracking
                SET status = 'completed',
                    remarks = CASE
                        WHEN TRIM(COALESCE(remarks, '')) = '' THEN ?
                        ELSE remarks
                    END,
                    actual_start_at = COALESCE(actual_start_at, UTC_TIMESTAMP()),
                    actual_completed_at = COALESCE(actual_completed_at, UTC_TIMESTAMP()),
                    updated_at = UTC_TIMESTAMP()
                WHERE job_card_id = ?
                  AND workflow_step_id = ?
                  AND status NOT IN ('completed','skipped','cancelled')
                LIMIT 1
            ");
            $stmt->bind_param('sii', $trackingRemark, $jobId, $approvalWorkflowStepId);
            $stmt->execute();
            if ($stmt->affected_rows > 0) {
                $changed = true;
            }
            $stmt->close();
        }

        if ($changed) {
            jcvRefreshJobCardProgressAfterPhotoCancel($conn, $jobId, 0);
        }

        $conn->commit();
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            // Ignore rollback failure.
        }
        error_log('jcvReconcileApprovedPhotoApprovals: ' . $e->getMessage());
    }
}

function jcvRefreshJobCardProgressAfterPhotoCancel(mysqli $conn, int $jobId, int $userId = 0): void
{
    if ($jobId <= 0 || !jcvTableExists($conn, 'job_tracking') || !jcvTableExists($conn, 'job_cards')) return;

    $summary = [
        'total_steps' => 0,
        'completed_steps' => 0,
        'open_steps' => 0,
        'progress_steps' => 0,
        'delayed_steps' => 0,
        'delay_history_steps' => 0
    ];

    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS total_steps,
            SUM(CASE WHEN status IN ('completed','skipped') THEN 1 ELSE 0 END) AS completed_steps,
            SUM(CASE WHEN status NOT IN ('completed','skipped','cancelled') THEN 1 ELSE 0 END) AS open_steps,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS progress_steps,
            SUM(CASE WHEN status = 'delayed' THEN 1 ELSE 0 END) AS delayed_steps,
            SUM(CASE WHEN is_delayed = 1 THEN 1 ELSE 0 END) AS delay_history_steps
        FROM job_tracking
        WHERE job_card_id = ?
    ");
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) $summary = array_merge($summary, $row);

    $currentWorkflowStepId = null;
    $stmt = $conn->prepare("
        SELECT jt.workflow_step_id
        FROM job_tracking jt
        LEFT JOIN workflow_steps ws ON ws.id = jt.workflow_step_id
        WHERE jt.job_card_id = ?
          AND jt.status NOT IN ('completed','skipped','cancelled')
        ORDER BY ws.sort_order ASC, jt.id ASC
        LIMIT 1
    ");
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $currentRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($currentRow) $currentWorkflowStepId = (int)$currentRow['workflow_step_id'];

    $jobStatusKey = 'pending';
    if ((int)($summary['delayed_steps'] ?? 0) > 0) {
        $jobStatusKey = 'delayed';
    } elseif ((int)($summary['open_steps'] ?? 0) === 0 && (int)($summary['total_steps'] ?? 0) > 0) {
        $jobStatusKey = 'completed';
    } elseif ((int)($summary['progress_steps'] ?? 0) > 0) {
        $jobStatusKey = 'in_progress';
    }

    $jobStatusId = null;
    if (jcvTableExists($conn, 'job_card_statuses')) {
        $stmt = $conn->prepare("SELECT id FROM job_card_statuses WHERE status_key = ? LIMIT 1");
        $stmt->bind_param('s', $jobStatusKey);
        $stmt->execute();
        $statusRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($statusRow) $jobStatusId = (int)$statusRow['id'];
    }

    $isDelayed = ((int)($summary['delay_history_steps'] ?? 0) > 0 || $jobStatusKey === 'delayed') ? 1 : 0;

    if ($jobStatusId && $currentWorkflowStepId) {
        $stmt = $conn->prepare("
            UPDATE job_cards
            SET current_workflow_step_id = ?,
                job_card_status_id = ?,
                is_delayed = ?,
                completed_at = CASE WHEN ? = 'completed' THEN COALESCE(completed_at, UTC_TIMESTAMP()) ELSE NULL END,
                updated_by = CASE WHEN ? > 0 THEN ? ELSE updated_by END,
                updated_at = UTC_TIMESTAMP()
            WHERE id = ?
        ");
        $stmt->bind_param('iiisiii', $currentWorkflowStepId, $jobStatusId, $isDelayed, $jobStatusKey, $userId, $userId, $jobId);
        $stmt->execute();
        $stmt->close();
    } elseif ($jobStatusId) {
        $stmt = $conn->prepare("
            UPDATE job_cards
            SET job_card_status_id = ?,
                is_delayed = ?,
                completed_at = CASE WHEN ? = 'completed' THEN COALESCE(completed_at, UTC_TIMESTAMP()) ELSE NULL END,
                updated_by = CASE WHEN ? > 0 THEN ? ELSE updated_by END,
                updated_at = UTC_TIMESTAMP()
            WHERE id = ?
        ");
        $stmt->bind_param('iisiii', $jobStatusId, $isDelayed, $jobStatusKey, $userId, $userId, $jobId);
        $stmt->execute();
        $stmt->close();
    }
}

function jcvCancelUploadedTrackingPhoto(mysqli $conn, array $job, int $trackingId, int $photoId, int $userId): string
{
    $jobId = (int)($job['id'] ?? 0);
    if ($jobId <= 0 || $trackingId <= 0 || $photoId <= 0) {
        throw new RuntimeException('Invalid photo cancel request.');
    }
    if (!jcvTableExists($conn, 'job_tracking_photos')) {
        throw new RuntimeException('Tracking photo table is missing.');
    }

    $stmt = $conn->prepare("
        SELECT
            p.*,
            jt.status AS tracking_status,
            jt.workflow_step_id,
            ws.step_key,
            ws.step_name,
            ws.sort_order,
            ws.order_type AS step_order_type
        FROM job_tracking_photos p
        LEFT JOIN job_tracking jt ON jt.id = p.job_tracking_id
        LEFT JOIN workflow_steps ws ON ws.id = jt.workflow_step_id
        WHERE p.id = ?
          AND p.job_card_id = ?
          AND p.job_tracking_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('iii', $photoId, $jobId, $trackingId);
    $stmt->execute();
    $photo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$photo) {
        throw new RuntimeException('Uploaded photo not found.');
    }

    $sourceStep = [
        'workflow_step_id' => (int)($photo['workflow_step_id'] ?? 0),
        'step_key' => (string)($photo['step_key'] ?? ''),
        'step_name' => (string)($photo['step_name'] ?? ''),
        'sort_order' => (int)($photo['sort_order'] ?? 0),
        'order_type' => (string)($photo['step_order_type'] ?? ($job['order_type'] ?? ''))
    ];

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("DELETE FROM job_tracking_photos WHERE id = ? AND job_card_id = ? AND job_tracking_id = ? LIMIT 1");
        $stmt->bind_param('iii', $photoId, $jobId, $trackingId);
        $stmt->execute();
        $stmt->close();

        $relativePath = trim((string)($photo['file_path'] ?? ''));
        if ($relativePath !== '' && strpos($relativePath, 'uploads/job_tracking_photos/') === 0) {
            $fullPath = __DIR__ . '/' . $relativePath;
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }

        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM job_tracking_photos WHERE job_card_id = ? AND job_tracking_id = ?");
        $stmt->bind_param('ii', $jobId, $trackingId);
        $stmt->execute();
        $countRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $remaining = (int)($countRow['cnt'] ?? 0);

        if ($remaining === 0) {
            if (jcvTableExists($conn, 'job_tracking_photo_approvals')) {
                $cancelRemark = 'Proof/design photos cancelled by staff. Fresh upload is required.';
                $workflowStepId = (int)$sourceStep['workflow_step_id'];
                $stmt = $conn->prepare("
                    UPDATE job_tracking_photo_approvals
                    SET status = 'expired',
                        customer_remarks = CASE WHEN COALESCE(customer_remarks, '') = '' THEN ? ELSE customer_remarks END,
                        updated_at = UTC_TIMESTAMP()
                    WHERE job_card_id = ?
                      AND job_tracking_id = ?
                      AND workflow_step_id = ?
                      AND status IN ('pending','approved','rejected')
                ");
                $stmt->bind_param('siii', $cancelRemark, $jobId, $trackingId, $workflowStepId);
                $stmt->execute();
                $stmt->close();
            }

            $approvalStep = jcvFindApprovalWorkflowStepForPhoto($conn, $job, $sourceStep);
            if ($approvalStep) {
                $approvalWorkflowStepId = (int)($approvalStep['id'] ?? 0);
                $approvalType = jcvApprovalTypeFromStepKey((string)($approvalStep['step_key'] ?? ''));

                if ($approvalWorkflowStepId > 0 && jcvTableExists($conn, 'customer_approvals')) {
                    $internalRemark = 'Proof/design photo cancelled by staff. Fresh approval required.';
                    $stmt = $conn->prepare("
                        UPDATE customer_approvals
                        SET status = 'expired',
                            approved_by_customer = 0,
                            approved_by_call = 0,
                            approved_at = NULL,
                            rejected_at = NULL,
                            internal_remarks = CASE WHEN COALESCE(internal_remarks, '') = '' THEN ? ELSE CONCAT(internal_remarks, '\n', ?) END,
                            updated_at = UTC_TIMESTAMP()
                        WHERE job_card_id = ?
                          AND workflow_step_id = ?
                          AND approval_type = ?
                        ORDER BY id DESC
                        LIMIT 1
                    ");
                    $stmt->bind_param('ssiis', $internalRemark, $internalRemark, $jobId, $approvalWorkflowStepId, $approvalType);
                    $stmt->execute();
                    $stmt->close();
                }

                if ($approvalWorkflowStepId > 0 && jcvTableExists($conn, 'job_tracking')) {
                    $approvalTrackingRemark = 'Approval cancelled because proof/design photo was removed. Fresh customer approval required.';
                    $stmt = $conn->prepare("
                        UPDATE job_tracking
                        SET status = 'pending',
                            remarks = ?,
                            actual_completed_at = NULL,
                            completed_by = NULL,
                            updated_at = UTC_TIMESTAMP()
                        WHERE job_card_id = ?
                          AND workflow_step_id = ?
                          AND status = 'completed'
                        LIMIT 1
                    ");
                    $stmt->bind_param('sii', $approvalTrackingRemark, $jobId, $approvalWorkflowStepId);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            $sourceRemark = 'Uploaded proof/design photo cancelled. Upload corrected photo and complete this stage again.';
            $stmt = $conn->prepare("
                UPDATE job_tracking
                SET status = 'in_progress',
                    remarks = ?,
                    actual_completed_at = NULL,
                    completed_by = NULL,
                    updated_at = UTC_TIMESTAMP()
                WHERE id = ?
                  AND job_card_id = ?
                  AND status = 'completed'
                LIMIT 1
            ");
            $stmt->bind_param('sii', $sourceRemark, $trackingId, $jobId);
            $stmt->execute();
            $stmt->close();

            jcvRefreshJobCardProgressAfterPhotoCancel($conn, $jobId, $userId);
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    if ($remaining === 0) {
        return 'Uploaded photo cancelled. All proof/design photos were removed, so customer approval was expired and this stage needs a fresh upload.';
    }

    return 'Uploaded photo cancelled successfully.';
}


$roleKey = strtolower((string)($_SESSION['role_key'] ?? ''));
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

/*
 * Row-level Job Card visibility:
 * - Admin/Sales retain their existing all-job monitoring/workflow access.
 * - Designing/Proofing users can open only Job Cards assigned to themselves.
 * - Printing users can open only Job Cards assigned to themselves and belonging
 *   to their printing department.
 *
 * This is an access layer only; workflow/status/payment/WhatsApp logic below
 * remains unchanged.
 */
$allAccessRoles = [
    'admin',
    'super_admin',
    'business_admin',
    'sales'
];

$designRoleKeys = [
    'designing_proofing',
    'design_proofing',
    'designing',
    'proofing',
    'designer',
    'designing_team'
];

$printingRoleKeys = [
    'offset_printing',
    'screen_printing',
    'digital_printing',
    'multicolor_offset_printing'
];

$hasAllJobCardAccess = in_array($roleKey, $allAccessRoles, true);
$isDesignRole = in_array($roleKey, $designRoleKeys, true);
$isSpecificPrintingRole = in_array($roleKey, $printingRoleKeys, true);
$isGeneralPrintingRole = $roleKey === 'printing';
$isAdminMonitor = in_array($roleKey, ['admin', 'super_admin', 'business_admin'], true);
$isPrintingJobView = $isSpecificPrintingRole || $isGeneralPrintingRole;
$isProductionTeamView = $isDesignRole || $isPrintingJobView;

$canUpdateJob = false;
$manualApprovalRoleKeys = [
    'admin',
    'sales',
    'designing_proofing',
    'design_proofing',
    'designing',
    'proofing'
];
$canManualCustomerApproval = in_array($roleKey, $manualApprovalRoleKeys, true);

try {
    $canUpdateJob = is_admin_user() || can_update($conn, 'job_cards.php');
} catch (Throwable $e) {
    $canUpdateJob = is_admin_user();
}

$jobId = (int)($_GET['id'] ?? 0);
$message = '';
$messageType = 'danger';
$toastTitle = 'Info';
$job = null;
$jobItems = [];
$trackingRows = [];
$trackingPhotosById = [];
$delayReasons = [];

if (($_GET['msg'] ?? '') === 'status_updated') {
    $waStatus = strtolower(trim((string)($_GET['wa'] ?? '')));
    $message = 'Job status updated successfully.';
    $messageType = 'success';
    $toastTitle = 'Success';

    if ($waStatus === 'approval_sent') {
        $message .= ' Approval WhatsApp link sent to customer with tracking link.';
    } elseif ($waStatus === 'approval_failed') {
        $message .= ' But WhatsApp approval link failed. Use the API Send WhatsApp Approval Link button again.';
        $messageType = 'warning';
        $toastTitle = 'WhatsApp Failed';
    } elseif ($waStatus === 'tracking_sent') {
        $message .= ' Customer tracking WhatsApp sent.';
    } elseif ($waStatus === 'tracking_failed') {
        $message .= ' But customer tracking WhatsApp failed. Status is saved.';
        $messageType = 'warning';
        $toastTitle = 'WhatsApp Failed';
    }
}

if (($_GET['msg'] ?? '') === 'approval_whatsapp_sent') {
    $message = 'WhatsApp approval link sent to customer through API.';
    $messageType = 'success';
    $toastTitle = 'WhatsApp Sent';
}

if (($_GET['msg'] ?? '') === 'approval_whatsapp_failed') {
    $message = 'WhatsApp approval link failed. Please check WhatsApp API settings/logs and try again.';
    $messageType = 'warning';
    $toastTitle = 'WhatsApp Failed';
}

if (($_GET['msg'] ?? '') === 'photo_cancelled') {
    $message = 'Uploaded proof/design photo cancelled successfully. If all photos were cancelled, customer approval was expired and the stage was moved back for fresh proof upload.';
    $messageType = 'success';
    $toastTitle = 'Photo Cancelled';
}

try {
    if (jcvTableExists($conn, 'delay_reasons')) {
        $res = $conn->query("
            SELECT id, reason_name
            FROM delay_reasons
            WHERE is_active = 1
            ORDER BY id ASC, reason_name ASC
        ");

        while ($row = $res->fetch_assoc()) {
            $delayReasons[] = $row;
        }

        $res->free();
    }
} catch (Throwable $e) {
    $delayReasons = [];
}

if ($jobId <= 0) {
    $message = 'Invalid job card.';
    $messageType = 'danger';
} elseif (!jcvTableExists($conn, 'job_cards')) {
    $message = 'job_cards table is missing.';
    $messageType = 'danger';
} else {
    try {
        $where = ['jc.id = ?'];
        $params = [$jobId];
        $types = 'i';

        if (!$hasAllJobCardAccess) {
            if ($isDesignRole) {
                /* A designer can open only the Job Card allocated to that user. */
                if ($currentUserId > 0) {
                    $where[] = "jc.assigned_design_user_id = ?";
                    $params[] = $currentUserId;
                    $types .= 'i';
                } else {
                    $where[] = "1 = 0";
                }
            } elseif ($isSpecificPrintingRole) {
                /* Keep department ownership and add person-level ownership. */
                $where[] = "(pt.role_key = ? OR rprint.role_key = ?)";
                $params[] = $roleKey;
                $params[] = $roleKey;
                $types .= 'ss';

                if ($currentUserId > 0) {
                    $where[] = "jc.assigned_printing_user_id = ?";
                    $params[] = $currentUserId;
                    $types .= 'i';
                } else {
                    $where[] = "1 = 0";
                }
            } elseif ($isGeneralPrintingRole) {
                $where[] = "(
                    pt.role_key IN ('offset_printing','screen_printing','digital_printing','multicolor_offset_printing')
                    OR rprint.role_key IN ('offset_printing','screen_printing','digital_printing','multicolor_offset_printing')
                )";

                if ($currentUserId > 0) {
                    $where[] = "jc.assigned_printing_user_id = ?";
                    $params[] = $currentUserId;
                    $types .= 'i';
                } else {
                    $where[] = "1 = 0";
                }
            } else {
                $where[] = "1 = 0";
            }
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $sql = "
            SELECT
                jc.*,

                ft.function_name,

                pt.printing_name,
                pt.printing_key,
                pt.role_key AS printing_role_key,

                pst.sub_type_name,

                jcs.status_name,
                jcs.status_key,
                jcs.color_code,

                ws.step_name AS current_step_name,
                ws.step_key AS current_step_key,

                sales.username AS sales_person,
                designer.username AS designer_name,
                printer.username AS printer_name,

                rprint.role_name AS assigned_printing_role_name,
                rprint.role_key AS assigned_printing_role_key,

                creator.username AS created_by_name,

                COALESCE(track.total_steps, 0) AS total_steps,
                COALESCE(track.completed_steps, 0) AS completed_steps,
                COALESCE(track.delayed_steps, 0) AS delayed_steps

            FROM job_cards jc

            LEFT JOIN function_types ft
                ON ft.id = jc.function_type_id

            LEFT JOIN printing_types pt
                ON pt.id = jc.printing_type_id

            LEFT JOIN printing_sub_types pst
                ON pst.id = jc.printing_sub_type_id

            LEFT JOIN job_card_statuses jcs
                ON jcs.id = jc.job_card_status_id

            LEFT JOIN workflow_steps ws
                ON ws.id = jc.current_workflow_step_id

            LEFT JOIN users sales
                ON sales.id = jc.assigned_sales_user_id

            LEFT JOIN users designer
                ON designer.id = jc.assigned_design_user_id

            LEFT JOIN users printer
                ON printer.id = jc.assigned_printing_user_id

            LEFT JOIN roles rprint
                ON rprint.id = jc.assigned_printing_role_id

            LEFT JOIN users creator
                ON creator.id = jc.created_by

            LEFT JOIN (
                SELECT
                    job_card_id,
                    COUNT(*) AS total_steps,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_steps,
                    SUM(CASE WHEN status = 'delayed' OR is_delayed = 1 THEN 1 ELSE 0 END) AS delayed_steps
                FROM job_tracking
                GROUP BY job_card_id
            ) track
                ON track.job_card_id = jc.id

            {$whereSql}

            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$job) {
            $message = 'Job card not found or you do not have permission to view this job.';
            $messageType = 'danger';
        }
    } catch (Throwable $e) {
        $message = 'Job card query error: ' . $e->getMessage();
        $messageType = 'danger';
        $job = null;
    }
}

/*
 * Approved customer photo responses should never leave the dedicated
 * Proofing/Design Approval stage pending. Reconcile on normal page load as a
 * safety net for records created before this fix.
 */
if ($job && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    jcvReconcileApprovedPhotoApprovals($conn, $job);
}

if ($job && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_photo_approval_api') {
    $trackingId = (int)($_POST['tracking_id'] ?? 0);
    $userId = (int)($_SESSION['user_id'] ?? 0);

    try {
        if ($trackingId <= 0) {
            throw new RuntimeException('Invalid approval WhatsApp request.');
        }

        if (!$canUpdateJob) {
            throw new RuntimeException('You do not have permission to send approval WhatsApp link.');
        }

        $stmt = $conn->prepare("
            SELECT
                jt.*,
                rr.role_key AS responsible_role_key,
                ws.default_owner_role_key,
                ws.step_key,
                ws.step_name,
                ws.is_approval_step
            FROM job_tracking jt
            LEFT JOIN roles rr
                ON rr.id = jt.responsible_role_id
            LEFT JOIN workflow_steps ws
                ON ws.id = jt.workflow_step_id
            WHERE jt.id = ?
              AND jt.job_card_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('ii', $trackingId, $jobId);
        $stmt->execute();
        $stepRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($stepRow && jcvIsInternalMasterCopyStage($stepRow)) {
            throw new RuntimeException('Master Copy is an internal stage. Customer WhatsApp approval is not allowed.');
        }

        if (!$stepRow) {
            throw new RuntimeException('Tracking stage not found.');
        }

        $stepRoleKey = $stepRow['responsible_role_key'] ?: $stepRow['default_owner_role_key'];
        if (!jcvIsDesignProofingStage($stepRow, $stepRoleKey)) {
            throw new RuntimeException('Approval WhatsApp link is available only for Designing / Proofing photo stages.');
        }

        if (!jcvHasTrackingPhotos($conn, $jobId, $trackingId)) {
            throw new RuntimeException('Please upload proof/design photos before sending approval link.');
        }

        $photoApproval = jcvGetOrCreatePhotoApproval(
            $conn,
            $jobId,
            $trackingId,
            (int)$stepRow['workflow_step_id'],
            (string)($job['customer_name'] ?? ''),
            (string)($job['mobile'] ?? '')
        );

        if (!$photoApproval) {
            throw new RuntimeException('Unable to create approval link.');
        }

        $approvalStatus = strtolower(trim((string)($photoApproval['status'] ?? 'pending')));
        if ($approvalStatus !== 'pending') {
            throw new RuntimeException('This approval link is already ' . ucwords(str_replace('_', ' ', $approvalStatus)) . '. Upload corrected proof/design photo and save as Completed to create a new approval link.');
        }

        $apiResult = jcvSendPhotoApprovalByApi($conn, $job, $stepRow, $photoApproval, $userId);
        $msg = !empty($apiResult['success']) ? 'approval_whatsapp_sent' : 'approval_whatsapp_failed';

        header('Location: job_card_view.php?id=' . $jobId . '&msg=' . $msg);
        exit;
    } catch (Throwable $e) {
        $message = 'Approval WhatsApp failed: ' . $e->getMessage();
        $messageType = 'danger';
        $toastTitle = 'WhatsApp Failed';
    }
}


if ($job && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_tracking_photo') {
    $trackingId = (int)($_POST['tracking_id'] ?? 0);
    $photoId = (int)($_POST['photo_id'] ?? 0);
    $userId = (int)($_SESSION['user_id'] ?? 0);

    try {
        if (!$canUpdateJob) {
            throw new RuntimeException('You do not have permission to cancel uploaded proof/design photos.');
        }

        jcvCancelUploadedTrackingPhoto($conn, $job, $trackingId, $photoId, $userId);
        header('Location: job_card_view.php?id=' . $jobId . '&msg=photo_cancelled');
        exit;
    } catch (Throwable $e) {
        $message = 'Photo cancel failed: ' . $e->getMessage();
        $messageType = 'danger';
        $toastTitle = 'Cancel Failed';
    }
}

if ($job && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_step_status') {
    $trackingId = (int)($_POST['tracking_id'] ?? 0);
    $newStatus = strtolower(trim((string)($_POST['status'] ?? '')));
    $remarks = trim((string)($_POST['remarks'] ?? ''));
    $delayReasonId = (int)($_POST['delay_reason_id'] ?? 0);
    $delayDays = max(0, (int)($_POST['delay_days'] ?? 0));

    $allowedStatus = [
        'pending',
        'in_progress',
        'completed',
        'delayed',
        'skipped',
        'cancelled'
    ];

    if ($trackingId <= 0 || !in_array($newStatus, $allowedStatus, true)) {
        $message = 'Invalid status update request.';
        $messageType = 'danger';
    } elseif ($newStatus === 'delayed' && $remarks === '') {
        $message = 'Delay remark is required.';
        $messageType = 'danger';
    } elseif ($newStatus === 'delayed' && $delayReasonId <= 0) {
        $message = 'Delay reason is required.';
        $messageType = 'danger';
    } else {
        try {
            $stmt = $conn->prepare("
                SELECT
                    jt.*,
                    rr.role_key AS responsible_role_key,
                    ws.default_owner_role_key,
                    ws.step_key,
                    ws.step_name,
                    ws.is_approval_step
                FROM job_tracking jt
                LEFT JOIN roles rr
                    ON rr.id = jt.responsible_role_id
                LEFT JOIN workflow_steps ws
                    ON ws.id = jt.workflow_step_id
                WHERE jt.id = ?
                  AND jt.job_card_id = ?
                LIMIT 1
            ");
            $stmt->bind_param('ii', $trackingId, $jobId);
            $stmt->execute();
            $stepRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$stepRow) {
                $message = 'Tracking stage not found.';
                $messageType = 'danger';
            } else {
                // Snapshot the submitted tracking row's stage before any
                // auto-advance/current-workflow recalculation takes place.
                $updatedStageName = trim((string)($stepRow['step_name'] ?? ''));
                if ($updatedStageName === '') {
                    $updatedStageName = 'Job Stage';
                }

                $stepRoleKey = $stepRow['responsible_role_key'] ?: $stepRow['default_owner_role_key'];
                $oldStepStatus = strtolower((string)($stepRow['status'] ?? 'pending'));

                $canUpdateThisStep = jcvCanUpdateStep(
                    $roleKey,
                    $stepRoleKey,
                    $job['assigned_printing_role_key'] ?? '',
                    $job['printing_role_key'] ?? '',
                    $canUpdateJob,
                    $oldStepStatus
                );

                $previousStep = jcvPreviousStepStatus($conn, $jobId, $trackingId);
                $previousStepAllowed = jcvPreviousStepAllowsUpdate($previousStep);

                if (!$previousStepAllowed) {
                    $previousStepName = trim((string)($previousStep['step_name'] ?? 'Previous stage'));
                    $message = $previousStepName . ' must be completed before updating this stage.';
                    $messageType = 'danger';
                    $toastTitle = 'Locked';
                } elseif (!$canUpdateThisStep) {
                    $message = 'You do not have permission to update this stage.';
                    $messageType = 'danger';
                    $toastTitle = 'Permission Denied';
                } else {
                    $userId = (int)($_SESSION['user_id'] ?? 0);

                    $isApprovalStage = jcvIsApprovalStage($stepRow);
                    $isDesignProofingStage = jcvIsDesignProofingStage($stepRow, $stepRoleKey);
                    $requiresDesignPhotoUpload = jcvRequiresDesignPhotoUpload($stepRow, $stepRoleKey);

                    $uploadedProofPhotos = jcvHasUploadedTrackingPhotos('tracking_photos');
                    $existingProofPhotos = jcvHasTrackingPhotos($conn, $jobId, $trackingId);

                    if ($requiresDesignPhotoUpload && $newStatus === 'completed' && !$uploadedProofPhotos && !$existingProofPhotos) {
                        throw new RuntimeException('Please upload a proof/design image or PDF before completing this stage. In Progress / Pending / Delayed status does not require file upload.');
                    }

                    jcvAssertDispatchPaymentAllowed($conn, $job, $stepRow, $newStatus);

                    if ($newStatus === 'completed' && $isApprovalStage) {
                        $approvalType = jcvApprovalTypeForStep($stepRow);
                        $approval = jcvGetCustomerApproval($conn, $jobId, (int)$stepRow['workflow_step_id'], $approvalType);

                        if (!jcvApprovalIsDone($approval)) {
                            $manualConfirm = isset($_POST['manual_customer_approved']) ? 1 : 0;
                            $approvalRemarks = trim((string)($_POST['approval_remarks'] ?? ''));

                            if (!$canManualCustomerApproval) {
                                throw new RuntimeException('Customer approval is required before completing this stage. If online approval has not happened, an authorised Admin / Sales / Designing user can confirm direct approval using the checkbox.');
                            }

                            if ($manualConfirm !== 1) {
                                throw new RuntimeException('Customer approval confirmation is required for this approval stage.');
                            }

                            if ($approvalRemarks === '') {
                                throw new RuntimeException('Customer approval remark is required.');
                            }

                            jcvSaveManualCustomerApproval(
                                $conn,
                                $jobId,
                                (int)$stepRow['workflow_step_id'],
                                $approvalType,
                                (string)($job['customer_name'] ?? ''),
                                (string)($job['mobile'] ?? ''),
                                $userId,
                                $approvalRemarks
                            );
                        }
                    }

                    $photoApprovalSendResult = null;
                    if ($requiresDesignPhotoUpload && $newStatus === 'completed' && $uploadedProofPhotos) {
                        jcvSaveTrackingPhotos($conn, $jobId, $trackingId, (int)$stepRow['workflow_step_id'], $userId, 'tracking_photos');

                        $photoApproval = jcvGetOrCreatePhotoApproval(
                            $conn,
                            $jobId,
                            $trackingId,
                            (int)$stepRow['workflow_step_id'],
                            (string)($job['customer_name'] ?? ''),
                            (string)($job['mobile'] ?? ''),
                            true
                        );

                        if ($photoApproval) {
                            $photoApprovalSendResult = jcvSendPhotoApprovalByApi($conn, $job, $stepRow, $photoApproval, $userId);
                        }
                    }

                    if ($newStatus === 'completed') {
                        $stmt = $conn->prepare("
                            UPDATE job_tracking
                            SET
                                status = ?,
                                remarks = ?,
                                actual_start_at = COALESCE(actual_start_at, UTC_TIMESTAMP()),
                                actual_completed_at = UTC_TIMESTAMP(),
                                completed_by = ?,
                                updated_at = UTC_TIMESTAMP()
                            WHERE id = ?
                              AND job_card_id = ?
                        ");
                        $stmt->bind_param('ssiii', $newStatus, $remarks, $userId, $trackingId, $jobId);
                        $stmt->execute();
                        $stmt->close();
                    } elseif ($newStatus === 'in_progress') {
                        $stmt = $conn->prepare("
                            UPDATE job_tracking
                            SET
                                status = ?,
                                remarks = ?,
                                actual_start_at = COALESCE(actual_start_at, UTC_TIMESTAMP()),
                                updated_at = UTC_TIMESTAMP()
                            WHERE id = ?
                              AND job_card_id = ?
                        ");
                        $stmt->bind_param('ssii', $newStatus, $remarks, $trackingId, $jobId);
                        $stmt->execute();
                        $stmt->close();
                    } elseif ($newStatus === 'delayed') {
                        $delayReasonValue = $delayReasonId;

                        $stmt = $conn->prepare("
                            UPDATE job_tracking
                            SET
                                status = ?,
                                remarks = ?,
                                is_delayed = 1,
                                delay_started_at = COALESCE(delay_started_at, UTC_TIMESTAMP()),
                                delay_days = ?,
                                delay_reason_id = ?,
                                delay_remarks = ?,
                                updated_at = UTC_TIMESTAMP()
                            WHERE id = ?
                              AND job_card_id = ?
                        ");

                        $stmt->bind_param(
                            'ssiisii',
                            $newStatus,
                            $remarks,
                            $delayDays,
                            $delayReasonValue,
                            $remarks,
                            $trackingId,
                            $jobId
                        );

                        $stmt->execute();
                        $stmt->close();
                    } else {
                        $stmt = $conn->prepare("
                            UPDATE job_tracking
                            SET
                                status = ?,
                                remarks = ?,
                                updated_at = UTC_TIMESTAMP()
                            WHERE id = ?
                              AND job_card_id = ?
                        ");
                        $stmt->bind_param('ssii', $newStatus, $remarks, $trackingId, $jobId);
                        $stmt->execute();
                        $stmt->close();
                    }

                    if ($newStatus === 'completed') {
                        if (jcvIsDispatchStageKey($stepRow['step_key'] ?? '', $stepRow['step_name'] ?? '')) {
                            jcvCompleteDispatchGroup($conn, $jobId, $userId, $remarks);
                        }
                        jcvAutoStartNextPendingStage($conn, $jobId, $trackingId);
                    }

                    $summary = [
                        'total_steps' => 0,
                        'completed_steps' => 0,
                        'open_steps' => 0,
                        'progress_steps' => 0,
                        'delayed_steps' => 0,
                        'delay_history_steps' => 0
                    ];

                    $stmt = $conn->prepare("
                        SELECT
                            COUNT(*) AS total_steps,
                            SUM(CASE WHEN status IN ('completed','skipped') THEN 1 ELSE 0 END) AS completed_steps,
                            SUM(CASE WHEN status NOT IN ('completed','skipped','cancelled') THEN 1 ELSE 0 END) AS open_steps,
                            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS progress_steps,
                            SUM(CASE WHEN status = 'delayed' THEN 1 ELSE 0 END) AS delayed_steps,
                            SUM(CASE WHEN is_delayed = 1 THEN 1 ELSE 0 END) AS delay_history_steps
                        FROM job_tracking
                        WHERE job_card_id = ?
                    ");
                    $stmt->bind_param('i', $jobId);
                    $stmt->execute();
                    $summaryRow = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ($summaryRow) {
                        $summary = array_merge($summary, $summaryRow);
                    }

                    $currentWorkflowStepId = null;

                    $stmt = $conn->prepare("
                        SELECT jt.workflow_step_id
                        FROM job_tracking jt
                        LEFT JOIN workflow_steps ws
                            ON ws.id = jt.workflow_step_id
                        WHERE jt.job_card_id = ?
                          AND jt.status NOT IN ('completed','skipped','cancelled')
                        ORDER BY ws.sort_order ASC, jt.id ASC
                        LIMIT 1
                    ");
                    $stmt->bind_param('i', $jobId);
                    $stmt->execute();
                    $currentRow = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ($currentRow) {
                        $currentWorkflowStepId = (int)$currentRow['workflow_step_id'];
                    }

                    $jobStatusKey = 'in_progress';

                    if ((int)($summary['delayed_steps'] ?? 0) > 0) {
                        $jobStatusKey = 'delayed';
                    } elseif ((int)($summary['open_steps'] ?? 0) === 0 && (int)($summary['total_steps'] ?? 0) > 0) {
                        $jobStatusKey = 'completed';
                    } elseif ((int)($summary['progress_steps'] ?? 0) > 0) {
                        $jobStatusKey = 'in_progress';
                    } else {
                        $jobStatusKey = 'pending';
                    }

                    $jobStatusId = null;

                    $stmt = $conn->prepare("
                        SELECT id
                        FROM job_card_statuses
                        WHERE status_key = ?
                        LIMIT 1
                    ");
                    $stmt->bind_param('s', $jobStatusKey);
                    $stmt->execute();
                    $statusRow = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ($statusRow) {
                        $jobStatusId = (int)$statusRow['id'];
                    }

                    $isDelayed = ((int)($summary['delay_history_steps'] ?? 0) > 0 || $jobStatusKey === 'delayed') ? 1 : 0;

                    if ($jobStatusId && $currentWorkflowStepId) {
                        $stmt = $conn->prepare("
                            UPDATE job_cards
                            SET
                                current_workflow_step_id = ?,
                                job_card_status_id = ?,
                                is_delayed = ?,
                                completed_at = CASE
                                    WHEN ? = 'completed' THEN COALESCE(completed_at, UTC_TIMESTAMP())
                                    ELSE completed_at
                                END,
                                updated_by = ?,
                                updated_at = UTC_TIMESTAMP()
                            WHERE id = ?
                        ");
                        $stmt->bind_param(
                            'iiisii',
                            $currentWorkflowStepId,
                            $jobStatusId,
                            $isDelayed,
                            $jobStatusKey,
                            $userId,
                            $jobId
                        );
                        $stmt->execute();
                        $stmt->close();
                    } elseif ($jobStatusId) {
                        $stmt = $conn->prepare("
                            UPDATE job_cards
                            SET
                                job_card_status_id = ?,
                                is_delayed = ?,
                                completed_at = CASE
                                    WHEN ? = 'completed' THEN COALESCE(completed_at, UTC_TIMESTAMP())
                                    ELSE completed_at
                                END,
                                updated_by = ?,
                                updated_at = UTC_TIMESTAMP()
                            WHERE id = ?
                        ");
                        $stmt->bind_param(
                            'iisii',
                            $jobStatusId,
                            $isDelayed,
                            $jobStatusKey,
                            $userId,
                            $jobId
                        );
                        $stmt->execute();
                        $stmt->close();
                    }

                    $reviewWhatsappResult = null;

                    // Google Review WhatsApp should be sent ONLY after the complete Job Card is completed.
                    // Do not send it for individual workflow stage completion updates.
                    // Example: Printing Completed / Packing Completed should send only stage template.
                    if ($jobStatusKey === 'completed' && !jcvIsInternalMasterCopyStage($stepRow)) {
                        $reviewWhatsappResult = jcvSendJobCompletedReviewWhatsapp($conn, $job, $userId);
                    }

                    $trackingSendResult = null;

                    // Every status change from this page must notify the
                    // customer. Send the stage template with the Track Now
                    // button for both readymade and customized jobs.
                    //
                    // A final job may
                    // also send the separate Google Review message; that must
                    // not suppress the job_stage_completed notification.
                    // For proof/design completed with a photo, the approval
                    // WhatsApp already contains both approval and tracking
                    // buttons, so do not send a duplicate stage message there.
                    if (!is_array($photoApprovalSendResult)) {
                        $trackingSendResult = jcvSendTrackingUpdateByApi(
                            $conn,
                            $job,
                            $stepRow,
                            $newStatus,
                            $remarks,
                            $delayReasonId,
                            $delayDays,
                            $userId,
                            $updatedStageName
                        );
                    }

                    $redirectWa = '';
                    if (is_array($photoApprovalSendResult)) {
                        $redirectWa = !empty($photoApprovalSendResult['success']) ? '&wa=approval_sent' : '&wa=approval_failed';
                    } elseif (is_array($trackingSendResult)) {
                        $redirectWa = !empty($trackingSendResult['success']) ? '&wa=tracking_sent' : '&wa=tracking_failed';
                    }

                    header('Location: job_card_view.php?id=' . $jobId . '&msg=status_updated' . $redirectWa);
                    exit;
                }
            }
        } catch (Throwable $e) {
            $message = 'Status update failed: ' . $e->getMessage();
            $messageType = 'danger';
            $toastTitle = 'Failed';
        }
    }
}

if ($job) {
    try {
        if (jcvTableExists($conn, 'job_tracking')) {
            $stmt = $conn->prepare("
                SELECT
                    jt.*,
                    ws.step_name,
                    ws.step_key,
                    ws.sort_order,
                    ws.default_owner_role_key,
                    ws.is_approval_step,
                    rr.role_name AS responsible_role_name,
                    rr.role_key AS responsible_role_key,
                    ru.username AS responsible_user_name,
                    cu.username AS completed_by_name,
                    dr.reason_name AS delay_reason_name,
                    ca.id AS approval_id,
                    ca.job_card_id AS approval_job_card_id,
                    ca.workflow_step_id AS approval_workflow_step_id,
                    ca.approval_type,
                    ca.approval_token,
                    ca.customer_name AS approval_customer_name,
                    ca.mobile AS approval_mobile,
                    ca.status AS approval_status,
                    ca.approved_by_customer,
                    ca.approved_by_call,
                    ca.call_confirmed_by,
                    ca.customer_remarks,
                    ca.internal_remarks,
                    ca.approved_at,
                    ca.rejected_at,
                    ca.expires_at,
                    ca.ip_address,
                    ca.user_agent,
                    ca.link_sent_at,
                    ca.link_sent_by,
                    ca.created_at AS approval_created_at,
                    ca.updated_at AS approval_updated_at,
                    call_user.username AS call_confirmed_by_name,
                    sent_user.username AS link_sent_by_name
                FROM job_tracking jt
                LEFT JOIN workflow_steps ws
                    ON ws.id = jt.workflow_step_id
                LEFT JOIN roles rr
                    ON rr.id = jt.responsible_role_id
                LEFT JOIN users ru
                    ON ru.id = jt.responsible_user_id
                LEFT JOIN users cu
                    ON cu.id = jt.completed_by
                LEFT JOIN delay_reasons dr
                    ON dr.id = jt.delay_reason_id
                LEFT JOIN (
                    SELECT ca1.*
                    FROM customer_approvals ca1
                    INNER JOIN (
                        SELECT job_card_id, workflow_step_id, MAX(id) AS max_id
                        FROM customer_approvals
                        GROUP BY job_card_id, workflow_step_id
                    ) latest_ca
                        ON latest_ca.max_id = ca1.id
                ) ca
                    ON ca.job_card_id = jt.job_card_id
                   AND ca.workflow_step_id = jt.workflow_step_id
                LEFT JOIN users call_user
                    ON call_user.id = ca.call_confirmed_by
                LEFT JOIN users sent_user
                    ON sent_user.id = ca.link_sent_by
                WHERE jt.job_card_id = ?
                ORDER BY ws.sort_order ASC, jt.id ASC
            ");
            $stmt->bind_param('i', $jobId);
            $stmt->execute();
            $res = $stmt->get_result();

            while ($row = $res->fetch_assoc()) {
                $trackingRows[] = $row;
            }

            $stmt->close();
            $trackingRows = jcvBuildDisplayTrackingRows($trackingRows);
        }
    } catch (Throwable $e) {
        $trackingRows = [];
    }
}

if ($job) {
    $jobItems = jcvFetchJobItems($conn, $job);
    $trackingPhotosById = jcvGetTrackingPhotos($conn, $jobId);
}

$jobItemCount = count($jobItems);
$jobItemTotalQty = 0.0;
$jobItemProductTotal = 0.0;

foreach ($jobItems as $jobItem) {
    $jobItemTotalQty += (float)($jobItem['qty'] ?? 0);
    $jobItemProductTotal += (float)($jobItem['amount'] ?? 0);
}

$jobProductSummary = $jobItems
    ? jcvProductItemsSummary($jobItems)
    : ($job ? jcvResolvedProductName($conn, $job) : 'Cards');

$totalSteps = $trackingRows ? count($trackingRows) : ($job ? (int)($job['total_steps'] ?? 0) : 0);
$completedSteps = 0;
foreach ($trackingRows as $row) {
    if (in_array(strtolower((string)($row['status'] ?? 'pending')), ['completed', 'skipped'], true)) {
        $completedSteps++;
    }
}
if (!$trackingRows && $job) {
    $completedSteps = (int)($job['completed_steps'] ?? 0);
}
$progressPercent = $totalSteps > 0 ? round(($completedSteps / $totalSteps) * 100) : 0;
$progressPercent = max(0, min(100, $progressPercent));

$orderType = $job ? strtolower((string)($job['order_type'] ?? 'readymade')) : '';
$statusKey = $job ? strtolower((string)($job['status_key'] ?? '')) : '';
$paymentSnapshot = $job ? jcvPaymentSnapshot($conn, $job) : ['is_paid' => false, 'balance_amount' => 0, 'paid_amount' => 0, 'final_amount' => 0];

if ($message !== '' && $toastTitle === 'Info') {
    $toastTitle = $messageType === 'success' ? 'Success' : ($messageType === 'warning' ? 'Warning' : 'Failed');
}

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>View Job Card - Subhiksha Cards</title>

    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>

    <style>
    .module-page .page-head {
        padding: 24px 28px;
        margin-bottom: 18px;
    }

    .module-page .page-head h1 {
        font-size: 30px;
        font-weight: 900;
        color: var(--text-main);
    }

    .module-card {
        padding: 24px;
    }

    .module-title {
        font-size: 18px;
        font-weight: 900;
        color: var(--text-main);
        margin: 0;
    }

    .info-card {
        border: 1px solid var(--border-soft);
        border-radius: 18px;
        padding: 16px;
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
        height: 100%;
    }

    .info-card small {
        display: block;
        color: var(--text-muted);
        font-size: 11px;
        font-weight: 900;
        text-transform: uppercase;
        margin-bottom: 5px;
    }

    .info-card strong,
    .info-card span {
        display: block;
        color: var(--text-main);
        font-weight: 900;
        word-break: break-word;
        white-space: pre-wrap;
    }

    .job-items-card {
        border: 1px solid var(--border-soft);
        border-radius: 18px;
        overflow: hidden;
        background: var(--card-bg);
    }

    .job-items-head {
        padding: 16px 18px;
        border-bottom: 1px solid var(--border-soft);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        flex-wrap: wrap;
    }

    .job-items-table-wrap {
        overflow-x: auto;
    }

    .job-items-table {
        width: 100%;
        min-width: 760px;
        border-collapse: collapse;
    }

    .job-items-table th,
    .job-items-table td {
        padding: 12px 14px;
        border-bottom: 1px solid var(--border-soft);
        vertical-align: middle;
    }

    .job-items-table th {
        font-size: 10.5px;
        font-weight: 900;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: .02em;
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
    }

    .job-items-table td {
        font-size: 12px;
        color: var(--text-main);
    }

    .job-items-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .job-items-summary {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .job-items-summary span {
        border: 1px solid var(--border-soft);
        border-radius: 999px;
        padding: 5px 10px;
        font-size: 11px;
        font-weight: 900;
        color: var(--text-muted);
    }

    .order-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border-radius: 999px;
        padding: 7px 12px;
        font-size: 11px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: .02em;
    }

    .order-badge.readymade {
        color: #92400e;
        background: #fef3c7;
    }

    .order-badge.customized {
        color: #075985;
        background: #e0f2fe;
    }

    .status-pill {
        font-size: 11px;
        font-weight: 900;
        border-radius: 999px;
        padding: 6px 10px;
        display: inline-flex;
        align-items: center;
        white-space: nowrap;
    }

    .status-pill.completed {
        color: #166534;
        background: #dcfce7;
    }

    .status-pill.progress {
        color: #1d4ed8;
        background: #dbeafe;
    }

    .status-pill.pending {
        color: #92400e;
        background: #fef3c7;
    }

    .status-pill.danger {
        color: #991b1b;
        background: #fee2e2;
    }


    .status-pill.locked {
        color: #475569;
        background: #e2e8f0;
    }

    .toast-ui {
        border: 0;
        border-radius: 18px;
        box-shadow: 0 18px 45px rgba(15, 23, 42, .18);
        overflow: hidden;
        min-width: 320px;
        max-width: 420px;
    }

    .toast-ui.success {
        background: #dcfce7;
        color: #14532d;
    }

    .toast-ui.danger {
        background: #fee2e2;
        color: #7f1d1d;
    }

    .toast-ui.warning {
        background: #fef3c7;
        color: #78350f;
    }

    .toast-ui .toast-title {
        font-size: 14px;
        font-weight: 900;
        margin-bottom: 2px;
    }

    .toast-ui .toast-message {
        font-size: 13px;
        font-weight: 800;
        line-height: 1.45;
    }

    .stage-lock-note {
        color: #64748b;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        border-radius: 999px;
        padding: 7px 12px;
        font-size: 11px;
        font-weight: 900;
    }

    .progress-wrap {
        width: 100%;
        height: 12px;
        background: color-mix(in srgb, var(--border-soft) 80%, transparent);
        border-radius: 999px;
        overflow: hidden;
    }

    .progress-bar-mini {
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(135deg, #2563eb, #22c55e);
    }

    .timeline {
        display: grid;
        gap: 12px;
    }

    .timeline-item {
        border: 1px solid var(--border-soft);
        border-radius: 16px;
        padding: 12px;
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
    }

    .timeline-item.delayed-history {
        border-color: #fecaca;
        background: color-mix(in srgb, #fee2e2 34%, var(--card-bg));
        box-shadow: 0 10px 24px rgba(220, 38, 38, .08);
    }

    .delay-history-note {
        border: 1px solid #fca5a5;
        background: #fef2f2;
        color: #991b1b;
        border-radius: 14px;
        padding: 10px 12px;
        font-weight: 900;
        font-size: 12px;
    }

    .delay-card {
        border-color: #fca5a5 !important;
        background: #fef2f2 !important;
    }

    .delay-card small,
    .delay-card strong,
    .delay-card span {
        color: #991b1b !important;
    }

    .timeline-title {
        font-size: 15px;
        font-weight: 900;
        color: var(--text-main);
        margin: 0;
    }

    .timeline-meta {
        color: var(--text-muted);
        font-size: 12px;
        font-weight: 700;
        margin-top: 4px;
    }

    .amount-card {
        padding: 12px 14px;
        border-radius: 14px;
        background: linear-gradient(135deg, rgba(37, 99, 235, .10), rgba(34, 197, 94, .10));
        border: 1px solid var(--border-soft);
    }

    .amount-card small {
        display: block;
        font-size: 12px;
        color: var(--text-muted);
        font-weight: 900;
        text-transform: uppercase;
    }

    .amount-card strong {
        display: block;
        margin-top: 3px;
        font-size: 18px;
        font-weight: 900;
        color: var(--text-main);
    }

    .timeline-item .info-card {
        padding: 10px 12px;
        border-radius: 12px;
    }

    .timeline-item .info-card small {
        font-size: 10px;
        margin-bottom: 3px;
    }

    .timeline-item .info-card strong,
    .timeline-item .info-card span {
        font-size: 13px;
        line-height: 1.35;
    }

    .stage-update-form {
        border: 1px dashed var(--border-soft);
        background: color-mix(in srgb, var(--card-bg) 92%, #dbeafe);
    }

    .stage-update-form textarea {
        min-height: 46px;
    }


    .stage-update-form.compact-update-form {
        padding: 14px !important;
        border-radius: 16px;
    }

    .compact-update-form .form-label {
        font-size: 12px;
        margin-bottom: 5px;
        color: var(--text-main);
    }

    .compact-update-form .form-control,
    .compact-update-form .form-select {
        min-height: 40px;
        border-radius: 12px;
        font-size: 13px;
        font-weight: 700;
    }

    .compact-update-form textarea.form-control {
        min-height: 40px;
        height: 40px;
    }

    .design-photo-box {
        border: 1px solid #f59e0b;
        background: #fffbeb;
        color: #78350f;
        border-radius: 14px;
        padding: 10px 12px;
    }

    .design-photo-title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 8px;
    }

    .design-photo-title strong {
        font-size: 13px;
        font-weight: 900;
    }

    .photo-input-row {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 8px;
    }

    .photo-input-row .form-control {
        flex: 1 1 auto;
    }

    .photo-input-remove {
        width: 34px;
        height: 34px;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0;
    }

    .photo-help-text {
        font-size: 10px;
        font-weight: 900;
        color: #92400e;
        text-transform: uppercase;
        letter-spacing: .02em;
    }

    .tracking-photo-list {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 10px;
    }

    .tracking-photo-thumb {
        width: 74px;
        height: 74px;
        border-radius: 12px;
        border: 1px solid var(--border-soft);
        object-fit: cover;
        background: #f8fafc;
    }


    .tracking-photo-item {
        position: relative;
        width: 84px;
        min-height: 100px;
        border: 1px solid var(--border-soft);
        border-radius: 16px;
        padding: 5px;
        background: #fff;
        box-shadow: 0 8px 18px rgba(15, 23, 42, .06);
    }

    .tracking-pdf-link {
        display: block;
        text-decoration: none;
    }

    .tracking-pdf-thumb {
        width: 108px;
        min-height: 110px;
        border: 1px solid #fecaca;
        border-radius: 14px;
        background: #fff7f7;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 10px;
        text-align: center;
    }

    .tracking-pdf-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 52px;
        height: 34px;
        padding: 0 8px;
        border-radius: 8px;
        background: #dc2626;
        color: #fff;
        font-size: 12px;
        font-weight: 900;
    }

    .tracking-pdf-thumb small {
        max-width: 90px;
        font-size: 10px;
        color: #475569;
        font-weight: 800;
        overflow-wrap: anywhere;
    }

    .tracking-photo-item .tracking-photo-thumb {
        width: 100%;
        height: 74px;
    }

    .photo-cancel-form {
        margin: 5px 0 0;
    }

    .photo-cancel-btn {
        width: 100%;
        border: 1px solid #fecaca;
        background: #fff1f2;
        color: #dc2626;
        border-radius: 999px;
        font-size: 10px;
        font-weight: 900;
        padding: 4px 6px;
        line-height: 1.1;
    }

    .photo-cancel-btn:hover {
        background: #dc2626;
        color: #fff;
        border-color: #dc2626;
    }

    .photo-approval-card {
        border-radius: 18px;
        border: 1px solid var(--border-soft);
        background: linear-gradient(135deg, #f8fafc, #ffffff);
        padding: 14px 16px;
    }

    .photo-approval-head {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .photo-status-row {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
        margin-top: 2px;
    }

    .photo-status-chip {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        width: auto !important;
        min-width: 0 !important;
        border-radius: 999px;
        padding: 6px 11px;
        font-size: 11px;
        font-weight: 900;
        line-height: 1;
        text-transform: uppercase;
        border: 1px solid #cbd5e1;
        background: #f8fafc;
        color: #475569;
    }

    .photo-status-chip.pending {
        border-color: #fed7aa;
        background: #fff7ed;
        color: #9a3412;
    }

    .photo-status-chip.approved {
        border-color: #bbf7d0;
        background: #ecfdf5;
        color: #166534;
    }

    .photo-status-chip.rejected,
    .photo-status-chip.expired {
        border-color: #fecaca;
        background: #fef2f2;
        color: #991b1b;
    }

    .photo-action-wrap {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: 8px;
    }

    .wa-photo-btn {
        background: #22c55e !important;
        border-color: #22c55e !important;
        color: #fff !important;
    }

    .wa-photo-btn:hover {
        background: #16a34a !important;
        border-color: #16a34a !important;
        color: #fff !important;
    }

    .delay-field {
        display: none;
    }

    .stage-update-form.is-delay .delay-field {
        display: block;
    }

    .stage-update-form .form-label,
    .design-photo-title strong {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        flex-wrap: nowrap;
    }

    .required-star {
        display: inline;
        color: #dc2626;
        font-weight: 900;
        line-height: 1;
        vertical-align: baseline;
    }

    .design-photo-upload-field {
        display: none;
    }

    .stage-update-form.needs-photo .design-photo-upload-field {
        display: block;
    }

    .approval-field {
        display: none;
    }

    .stage-update-form.needs-approval .approval-field {
        display: block;
    }

    .approval-box {
        border: 1px solid #f59e0b;
        background: #fffbeb;
        color: #78350f;
        border-radius: 16px;
        padding: 14px 16px;
    }

    .approval-box.success {
        border-color: #22c55e;
        background: #f0fdf4;
        color: #14532d;
    }

    .approval-box.denied {
        border-color: #ef4444;
        background: #fef2f2;
        color: #991b1b;
    }

    .approval-box.denied .approval-mini {
        border-color: rgba(153, 27, 27, .18);
    }

    .approval-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 8px;
        margin-top: 10px;
    }

    .approval-mini {
        border: 1px solid rgba(120, 53, 15, .18);
        border-radius: 12px;
        padding: 8px 10px;
        background: rgba(255, 255, 255, .55);
        min-width: 0;
    }

    .approval-box.success .approval-mini {
        border-color: rgba(20, 83, 45, .16);
    }

    .approval-mini small {
        display: block;
        font-size: 9px;
        font-weight: 900;
        text-transform: uppercase;
        opacity: .75;
        margin-bottom: 2px;
    }

    .approval-mini strong,
    .approval-mini span {
        display: block;
        font-size: 12px;
        font-weight: 900;
        word-break: break-word;
    }

    @media(max-width:991.98px) {
        .approval-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media(max-width:767.98px) {
        .module-page .page-head {
            padding: 18px;
            border-radius: 18px;
        }

        .module-page .page-head h1 {
            font-size: 24px;
        }

        .module-card {
            padding: 16px;
            border-radius: 18px;
        }
    }

    /* Compact Job Card View UI */
    .module-page {
        width: 100%;
        max-width: 1180px;
        margin-left: auto;
        margin-right: auto;
    }

    .module-page .page-head {
        padding: 16px 18px;
        margin-bottom: 12px;
        border-radius: 16px;
    }

    .module-page .page-head h1 {
        font-size: 24px;
        line-height: 1.15;
        font-weight: 800;
    }

    .module-page .page-head p {
        font-size: 12px;
        font-weight: 500;
    }

    .module-page .page-head .btn {
        font-size: 12px;
        font-weight: 700 !important;
        padding: 7px 14px !important;
    }

    .module-card {
        padding: 16px;
        border-radius: 16px;
    }

    .module-title {
        font-size: 15px;
        font-weight: 800;
    }

    .amount-card {
        padding: 9px 12px;
        border-radius: 12px;
        min-height: 62px;
    }

    .amount-card small {
        font-size: 10px;
        font-weight: 700;
    }

    .amount-card strong {
        margin-top: 2px;
        font-size: 16px;
        font-weight: 800;
    }

    .info-card {
        border-radius: 14px;
        padding: 11px 12px;
        min-height: 72px;
    }

    .info-card small {
        font-size: 9.5px;
        font-weight: 700;
        margin-bottom: 3px;
    }

    .info-card strong,
    .info-card span {
        font-size: 13px;
        line-height: 1.35;
        font-weight: 750;
    }

    .order-badge,
    .status-pill,
    .stage-lock-note {
        font-size: 10px;
        font-weight: 700;
        padding: 5px 9px;
    }

    .job-items-card {
        border-radius: 14px;
    }

    .job-items-head {
        padding: 11px 13px;
        gap: 10px;
    }

    .job-items-table {
        min-width: 700px;
    }

    .job-items-table th,
    .job-items-table td {
        padding: 8px 10px;
    }

    .job-items-table th {
        font-size: 9.5px;
        font-weight: 750;
    }

    .job-items-table td {
        font-size: 11px;
    }

    .job-items-summary span {
        padding: 4px 8px;
        font-size: 10px;
        font-weight: 700;
    }

    .timeline {
        gap: 9px;
    }

    .timeline-item {
        border-radius: 14px;
        padding: 10px;
    }

    .timeline-title {
        font-size: 13px;
        font-weight: 800;
    }

    .timeline-meta {
        font-size: 10.5px;
        font-weight: 600;
    }

    .timeline-item .info-card {
        padding: 8px 10px;
        border-radius: 10px;
        min-height: auto;
    }

    .timeline-item .info-card small {
        font-size: 9px;
    }

    .timeline-item .info-card strong,
    .timeline-item .info-card span {
        font-size: 11.5px;
        font-weight: 700;
    }

    .progress-wrap {
        height: 8px;
    }

    .stage-update-form.compact-update-form {
        padding: 10px !important;
        border-radius: 12px;
    }

    .compact-update-form .form-label,
    .module-page .form-label {
        font-size: 11px;
        font-weight: 700;
        margin-bottom: 4px;
    }

    .module-page .form-control,
    .module-page .form-select {
        min-height: 34px;
        padding: 6px 9px;
        font-size: 12px;
        border-radius: 9px;
    }

    .module-page .btn {
        font-size: 11px;
        font-weight: 700;
        padding: 6px 10px;
    }

    .module-page .btn-sm {
        font-size: 10px;
        padding: 5px 8px;
    }

    .approval-box {
        border-radius: 12px;
        padding: 10px 12px;
    }

    .approval-mini {
        border-radius: 9px;
        padding: 6px 8px;
    }

    .approval-mini strong,
    .approval-mini span {
        font-size: 11px;
        font-weight: 700;
    }

    @media (max-width: 1199.98px) {
        .module-page {
            max-width: 100%;
        }
    }

    @media (max-width: 767.98px) {
        .module-page {
            max-width: 100%;
        }

        .module-page .page-head {
            padding: 14px;
        }

        .module-page .page-head h1 {
            font-size: 21px;
        }

        .module-card {
            padding: 13px;
        }

        .info-card {
            min-height: auto;
        }

        .amount-card {
            min-height: auto;
        }
    }
    </style>
</head>

<body class="<?= e(($theme['layout_density'] ?? '') === 'compact' ? 'layout-compact' : '') ?>">
    <div id="mobileOverlay"></div>

    <div class="app-shell">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main id="main">
            <?php include __DIR__ . '/includes/nav.php'; ?>

            <section class="page-section module-page">
                <div class="card-ui page-head">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                        <div>
                            <h1 class="mb-1">View Job Card</h1>
                            <p class="text-muted-custom mb-0">
                                <?= $job ? e($job['job_card_no']) : 'Job card details' ?>
                            </p>
                        </div>

                        <a href="job_cards.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">
                            Back to Job Cards
                        </a>
                    </div>
                </div>

                <?php if ($message !== ''): ?>
                <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 12000">
                    <div id="pageToast" class="toast toast-ui <?= e($messageType) ?>" role="alert" aria-live="assertive"
                        aria-atomic="true" data-bs-delay="4200">
                        <div class="d-flex">
                            <div class="toast-body">
                                <div class="toast-title">
                                    <?= e($toastTitle ?: ($messageType === 'success' ? 'Success' : 'Failed')) ?></div>
                                <div class="toast-message"><?= e($message) ?></div>
                            </div>
                            <button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast"
                                aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!$job): ?>
                <div class="card-ui module-card">
                    <div class="alert alert-danger rounded-4 fw-bold mb-0">
                        <?= e($message ?: 'Job card not found.') ?>
                    </div>
                </div>
                <?php else: ?>

                <?php if (!$isProductionTeamView): ?>
                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-4">
                        <div class="amount-card">
                            <small>Final Amount</small>
                            <strong><?= e(jcvMoney($job['final_amount'] ?? 0)) ?></strong>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="amount-card">
                            <small>Advance Amount</small>
                            <strong><?= e(jcvMoney($job['advance_amount'] ?? 0)) ?></strong>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="amount-card">
                            <small>Balance Amount</small>
                            <strong><?= e(jcvMoney($job['balance_amount'] ?? 0)) ?></strong>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($isProductionTeamView): ?>
                <div class="card-ui module-card mb-3">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
                        <div>
                            <h2 class="module-title mb-1"><?= e($job['job_card_no']) ?></h2>
                            <p class="text-muted-custom mb-0">
                                <?= $isDesignRole ? 'Design / Production Job Details' : 'Printing Job Details' ?>
                            </p>
                        </div>

                        <span class="status-pill <?= e(jcvStatusClass($statusKey)) ?>">
                            <?= e($job['status_name'] ?? 'Status') ?>
                        </span>
                    </div>

                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <div class="info-card h-100">
                                <small>Customer Name</small>
                                <strong><?= e($job['customer_name'] ?? '-') ?></strong>
                            </div>
                        </div>

                        <div class="col-12 col-md-4">
                            <div class="info-card h-100">
                                <small>Mobile</small>
                                <strong><?= e($job['mobile'] ?? '-') ?></strong>
                            </div>
                        </div>

                        <div class="col-12 col-md-4">
                            <div class="info-card h-100">
                                <small>Function Type</small>
                                <strong><?= e($job['function_name'] ?? '-') ?></strong>
                            </div>
                        </div>

                        <div class="col-12 col-md-4">
                            <div class="info-card h-100">
                                <small>Assigned Designer</small>
                                <strong><?= e($job['designer_name'] ?? '-') ?></strong>
                            </div>
                        </div>

                        <div class="col-12 col-md-4">
                            <div class="info-card h-100">
                                <small>Assigned Printer</small>
                                <strong><?= e($job['printer_name'] ?? '-') ?></strong>
                            </div>
                        </div>

                        <div class="col-12 col-md-4">
                            <div class="info-card h-100">
                                <small>Product</small>
                                <strong><?= e($jobProductSummary) ?></strong>
                            </div>
                        </div>

                        <div class="col-12 col-md-4">
                            <div class="info-card h-100">
                                <small>Total Quantity</small>
                                <strong><?= number_format($jobItemTotalQty, 2) ?></strong>
                            </div>
                        </div>

                        <div class="col-12 col-md-4">
                            <div class="info-card h-100">
                                <small>Printing Type</small>
                                <strong><?= e($job['printing_name'] ?? '-') ?></strong>
                            </div>
                        </div>

                        <div class="col-12 col-md-4">
                            <div class="info-card h-100">
                                <small>Printing Sub Type</small>
                                <strong><?= e($job['sub_type_name'] ?? '-') ?></strong>
                            </div>
                        </div>

                        <div class="col-12 col-md-4">
                            <div class="info-card h-100">
                                <small>Current Stage</small>
                                <strong><?= e($job['current_step_name'] ?? '-') ?></strong>
                            </div>
                        </div>

                        <div class="col-12 col-md-4">
                            <div class="info-card h-100">
                                <small>Expected Delivery Date</small>
                                <strong><?= e(jcvDate($job['delivery_date'] ?? null)) ?></strong>
                            </div>
                        </div>

                        <div class="col-12 col-md-4">
                            <div class="info-card h-100">
                                <small>Assigned Printing Role</small>
                                <strong><?= e($job['assigned_printing_role_name'] ?? '-') ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="card-ui module-card mb-3">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
                        <div>
                            <h2 class="module-title"><?= e($job['job_card_no']) ?></h2>
                            <p class="text-muted-custom mb-0">
                                Created on <?= e(jcvDateTime($job['created_at'] ?? null)) ?>
                            </p>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <span class="order-badge <?= e(jcvOrderBadgeClass($orderType)) ?>">
                                <?= e($orderType === 'customized' ? 'Customized' : 'Readymade') ?>
                            </span>

                            <span class="status-pill <?= e(jcvStatusClass($statusKey)) ?>">
                                <?= e($job['status_name'] ?? 'Status') ?>
                            </span>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="info-card">
                                <small>Customer</small>
                                <strong><?= e($job['customer_name']) ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="info-card">
                                <small>Mobile</small>
                                <strong><?= e($job['mobile']) ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="info-card">
                                <small>Delivery Date</small>
                                <strong><?= e(jcvDate($job['delivery_date'] ?? null)) ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="info-card">
                                <small>Function / Product Type</small>
                                <strong><?= e($job['function_name'] ?? '-') ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="info-card">
                                <small>Products</small>
                                <strong><?= e($jobProductSummary) ?></strong>
                                <span class="text-muted-custom small mt-1">
                                    <?= number_format($jobItemCount) ?> product<?= $jobItemCount === 1 ? '' : 's' ?>
                                </span>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="info-card">
                                <small>Printing Type</small>
                                <strong><?= e($job['printing_name'] ?? '-') ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="info-card">
                                <small>Printing Sub Type</small>
                                <strong><?= e($job['sub_type_name'] ?? '-') ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="info-card">
                                <small>Current Stage</small>
                                <strong><?= e($job['current_step_name'] ?? '-') ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="info-card">
                                <small>Assigned Printing Role</small>
                                <strong><?= e($job['assigned_printing_role_name'] ?? '-') ?></strong>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="info-card">
                                <small>Sales Person</small>
                                <strong><?= e($job['sales_person'] ?? '-') ?></strong>
                            </div>
                        </div>

                        <?php if ($isAdminMonitor): ?>
                        <div class="col-md-3">
                            <div class="info-card">
                                <small>Designer</small>
                                <strong><?= e($job['designer_name'] ?? '-') ?></strong>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="info-card">
                                <small>Printer</small>
                                <strong><?= e($job['printer_name'] ?? '-') ?></strong>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="col-md-3">
                            <div class="info-card">
                                <small>Created By</small>
                                <strong><?= e($job['created_by_name'] ?? '-') ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card-ui module-card mb-3">
                    <div class="job-items-card">
                        <div class="job-items-head">
                            <div>
                                <h2 class="module-title mb-1">Products in this Job Card</h2>
                                <p class="text-muted-custom mb-0">
                                    <?= $isProductionTeamView
                                        ? 'Production details only. Pricing information is hidden for Designing and Printing teams.'
                                        : 'All products created under the same Job Card ID. Workflow/status remains common for the complete Job Card.' ?>
                                </p>
                            </div>

                            <div class="job-items-summary">
                                <span><?= number_format($jobItemCount) ?>
                                    Product<?= $jobItemCount === 1 ? '' : 's' ?></span>
                                <span>Total Qty: <?= number_format($jobItemTotalQty, 2) ?></span>
                                <?php if (!$isProductionTeamView): ?>
                                <span>Product Total: <?= e(jcvMoney($jobItemProductTotal)) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!$jobItems): ?>
                        <div class="alert alert-warning rounded-4 fw-bold m-3 mb-3">
                            No product items found for this Job Card.
                        </div>
                        <?php else: ?>
                        <div class="job-items-table-wrap">
                            <table class="job-items-table">
                                <thead>
                                    <tr>
                                        <th style="width:60px;">#</th>
                                        <th>Product</th>
                                        <th class="text-end">Qty</th>
                                        <?php if ($isProductionTeamView): ?>
                                        <th>Printing Details</th>
                                        <?php endif; ?>
                                        <?php if (!$isProductionTeamView): ?>
                                        <th class="text-end">Rate / Unit</th>
                                        <th class="text-end">Product Total</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($jobItems as $itemIndex => $jobItem): ?>
                                    <tr>
                                        <td><?= (int)$itemIndex + 1 ?></td>
                                        <td>
                                            <strong><?= e($jobItem['item_name'] ?? 'Cards') ?></strong>
                                        </td>
                                        <td class="text-end"><?= number_format((float)($jobItem['qty'] ?? 0), 2) ?></td>
                                        <?php if ($isProductionTeamView): ?>
                                        <td>
                                            <?php
                                                $printingDetails = [];
                                                if (!empty($jobItem['size_text'])) $printingDetails[] = 'Size: ' . $jobItem['size_text'];
                                                if (!empty($jobItem['gsm_thickness'])) $printingDetails[] = 'GSM/Thickness: ' . $jobItem['gsm_thickness'];
                                                if (!empty($jobItem['printing_side'])) $printingDetails[] = 'Side: ' . $jobItem['printing_side'];
                                                if (!empty($jobItem['screening_type'])) $printingDetails[] = 'Screening: ' . $jobItem['screening_type'];
                                                if (!empty($jobItem['lamination_type'])) $printingDetails[] = 'Lamination: ' . $jobItem['lamination_type'];
                                                if (!empty($jobItem['finishing_required'])) $printingDetails[] = 'Finishing Required';
                                                if (!empty($jobItem['description'])) $printingDetails[] = 'Details: ' . $jobItem['description'];
                                            ?>
                                            <?= e($printingDetails ? implode(' | ', $printingDetails) : '-') ?>
                                        </td>
                                        <?php endif; ?>
                                        <?php if (!$isProductionTeamView): ?>
                                        <td class="text-end"><?= e(jcvMoney($jobItem['rate'] ?? 0)) ?></td>
                                        <td class="text-end fw-bold"><?= e(jcvMoney($jobItem['amount'] ?? 0)) ?></td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card-ui module-card mb-3">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="module-title">Progress</h2>
                            <p class="text-muted-custom mb-0">
                                <?= number_format($completedSteps) ?> completed out of <?= number_format($totalSteps) ?>
                                stages.
                            </p>
                        </div>

                        <strong class="fs-4"><?= (int)$progressPercent ?>%</strong>
                    </div>

                    <div class="progress-wrap">
                        <div class="progress-bar-mini" style="width:<?= (int)$progressPercent ?>%"></div>
                    </div>
                </div>

                <div class="card-ui module-card">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="module-title">Workflow Tracking</h2>
                            <p class="text-muted-custom mb-0">
                                Stage-wise production tracking details.
                            </p>
                        </div>
                    </div>

                    <?php if (!$trackingRows): ?>
                    <div class="alert alert-warning rounded-4 fw-bold mb-0">
                        No tracking stages found for this job card.
                    </div>
                    <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($trackingRows as $step): ?>
                        <?php
                            $stepStatus = strtolower((string)($step['status'] ?? 'pending'));
                            $isDispatchStage = jcvIsDispatchStageKey($step['step_key'] ?? '', $step['step_name'] ?? '');
                            $dispatchPaymentPending = $isDispatchStage && empty($paymentSnapshot['is_paid']);
                            $statusClass = jcvStatusClass($stepStatus);
                            $stepOwnerRoleKey = $step['responsible_role_key'] ?: ($step['default_owner_role_key'] ?? '');
                            $canUpdateThisStep = jcvCanUpdateStep(
                                $roleKey,
                                $stepOwnerRoleKey,
                                $job['assigned_printing_role_key'] ?? '',
                                $job['printing_role_key'] ?? '',
                                $canUpdateJob,
                                $stepStatus
                            );
                            $previousStep = jcvPreviousStepStatus($conn, $jobId, (int)$step['id']);
                            $previousStepAllowed = jcvPreviousStepAllowsUpdate($previousStep);
                            $canOpenUpdateForm = $canUpdateThisStep && $previousStepAllowed;
                            $previousStepName = trim((string)($previousStep['step_name'] ?? 'Previous stage'));
                            $previousStepStatus = strtolower(trim((string)($previousStep['status'] ?? '')));
                            $stageWasDelayed = (int)($step['is_delayed'] ?? 0) === 1
                                || !empty($step['delay_started_at'])
                                || !empty($step['delay_reason_id'])
                                || trim((string)($step['delay_remarks'] ?? '')) !== '';
                            $stageDelayedCompleted = $stageWasDelayed && $stepStatus === 'completed';
                        ?>
                        <div class="timeline-item <?= $stageWasDelayed ? 'delayed-history' : '' ?>">
                            <div class="d-flex flex-column flex-lg-row justify-content-between gap-2">
                                <div>
                                    <h3 class="timeline-title"><?= e($step['step_name'] ?? '-') ?></h3>
                                    <div class="timeline-meta">
                                        Role:
                                        <?= e($step['responsible_role_name'] ?? jcvRoleLabel($stepOwnerRoleKey)) ?>
                                        |
                                        User:
                                        <?= e($step['responsible_user_name'] ?? '-') ?>
                                    </div>
                                </div>

                                <div class="d-flex flex-column flex-lg-row align-items-lg-center gap-2">
                                    <span class="status-pill <?= e($statusClass) ?>">
                                        <?= e(ucwords(str_replace('_', ' ', $stepStatus))) ?>
                                    </span>

                                    <?php if ($stageDelayedCompleted): ?>
                                    <span class="status-pill danger">Delayed & Completed</span>
                                    <?php elseif ($stageWasDelayed && $stepStatus !== 'delayed'): ?>
                                    <span class="status-pill danger">Delayed History</span>
                                    <?php endif; ?>

                                    <?php if ($canOpenUpdateForm): ?>
                                    <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold"
                                        data-bs-toggle="collapse" data-bs-target="#updateStage<?= (int)$step['id'] ?>">
                                        Update Status
                                    </button>
                                    <?php elseif ($canUpdateThisStep && !$previousStepAllowed): ?>
                                    <button type="button"
                                        class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-bold js-locked-stage"
                                        data-message="<?= e($previousStepName . ' must be completed before updating this stage.') ?>">
                                        Locked
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="row g-3 mt-2">
                                <div class="col-md-3">
                                    <div class="info-card">
                                        <small>Planned Start</small>
                                        <strong><?= e(jcvDate($step['planned_start_date'] ?? null)) ?></strong>
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="info-card">
                                        <small>Planned Completion</small>
                                        <strong><?= e(jcvDate($step['planned_completion_date'] ?? null)) ?></strong>
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="info-card">
                                        <small>Actual Start</small>
                                        <strong><?= e(jcvDateTime($step['actual_start_at'] ?? null)) ?></strong>
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="info-card">
                                        <small>Actual Completed</small>
                                        <strong><?= e(jcvDateTime($step['actual_completed_at'] ?? null)) ?></strong>
                                    </div>
                                </div>

                                <?php if ($canUpdateThisStep && !$previousStepAllowed): ?>
                                <div class="col-12">
                                    <span class="stage-lock-note">
                                        <?= e($previousStepName) ?> must be completed before this stage can be updated.
                                    </span>
                                </div>
                                <?php endif; ?>

                                <?php if ($stageWasDelayed): ?>
                                <div class="col-12">
                                    <div class="delay-history-note">
                                        <?= $stageDelayedCompleted ? 'This stage was delayed and later completed.' : 'This stage has delay history.' ?>
                                        <?= !empty($step['delay_remarks']) ? ' Remark: ' . e($step['delay_remarks']) : '' ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if ($stageWasDelayed || $stepStatus === 'delayed'): ?>
                                <div class="col-md-4">
                                    <div class="info-card delay-card">
                                        <small>Delay Days</small>
                                        <strong><?= e($step['delay_days'] ?? 0) ?></strong>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="info-card delay-card">
                                        <small>Delay Reason</small>
                                        <strong><?= e($step['delay_reason_name'] ?? '-') ?></strong>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="info-card delay-card">
                                        <small>Delay Remarks</small>
                                        <span><?= e($step['delay_remarks'] ?? '-') ?></span>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($step['remarks'])): ?>
                                <div class="col-12">
                                    <div class="info-card">
                                        <small>Remarks</small>
                                        <span><?= e($step['remarks']) ?></span>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php
                                    $isApprovalStage = jcvIsApprovalStage($step);
                                    $approvalRow = null;
                                    if (!empty($step['approval_id'])) {
                                        $approvalRow = [
                                            'status' => $step['approval_status'] ?? '',
                                            'approved_by_customer' => $step['approved_by_customer'] ?? 0,
                                            'approved_by_call' => $step['approved_by_call'] ?? 0
                                        ];
                                    }
                                    $approvalDone = jcvApprovalIsDone($approvalRow);
                                    $isDesignProofingStage = jcvIsDesignProofingStage($step, $stepOwnerRoleKey);
                                    $photoRequiredStage = $isDesignProofingStage && !$isApprovalStage;
                                    $requiresDesignPhotoUpload = jcvRequiresDesignPhotoUpload($step, $stepOwnerRoleKey);
                                ?>

                                <?php if ($isApprovalStage): ?>
                                <?php
                                    $approvalStatusText = strtolower(trim((string)($step['approval_status'] ?? '')));
                                    $approvalRejected = in_array($approvalStatusText, ['rejected', 'correction_requested'], true);
                                    $approvalBoxClass = $approvalDone ? 'success' : ($approvalRejected ? 'denied' : '');
                                ?>
                                <div class="col-12">
                                    <div class="approval-box <?= e($approvalBoxClass) ?>">
                                        <strong>Customer Approval:
                                            <?= $approvalDone ? 'Approved' : ($approvalRejected ? 'Denied / Not Approved' : 'Pending') ?></strong>
                                        <div class="small mt-1">
                                            <?php if ($approvalDone): ?>
                                            <?php if ((int)($step['approved_by_customer'] ?? 0) === 1): ?>
                                            Approved by customer link. This approval stage is completed automatically;
                                            no manual status update is required.
                                            <?php elseif ((int)($step['approved_by_call'] ?? 0) === 1): ?>
                                            Manually approved by
                                            call<?= !empty($step['call_confirmed_by_name']) ? ' by ' . e($step['call_confirmed_by_name']) : '' ?>.
                                            <?php endif; ?>
                                            <?= !empty($step['approved_at']) ? ' Approval time: ' . e(jcvDateTime($step['approved_at'])) : '' ?>
                                            <?php elseif ($approvalRejected): ?>
                                            Customer denied / requested correction. This approval stage is locked and
                                            the next stage will not open until approval is received.
                                            <?php if (!empty($step['customer_remarks'])): ?>
                                            <br><strong>Customer Remark:</strong> <?= e($step['customer_remarks']) ?>
                                            <?php endif; ?>
                                            <?php else: ?>
                                            Waiting for customer approval. If the customer does not approve through the
                                            link but confirms directly,
                                            use the manual approval checkbox below to complete this stage.
                                            <?php endif; ?>
                                        </div>

                                        <?php if (!empty($step['approval_id'])): ?>
                                        <div class="approval-grid">
                                            <div class="approval-mini">
                                                <small>ID</small><strong><?= e($step['approval_id']) ?></strong>
                                            </div>
                                            <div class="approval-mini"><small>Job Card
                                                    ID</small><strong><?= e($step['approval_job_card_id'] ?? '-') ?></strong>
                                            </div>
                                            <div class="approval-mini"><small>Workflow Step
                                                    ID</small><strong><?= e($step['approval_workflow_step_id'] ?? '-') ?></strong>
                                            </div>
                                            <div class="approval-mini"><small>Approval
                                                    Type</small><strong><?= e($step['approval_type'] ?? '-') ?></strong>
                                            </div>
                                            <div class="approval-mini"><small>Approval
                                                    Token</small><span><?= e($step['approval_token'] ?? '-') ?></span>
                                            </div>
                                            <div class="approval-mini"><small>Customer
                                                    Name</small><strong><?= e($step['approval_customer_name'] ?? '-') ?></strong>
                                            </div>
                                            <div class="approval-mini">
                                                <small>Mobile</small><strong><?= e($step['approval_mobile'] ?? '-') ?></strong>
                                            </div>
                                            <div class="approval-mini">
                                                <small>Status</small><strong><?= e($step['approval_status'] ?? '-') ?></strong>
                                            </div>
                                            <div class="approval-mini"><small>Approved By
                                                    Customer</small><strong><?= ((int)($step['approved_by_customer'] ?? 0) === 1) ? 'Yes' : 'No' ?></strong>
                                            </div>
                                            <div class="approval-mini"><small>Approved By
                                                    Call</small><strong><?= ((int)($step['approved_by_call'] ?? 0) === 1) ? 'Yes' : 'No' ?></strong>
                                            </div>
                                            <div class="approval-mini"><small>Call Confirmed
                                                    By</small><strong><?= e($step['call_confirmed_by_name'] ?? ($step['call_confirmed_by'] ?? '-')) ?></strong>
                                            </div>
                                            <div class="approval-mini"><small>Approved
                                                    At</small><strong><?= e(jcvDateTime($step['approved_at'] ?? null)) ?></strong>
                                            </div>
                                            <div class="approval-mini"><small>Rejected
                                                    At</small><strong><?= e(jcvDateTime($step['rejected_at'] ?? null)) ?></strong>
                                            </div>
                                            <div class="approval-mini"><small>Expires
                                                    At</small><strong><?= e(jcvDateTime($step['expires_at'] ?? null)) ?></strong>
                                            </div>
                                            <div class="approval-mini"><small>IP
                                                    Address</small><strong><?= e($step['ip_address'] ?? '-') ?></strong>
                                            </div>
                                            <div class="approval-mini"><small>Link Sent
                                                    At</small><strong><?= e(jcvDateTime($step['link_sent_at'] ?? null)) ?></strong>
                                            </div>
                                            <div class="approval-mini"><small>Link Sent
                                                    By</small><strong><?= e($step['link_sent_by_name'] ?? ($step['link_sent_by'] ?? '-')) ?></strong>
                                            </div>
                                            <div class="approval-mini"><small>Created
                                                    At</small><strong><?= e(jcvDateTime($step['approval_created_at'] ?? null)) ?></strong>
                                            </div>
                                            <div class="approval-mini"><small>Updated
                                                    At</small><strong><?= e(jcvDateTime($step['approval_updated_at'] ?? null)) ?></strong>
                                            </div>
                                            <div class="approval-mini"><small>User
                                                    Agent</small><span><?= e($step['user_agent'] ?? '-') ?></span></div>
                                        </div>
                                        <?php endif; ?>

                                        <?php if (!empty($step['customer_remarks'])): ?>
                                        <div class="small mt-2"><strong>Customer Remarks:</strong>
                                            <?= e($step['customer_remarks']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($step['internal_remarks'])): ?>
                                        <div class="small mt-1"><strong>Internal / Manual Approval Remarks:</strong>
                                            <?= e($step['internal_remarks']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php
                                    $stepPhotos = $trackingPhotosById[(int)$step['id']] ?? [];
                                    $photoApproval = null;
                                    $photoApprovalStatus = 'pending';
                                    $canSendPhotoApprovalApi = false;
                                    if ($isDesignProofingStage && $stepPhotos) {
                                        $photoApproval = jcvGetOrCreatePhotoApproval(
                                            $conn,
                                            $jobId,
                                            (int)$step['id'],
                                            (int)$step['workflow_step_id'],
                                            (string)($job['customer_name'] ?? ''),
                                            (string)($job['mobile'] ?? '')
                                        );
                                        if ($photoApproval) {
                                            $photoApprovalStatus = strtolower(trim((string)($photoApproval['status'] ?? 'pending')));
                                            $canSendPhotoApprovalApi = $photoApprovalStatus === 'pending' && trim((string)($job['mobile'] ?? '')) !== '' && $canUpdateJob;
                                        }
                                    }
                                ?>
                                <?php if ($isDesignProofingStage && $stepPhotos): ?>
                                <?php
                                    $photoStatusLabel = $photoApproval ? ucwords(str_replace('_', ' ', $photoApprovalStatus)) : 'No Approval Link';
                                    $photoStatusClass = in_array($photoApprovalStatus, ['approved','rejected','expired','pending'], true) ? $photoApprovalStatus : 'pending';
                                    $canCancelUploadedPhoto = $canUpdateJob;
                                ?>
                                <div class="col-12">
                                    <div class="photo-approval-card">
                                        <div
                                            class="d-flex flex-column flex-md-row justify-content-between gap-3 align-items-md-center">
                                            <div class="photo-approval-head">
                                                <small class="text-muted-custom fw-bold text-uppercase">Uploaded Design
                                                    / Proofing Files</small>
                                                <strong><?= count($stepPhotos) ?> file(s) uploaded</strong>
                                                <div class="photo-status-row">
                                                    <span class="photo-status-chip <?= e($photoStatusClass) ?>">
                                                        <?= $photoStatusClass === 'approved' ? '✓' : ($photoStatusClass === 'rejected' ? '!' : ($photoStatusClass === 'expired' ? '×' : '⏱')) ?>
                                                        <?= e($photoStatusLabel) ?>
                                                    </span>
                                                    <?php if ($photoApproval && !empty($photoApproval['link_sent_at'])): ?>
                                                    <span class="text-muted-custom small fw-bold">Sent:
                                                        <?= e(jcvDateTime($photoApproval['link_sent_at'])) ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($photoStatusClass === 'approved'): ?>
                                                    <span class="text-success small fw-bold">Customer approved this
                                                        proof/design.</span>
                                                    <?php elseif ($photoStatusClass === 'rejected'): ?>
                                                    <span class="text-danger small fw-bold">Customer rejected this
                                                        proof/design. Upload corrected copy.</span>
                                                    <?php elseif ($photoStatusClass === 'expired'): ?>
                                                    <span class="text-danger small fw-bold">This approval link is
                                                        cancelled/expired. Upload fresh copy.</span>
                                                    <?php else: ?>
                                                    <span class="text-muted-custom small fw-bold">Waiting for customer
                                                        approval.</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <div class="photo-action-wrap">
                                                <?php if ($canSendPhotoApprovalApi): ?>
                                                <form method="post" class="m-0">
                                                    <input type="hidden" name="action" value="send_photo_approval_api">
                                                    <input type="hidden" name="tracking_id"
                                                        value="<?= (int)$step['id'] ?>">
                                                    <button type="submit"
                                                        class="btn btn-sm wa-photo-btn rounded-pill px-3 fw-bold">
                                                        Send WhatsApp Approval Link
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="tracking-photo-list">
                                            <?php foreach ($stepPhotos as $photo): ?>
                                            <div class="tracking-photo-item">
                                                <?php if (jcvTrackingFileIsPdf($photo)): ?>
                                                <a href="<?= e($photo['file_path'] ?? '#') ?>" target="_blank"
                                                    rel="noopener" class="tracking-pdf-link">
                                                    <div class="tracking-pdf-thumb">
                                                        <span class="tracking-pdf-icon">PDF</span>
                                                        <small><?= e($photo['original_name'] ?? 'Proofing PDF') ?></small>
                                                    </div>
                                                </a>
                                                <?php else: ?>
                                                <a href="<?= e($photo['file_path'] ?? '#') ?>" target="_blank"
                                                    rel="noopener">
                                                    <img src="<?= e($photo['file_path'] ?? '') ?>"
                                                        class="tracking-photo-thumb" alt="Tracking photo">
                                                </a>
                                                <?php endif; ?>
                                                <?php if ($canCancelUploadedPhoto): ?>
                                                <form method="post" class="photo-cancel-form"
                                                    onsubmit="return confirm('Cancel this uploaded proof/design photo? If this is the last photo, the customer approval link will expire and this stage will move back for fresh upload.');">
                                                    <input type="hidden" name="action" value="cancel_tracking_photo">
                                                    <input type="hidden" name="tracking_id"
                                                        value="<?= (int)$step['id'] ?>">
                                                    <input type="hidden" name="photo_id"
                                                        value="<?= (int)($photo['id'] ?? 0) ?>">
                                                    <button type="submit" class="photo-cancel-btn">Cancel</button>
                                                </form>
                                                <?php endif; ?>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if ($canOpenUpdateForm): ?>
                                <div class="col-12">
                                    <div class="collapse" id="updateStage<?= (int)$step['id'] ?>">
                                        <form method="post" enctype="multipart/form-data"
                                            class="info-card mt-2 stage-update-form compact-update-form"
                                            data-approval-stage="<?= $isApprovalStage ? '1' : '0' ?>"
                                            data-approval-done="<?= $approvalDone ? '1' : '0' ?>"
                                            data-can-manual-approval="<?= $canManualCustomerApproval ? '1' : '0' ?>"
                                            data-design-photo-required="<?= $requiresDesignPhotoUpload ? '1' : '0' ?>"
                                            data-has-existing-photos="<?= !empty($stepPhotos) ? '1' : '0' ?>">
                                            <input type="hidden" name="action" value="update_step_status">
                                            <input type="hidden" name="tracking_id" value="<?= (int)$step['id'] ?>">

                                            <?php if ($dispatchPaymentPending): ?>
                                            <div class="alert alert-warning rounded-4 fw-bold mb-3">
                                                <?php if ($isProductionTeamView): ?>
                                                Payment Pending: Dispatch cannot be completed until the customer payment
                                                is cleared.
                                                <?php else: ?>
                                                Payment Pending: Balance
                                                <?= e(jcvMoney($paymentSnapshot['balance_amount'] ?? 0)) ?> must be
                                                collected before completing Dispatch.
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>

                                            <div class="row g-2 align-items-end">
                                                <div class="col-lg-2 col-md-3">
                                                    <label class="form-label fw-bold">Update Status <span
                                                            class="required-star">*</span></label>
                                                    <select name="status" class="form-select js-stage-status" required>
                                                        <option value="">Select Status</option>
                                                        <option value="pending"
                                                            <?= $stepStatus === 'pending' ? 'selected' : '' ?>>Pending
                                                        </option>
                                                        <option value="in_progress"
                                                            <?= $stepStatus === 'in_progress' ? 'selected' : '' ?>>In
                                                            Progress</option>
                                                        <option value="completed"
                                                            <?= $stepStatus === 'completed' ? 'selected' : '' ?>
                                                            <?= $dispatchPaymentPending ? 'disabled' : '' ?>>
                                                            Completed<?= $dispatchPaymentPending ? ' (Payment Pending)' : '' ?>
                                                        </option>
                                                        <option value="delayed"
                                                            <?= $stepStatus === 'delayed' ? 'selected' : '' ?>>Delayed
                                                        </option>
                                                        <option value="skipped"
                                                            <?= $stepStatus === 'skipped' ? 'selected' : '' ?>>Skipped
                                                        </option>
                                                        <option value="cancelled"
                                                            <?= $stepStatus === 'cancelled' ? 'selected' : '' ?>>
                                                            Cancelled</option>
                                                    </select>
                                                </div>

                                                <div class="col-lg-2 col-md-3 delay-field">
                                                    <label class="form-label fw-bold">Delay Reason <span
                                                            class="required-star">*</span></label>
                                                    <select name="delay_reason_id" class="form-select">
                                                        <option value="">Select Reason</option>
                                                        <?php foreach ($delayReasons as $reason): ?>
                                                        <option value="<?= (int)$reason['id'] ?>"
                                                            <?= (int)($step['delay_reason_id'] ?? 0) === (int)$reason['id'] ? 'selected' : '' ?>>
                                                            <?= e($reason['reason_name']) ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div class="col-lg-2 col-md-3 delay-field">
                                                    <label class="form-label fw-bold">Delay Days <span
                                                            class="required-star">*</span></label>
                                                    <input type="number" name="delay_days" class="form-control" min="0"
                                                        value="<?= e($step['delay_days'] ?? 0) ?>">
                                                </div>

                                                <div class="col-lg-4 col-md-6">
                                                    <label class="form-label fw-bold">Remark <span
                                                            class="required-star js-remark-star d-none">*</span></label>
                                                    <textarea name="remarks" class="form-control js-remark" rows="2"
                                                        placeholder="Enter update remark"><?= e($step['remarks'] ?? '') ?></textarea>
                                                </div>

                                                <?php if ($requiresDesignPhotoUpload): ?>
                                                <div class="col-12 design-photo-upload-field">
                                                    <div class="design-photo-box">
                                                        <div class="design-photo-title">
                                                            <strong>Designing / Proofing Files <span
                                                                    class="required-star">*</span></strong>
                                                            <button type="button"
                                                                class="btn btn-sm btn-outline-primary rounded-pill fw-bold js-add-photo-input">
                                                                + Add More File
                                                            </button>
                                                        </div>
                                                        <div class="js-photo-input-list">
                                                            <div class="photo-input-row">
                                                                <input type="file" name="tracking_photos[]"
                                                                    class="form-control js-tracking-photos"
                                                                    accept="image/jpeg,image/png,image/webp,image/gif,application/pdf"
                                                                    data-required-on-completed="1">
                                                                <button type="button"
                                                                    class="btn btn-outline-danger photo-input-remove js-remove-photo-input"
                                                                    title="Remove image">×</button>
                                                            </div>
                                                        </div>
                                                        <div class="photo-help-text">
                                                            Proof/design file upload is required only when this stage is
                                                            marked
                                                            Completed. Pending / In Progress / Delayed updates do not
                                                            require files. Allowed: JPG, PNG, WEBP, GIF, PDF.
                                                        </div>
                                                    </div>
                                                </div>
                                                <?php endif; ?>

                                                <?php if ($isApprovalStage && !$approvalDone): ?>
                                                <div class="col-12 approval-field">
                                                    <?php if ($canManualCustomerApproval): ?>
                                                    <div class="approval-box">
                                                        <div class="form-check mb-3">
                                                            <input class="form-check-input js-manual-approval"
                                                                type="checkbox" name="manual_customer_approved"
                                                                value="1" id="manualApproval<?= (int)$step['id'] ?>">
                                                            <label class="form-check-label fw-bold"
                                                                for="manualApproval<?= (int)$step['id'] ?>">
                                                                Customer has NOT approved online, but confirmed by call
                                                                / direct confirmation
                                                                <span class="required-star">*</span>
                                                            </label>
                                                        </div>

                                                        <label class="form-label fw-bold">
                                                            Approval Remark <span class="required-star">*</span>
                                                        </label>
                                                        <textarea name="approval_remarks"
                                                            class="form-control js-approval-remarks" rows="2"
                                                            placeholder="Example: Customer confirmed proof/design approval by phone call"><?= e($step['internal_remarks'] ?? '') ?></textarea>
                                                    </div>
                                                    <?php else: ?>
                                                    <div class="approval-box">
                                                        Customer approval is pending. An authorised Admin / Sales /
                                                        Designing user
                                                        must receive direct customer confirmation before completing this
                                                        stage.
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                                <?php endif; ?>

                                                <div class="col-12 text-end">
                                                    <button type="submit"
                                                        class="btn btn-success rounded-pill px-4 fw-bold">
                                                        Save Update
                                                    </button>
                                                </div>
                                            </div>

                                            <small class="text-muted-custom d-block mt-2">
                                                Delay status requires delay reason and remark. Designing / Proofing
                                                photos are required only when the production stage is marked Completed.
                                                Proofing Approval / Design Approval does not need photo upload; it needs
                                                customer approval or Admin/Sales manual confirmation.
                                            </small>
                                        </form>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <?php endif; ?>
            </section>
        </main>

        <div id="settingsOverlay"></div>
        <?php include __DIR__ . '/includes/rightsidebar.php'; ?>
    </div>

    <?php include __DIR__ . '/includes/script.php'; ?>

    <script>
    (function() {
        function showActionToast(message, type, title) {
            type = type || 'success';
            title = title || (type === 'success' ? 'Success' : 'Info');

            let container = document.querySelector('.toast-container');
            if (!container) {
                container = document.createElement('div');
                container.className = 'toast-container position-fixed top-0 end-0 p-3';
                container.style.zIndex = '12000';
                document.body.appendChild(container);
            }

            const toast = document.createElement('div');
            toast.className = 'toast toast-ui ' + type;
            toast.setAttribute('role', 'alert');
            toast.setAttribute('aria-live', 'assertive');
            toast.setAttribute('aria-atomic', 'true');
            toast.setAttribute('data-bs-delay', '4200');
            toast.innerHTML =
                '<div class="d-flex"><div class="toast-body"><div class="toast-title"></div><div class="toast-message"></div></div><button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>';
            toast.querySelector('.toast-title').textContent = title;
            toast.querySelector('.toast-message').textContent = message;
            container.appendChild(toast);

            if (window.bootstrap && bootstrap.Toast) {
                const instance = bootstrap.Toast.getOrCreateInstance(toast);
                instance.show();
                toast.addEventListener('hidden.bs.toast', function() {
                    toast.remove();
                });
            } else {
                setTimeout(function() {
                    toast.remove();
                }, 4200);
            }
        }

        const pageToastEl = document.getElementById('pageToast');
        if (pageToastEl && window.bootstrap && bootstrap.Toast) {
            bootstrap.Toast.getOrCreateInstance(pageToastEl).show();
        }

        function refreshDelayFields(form) {
            const select = form.querySelector('.js-stage-status');
            const remark = form.querySelector('.js-remark');
            const remarkStar = form.querySelector('.js-remark-star');
            const delayReason = form.querySelector('select[name="delay_reason_id"]');
            const delayDays = form.querySelector('input[name="delay_days"]');
            const approvalRemarks = form.querySelector('.js-approval-remarks');
            const manualApproval = form.querySelector('.js-manual-approval');
            const photoInput = form.querySelector('.js-tracking-photos');

            if (!select) return;

            if (select.value === 'delayed') {
                form.classList.add('is-delay');
                if (remark) remark.setAttribute('required', 'required');
                if (remarkStar) remarkStar.classList.remove('d-none');
                if (delayReason) delayReason.setAttribute('required', 'required');
                if (delayDays) delayDays.setAttribute('required', 'required');
            } else {
                form.classList.remove('is-delay');
                if (delayReason) delayReason.removeAttribute('required');
                if (delayDays) delayDays.removeAttribute('required');
            }

            const approvalStage = form.dataset.approvalStage === '1';
            const approvalDone = form.dataset.approvalDone === '1';
            const canManualApproval = form.dataset.canManualApproval === '1';
            const needsApproval = approvalStage && !approvalDone && select.value === 'completed';

            if (needsApproval) {
                form.classList.add('needs-approval');
                if (canManualApproval) {
                    if (manualApproval) manualApproval.setAttribute('required', 'required');
                    if (approvalRemarks) approvalRemarks.setAttribute('required', 'required');
                }
            } else {
                form.classList.remove('needs-approval');
                if (manualApproval) manualApproval.removeAttribute('required');
                if (approvalRemarks) approvalRemarks.removeAttribute('required');
            }

            if (select.value !== 'delayed' && !needsApproval) {
                if (remark) remark.removeAttribute('required');
                if (remarkStar) remarkStar.classList.add('d-none');
            }

            const photoRequiredForCompleted = form.dataset.designPhotoRequired === '1' && select.value ===
                'completed';
            const hasExistingPhotos = form.dataset.hasExistingPhotos === '1';
            form.classList.toggle('needs-photo', photoRequiredForCompleted);

            form.querySelectorAll('.js-tracking-photos').forEach(function(input, index) {
                if (photoRequiredForCompleted && (!hasExistingPhotos || index === 0)) {
                    input.setAttribute('required', 'required');
                } else {
                    input.removeAttribute('required');
                }
            });
        }

        document.querySelectorAll('.stage-update-form').forEach(function(form) {
            refreshDelayFields(form);

            const select = form.querySelector('.js-stage-status');
            if (select) {
                select.addEventListener('change', function() {
                    refreshDelayFields(form);
                });
            }
        });

        document.querySelectorAll('.js-locked-stage').forEach(function(btn) {
            btn.addEventListener('click', function() {
                showActionToast(btn.dataset.message ||
                    'Previous stage must be completed before updating this stage.', 'warning',
                    'Stage Locked');
            });
        });


        document.querySelectorAll('.js-add-photo-input').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const form = btn.closest('form');
                const list = form ? form.querySelector('.js-photo-input-list') : null;
                if (!list) return;

                const row = document.createElement('div');
                row.className = 'photo-input-row';
                row.innerHTML =
                    '<input type="file" name="tracking_photos[]" class="form-control js-tracking-photos" accept="image/jpeg,image/png,image/webp,image/gif,application/pdf"><button type="button" class="btn btn-outline-danger photo-input-remove js-remove-photo-input" title="Remove file">×</button>';
                list.appendChild(row);
                refreshDelayFields(form);
            });
        });

        document.addEventListener('click', function(event) {
            const btn = event.target.closest('.js-remove-photo-input');
            if (!btn) return;
            const list = btn.closest('.js-photo-input-list');
            const row = btn.closest('.photo-input-row');
            if (!list || !row) return;
            if (list.querySelectorAll('.photo-input-row').length <= 1) {
                const input = row.querySelector('input[type="file"]');
                if (input) input.value = '';
                return;
            }
            row.remove();
        });

        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    })();
    </script>
</body>

</html>