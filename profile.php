<?php
require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'profile.php');

$user = [];
try {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $stmt = $conn->prepare("
        SELECT u.*, r.role_name
        FROM users u
        LEFT JOIN roles r ON r.id = u.role_id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
} catch (Throwable $e) {
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Profile - Subhiksha Cards</title>
    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>
</head>
<body class="<?= e(($theme['layout_density'] ?? '') === 'compact' ? 'layout-compact' : '') ?>">
<div id="mobileOverlay"></div>
<div class="app-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main id="main">
        <?php include __DIR__ . '/includes/nav.php'; ?>
        <section class="page-section">
            <div class="card-ui p-4">
                <h1 class="fw-bold mb-1">My Profile</h1>
                <p class="text-muted-custom">Current logged in user details.</p>

                <div class="table-responsive">
                    <table class="table-ui">
                        <tbody>
                            <tr><th>Name</th><td><?= e($user['name'] ?? '-') ?></td></tr>
                            <tr><th>Username</th><td><?= e($user['username'] ?? '-') ?></td></tr>
                            <tr><th>Email</th><td><?= e($user['email'] ?? '-') ?></td></tr>
                            <tr><th>Mobile</th><td><?= e($user['mobile'] ?? '-') ?></td></tr>
                            <tr><th>Role</th><td><?= e($user['role_name'] ?? '-') ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
    <div id="settingsOverlay"></div>
    <?php include __DIR__ . '/includes/rightsidebar.php'; ?>
</div>
<?php include __DIR__ . '/includes/script.php'; ?>
</body>
</html>
