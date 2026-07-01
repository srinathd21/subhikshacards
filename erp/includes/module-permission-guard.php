<?php
/**
 * includes/module-permission-guard.php
 * Common role permission helper for all Subhiksha Cards ERP pages and APIs.
 *
 * Usage in page:
 *   require_once __DIR__ . '/includes/auth.php';
 *   require_once __DIR__ . '/includes/module-permission-guard.php';
 *   $modulePerm = module_bootstrap_permissions($conn, 'enquiries.php');
 *
 * Usage in API:
 *   require_once __DIR__ . '/../includes/auth.php';
 *   require_once __DIR__ . '/../includes/module-permission-guard.php';
 *   module_require_action_permission($conn, 'enquiries.php', 'create');
 */

if (!function_exists('module_current_page')) {
    function module_current_page(string $page): string
    {
        return basename(parse_url($page, PHP_URL_PATH) ?: $page);
    }
}

if (!function_exists('module_bootstrap_permissions')) {
    function module_bootstrap_permissions(mysqli $conn, string $page): array
    {
        $page = module_current_page($page);

        require_permission($conn, 'can_view', $page);

        return [
            'page' => $page,
            'can_view' => true,
            'can_create' => can_create($conn, $page),
            'can_edit' => can_edit($conn, $page),
            'can_delete' => can_delete($conn, $page),
            'can_update' => can_update($conn, $page),
            'can_approve' => can_approve($conn, $page),
            'can_print' => can_print($conn, $page),
            'can_export' => can_export($conn, $page),
            'can_send_whatsapp' => can_send_whatsapp($conn, $page),
            'can_assign' => can_assign($conn, $page),
            'can_override' => can_override($conn, $page),
        ];
    }
}

if (!function_exists('module_action_permission')) {
    function module_action_permission(string $action, int $recordId = 0): string
    {
        $action = strtolower(trim($action));

        if (in_array($action, ['list', 'view', 'get', 'details', 'search'], true)) {
            return 'can_view';
        }

        if (in_array($action, ['create', 'store', 'add'], true)) {
            return 'can_create';
        }

        if (in_array($action, ['edit', 'update', 'save_record'], true)) {
            return $recordId > 0 ? 'can_edit' : 'can_create';
        }

        if (in_array($action, ['delete', 'remove', 'close', 'close_record', 'cancel'], true)) {
            return 'can_delete';
        }

        if (in_array($action, ['status', 'update_status', 'update_tracking_status', 'start', 'complete'], true)) {
            return 'can_update';
        }

        if (in_array($action, ['approve', 'reject'], true)) {
            return 'can_approve';
        }

        if (in_array($action, ['print'], true)) {
            return 'can_print';
        }

        if (in_array($action, ['export', 'excel', 'pdf'], true)) {
            return 'can_export';
        }

        if (in_array($action, ['send_whatsapp', 'send_whatsapp_api', 'log_manual_whatsapp'], true)) {
            return 'can_send_whatsapp';
        }

        if (in_array($action, ['assign'], true)) {
            return 'can_assign';
        }

        if (in_array($action, ['override'], true)) {
            return 'can_override';
        }

        return 'can_view';
    }
}

if (!function_exists('module_require_action_permission')) {
    function module_require_action_permission(mysqli $conn, string $page, string $action, int $recordId = 0, bool $json = true): void
    {
        $page = module_current_page($page);
        $permission = module_action_permission($action, $recordId);

        if (!permission_allowed($conn, $permission, $page)) {
            if ($json) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'status' => false,
                    'success' => false,
                    'message' => 'You do not have permission for this action.'
                ]);
                exit;
            }

            require_permission($conn, $permission, $page);
        }
    }
}
