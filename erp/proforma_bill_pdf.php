<?php
/**
 * proforma_bill_pdf.php
 * Single public/admin endpoint for the shared Proforma PDF include.
 *
 * The invoice layout is defined only in:
 * includes/proforma-bill-pdf.php
 */

require_once __DIR__ . '/includes/db.php';

$publicAccess = isset($_GET['public']) && (string)$_GET['public'] === '1';
if (!$publicAccess) {
    require_once __DIR__ . '/includes/auth.php';

    $allowed = false;
    $roleKey = strtolower(trim((string)($_SESSION['role_key'] ?? $_SESSION['role'] ?? $_SESSION['user_role'] ?? '')));
    if (in_array($roleKey, ['admin', 'super_admin', 'superadmin'], true)) {
        $allowed = true;
    }

    if (!$allowed) {
        foreach (['can_view', 'can_create', 'can_update', 'can_edit'] as $fn) {
            if (!function_exists($fn)) continue;
            try {
                if ((bool)$fn($conn, 'proforma_bills.php')) {
                    $allowed = true;
                    break;
                }
            } catch (ArgumentCountError $e) {
                try {
                    if ((bool)$fn('proforma_bills.php')) {
                        $allowed = true;
                        break;
                    }
                } catch (Throwable $inner) {
                }
            } catch (Throwable $e) {
            }
        }
    }

    if (!$allowed && function_exists('require_permission')) {
        require_permission($conn, 'can_view', 'proforma_bills.php');
        $allowed = true;
    }

    if (!$allowed) {
        http_response_code(403);
        die('Access denied.');
    }
}

if (isset($_GET['layout_check']) && (string)$_GET['layout_check'] === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Subhiksha Proforma Layout: closer-title-value-v10';
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    die('Invalid proforma bill.');
}

require_once __DIR__ . '/includes/proforma-bill-pdf.php';

try {
    $download = isset($_GET['download']) && (string)$_GET['download'] === '1';
    sbp_output_proforma_pdf_inline($conn, $id, $download);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    die('PDF generation failed: ' . $e->getMessage());
}