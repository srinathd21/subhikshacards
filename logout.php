<?php
/**
 * logout.php
 * Subhiksha Card ERP - Logout
 */

require_once __DIR__ . '/includes/db.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$roleId = (int)($_SESSION['role_id'] ?? 0);

if ($userId > 0) {
    activityLog(
        $pdo,
        $userId,
        $roleId,
        'logout',
        'Authentication',
        'users',
        $userId,
        null,
        null,
        'User logged out'
    );
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'] ?? '/',
        $params['domain'] ?? '',
        $params['secure'] ?? false,
        $params['httponly'] ?? true
    );
}

session_destroy();

header('Location: login.php');
exit;
