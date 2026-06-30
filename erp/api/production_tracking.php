<?php
/**
 * production_tracking.php
 * Subiksha Cards ERP - Production Tracking Page
 * Uses existing API: api/production_tracking.php?action=update_tracking_status
 */

require_once __DIR__ . '/includes/auth.php';

require_permission($conn, 'can_view', 'production_tracking.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = 'Production Tracking';
$currentPage = 'production_tracking.php';

$canUpdate = can_update($conn, $currentPage);
$isAdmin = function_exists('is_admin_user') ? is_admin_user() : false;

if (empty($_SESSION['production_tracking_csrf'])) {
    $_SESSION['production_tracking_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['production_tracking_csrf'];

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function pt_page_table_exists(mysqli $conn, string $table): bool
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

function pt_page_col(mysqli $conn, string $table, string $col): bool
{
    static $cache = [];
    $key = $table . '.' . $col;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $tableEsc = $conn->real_escape_string($table);
        $colEsc = $conn->real_escape_string($col);
        $res = $conn->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$colEsc}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) {
            $res->free();
        }
        return $cache[$key] = $ok;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function pt_page_alias(mysqli $conn, string $tableAlias, string $tableName, array $cols, string $alias, string $fallback = ''): string
{
    foreach ($cols as $col) {
        if (pt_page_col($conn, $tableName, $col)) {
            return "{$tableAlias}.`{$col}` AS `{$alias}`";
        }
    }
    return "'" . $conn->real_escape_string($fallback) . "' AS `{$alias}`";
}

function pt_page_date_alias(mysqli $conn, string $tableAlias, string $tableName, array $cols, string $alias): string
{
    foreach ($cols as $col) {
        if (pt_page_col($conn, $tableName, $col)) {
            return "{$tableAlias}.`{$col}` AS `{$alias}`";
        }
    }
    return "NULL AS `{$alias}`";
}

function pt_page_current_role_ids(): array
{
    $ids = [];

    foreach (['role_id', 'current_role_id'] as $key) {
        if (!empty($_SESSION[$key])) {
            $ids[] = (int)$_SESSION[$key];
        }
    }

    foreach (['role_ids', 'user_role_ids'] as $key) {
        if (!empty($_SESSION[$key]) && is_array($_SESSION[$key])) {
            foreach ($_SESSION[$key] as $id) {
                $ids[] = (int)$id;
            }
        }
    }

    if (!empty($_SESSION['roles']) && is_array($_SESSION['roles'])) {
        foreach ($_SESSION['roles'] as $role) {
            if (is_array($role) && !empty($role['id'])) {
                $ids[] = (int)$role['id'];
            } elseif (is_numeric($role)) {
                $ids[] = (int)$role;
            }
        }
    }

    return array_values(array_unique(array_filter($ids)));
}

$requiredTables = ['job_cards', 'job_tracking', 'workflow_steps'];
$missingTables = [];
foreach ($requiredTables as $tbl) {
    if (!pt_page_table_exists($conn, $tbl)) {
        $missingTables[] = $tbl;
    }
}

$hasRequiredTables = empty($missingTables);
$delayReasons = [];
$workflowSteps = [];
$rows = [];
$stats = [
    'total' => 0,
    'pending' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'delayed' => 0,
];

$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$stepFilter = (int)($_GET['step_id'] ?? 0);
$delayFilter = trim((string)($_GET['delay'] ?? 'all'));

$validStatuses = ['pending', 'in_progress', 'completed'];

if ($hasRequiredTables) {
    try {
        $stepNameCol = pt_page_col($conn, 'workflow_steps', 'step_name') ? 'step_name' : (pt_page_col($conn, 'workflow_steps', 'name') ? 'name' : 'id');
        $stepKeyCol = pt_page_col($conn, 'workflow_steps', 'step_key') ? 'step_key' : null;
        $sortCol = pt_page_col($conn, 'workflow_steps', 'sort_order') ? 'sort_order' : 'id';

        $stepSql = "SELECT id, `{$stepNameCol}` AS step_name" . ($stepKeyCol ? ", `{$stepKeyCol}` AS step_key" : ", '' AS step_key") . " FROM workflow_steps ORDER BY `{$sortCol}` ASC, id ASC";
        $stepRes = $conn->query($stepSql);
        while ($step = $stepRes->fetch_assoc()) {
            $workflowSteps[] = $step;
        }

        if (pt_page_table_exists($conn, 'delay_reasons')) {
            $reasonNameCol = pt_page_col($conn, 'delay_reasons', 'reason_name') ? 'reason_name' : (pt_page_col($conn, 'delay_reasons', 'name') ? 'name' : 'id');
            $activeWhere = pt_page_col($conn, 'delay_reasons', 'is_active') ? 'WHERE is_active = 1' : (pt_page_col($conn, 'delay_reasons', 'status') ? "WHERE status IN (1, 'active')" : '');
            $reasonRes = $conn->query("SELECT id, `{$reasonNameCol}` AS reason_name FROM delay_reasons {$activeWhere} ORDER BY id ASC");
            while ($reason = $reasonRes->fetch_assoc()) {
                $delayReasons[] = $reason;
            }
        }

        $delayedExpr = pt_page_col($conn, 'job_tracking', 'is_delayed')
            ? "(jt.is_delayed = 1 OR (jt.status <> 'completed' AND jt.planned_completion_date IS NOT NULL AND DATE(jt.planned_completion_date) < CURDATE()))"
            : "(jt.status <> 'completed' AND jt.planned_completion_date IS NOT NULL AND DATE(jt.planned_completion_date) < CURDATE())";

        $statSql = "
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN jt.status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN jt.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
                SUM(CASE WHEN jt.status = 'completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN {$delayedExpr} THEN 1 ELSE 0 END) AS delayed
            FROM job_tracking jt
        ";
        $statRes = $conn->query($statSql);
        $statRow = $statRes->fetch_assoc() ?: [];
        foreach ($stats as $key => $value) {
            $stats[$key] = (int)($statRow[$key] ?? 0);
        }

        $where = [];
        $types = '';
        $params = [];

        if ($statusFilter !== 'all' && in_array($statusFilter, $validStatuses, true)) {
            $where[] = 'jt.status = ?';
            $types .= 's';
            $params[] = $statusFilter;
        }

        if ($stepFilter > 0) {
            $where[] = 'jt.workflow_step_id = ?';
            $types .= 'i';
            $params[] = $stepFilter;
        }

        if ($delayFilter === 'delayed') {
            $where[] = $delayedExpr;
        }

        if (!$isAdmin && pt_page_col($conn, 'job_tracking', 'responsible_role_id')) {
            $roleIds = pt_page_current_role_ids();
            if ($roleIds) {
                $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
                $where[] = "(jt.responsible_role_id IN ({$placeholders}) OR jt.responsible_role_id IS NULL OR jt.responsible_role_id = 0)";
                $types .= str_repeat('i', count($roleIds));
                foreach ($roleIds as $roleId) {
                    $params[] = $roleId;
                }
            }
        }

        $searchParts = [];
        foreach (['job_card_number', 'job_no', 'order_no', 'proforma_no'] as $col) {
            if (pt_page_col($conn, 'job_cards', $col)) {
                $searchParts[] = "jc.`{$col}` LIKE ?";
            }
        }
        foreach (['customer_name', 'mobile', 'product_name', 'item_name', 'order_type', 'printing_type'] as $col) {
            if (pt_page_col($conn, 'job_cards', $col)) {
                $searchParts[] = "jc.`{$col}` LIKE ?";
            }
        }
        if (pt_page_col($conn, 'workflow_steps', 'step_name')) {
            $searchParts[] = 'ws.step_name LIKE ?';
        }

        if ($search !== '' && $searchParts) {
            $where[] = '(' . implode(' OR ', $searchParts) . ')';
            $like = '%' . $search . '%';
            $types .= str_repeat('s', count($searchParts));
            foreach ($searchParts as $_) {
                $params[] = $like;
            }
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $selects = [
            'jt.id AS tracking_id',
            'jt.job_card_id',
            'jt.workflow_step_id',
            'jt.status AS tracking_status',
            pt_page_date_alias($conn, 'jt', 'job_tracking', ['planned_start_date', 'planned_start_at'], 'planned_start_date'),
            pt_page_date_alias($conn, 'jt', 'job_tracking', ['planned_completion_date', 'planned_end_date', 'planned_completed_at'], 'planned_completion_date'),
            pt_page_date_alias($conn, 'jt', 'job_tracking', ['actual_start_at', 'actual_start_date'], 'actual_start_at'),
            pt_page_date_alias($conn, 'jt', 'job_tracking', ['actual_completed_at', 'actual_completion_date'], 'actual_completed_at'),
            pt_page_alias($conn, 'jt', 'job_tracking', ['remarks', 'tracking_remarks'], 'tracking_remarks'),
            pt_page_alias($conn, 'jt', 'job_tracking', ['delay_remarks'], 'delay_remarks'),
            pt_page_alias($conn, 'jt', 'job_tracking', ['delay_days'], 'delay_days', '0'),
            pt_page_col($conn, 'job_tracking', 'is_delayed') ? 'jt.is_delayed AS is_delayed' : "0 AS is_delayed",
            pt_page_col($conn, 'job_tracking', 'responsible_role_id') ? 'jt.responsible_role_id AS responsible_role_id' : "0 AS responsible_role_id",
            pt_page_alias($conn, 'ws', 'workflow_steps', ['step_name', 'name'], 'step_name'),
            pt_page_alias($conn, 'ws', 'workflow_steps', ['step_key'], 'step_key'),
            pt_page_alias($conn, 'jc', 'job_cards', ['job_card_number', 'job_no', 'order_no', 'proforma_no'], 'job_no'),
            pt_page_alias($conn, 'jc', 'job_cards', ['customer_name', 'name'], 'customer_name'),
            pt_page_alias($conn, 'jc', 'job_cards', ['mobile', 'phone', 'contact_number'], 'mobile'),
            pt_page_alias($conn, 'jc', 'job_cards', ['product_name', 'item_name', 'product_details'], 'product_name'),
            pt_page_alias($conn, 'jc', 'job_cards', ['order_type'], 'order_type'),
            pt_page_alias($conn, 'jc', 'job_cards', ['printing_type', 'printing_type_name'], 'printing_type'),
            pt_page_date_alias($conn, 'jc', 'job_cards', ['delivery_date', 'expected_delivery_date'], 'delivery_date')
        ];

        $sql = "
            SELECT " . implode(",\n                   ", $selects) . "
            FROM job_tracking jt
            INNER JOIN job_cards jc ON jc.id = jt.job_card_id
            LEFT JOIN workflow_steps ws ON ws.id = jt.workflow_step_id
            {$whereSql}
            ORDER BY jc.id DESC, ws.sort_order ASC, jt.id ASC
            LIMIT 500
        ";

        $stmt = $conn->prepare($sql);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $planned = $row['planned_completion_date'] ?? null;
            $isDelayed = (int)($row['is_delayed'] ?? 0) === 1;
            if (!$isDelayed && !empty($planned) && ($row['tracking_status'] ?? '') !== 'completed') {
                $isDelayed = strtotime((string)$planned) < strtotime(date('Y-m-d'));
            }
            $row['computed_delayed'] = $isDelayed ? 1 : 0;
            $rows[] = $row;
        }
        $stmt->close();
    } catch (Throwable $e) {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => 'Unable to load production tracking: ' . $e->getMessage()
        ];
    }
}

require_once __DIR__ . '/includes/head.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.production-page {
    padding: 24px;
    background: #f5f8fc;
    min-height: 100vh
}

.production-card {
    background: #fff;
    border: 1px solid #dce4f0;
    border-radius: 18px;
    box-shadow: 0 12px 28px rgba(15, 23, 42, .04)
}

.production-header {
    padding: 30px;
    display: flex;
    justify-content: space-between;
    gap: 16px;
    align-items: center;
    margin-bottom: 18px
}

.production-title {
    font-size: 30px;
    font-weight: 900;
    color: #020617;
    margin: 0
}

.production-subtitle {
    color: #52627a;
    margin-top: 8px;
    font-size: 15px
}

.filter-card {
    padding: 22px;
    margin-bottom: 18px
}

.filter-grid {
    display: grid;
    grid-template-columns: 1.5fr 1fr 1fr 1fr auto;
    gap: 12px;
    align-items: end
}

.form-label-mini {
    font-size: 12px;
    font-weight: 900;
    color: #53657d;
    text-transform: uppercase;
    margin-bottom: 6px
}

.filter-input,
.filter-select {
    width: 100%;
    border: 1px solid #dce4f0;
    border-radius: 13px;
    padding: 11px 13px;
    outline: none;
    background: #fff
}

.btn-main {
    background: #1677ff;
    color: #fff;
    border: 0;
    border-radius: 13px;
    padding: 11px 18px;
    font-weight: 800
}

.btn-main:hover {
    background: #075ee8;
    color: #fff
}

.btn-light2 {
    background: #eef4fb;
    color: #24364d;
    border: 0;
    border-radius: 13px;
    padding: 11px 18px;
    font-weight: 800;
    text-decoration: none
}

.error-box {
    background: #fee2e2;
    border: 1px solid #fca5a5;
    color: #7f1d1d;
    padding: 18px 20px;
    border-radius: 16px;
    margin-bottom: 18px;
    font-weight: 800
}

.stat-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 14px;
    margin-bottom: 18px
}

