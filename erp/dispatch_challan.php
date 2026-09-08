<?php
/**
 * dispatch_challan.php
 *
 * Supported access modes:
 * 1. Public customer link by Job Card ID:
 *      ?job_card_id=<id>
 *
 * 2. Existing secure token link (kept for backward compatibility):
 *      ?token=<secure_token>
 *
 * Optional:
 *      &download=1   Force PDF download instead of inline preview.
 *
 * NOTE:
 * The job_card_id mode is intentionally public because the WhatsApp template
 * button is configured as:
 * https://subhikshacards.in/erp/dispatch_challan.php?job_card_id={{1}}
 */

require_once __DIR__ . '/includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/dispatch-challan-pdf.php';

$token = trim((string)($_GET['token'] ?? ''));
$download = (int)($_GET['download'] ?? 0) === 1;
$jobId = (int)($_GET['job_card_id'] ?? $_GET['id'] ?? 0);
$dispatchId = null;

/*
 * Backward-compatible secure-token access.
 * If a token is supplied, it takes priority over job_card_id.
 */
if ($token !== '') {
    $dispatch = sdc_fetch_dispatch_by_token($conn, $token);

    if (!$dispatch) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Dispatch Challan link is invalid or unavailable.');
    }

    $jobId = (int)($dispatch['job_card_id'] ?? 0);
    $dispatchId = (int)($dispatch['id'] ?? 0);
}

/*
 * Public WhatsApp link mode:
 * ?job_card_id=87
 *
 * No ERP login is required because the customer opens this link directly
 * from WhatsApp.
 */
if ($jobId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Invalid Dispatch Challan request.');
}

try {
    sdc_output_dispatch_challan_pdf(
        $conn,
        $jobId,
        $download,
        $dispatchId && $dispatchId > 0 ? $dispatchId : null
    );
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Unable to generate Dispatch Challan: ' . $e->getMessage();
}