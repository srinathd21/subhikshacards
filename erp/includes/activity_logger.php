<?php
/**
 * includes/activity_logger.php
 * Common activity logging helper for Subhiksha Cards ERP.
 * Include this file in action/API pages and call sc_log_activity() after create/update/delete/etc.
 */

if (!function_exists('sc_activity_table_exists')) {
    function sc_activity_table_exists(mysqli $conn, string $table): bool
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
}

if (!function_exists('sc_activity_column_exists')) {
    function sc_activity_column_exists(mysqli $conn, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $table = $conn->real_escape_string($table);
            $column = $conn->real_escape_string($column);
            $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
            $ok = $res && $res->num_rows > 0;
            if ($res) {
                $res->free();
            }
            return $cache[$key] = $ok;
        } catch (Throwable $e) {
            return $cache[$key] = false;
        }
    }
}

if (!function_exists('sc_activity_action_type_id')) {
    function sc_activity_action_type_id(mysqli $conn, string $actionKey): ?int
    {
        if ($actionKey === '' || !sc_activity_table_exists($conn, 'activity_action_types')) {
            return null;
        }

        try {
            $stmt = $conn->prepare("SELECT id FROM activity_action_types WHERE action_key = ? LIMIT 1");
            $stmt->bind_param('s', $actionKey);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return $row ? (int)$row['id'] : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('sc_log_activity')) {
    function sc_log_activity(
        mysqli $conn,
        string $actionKey,
        string $moduleName,
        string $tableName = '',
        ?int $recordId = null,
        string $description = '',
        array $details = []
    ): bool {
        if (!sc_activity_table_exists($conn, 'activity_logs')) {
            return false;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $actionKey = trim($actionKey);
        $moduleName = trim($moduleName);
        $tableName = trim($tableName);
        $description = trim($description);

        if ($actionKey === '') {
            $actionKey = 'activity';
        }
        if ($moduleName === '') {
            $moduleName = 'System';
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $roleId = (int)($_SESSION['role_id'] ?? 0);
        $actionTypeId = sc_activity_action_type_id($conn, $actionKey);
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $detailsJson = $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        try {
            $fields = [];
            $placeholders = [];
            $types = '';
            $values = [];

            $add = function (string $column, string $type, $value) use (&$fields, &$placeholders, &$types, &$values, $conn) {
                if (sc_activity_column_exists($conn, 'activity_logs', $column)) {
                    $fields[] = $column;
                    $placeholders[] = '?';
                    $types .= $type;
                    $values[] = $value;
                }
            };

            $add('user_id', 'i', $userId > 0 ? $userId : null);
            $add('role_id', 'i', $roleId > 0 ? $roleId : null);
            $add('action_type_id', 'i', $actionTypeId);
            $add('action_key', 's', $actionKey);
            $add('module_name', 's', $moduleName);
            $add('table_name', 's', $tableName);
            $add('record_id', 'i', $recordId);
            $add('description', 's', $description);
            $add('ip_address', 's', $ip);
            $add('user_agent', 's', $userAgent);

            // Optional columns if you add them later.
            $add('details', 's', $detailsJson);
            $add('meta_data', 's', $detailsJson);
            $add('old_values', 's', isset($details['old']) ? json_encode($details['old'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null);
            $add('new_values', 's', isset($details['new']) ? json_encode($details['new'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null);

            if (sc_activity_column_exists($conn, 'activity_logs', 'created_at')) {
                $fields[] = 'created_at';
                $placeholders[] = 'NOW()';
            }

            if (!$fields) {
                return false;
            }

            $sql = 'INSERT INTO activity_logs (`' . implode('`,`', $fields) . '`) VALUES (' . implode(',', $placeholders) . ')';
            $stmt = $conn->prepare($sql);

            if ($types !== '') {
                $stmt->bind_param($types, ...$values);
            }

            $ok = $stmt->execute();
            $stmt->close();

            return (bool)$ok;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('logActivity')) {
    function logActivity(
        mysqli $conn,
        string $actionKey,
        string $moduleName,
        string $tableName = '',
        ?int $recordId = null,
        string $description = '',
        array $details = []
    ): bool {
        return sc_log_activity($conn, $actionKey, $moduleName, $tableName, $recordId, $description, $details);
    }
}
