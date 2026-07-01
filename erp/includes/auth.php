<?php
/**
 * includes/auth.php
 * Subhiksha Cards ERP - Login + Role Permission Guard
 */

require_once __DIR__ . '/db.php';

/* WhatsApp helper is optional. Auth should not fail if the file is missing. */
$waFile = __DIR__ . '/whatsapp-api.php';
if (file_exists($waFile)) {
    require_once $waFile;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die('MySQLi connection $conn is missing. Check includes/db.php');
}

if (!function_exists('auth_current_page')) {
    function auth_current_page(string $pageUrl): string
    {
        $path = parse_url($pageUrl, PHP_URL_PATH);
        return basename($path ?: $pageUrl);
    }
}

if (!function_exists('auth_login_url')) {
    function auth_login_url(): string
    {
        return 'login.php';
    }
}

if (!function_exists('auth_dashboard_url')) {
    function auth_dashboard_url(): string
    {
        return 'dashboard.php';
    }
}

if (!function_exists('auth_role_keys')) {
    function auth_role_keys(): array
    {
        $keys = [];

        foreach (['role_key', 'current_role_key'] as $sessionKey) {
            if (!empty($_SESSION[$sessionKey])) {
                $keys[] = strtolower((string)$_SESSION[$sessionKey]);
            }
        }

        if (!empty($_SESSION['role_keys']) && is_array($_SESSION['role_keys'])) {
            foreach ($_SESSION['role_keys'] as $key) {
                $keys[] = strtolower((string)$key);
            }
        }

        if (!empty($_SESSION['role_name'])) {
            $keys[] = strtolower(str_replace(' ', '_', (string)$_SESSION['role_name']));
        }

        return array_values(array_unique(array_filter($keys)));
    }
}

if (!function_exists('auth_role_ids')) {
    function auth_role_ids(): array
    {
        $ids = [];

        foreach (['role_id', 'current_role_id'] as $sessionKey) {
            if (!empty($_SESSION[$sessionKey])) {
                $ids[] = (int)$_SESSION[$sessionKey];
            }
        }

        if (!empty($_SESSION['role_ids']) && is_array($_SESSION['role_ids'])) {
            foreach ($_SESSION['role_ids'] as $id) {
                $ids[] = (int)$id;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }
}

if (!function_exists('is_admin_user')) {
    function is_admin_user(): bool
    {
        $keys = auth_role_keys();
        return in_array('admin', $keys, true)
            || in_array('super_admin', $keys, true)
            || !empty($_SESSION['is_super_admin']);
    }
}

if (!function_exists('require_login')) {
    function require_login(): void
    {
        if (empty($_SESSION['user_id'])) {
            $current = auth_current_page($_SERVER['SCRIPT_NAME'] ?? '');
            if ($current !== 'login.php') {
                header('Location: ' . auth_login_url());
                exit;
            }
        }
    }
}

if (!function_exists('permission_allowed')) {
    function permission_allowed(mysqli $conn, string $permission, string $pageUrl): bool
    {
        $allowedPermissions = [
            'can_view',
            'can_create',
            'can_edit',
            'can_delete',
            'can_update',
            'can_approve',
            'can_print',
            'can_export',
            'can_send_whatsapp',
            'can_assign',
            'can_override'
        ];

        if (!in_array($permission, $allowedPermissions, true)) {
            return false;
        }

        if (empty($_SESSION['user_id'])) {
            return false;
        }

        /*
         | Admin bypass removed.
         | Every role, including Admin, must follow role_page_permissions.
         */

        $pageName = auth_current_page($pageUrl);

        /*
         | Dashboard bypass removed.
         | Dashboard access also depends on role_page_permissions.
         */

        $roleIds = auth_role_ids();
        if (!$roleIds) {
            return false;
        }

        try {
            $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
            $types = str_repeat('i', count($roleIds)) . 'ss';

            $params = $roleIds;
            $params[] = $pageUrl;
            $params[] = $pageName;

            $sql = "
                SELECT COALESCE(MAX(rpp.`$permission`), 0) AS allowed
                FROM role_page_permissions rpp
                INNER JOIN sidebar_items si ON si.id = rpp.sidebar_item_id
                WHERE rpp.role_id IN ($placeholders)
                  AND si.is_active = 1
                  AND (
                        si.route = ?
                        OR SUBSTRING_INDEX(SUBSTRING_INDEX(si.route, '?', 1), '/', -1) = ?
                      )
            ";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return (int)($row['allowed'] ?? 0) === 1;
        } catch (Throwable $e) {
            error_log('permission_allowed error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('can_view')) {
    function can_view(mysqli $conn, string $page): bool { return permission_allowed($conn, 'can_view', $page); }
}
if (!function_exists('can_create')) {
    function can_create(mysqli $conn, string $page): bool { return permission_allowed($conn, 'can_create', $page); }
}
if (!function_exists('can_edit')) {
    function can_edit(mysqli $conn, string $page): bool { return permission_allowed($conn, 'can_edit', $page); }
}
if (!function_exists('can_delete')) {
    function can_delete(mysqli $conn, string $page): bool { return permission_allowed($conn, 'can_delete', $page); }
}
if (!function_exists('can_update')) {
    function can_update(mysqli $conn, string $page): bool { return permission_allowed($conn, 'can_update', $page); }
}
if (!function_exists('can_approve')) {
    function can_approve(mysqli $conn, string $page): bool { return permission_allowed($conn, 'can_approve', $page); }
}
if (!function_exists('can_print')) {
    function can_print(mysqli $conn, string $page): bool { return permission_allowed($conn, 'can_print', $page); }
}
if (!function_exists('can_export')) {
    function can_export(mysqli $conn, string $page): bool { return permission_allowed($conn, 'can_export', $page); }
}
if (!function_exists('can_send_whatsapp')) {
    function can_send_whatsapp(mysqli $conn, string $page): bool { return permission_allowed($conn, 'can_send_whatsapp', $page); }
}
if (!function_exists('can_assign')) {
    function can_assign(mysqli $conn, string $page): bool { return permission_allowed($conn, 'can_assign', $page); }
}
if (!function_exists('can_override')) {
    function can_override(mysqli $conn, string $page): bool { return permission_allowed($conn, 'can_override', $page); }
}

if (!function_exists('require_permission')) {
    function require_permission(mysqli $conn, string $permission, string $page): void
    {
        require_login();

        if (!permission_allowed($conn, $permission, $page)) {
            http_response_code(403);
            echo '<!doctype html>
            <html>
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>Access Denied</title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
            </head>
            <body class="bg-light">
                <div class="container py-5">
                    <div class="card shadow-sm border-0 rounded-4">
                        <div class="card-body p-4">
                            <h4 class="fw-bold text-danger mb-2">Access Denied</h4>
                            <p class="text-muted mb-3">You do not have permission to access this page.</p>
                            <a href="dashboard.php" class="btn btn-primary">Back to Dashboard</a>
                        </div>
                    </div>
                </div>
            </body>
            </html>';
            exit;
        }
    }
}