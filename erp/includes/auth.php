<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/whatsapp-api.php';

/*
 | -------------------------------------------------------
 | mysqli connection for reference UI files
 | -------------------------------------------------------
 | Your db.php already creates $pdo. The uploaded reference UI uses $conn.
 | So this file creates $conn also.
 */
if (!isset($conn) || !($conn instanceof mysqli)) {
    $DB_HOST = 'srv1740.hstgr.io';
    $DB_NAME = 'u966043993_subhiksha';
    $DB_USER = 'u966043993_subhiksha';
    $DB_PASS = 'C^Iy3jgM!8';

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
        $conn->set_charset('utf8mb4');
    } catch (Throwable $e) {
        http_response_code(500);
        die('Database connection failed in includes/auth.php');
    }
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('require_login')) {
    function require_login(): void
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: login.php');
            exit;
        }
    }
}

if (!function_exists('is_admin_user')) {
    function is_admin_user(): bool
    {
        $roleKey = strtolower((string)($_SESSION['role_key'] ?? ''));
        $roleName = strtolower((string)($_SESSION['role_name'] ?? ''));

        return $roleKey === 'admin' || $roleName === 'admin';
    }
}

if (!function_exists('auth_current_page')) {
    function auth_current_page(string $pageUrl): string
    {
        return basename(parse_url($pageUrl, PHP_URL_PATH) ?: $pageUrl);
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

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $roleId = (int)($_SESSION['role_id'] ?? 0);

        if ($userId <= 0 || $roleId <= 0) {
            return false;
        }

        /*
         | Admin should always get full access.
         */
        if (is_admin_user()) {
            return true;
        }

        /*
         | Allow dashboard after login.
         */
        $pageName = auth_current_page($pageUrl);
        if (in_array($pageName, ['index.php', 'dashboard.php'], true)) {
            return true;
        }

        try {
            /*
             | Your current DB uses:
             | sidebar_items + role_page_permissions
             */
            $sql = "
                SELECT COALESCE(MAX(rpp.$permission), 0) AS allowed
                FROM role_page_permissions rpp
                INNER JOIN sidebar_items si ON si.id = rpp.sidebar_item_id
                WHERE rpp.role_id = ?
                  AND si.is_active = 1
                  AND (
                        si.route = ?
                        OR SUBSTRING_INDEX(SUBSTRING_INDEX(si.route, '?', 1), '/', -1) = ?
                      )
            ";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param('iss', $roleId, $pageUrl, $pageName);
            $stmt->execute();

            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return (int)($row['allowed'] ?? 0) === 1;
        } catch (Throwable $e) {
            /*
             | If permission table is not ready, keep admin working only.
             */
            return false;
        }
    }
}

if (!function_exists('can_view')) {
    function can_view(mysqli $conn, string $page): bool
    {
        return permission_allowed($conn, 'can_view', $page);
    }
}

if (!function_exists('can_create')) {
    function can_create(mysqli $conn, string $page): bool
    {
        return permission_allowed($conn, 'can_create', $page);
    }
}

if (!function_exists('can_edit')) {
    function can_edit(mysqli $conn, string $page): bool
    {
        return permission_allowed($conn, 'can_edit', $page);
    }
}

if (!function_exists('can_delete')) {
    function can_delete(mysqli $conn, string $page): bool
    {
        return permission_allowed($conn, 'can_delete', $page);
    }
}

if (!function_exists('can_update')) {
    function can_update(mysqli $conn, string $page): bool
    {
        return permission_allowed($conn, 'can_update', $page);
    }
}

if (!function_exists('can_approve')) {
    function can_approve(mysqli $conn, string $page): bool
    {
        return permission_allowed($conn, 'can_approve', $page);
    }
}

if (!function_exists('can_print')) {
    function can_print(mysqli $conn, string $page): bool
    {
        return permission_allowed($conn, 'can_print', $page);
    }
}

if (!function_exists('can_export')) {
    function can_export(mysqli $conn, string $page): bool
    {
        return permission_allowed($conn, 'can_export', $page);
    }
}

if (!function_exists('can_send_whatsapp')) {
    function can_send_whatsapp(mysqli $conn, string $page): bool
    {
        return permission_allowed($conn, 'can_send_whatsapp', $page);
    }
}

if (!function_exists('can_assign')) {
    function can_assign(mysqli $conn, string $page): bool
    {
        return permission_allowed($conn, 'can_assign', $page);
    }
}

if (!function_exists('can_override')) {
    function can_override(mysqli $conn, string $page): bool
    {
        return permission_allowed($conn, 'can_override', $page);
    }
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