<?php
/**
 * includes/db.php
 * Subiksha Card ERP - Localhost Database Connection
 */

declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');

$dbHost = 'srv1740.hstgr.io';
$dbName = 'u966043993_subhiksha';
$dbUser = 'u966043993_subhiksha';
$dbPass = 'C^Iy3jgM!8';

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
} catch (PDOException $e) {
    http_response_code(500);
    die('Database connection failed. Check includes/db.php');
}

if (session_status() === PHP_SESSION_NONE) {
    session_name('SUBHIKSHA_CARD_ERP');
    session_start();
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function redirectIfLoggedIn(): void
{
    if (isLoggedIn()) {
        header('Location: dashboard.php');
        exit;
    }
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function validateCsrfToken(?string $token): bool
{
    return !empty($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function clientIp(): string
{
    return $_SERVER['HTTP_CLIENT_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '';
}

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
        // Do not stop the main process if log insert fails.
    }
}