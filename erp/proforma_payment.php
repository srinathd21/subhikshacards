<?php
/**
 * proforma_payment.php
 * Separate balance payment collection page with excess-payment return and cancel/revert options.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'proforma_bills.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function pp_table_exists(mysqli $conn, string $table): bool
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

function pp_col_exists(mysqli $conn, string $table, string $col): bool
{
    static $cache = [];
    $key = $table . '.' . $col;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $tableEsc = $conn->real_escape_string($table);
        $colEsc = $conn->real_escape_string($col);
        $res = $conn->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$colEsc}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) $res->free();
        return $cache[$key] = $ok;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function pp_ensure_payment_cancel_columns(mysqli $conn): void
{
    if (!pp_table_exists($conn, 'payments')) return;
    $alters = [];
    if (!pp_col_exists($conn, 'payments', 'is_cancelled')) $alters[] = "ADD COLUMN `is_cancelled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `received_by`";
    if (!pp_col_exists($conn, 'payments', 'cancelled_at')) $alters[] = "ADD COLUMN `cancelled_at` DATETIME DEFAULT NULL AFTER `is_cancelled`";
    if (!pp_col_exists($conn, 'payments', 'cancelled_by')) $alters[] = "ADD COLUMN `cancelled_by` BIGINT(20) UNSIGNED DEFAULT NULL AFTER `cancelled_at`";
    if (!pp_col_exists($conn, 'payments', 'cancel_reason')) $alters[] = "ADD COLUMN `cancel_reason` TEXT DEFAULT NULL AFTER `cancelled_by`";
    if ($alters) {
        $conn->query("ALTER TABLE `payments` " . implode(', ', $alters));
    }
}

function pp_ensure_payment_return_columns(mysqli $conn): void
{
    if (!pp_table_exists($conn, 'payments')) return;

    $alters = [];
    if (!pp_col_exists($conn, 'payments', 'tendered_amount')) {
        $alters[] = "ADD COLUMN `tendered_amount` DECIMAL(12,2) DEFAULT NULL AFTER `amount`";
    }
    if (!pp_col_exists($conn, 'payments', 'return_amount')) {
        $after = pp_col_exists($conn, 'payments', 'tendered_amount')
            ? 'tendered_amount'
            : 'amount';
        $alters[] = "ADD COLUMN `return_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `{$after}`";
    }

    if ($alters) {
        $conn->query("ALTER TABLE `payments` " . implode(', ', $alters));
    }
}

function pp_payment_return_columns_ready(mysqli $conn): bool
{
    try {
        $res = $conn->query("
            SELECT COUNT(*) AS total
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'payments'
              AND COLUMN_NAME IN ('tendered_amount', 'return_amount')
        ");
        $row = $res ? $res->fetch_assoc() : null;
        if ($res) $res->free();
        return (int)($row['total'] ?? 0) === 2;
    } catch (Throwable $e) {
        return false;
    }
}


function pp_ensure_cash_denomination_table(mysqli $conn): void
{
    /* Use the common column name `amount` for denomination amount. */
    $conn->query("
        CREATE TABLE IF NOT EXISTS payment_cash_denominations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            payment_id INT NOT NULL,
            denomination_type ENUM('note','coin') NOT NULL,
            denomination_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            denomination_count INT UNSIGNED NOT NULL DEFAULT 0,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_by INT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_payment_cash_payment_id (payment_id),
            KEY idx_payment_cash_type_value (payment_id, denomination_type, denomination_value)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (!pp_col_exists($conn, 'payment_cash_denominations', 'amount')) {
        $conn->query("ALTER TABLE payment_cash_denominations ADD COLUMN amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER denomination_count");
    }

    if (!pp_col_exists($conn, 'payment_cash_denominations', 'created_by')) {
        $conn->query("ALTER TABLE payment_cash_denominations ADD COLUMN created_by INT DEFAULT NULL AFTER amount");
    }

}

function pp_cash_denomination_master(): array
{
    return [
        ['field' => 'cash_note_500', 'type' => 'note', 'value' => 500],
        ['field' => 'cash_note_200', 'type' => 'note', 'value' => 200],
        ['field' => 'cash_note_100', 'type' => 'note', 'value' => 100],
        ['field' => 'cash_note_50',  'type' => 'note', 'value' => 50],
        ['field' => 'cash_note_20',  'type' => 'note', 'value' => 20],
        ['field' => 'cash_note_10',  'type' => 'note', 'value' => 10],
        ['field' => 'cash_coin_20',  'type' => 'coin', 'value' => 20],
        ['field' => 'cash_coin_10',  'type' => 'coin', 'value' => 10],
        ['field' => 'cash_coin_5',   'type' => 'coin', 'value' => 5],
        ['field' => 'cash_coin_2',   'type' => 'coin', 'value' => 2],
        ['field' => 'cash_coin_1',   'type' => 'coin', 'value' => 1],
    ];
}

function pp_cash_denomination_payload(): array
{
    $rows = [];
    $total = 0.0;

    foreach (pp_cash_denomination_master() as $meta) {
        $count = max(0, (int)($_POST[$meta['field']] ?? 0));
        $value = (float)$meta['value'];
        $amount = round($count * $value, 2);
        $total += $amount;

        /* Store every denomination row, including zero-count rows. */
        $rows[] = [
            'type' => (string)$meta['type'],
            'value' => $value,
            'count' => $count,
            'amount' => $amount,
        ];
    }

    return [
        'rows' => $rows,
        'total' => round($total, 2),
    ];
}

