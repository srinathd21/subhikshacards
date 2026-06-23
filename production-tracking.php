<?php
/**
 * production-tracking.php
 * Subhiksha Cards ERP - Production Tracking with stage filter
 */

require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'production-tracking.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['production_tracking_csrf'])) {
    $_SESSION['production_tracking_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['production_tracking_csrf'];
$message = '';
$messageType = 'success';

$stages = [
    '' => 'All',
    'design' => 'Design / Proofing',
    'approval' => 'Approval',
    'printing' => 'Printing',
    'cutting_packing' => 'Cutting & Packing',
    'quality' => 'Quality Check',
    'dispatch' => 'Ready for Dispatch',
];

$stage = trim((string)($_GET['stage'] ?? ''));
if (!array_key_exists($stage, $stages)) {
    $stage = '';
}

function pt_table_exists(mysqli $conn, string $table): bool
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

function pt_post(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function pt_int($value): int
{
    return (int)filter_var($value, FILTER_SANITIZE_NUMBER_INT);
}

function pt_redirect(string $query = ''): void
{
    header('Location: production-tracking.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

function pt_csrf(): void
{
    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['production_tracking_csrf']) ||
        !hash_equals($_SESSION['production_tracking_csrf'], (string)$_POST['csrf_token'])
    ) {
        http_response_code(400);
        die('Invalid CSRF token.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pt_csrf();

    try {
        $action = pt_post('action');

        if ($action === 'save_job') {
            if (!pt_table_exists($conn, 'production_jobs')) {
                throw new RuntimeException('production_jobs table is missing. Run the SQL file first.');
            }

            $id = pt_int($_POST['id'] ?? 0);
            $jobNo = pt_post('job_no');
            $customerName = pt_post('customer_name');
            $mobile = pt_post('mobile');
            $orderNo = pt_post('order_no');
            $itemName = pt_post('item_name');
            $quantity = pt_int($_POST['quantity'] ?? 0);
            $currentStage = pt_post('current_stage', 'design');
            $priority = pt_post('priority', 'Normal');
            $deliveryDate = pt_post('delivery_date');
            $notes = pt_post('notes');
            $status = pt_post('status', 'Active');

            if ($jobNo === '') {
                $jobNo = 'JOB-' . date('ymd') . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            }

            if ($customerName === '' || $itemName === '') {
                throw new RuntimeException('Customer name and item name are required.');
            }

            if ($id > 0) {
                $stmt = $conn->prepare("
                    UPDATE production_jobs
                    SET job_no=?, customer_name=?, mobile=?, order_no=?, item_name=?, quantity=?, current_stage=?,
                        priority=?, delivery_date=?, notes=?, status=?, updated_at=NOW()
                    WHERE id=?
                ");
                $stmt->bind_param('sssssisssssi', $jobNo, $customerName, $mobile, $orderNo, $itemName, $quantity,
                    $currentStage, $priority, $deliveryDate, $notes, $status, $id);
                $stmt->execute();
                $stmt->close();

                pt_redirect('msg=updated' . ($currentStage !== '' ? '&stage=' . urlencode($currentStage) : ''));
            }

            $createdBy = (int)($_SESSION['user_id'] ?? 0);
            $stmt = $conn->prepare("
                INSERT INTO production_jobs
                    (job_no, customer_name, mobile, order_no, item_name, quantity, current_stage, priority,
                     delivery_date, notes, status, created_by, created_at, updated_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->bind_param('sssssisssssi', $jobNo, $customerName, $mobile, $orderNo, $itemName, $quantity,
                $currentStage, $priority, $deliveryDate, $notes, $status, $createdBy);
            $stmt->execute();
            $stmt->close();

            pt_redirect('msg=created' . ($currentStage !== '' ? '&stage=' . urlencode($currentStage) : ''));
        }

        if ($action === 'delete_job') {
            $id = pt_int($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid job.');

            $stmt = $conn->prepare("UPDATE production_jobs SET status='Inactive', updated_at=NOW() WHERE id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            pt_redirect('msg=deleted' . ($stage !== '' ? '&stage=' . urlencode($stage) : ''));
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

$msg = (string)($_GET['msg'] ?? '');
if ($msg === 'created') $message = 'Production job created successfully.';
elseif ($msg === 'updated') $message = 'Production job updated successfully.';
elseif ($msg === 'deleted') $message = 'Production job disabled successfully.';

$rows = [];
if (pt_table_exists($conn, 'production_jobs')) {
    try {
        if ($stage !== '') {
            $stmt = $conn->prepare("SELECT * FROM production_jobs WHERE current_stage=? ORDER BY id DESC LIMIT 300");
            $stmt->bind_param('s', $stage);
            $stmt->execute();
            $res = $stmt->get_result();
        } else {
            $res = $conn->query("SELECT * FROM production_jobs ORDER BY id DESC LIMIT 300");
        }

        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    } catch (Throwable $e) {
        $rows = [];
    }
}

$totalRows = count($rows);
$activeRows = 0;
$completedRows = 0;
foreach ($rows as $row) {
    if (strtolower((string)($row['status'] ?? 'active')) === 'active') $activeRows++;
    if (strtolower((string)($row['status'] ?? '')) === 'completed') $completedRows++;
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Production Tracking - Subhiksha Cards</title>
    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>

    <style>
        .module-page .page-head{padding:24px 28px;margin-bottom:18px}
        .module-page .page-head h1{font-size:30px;font-weight:900;color:var(--text-main)}
        .module-card{padding:24px}
        .module-title{font-size:18px;font-weight:900;color:var(--text-main);margin:0}
        .stat-card{padding:18px;min-height:112px;display:flex;align-items:center;gap:14px}
        .stat-icon{width:52px;height:52px;border-radius:16px;display:grid;place-items:center;color:#fff;flex:0 0 auto}
        .stat-card span{display:block;font-size:12px;color:var(--text-muted);font-weight:900;text-transform:uppercase}
        .stat-card strong{font-size:24px;font-weight:900;color:var(--text-main)}
        .status-pill{font-size:11px;font-weight:900;border-radius:999px;padding:5px 9px}
        .status-pill.pending{color:var(--warning-color);background:color-mix(in srgb,var(--warning-color) 14%,transparent)}
        .status-pill.active{color:var(--info-color);background:color-mix(in srgb,var(--info-color) 14%,transparent)}
        .status-pill.completed{color:var(--success-color);background:color-mix(in srgb,var(--success-color) 14%,transparent)}
        .status-pill.inactive{color:var(--danger-color);background:color-mix(in srgb,var(--danger-color) 14%,transparent)}
        .stage-tabs{display:flex;gap:9px;flex-wrap:wrap}
        .stage-tabs a{border:1px solid var(--border-soft);background:var(--card-bg);color:var(--text-main);border-radius:999px;padding:9px 14px;text-decoration:none;font-size:12px;font-weight:900}
        .stage-tabs a.active{background:linear-gradient(135deg,var(--brand-1),var(--brand-2));color:var(--brand-text);border-color:transparent}
        .form-control,.form-select{border-radius:14px;min-height:46px}
        .modal-content{border:0;border-radius:22px;background:var(--card-bg);color:var(--text-main)}
        .modal-header,.modal-footer{border-color:var(--border-soft)}
        .small-muted{display:block;margin-top:3px;color:var(--text-muted);font-size:11px;font-weight:700}
        .mobile-cards{display:none}
        .mobile-card{border:1px solid var(--border-soft);background:color-mix(in srgb,var(--card-bg) 96%,var(--body-bg));border-radius:18px;padding:16px;margin-bottom:12px}
        .mobile-card-title{font-size:16px;font-weight:900;color:var(--text-main)}
        .mobile-card-subtitle{display:block;color:var(--text-muted);font-size:12px;font-weight:700;margin-top:4px;word-break:break-word}
        .mobile-card-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
        @media(max-width:767.98px){
            .module-page .page-head{padding:18px;border-radius:18px}
            .module-page .page-head h1{font-size:24px}
            .module-page .page-head .btn{width:100%}
            .module-card{padding:16px;border-radius:18px}
            .desktop-table{display:none!important}
            .mobile-cards{display:block}
            .mobile-card-actions .btn,.mobile-card-actions form{flex:1 1 auto}
            .mobile-card-actions .btn{width:100%}
            .stage-tabs a{flex:1 1 auto;text-align:center}
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
                            <h1 class="mb-1">Production Tracking</h1>
                            <p class="text-muted-custom mb-0">Track design, approval, printing, cutting, quality and dispatch stages.</p>
                        </div>

                        <button type="button" class="btn btn-primary rounded-pill px-4 fw-bold" id="newJobBtn"
                            data-bs-toggle="modal" data-bs-target="#jobModal">
                            Create Job
                        </button>
                    </div>
                </div>

                <div class="card-ui module-card mb-3">
                    <div class="stage-tabs">
                        <?php foreach ($stages as $key => $label): ?>
                            <a class="<?= $stage === $key ? 'active' : '' ?>"
                               href="production-tracking.php<?= $key !== '' ? '?stage=' . e($key) : '' ?>">
                                <?= e($label) ?>
                            </a>
                        <?php endforeach; ?>
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
                                <i data-lucide="factory"></i>
                            </div>
                            <div>
                                <span>Total Jobs</span>
                                <strong><?= number_format($totalRows) ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#f97316)">
                                <i data-lucide="activity"></i>
                            </div>
                            <div>
                                <span>Active</span>
                                <strong><?= number_format($activeRows) ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#16a34a,#22c55e)">
                                <i data-lucide="check-circle-2"></i>
                            </div>
                            <div>
                                <span>Completed</span>
                                <strong><?= number_format($completedRows) ?></strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-ui module-card">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="module-title"><?= e($stage !== '' ? $stages[$stage] : 'All Production Jobs') ?></h2>
                            <p class="text-muted-custom mb-0">Desktop table and mobile card view.</p>
                        </div>

                        <div style="max-width:340px;width:100%">
                            <input type="search" id="tableSearch" class="form-control" placeholder="Search job...">
                        </div>
                    </div>

                    <div class="table-responsive desktop-table">
                        <table class="table-ui" id="dataTable">
                            <thead>
                                <tr>
                                    <th>Job</th>
                                    <th>Customer</th>
                                    <th>Item</th>
                                    <th>Stage</th>
                                    <th>Delivery</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$rows): ?>
                                    <tr><td colspan="7" class="text-center text-muted-custom py-4">No jobs found.</td></tr>
                                <?php endif; ?>

                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td><strong><?= e($row['job_no']) ?></strong><span class="small-muted"><?= e($row['order_no'] ?? '') ?></span></td>
                                        <td><?= e($row['customer_name']) ?><span class="small-muted"><?= e($row['mobile'] ?? '') ?></span></td>
                                        <td><?= e($row['item_name']) ?><span class="small-muted">Qty: <?= e($row['quantity'] ?? 0) ?></span></td>
                                        <td><?= e($stages[$row['current_stage']] ?? $row['current_stage']) ?></td>
                                        <td><?= e($row['delivery_date'] ?? '-') ?></td>
                                        <td><span class="status-pill <?= strtolower((string)$row['status']) === 'completed' ? 'completed' : (strtolower((string)$row['status']) === 'inactive' ? 'inactive' : 'active') ?>"><?= e($row['status'] ?? 'Active') ?></span></td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill fw-bold js-edit-job"
                                                data-bs-toggle="modal" data-bs-target="#jobModal"
                                                data-id="<?= e($row['id']) ?>"
                                                data-job-no="<?= e($row['job_no']) ?>"
                                                data-customer-name="<?= e($row['customer_name']) ?>"
                                                data-mobile="<?= e($row['mobile']) ?>"
                                                data-order-no="<?= e($row['order_no']) ?>"
                                                data-item-name="<?= e($row['item_name']) ?>"
                                                data-quantity="<?= e($row['quantity']) ?>"
                                                data-current-stage="<?= e($row['current_stage']) ?>"
                                                data-priority="<?= e($row['priority']) ?>"
                                                data-delivery-date="<?= e($row['delivery_date']) ?>"
                                                data-notes="<?= e($row['notes']) ?>"
                                                data-status="<?= e($row['status']) ?>">
                                                Edit
                                            </button>

                                            <?php if (strtolower((string)($row['status'] ?? 'active')) !== 'inactive'): ?>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Disable this job?')">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="delete_job">
                                                    <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill fw-bold">Disable</button>
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
                            <div class="mobile-card text-center text-muted-custom">No jobs found.</div>
                        <?php endif; ?>

                        <?php foreach ($rows as $row): ?>
                            <div class="mobile-card">
                                <div class="d-flex justify-content-between gap-2">
                                    <div>
                                        <div class="mobile-card-title"><?= e($row['job_no']) ?></div>
                                        <span class="mobile-card-subtitle"><?= e($row['customer_name']) ?> · <?= e($row['mobile']) ?></span>
                                        <span class="mobile-card-subtitle"><?= e($row['item_name']) ?> · Qty <?= e($row['quantity']) ?></span>
                                        <span class="mobile-card-subtitle">Stage: <?= e($stages[$row['current_stage']] ?? $row['current_stage']) ?></span>
                                        <span class="mobile-card-subtitle">Delivery: <?= e($row['delivery_date'] ?? '-') ?></span>
                                    </div>
                                    <span class="status-pill <?= strtolower((string)$row['status']) === 'completed' ? 'completed' : 'active' ?>"><?= e($row['status'] ?? 'Active') ?></span>
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

    <div class="modal fade" id="jobModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <form method="post" class="modal-content">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="save_job">
                <input type="hidden" name="id" id="id">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="jobModalTitle">Create Production Job</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label fw-bold">Job No</label><input name="job_no" id="job_no" class="form-control" placeholder="Auto if empty"></div>
                        <div class="col-md-6"><label class="form-label fw-bold">Order No</label><input name="order_no" id="order_no" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label fw-bold">Customer Name *</label><input name="customer_name" id="customer_name" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label fw-bold">Mobile</label><input name="mobile" id="mobile" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label fw-bold">Item Name *</label><input name="item_name" id="item_name" class="form-control" required></div>
                        <div class="col-md-3"><label class="form-label fw-bold">Quantity</label><input type="number" name="quantity" id="quantity" class="form-control" value="0"></div>
                        <div class="col-md-3"><label class="form-label fw-bold">Delivery Date</label><input type="date" name="delivery_date" id="delivery_date" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label fw-bold">Stage</label><select name="current_stage" id="current_stage" class="form-select"><?php foreach ($stages as $key => $label): if ($key === '') continue; ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-4"><label class="form-label fw-bold">Priority</label><select name="priority" id="priority" class="form-select"><option>Low</option><option selected>Normal</option><option>High</option><option>Urgent</option></select></div>
                        <div class="col-md-4"><label class="form-label fw-bold">Status</label><select name="status" id="status" class="form-select"><option>Active</option><option>Pending</option><option>Completed</option><option>Inactive</option></select></div>
                        <div class="col-12"><label class="form-label fw-bold">Notes</label><textarea name="notes" id="notes" rows="3" class="form-control"></textarea></div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold" id="jobSubmitBtn">Save Job</button>
                </div>
            </form>
        </div>
    </div>

    <?php include __DIR__ . '/includes/script.php'; ?>
    <script>
        (function () {
            function set(id, value) {
                const el = document.getElementById(id);
                if (!el) return;
                el.value = value == null ? '' : value;
            }

            document.getElementById('newJobBtn')?.addEventListener('click', function () {
                document.getElementById('jobModalTitle').textContent = 'Create Production Job';
                document.getElementById('jobSubmitBtn').textContent = 'Save Job';
                ['id','job_no','customer_name','mobile','order_no','item_name','delivery_date','notes'].forEach(id => set(id, ''));
                set('quantity', 0);
                set('current_stage', '<?= e($stage !== '' ? $stage : 'design') ?>');
                set('priority', 'Normal');
                set('status', 'Active');
            });

            document.querySelectorAll('.js-edit-job').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    document.getElementById('jobModalTitle').textContent = 'Edit Production Job';
                    document.getElementById('jobSubmitBtn').textContent = 'Update Job';
                    set('id', btn.dataset.id);
                    set('job_no', btn.dataset.jobNo);
                    set('customer_name', btn.dataset.customerName);
                    set('mobile', btn.dataset.mobile);
                    set('order_no', btn.dataset.orderNo);
                    set('item_name', btn.dataset.itemName);
                    set('quantity', btn.dataset.quantity);
                    set('current_stage', btn.dataset.currentStage);
                    set('priority', btn.dataset.priority);
                    set('delivery_date', btn.dataset.deliveryDate);
                    set('notes', btn.dataset.notes);
                    set('status', btn.dataset.status);
                });
            });

            document.getElementById('tableSearch')?.addEventListener('input', function () {
                const value = this.value.toLowerCase().trim();
                document.querySelectorAll('#dataTable tbody tr').forEach(row => {
                    row.style.display = row.textContent.toLowerCase().includes(value) ? '' : 'none';
                });
                document.querySelectorAll('#mobileCards .mobile-card').forEach(card => {
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
