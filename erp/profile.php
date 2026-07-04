<?php
/**
 * profile.php
 * Subhiksha Cards ERP - My Profile (Read Only)
 *
 * This page is available for every logged-in user.
 * No edit, delete, or password-update actions are allowed here.
 */

require_once __DIR__ . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('profile_table_exists')) {
    function profile_table_exists(mysqli $conn, string $table): bool
    {
        static $cache = [];
        $key = strtolower($table);
        if (array_key_exists($key, $cache)) return $cache[$key];

        try {
            $tableEsc = $conn->real_escape_string($table);
            $res = $conn->query("SHOW TABLES LIKE '{$tableEsc}'");
            $ok = $res && $res->num_rows > 0;
            if ($res) $res->free();
            return $cache[$key] = $ok;
        } catch (Throwable $e) {
            return $cache[$key] = false;
        }
    }
}

if (!function_exists('profile_col_exists')) {
    function profile_col_exists(mysqli $conn, string $table, string $column): bool
    {
        static $cache = [];
        $key = strtolower($table . '.' . $column);
        if (array_key_exists($key, $cache)) return $cache[$key];

        if (!profile_table_exists($conn, $table)) return $cache[$key] = false;

        try {
            $tableEsc = $conn->real_escape_string($table);
            $colEsc = $conn->real_escape_string($column);
            $res = $conn->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$colEsc}'");
            $ok = $res && $res->num_rows > 0;
            if ($res) $res->free();
            return $cache[$key] = $ok;
        } catch (Throwable $e) {
            return $cache[$key] = false;
        }
    }
}

if (!function_exists('profile_first_col')) {
    function profile_first_col(mysqli $conn, string $table, array $columns): ?string
    {
        foreach ($columns as $column) {
            if (profile_col_exists($conn, $table, $column)) return $column;
        }
        return null;
    }
}