.stat-card {
    padding: 22px;
    display: flex;
    gap: 13px;
    align-items: center
}

.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    display: grid;
    place-items: center;
    color: #fff;
    font-size: 19px
}

.blue {
    background: #2084ed
}

.orange {
    background: #fb8c00
}

.green {
    background: #20b85a
}

.red {
    background: #ef4444
}

.gray {
    background: #64748b
}

.stat-label {
    font-size: 11px;
    font-weight: 900;
    color: #58708f;
    text-transform: uppercase
}

.stat-value {
    font-size: 25px;
    font-weight: 900;
    color: #020617
}

.list-card {
    padding: 24px
}

.list-head {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    align-items: center;
    margin-bottom: 18px
}

.section-title {
    font-size: 20px;
    font-weight: 900;
    margin: 0;
    color: #071326
}

.section-subtitle {
    color: #607089;
    margin-top: 4px
}

.production-table {
    width: 100%;
    border-collapse: collapse
}

.production-table thead th {
    background: #edf2f8;
    color: #30445f;
    font-size: 12px;
    text-transform: uppercase;
    padding: 14px;
    border-bottom: 1px solid #dce4f0
}

.production-table tbody td {
    padding: 14px;
    border-bottom: 1px solid #edf2f7;
    vertical-align: middle
}

