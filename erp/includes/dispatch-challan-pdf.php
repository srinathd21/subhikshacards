<?php
/**
 * includes/dispatch-challan-pdf.php
 * Dispatch Challan generator for Subhiksha Cards ERP.
 *
 * - Uses the existing FPDF library.
 * - Reads Job Card / item / dispatch data only.
 * - Does not change Job Card workflow or payment logic.
 * - Creates a saved PDF under uploads/dispatch_challans/ so the same file can
 *   be previewed/downloaded from a secure customer link sent through WhatsApp.
 */

if (!function_exists('sdc_table_exists')) {
    function sdc_table_exists(mysqli $conn, string $table): bool
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
}

if (!function_exists('sdc_col_exists')) {
    function sdc_col_exists(mysqli $conn, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) return $cache[$key];

        try {
            $t = $conn->real_escape_string($table);
            $c = $conn->real_escape_string($column);
            $res = $conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
            $ok = $res && $res->num_rows > 0;
            if ($res) $res->free();
            return $cache[$key] = $ok;
        } catch (Throwable $e) {
            return $cache[$key] = false;
        }
    }
}

if (!function_exists('sdc_load_fpdf')) {
    function sdc_load_fpdf(): void
    {
        if (class_exists('FPDF')) return;

        $root = dirname(__DIR__);
        $candidates = [
            $root . '/assets/libs/fpdf/fpdf.php',
            $root . '/assets/libs/fpdf/FPDF.php',
            $root . '/assets/libs/fpdf/fpdf186/fpdf.php',
            $root . '/assets/libs/fpdf/fpdf184/fpdf.php',
            $root . '/vendor/autoload.php',
            $root . '/libs/fpdf.php',
            $root . '/libs/fpdf/fpdf.php',
            $root . '/fpdf.php',
        ];

        foreach ($candidates as $file) {
            if (is_file($file)) {
                require_once $file;
                if (class_exists('FPDF')) return;
            }
        }

        throw new RuntimeException('FPDF library not found. Expected: assets/libs/fpdf/fpdf.php');
    }
}

if (!function_exists('sdc_pdf_text')) {
    function sdc_pdf_text($value): string
    {
        $text = trim((string)$value);
        if ($text === '') return '';
        $text = str_replace(['₹', '–', '—', '→'], ['Rs.', '-', '-', '->'], $text);
        $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text);
        return $converted !== false ? $converted : $text;
    }
}

if (!function_exists('sdc_clean')) {
    function sdc_clean($value, string $fallback = '-'): string
    {
        $value = trim((string)$value);
        return $value === '' ? $fallback : $value;
    }
}

if (!function_exists('sdc_date')) {
    function sdc_date($value): string
    {
        if (empty($value)) return '-';
        $ts = strtotime((string)$value);
        return $ts === false ? '-' : date('d-m-Y', $ts);
    }
}

if (!function_exists('sdc_qty')) {
    function sdc_qty($value): string
    {
        $n = (float)$value;
        if (abs($n - round($n)) < 0.00001) {
            return number_format($n, 0, '.', ',');
        }
        return number_format($n, 2, '.', ',');
    }
}

if (!function_exists('sdc_safe_filename')) {
    function sdc_safe_filename(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($value));
        $value = trim((string)$value, '._-');
        return $value !== '' ? $value : 'dispatch_challan';
    }
}

if (!function_exists('sdc_root')) {
    function sdc_root(): string
    {
        return dirname(__DIR__);
    }
}

if (!function_exists('sdc_logo_path')) {
    function sdc_logo_path(): ?string
    {
        $root = sdc_root();
        $candidates = [
            $root . '/assets/img/subhiksha-logo.png',
            $root . '/assets/img/subhiksha_logo.png',
            $root . '/assets/images/subhiksha-logo.png',
            $root . '/assets/images/subhiksha_logo.png',
            $root . '/assets/img/logo.png',
            $root . '/assets/images/logo.png',
        ];

        foreach ($candidates as $file) {
            if (is_file($file)) return $file;
        }
        return null;
    }
}

