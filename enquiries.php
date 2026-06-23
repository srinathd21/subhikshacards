<?php
/**
 * enquiries.php
 * Subhiksha Cards ERP - Enquiries
 */

require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'enquiries.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['enquiries_csrf'])) {
    $_SESSION['enquiries_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['enquiries_csrf'];
$message = '';
$messageType = 'success';

function mp_table_exists(mysqli $conn, string $table): bool
{
    try {
        $table = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '{${table}}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) $res->free();
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

function mp_post(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function mp_int($value): int
{
    return (int)filter_var($value, FILTER_SANITIZE_NUMBER_INT);
}

function mp_redirect(string $query = ''): void
{
    header('Location: enquiries.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

function mp_csrf(): void
{
    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['enquiries_csrf']) ||
        !hash_equals($_SESSION['enquiries_csrf'], (string)$_POST['csrf_token'])
    ) {
        http_response_code(400);
        die('Invalid CSRF token.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mp_csrf();

    try {
        $action = mp_post('action');

        if ($action === 'save_record') {
            if (!mp_table_exists($conn, 'enquiries')) {
                throw new RuntimeException('enquiries table is missing. Run the SQL file first.');
            }

            $id = mp_int($_POST['id'] ?? 0);
            $customer_name = mp_post('customer_name');
            $mobile = mp_post('mobile');
            $event_type = mp_post('event_type');
            $event_date = mp_post('event_date');
            $notes = mp_post('notes');
            $status = mp_post('status', 'Active');

            if ($customer_name === '' || $mobile === '' || $event_type === '' || $event_date === '') {
                throw new RuntimeException('Required fields are missing.');
            }

            if ($id > 0) {
                $stmt = $conn->prepare("
                    UPDATE enquiries
                    SET customer_name=?, mobile=?, event_type=?, event_date=?, notes=?, status=?, updated_at=NOW()
                    WHERE id = ?
                ");
                $stmt->bind_param('ssssssi', $customer_name, $mobile, $event_type, $event_date, $notes, $status, $id);
                $stmt->execute();
                $stmt->close();

                mp_redirect('msg=updated');
            }

            $stmt = $conn->prepare("
                INSERT INTO enquiries
                    (customer_name, mobile, event_type, event_date, notes, status, created_by, created_at, updated_at)
                VALUES
                    (?,?,?,?,?,?, ?, NOW(), NOW())
            ");
            $createdBy = (int)($_SESSION['user_id'] ?? 0);
            $stmt->bind_param('ssssssi', $customer_name, $mobile, $event_type, $event_date, $notes, $status, $createdBy);
            $stmt->execute();
            $stmt->close();

            mp_redirect('msg=created');
        }

        if ($action === 'delete_record') {
            $id = mp_int($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid record.');

            $stmt = $conn->prepare("UPDATE enquiries SET status='Inactive', updated_at=NOW() WHERE id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            mp_redirect('msg=deleted');
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

$msg = (string)($_GET['msg'] ?? '');
if ($msg === 'created') $message = 'Enquiries created successfully.';
elseif ($msg === 'updated') $message = 'Enquiries updated successfully.';
elseif ($msg === 'deleted') $message = 'Enquiries disabled successfully.';

$rows = [];
if (mp_table_exists($conn, 'enquiries')) {
    try {
        $res = $conn->query("SELECT * FROM enquiries ORDER BY id DESC LIMIT 300");
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
    } catch (Throwable $e) {
        $rows = [];
    }
}

$totalRows = count($rows);
$activeRows = 0;
foreach ($rows as $row) {
    if (strtolower((string)($row['status'] ?? 'active')) === 'active') $activeRows++;
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Enquiries - Subhiksha Cards</title>
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

        .stat-card {
            padding: 18px;
            min-height: 112px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .stat-icon {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            display: grid;
            place-items: center;
            color: #fff;
            flex: 0 0 auto;
        }

        .stat-card span {
            display: block;
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 900;
            text-transform: uppercase;
        }

        .stat-card strong {
            font-size: 24px;
            font-weight: 900;
            color: var(--text-main);
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

        .status-pill.pending {
            color: var(--warning-color);
            background: color-mix(in srgb, var(--warning-color) 14%, transparent);
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

        .modal-content {
            border: 0;
            border-radius: 22px;
            background: var(--card-bg);
            color: var(--text-main);
        }

        .modal-header,
        .modal-footer {
            border-color: var(--border-soft);
        }

        .small-muted {
            display: block;
            margin-top: 3px;
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 700;
        }

        .mobile-cards {
            display: none;
        }

        .mobile-card {
            border: 1px solid var(--border-soft);
            background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
            border-radius: 18px;
            padding: 16px;
            margin-bottom: 12px;
        }

        .mobile-card-title {
            font-size: 16px;
            font-weight: 900;
            color: var(--text-main);
        }

        .mobile-card-subtitle {
            display: block;
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 700;
            margin-top: 4px;
            word-break: break-word;
        }

        .mobile-card-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }

        @media(max-width:767.98px) {
            .module-page .page-head {
                padding: 18px;
                border-radius: 18px;
            }

            .module-page .page-head h1 {
                font-size: 24px;
            }

            .module-page .page-head .btn {
                width: 100%;
            }

            .module-card {
                padding: 16px;
                border-radius: 18px;
            }

            .desktop-table {
                display: none !important;
            }

            .mobile-cards {
                display: block;
            }

            .mobile-card-actions .btn,
            .mobile-card-actions form {
                flex: 1 1 auto;
            }

            .mobile-card-actions .btn {
                width: 100%;
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
                            <h1 class="mb-1">Enquiries</h1>
                            <p class="text-muted-custom mb-0">Manage enquiries records.</p>
                        </div>

                        <button type="button" class="btn btn-primary rounded-pill px-4 fw-bold" id="newRecordBtn"
                            data-bs-toggle="modal" data-bs-target="#recordModal">
                            Create New
                        </button>
                    </div>
                </div>

                <?php if ($message !== ''): ?>
                    <div class="alert alert-<?= e($messageType) ?> rounded-4 fw-bold">
                        <?= e($message) ?>
                    </div>
                <?php endif; ?>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-4">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#2563eb,#0ea5e9)">
                                <i data-lucide="phone"></i>
                            </div>
                            <div>
                                <span>Total Records</span>
                                <strong><?= number_format($totalRows) ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#16a34a,#22c55e)">
                                <i data-lucide="check-circle-2"></i>
                            </div>
                            <div>
                                <span>Active</span>
                                <strong><?= number_format($activeRows) ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#f97316)">
                                <i data-lucide="clock"></i>
                            </div>
                            <div>
                                <span>Latest</span>
                                <strong><?= number_format(min($totalRows, 300)) ?></strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-ui module-card">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="module-title">Enquiries List</h2>
                            <p class="text-muted-custom mb-0">Tabular view on desktop and card view on mobile.</p>
                        </div>

                        <div style="max-width:340px;width:100%">
                            <input type="search" id="tableSearch" class="form-control" placeholder="Search...">
                        </div>
                    </div>

                    <div class="table-responsive desktop-table">
                        <table class="table-ui" id="dataTable">
                            <thead>
                                <tr>
                                    <th>Customer Name</th><th>Mobile</th><th>Event Type</th><th>Event Date</th><th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$rows): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted-custom py-4">
                                            No records found.
                                        </td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td><?= e((string)($row['customer_name'] ?? '-')) ?></td><td><?= e((string)($row['mobile'] ?? '-')) ?></td><td><?= e((string)($row['event_type'] ?? '-')) ?></td><td><?= e((string)($row['event_date'] ?? '-')) ?></td><td><span class="status-pill <?= strtolower((string)$row['status']) === 'active' ? 'active' : 'pending' ?>"><?= e($row['status'] ?? 'Pending') ?></span></td>
                                        <td class="text-end">
                                            <button type="button"
                                                class="btn btn-sm btn-outline-primary rounded-pill fw-bold js-edit-record"
                                                data-bs-toggle="modal"
                                                data-bs-target="#recordModal"
                                                data-id="<?= e($row['id']) ?>"
                                                data-customer-name="<?= e($row['customer_name'] ?? '') ?>"
data-mobile="<?= e($row['mobile'] ?? '') ?>"
data-event-type="<?= e($row['event_type'] ?? '') ?>"
data-event-date="<?= e($row['event_date'] ?? '') ?>"
data-notes="<?= e($row['notes'] ?? '') ?>"
                                                data-status="<?= e($row['status'] ?? 'Active') ?>">
                                                Edit
                                            </button>

                                            <?php if (strtolower((string)($row['status'] ?? 'active')) !== 'inactive'): ?>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Disable this record?')">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="delete_record">
                                                    <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill fw-bold">
                                                        Disable
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mobile-cards" id="mobileCards">
                        <?php if (!$rows): ?>
                            <div class="mobile-card text-center text-muted-custom">No records found.</div>
                        <?php endif; ?>

                        <?php foreach ($rows as $row): ?>
                            <div class="mobile-card">
                                <div class="d-flex justify-content-between gap-2">
                                    <div>
                                        <div class="mobile-card-title"><?= e((string)($row['customer_name'] ?? 'Enquiries')) ?></div>
                                        <span class="mobile-card-subtitle">Customer Name: <?= e((string)($row['customer_name'] ?? '-')) ?></span>
<span class="mobile-card-subtitle">Mobile: <?= e((string)($row['mobile'] ?? '-')) ?></span>
<span class="mobile-card-subtitle">Event Type: <?= e((string)($row['event_type'] ?? '-')) ?></span>
<span class="mobile-card-subtitle">Event Date: <?= e((string)($row['event_date'] ?? '-')) ?></span>
                                    </div>
                                    <span class="status-pill <?= strtolower((string)($row['status'] ?? 'active')) === 'active' ? 'active' : 'pending' ?>">
                                        <?= e($row['status'] ?? 'Active') ?>
                                    </span>
                                </div>

                                <div class="mobile-card-actions">
                                    <button type="button"
                                        class="btn btn-sm btn-outline-primary rounded-pill fw-bold js-edit-record"
                                        data-bs-toggle="modal"
                                        data-bs-target="#recordModal"
                                        data-id="<?= e($row['id']) ?>"
                                        data-customer-name="<?= e($row['customer_name'] ?? '') ?>"
data-mobile="<?= e($row['mobile'] ?? '') ?>"
data-event-type="<?= e($row['event_type'] ?? '') ?>"
data-event-date="<?= e($row['event_date'] ?? '') ?>"
data-notes="<?= e($row['notes'] ?? '') ?>"
                                        data-status="<?= e($row['status'] ?? 'Active') ?>">
                                        Edit
                                    </button>

                                    <?php if (strtolower((string)($row['status'] ?? 'active')) !== 'inactive'): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Disable this record?')">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="delete_record">
                                            <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill fw-bold">
                                                Disable
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        </main>

        <div id="settingsOverlay"></div>
        <?php include __DIR__ . '/includes/rightsidebar.php'; ?>
    </div>

    <div class="modal fade" id="recordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <form method="post" class="modal-content">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="save_record">
                <input type="hidden" name="id" id="id" value="">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="recordModalTitle">Create Enquiries</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label fw-bold">Customer Name</label><input type="text" name="customer_name" id="customer_name" class="form-control"></div>
<div class="col-md-6"><label class="form-label fw-bold">Mobile</label><input type="text" name="mobile" id="mobile" class="form-control"></div>
<div class="col-md-6"><label class="form-label fw-bold">Event Type</label><input type="text" name="event_type" id="event_type" class="form-control"></div>
<div class="col-md-6"><label class="form-label fw-bold">Event Date</label><input type="date" name="event_date" id="event_date" class="form-control"></div>
<div class="col-12"><label class="form-label fw-bold">Notes</label><textarea name="notes" id="notes" rows="3" class="form-control"></textarea></div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Status</label>
                            <select name="status" id="status" class="form-select">
                                <option value="Active">Active</option>
                                <option value="Pending">Pending</option>
                                <option value="Completed">Completed</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold" id="recordSubmitBtn">
                        Save
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php include __DIR__ . '/includes/script.php'; ?>

    <script>
        (function () {
            const title = document.getElementById('recordModalTitle');
            const submit = document.getElementById('recordSubmitBtn');

            function set(id, value) {
                const el = document.getElementById(id);
                if (!el) return;
                el.value = value == null ? '' : value;
            }

            document.getElementById('newRecordBtn')?.addEventListener('click', function () {
                title.textContent = 'Create Enquiries';
                submit.textContent = 'Save';
                set('id', '');
                set('customer_name', '');
                set('mobile', '');
                set('event_type', '');
                set('event_date', '');
                set('notes', '');
                set('status', 'Active');
            });

            document.querySelectorAll('.js-edit-record').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    title.textContent = 'Edit Enquiries';
                    submit.textContent = 'Update';

                    set('id', btn.dataset.id || '');
                    set('customer_name', btn.dataset.customerName || '');
                    set('mobile', btn.dataset.mobile || '');
                    set('event_type', btn.dataset.eventType || '');
                    set('event_date', btn.dataset.eventDate || '');
                    set('notes', btn.dataset.notes || '');
                    set('status', btn.dataset.status || 'Active');
                });
            });

            document.getElementById('tableSearch')?.addEventListener('input', function () {
                const value = this.value.toLowerCase().trim();

                document.querySelectorAll('#dataTable tbody tr').forEach(function (row) {
                    row.style.display = row.textContent.toLowerCase().includes(value) ? '' : 'none';
                });

                document.querySelectorAll('#mobileCards .mobile-card').forEach(function (card) {
                    card.style.display = card.textContent.toLowerCase().includes(value) ? '' : 'none';
                });
            });

            if (window.lucide && typeof window.lucide.createIcons === 'function') {
                window.lucide.createIcons();
            }
        })();
    </script>
</body>

</html>