.badgex {
    display: inline-flex;
    padding: 7px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 800
}

.badge-pending {
    background: #f1f5f9;
    color: #334155
}

.badge-in_progress {
    background: #e0f2fe;
    color: #0369a1
}

.badge-completed {
    background: #dcfce7;
    color: #166534
}

.badge-delayed {
    background: #fee2e2;
    color: #991b1b
}

.badge-stage {
    background: #eaf2ff;
    color: #075ec9
}

.date-small {
    font-size: 12px;
    color: #64748b
}

.strike {
    text-decoration: line-through;
    color: #dc2626;
    font-weight: 800
}

.action-row {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    flex-wrap: wrap
}

.mini-btn {
    border: 1px solid #dce4f0;
    background: #fff;
    border-radius: 10px;
    padding: 8px 10px;
    font-size: 12px;
    font-weight: 800;
    cursor: pointer
}

.mini-btn.progress-btn {
    background: #0ea5e9;
    color: #fff;
    border-color: #0ea5e9
}

.mini-btn.done-btn {
    background: #16a34a;
    color: #fff;
    border-color: #16a34a
}

.empty-row {
    padding: 35px;
    text-align: center;
    color: #607089;
    font-size: 16px
}

.toast-wrap {
    position: fixed;
    top: 22px;
    right: 22px;
    z-index: 99999
}

