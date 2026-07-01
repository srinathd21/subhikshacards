<?php
/**
 * includes/db.php
 * Subhiksha Card ERP - LIVE Hostinger Database Connection
 */

declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');

/*
 | -------------------------------------------------------
 | LIVE Database credentials
 | -------------------------------------------------------
 | This file is for live Hostinger deployment.
 | No localhost fallback is used here.
 */
$dbHost = 'srv1740.hstgr.io';
$dbName = 'u966043993_subhiksha';
$dbUser = 'u966043993_subhiksha';
$dbPass = 'C^Iy3jgM!8';

$pdo  = null;
$conn = null;

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    $conn->set_charset('utf8mb4');
} catch (Throwable $e) {
    http_response_code(500);
    error_log('Subhiksha LIVE DB connection failed: ' . $e->getMessage());
    die('Database connection failed. Please check live database credentials in includes/db.php');
}

/* Optional constants */
if (!defined('DB_HOST')) {
    define('DB_HOST', $dbHost);
}
if (!defined('DB_NAME')) {
    define('DB_NAME', $dbName);
}
if (!defined('DB_USER')) {
    define('DB_USER', $dbUser);
}
if (!defined('DB_PASS')) {
    define('DB_PASS', $dbPass);
}
if (!defined('DB_CONNECTION_LABEL')) {
    define('DB_CONNECTION_LABEL', 'live_hostinger');
}

/*
 | -------------------------------------------------------
 | Session
 | -------------------------------------------------------
 | Must be started only here with the project session name.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_name('SUBHIKSHA_CARD_ERP');
    session_start();
}

/*
 | -------------------------------------------------------
 | Common helper functions
 | -------------------------------------------------------
 */
if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('isLoggedIn')) {
    function isLoggedIn(): bool
    {
        return !empty($_SESSION['user_id']);
    }
}

if (!function_exists('requireLogin')) {
    function requireLogin(): void
    {
        if (!isLoggedIn()) {
            header('Location: login.php');
            exit;
        }
    }
}

/* Alias used by auth.php and module files */
if (!function_exists('require_login')) {
    function require_login(): void
    {
        requireLogin();
    }
}

if (!function_exists('redirectIfLoggedIn')) {
    function redirectIfLoggedIn(): void
    {
        if (isLoggedIn()) {
            header('Location: dashboard.php');
            exit;
        }
    }
}

if (!function_exists('csrfToken')) {
    function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('validateCsrfToken')) {
    function validateCsrfToken(?string $token): bool
    {
        return !empty($token)
            && !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('clientIp')) {
    function clientIp(): string
    {
        return $_SERVER['HTTP_CLIENT_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '';
    }
}

/*
 | -------------------------------------------------------
 | Activity log
 | -------------------------------------------------------
 */
if (!function_exists('activityLog')) {
    function activityLog(
        PDO $pdo,
        ?int $userId,
        ?int $roleId,
        string $actionKey,
        string $moduleName,
        ?string $tableName = null,
        ?int $recordId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null
    ): void {
        try {
            $actionTypeId = null;

            $stmt = $pdo->prepare("
                SELECT id
                FROM activity_action_types
                WHERE action_key = :action_key AND is_active = 1
                LIMIT 1
            ");
            $stmt->execute([
                ':action_key' => $actionKey
            ]);

            $actionType = $stmt->fetch();

            if ($actionType) {
                $actionTypeId = (int)$actionType['id'];
            }

            $stmt = $pdo->prepare("
                INSERT INTO activity_logs
                (
                    user_id,
                    role_id,
                    action_type_id,
                    action_key,
                    module_name,
                    table_name,
                    record_id,
                    old_values,
                    new_values,
                    description,
                    ip_address,
                    user_agent,
                    created_at
                )
                VALUES
                (
                    :user_id,
                    :role_id,
                    :action_type_id,
                    :action_key,
                    :module_name,
                    :table_name,
                    :record_id,
                    :old_values,
                    :new_values,
                    :description,
                    :ip_address,
                    :user_agent,
                    NOW()
                )
            ");

            $stmt->execute([
                ':user_id'        => $userId,
                ':role_id'        => $roleId,
                ':action_type_id' => $actionTypeId,
                ':action_key'     => $actionKey,
                ':module_name'    => $moduleName,
                ':table_name'     => $tableName,
                ':record_id'      => $recordId,
                ':old_values'     => $oldValues ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
                ':new_values'     => $newValues ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
                ':description'    => $description,
                ':ip_address'     => clientIp(),
                ':user_agent'     => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ]);
        } catch (Throwable $e) {
            /* Do not stop the main process if activity log insert fails. */
        }
    }
}