function pp_save_cash_denominations(mysqli $conn, int $paymentId, array $payload): void
{
    if ($paymentId <= 0) return;

    /* Avoid DDL inside an active payment transaction when the table is already ready. */
    if (!pp_table_exists($conn, 'payment_cash_denominations')
        || !pp_col_exists($conn, 'payment_cash_denominations', 'amount')
        || !pp_col_exists($conn, 'payment_cash_denominations', 'created_by')) {
        pp_ensure_cash_denomination_table($conn);
    }

    $stmt = $conn->prepare("DELETE FROM payment_cash_denominations WHERE payment_id = ?");
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("
        INSERT INTO payment_cash_denominations
            (payment_id, denomination_type, denomination_value, denomination_count, amount, created_by, created_at)
        VALUES
            (?, ?, ?, ?, ?, ?, NOW())
    ");

    $userId = (int)($_SESSION['user_id'] ?? 0);

    foreach (($payload['rows'] ?? []) as $row) {
        $type = (string)($row['type'] ?? 'note');
        $value = (float)($row['value'] ?? 0);
        $count = max(0, (int)($row['count'] ?? 0));
        $amount = round($count * $value, 2);
        $stmt->bind_param('isdidi', $paymentId, $type, $value, $count, $amount, $userId);
        $stmt->execute();
    }

    $stmt->close();
}

function pp_money($value): string
{
    return '₹' . number_format((float)$value, 2);
}

function pp_date($value): string
{
    return !empty($value) ? date('d-m-Y', strtotime((string)$value)) : '-';
}

function pp_datetime($value): string
{
    return !empty($value) ? date('d-m-Y h:i A', strtotime((string)$value)) : '-';
}

function pp_next_no(mysqli $conn): string
{
    $prefix = 'PAY-' . date('ymd') . '-';
    try {
        $like = $prefix . '%';
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM payments WHERE payment_no LIKE ?");
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $prefix . str_pad((string)(((int)($row['total'] ?? 0)) + 1), 4, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        return $prefix . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
    }
}

function pp_check_csrf(): void
{
    if (empty($_POST['csrf_token']) || empty($_SESSION['payment_csrf']) || !hash_equals($_SESSION['payment_csrf'], (string)$_POST['csrf_token'])) {
        http_response_code(400);
        die('Invalid CSRF token.');
    }
}

function pp_redirect(int $id, string $msg = '', string $err = '', array $extra = []): void
{
    $q = ['id' => $id];
    if ($msg !== '') $q['msg'] = $msg;
    if ($err !== '') $q['err'] = $err;
    foreach ($extra as $key => $value) {
        if ($value !== null && $value !== '') {
            $q[$key] = (string)$value;
        }
    }
    header('Location: proforma_payment.php?' . http_build_query($q));
    exit;
}

function pp_can_update_payment(mysqli $conn): bool
{
    if (function_exists('is_admin_user') && is_admin_user()) return true;
    if (function_exists('can_update')) {
        try {
            return can_update($conn, 'proforma_bills.php');
        } catch (Throwable $e) {
            return false;
        }
    }
    return true;
}

function pp_update_bill_and_job_amounts(mysqli $conn, int $proformaId, float $newAdvance): void
{
    $stmt = $conn->prepare("SELECT final_amount FROM proforma_bills WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $proformaId);
    $stmt->execute();
    $bill = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$bill) {
        throw new RuntimeException('Proforma bill not found.');
    }

    $finalAmount = (float)$bill['final_amount'];
    if ($newAdvance < 0) $newAdvance = 0;
    if ($newAdvance > $finalAmount) $newAdvance = $finalAmount;
    $newBalance = max(0, $finalAmount - $newAdvance);
    $userId = (int)($_SESSION['user_id'] ?? 0);

    $stmt = $conn->prepare("UPDATE proforma_bills SET advance_amount = ?, balance_amount = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('ddii', $newAdvance, $newBalance, $userId, $proformaId);
    $stmt->execute();
    $stmt->close();

    if (pp_table_exists($conn, 'job_cards')) {
        $stmt = $conn->prepare("UPDATE job_cards SET advance_amount = ?, balance_amount = ?, updated_by = ?, updated_at = NOW() WHERE proforma_bill_id = ?");
        $stmt->bind_param('ddii', $newAdvance, $newBalance, $userId, $proformaId);
        $stmt->execute();
        $stmt->close();
    }
}




/**
 * Read the authoritative active payment total for a Proforma.
 *
 * If payment rows exist, payments.amount is the source of truth.
 * If this is a legacy bill with no payment rows at all, fall back to the
 * stored proforma_bills.advance_amount value.
 */
function pp_payment_totals_from_db(mysqli $conn, int $proformaId): array
{
    $stmt = $conn->prepare("
        SELECT final_amount, advance_amount
        FROM proforma_bills
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $proformaId);
    $stmt->execute();
    $bill = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$bill) {
        throw new RuntimeException('Proforma bill not found.');
    }

    $finalAmount = max(0, (float)($bill['final_amount'] ?? 0));
    $storedAdvance = max(0, (float)($bill['advance_amount'] ?? 0));
    $paymentCount = 0;
    $activePaid = 0.0;

    if (pp_table_exists($conn, 'payments')) {
        $cancelExpr = pp_col_exists($conn, 'payments', 'is_cancelled')
            ? "CASE WHEN COALESCE(is_cancelled, 0) = 0 THEN amount ELSE 0 END"
            : "amount";

        $stmt = $conn->prepare("
            SELECT
                COUNT(*) AS payment_count,
                COALESCE(SUM({$cancelExpr}), 0) AS active_paid
            FROM payments
            WHERE proforma_bill_id = ?
        ");
        $stmt->bind_param('i', $proformaId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $paymentCount = (int)($row['payment_count'] ?? 0);
        $activePaid = max(0, (float)($row['active_paid'] ?? 0));
    }

    /*
     * New/current bills normally have payment rows for the advance.
     * Legacy bills may have only advance_amount stored on proforma_bills.
     */
    $paidAmount = $paymentCount > 0 ? $activePaid : $storedAdvance;
    $paidAmount = min($paidAmount, $finalAmount);
    $balanceAmount = max(0, $finalAmount - $paidAmount);

    return [
        'final_amount' => round($finalAmount, 2),
        'paid_amount' => round($paidAmount, 2),
        'balance_amount' => round($balanceAmount, 2),
        'payment_count' => $paymentCount,
    ];
}

/**
 * Synchronize Proforma + every linked Job Card from actual active payments.
 *
 * This fixes stale advance_amount/balance_amount values while preserving the
 * existing payment rows and payment workflow.
 */
function pp_sync_bill_and_job_amounts_from_payments(mysqli $conn, int $proformaId): array
{
    $totals = pp_payment_totals_from_db($conn, $proformaId);

    $paidAmount = (float)$totals['paid_amount'];
    $balanceAmount = (float)$totals['balance_amount'];
    $userId = (int)($_SESSION['user_id'] ?? 0);

    $stmt = $conn->prepare("
        UPDATE proforma_bills
        SET
            advance_amount = ?,
            balance_amount = ?,
            updated_by = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param('ddii', $paidAmount, $balanceAmount, $userId, $proformaId);
    $stmt->execute();
    $stmt->close();

    if (pp_table_exists($conn, 'job_cards')) {
        $stmt = $conn->prepare("
            UPDATE job_cards
            SET
                advance_amount = ?,
                balance_amount = ?,
                updated_by = ?,
                updated_at = NOW()
            WHERE proforma_bill_id = ?
        ");
        $stmt->bind_param('ddii', $paidAmount, $balanceAmount, $userId, $proformaId);
        $stmt->execute();
        $stmt->close();
    }

    return $totals;
}


function pp_payment_base_url(mysqli $conn): string
{
    try {
        if (pp_table_exists($conn, 'system_settings')) {
            $stmt = $conn->prepare("
                SELECT setting_value
                FROM system_settings
                WHERE setting_key IN ('site_url','base_url','app_url')
                  AND TRIM(setting_value) <> ''
                ORDER BY FIELD(setting_key,'site_url','base_url','app_url')
                LIMIT 1
            ");
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $configured = trim((string)($row['setting_value'] ?? ''));
            if ($configured !== '') {
                return rtrim($configured, '/');
            }
        }
    } catch (Throwable $e) {
        // Fall back to the current request URL below.
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

    return rtrim($scheme . '://' . $host . ($scriptDir === '' || $scriptDir === '/' ? '' : $scriptDir), '/');
}

function pp_payment_proforma_pdf_url(mysqli $conn, int $proformaId): string
{
    if ($proformaId <= 0) {
        return '';
    }

    return pp_payment_base_url($conn)
        . '/proforma_bill_pdf.php?id=' . $proformaId
        . '&public=1&download=1';
}

function pp_payment_whatsapp_money($value): string
{
    return number_format((float)$value, 2, '.', '');
}

function pp_payment_whatsapp_api_ready(): bool
{
    $apiFile = __DIR__ . '/includes/whatsapp-api.php';
    if (!file_exists($apiFile)) {
        return false;
    }

    require_once $apiFile;
    return function_exists('subhiksha_send_whatsapp') || function_exists('subhiksha_send_template_whatsapp');
}


function pp_split_payment_meta(string $remarks): array
{
    $remarks = trim($remarks);
    if ($remarks === '') return [];

    if (!preg_match('/\\[SPLIT_META:GROUP=([A-Za-z0-9_-]+);PART=([12]);CASH=([0-9.]+);UPI=([0-9.]+);RETURN=([0-9.]+)\\]/', $remarks, $m)) {
        return [];
    }

    return [
        'group' => (string)$m[1],
        'part' => (int)$m[2],
        'cash' => (float)$m[3],
        'upi' => (float)$m[4],
        'return' => (float)$m[5],
    ];
}

function pp_clean_payment_remarks($remarks): string
{
    $remarks = (string)$remarks;
    $remarks = preg_replace('/\\s*\\[SPLIT_META:[^\\]]+\\]\\s*/', ' ', $remarks);
    return trim(preg_replace('/\\s{2,}/', ' ', (string)$remarks));
}

function pp_payment_whatsapp_row(mysqli $conn, int $proformaId, int $paymentId): ?array
{
    if ($proformaId <= 0 || $paymentId <= 0) {
        return null;
    }

    try {
        $stmt = $conn->prepare("
            SELECT
                p.*,
                pb.proforma_no,
                pb.customer_name,
                pb.mobile,
                pb.customer_id AS bill_customer_id,
                pb.final_amount,
                pb.advance_amount,
                pb.balance_amount,
                COALESCE((
                    SELECT SUM(p2.amount)
                    FROM payments p2
                    WHERE p2.proforma_bill_id = p.proforma_bill_id
                      AND p2.id <= p.id
                      AND COALESCE(p2.is_cancelled, 0) = 0
                ), 0) AS total_paid_after_payment,
                GREATEST(
                    pb.final_amount - COALESCE((
                        SELECT SUM(p3.amount)
                        FROM payments p3
                        WHERE p3.proforma_bill_id = p.proforma_bill_id
                          AND p3.id <= p.id
                          AND COALESCE(p3.is_cancelled, 0) = 0
                    ), 0),
                    0
                ) AS balance_after_payment,
                ft.function_name
            FROM payments p
            INNER JOIN proforma_bills pb ON pb.id = p.proforma_bill_id
            LEFT JOIN function_types ft ON ft.id = pb.function_type_id
            WHERE p.id = ?
              AND p.proforma_bill_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('ii', $paymentId, $proformaId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function pp_payment_whatsapp_template_key(array $row): string
{
    // Use the balance immediately after this payment. This keeps Retry
    // WhatsApp correct even if later payments have already been collected.
    $balanceAfterPayment = array_key_exists('balance_after_payment', $row)
        ? (float)$row['balance_after_payment']
        : (float)($row['balance_amount'] ?? 0);

    return ($balanceAfterPayment <= 0.00001)
        ? 'payment_completed_new'
        : 'payment_received';
}

function pp_send_payment_whatsapp(mysqli $conn, int $proformaId, int $paymentId): array
{
    if (!pp_payment_whatsapp_api_ready()) {
        return [
            'success' => false,
            'message' => 'WhatsApp API file/function missing.',
            'mode' => 'api',
            'log_id' => 0
        ];
    }

    $row = pp_payment_whatsapp_row($conn, $proformaId, $paymentId);
    if (!$row) {
        return [
            'success' => false,
            'message' => 'Payment details not found for WhatsApp.',
            'mode' => 'api',
            'log_id' => 0
        ];
    }

    $mobile = trim((string)($row['mobile'] ?? ''));
    if ($mobile === '') {
        return [
            'success' => false,
            'message' => 'Customer mobile number missing.',
            'mode' => 'api',
            'log_id' => 0
        ];
    }

    $templateKey = pp_payment_whatsapp_template_key($row);
    $balanceAfterPayment = array_key_exists('balance_after_payment', $row)
        ? (float)$row['balance_after_payment']
        : (float)($row['balance_amount'] ?? 0);
    $totalPaidAfterPayment = array_key_exists('total_paid_after_payment', $row)
        ? (float)$row['total_paid_after_payment']
        : (float)($row['advance_amount'] ?? 0);
    $proformaPdfUrl = $templateKey === 'payment_completed_new'
        ? pp_payment_proforma_pdf_url($conn, $proformaId)
        : '';

    if ($templateKey === 'payment_completed_new' && $proformaPdfUrl === '') {
        return [
            'success' => false,
            'message' => 'Proforma PDF link could not be generated.',
            'mode' => 'api',
            'log_id' => 0
        ];
    }

    $splitMeta = pp_split_payment_meta((string)($row['remarks'] ?? ''));
    $displayPaidAmount = (float)($row['amount'] ?? 0);
    $displayPaymentMode = strtoupper((string)($row['payment_mode'] ?? '-'));

    if ($splitMeta && (int)($splitMeta['part'] ?? 0) === 2) {
        $displayPaidAmount = round((float)$splitMeta['cash'] + (float)$splitMeta['upi'], 2);
        $displayPaymentMode = 'CASH + UPI';
    }

    $variables = [
        'customer_name' => trim((string)($row['customer_name'] ?? 'Customer')) ?: 'Customer',
        'proforma_no' => trim((string)($row['proforma_no'] ?? '-')) ?: '-',
        'payment_no' => trim((string)($row['payment_no'] ?? '-')) ?: '-',
        'paid_amount' => pp_payment_whatsapp_money($displayPaidAmount),
        'payment_mode' => $displayPaymentMode,
        'balance_amount' => pp_payment_whatsapp_money($balanceAfterPayment),
        'final_amount' => pp_payment_whatsapp_money($row['final_amount'] ?? 0),
        'total_paid' => pp_payment_whatsapp_money($totalPaidAfterPayment),
        'payment_date' => !empty($row['payment_date']) ? date('d-m-Y', strtotime((string)$row['payment_date'])) : date('d-m-Y'),
        'reference_no' => trim((string)($row['reference_no'] ?? '-')) ?: '-',
        'function_type' => trim((string)($row['function_name'] ?? '-')) ?: '-',
        'proforma_pdf_link' => $proformaPdfUrl
    ];

    $meta = [
        'related_module' => 'Payments',
        'related_id' => $paymentId,
        'customer_id' => !empty($row['bill_customer_id']) ? (int)$row['bill_customer_id'] : (!empty($row['customer_id']) ? (int)$row['customer_id'] : null),
        'sent_by' => (int)($_SESSION['user_id'] ?? 0),
        'extra_payload' => ['type' => 'text']
    ];

    if (function_exists('subhiksha_send_template_whatsapp')) {
        $result = subhiksha_send_template_whatsapp($conn, $templateKey, $mobile, $variables, $meta);
    } else {
        $result = subhiksha_send_whatsapp($conn, array_merge($meta, [
            'mobile' => $mobile,
            'template_key' => $templateKey,
            'variables' => $variables
        ]));
    }

    $result['template_key'] = $templateKey;
    $result['payment_id'] = $paymentId;
    $result['mode'] = 'api';

    return $result;
}

/*
 * Advance payments created together with a Proforma are now logged using
 * related_module = 'Payments' and related_id = payment id by api/create_proforma.php.
 * Therefore this existing status/retry UI automatically shows:
 * - WhatsApp Sent when the automatic send succeeds
 * - Retry WhatsApp when the automatic send fails or no log exists
 */
function pp_last_payment_whatsapp_status(mysqli $conn, int $paymentId): array
{
    if ($paymentId <= 0 || !pp_table_exists($conn, 'whatsapp_logs')) {
        return ['status' => 'not_sent', 'label' => 'Not Sent', 'log_id' => 0, 'message' => 'No WhatsApp log found.'];
    }

    try {
        $stmt = $conn->prepare("
            SELECT id, status, provider_response, sent_at, created_at
            FROM whatsapp_logs
            WHERE related_module = 'Payments'
              AND related_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->bind_param('i', $paymentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return ['status' => 'not_sent', 'label' => 'Not Sent', 'log_id' => 0, 'message' => 'No WhatsApp log found.'];
        }

        $status = strtolower(trim((string)($row['status'] ?? '')));
        if ($status === 'sent') {
            return ['status' => 'sent', 'label' => 'Sent', 'log_id' => (int)$row['id'], 'message' => 'WhatsApp sent.'];
        }

        return ['status' => 'failed', 'label' => 'Failed', 'log_id' => (int)$row['id'], 'message' => trim((string)($row['provider_response'] ?? 'WhatsApp failed.'))];
    } catch (Throwable $e) {
        return ['status' => 'failed', 'label' => 'Failed', 'log_id' => 0, 'message' => $e->getMessage()];
    }
}

try {
    pp_ensure_payment_cancel_columns($conn);
    pp_ensure_payment_return_columns($conn);
} catch (Throwable $e) {
    // Page will still work with its legacy columns if ALTER permission is unavailable.
}

if (empty($_SESSION['payment_csrf'])) {
    $_SESSION['payment_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['payment_csrf'];

$id = (int)($_GET['id'] ?? 0);
$error = '';
$message = '';
$messageType = 'success';

$pageMsg = (string)($_GET['msg'] ?? '');
if ($pageMsg === 'payment_collected') {
    $message = 'Payment collected successfully.';
} elseif ($pageMsg === 'payment_collected_wa_sent') {
    $message = 'Payment collected successfully and WhatsApp message sent.';
} elseif ($pageMsg === 'payment_collected_wa_failed') {
    $message = 'Payment collected successfully. WhatsApp failed: ' . trim((string)($_GET['wa_err'] ?? 'Please use Retry WhatsApp.'));
    $messageType = 'warning';
} elseif ($pageMsg === 'payment_whatsapp_resent') {
    $message = 'WhatsApp payment message sent successfully.';
} elseif ($pageMsg === 'payment_whatsapp_retry_failed') {
    $message = 'WhatsApp retry failed: ' . trim((string)($_GET['wa_err'] ?? 'Please check WhatsApp API settings.'));
    $messageType = 'warning';
} elseif ($pageMsg === 'payment_cancelled') {
    $message = 'Payment cancelled and amount reverted successfully.';
} elseif (!empty($_GET['err'])) {
    $message = 'Error: ' . trim((string)$_GET['err']);
    $messageType = 'danger';
}

$returnedAfterPayment = round((float)str_replace(',', '', (string)($_GET['return_amount'] ?? '0')), 2);
if ($message !== '' && $returnedAfterPayment > 0.009) {
    $message .= ' Return ' . pp_money($returnedAfterPayment) . ' to the customer.';
}

if ($id <= 0) {
    $error = 'Invalid proforma bill.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id > 0) {
    pp_check_csrf();
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if (!pp_can_update_payment($conn)) {
            throw new RuntimeException('You do not have permission to update payment.');
        }

        if ($action === 'collect_payment') {
            $useCash = !empty($_POST['use_cash']);
            $useUpi = !empty($_POST['use_upi']);
            $cashTendered = round((float)str_replace(',', '', (string)($_POST['cash_amount'] ?? '0')), 2);
            $upiAmount = round((float)str_replace(',', '', (string)($_POST['upi_amount'] ?? '0')), 2);
            $paymentDate = trim((string)($_POST['payment_date'] ?? date('Y-m-d')));
            $upiReference = trim((string)($_POST['upi_reference'] ?? ''));
            $remarks = trim((string)($_POST['remarks'] ?? ''));

            if (!$useCash && !$useUpi) {
                throw new RuntimeException('Select Cash, UPI or both.');
            }
            if ($useCash && $cashTendered <= 0) {
                throw new RuntimeException('Cash Received must be greater than zero.');
            }
            if ($useUpi && $upiAmount <= 0) {
                throw new RuntimeException('UPI Amount must be greater than zero.');
            }
            if ($paymentDate === '') $paymentDate = date('Y-m-d');

            $stmt = $conn->prepare("SELECT id, customer_id, final_amount, advance_amount, balance_amount FROM proforma_bills WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $bill = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$bill) throw new RuntimeException('Proforma bill not found.');

            /* Always calculate against the current active payment ledger. */
            $currentTotals = pp_payment_totals_from_db($conn, $id);
            $balance = round((float)$currentTotals['balance_amount'], 2);
            if ($balance <= 0.009) {
                throw new RuntimeException('This proforma bill is already fully paid.');
            }

            if ($useUpi && $upiAmount > $balance + 0.009) {
                throw new RuntimeException('UPI Amount cannot exceed the current balance. Excess/Return is allowed only through Cash.');
            }

            /*
             * Split rule (same behavior as Quick Sale):
             * - UPI is applied first and can never exceed the balance.
             * - Cash fills the remaining amount.
             * - Any cash above the remaining balance is returned to the customer.
             * - Partial payment is still allowed on Proforma payments.
             */
            $upiApplied = $useUpi ? round(min($upiAmount, $balance), 2) : 0.0;
            $remainingAfterUpi = round(max(0, $balance - $upiApplied), 2);
            $cashApplied = $useCash ? round(min($cashTendered, $remainingAfterUpi), 2) : 0.0;
            $returnAmount = $useCash ? round(max(0, $cashTendered - $cashApplied), 2) : 0.0;
            $totalApplied = round($cashApplied + $upiApplied, 2);

            if ($totalApplied <= 0.009) {
                throw new RuntimeException('Payment amount must be greater than zero.');
            }
            if ($useCash && $useUpi && $cashApplied <= 0.009) {
                throw new RuntimeException('UPI already covers the balance. Unselect Cash or reduce UPI Amount.');
            }

            $cashDenominationPayload = null;
            if ($useCash) {
                $cashDenominationPayload = pp_cash_denomination_payload();
                if (abs((float)$cashDenominationPayload['total'] - $cashTendered) > 0.009) {
                    throw new RuntimeException(
                        'Cash denomination total must match Cash Received. Denomination total: ' .
                        pp_money($cashDenominationPayload['total']) . ', Cash received: ' . pp_money($cashTendered)
                    );
                }
            }

            if ($useCash) {
                /* Prepare denomination storage before the DB transaction to avoid DDL auto-commit. */
                pp_ensure_cash_denomination_table($conn);
            }

            $customerId = !empty($bill['customer_id']) ? (int)$bill['customer_id'] : null;
            $userId = (int)($_SESSION['user_id'] ?? 0);
            $isSplit = $useCash && $useUpi;
            $splitGroup = '';
            if ($isSplit) {
                try {
                    $splitGroup = date('YmdHis') . '_' . bin2hex(random_bytes(4));
                } catch (Throwable $e) {
                    $splitGroup = str_replace('.', '', uniqid('sp_', true));
                }
            }

            $createdPaymentIds = [];
            $whatsappPaymentId = 0;

            $conn->begin_transaction();
            try {
                $insertPaymentRow = function (
                    string $mode,
                    float $appliedAmount,
                    float $tendered,
                    float $returned,
                    string $reference,
                    string $rowRemarks,
                    bool $completesBalance
                ) use ($conn, $customerId, $id, $paymentDate, $userId): int {
                    $paymentNo = pp_next_no($conn);
                    $paymentType = $completesBalance ? 'full' : 'balance';

                    if (pp_payment_return_columns_ready($conn)) {
                        $stmt = $conn->prepare("INSERT INTO payments (customer_id, proforma_bill_id, payment_no, payment_type, payment_mode, amount, tendered_amount, return_amount, payment_date, reference_no, remarks, received_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                        $stmt->bind_param('iisssdddsssi', $customerId, $id, $paymentNo, $paymentType, $mode, $appliedAmount, $tendered, $returned, $paymentDate, $reference, $rowRemarks, $userId);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO payments (customer_id, proforma_bill_id, payment_no, payment_type, payment_mode, amount, payment_date, reference_no, remarks, received_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                        $stmt->bind_param('iisssdsssi', $customerId, $id, $paymentNo, $paymentType, $mode, $appliedAmount, $paymentDate, $reference, $rowRemarks, $userId);
                    }
                    $stmt->execute();
                    $paymentId = (int)$stmt->insert_id;
                    $stmt->close();

                    if ($paymentId <= 0) {
                        throw new RuntimeException('Unable to save payment.');
                    }
                    return $paymentId;
                };

                $baseRemarks = $remarks;
                $splitSummary = $isSplit
                    ? 'Split payment: Cash ' . pp_money($cashApplied) . ' + UPI ' . pp_money($upiApplied)
                    : '';
                if ($returnAmount > 0.009) {
                    $returnAudit = 'Cash received ' . pp_money($cashTendered)
                        . '; Applied ' . pp_money($cashApplied)
                        . '; Return ' . pp_money($returnAmount);
                    $baseRemarks = $baseRemarks !== '' ? $baseRemarks . ' | ' . $returnAudit : $returnAudit;
                }

                $runningApplied = 0.0;

                if ($useCash && $cashApplied > 0.009) {
                    $runningApplied = round($runningApplied + $cashApplied, 2);
                    $cashCompletes = $runningApplied >= $balance - 0.009 && !$useUpi;
                    $cashRemarks = $baseRemarks;
                    if ($isSplit) {
                        $meta = '[SPLIT_META:GROUP=' . $splitGroup
                            . ';PART=1;CASH=' . number_format($cashApplied, 2, '.', '')
                            . ';UPI=' . number_format($upiApplied, 2, '.', '')
                            . ';RETURN=' . number_format($returnAmount, 2, '.', '') . ']';
                        $cashRemarks = trim(($cashRemarks !== '' ? $cashRemarks . ' | ' : '') . $splitSummary . ' | Cash part ' . $meta);
                    }

                    $cashPaymentId = $insertPaymentRow(
                        'cash',
                        $cashApplied,
                        $cashTendered,
                        $returnAmount,
                        '',
                        $cashRemarks,
                        $cashCompletes
                    );
                    $createdPaymentIds[] = $cashPaymentId;
                    if (is_array($cashDenominationPayload)) {
                        pp_save_cash_denominations($conn, $cashPaymentId, $cashDenominationPayload);
                    }
                    $whatsappPaymentId = $cashPaymentId;
                }

                if ($useUpi && $upiApplied > 0.009) {
                    $runningApplied = round($runningApplied + $upiApplied, 2);
                    $upiCompletes = $runningApplied >= $balance - 0.009;
                    $upiRemarks = $baseRemarks;
                    if ($isSplit) {
                        $meta = '[SPLIT_META:GROUP=' . $splitGroup
                            . ';PART=2;CASH=' . number_format($cashApplied, 2, '.', '')
                            . ';UPI=' . number_format($upiApplied, 2, '.', '')
                            . ';RETURN=' . number_format($returnAmount, 2, '.', '') . ']';
                        $upiRemarks = trim(($upiRemarks !== '' ? $upiRemarks . ' | ' : '') . $splitSummary . ' | UPI part ' . $meta);
                    }

                    $upiPaymentId = $insertPaymentRow(
                        'upi',
                        $upiApplied,
                        $upiApplied,
                        0.0,
                        $upiReference,
                        $upiRemarks,
                        $upiCompletes
                    );
                    $createdPaymentIds[] = $upiPaymentId;
                    /* For split payment, WhatsApp is sent once from the final UPI row. */
                    $whatsappPaymentId = $upiPaymentId;
                }

                pp_sync_bill_and_job_amounts_from_payments($conn, $id);
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }

            if ($whatsappPaymentId <= 0) {
                throw new RuntimeException('Payment saved but WhatsApp payment reference could not be resolved.');
            }

            $waResult = pp_send_payment_whatsapp($conn, $id, $whatsappPaymentId);
            if (!empty($waResult['success'])) {
                pp_redirect($id, 'payment_collected_wa_sent', '', [
                    'return_amount' => $returnAmount > 0.009 ? number_format($returnAmount, 2, '.', '') : ''
                ]);
            }

            pp_redirect($id, 'payment_collected_wa_failed', '', [
                'payment_id' => $whatsappPaymentId,
                'wa_err' => (string)($waResult['message'] ?? 'Unknown WhatsApp error.'),
                'return_amount' => $returnAmount > 0.009 ? number_format($returnAmount, 2, '.', '') : ''
            ]);
        }

        if ($action === 'retry_payment_whatsapp') {
            $paymentId = (int)($_POST['payment_id'] ?? 0);
            if ($paymentId <= 0) throw new RuntimeException('Invalid payment for WhatsApp retry.');

            $waResult = pp_send_payment_whatsapp($conn, $id, $paymentId);
            if (!empty($waResult['success'])) {
                pp_redirect($id, 'payment_whatsapp_resent');
            }

            pp_redirect($id, 'payment_whatsapp_retry_failed', '', [
                'payment_id' => $paymentId,
                'wa_err' => (string)($waResult['message'] ?? 'Unknown WhatsApp error.')
            ]);
        }

        if ($action === 'cancel_payment') {
            if (!pp_col_exists($conn, 'payments', 'is_cancelled')) {
                throw new RuntimeException('Payment cancellation columns are missing. Please allow ALTER TABLE permission or add cancellation columns.');
            }

            $paymentId = (int)($_POST['payment_id'] ?? 0);
            $cancelReason = trim((string)($_POST['cancel_reason'] ?? ''));
            if ($paymentId <= 0) throw new RuntimeException('Invalid payment.');
            if ($cancelReason === '') throw new RuntimeException('Cancel reason is required.');

            $stmt = $conn->prepare("SELECT * FROM payments WHERE id = ? AND proforma_bill_id = ? LIMIT 1");
            $stmt->bind_param('ii', $paymentId, $id);
            $stmt->execute();
            $payment = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$payment) throw new RuntimeException('Payment not found.');
            if ((int)($payment['is_cancelled'] ?? 0) === 1) throw new RuntimeException('This payment is already cancelled.');

            $stmt = $conn->prepare("SELECT advance_amount FROM proforma_bills WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $bill = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$bill) throw new RuntimeException('Proforma bill not found.');

            $userId = (int)($_SESSION['user_id'] ?? 0);
            $stmt = $conn->prepare("UPDATE payments SET is_cancelled = 1, cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ? WHERE id = ? AND proforma_bill_id = ?");
            $stmt->bind_param('isii', $userId, $cancelReason, $paymentId, $id);
            $stmt->execute();
            $stmt->close();

            /*
             * After cancellation, recalculate from the remaining active payments.
             * This also correctly handles cancelling the last/advance payment.
             */
            pp_sync_bill_and_job_amounts_from_payments($conn, $id);
            pp_redirect($id, 'payment_cancelled');
        }

        throw new RuntimeException('Invalid request.');
    } catch (Throwable $e) {
        pp_redirect($id, '', $e->getMessage());
    }
}

$bill = null;
$activePayments = [];
$cancelledPayments = [];

if ($id > 0 && $error === '') {
    try {
        $stmt = $conn->prepare("SELECT pb.*, ps.status_name, ft.function_name FROM proforma_bills pb LEFT JOIN proforma_statuses ps ON ps.id = pb.proforma_status_id LEFT JOIN function_types ft ON ft.id = pb.function_type_id WHERE pb.id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $bill = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$bill) $error = 'Proforma bill not found.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($bill) {
    try {
        $pagePaymentTotals = pp_sync_bill_and_job_amounts_from_payments($conn, $id);
        $bill['advance_amount'] = $pagePaymentTotals['paid_amount'];
        $bill['balance_amount'] = $pagePaymentTotals['balance_amount'];
    } catch (Throwable $e) {
        // Keep the stored bill values if payment synchronization cannot run.
    }
}

if ($bill && pp_table_exists($conn, 'payments')) {
    try {
        $cancelSelect = pp_col_exists($conn, 'payments', 'is_cancelled') ? 'COALESCE(p.is_cancelled,0) AS is_cancelled, p.cancelled_at, p.cancelled_by, p.cancel_reason, cu.username AS cancelled_by_name' : '0 AS is_cancelled, NULL AS cancelled_at, NULL AS cancelled_by, NULL AS cancel_reason, NULL AS cancelled_by_name';
        $cancelJoin = pp_col_exists($conn, 'payments', 'cancelled_by') ? 'LEFT JOIN users cu ON cu.id = p.cancelled_by' : '';
        $sql = "SELECT p.*, ru.username AS received_by_name, {$cancelSelect} FROM payments p LEFT JOIN users ru ON ru.id = p.received_by {$cancelJoin} WHERE p.proforma_bill_id = ? ORDER BY p.id DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            if ((int)($row['is_cancelled'] ?? 0) === 1) {
                $cancelledPayments[] = $row;
            } else {
                $row['wa_status_info'] = pp_last_payment_whatsapp_status($conn, (int)($row['id'] ?? 0));
                $activePayments[] = $row;
            }
        }
        $stmt->close();
    } catch (Throwable $e) {
        $message = 'Unable to load payment history: ' . $e->getMessage();
        $messageType = 'danger';
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Collect Payment - Subhiksha Cards</title>
    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>
    <style>
    .payment-page .page-head {
        padding: 24px 28px;
        margin-bottom: 18px
    }

    .payment-page .page-head h1 {
        font-size: 30px;
        font-weight: 900;
        color: var(--text-main)
    }

    .module-card {
        padding: 24px;
        border-radius: 20px;
        margin-bottom: 18px
    }

    .section-title {
        font-size: 18px;
        font-weight: 900;
        color: var(--text-main);
        margin-bottom: 12px
    }

    .info-box {
        border: 1px solid var(--border-soft);
        border-radius: 16px;
        padding: 14px;
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
        height: 100%
    }

    .info-box small {
        display: block;
        font-size: 11px;
        text-transform: uppercase;
        color: var(--text-muted);
        font-weight: 900;
        margin-bottom: 5px
    }

    .info-box strong {
        display: block;
        font-size: 18px;
        color: var(--text-main);
        font-weight: 900;
        word-break: break-word
    }

    .balance-due strong {
        color: #991b1b
    }

    .paid-box strong {
        color: #166534
    }

    .payment-form {
        border: 1px solid var(--border-soft);
        border-radius: 18px;
        padding: 18px;
        background: color-mix(in srgb, var(--success-color, #16a34a) 6%, var(--card-bg))
    }

    .payment-calculation-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 10px;
    }

    .payment-calculation-box {
        border: 1px solid var(--border-soft);
        border-radius: 15px;
        padding: 11px 13px;
        background: var(--card-bg);
    }

    .payment-calculation-box small {
        display: block;
        color: var(--text-muted);
        font-size: 11px;
        font-weight: 900;
        text-transform: uppercase;
        margin-bottom: 4px;
    }

    .payment-calculation-box strong {
        color: var(--text-main);
        font-size: 18px;
        font-weight: 900;
    }

    .payment-calculation-box.return-box {
        border-color: rgba(245, 158, 11, .42);
        background: rgba(245, 158, 11, .10);
    }

    .payment-calculation-box.return-box strong {
        color: #9a3412;
    }

    .table-view th {
        font-size: 12px;
        text-transform: uppercase;
        color: var(--text-muted);
        white-space: nowrap
    }

    .table-view td {
        vertical-align: middle
    }

    .cancelled-row {
        background: #fef2f2 !important;
        color: #991b1b
    }

    .status-badge {
        display: inline-flex;
        border-radius: 999px;
        padding: 5px 10px;
        font-size: 11px;
        font-weight: 900;
        background: #dcfce7;
        color: #166534
    }

    .status-badge.cancelled {
        background: #fee2e2;
        color: #991b1b
    }

    .toast-ui {
        border: 0;
        border-radius: 18px;
        box-shadow: 0 18px 45px rgba(15, 23, 42, .18);
        overflow: hidden;
        min-width: 320px;
        max-width: 420px
    }

    .toast-ui.success {
        background: #dcfce7;
        color: #14532d
    }

    .toast-ui.danger {
        background: #fee2e2;
        color: #7f1d1d
    }

    .toast-ui.warning {
        background: #fef3c7;
        color: #92400e
    }

    .toast-title {
        font-size: 14px;
        font-weight: 900
    }

    .toast-message {
        font-size: 13px;
        font-weight: 800;
        line-height: 1.45
    }

    @media(max-width:767.98px) {
        .payment-page .page-head {
            padding: 18px;
            border-radius: 18px
        }

        .payment-page .page-head h1 {
            font-size: 24px
        }

        .module-card {
            padding: 16px;
            border-radius: 18px
        }

        .table-view {
            font-size: 13px
        }
    }


    /* Cash / UPI checkbox payment UI */
    .payment-mode-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(180px, 1fr));
        gap: 14px
    }

    .payment-mode-card {
        position: relative;
        display: flex;
        align-items: flex-start;
        gap: 12px;
        border: 1.5px solid var(--border-soft);
        border-radius: 18px;
        padding: 16px;
        min-height: 92px;
        background: var(--card-bg);
        cursor: pointer;
        transition: .18s ease;
        user-select: none
    }

    .payment-mode-card:hover {
        transform: translateY(-1px);
        box-shadow: 0 10px 24px rgba(15, 23, 42, .08)
    }

    .payment-mode-card.active {
        border-color: #2563eb;
        background: rgba(37, 99, 235, .08);
        box-shadow: 0 14px 34px rgba(37, 99, 235, .12)
    }

    .payment-mode-card input {
        width: 24px;
        height: 24px;
        accent-color: #2563eb;
        margin-top: 2px;
        cursor: pointer
    }

    .payment-mode-card strong {
        display: block;
        font-size: 17px;
        font-weight: 900;
        color: var(--text-main);
        line-height: 1.2
    }

    .payment-mode-card span {
        display: block;
        margin-top: 3px;
        font-size: 12px;
        font-weight: 900;
        color: var(--text-muted);
        line-height: 1.25
    }

    .denom-modal-compact .modal-dialog {
        max-width: 610px
    }

    .denom-modal-compact .modal-content {
        border: 0;
        border-radius: 22px;
        overflow: hidden;
        box-shadow: 0 24px 70px rgba(15, 23, 42, .26)
    }

    .denom-modal-compact .modal-header,
    .denom-modal-compact .modal-footer {
        padding: 12px 16px
    }

    .denom-modal-compact .modal-body {
        padding: 14px 16px;
        max-height: 70vh;
        overflow: auto
    }

    .denom-section-title {
        font-size: 13px;
        font-weight: 900;
        color: var(--text-main);
        margin: 8px 0 6px
    }

    .denom-line {
        display: grid;
        grid-template-columns: 86px 72px 1fr;
        align-items: center;
        gap: 8px;
        margin-bottom: 7px;
        font-weight: 800;
        font-size: 13px
    }

    .denom-line input {
        min-height: 36px;
        border-radius: 10px;
        text-align: center;
        font-weight: 900
    }

    .denom-line .denom-amount {
        min-height: 36px;
        border: 1px solid var(--border-soft);
        border-radius: 10px;
        padding: 7px 9px;
        background: color-mix(in srgb, var(--card-bg) 94%, var(--body-bg));
        font-weight: 900;
        text-align: right
    }

    .denom-total-box {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 8px;
        border-radius: 14px;
        background: rgba(22, 163, 74, .10);
        border: 1px solid rgba(22, 163, 74, .24);
        padding: 9px 11px;
        font-weight: 900;
        font-size: 13px
    }

    .denom-total-box span {
        white-space: nowrap;
    }

    body.modal-open-fallback {
        overflow: hidden
    }

    .modal-backdrop-fallback {
        position: fixed;
        inset: 0;
        z-index: 1040;
        background: rgba(15, 23, 42, .50)
    }

    .modal.show.modal-fallback {
        display: block;
        z-index: 1055;
        background: transparent
    }

    @media(max-width:575.98px) {
        .payment-mode-grid {
            grid-template-columns: 1fr
        }

        .payment-calculation-grid,
        .denom-total-box {
            grid-template-columns: 1fr;
        }

        .denom-modal-compact .modal-dialog {
            max-width: calc(100% - 22px);
            margin: 11px auto
        }

        .denom-line {
            grid-template-columns: 76px 66px 1fr;
            font-size: 12px
        }
    }


    /* Professional compact 100% zoom UI + split payment */
    .payment-page {
        max-width: 1240px;
        width: 100%;
        margin-left: auto;
        margin-right: auto;
    }

    .payment-page .page-head {
        padding: 15px 18px !important;
        margin-bottom: 12px !important;
        border-radius: 16px !important;
    }

    .payment-page .page-head h1 {
        font-size: 23px !important;
        font-weight: 800 !important;
        line-height: 1.15 !important;
    }

    .payment-page .page-head p,
    .payment-page .text-muted-custom {
        font-size: 11px !important;
        font-weight: 500 !important;
    }

    .payment-page .page-head .btn,
    .payment-page .module-card .btn {
        font-size: 11px !important;
        font-weight: 700 !important;
        padding: 6px 10px !important;
        min-height: 30px !important;
    }

    .payment-page .module-card {
        padding: 14px !important;
        border-radius: 15px !important;
        margin-bottom: 12px !important;
    }

    .payment-page .section-title {
        font-size: 15px !important;
        font-weight: 800 !important;
        margin-bottom: 9px !important;
    }

    .payment-page .row.g-3 {
        --bs-gutter-x: .7rem;
        --bs-gutter-y: .7rem;
    }

    .payment-page .info-box {
        border-radius: 12px !important;
        padding: 9px 10px !important;
        min-height: 62px;
    }

    .payment-page .info-box small {
        font-size: 9px !important;
        font-weight: 700 !important;
        margin-bottom: 2px !important;
    }

    .payment-page .info-box strong {
        font-size: 13px !important;
        font-weight: 750 !important;
        line-height: 1.25 !important;
    }

    .payment-page .payment-form {
        border-radius: 14px !important;
        padding: 13px !important;
    }

    .payment-page .form-label {
        font-size: 11px !important;
        font-weight: 700 !important;
        margin-bottom: 4px !important;
    }

    .payment-page .form-control,
    .payment-page .form-select,
    .payment-page .input-group-text {
        min-height: 34px !important;
        font-size: 12px !important;
        padding: 6px 9px !important;
        border-radius: 9px !important;
    }

    .payment-page textarea.form-control {
        min-height: 56px !important;
    }

    .payment-page .payment-mode-grid {
        grid-template-columns: repeat(2, minmax(160px, 1fr));
        gap: 9px;
    }

    .payment-page .payment-mode-card {
        min-height: 64px !important;
        padding: 10px 11px !important;
        border-radius: 13px !important;
        gap: 8px !important;
    }

    .payment-page .payment-mode-card .mode-head {
        display: flex;
        align-items: flex-start;
        gap: 8px;
    }

    .payment-page .payment-mode-card input {
        width: 18px !important;
        height: 18px !important;
        margin-top: 1px !important;
    }

    .payment-page .payment-mode-card strong {
        font-size: 13px !important;
        font-weight: 800 !important;
    }

    .payment-page .payment-mode-card span,
    .payment-page .payment-mode-card .mode-note {
        font-size: 10px !important;
        font-weight: 600 !important;
        margin-top: 1px !important;
    }

    .payment-detail-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(220px, 1fr));
        gap: 10px;
    }

    .payment-detail-box {
        border: 1px solid var(--border-soft);
        border-radius: 12px;
        padding: 10px;
        background: var(--card-bg);
    }

    .payment-summary-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(120px, 1fr));
        gap: 8px;
    }

    .payment-summary-box {
        border: 1px solid var(--border-soft);
        border-radius: 11px;
        padding: 8px 10px;
        background: var(--card-bg);
    }

    .payment-summary-box small {
        display: block;
        font-size: 9px;
        font-weight: 700;
        color: var(--text-muted);
        text-transform: uppercase;
        margin-bottom: 2px;
    }

    .payment-summary-box strong {
        display: block;
        font-size: 14px;
        font-weight: 800;
        color: var(--text-main);
    }

    .payment-summary-box.return-box {
        border-color: rgba(245, 158, 11, .36);
        background: rgba(245, 158, 11, .08);
    }

    .payment-summary-box.return-box strong {
        color: #9a3412;
    }

    .payment-validation-message {
        border-radius: 10px;
        padding: 7px 9px;
        font-size: 10.5px;
        font-weight: 700;
    }

    .payment-validation-message.ok {
        background: #dcfce7;
        color: #166534;
    }

    .payment-validation-message.bad {
        background: #fee2e2;
        color: #991b1b;
    }

    .payment-page .table-view th {
        font-size: 9.5px !important;
        font-weight: 700 !important;
        padding: 7px 8px !important;
    }

    .payment-page .table-view td {
        font-size: 10.5px !important;
        padding: 7px 8px !important;
    }

    .payment-page .table-view td strong {
        font-weight: 750 !important;
    }

    .payment-page .status-badge {
        padding: 4px 7px !important;
        font-size: 9px !important;
        font-weight: 700 !important;
    }

    .status-badge.split-grouped {
        background: #e0f2fe;
        color: #0369a1;
    }

    .payment-page .table-view .form-control-sm {
        min-height: 28px !important;
        font-size: 10px !important;
        padding: 4px 7px !important;
    }

    .denom-modal-compact .modal-dialog {
        max-width: 560px !important;
    }

    .denom-modal-compact .modal-title {
        font-size: 14px !important;
        font-weight: 800 !important;
    }

    .denom-modal-compact .modal-body {
        padding: 11px 13px !important;
    }

    .denom-line {
        font-size: 11px !important;
        margin-bottom: 5px !important;
    }

    .denom-line input,
    .denom-line .denom-amount {
        min-height: 31px !important;
        font-size: 11px !important;
    }

    @media (max-width: 991.98px) {
        .payment-page {
            max-width: 100%;
        }

        .payment-summary-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 767.98px) {
        .payment-page .page-head {
            padding: 13px !important;
        }

        .payment-page .page-head h1 {
            font-size: 20px !important;
        }

        .payment-page .module-card {
            padding: 12px !important;
        }

        .payment-detail-grid,
        .payment-mode-grid,
        .payment-summary-grid {
            grid-template-columns: 1fr !important;
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
            <section class="page-section payment-page">
                <div class="card-ui page-head">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                        <div>
                            <h1 class="mb-1">Collect Payment</h1>
                            <p class="text-muted-custom mb-0">
                                <?= $bill ? e($bill['proforma_no'] ?? '-') : 'Payment details' ?></p>
                        </div>
                        <div class="d-flex gap-2 flex-wrap"><a href="proforma_bills.php"
                                class="btn btn-outline-secondary rounded-pill px-4 fw-bold">Back to
                                List</a><?php if ($bill): ?><a href="proforma_bill_view.php?id=<?= (int)$id ?>"
                                class="btn btn-primary rounded-pill px-4 fw-bold">View Bill</a><?php endif; ?></div>
                    </div>
                </div>

                <?php if ($message !== ''): ?>
                <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:12000">
                    <div id="pageToast" class="toast toast-ui <?= e($messageType) ?>" role="alert" aria-live="assertive"
                        aria-atomic="true" data-bs-delay="4200">
                        <div class="d-flex">
                            <div class="toast-body">
                                <div class="toast-title">
                                    <?= $messageType === 'danger' ? 'Failed' : ($messageType === 'warning' ? 'Warning' : 'Success') ?>
                                </div>
                                <div class="toast-message"><?= e($message) ?></div>
                            </div><button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast"
                                aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                <div class="card-ui module-card">
                    <div class="alert alert-danger rounded-4 fw-bold mb-0"><?= e($error) ?></div>
                </div>
                <?php elseif ($bill): ?>
                <div class="card-ui module-card">
                    <div class="section-title">Bill Summary</div>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="info-box"><small>Proforma
                                    No</small><strong><?= e($bill['proforma_no'] ?? '-') ?></strong></div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box">
                                <small>Customer</small><strong><?= e($bill['customer_name'] ?? '-') ?></strong><small><?= e($bill['mobile'] ?? '') ?></small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box">
                                <small>Function</small><strong><?= e($bill['function_name'] ?? '-') ?></strong>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box">
                                <small>Status</small><strong><?= e($bill['status_name'] ?? '-') ?></strong>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-box"><small>Final
                                    Amount</small><strong><?= e(pp_money($bill['final_amount'] ?? 0)) ?></strong></div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-box paid-box"><small>Paid
                                    Amount</small><strong><?= e(pp_money($bill['advance_amount'] ?? 0)) ?></strong>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-box balance-due"><small>Balance
                                    Amount</small><strong><?= e(pp_money($bill['balance_amount'] ?? 0)) ?></strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-ui module-card">
                    <div class="section-title">Make Payment</div>
                    <?php if ((float)($bill['balance_amount'] ?? 0) <= 0): ?>
                    <div class="alert alert-success rounded-4 fw-bold mb-0">This proforma bill is fully paid.</div>
                    <?php else: ?>
                    <form method="post" class="payment-form" id="paymentForm">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="collect_payment">

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Payment Date *</label>
                                <input type="date" name="payment_date" id="payment_date" class="form-control"
                                    value="<?= e(date('Y-m-d')) ?>" required>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label fw-bold">Payment Mode *</label>
                                <div class="payment-mode-grid">
                                    <label class="payment-mode-card active" id="cashModeCard" for="use_cash">
                                        <span class="mode-head">
                                            <input type="checkbox" id="use_cash" name="use_cash" value="1" checked>
                                            <span>
                                                <strong>Cash</strong>
                                                <span class="mode-note">Cash denomination is mandatory.</span>
                                            </span>
                                        </span>
                                    </label>
                                    <label class="payment-mode-card" id="upiModeCard" for="use_upi">
                                        <span class="mode-head">
                                            <input type="checkbox" id="use_upi" name="use_upi" value="1">
                                            <span>
                                                <strong>UPI</strong>
                                                <span class="mode-note">Can be used alone or split with Cash.</span>
                                            </span>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="payment-detail-grid mt-3">
                            <div class="payment-detail-box" id="cashPaymentBox">
                                <label class="form-label fw-bold">Cash Received *</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" min="0.01" step="0.01" name="cash_amount" id="cash_amount"
                                        class="form-control"
                                        value="<?= e(number_format((float)$bill['balance_amount'], 2, '.', '')) ?>"
                                        placeholder="Cash received">
                                </div>
                                <div class="d-flex justify-content-between align-items-center gap-2 mt-2 flex-wrap">
                                    <small class="text-muted-custom fw-semibold">Excess Cash is allowed and is shown as
                                        Return Amount.</small>
                                    <button type="button" id="openCashDenomBtn"
                                        class="btn btn-outline-primary btn-sm rounded-pill px-3 fw-bold">Enter Cash
                                        Denomination</button>
                                </div>
                            </div>

                            <div class="payment-detail-box d-none" id="upiPaymentBox">
                                <label class="form-label fw-bold">UPI Amount *</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" min="0.01" step="0.01" name="upi_amount" id="upi_amount"
                                        class="form-control" placeholder="UPI amount" disabled>
                                </div>
                                <label class="form-label fw-bold mt-2">UPI Reference</label>
                                <input type="text" name="upi_reference" id="upi_reference" class="form-control"
                                    placeholder="Optional UPI transaction ID" disabled>
                            </div>
                        </div>

                        <div class="payment-summary-grid mt-3" aria-live="polite">
                            <div class="payment-summary-box">
                                <small>Balance Due</small>
                                <strong id="paymentBalanceDue"><?= e(pp_money($bill['balance_amount'] ?? 0)) ?></strong>
                            </div>
                            <div class="payment-summary-box">
                                <small>Total Received</small>
                                <strong id="paymentReceived">₹0.00</strong>
                            </div>
                            <div class="payment-summary-box">
                                <small>Applied to Bill</small>
                                <strong id="paymentAppliedAmount">₹0.00</strong>
                            </div>
                            <div class="payment-summary-box return-box">
                                <small>Return Amount</small>
                                <strong id="paymentReturnAmount">₹0.00</strong>
                            </div>
                        </div>

                        <div id="paymentValidationMessage" class="payment-validation-message mt-2"></div>

                        <div class="mt-3">
                            <label class="form-label fw-bold">Remarks</label>
                            <textarea name="remarks" class="form-control" rows="2"
                                placeholder="Payment remarks"></textarea>
                        </div>

                        <div class="d-flex flex-wrap justify-content-end gap-2 mt-3">
                            <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold">Save
                                Payment</button>
                        </div>
                    </form>

                    <div class="modal fade denom-modal-compact" id="cashDenominationModal" tabindex="-1"
                        aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <div>
                                        <h5 class="modal-title fw-black mb-0">Cash Denomination</h5>
                                        <small class="text-muted-custom fw-bold">Enter count for every note /
                                            coin</small>
                                    </div>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"
                                        aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="denom-total-box mb-2">
                                        <span>Cash Received: <span id="denomTarget">₹0.00</span></span>
                                        <span>Total: <span id="denomTotal">₹0.00</span></span>
                                        <span>Return: <span id="denomReturn">₹0.00</span></span>
                                    </div>

                                    <div class="denom-section-title">Notes:</div>
                                    <?php foreach ([500, 200, 100, 50, 20, 10] as $noteValue): ?>
                                    <div class="denom-line">
                                        <input type="number" min="0" step="1" value="0"
                                            class="form-control cash-denom-count"
                                            name="cash_note_<?= (int)$noteValue ?>" data-value="<?= (int)$noteValue ?>"
                                            form="paymentForm">
                                        <span>x ₹<?= (int)$noteValue ?></span>
                                        <span class="denom-amount">₹<span class="denom-row-total">0.00</span></span>
                                    </div>
                                    <?php endforeach; ?>

                                    <div class="denom-section-title">Coins:</div>
                                    <?php foreach ([20, 10, 5, 2, 1] as $coinValue): ?>
                                    <div class="denom-line">
                                        <input type="number" min="0" step="1" value="0"
                                            class="form-control cash-denom-count"
                                            name="cash_coin_<?= (int)$coinValue ?>" data-value="<?= (int)$coinValue ?>"
                                            form="paymentForm">
                                        <span>x ₹<?= (int)$coinValue ?></span>
                                        <span class="denom-amount">₹<span class="denom-row-total">0.00</span></span>
                                    </div>
                                    <?php endforeach; ?>

                                    <div id="denomError"
                                        class="alert alert-danger rounded-4 fw-bold py-2 px-3 mt-2 mb-0 d-none"></div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-outline-secondary rounded-pill px-3 fw-bold"
                                        data-bs-dismiss="modal">Close</button>
                                    <button type="button" class="btn btn-primary rounded-pill px-3 fw-bold"
                                        id="saveDenomBtn">Save Denomination</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="card-ui module-card">
                    <div class="section-title">Recent Payment History</div>
                    <div class="table-responsive">
                        <table class="table table-view">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Type</th>
                                    <th>Mode</th>
                                    <th>Received</th>
                                    <th>Applied</th>
                                    <th>Return</th>
                                    <th>Date</th>
                                    <th>Reference</th>
                                    <th>Received By</th>
                                    <th>Remarks</th>
                                    <th>WhatsApp</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$activePayments): ?><tr>
                                    <td colspan="12" class="text-center text-muted-custom py-3">No active payment found.
                                    </td>
                                </tr><?php endif; ?>
                                <?php foreach ($activePayments as $pay): ?>
                                <?php
                                    $receivedAmount = array_key_exists('tendered_amount', $pay) && $pay['tendered_amount'] !== null
                                        ? (float)$pay['tendered_amount']
                                        : (float)($pay['amount'] ?? 0);
                                    $returnedAmount = array_key_exists('return_amount', $pay)
                                        ? (float)($pay['return_amount'] ?? 0)
                                        : 0.0;
                                ?>
                                <tr>
                                    <td><strong><?= e($pay['payment_no'] ?? '-') ?></strong></td>
                                    <td><?= e(ucfirst((string)($pay['payment_type'] ?? '-'))) ?></td>
                                    <td><?= e(strtoupper((string)($pay['payment_mode'] ?? '-'))) ?></td>
                                    <td><strong><?= e(pp_money($receivedAmount)) ?></strong></td>
                                    <td><strong><?= e(pp_money($pay['amount'] ?? 0)) ?></strong></td>
                                    <td><strong><?= e(pp_money($returnedAmount)) ?></strong></td>
                                    <td><?= e(pp_date($pay['payment_date'] ?? null)) ?></td>
                                    <td><?= e($pay['reference_no'] ?? '-') ?></td>
                                    <td><?= e($pay['received_by_name'] ?? '-') ?></td>
                                    <td><?= e(pp_clean_payment_remarks($pay['remarks'] ?? '-') ?: '-') ?></td>
                                    <td>
                                        <?php
                                            $splitMeta = pp_split_payment_meta((string)($pay['remarks'] ?? ''));
                                            $waInfo = $pay['wa_status_info'] ?? ['status' => 'not_sent', 'label' => 'Not Sent'];
                                        ?>
                                        <?php if ($splitMeta && (int)($splitMeta['part'] ?? 0) === 1): ?>
                                        <span class="status-badge split-grouped">Split Grouped</span>
                                        <?php elseif (($waInfo['status'] ?? '') === 'sent'): ?>
                                        <span class="status-badge">WhatsApp Sent</span>
                                        <?php else: ?>
                                        <div class="d-flex flex-column gap-1 align-items-start">
                                            <span
                                                class="status-badge cancelled"><?= e($waInfo['label'] ?? 'Failed') ?></span>
                                            <form method="post"
                                                onsubmit="return confirm('Retry WhatsApp payment message?')">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                <input type="hidden" name="action" value="retry_payment_whatsapp">
                                                <input type="hidden" name="payment_id" value="<?= (int)$pay['id'] ?>">
                                                <button type="submit"
                                                    class="btn btn-sm btn-success rounded-pill fw-bold">Retry
                                                    WhatsApp</button>
                                            </form>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <form method="post" class="d-flex gap-2 justify-content-end"
                                            onsubmit="return confirm('Cancel this payment and revert balance?')">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="cancel_payment">
                                            <input type="hidden" name="payment_id" value="<?= (int)$pay['id'] ?>">
                                            <input type="text" name="cancel_reason" class="form-control form-control-sm"
                                                style="max-width:180px" placeholder="Cancel reason" required>
                                            <button type="submit"
                                                class="btn btn-sm btn-outline-danger rounded-pill fw-bold">Cancel</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card-ui module-card">
                    <div class="section-title">Cancelled Payment List</div>
                    <div class="table-responsive">
                        <table class="table table-view">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Mode</th>
                                    <th>Received</th>
                                    <th>Applied</th>
                                    <th>Return</th>
                                    <th>Payment Date</th>
                                    <th>Cancelled At</th>
                                    <th>Cancelled By</th>
                                    <th>Reason</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$cancelledPayments): ?><tr>
                                    <td colspan="9" class="text-center text-muted-custom py-3">No cancelled payment
                                        found.</td>
                                </tr><?php endif; ?>
                                <?php foreach ($cancelledPayments as $pay): ?>
                                <?php
                                    $receivedAmount = array_key_exists('tendered_amount', $pay) && $pay['tendered_amount'] !== null
                                        ? (float)$pay['tendered_amount']
                                        : (float)($pay['amount'] ?? 0);
                                    $returnedAmount = array_key_exists('return_amount', $pay)
                                        ? (float)($pay['return_amount'] ?? 0)
                                        : 0.0;
                                ?>
                                <tr class="cancelled-row">
                                    <td><strong><?= e($pay['payment_no'] ?? '-') ?></strong></td>
                                    <td><?= e(strtoupper((string)($pay['payment_mode'] ?? '-'))) ?></td>
                                    <td><strong><?= e(pp_money($receivedAmount)) ?></strong></td>
                                    <td><strong><?= e(pp_money($pay['amount'] ?? 0)) ?></strong></td>
                                    <td><strong><?= e(pp_money($returnedAmount)) ?></strong></td>
                                    <td><?= e(pp_date($pay['payment_date'] ?? null)) ?></td>
                                    <td><?= e(pp_datetime($pay['cancelled_at'] ?? null)) ?></td>
                                    <td><?= e($pay['cancelled_by_name'] ?? '-') ?></td>
                                    <td><?= e($pay['cancel_reason'] ?? '-') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
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
        const pageToastEl = document.getElementById('pageToast');
        if (pageToastEl && window.bootstrap && bootstrap.Toast) {
            bootstrap.Toast.getOrCreateInstance(pageToastEl).show();
        }

        const form = document.getElementById('paymentForm');
        if (!form) {
            return;
        }

        const cashCheck = document.getElementById('use_cash');
        const upiCheck = document.getElementById('use_upi');
        const cashModeCard = document.getElementById('cashModeCard');
        const upiModeCard = document.getElementById('upiModeCard');
        const cashPaymentBox = document.getElementById('cashPaymentBox');
        const upiPaymentBox = document.getElementById('upiPaymentBox');
        const cashAmountInput = document.getElementById('cash_amount');
        const upiAmountInput = document.getElementById('upi_amount');
        const upiReferenceInput = document.getElementById('upi_reference');
        const paymentDateInput = document.getElementById('payment_date');
        const openCashBtn = document.getElementById('openCashDenomBtn');
        const modalEl = document.getElementById('cashDenominationModal');
        const denomInputs = [...document.querySelectorAll('.cash-denom-count')];
        const denomTarget = document.getElementById('denomTarget');
        const denomTotalEl = document.getElementById('denomTotal');
        const denomReturn = document.getElementById('denomReturn');
        const denomError = document.getElementById('denomError');
        const saveDenomBtn = document.getElementById('saveDenomBtn');
        const receivedAmountEl = document.getElementById('paymentReceived');
        const appliedAmountEl = document.getElementById('paymentAppliedAmount');
        const returnAmountEl = document.getElementById('paymentReturnAmount');
        const validationEl = document.getElementById('paymentValidationMessage');
        const balanceDue = <?= json_encode(round((float)($bill['balance_amount'] ?? 0), 2)) ?>;
        let fallbackBackdrop = null;

        function money(v) {
            return '₹' + Number(v || 0).toLocaleString('en-IN', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function numericValue(input) {
            return Math.round((parseFloat(input?.value || '0') || 0) * 100) / 100;
        }

        function denominationTotal() {
            let total = 0;
            denomInputs.forEach(function(input) {
                const count = Math.max(0, parseInt(input.value || '0', 10) || 0);
                input.value = count;
                const value = parseFloat(input.dataset.value || '0') || 0;
                const rowTotal = count * value;
                total += rowTotal;
                const rowTotalEl = input.closest('.denom-line')?.querySelector('.denom-row-total');
                if (rowTotalEl) rowTotalEl.textContent = rowTotal.toFixed(2);
            });
            total = Math.round(total * 100) / 100;
            if (denomTotalEl) denomTotalEl.textContent = money(total);
            return total;
        }

        function calculatePayment() {
            const useCash = cashCheck?.checked === true;
            const useUpi = upiCheck?.checked === true;
            const cashTendered = useCash ? Math.max(0, numericValue(cashAmountInput)) : 0;
            const upiAmount = useUpi ? Math.max(0, numericValue(upiAmountInput)) : 0;

            let upiApplied = 0;
            let cashApplied = 0;
            let applied = 0;
            let returned = 0;
            let valid = false;
            let message = '';

            if (balanceDue <= 0) {
                message = 'This Proforma is already fully paid.';
            } else if (!useCash && !useUpi) {
                message = 'Select Cash, UPI or both.';
            } else if (useCash && cashTendered <= 0) {
                message = 'Enter Cash Received.';
            } else if (useUpi && upiAmount <= 0) {
                message = 'Enter UPI Amount.';
            } else if (upiAmount > balanceDue + 0.009) {
                message = 'UPI Amount cannot exceed Balance Due. Excess/Return is allowed only through Cash.';
            } else {
                upiApplied = useUpi ? Math.min(upiAmount, balanceDue) : 0;
                const remainingAfterUpi = Math.max(0, Math.round((balanceDue - upiApplied) * 100) / 100);
                cashApplied = useCash ? Math.min(cashTendered, remainingAfterUpi) : 0;
                returned = useCash ? Math.max(0, Math.round((cashTendered - cashApplied) * 100) / 100) : 0;
                applied = Math.round((cashApplied + upiApplied) * 100) / 100;

                if (useCash && useUpi && cashApplied <= 0.009) {
                    message = 'UPI already covers the balance. Unselect Cash or reduce UPI Amount.';
                } else if (applied <= 0.009) {
                    message = 'Enter a payment amount.';
                } else {
                    valid = true;
                    const remaining = Math.max(0, Math.round((balanceDue - applied) * 100) / 100);
                    if (remaining <= 0.009) {
                        message = returned > 0.009 ?
                            'This payment clears the bill. Return ' + money(returned) + ' to the customer.' :
                            'This payment clears the bill.';
                    } else {
                        message = 'Partial payment: ' + money(applied) + '. Remaining balance after save: ' + money(
                            remaining) + '.';
                    }
                }
            }

            const received = Math.round((cashTendered + upiAmount) * 100) / 100;
            if (receivedAmountEl) receivedAmountEl.textContent = money(received);
            if (appliedAmountEl) appliedAmountEl.textContent = money(applied);
            if (returnAmountEl) returnAmountEl.textContent = money(returned);
            if (denomTarget) denomTarget.textContent = money(cashTendered);
            if (denomReturn) denomReturn.textContent = money(returned);

            if (validationEl) {
                validationEl.textContent = message;
                validationEl.classList.toggle('ok', valid);
                validationEl.classList.toggle('bad', !valid);
            }

            return {
                useCash,
                useUpi,
                cashTendered,
                upiAmount,
                cashApplied,
                upiApplied,
                received,
                applied,
                returned,
                valid,
                message
            };
        }

        function syncPaymentModeUi() {
            const useCash = cashCheck?.checked === true;
            const useUpi = upiCheck?.checked === true;

            cashModeCard?.classList.toggle('active', useCash);
            upiModeCard?.classList.toggle('active', useUpi);

            if (cashPaymentBox) {
                cashPaymentBox.classList.toggle('d-none', !useCash);
                cashPaymentBox.style.display = useCash ? '' : 'none';
            }
            if (upiPaymentBox) {
                upiPaymentBox.classList.toggle('d-none', !useUpi);
                upiPaymentBox.style.display = useUpi ? '' : 'none';
            }

            if (cashAmountInput) {
                cashAmountInput.disabled = !useCash;
                if (!useCash) cashAmountInput.value = '';
            }
            if (upiAmountInput) {
                upiAmountInput.disabled = !useUpi;
                if (!useUpi) upiAmountInput.value = '';
            }
            if (upiReferenceInput) {
                upiReferenceInput.disabled = !useUpi;
                if (!useUpi) upiReferenceInput.value = '';
            }
            if (openCashBtn) openCashBtn.style.display = useCash ? 'inline-flex' : 'none';

            if (!useCash) {
                denomInputs.forEach(input => input.value = '0');
                denominationTotal();
            }

            /* Convenient single-mode defaults, same style as Quick Sale. */
            if (balanceDue > 0 && useCash && !useUpi && numericValue(cashAmountInput) <= 0) {
                cashAmountInput.value = balanceDue.toFixed(2);
            }
            if (balanceDue > 0 && useUpi && !useCash && numericValue(upiAmountInput) <= 0) {
                upiAmountInput.value = balanceDue.toFixed(2);
            }

            calculatePayment();
        }

        function showError(message) {
            if (!denomError) return;
            denomError.textContent = message || '';
            denomError.classList.toggle('d-none', !message);
        }

        function fallbackShowModal() {
            if (!modalEl) return;
            modalEl.classList.add('show', 'modal-fallback');
            modalEl.style.display = 'block';
            modalEl.removeAttribute('aria-hidden');
            document.body.classList.add('modal-open-fallback');
            if (!fallbackBackdrop) {
                fallbackBackdrop = document.createElement('div');
                fallbackBackdrop.className = 'modal-backdrop-fallback';
                fallbackBackdrop.addEventListener('click', fallbackHideModal);
                document.body.appendChild(fallbackBackdrop);
            }
        }

        function fallbackHideModal() {
            if (!modalEl) return;
            modalEl.classList.remove('show', 'modal-fallback');
            modalEl.style.display = 'none';
            modalEl.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('modal-open-fallback');
            if (fallbackBackdrop) {
                fallbackBackdrop.remove();
                fallbackBackdrop = null;
            }
        }

        function openDenominationModal() {
            if (!cashCheck?.checked) {
                alert('Select Cash payment first.');
                return;
            }
            if (numericValue(cashAmountInput) <= 0) {
                alert('Enter Cash Received before denomination.');
                cashAmountInput?.focus();
                return;
            }
            showError('');
            denominationTotal();
            calculatePayment();
            if (window.bootstrap && bootstrap.Modal && modalEl) {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            } else {
                fallbackShowModal();
            }
        }

        function closeDenominationModal() {
            if (window.bootstrap && bootstrap.Modal && modalEl) {
                bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            }
            fallbackHideModal();
        }

        cashCheck?.addEventListener('change', syncPaymentModeUi);
        upiCheck?.addEventListener('change', syncPaymentModeUi);
        cashAmountInput?.addEventListener('input', calculatePayment);
        upiAmountInput?.addEventListener('input', calculatePayment);
        openCashBtn?.addEventListener('click', openDenominationModal);
        denomInputs.forEach(input => input.addEventListener('input', function() {
            denominationTotal();
            calculatePayment();
        }));

        document.querySelectorAll('[data-bs-dismiss="modal"]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                setTimeout(fallbackHideModal, 10);
            });
        });

        saveDenomBtn?.addEventListener('click', function() {
            const expected = numericValue(cashAmountInput);
            const total = denominationTotal();
            if (Math.abs(total - expected) > 0.009) {
                showError('Denomination total ' + money(total) + ' must match Cash Received ' + money(
                    expected) + '.');
                return;
            }
            showError('');
            closeDenominationModal();
        });

        form.addEventListener('submit', function(e) {
            const payment = calculatePayment();
            if (!payment.valid) {
                e.preventDefault();
                alert(payment.message);
                return false;
            }
            if (!String(paymentDateInput?.value || '').trim()) {
                e.preventDefault();
                alert('Please select Payment Date.');
                paymentDateInput?.focus();
                return false;
            }
            if (payment.useCash) {
                const total = denominationTotal();
                if (Math.abs(total - payment.cashTendered) > 0.009) {
                    e.preventDefault();
                    showError('Denomination total ' + money(total) + ' must match Cash Received ' + money(
                        payment.cashTendered) + '.');
                    openDenominationModal();
                    return false;
                }
            }

            let confirmMessage = 'Apply ' + money(payment.applied) + ' to this Proforma bill?';
            if (payment.useCash && payment.useUpi) {
                confirmMessage += '\n\nSplit: Cash ' + money(payment.cashApplied) + ' + UPI ' + money(
                    payment.upiApplied) + '.';
            }
            if (payment.returned > 0.009) {
                confirmMessage += '\nReturn ' + money(payment.returned) + ' to the customer.';
            }
            if (!confirm(confirmMessage)) {
                e.preventDefault();
                return false;
            }
        });

        syncPaymentModeUi();
        denominationTotal();
        calculatePayment();
    })();
    </script>
</body>

</html>