.custom-toast {
    min-width: 310px;
    max-width: 430px;
    border-radius: 16px;
    padding: 15px 16px;
    display: flex;
    gap: 12px;
    align-items: flex-start;
    color: #fff;
    box-shadow: 0 18px 40px rgba(15, 23, 42, .22);
    margin-bottom: 10px;
    animation: toastIn .22s ease
}

.custom-toast.success {
    background: linear-gradient(135deg, #16a34a, #22c55e)
}

.custom-toast.error {
    background: linear-gradient(135deg, #dc2626, #ef4444)
}

.custom-toast.warning {
    background: linear-gradient(135deg, #f59e0b, #f97316)
}

.toast-icon {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: rgba(255, 255, 255, .2);
    display: grid;
    place-items: center;
    flex: 0 0 auto
}

.toast-title {
    font-weight: 900
}

.toast-message {
    font-size: 13px
}

.toast-close {
    margin-left: auto;
    background: transparent;
    border: 0;
    color: #fff;
    font-size: 18px;
    cursor: pointer
}

@keyframes toastIn {
    from {
        transform: translateX(25px);
        opacity: 0
    }

    to {
        transform: translateX(0);
        opacity: 1
    }
}

.mobile-cards {
    display: none
}

.mobile-card {
    border: 1px solid #dce4f0;
    border-radius: 16px;
    padding: 16px;
    margin-bottom: 12px;
    background: #fff
}

.mobile-title {
    font-weight: 900;
    font-size: 15px;
    color: #071326
}

.mobile-meta {
    font-size: 13px;
    color: #64748b;
    margin-top: 4px
}

@media(max-width:1100px) {
    .stat-grid {
        grid-template-columns: repeat(2, 1fr)
    }

    .filter-grid {
        grid-template-columns: 1fr 1fr
    }

    .production-header,
    .list-head {
        flex-direction: column;
        align-items: stretch
    }

    .desktop-table {
        overflow-x: auto
    }

    .production-table {
        min-width: 1050px
    }
}

@media(max-width:700px) {
    .production-page {
        padding: 14px
    }

    .stat-grid {
        grid-template-columns: 1fr
    }

    .filter-grid {
        grid-template-columns: 1fr
    }

    .desktop-table {
        display: none
    }

    .mobile-cards {
        display: block
    }

    .production-title {
        font-size: 24px
    }

    .production-header {
        padding: 20px
    }

    .toast-wrap {
        left: 14px;
        right: 14px
    }

    .custom-toast {
        min-width: 0;
        max-width: none
    }
}
</style>

<div class="toast-wrap" id="toastWrap"></div>

<div class="production-page">
    <div class="production-card production-header">
        <div>
            <h1 class="production-title">Production Tracking</h1>
            <div class="production-subtitle">Track job card stages, delays, responsible departments and completion
                status.</div>
        </div>
        <a href="job_cards.php" class="btn-light2"><i class="fa fa-arrow-left me-1"></i> Job Cards</a>
    </div>

    <?php if (!$hasRequiredTables): ?>
    <div class="error-box">
        Missing required table(s): <?= e(implode(', ', $missingTables)) ?>. Create job card and tracking tables first.
    </div>
    <?php endif; ?>

    <div class="stat-grid">
        <div class="production-card stat-card">
            <div class="stat-icon blue"><i class="fa fa-layer-group"></i></div>
            <div>
                <div class="stat-label">Total Stages</div>
                <div class="stat-value"><?= e($stats['total']) ?></div>
            </div>
        </div>
        <div class="production-card stat-card">
            <div class="stat-icon gray"><i class="fa fa-clock"></i></div>
            <div>
                <div class="stat-label">Pending</div>
                <div class="stat-value"><?= e($stats['pending']) ?></div>
            </div>
        </div>
        <div class="production-card stat-card">
            <div class="stat-icon orange"><i class="fa fa-spinner"></i></div>
            <div>
                <div class="stat-label">In Progress</div>
                <div class="stat-value"><?= e($stats['in_progress']) ?></div>
            </div>
        </div>
        <div class="production-card stat-card">
            <div class="stat-icon green"><i class="fa fa-check-circle"></i></div>
            <div>
                <div class="stat-label">Completed</div>
                <div class="stat-value"><?= e($stats['completed']) ?></div>
            </div>
        </div>
        <div class="production-card stat-card">
            <div class="stat-icon red"><i class="fa fa-triangle-exclamation"></i></div>
            <div>
                <div class="stat-label">Delayed</div>
                <div class="stat-value"><?= e($stats['delayed']) ?></div>
            </div>
        </div>
    </div>

    <div class="production-card filter-card">
        <form method="get" class="filter-grid">
            <div>
                <div class="form-label-mini">Search</div>
                <input class="filter-input" type="text" name="search" value="<?= e($search) ?>"
                    placeholder="Job no, customer, mobile, product...">
            </div>
            <div>
                <div class="form-label-mini">Stage</div>
                <select class="filter-select" name="step_id">
                    <option value="0">All Stages</option>
                    <?php foreach ($workflowSteps as $step): ?>
                    <option value="<?= (int)$step['id'] ?>" <?= $stepFilter === (int)$step['id'] ? 'selected' : '' ?>>
                        <?= e($step['step_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <div class="form-label-mini">Status</div>
                <select class="filter-select" name="status">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>In Progress
                    </option>
                    <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                </select>
            </div>
            <div>
                <div class="form-label-mini">Delay</div>
                <select class="filter-select" name="delay">
                    <option value="all" <?= $delayFilter === 'all' ? 'selected' : '' ?>>All</option>
                    <option value="delayed" <?= $delayFilter === 'delayed' ? 'selected' : '' ?>>Delayed Only</option>
                </select>
            </div>
            <div style="display:flex;gap:8px;">
                <button class="btn-main" type="submit"><i class="fa fa-search me-1"></i> Filter</button>
                <a class="btn-light2" href="production_tracking.php">Reset</a>
            </div>
        </form>
    </div>

    <div class="production-card list-card">
        <div class="list-head">
            <div>
                <h2 class="section-title">Production Stage List</h2>
                <div class="section-subtitle">Update only pending, in-progress and completed status through API.</div>
            </div>
        </div>

        <div class="desktop-table">
            <table class="production-table">
                <thead>
                    <tr>
                        <th>Job</th>
                        <th>Customer</th>
                        <th>Product</th>
                        <th>Stage</th>
                        <th>Planned</th>
                        <th>Actual</th>
                        <th>Status</th>
                        <th style="text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$hasRequiredTables || empty($rows)): ?>
                    <tr>
                        <td colspan="8" class="empty-row">No production tracking records found.</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                    <?php
                            $status = (string)($row['tracking_status'] ?? 'pending');
                            $isDelayed = (int)($row['computed_delayed'] ?? 0) === 1;
                            $plannedCompletion = $row['planned_completion_date'] ?? '';
                            ?>
                    <tr>
                        <td><strong><?= e($row['job_no']) ?></strong>
                            <div class="date-small">#<?= (int)$row['job_card_id'] ?></div>
                        </td>
                        <td><strong><?= e($row['customer_name']) ?></strong>
                            <div class="date-small"><?= e($row['mobile']) ?></div>
                        </td>
                        <td><?= e($row['product_name']) ?><div class="date-small"><?= e($row['order_type']) ?>
                                <?= e($row['printing_type']) ?></div>
                        </td>
                        <td><span class="badgex badge-stage"><?= e($row['step_name']) ?></span></td>
                        <td>
                            <div class="date-small">Start:
                                <?= !empty($row['planned_start_date']) ? e(date('d-m-Y', strtotime($row['planned_start_date']))) : '-' ?>
                            </div>
                            <div class="date-small">End: <?php if ($isDelayed && !empty($plannedCompletion)): ?><span
                                    class="strike"><?= e(date('d-m-Y', strtotime($plannedCompletion))) ?></span><?php else: ?><?= !empty($plannedCompletion) ? e(date('d-m-Y', strtotime($plannedCompletion))) : '-' ?><?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="date-small">Start:
                                <?= !empty($row['actual_start_at']) ? e(date('d-m-Y H:i', strtotime($row['actual_start_at']))) : '-' ?>
                            </div>
                            <div class="date-small">Done:
                                <?= !empty($row['actual_completed_at']) ? e(date('d-m-Y H:i', strtotime($row['actual_completed_at']))) : '-' ?>
                            </div>
                        </td>
                        <td>
                            <span
                                class="badgex badge-<?= e($status) ?>"><?= e(ucwords(str_replace('_', ' ', $status))) ?></span>
                            <?php if ($isDelayed): ?><span
                                class="badgex badge-delayed mt-1">Delayed</span><?php endif; ?>
                        </td>
                        <td>
                            <div class="action-row">
                                <?php if ($canUpdate && $status !== 'in_progress' && $status !== 'completed'): ?>
                                <button type="button" class="mini-btn progress-btn"
                                    onclick="prepareUpdate(<?= (int)$row['tracking_id'] ?>, 'in_progress', <?= (int)$isDelayed ?>)">Start</button>
                                <?php endif; ?>
                                <?php if ($canUpdate && $status !== 'completed'): ?>
                                <button type="button" class="mini-btn done-btn"
                                    onclick="prepareUpdate(<?= (int)$row['tracking_id'] ?>, 'completed', <?= (int)$isDelayed ?>)">Complete</button>
                                <?php endif; ?>
                                <?php if (!$canUpdate || $status === 'completed'): ?>
                                <span class="date-small">No action</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="mobile-cards">
            <?php if (!$hasRequiredTables || empty($rows)): ?>
            <div class="empty-row">No production tracking records found.</div>
            <?php else: ?>
            <?php foreach ($rows as $row): ?>
            <?php $status = (string)($row['tracking_status'] ?? 'pending'); $isDelayed = (int)($row['computed_delayed'] ?? 0) === 1; ?>
            <div class="mobile-card">
                <div class="mobile-title"><?= e($row['job_no']) ?> - <?= e($row['step_name']) ?></div>
                <div class="mobile-meta"><?= e($row['customer_name']) ?> | <?= e($row['mobile']) ?></div>
                <div class="mobile-meta"><?= e($row['product_name']) ?></div>
                <div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap;">
                    <span
                        class="badgex badge-<?= e($status) ?>"><?= e(ucwords(str_replace('_', ' ', $status))) ?></span>
                    <?php if ($isDelayed): ?><span class="badgex badge-delayed">Delayed</span><?php endif; ?>
                </div>
                <div class="action-row" style="justify-content:flex-start;margin-top:12px;">
                    <?php if ($canUpdate && $status !== 'in_progress' && $status !== 'completed'): ?>
                    <button type="button" class="mini-btn progress-btn"
                        onclick="prepareUpdate(<?= (int)$row['tracking_id'] ?>, 'in_progress', <?= (int)$isDelayed ?>)">Start</button>
                    <?php endif; ?>
                    <?php if ($canUpdate && $status !== 'completed'): ?>
                    <button type="button" class="mini-btn done-btn"
                        onclick="prepareUpdate(<?= (int)$row['tracking_id'] ?>, 'completed', <?= (int)$isDelayed ?>)">Complete</button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal fade" id="delayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" onsubmit="submitDelayUpdate(event)">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Delay Details Required</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="delay_tracking_id">
                <input type="hidden" id="delay_new_status">
                <div class="mb-3">
                    <label class="form-label fw-bold">Delay Reason</label>
                    <select id="delay_reason_id" class="form-select" required>
                        <option value="">Select reason</option>
                        <?php foreach ($delayReasons as $reason): ?>
                        <option value="<?= (int)$reason['id'] ?>"><?= e($reason['reason_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Delay Remarks</label>
                    <textarea id="delay_remarks" class="form-control" rows="3" required
                        placeholder="Enter reason for delay..."></textarea>
                </div>
                <div>
                    <label class="form-label fw-bold">Update Remarks</label>
                    <textarea id="delay_update_remarks" class="form-control" rows="2"
                        placeholder="Optional stage update remarks..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light fw-bold" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary fw-bold">Update Stage</button>
            </div>
        </form>
    </div>
</div>

<script>
const PRODUCTION_TRACKING_CSRF = "<?= e($csrfToken) ?>";
const API_URL = 'api/production_tracking.php';
let delayModalObj = null;

function showToast(type, message) {
    const wrap = document.getElementById('toastWrap');
    let title = 'Message';
    let icon = 'fa-info';
    if (type === 'success') {
        title = 'Success';
        icon = 'fa-check';
    }
    if (type === 'error') {
        title = 'Error';
        icon = 'fa-times';
    }
    if (type === 'warning') {
        title = 'Warning';
        icon = 'fa-exclamation';
    }

    const toast = document.createElement('div');
    toast.className = 'custom-toast ' + type;
    toast.innerHTML =
        `<div class="toast-icon"><i class="fa ${icon}"></i></div><div><div class="toast-title">${title}</div><div class="toast-message">${message}</div></div><button type="button" class="toast-close">&times;</button>`;
    wrap.appendChild(toast);
    toast.querySelector('.toast-close').addEventListener('click', () => toast.remove());
    setTimeout(() => {
        if (toast.parentNode) toast.remove();
    }, 4500);
}

function prepareUpdate(trackingId, status, isDelayed) {
    if (isDelayed === 1) {
        document.getElementById('delay_tracking_id').value = trackingId;
        document.getElementById('delay_new_status').value = status;
        document.getElementById('delay_reason_id').value = '';
        document.getElementById('delay_remarks').value = '';
        document.getElementById('delay_update_remarks').value = '';
        delayModalObj = new bootstrap.Modal(document.getElementById('delayModal'));
        delayModalObj.show();
        return;
    }

    updateTrackingStatus(trackingId, status, '', '', '');
}

function submitDelayUpdate(event) {
    event.preventDefault();
    const trackingId = document.getElementById('delay_tracking_id').value;
    const status = document.getElementById('delay_new_status').value;
    const delayReasonId = document.getElementById('delay_reason_id').value;
    const delayRemarks = document.getElementById('delay_remarks').value.trim();
    const remarks = document.getElementById('delay_update_remarks').value.trim();

    if (!delayReasonId || !delayRemarks) {
        showToast('error', 'Delay reason and remarks are required.');
        return;
    }

    updateTrackingStatus(trackingId, status, remarks, delayReasonId, delayRemarks);
}

function updateTrackingStatus(trackingId, status, remarks = '', delayReasonId = '', delayRemarks = '') {
    const formData = new FormData();
    formData.append('action', 'update_tracking_status');
    formData.append('tracking_id', trackingId);
    formData.append('status', status);
    formData.append('remarks', remarks);
    formData.append('delay_reason_id', delayReasonId);
    formData.append('delay_remarks', delayRemarks);
    formData.append('csrf_token', PRODUCTION_TRACKING_CSRF);

    fetch(API_URL, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (delayModalObj) delayModalObj.hide();
                showToast('success', data.message || 'Production tracking status updated successfully.');
                setTimeout(() => window.location.reload(), 750);
            } else {
                showToast('error', data.message || 'Unable to update production status.');
            }
        })
        .catch(() => showToast('error', 'Server error. Please try again.'));
}

<?php if (!empty($_SESSION['toast'])): ?>
showToast("<?= e($_SESSION['toast']['type']) ?>", "<?= e($_SESSION['toast']['message']) ?>");
<?php unset($_SESSION['toast']); endif; ?>
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>