<?php
/**
 * customer_proforma_bill.php
 * Public mobile-friendly Proforma Invoice preview for WhatsApp customers.
 * No authentication and no database schema change. Shows only PDF preview + Open / Download options.
 */
require_once __DIR__ . '/includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    die('Invalid Proforma Invoice.');
}

function cpv_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function cpv_date($value): string
{
    return !empty($value) ? date('d-m-Y', strtotime((string)$value)) : '-';
}

$bill = null;
try {
    $stmt = $conn->prepare("\n        SELECT\n            pb.id,\n            pb.proforma_no,\n            pb.created_at,\n            pb.delivery_date,\n            pb.customer_name,\n            pb.mobile,\n            pb.final_amount,\n            pb.balance_amount,\n            ps.status_name\n        FROM proforma_bills pb\n        LEFT JOIN proforma_statuses ps ON ps.id = pb.proforma_status_id\n        WHERE pb.id = ?\n        LIMIT 1\n    ");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $bill = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (Throwable $e) {
    $bill = null;
}

if (!$bill) {
    http_response_code(404);
    die('Proforma Invoice not found.');
}

$downloadUrl = 'proforma_bill_pdf.php?id=' . (int)$id . '&public=1&download=1';
$viewPdfUrl = 'proforma_bill_pdf.php?id=' . (int)$id . '&public=1';
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Proforma Invoice <?= cpv_e($bill['proforma_no'] ?? '') ?></title>
    <style>
    :root {
        --blue: #1d4ed8;
        --blue-soft: #eff6ff;
        --border: #dbe3ef;
        --text: #111827;
        --muted: #64748b;
        --bg: #f5f7fb;
        --card: #ffffff;
        --green-bg: #dcfce7;
        --green-text: #166534;
    }

    * {
        box-sizing: border-box;
    }

    html,
    body {
        min-height: 100%;
    }

    body {
        margin: 0;
        font-family: Arial, Helvetica, sans-serif;
        background: var(--bg);
        color: var(--text);
        padding: 14px;
    }

    .wrap {
        max-width: 980px;
        margin: 0 auto;
    }

    .top-card {
        background: linear-gradient(135deg, #ffffff, #eef4ff);
        border: 1px solid var(--border);
        border-radius: 22px;
        padding: 18px;
        box-shadow: 0 18px 50px rgba(15, 23, 42, .08);
        margin-bottom: 14px;
    }

    .brand {
        display: flex;
        justify-content: space-between;
        gap: 14px;
        align-items: flex-start;
    }

    .brand h1 {
        margin: 0;
        font-size: 27px;
        letter-spacing: .03em;
        line-height: 1.1;
    }

    .brand small {
        display: block;
        color: var(--muted);
        font-weight: 800;
        margin-top: 6px;
        line-height: 1.4;
    }

    .badge {
        display: inline-flex;
        padding: 7px 12px;
        border-radius: 999px;
        background: var(--green-bg);
        color: var(--green-text);
        font-weight: 900;
        font-size: 12px;
        white-space: nowrap;
    }

    .invoice-line {
        display: flex;
        flex-wrap: wrap;
        gap: 10px 22px;
        margin-top: 14px;
        color: var(--muted);
        font-size: 13px;
        font-weight: 800;
    }

    .invoice-line b {
        color: var(--text);
    }

    .btn-row {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
        margin-top: 16px;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 48px;
        text-decoration: none;
        border-radius: 999px;
        padding: 12px 18px;
        font-weight: 900;
        border: 1px solid var(--blue);
        color: var(--blue);
        background: #fff;
        text-align: center;
    }

    .btn.primary {
        background: var(--blue);
        color: #fff;
    }

    .preview-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 22px;
        padding: 12px;
        box-shadow: 0 12px 36px rgba(15, 23, 42, .06);
    }

    .preview-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        padding: 8px 8px 12px;
        color: var(--muted);
        font-weight: 900;
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: .04em;
    }

    .pdf-frame {
        width: 100%;
        height: 78vh;
        min-height: 580px;
        border: 1px solid var(--border);
        border-radius: 16px;
        background: #fff;
        display: block;
    }

    .mobile-help {
        display: none;
        margin-top: 10px;
        color: var(--muted);
        font-size: 12px;
        font-weight: 800;
        line-height: 1.45;
        text-align: center;
    }

    .footer {
        text-align: center;
        color: var(--muted);
        font-size: 12px;
        font-weight: 800;
        margin: 14px 0 4px;
    }

    @media (max-width: 720px) {
        body {
            padding: 10px;
        }

        .top-card {
            border-radius: 18px;
            padding: 15px;
        }

        .brand {
            display: block;
        }

        .brand h1 {
            font-size: 22px;
        }

        .badge {
            margin-top: 10px;
        }

        .invoice-line {
            display: block;
            line-height: 1.8;
        }

        .btn-row {
            grid-template-columns: 1fr;
        }

        .btn {
            width: 100%;
        }

        .preview-card {
            border-radius: 18px;
            padding: 8px;
        }

        .preview-head {
            display: block;
            line-height: 1.6;
        }

        .pdf-frame {
            height: 70vh;
            min-height: 520px;
            border-radius: 14px;
        }

        .mobile-help {
            display: block;
        }
    }
    </style>
</head>

<body>
    <div class="wrap">
        <div class="top-card">
            <div class="brand">
                <div>
                    <h1>SUBHIKSHA CARDS</h1>
                    <small>A unit of Mani Paper Card Company • Dharmapuri</small>
                </div>
                <div><span class="badge">PROFORMA INVOICE</span></div>
            </div>
            <div class="invoice-line">
                <span>No: <b><?= cpv_e($bill['proforma_no'] ?? '-') ?></b></span>
                <span>Date: <b><?= cpv_e(cpv_date($bill['created_at'] ?? date('Y-m-d'))) ?></b></span>
                <span>Customer: <b><?= cpv_e($bill['customer_name'] ?? '-') ?></b></span>
                <span>Status: <b><?= cpv_e($bill['status_name'] ?? 'Confirmed') ?></b></span>
            </div>
            <div class="btn-row">
                <a class="btn primary" href="<?= cpv_e($viewPdfUrl) ?>" target="_blank" rel="noopener">Open PDF</a>
                <a class="btn" href="<?= cpv_e($downloadUrl) ?>">Download PDF</a>
            </div>
        </div>

        <div class="preview-card">
            <div class="preview-head">
                <span>PDF Preview</span>
                <span>Use Open PDF / Download PDF for full screen view</span>
            </div>
            <iframe class="pdf-frame" src="<?= cpv_e($viewPdfUrl) ?>" title="Proforma Invoice PDF Preview"></iframe>
            <div class="mobile-help">If the PDF preview does not load on your mobile, tap Open PDF or Download PDF.
            </div>
        </div>

        <div class="footer">Thank you for choosing Subhiksha Cards.</div>
    </div>
</body>

</html>