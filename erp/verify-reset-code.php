<?php
/**
 * verify-reset-code.php
 * Step 2: Verify 6-digit authentication code.
 */

require_once __DIR__ . '/includes/db.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$message = '';
$userId = (int)($_SESSION['password_reset_user_id'] ?? 0);
$devCode = $_SESSION['dev_password_reset_code'] ?? '';

if ($userId <= 0) {
    header('Location: forgot-password.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    $code = preg_replace('/\D+/', '', (string)($_POST['code'] ?? ''));

    if (!validateCsrfToken($csrf)) {
        $error = 'Invalid request. Please refresh and try again.';
    } elseif (strlen($code) !== 6) {
        $error = 'Please enter the valid 6-digit authentication code.';
    } else {
        $stmt = $pdo->prepare(" 
            SELECT id, code_hash, attempts, expires_at
            FROM password_reset_codes
            WHERE user_id = :user_id
              AND used_at IS NULL
              AND expires_at >= NOW()
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId]);
        $reset = $stmt->fetch();

        if (!$reset) {
            $error = 'Authentication code expired. Please request a new code.';
        } elseif ((int)$reset['attempts'] >= 5) {
            $error = 'Too many wrong attempts. Please request a new code.';
        } else {
            $enteredHash = hash('sha256', $code);

            if (!hash_equals($reset['code_hash'], $enteredHash)) {
                $updateAttempts = $pdo->prepare(" 
                    UPDATE password_reset_codes
                    SET attempts = attempts + 1
                    WHERE id = :id
                ");
                $updateAttempts->execute([':id' => (int)$reset['id']]);
                $error = 'Invalid authentication code.';
            } else {
                $markUsed = $pdo->prepare(" 
                    UPDATE password_reset_codes
                    SET used_at = NOW()
                    WHERE id = :id
                ");
                $markUsed->execute([':id' => (int)$reset['id']]);

                $_SESSION['password_reset_verified_user_id'] = $userId;
                $_SESSION['password_reset_verified_until'] = time() + (15 * 60);

                unset($_SESSION['dev_password_reset_code']);

                header('Location: reset-password.php');
                exit;
            }
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
    <title>Verify Code | Subhiksha Cards ERP</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

    <style>
    * { box-sizing: border-box; }
    html, body { min-height: 100%; margin: 0; overflow-x: hidden; }
    body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: radial-gradient(circle at 0% 0%, rgba(37,99,235,.22), transparent 34%), radial-gradient(circle at 100% 0%, rgba(236,72,153,.20), transparent 32%), linear-gradient(135deg, #edf5ff 0%, #f7f0ff 48%, #fff1f8 100%); }
    .auth-page { min-height: 100dvh; display: flex; align-items: center; justify-content: center; padding: 24px; }
    .auth-card { width: 100%; max-width: 440px; background: rgba(255,255,255,.95); border-radius: 26px; padding: 34px; box-shadow: 0 30px 80px rgba(30,41,59,.22); border: 1px solid rgba(255,255,255,.9); }
    .logo-circle { width: 88px; height: 88px; margin: 0 auto 20px; border-radius: 50%; display: grid; place-items: center; background: #fff; box-shadow: 0 18px 45px rgba(79,70,229,.18); border: 1px solid rgba(124,58,237,.20); }
    .logo-circle img { width: 64px; height: 64px; object-fit: contain; }
    h1 { text-align: center; font-size: 28px; font-weight: 900; color: #111c44; margin-bottom: 8px; }
    .subtitle { text-align: center; color: #64748b; font-size: 14px; margin-bottom: 26px; }
    .code-input { width: 100%; height: 64px; border: 1px solid #dbe3f0; border-radius: 15px; text-align: center; font-size: 26px; font-weight: 900; letter-spacing: 10px; outline: 0; }
    .code-input:focus { border-color: #7c3aed; box-shadow: 0 0 0 4px rgba(124,58,237,.10); }
    .btn-gradient { width: 100%; height: 58px; border: 0; border-radius: 15px; color: #fff; font-weight: 800; background: linear-gradient(100deg, #2563eb, #7c3aed, #ec4899); box-shadow: 0 16px 30px rgba(79,70,229,.24); }
    .back-link { display: block; text-align: center; margin-top: 18px; color: #1d4ed8; font-weight: 800; text-decoration: none; }
    .alert { border-radius: 14px; font-size: 14px; }
    @media (max-width: 480px) {
        html, body { height: auto !important; min-height: 100dvh; overflow-y: auto !important; -webkit-overflow-scrolling: touch; }
        .auth-page { min-height: 100dvh; align-items: flex-start; padding: 14px; }
        .auth-card { margin-top: 12px; padding: 26px 18px; border-radius: 22px; }
        .logo-circle { width: 76px; height: 76px; }
        .logo-circle img { width: 56px; height: 56px; }
        h1 { font-size: 24px; }
        .subtitle { font-size: 13px; }
        .code-input { height: 58px; font-size: 22px; letter-spacing: 8px; }
    }
    </style>
</head>
<body>
    <main class="auth-page">
        <div class="auth-card">
            <div class="logo-circle"><img src="<?= e($profileLogoPath) ?>" alt="Ecommer Logo"></div>
            <h1>Enter Code</h1>
            <p class="subtitle">Enter the 6-digit authentication code sent to your registered email.</p>

            <?php if ($devCode !== ''): ?>
                <div class="alert alert-warning"><strong>Testing Code:</strong> <?= e($devCode) ?><br>Remove this display in live mode.</div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                <div class="mb-3">
                    <input type="text" name="code" class="code-input" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" placeholder="------" required autofocus>
                </div>
                <button type="submit" class="btn-gradient">Verify Code <i class="fa-solid fa-arrow-right ms-1"></i></button>
            </form>

            <a href="forgot-password.php" class="back-link">Request New Code</a>
            <a href="login.php" class="back-link"><i class="fa-solid fa-arrow-left me-1"></i>Back to Login</a>
        </div>
    </main>
</body>
</html>
