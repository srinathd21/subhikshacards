<?php
/**
 * reset-password.php
 */

require_once __DIR__ . '/includes/db.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

if (empty($_SESSION['reset_user_id']) || empty($_SESSION['reset_code_id']) || empty($_SESSION['reset_verified'])) {
    header('Location: forgot-password.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if (!validateCsrfToken($csrf)) {
        $error = 'Invalid request. Please refresh and try again.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Password and confirm password do not match.';
    } else {
        $pdo->beginTransaction();

        try {
            $newHash = password_hash($password, PASSWORD_BCRYPT);

            $updateUser = $pdo->prepare("
                UPDATE users
                SET password_hash = :password_hash,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $updateUser->execute([
                ':password_hash' => $newHash,
                ':id' => (int)$_SESSION['reset_user_id']
            ]);

            $updateCode = $pdo->prepare("
                UPDATE password_reset_codes
                SET used_at = NOW()
                WHERE id = :id
                  AND user_id = :user_id
            ");
            $updateCode->execute([
                ':id' => (int)$_SESSION['reset_code_id'],
                ':user_id' => (int)$_SESSION['reset_user_id']
            ]);

            activityLog(
                $pdo,
                (int)$_SESSION['reset_user_id'],
                null,
                'password_reset',
                'Authentication',
                'users',
                (int)$_SESSION['reset_user_id'],
                null,
                ['reset_at' => date('Y-m-d H:i:s')],
                'User password reset successfully'
            );

            unset(
                $_SESSION['reset_user_id'],
                $_SESSION['reset_code_id'],
                $_SESSION['reset_verified'],
                $_SESSION['reset_email_masked'],
                $_SESSION['reset_login_value'],
                $_SESSION['testing_reset_code']
            );

            $pdo->commit();

            header('Location: login.php?reset=success');
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Reset password failed: ' . $e->getMessage());
            $error = 'Unable to change password. Please try again.';
        }
    }
}

$token = csrfToken();
$profileLogoPath = 'assets/img/ecommer-logo.png';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Reset Password | Subhiksha Cards ERP</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <style>
    * { box-sizing: border-box; }
    html, body { margin: 0; width: 100%; min-height: 100%; }
    body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: radial-gradient(circle at 0% 0%, rgba(37,99,235,.22), transparent 34%), radial-gradient(circle at 100% 0%, rgba(236,72,153,.20), transparent 32%), linear-gradient(135deg,#edf5ff 0%,#f7f0ff 48%,#fff1f8 100%); overflow-x: hidden; }
    .auth-page { width: 100%; min-height: 100vh; min-height: 100dvh; display: flex; align-items: center; justify-content: center; padding: 24px 14px; }
    .auth-card { width: 100%; max-width: 430px; border-radius: 28px; padding: 34px 30px 30px; background: rgba(255,255,255,.95); border: 1px solid rgba(255,255,255,.9); box-shadow: 0 30px 80px rgba(30,41,59,.22); }
    .logo-badge { width: 92px; height: 92px; margin: 0 auto 22px; border-radius: 50%; display: grid; place-items: center; background: linear-gradient(145deg,#fff,#f5f3ff); box-shadow: 0 18px 45px rgba(79,70,229,.18); }
    .logo-badge img { width: 70px; height: 70px; object-fit: contain; }
    h1 { text-align: center; font-size: 30px; font-weight: 900; color: #111c44; margin: 0 0 8px; }
    .subtitle { text-align: center; color: #64748b; font-size: 14px; margin-bottom: 22px; line-height: 1.6; }
    .input-modern { display: flex; align-items: center; height: 60px; border: 1px solid rgba(148,163,184,.42); border-radius: 16px; overflow: hidden; background: #fff; margin-bottom: 18px; }
    .input-icon { width: 58px; height: 100%; display: grid; place-items: center; color: #4f46e5; border-right: 1px solid #e5eaf2; background: #f8fafc; }
    .input-modern input { width: 100%; height: 100%; border: 0; outline: 0; padding: 0 15px; font-size: 14px; font-weight: 600; }
    .password-eye { width: 50px; height: 100%; border: 0; background: transparent; color: #64748b; display: grid; place-items: center; }
    .btn-gradient { width: 100%; height: 60px; border: 0; border-radius: 16px; color: #fff; font-weight: 900; background: linear-gradient(100deg,#2563eb 0%,#7c3aed 48%,#ec4899 100%); box-shadow: 0 18px 32px rgba(79,70,229,.24); }
    .back-link { display: block; text-align: center; margin-top: 18px; color: #1d4ed8; font-weight: 800; text-decoration: none; }
    .alert { border: 0; border-radius: 15px; font-size: 14px; }
    @media (max-width: 480px) { .auth-page { align-items: center; padding: 20px 12px; } .auth-card { max-width: 390px; padding: 30px 18px 26px; border-radius: 24px; } h1 { font-size: 26px; } }
    @media (max-height: 680px) and (max-width: 480px) { .auth-page { align-items: flex-start; padding-top: 14px; padding-bottom: 24px; } }
    </style>
</head>
<body>
    <main class="auth-page">
        <div class="auth-card">
            <div class="logo-badge"><img src="<?= e($profileLogoPath) ?>" alt="Ecommer Logo"></div>
            <h1>Reset Password</h1>
            <p class="subtitle">Create your new password.</p>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= e($token) ?>">

                <div class="input-modern">
                    <div class="input-icon"><i class="fa-solid fa-lock"></i></div>
                    <input type="password" name="password" id="password" placeholder="New Password" required>
                    <button type="button" class="password-eye" onclick="togglePassword('password','eye1')"><i class="fa-regular fa-eye" id="eye1"></i></button>
                </div>

                <div class="input-modern">
                    <div class="input-icon"><i class="fa-solid fa-lock"></i></div>
                    <input type="password" name="confirm_password" id="confirmPassword" placeholder="Confirm Password" required>
                    <button type="button" class="password-eye" onclick="togglePassword('confirmPassword','eye2')"><i class="fa-regular fa-eye" id="eye2"></i></button>
                </div>

                <button type="submit" class="btn-gradient">Change Password <i class="fa-solid fa-arrow-right ms-1"></i></button>
            </form>

            <a href="login.php" class="back-link"><i class="fa-solid fa-arrow-left me-1"></i>Back to Login</a>
        </div>
    </main>

    <script>
    function togglePassword(inputId, iconId) {
        const input = document.getElementById(inputId);
        const icon = document.getElementById(iconId);
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
    </script>
</body>
</html>