if (!function_exists('sdc_setting')) {
    function sdc_setting(mysqli $conn, string $key, string $default = ''): string
    {
        try {
            if (!sdc_table_exists($conn, 'system_settings')) return $default;
            $stmt = $conn->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $row ? trim((string)$row['setting_value']) : $default;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('sdc_base_url')) {
    function sdc_base_url(mysqli $conn): string
    {
        foreach (['site_url', 'base_url', 'app_url'] as $key) {
            $value = sdc_setting($conn, $key, '');
            if ($value !== '') return rtrim($value, '/');
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') == 443);
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        return rtrim($scheme . '://' . $host . ($dir === '' || $dir === '/' ? '' : $dir), '/');
    }
}

if (!function_exists('sdc_fetch_dispatch_by_token')) {
    function sdc_fetch_dispatch_by_token(mysqli $conn, string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || !sdc_table_exists($conn, 'dispatches') || !sdc_col_exists($conn, 'dispatches', 'challan_token')) {
            return null;
        }
        try {
            $stmt = $conn->prepare('SELECT * FROM dispatches WHERE challan_token = ? LIMIT 1');
            $stmt->bind_param('s', $token);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('sdc_ensure_challan_token')) {
    function sdc_ensure_challan_token(mysqli $conn, int $dispatchId): string
    {
        if ($dispatchId <= 0 || !sdc_col_exists($conn, 'dispatches', 'challan_token')) {
            throw new RuntimeException('Dispatch Challan token column is missing. Run dispatch_challan_patch.sql.');
        }

        try {
            $stmt = $conn->prepare('SELECT challan_token FROM dispatches WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $dispatchId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $existing = trim((string)($row['challan_token'] ?? ''));
            if ($existing !== '') return $existing;
        } catch (Throwable $e) {}

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = bin2hex(random_bytes(32));
            try {
                $stmt = $conn->prepare('UPDATE dispatches SET challan_token = ?, challan_token_generated_at = COALESCE(challan_token_generated_at, NOW()), updated_at = NOW() WHERE id = ? AND (challan_token IS NULL OR challan_token = \'\')');
                $stmt->bind_param('si', $token, $dispatchId);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                if ($affected > 0) return $token;

                $stmt = $conn->prepare('SELECT challan_token FROM dispatches WHERE id = ? LIMIT 1');
                $stmt->bind_param('i', $dispatchId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $existing = trim((string)($row['challan_token'] ?? ''));
                if ($existing !== '') return $existing;
            } catch (Throwable $e) {
                // A rare duplicate-token collision can retry with a fresh token.
            }
        }

        throw new RuntimeException('Unable to generate Dispatch Challan secure token.');
    }
}

if (!function_exists('sdc_public_challan_url')) {
    function sdc_public_challan_url(mysqli $conn, string $token): string
    {
        return sdc_base_url($conn) . '/dispatch_challan.php?token=' . rawurlencode($token);
    }
}

if (!function_exists('sdc_fetch_job')) {
    function sdc_fetch_job(mysqli $conn, int $jobId): ?array
    {
        if ($jobId <= 0 || !sdc_table_exists($conn, 'job_cards')) return null;

        try {
            $customerJoin = sdc_table_exists($conn, 'customers')
                ? 'LEFT JOIN customers c ON c.id = jc.customer_id'
                : '';
            $customerAddress = sdc_table_exists($conn, 'customers') && sdc_col_exists($conn, 'customers', 'address')
                ? 'c.address AS customer_address,'
                : 'NULL AS customer_address,';

            $proformaJoin = sdc_table_exists($conn, 'proforma_bills')
                ? 'LEFT JOIN proforma_bills pb ON pb.id = jc.proforma_bill_id'
                : '';
            $proformaNo = sdc_table_exists($conn, 'proforma_bills') && sdc_col_exists($conn, 'proforma_bills', 'proforma_no')
                ? 'pb.proforma_no,'
                : 'NULL AS proforma_no,';

            $functionJoin = sdc_table_exists($conn, 'function_types')
                ? 'LEFT JOIN function_types ft ON ft.id = jc.function_type_id'
                : '';
            $functionName = sdc_table_exists($conn, 'function_types') && sdc_col_exists($conn, 'function_types', 'function_name')
                ? 'ft.function_name,'
                : 'NULL AS function_name,';

            $printingJoin = sdc_table_exists($conn, 'printing_types')
                ? 'LEFT JOIN printing_types pt ON pt.id = jc.printing_type_id'
                : '';
            $printingName = sdc_table_exists($conn, 'printing_types') && sdc_col_exists($conn, 'printing_types', 'printing_name')
                ? 'pt.printing_name,'
                : 'NULL AS printing_name,';

            $subJoin = sdc_table_exists($conn, 'printing_sub_types')
                ? 'LEFT JOIN printing_sub_types pst ON pst.id = jc.printing_sub_type_id'
                : '';
            $subName = sdc_table_exists($conn, 'printing_sub_types') && sdc_col_exists($conn, 'printing_sub_types', 'sub_type_name')
                ? 'pst.sub_type_name'
                : 'NULL AS sub_type_name';

            $sql = "
                SELECT
                    jc.*,
                    {$customerAddress}
                    {$proformaNo}
                    {$functionName}
                    {$printingName}
                    {$subName}
                FROM job_cards jc
                {$customerJoin}
                {$proformaJoin}
                {$functionJoin}
                {$printingJoin}
                {$subJoin}
                WHERE jc.id = ?
                LIMIT 1
            ";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $jobId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('sdc_fetch_items')) {
    function sdc_fetch_items(mysqli $conn, array $job): array
    {
        $jobId = (int)($job['id'] ?? 0);
        $proformaId = (int)($job['proforma_bill_id'] ?? 0);
        $items = [];

        if ($jobId > 0 && sdc_table_exists($conn, 'job_card_items')) {
            try {
                $hasPrinting = sdc_table_exists($conn, 'printing_types');
                $hasSub = sdc_table_exists($conn, 'printing_sub_types');
                $hasProducts = sdc_table_exists($conn, 'products');

                $stmt = $conn->prepare("
                    SELECT
                        jci.*,
                        " . ($hasProducts ? 'p.product_name AS master_product_name,' : 'NULL AS master_product_name,') . "
                        " . ($hasPrinting ? 'pt.printing_name AS item_printing_name,' : 'NULL AS item_printing_name,') . "
                        " . ($hasSub ? 'pst.sub_type_name AS item_sub_type_name' : 'NULL AS item_sub_type_name') . "
                    FROM job_card_items jci
                    " . ($hasProducts ? 'LEFT JOIN products p ON p.id = jci.product_id' : '') . "
                    " . ($hasPrinting ? 'LEFT JOIN printing_types pt ON pt.id = jci.printing_type_id' : '') . "
                    " . ($hasSub ? 'LEFT JOIN printing_sub_types pst ON pst.id = jci.printing_sub_type_id' : '') . "
                    WHERE jci.job_card_id = ?
                    ORDER BY jci.id ASC
                ");
                $stmt->bind_param('i', $jobId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) $items[] = $row;
                $stmt->close();
            } catch (Throwable $e) {
                $items = [];
            }
        }

        if (!$items && $proformaId > 0 && sdc_table_exists($conn, 'proforma_bill_items')) {
            try {
                $hasPrinting = sdc_table_exists($conn, 'printing_types');
                $hasSub = sdc_table_exists($conn, 'printing_sub_types');
                $hasProducts = sdc_table_exists($conn, 'products');

                $stmt = $conn->prepare("
                    SELECT
                        pbi.*,
                        " . ($hasProducts ? 'p.product_name AS master_product_name,' : 'NULL AS master_product_name,') . "
                        " . ($hasPrinting ? 'pt.printing_name AS item_printing_name,' : 'NULL AS item_printing_name,') . "
                        " . ($hasSub ? 'pst.sub_type_name AS item_sub_type_name' : 'NULL AS item_sub_type_name') . "
                    FROM proforma_bill_items pbi
                    " . ($hasProducts ? 'LEFT JOIN products p ON p.id = pbi.product_id' : '') . "
                    " . ($hasPrinting ? 'LEFT JOIN printing_types pt ON pt.id = pbi.printing_type_id' : '') . "
                    " . ($hasSub ? 'LEFT JOIN printing_sub_types pst ON pst.id = pbi.printing_sub_type_id' : '') . "
                    WHERE pbi.proforma_bill_id = ?
                    ORDER BY pbi.sort_order ASC, pbi.id ASC
                ");
                $stmt->bind_param('i', $proformaId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) $items[] = $row;
                $stmt->close();
            } catch (Throwable $e) {
                $items = [];
            }
        }

        if (!$items) {
            $productName = trim((string)($job['product_name'] ?? ''));
            if ($productName === '' || preg_match('/^\d+$/', $productName)) {
                $productId = (int)($job['product_id'] ?? 0);
                if ($productId > 0 && sdc_table_exists($conn, 'products')) {
                    try {
                        $stmt = $conn->prepare('SELECT product_name FROM products WHERE id = ? LIMIT 1');
                        $stmt->bind_param('i', $productId);
                        $stmt->execute();
                        $master = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        if ($master && trim((string)($master['product_name'] ?? '')) !== '') {
                            $productName = trim((string)$master['product_name']);
                        }
                    } catch (Throwable $e) {}
                }
            }

            $items[] = [
                'item_name' => $productName !== '' ? $productName : 'Cards',
                'master_product_name' => $productName !== '' ? $productName : 'Cards',
                'qty' => sdc_col_exists($conn, 'job_cards', 'qty') ? (float)($job['qty'] ?? 0) : 0,
                'description' => '',
                'item_printing_name' => (string)($job['printing_name'] ?? ''),
                'item_sub_type_name' => (string)($job['sub_type_name'] ?? ''),
                'size_text' => '',
                'gsm_thickness' => '',
            ];
        }

        foreach ($items as &$item) {
            $name = trim((string)($item['item_name'] ?? ''));
            $masterName = trim((string)($item['master_product_name'] ?? ''));
            if ($name === '' || preg_match('/^\d+$/', $name)) {
                $name = $masterName !== '' ? $masterName : 'Cards';
            }
            $item['_resolved_name'] = $name;
        }
        unset($item);

        return $items;
    }
}

if (!function_exists('sdc_fetch_dispatch')) {
    function sdc_fetch_dispatch(mysqli $conn, int $jobId, ?int $dispatchId = null): ?array
    {
        if (!sdc_table_exists($conn, 'dispatches')) return null;

        try {
            if ($dispatchId && $dispatchId > 0) {
                $stmt = $conn->prepare('SELECT * FROM dispatches WHERE id = ? AND job_card_id = ? LIMIT 1');
                $stmt->bind_param('ii', $dispatchId, $jobId);
            } else {
                $stmt = $conn->prepare('SELECT * FROM dispatches WHERE job_card_id = ? ORDER BY id DESC LIMIT 1');
                $stmt->bind_param('i', $jobId);
            }
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('sdc_next_dispatch_no')) {
    function sdc_next_dispatch_no(mysqli $conn, string $dispatchDate): string
    {
        $ts = strtotime($dispatchDate);
        $prefix = 'SC-DC-' . ($ts !== false ? date('ymd', $ts) : date('ymd')) . '-';
        $next = 1;

        if (sdc_table_exists($conn, 'dispatches')) {
            try {
                $like = $prefix . '%';
                $stmt = $conn->prepare("
                    SELECT dispatch_no
                    FROM dispatches
                    WHERE dispatch_no LIKE ?
                    ORDER BY id DESC
                    LIMIT 100
                ");
                $stmt->bind_param('s', $like);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $no = (string)($row['dispatch_no'] ?? '');
                    if (preg_match('/(\d+)$/', $no, $m)) {
                        $next = max($next, ((int)$m[1]) + 1);
                    }
                }
                $stmt->close();
            } catch (Throwable $e) {}
        }

        return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('sdc_dynamic_update')) {
    function sdc_dynamic_update(mysqli $conn, string $table, int $id, array $data): void
    {
        if ($id <= 0 || !sdc_table_exists($conn, $table)) return;

        $filtered = [];
        foreach ($data as $column => $value) {
            if (sdc_col_exists($conn, $table, $column)) $filtered[$column] = $value;
        }
        if (!$filtered) return;

        $sets = [];
        $types = '';
        $values = [];
        foreach ($filtered as $column => $value) {
            $sets[] = "`{$column}` = ?";
            if (is_int($value) || $value === null) $types .= 'i';
            elseif (is_float($value)) $types .= 'd';
            else $types .= 's';
            $values[] = $value;
        }
        $types .= 'i';
        $values[] = $id;

        $stmt = $conn->prepare("UPDATE `{$table}` SET " . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('sdc_ensure_dispatch_record')) {
    function sdc_ensure_dispatch_record(mysqli $conn, int $jobId, array $data = []): array
    {
        if (!sdc_table_exists($conn, 'dispatches')) {
            throw new RuntimeException('dispatches table is missing.');
        }

        $job = sdc_fetch_job($conn, $jobId);
        if (!$job) throw new RuntimeException('Job Card not found for Dispatch Challan.');

        $dispatch = sdc_fetch_dispatch($conn, $jobId);
        $dispatchDate = trim((string)($data['dispatch_date'] ?? ($job['dispatch_date'] ?? '')));
        if ($dispatchDate === '') $dispatchDate = date('Y-m-d');

        $deliveryMode = trim((string)($data['delivery_mode'] ?? ($job['delivery_mode'] ?? ($job['courier_name'] ?? ''))));
        $deliveryPerson = trim((string)($data['delivery_person'] ?? ($job['delivery_person'] ?? '')));
        $trackingNo = trim((string)($data['tracking_no'] ?? ($job['tracking_no'] ?? '')));
        $remarks = trim((string)($data['remarks'] ?? ($job['dispatch_remarks'] ?? ($job['remarks'] ?? ''))));

        if ($dispatch) {
            $id = (int)$dispatch['id'];
            sdc_dynamic_update($conn, 'dispatches', $id, [
                'customer_id' => !empty($job['customer_id']) ? (int)$job['customer_id'] : null,
                'job_no' => (string)($job['job_card_no'] ?? ''),
                'customer_name' => (string)($job['customer_name'] ?? ''),
                'mobile' => (string)($job['mobile'] ?? ''),
                'dispatch_date' => $dispatchDate,
                'delivery_mode' => $deliveryMode,
                'delivery_person' => $deliveryPerson,
                'courier_name' => $deliveryMode,
                'tracking_no' => $trackingNo,
                'vehicle_no' => $trackingNo,
                'remarks' => $remarks,
                'status' => 'Dispatched',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return sdc_fetch_dispatch($conn, $jobId, $id) ?: $dispatch;
        }

        $dispatchNo = sdc_next_dispatch_no($conn, $dispatchDate);
        $baseData = [
            'job_card_id' => $jobId,
            'customer_id' => !empty($job['customer_id']) ? (int)$job['customer_id'] : null,
            'dispatch_no' => $dispatchNo,
            'job_no' => (string)($job['job_card_no'] ?? ''),
            'customer_name' => (string)($job['customer_name'] ?? ''),
            'mobile' => (string)($job['mobile'] ?? ''),
            'dispatch_date' => $dispatchDate,
            'delivery_mode' => $deliveryMode,
            'delivery_person' => $deliveryPerson,
            'courier_name' => $deliveryMode,
            'tracking_no' => $trackingNo,
            'vehicle_no' => $trackingNo,
            'remarks' => $remarks,
            'status' => 'Dispatched',
            'created_by' => (int)($_SESSION['user_id'] ?? 0),
        ];

        $insert = [];
        foreach ($baseData as $column => $value) {
            if (sdc_col_exists($conn, 'dispatches', $column)) $insert[$column] = $value;
        }

        $fields = array_keys($insert);
        $placeholders = implode(',', array_fill(0, count($fields), '?'));
        $types = '';
        $values = [];
        foreach ($insert as $value) {
            if (is_int($value) || $value === null) $types .= 'i';
            elseif (is_float($value)) $types .= 'd';
            else $types .= 's';
            $values[] = $value;
        }

        $stmt = $conn->prepare("INSERT INTO dispatches (`" . implode('`,`', $fields) . "`) VALUES ({$placeholders})");
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();

        return sdc_fetch_dispatch($conn, $jobId, $id) ?: [
            'id' => $id,
            'dispatch_no' => $dispatchNo,
            'dispatch_date' => $dispatchDate,
            'delivery_mode' => $deliveryMode,
            'delivery_person' => $deliveryPerson,
            'tracking_no' => $trackingNo,
            'vehicle_no' => $trackingNo,
            'remarks' => $remarks,
        ];
    }
}

if (!function_exists('sdc_dispatch_value')) {
    function sdc_dispatch_value(array $dispatch, array $job, array $dispatchKeys, array $jobKeys = []): string
    {
        foreach ($dispatchKeys as $key) {
            $value = trim((string)($dispatch[$key] ?? ''));
            if ($value !== '') return $value;
        }
        foreach ($jobKeys as $key) {
            $value = trim((string)($job[$key] ?? ''));
            if ($value !== '') return $value;
        }
        return '';
    }
}

if (!function_exists('sdc_product_summary')) {
    function sdc_product_summary(array $items): string
    {
        $parts = [];

        foreach ($items as $item) {
            $name = trim((string)($item['_resolved_name'] ?? $item['item_name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $qty = sdc_qty($item['qty'] ?? 0);
            $parts[] = $name . ' - ' . $qty . ' Qty';
        }

        if (!$parts) {
            return 'Cards';
        }

        return implode(', ', $parts);
    }
}

if (!function_exists('sdc_total_qty')) {
    function sdc_total_qty(array $items): float
    {
        $total = 0.0;
        foreach ($items as $item) $total += (float)($item['qty'] ?? 0);
        return $total;
    }
}

if (!function_exists('sdc_split_lines')) {
    function sdc_split_lines($pdf, string $text, float $width): array
    {
        $text = trim(preg_replace('/\s+/', ' ', sdc_pdf_text($text)) ?? sdc_pdf_text($text));
        if ($text === '') return [''];

        $words = preg_split('/\s+/', $text) ?: [$text];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $test = $line === '' ? $word : $line . ' ' . $word;
            if ($pdf->GetStringWidth($test) <= $width - 2) {
                $line = $test;
            } else {
                if ($line !== '') $lines[] = $line;
                $line = $word;
            }
        }
        if ($line !== '') $lines[] = $line;
        return $lines ?: [''];
    }
}

if (!function_exists('sdc_draw_table_header')) {
    function sdc_draw_table_header($pdf): void
    {
        $widths = [10, 64, 22, 40, 49];
        $headers = ['S.No', 'Product Name', 'Quantity', 'Printing Type', 'Details'];
        $pdf->SetFillColor(11, 74, 151);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 8);
        foreach ($headers as $i => $header) {
            $pdf->Cell($widths[$i], 8, sdc_pdf_text($header), 1, 0, 'C', true);
        }
        $pdf->Ln();
        $pdf->SetTextColor(31, 41, 55);
    }
}

if (!function_exists('sdc_draw_page_header')) {
    function sdc_draw_page_header($pdf, array $job, array $dispatch): void
    {
        $logo = sdc_logo_path();
        if ($logo) {
            try {
                $pdf->Image($logo, 12, 10, 48);
            } catch (Throwable $e) {}
        } else {
            $pdf->SetXY(12, 13);
            $pdf->SetTextColor(11, 74, 151);
            $pdf->SetFont('Arial', 'B', 18);
            $pdf->Cell(75, 9, 'SUBHIKSHA CARDS', 0, 0, 'L');
        }

        $pdf->SetTextColor(11, 74, 151);
        $pdf->SetFont('Arial', 'B', 20);
        $pdf->SetXY(100, 12);
        $pdf->Cell(98, 9, 'DISPATCH CHALLAN', 0, 1, 'R');
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->SetX(100);
        $pdf->Cell(98, 5, 'Invitation Printing ERP & CRM', 0, 1, 'R');

        $pdf->SetDrawColor(242, 140, 0);
        $pdf->SetLineWidth(0.8);
        $pdf->Line(12, 34, 198, 34);
        $pdf->SetLineWidth(0.2);

        $dispatchNo = sdc_clean($dispatch['dispatch_no'] ?? '');
        $jobNo = sdc_clean($job['job_card_no'] ?? '');
        $pdf->SetTextColor(31, 41, 55);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetXY(12, 38);
        $pdf->Cell(28, 5, 'Challan No:', 0, 0);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(60, 5, sdc_pdf_text($dispatchNo), 0, 0);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(27, 5, 'Job Card No:', 0, 0);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(45, 5, sdc_pdf_text($jobNo), 0, 0);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(12, 5, 'Date:', 0, 0);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(14, 5, sdc_pdf_text(sdc_date($dispatch['dispatch_date'] ?? null)), 0, 1, 'R');
    }
}

if (!function_exists('sdc_draw_dispatch_pdf')) {
    function sdc_draw_dispatch_pdf($pdf, array $job, array $items, array $dispatch): void
    {
        $pdf->SetMargins(12, 10, 12);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->AddPage();
        sdc_draw_page_header($pdf, $job, $dispatch);

        $pdf->SetY(49);
        $leftX = 12;
        $rightX = 108;
        $boxW = 90;
        $boxH = 34;

        // Customer details box
        $pdf->SetFillColor(238, 245, 255);
        $pdf->SetDrawColor(217, 225, 234);
        $pdf->Rect($leftX, 49, $boxW, $boxH, 'DF');
        $pdf->SetXY($leftX + 4, 53);
        $pdf->SetTextColor(11, 74, 151);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(80, 5, 'CUSTOMER DETAILS', 0, 1);
        $pdf->SetX($leftX + 4);
        $pdf->SetTextColor(31, 41, 55);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(24, 5, 'Customer:', 0, 0);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(58, 5, sdc_pdf_text(sdc_clean($job['customer_name'] ?? '')), 0, 1);
        $pdf->SetX($leftX + 4);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(24, 5, 'Mobile:', 0, 0);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(58, 5, sdc_pdf_text(sdc_clean($job['mobile'] ?? '')), 0, 1);
        $pdf->SetX($leftX + 4);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(24, 5, 'Address:', 0, 0);
        $pdf->SetFont('Arial', '', 7.5);
        $address = sdc_pdf_text(sdc_clean($job['customer_address'] ?? '', '-'));
        $pdf->MultiCell(58, 4, $address, 0, 'L');

        // Dispatch details box
        $pdf->SetFillColor(255, 244, 229);
        $pdf->Rect($rightX, 49, $boxW, $boxH, 'DF');
        $pdf->SetXY($rightX + 4, 53);
        $pdf->SetTextColor(242, 140, 0);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(80, 5, 'DISPATCH DETAILS', 0, 1);

        $dispatchMode = sdc_dispatch_value($dispatch, $job, ['delivery_mode', 'courier_name'], ['delivery_mode', 'courier_name']);
        $deliveryPerson = sdc_dispatch_value($dispatch, $job, ['delivery_person'], ['delivery_person']);
        $trackingNo = sdc_dispatch_value($dispatch, $job, ['tracking_no', 'vehicle_no'], ['tracking_no']);

        $pairs = [
            ['Dispatch Mode:', $dispatchMode],
            ['Delivery Person:', $deliveryPerson],
            ['Tracking / LR No:', $trackingNo],
            ['Proforma No:', (string)($job['proforma_no'] ?? '')],
        ];
        $y = 59;
        foreach ($pairs as $pair) {
            $pdf->SetXY($rightX + 4, $y);
            $pdf->SetTextColor(31, 41, 55);
            $pdf->SetFont('Arial', 'B', 7.5);
            $pdf->Cell(29, 5, sdc_pdf_text($pair[0]), 0, 0);
            $pdf->SetFont('Arial', '', 7.5);
            $pdf->Cell(51, 5, sdc_pdf_text(sdc_clean($pair[1], '-')), 0, 1);
            $y += 5;
        }

        $pdf->SetY(89);
        sdc_draw_table_header($pdf);

        $widths = [10, 64, 22, 40, 49];
        $pdf->SetFont('Arial', '', 7.5);
        foreach ($items as $index => $item) {
            $product = sdc_clean($item['_resolved_name'] ?? $item['item_name'] ?? '', 'Cards');
            $qty = sdc_qty($item['qty'] ?? 0);
            $printing = sdc_clean($item['item_printing_name'] ?? $job['printing_name'] ?? '', '-');
            $sub = trim((string)($item['item_sub_type_name'] ?? ''));
            if ($sub !== '') $printing .= ' - ' . $sub;

            $details = [];
            foreach (['size_text' => 'Size', 'gsm_thickness' => 'GSM'] as $field => $label) {
                $value = trim((string)($item[$field] ?? ''));
                if ($value !== '') $details[] = $label . ': ' . $value;
            }
            $description = trim((string)($item['description'] ?? ''));
            if ($description !== '') $details[] = $description;
            $detailText = $details ? implode(' | ', $details) : '-';

            $cells = [
                [(string)($index + 1)],
                sdc_split_lines($pdf, $product, $widths[1]),
                [$qty],
                sdc_split_lines($pdf, $printing, $widths[3]),
                sdc_split_lines($pdf, $detailText, $widths[4]),
            ];
            $maxLines = 1;
            foreach ($cells as $cellLines) $maxLines = max($maxLines, count($cellLines));
            $rowH = max(7, ($maxLines * 4.2) + 2);

            if ($pdf->GetY() + $rowH > 252) {
                $pdf->AddPage();
                sdc_draw_page_header($pdf, $job, $dispatch);
                $pdf->SetY(49);
                sdc_draw_table_header($pdf);
                $pdf->SetFont('Arial', '', 7.5);
            }

            $x = 12;
            $y = $pdf->GetY();
            $fill = $index % 2 === 1;
            if ($fill) $pdf->SetFillColor(246, 248, 251);
            $pdf->SetDrawColor(217, 225, 234);

            foreach ($widths as $i => $w) {
                $pdf->Rect($x, $y, $w, $rowH, $fill ? 'DF' : 'D');
                $lines = $cells[$i];
                $textY = $y + 2.8;
                foreach ($lines as $line) {
                    $pdf->SetXY($x + 1, $textY);
                    if ($i === 0 || $i === 2) {
                        $pdf->Cell($w - 2, 4, sdc_pdf_text($line), 0, 0, 'C');
                    } else {
                        $pdf->Cell($w - 2, 4, sdc_pdf_text($line), 0, 0, 'L');
                    }
                    $textY += 4.2;
                }
                $x += $w;
            }
            $pdf->SetY($y + $rowH);
        }

        $pdf->Ln(6);
        $remarks = sdc_clean($dispatch['remarks'] ?? ($job['dispatch_remarks'] ?? ''), '-');
        $pdf->SetFillColor(246, 248, 251);
        $pdf->SetDrawColor(217, 225, 234);
        $startY = $pdf->GetY();
        $pdf->Rect(12, $startY, 186, 22, 'DF');
        $pdf->SetXY(16, $startY + 4);
        $pdf->SetTextColor(11, 74, 151);
        $pdf->SetFont('Arial', 'B', 8.5);
        $pdf->Cell(170, 5, 'REMARKS', 0, 1);
        $pdf->SetX(16);
        $pdf->SetTextColor(31, 41, 55);
        $pdf->SetFont('Arial', '', 8);
        $pdf->MultiCell(178, 4, sdc_pdf_text($remarks), 0, 'L');
        $pdf->SetY($startY + 25);

        if ($pdf->GetY() + 35 > 270) $pdf->AddPage();
        $sigY = $pdf->GetY();
        $pdf->SetDrawColor(217, 225, 234);
        $pdf->Rect(12, $sigY, 89, 30);
        $pdf->Rect(109, $sigY, 89, 30);
        $pdf->SetTextColor(11, 74, 151);
        $pdf->SetFont('Arial', 'B', 8.5);
        $pdf->SetXY(16, $sigY + 4);
        $pdf->Cell(80, 5, 'RECEIVER ACKNOWLEDGEMENT', 0, 0);
        $pdf->SetXY(113, $sigY + 4);
        $pdf->SetTextColor(242, 140, 0);
        $pdf->Cell(80, 5, 'FOR SUBHIKSHA CARDS', 0, 1);
        $pdf->SetDrawColor(217, 225, 234);
        $pdf->Line(16, $sigY + 21, 97, $sigY + 21);
        $pdf->Line(113, $sigY + 21, 194, $sigY + 21);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->SetFont('Arial', '', 7);
        $pdf->SetXY(16, $sigY + 23);
        $pdf->Cell(81, 4, 'Name / Signature / Date', 0, 0, 'C');
        $pdf->SetXY(113, $sigY + 23);
        $pdf->Cell(81, 4, 'Authorized Signatory', 0, 0, 'C');

        $pdf->SetY(-16);
        $pdf->SetFillColor(11, 74, 151);
        $pdf->Rect(12, $pdf->GetY(), 186, 8, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 7.5);
        $pdf->SetXY(16, $pdf->GetY() + 2.2);
        $pdf->Cell(55, 4, 'SUBHIKSHA CARDS', 0, 0, 'L');
        $pdf->SetFont('Arial', '', 7);
        $pdf->Cell(70, 4, 'Dispatch Challan - ERP Generated', 0, 0, 'C');
        $pdf->Cell(57, 4, 'Not a Tax Invoice', 0, 0, 'R');
    }
}

if (!function_exists('sdc_generate_dispatch_challan_pdf')) {
    function sdc_generate_dispatch_challan_pdf(mysqli $conn, int $jobId, ?int $dispatchId = null): array
    {
        sdc_load_fpdf();

        $job = sdc_fetch_job($conn, $jobId);
        if (!$job) throw new RuntimeException('Job Card not found.');

        $dispatch = sdc_fetch_dispatch($conn, $jobId, $dispatchId);
        if (!$dispatch) {
            $dispatch = sdc_ensure_dispatch_record($conn, $jobId, [
                'dispatch_date' => $job['dispatch_date'] ?? date('Y-m-d'),
                'delivery_mode' => $job['delivery_mode'] ?? ($job['courier_name'] ?? ''),
                'delivery_person' => $job['delivery_person'] ?? '',
                'tracking_no' => $job['tracking_no'] ?? '',
                'remarks' => $job['dispatch_remarks'] ?? ($job['remarks'] ?? ''),
            ]);
        }

        $items = sdc_fetch_items($conn, $job);
        $dispatchNo = sdc_clean($dispatch['dispatch_no'] ?? '', 'SC-DC-' . date('ymd') . '-' . str_pad((string)$jobId, 4, '0', STR_PAD_LEFT));
        $fileName = 'Dispatch_Challan_' . sdc_safe_filename($dispatchNo) . '.pdf';

        $root = sdc_root();
        $dir = $root . '/uploads/dispatch_challans';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create uploads/dispatch_challans folder.');
        }
        if (!is_writable($dir)) {
            throw new RuntimeException('uploads/dispatch_challans folder is not writable.');
        }

        $absPath = $dir . '/' . $fileName;
        $relPath = 'uploads/dispatch_challans/' . $fileName;

        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->SetTitle('Dispatch Challan - ' . $dispatchNo);
        $pdf->SetAuthor('Subhiksha Cards');
        $pdf->SetCreator('Subhiksha Cards ERP');
        sdc_draw_dispatch_pdf($pdf, $job, $items, $dispatch);
        $pdf->Output('F', $absPath);

        if (!is_file($absPath) || filesize($absPath) <= 0) {
            throw new RuntimeException('Dispatch Challan PDF was not generated.');
        }

        $dispatchRecordId = (int)($dispatch['id'] ?? 0);
        $challanToken = '';
        $publicUrl = '';
        if ($dispatchRecordId > 0) {
            sdc_dynamic_update($conn, 'dispatches', $dispatchRecordId, [
                'challan_pdf_path' => $relPath,
                'challan_generated_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $challanToken = sdc_ensure_challan_token($conn, $dispatchRecordId);
            $publicUrl = sdc_public_challan_url($conn, $challanToken);
        }

        return [
            'success' => true,
            'job_card_id' => $jobId,
            'dispatch_id' => $dispatchRecordId,
            'dispatch_no' => $dispatchNo,
            'filename' => $fileName,
            'absolute_path' => $absPath,
            'relative_path' => $relPath,
            'challan_token' => $challanToken,
            'public_url' => $publicUrl,
            'job' => $job,
            'items' => $items,
            'dispatch' => $dispatch,
        ];
    }
}

if (!function_exists('sdc_send_dispatch_challan_whatsapp')) {
    function sdc_send_dispatch_challan_whatsapp(mysqli $conn, int $jobId, ?array $pdfResult = null): array
    {
        if (!function_exists('subhiksha_send_template_whatsapp')) {
            return [
                'success' => false,
                'message' => 'WhatsApp template sender is not available. Update includes/whatsapp-api.php.',
            ];
        }

        if (!$pdfResult || empty($pdfResult['success'])) {
            $pdfResult = sdc_generate_dispatch_challan_pdf($conn, $jobId);
        }

        $job = (array)($pdfResult['job'] ?? sdc_fetch_job($conn, $jobId) ?? []);
        $items = (array)($pdfResult['items'] ?? sdc_fetch_items($conn, $job));
        $dispatch = (array)($pdfResult['dispatch'] ?? sdc_fetch_dispatch($conn, $jobId) ?? []);

        $dispatchId = (int)($dispatch['id'] ?? ($pdfResult['dispatch_id'] ?? 0));
        $token = trim((string)($pdfResult['challan_token'] ?? ''));
        if ($token === '' && $dispatchId > 0) {
            $token = sdc_ensure_challan_token($conn, $dispatchId);
        }
        if ($token === '') {
            return ['success' => false, 'message' => 'Dispatch Challan secure link could not be generated.'];
        }

        /*
         * The approved Meta template has 6 BODY variables and a dynamic URL
         * button based on job_card_id. The secure token URL is still generated
         * and kept for backward compatibility, but the WhatsApp button uses
         * the public Job Card ID URL requested for this template.
         */
        $publicUrl = sdc_base_url($conn)
            . '/dispatch_challan.php?job_card_id=' . rawurlencode((string)$jobId);

        $mobile = trim((string)($job['mobile'] ?? ''));
        $productDetails = sdc_product_summary($items);
        $totalQuantity = sdc_qty(sdc_total_qty($items));

        $variables = [
            'customer_name' => sdc_clean($job['customer_name'] ?? '', 'Customer'),
            'dispatch_challan_no' => sdc_clean($pdfResult['dispatch_no'] ?? ($dispatch['dispatch_no'] ?? ''), '-'),
            'job_card_no' => sdc_clean($job['job_card_no'] ?? '', '-'),
            'product_details' => $productDetails,
            'total_quantity' => $totalQuantity,
            'dispatch_date' => sdc_date($dispatch['dispatch_date'] ?? ($job['dispatch_date'] ?? null)),
            'job_card_id' => (string)$jobId,
            'dispatch_challan_link' => $publicUrl,
        ];

        $meta = [
            'related_module' => 'Dispatch Challan',
            'related_id' => $dispatchId > 0 ? $dispatchId : $jobId,
            'customer_id' => !empty($job['customer_id']) ? (int)$job['customer_id'] : null,
            'job_card_id' => $jobId,
            'sent_by' => (int)($_SESSION['user_id'] ?? 0),
        ];

        $result = subhiksha_send_template_whatsapp(
            $conn,
            'dispatch_challan',
            $mobile,
            $variables,
            $meta
        );

        if ($dispatchId > 0) {
            sdc_dynamic_update($conn, 'dispatches', $dispatchId, [
                'whatsapp_log_id' => !empty($result['log_id']) ? (int)$result['log_id'] : null,
                'whatsapp_message_id' => (string)($result['message_id'] ?? ''),
                'whatsapp_status' => !empty($result['success']) ? 'sent' : 'failed',
                'whatsapp_sent_at' => !empty($result['success']) ? date('Y-m-d H:i:s') : null,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $result['challan_url'] = $publicUrl;
        $result['challan_token'] = $token;
        return $result;
    }
}

if (!function_exists('sdc_output_dispatch_challan_pdf')) {
    function sdc_output_dispatch_challan_pdf(mysqli $conn, int $jobId, bool $download = false, ?int $dispatchId = null): void
    {
        $result = sdc_generate_dispatch_challan_pdf($conn, $jobId, $dispatchId);
        $file = (string)$result['absolute_path'];
        $filename = (string)$result['filename'];

        if (!is_file($file)) throw new RuntimeException('Dispatch Challan PDF not found.');

        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
        readfile($file);
        exit;
    }
}