if (!function_exists('profile_fetch_one')) {
    function profile_fetch_one(mysqli $conn, string $sql): ?array
    {
        try {
            $res = $conn->query($sql);
            if (!$res) return null;
            $row = $res->fetch_assoc();
            $res->free();
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('profile_date')) {
    function profile_date($value): string
    {
        return !empty($value) ? date('d-m-Y h:i A', strtotime((string)$value)) : '-';
    }
}

if (!function_exists('profile_read_value')) {
    function profile_read_value(array $row, ?string $column, string $fallback = ''): string
    {
        if ($column && array_key_exists($column, $row)) {
            return trim((string)$row[$column]);
        }
        return trim($fallback);
    }
}

function profile_current_user(mysqli $conn, int $userId): array
{
    if ($userId <= 0 || !profile_table_exists($conn, 'users')) {
        return [];
    }

    $joinRole = '';
    $roleSelect = "'' AS profile_role_name, '' AS profile_role_key";
    if (profile_table_exists($conn, 'roles') && profile_col_exists($conn, 'users', 'role_id')) {
        $roleNameExpr = profile_col_exists($conn, 'roles', 'role_name') ? 'r.role_name' : "''";
        $roleKeyExpr = profile_col_exists($conn, 'roles', 'role_key') ? 'r.role_key' : "''";
        $roleSelect = "COALESCE({$roleNameExpr}, '') AS profile_role_name, COALESCE({$roleKeyExpr}, '') AS profile_role_key";
        $joinRole = ' LEFT JOIN roles r ON r.id = u.role_id ';
    }

    $userId = (int)$userId;
    $row = profile_fetch_one($conn, "SELECT u.*, {$roleSelect} FROM users u {$joinRole} WHERE u.id = {$userId} LIMIT 1");
    return $row ?: [];
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$user = profile_current_user($conn, $userId);

$nameCol = profile_first_col($conn, 'users', ['full_name', 'name', 'display_name', 'employee_name']);
$emailCol = profile_first_col($conn, 'users', ['email', 'email_id']);
$mobileCol = profile_first_col($conn, 'users', ['mobile', 'phone', 'contact_no', 'phone_no']);
$usernameCol = profile_first_col($conn, 'users', ['username', 'user_name', 'login_username']);
$statusCol = profile_first_col($conn, 'users', ['is_active', 'status']);
$createdCol = profile_first_col($conn, 'users', ['created_at', 'created_on']);
$updatedCol = profile_first_col($conn, 'users', ['updated_at', 'updated_on']);
$lastLoginCol = profile_first_col($conn, 'users', ['last_login_at', 'last_login', 'last_logged_in_at']);

$profileName = profile_read_value($user, $nameCol, (string)($_SESSION['name'] ?? ''));
$profileEmail = profile_read_value($user, $emailCol, (string)($_SESSION['email'] ?? ''));
$profileMobile = profile_read_value($user, $mobileCol, (string)($_SESSION['mobile'] ?? ''));
$profileUsername = profile_read_value($user, $usernameCol, (string)($_SESSION['username'] ?? ''));
$profileRole = trim((string)($user['profile_role_name'] ?? $_SESSION['role_name'] ?? $_SESSION['role'] ?? 'User')) ?: 'User';
$profileRoleKey = trim((string)($user['profile_role_key'] ?? $_SESSION['role_key'] ?? ''));
$profileStatus = '-';
if ($statusCol && array_key_exists($statusCol, $user)) {
    $rawStatus = strtolower(trim((string)$user[$statusCol]));
    $profileStatus = in_array($rawStatus, ['1', 'active', 'enabled'], true) ? 'Active' : (in_array($rawStatus, ['0', 'inactive', 'disabled'], true) ? 'Inactive' : ucfirst($rawStatus));
}

$createdAt = $createdCol ? profile_date($user[$createdCol] ?? null) : '-';
$updatedAt = $updatedCol ? profile_date($user[$updatedCol] ?? null) : '-';
$lastLoginAt = $lastLoginCol ? profile_date($user[$lastLoginCol] ?? null) : '-';
$initial = strtoupper(substr(trim($profileName ?: $profileUsername ?: 'U'), 0, 1));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>My Profile - Subhiksha Cards</title>
    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>
    <style>
        .profile-page .page-head{padding:26px 28px;margin-bottom:18px;position:relative;overflow:hidden}.profile-page .page-head:after{content:"";position:absolute;width:260px;height:260px;right:-90px;top:-100px;border-radius:999px;background:color-mix(in srgb,var(--brand-1) 14%,transparent);z-index:0}.profile-page .page-head>*{position:relative;z-index:1}.profile-title{font-size:30px;font-weight:900;color:var(--text-main);margin:0}.profile-subtitle{color:var(--text-muted);font-weight:700;margin:4px 0 0}.profile-avatar{width:82px;height:82px;border-radius:26px;display:grid;place-items:center;background:linear-gradient(135deg,#2563eb,#0ea5e9);color:#fff;font-size:34px;font-weight:900;box-shadow:0 20px 45px rgba(37,99,235,.22)}.profile-card{padding:24px}.profile-card-title{font-size:18px;font-weight:900;color:var(--text-main);margin:0}.profile-info-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.profile-info-box{border:1px solid var(--border-soft);background:color-mix(in srgb,var(--card-bg) 96%,var(--body-bg));border-radius:18px;padding:16px 17px;min-height:90px}.profile-info-box small{display:block;color:var(--text-muted);font-size:11px;text-transform:uppercase;font-weight:900;letter-spacing:.3px;margin-bottom:6px}.profile-info-box strong{display:block;color:var(--text-main);font-size:15px;font-weight:900;word-break:break-word}.readonly-note{border:1px dashed color-mix(in srgb,var(--brand-1) 35%,var(--border-soft));background:color-mix(in srgb,var(--brand-1) 8%,var(--card-bg));border-radius:18px;padding:14px 16px;color:var(--text-main);font-weight:800}.status-chip{display:inline-flex;align-items:center;gap:7px;padding:8px 12px;border-radius:999px;background:#dcfce7;color:#166534;font-size:12px;font-weight:900}.status-chip.inactive{background:#fee2e2;color:#991b1b}.profile-action-link{display:inline-flex;align-items:center;gap:8px;text-decoration:none;border:1px solid var(--border-soft);border-radius:999px;padding:10px 16px;font-weight:900;color:var(--text-main);background:var(--card-bg)}.profile-action-link:hover{color:var(--brand-1);border-color:color-mix(in srgb,var(--brand-1) 45%,var(--border-soft))}@media(max-width:1199.98px){.profile-info-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:767.98px){.profile-page .page-head{padding:20px;border-radius:18px}.profile-title{font-size:24px}.profile-card{padding:18px;border-radius:18px}.profile-info-grid{grid-template-columns:1fr}.profile-avatar{width:70px;height:70px;border-radius:22px;font-size:28px}}
    </style>
</head>
<body class="<?= e(($theme['layout_density'] ?? '') === 'compact' ? 'layout-compact' : '') ?>">
<div id="mobileOverlay"></div>
<div class="app-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main id="main">
        <?php include __DIR__ . '/includes/nav.php'; ?>

        <section class="page-section profile-page">
            <div class="card-ui page-head">
                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="profile-avatar"><?= e($initial) ?></div>
                        <div>
                            <h1 class="profile-title">My Profile</h1>
                            <p class="profile-subtitle">Read-only account details for the logged-in user.</p>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <span class="status-chip <?= e(strtolower($profileStatus) === 'inactive' ? 'inactive' : '') ?>"><i data-lucide="shield-check"></i><?= e($profileStatus) ?></span>
                        <a href="dashboard.php" class="profile-action-link"><i data-lucide="layout-dashboard"></i> Dashboard</a>
                    </div>
                </div>
            </div>

            <div class="card-ui profile-card mb-3">
                <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                    <div>
                        <h2 class="profile-card-title">Account Summary</h2>
                        <p class="text-muted-custom mb-0">This page is view-only. Users cannot edit, delete, or change password here.</p>
                    </div>
                    <i data-lucide="user-circle"></i>
                </div>

                <div class="readonly-note mb-3">
                    <i data-lucide="lock"></i>
                    Profile details are controlled by Admin/User Management. Contact Admin for any correction.
                </div>

                <div class="profile-info-grid">
                    <div class="profile-info-box"><small>Name</small><strong><?= e($profileName ?: '-') ?></strong></div>
                    <div class="profile-info-box"><small>Username</small><strong><?= e($profileUsername ?: '-') ?></strong></div>
                    <div class="profile-info-box"><small>Role</small><strong><?= e($profileRole ?: '-') ?></strong></div>
                    <div class="profile-info-box"><small>Role Key</small><strong><?= e($profileRoleKey ?: '-') ?></strong></div>
                    <div class="profile-info-box"><small>Email</small><strong><?= e($profileEmail ?: '-') ?></strong></div>
                    <div class="profile-info-box"><small>Mobile</small><strong><?= e($profileMobile ?: '-') ?></strong></div>
                    <div class="profile-info-box"><small>Status</small><strong><?= e($profileStatus) ?></strong></div>
                    <div class="profile-info-box"><small>Created</small><strong><?= e($createdAt) ?></strong></div>
                    <div class="profile-info-box"><small>Last Updated</small><strong><?= e($updatedAt) ?></strong></div>
                    <div class="profile-info-box"><small>Last Login</small><strong><?= e($lastLoginAt) ?></strong></div>
                    <div class="profile-info-box"><small>User ID</small><strong><?= e($userId ?: '-') ?></strong></div>
                    <div class="profile-info-box"><small>Access</small><strong>Read Only</strong></div>
                </div>
            </div>
        </section>
    </main>
    <div id="settingsOverlay"></div>
    <?php include __DIR__ . '/includes/rightsidebar.php'; ?>
</div>

<?php include __DIR__ . '/includes/script.php'; ?>
<script>
(function(){
    if(window.lucide && typeof window.lucide.createIcons === 'function'){ window.lucide.createIcons(); }
})();
</script>
</body>
</html>
