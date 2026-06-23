<?php
/**
 * customer-tracking-links.php
 * Admin/Internal page to generate customer tracking links.
 */

require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'customer-tracking-links.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['tracking_links_csrf'])) {
    $_SESSION['tracking_links_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['tracking_links_csrf'];
$message = '';
$messageType = 'success';

function tl_table_exists(mysqli $conn, string $table): bool
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

function tl_csrf(): void
{
    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['tracking_links_csrf']) ||
        !hash_equals($_SESSION['tracking_links_csrf'], (string)$_POST['csrf_token'])
    ) {
        http_response_code(400);
        die('Invalid CSRF token.');
    }
}

function tl_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

    return $scheme . '://' . $host . $dir;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tl_csrf();

    try {
        $jobCardId = (int)($_POST['job_card_id'] ?? 0);
        $expiresAt = trim((string)($_POST['expires_at'] ?? ''));

        if ($jobCardId <= 0) {
            throw new RuntimeException('Please select a job card.');
        }

        $stmt = $conn->prepare("SELECT id, mobile FROM job_cards WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $jobCardId);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$job) {
            throw new RuntimeException('Job card not found.');
        }

        $token = bin2hex(random_bytes(24));
        $mobile = (string)$job['mobile'];
        $createdBy = (int)($_SESSION['user_id'] ?? 0);
        $expiresValue = $expiresAt !== '' ? $expiresAt : null;

        $stmt = $conn->prepare("
            INSERT INTO customer_tracking_links
                (job_card_id, tracking_token, mobile, is_active, expires_at, created_by, created_at)
            VALUES
                (?, ?, ?, 1, ?, ?, NOW())
        ");
        $stmt->bind_param('isssi', $jobCardId, $token, $mobile, $expiresValue, $createdBy);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE job_cards SET tracking_token = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('si', $token, $jobCardId);
        $stmt->execute();
        $stmt->close();

        header('Location: customer-tracking-links.php?msg=created');
        exit;
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

if (($_GET['msg'] ?? '') === 'created') {
    $message = 'Tracking link generated successfully.';
}

$jobs = [];
try {
    $res = $conn->query("
        SELECT id, job_card_no, customer_name, mobile, product_name, order_type, delivery_date
        FROM job_cards
        ORDER BY id DESC
        LIMIT 300
    ");
    while ($row = $res->fetch_assoc()) {
        $jobs[] = $row;
    }
} catch (Throwable $e) {
    $jobs = [];
}

$links = [];
try {
    $res = $conn->query("
        SELECT
            ctl.*,
            jc.job_card_no,
            jc.customer_name,
            jc.product_name
        FROM customer_tracking_links ctl
        INNER JOIN job_cards jc ON jc.id = ctl.job_card_id
        ORDER BY ctl.id DESC
        LIMIT 300
    ");
    while ($row = $res->fetch_assoc()) {
        $links[] = $row;
    }
} catch (Throwable $e) {
    $links = [];
}

$baseUrl = tl_base_url();
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Customer Tracking Links - Subhiksha Cards</title>
    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>

    <style>
        .tracking-page .page-head {
            padding: 24px 28px;
            margin-bottom: 18px;
        }

        .tracking-page .page-head h1 {
            font-size: 30px;
            font-weight: 900;
            color: var(--text-main);
        }

        .tracking-card {
            padding: 24px;
        }

        .tracking-title {
            font-size: 18px;
            font-weight: 900;
            color: var(--text-main);
            margin: 0;
        }

        .status-pill {
            font-size: 11px;
            font-weight: 900;
            border-radius: 999px;
            padding: 5px 9px;
        }

        .status-pill.active {
            color: var(--success-color);
            background: color-mix(in srgb, var(--success-color) 14%, transparent);
        }

        .status-pill.inactive {
            color: var(--danger-color);
            background: color-mix(in srgb, var(--danger-color) 14%, transparent);
        }

        .form-control,
        .form-select {
            border-radius: 14px;
            min-height: 46px;
        }

        .link-box {
            max-width: 380px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 700;
        }

        @media(max-width:767.98px) {
            .tracking-page .page-head {
                padding: 18px;
                border-radius: 18px;
            }

            .tracking-page .page-head h1 {
                font-size: 24px;
            }

            .tracking-card {
                padding: 16px;
                border-radius: 18px;
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

            <section class="page-section tracking-page">
                <div class="card-ui page-head">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                        <div>
                            <h1 class="mb-1">Customer Tracking Links</h1>
                            <p class="text-muted-custom mb-0">
                                Generate public tracking links for customers without login.
                            </p>
                        </div>

                        <a href="customer-tracking.php" target="_blank" class="btn btn-outline-primary rounded-pill px-4 fw-bold">
                            Open Tracking Page
                        </a>
                    </div>
                </div>

                <?php if ($message !== ''): ?>
                    <div class="alert alert-<?= e($messageType) ?> rounded-4 fw-bold">
                        <?= e($message) ?>
                    </div>
                <?php endif; ?>

                <div class="card-ui tracking-card mb-3">
                    <h2 class="tracking-title mb-3">Generate New Tracking Link</h2>

                    <form method="post" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                        <div class="col-12 col-lg-6">
                            <label class="form-label fw-bold">Job Card</label>
                            <select name="job_card_id" class="form-select" required>
                                <option value="">Select Job Card</option>
                                <?php foreach ($jobs as $job): ?>
                                    <option value="<?= e($job['id']) ?>">
                                        <?= e($job['job_card_no']) ?> - <?= e($job['customer_name']) ?> - <?= e($job['mobile']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-lg-3">
                            <label class="form-label fw-bold">Expiry Date</label>
                            <input type="datetime-local" name="expires_at" class="form-control">
                        </div>

                        <div class="col-12 col-lg-3 d-flex align-items-end">
                            <button class="btn btn-primary rounded-pill px-4 fw-bold w-100">
                                Generate Link
                            </button>
                        </div>
                    </form>
                </div>

                <div class="card-ui tracking-card">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="tracking-title">Generated Links</h2>
                            <p class="text-muted-custom mb-0">Copy and send link through WhatsApp.</p>
                        </div>

                        <input type="search" id="linkSearch" class="form-control" style="max-width:340px" placeholder="Search links...">
                    </div>

                    <div class="table-responsive">
                        <table class="table-ui" id="linkTable">
                            <thead>
                                <tr>
                                    <th>Job</th>
                                    <th>Customer</th>
                                    <th>Link</th>
                                    <th>Views</th>
                                    <th>Expiry</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (!$links): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted-custom py-4">
                                            No tracking links found.
                                        </td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($links as $link): ?>
                                    <?php $fullLink = $baseUrl . '/customer-tracking.php?token=' . $link['tracking_token']; ?>
                                    <tr>
                                        <td>
                                            <strong><?= e($link['job_card_no']) ?></strong>
                                            <small class="d-block text-muted-custom"><?= e($link['product_name'] ?? '') ?></small>
                                        </td>
                                        <td><?= e($link['customer_name']) ?><small class="d-block text-muted-custom"><?= e($link['mobile']) ?></small></td>
                                        <td><div class="link-box"><?= e($fullLink) ?></div></td>
                                        <td><?= e($link['view_count'] ?? 0) ?></td>
                                        <td><?= !empty($link['expires_at']) ? e(date('d-m-Y h:i A', strtotime($link['expires_at']))) : 'No expiry' ?></td>
                                        <td>
                                            <span class="status-pill <?= (int)$link['is_active'] === 1 ? 'active' : 'inactive' ?>">
                                                <?= (int)$link['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <a href="<?= e($fullLink) ?>" target="_blank" class="btn btn-sm btn-outline-secondary rounded-pill fw-bold">
                                                Open
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill fw-bold js-copy-link"
                                                data-link="<?= e($fullLink) ?>">
                                                Copy
                                            </button>
                                            <a href="https://wa.me/91<?= e(preg_replace('/\D+/', '', $link['mobile'])) ?>?text=<?= urlencode('Track your Subhiksha Cards order: ' . $fullLink) ?>"
                                               target="_blank" class="btn btn-sm btn-success rounded-pill fw-bold">
                                                WhatsApp
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>

        <div id="settingsOverlay"></div>
        <?php include __DIR__ . '/includes/rightsidebar.php'; ?>
    </div>

    <?php include __DIR__ . '/includes/script.php'; ?>

    <script>
        document.querySelectorAll('.js-copy-link').forEach(function (btn) {
            btn.addEventListener('click', function () {
                navigator.clipboard.writeText(btn.dataset.link || '');
                btn.textContent = 'Copied';
                setTimeout(function () {
                    btn.textContent = 'Copy';
                }, 1400);
            });
        });

        document.getElementById('linkSearch')?.addEventListener('input', function () {
            const value = this.value.toLowerCase().trim();
            document.querySelectorAll('#linkTable tbody tr').forEach(function (row) {
                row.style.display = row.textContent.toLowerCase().includes(value) ? '' : 'none';
            });
        });

        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    </script>
</body>

</html>
