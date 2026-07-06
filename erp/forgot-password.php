<?php
/**
 * forgot-password.php
 * Sends a 6-digit authentication code to the user's email and redirects to verify-reset-code.php.
 */

require_once __DIR__ . '/includes/db.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

/*
 * For local testing, keep true. The code will be stored in session and shown in verify-reset-code.php.
 * For live, change to false after email sending is working.
 */
define('SHOW_AUTH_CODE_ON_SCREEN', true);

/* Change this to your real domain email before live use. */
define('MAIL_FROM_EMAIL', 'no-reply@ecommer.in');
define('MAIL_FROM_NAME', 'Subhiksha Cards ERP');

$error = '';

function maskEmailAddress(string $email): string
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'your registered email';
    }

    [$name, $domain] = explode('@', $email, 2);
    $nameMask = substr($name, 0, 2) . str_repeat('*', max(strlen($name) - 2, 2));

    return $nameMask . '@' . $domain;
}

function sendResetCodeMail(string $toEmail, string $toName, string $code): bool
{
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $subject = 'Your Subhiksha Cards ERP Password Reset Code';

    $body = "Hello " . ($toName !== '' ? $toName : 'User') . ",\n\n";
    $body .= "Your password reset authentication code is: " . $code . "\n\n";
    $body .= "This code is valid for 10 minutes.\n";
    $body .= "If you did not request this, please ignore this email.\n\n";
    $body .= "Regards,\n" . MAIL_FROM_NAME;

    $headers = [];
    $headers[] = 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_EMAIL . '>';
    $headers[] = 'Reply-To: ' . MAIL_FROM_EMAIL;
    $headers[] = 'X-Mailer: PHP/' . phpversion();
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';

    return mail($toEmail, $subject, $body, implode("\r\n", $headers));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $csrf  = $_POST['csrf_token'] ?? '';

    if (!validateCsrfToken($csrf)) {
        $error = 'Invalid request. Please refresh and try again.';
    } elseif ($login === '') {
        $error = 'Please enter your username, email or mobile number.';
    } else {
        $stmt = $pdo->prepare("
            SELECT id, name, email, username, mobile, is_active
            FROM users
            WHERE username = :login_username
               OR email = :login_email
               OR mobile = :login_mobile
            LIMIT 1
        ");

        $stmt->execute([
            ':login_username' => $login,
            ':login_email'    => $login,
            ':login_mobile'   => $login
        ]);

        $user = $stmt->fetch();

        if (!$user || (int)$user['is_active'] !== 1) {
            $error = 'Account not found or inactive. Please contact Admin.';
        } elseif (empty($user['email'])) {
            $error = 'No email address is linked with this account. Please contact Admin.';
        } else {
            $code = (string)random_int(100000, 999999);
            $codeHash = password_hash($code, PASSWORD_BCRYPT);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

            $pdo->beginTransaction();

            try {
                $closeOld = $pdo->prepare("
                    UPDATE password_reset_codes
                    SET used_at = NOW()
                    WHERE user_id = :user_id
                      AND used_at IS NULL
                ");
                $closeOld->execute([
                    ':user_id' => (int)$user['id']
                ]);

                $insert = $pdo->prepare("
                    INSERT INTO password_reset_codes
                    (
                        user_id,
                        code_hash,
                        expires_at,
                        attempts,
                        created_at
                    )
                    VALUES
                    (
                        :user_id,
                        :code_hash,
                        :expires_at,
                        0,
                        NOW()
                    )
                ");

                $insert->execute([
                    ':user_id'    => (int)$user['id'],
                    ':code_hash'  => $codeHash,
                    ':expires_at' => $expiresAt
                ]);

                $resetCodeId = (int)$pdo->lastInsertId();

                $mailSent = sendResetCodeMail((string)$user['email'], (string)$user['name'], $code);

                /*
                 * In local testing, mail() may fail. SHOW_AUTH_CODE_ON_SCREEN allows testing without mail server.
                 * In live, set SHOW_AUTH_CODE_ON_SCREEN to false, then mail must be sent successfully.
                 */
                if (!$mailSent && SHOW_AUTH_CODE_ON_SCREEN === false) {
                    throw new RuntimeException('Mail sending failed. Please check hosting email configuration.');
                }

                $_SESSION['reset_user_id'] = (int)$user['id'];
                $_SESSION['reset_code_id'] = $resetCodeId;
                $_SESSION['reset_email_masked'] = maskEmailAddress((string)$user['email']);
                $_SESSION['reset_login_value'] = $login;

                if (SHOW_AUTH_CODE_ON_SCREEN) {
                    $_SESSION['testing_reset_code'] = $code;
                }

                $pdo->commit();

                /* Important fix: redirect to verify page after Send Code. */
                header('Location: verify-reset-code.php');
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('Forgot password code creation failed: ' . $e->getMessage());
                $error = 'Unable to send authentication code. Please try again.';
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
    <title>Forgot Password | Subhiksha Cards ERP</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

    <style>
    * { box-sizing: border-box; }

    html, body {
        margin: 0;
        width: 100%;
        min-height: 100%;
    }

    body {
        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        background:
            radial-gradient(circle at 0% 0%, rgba(37, 99, 235, .22), transparent 34%),
            radial-gradient(circle at 100% 0%, rgba(236, 72, 153, .20), transparent 32%),
            linear-gradient(135deg, #edf5ff 0%, #f7f0ff 48%, #fff1f8 100%);
        overflow-x: hidden;
    }

    .auth-page {
        width: 100%;
        min-height: 100vh;
        min-height: 100dvh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px 14px;
    }

    .auth-card {
        width: 100%;
        max-width: 430px;
        border-radius: 28px;
        padding: 34px 30px 30px;
        background: rgba(255, 255, 255, .95);
        border: 1px solid rgba(255, 255, 255, .9);
        box-shadow: 0 30px 80px rgba(30, 41, 59, .22);
    }

    .logo-badge {
        width: 92px;
        height: 92px;
        margin: 0 auto 22px;
        border-radius: 50%;
        display: grid;
        place-items: center;
        background: linear-gradient(145deg, #ffffff, #f5f3ff);
        border: 1px solid rgba(124, 58, 237, .13);
        box-shadow: 0 18px 45px rgba(79, 70, 229, .18);
    }

    .logo-badge img {
        width: 70px;
        height: 70px;
        object-fit: contain;
    }

    h1 {
        text-align: center;
        font-size: 30px;
        font-weight: 900;
        color: #111c44;
        margin: 0 0 8px;
    }

    .subtitle {
        text-align: center;
        color: #64748b;
        font-size: 14px;
        margin-bottom: 26px;
        line-height: 1.6;
    }

    .input-modern {
        display: flex;
        align-items: center;
        height: 60px;
        border: 1px solid rgba(148, 163, 184, .42);
        border-radius: 16px;
        overflow: hidden;
        background: #fff;
        margin-bottom: 18px;
    }

    .input-icon {
        width: 58px;
        height: 100%;
        display: grid;
        place-items: center;
        color: #4f46e5;
        border-right: 1px solid #e5eaf2;
        background: #f8fafc;
    }

    .input-modern input {
        width: 100%;
        height: 100%;
        border: 0;
        outline: 0;
        padding: 0 15px;
        font-size: 14px;
        font-weight: 600;
    }

    .btn-gradient {
        width: 100%;
        height: 60px;
        border: 0;
        border-radius: 16px;
        color: #fff;
        font-weight: 900;
        background: linear-gradient(100deg, #2563eb 0%, #7c3aed 48%, #ec4899 100%);
        box-shadow: 0 18px 32px rgba(79, 70, 229, .24);
    }

    .back-link {
        display: block;
        text-align: center;
        margin-top: 18px;
        color: #1d4ed8;
        font-weight: 800;
        text-decoration: none;
    }

    .alert {
        border: 0;
        border-radius: 15px;
        font-size: 14px;
    }

    @media (max-width: 480px) {
        .auth-page {
            align-items: center;
            padding: 20px 12px;
        }

        .auth-card {
            max-width: 390px;
            padding: 30px 18px 26px;
            border-radius: 24px;
        }

        .logo-badge {
            width: 82px;
            height: 82px;
        }

        .logo-badge img {
            width: 62px;
            height: 62px;
        }

        h1 { font-size: 26px; }
    }

    @media (max-height: 620px) and (max-width: 480px) {
        .auth-page {
            align-items: flex-start;
            padding-top: 14px;
            padding-bottom: 24px;
        }
    }
    </style>
</head>
<body>
    <main class="auth-page">
        <div class="auth-card">
            <div class="logo-badge">
                <img src="<?= e($profileLogoPath) ?>" alt="Ecommer Logo">
            </div>

            <h1>Forgot Password?</h1>
            <p class="subtitle">Enter your username, email or mobile number. We will send a 6-digit authentication code to your registered email.</p>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= e($token) ?>">

                <div class="input-modern">
                    <div class="input-icon"><i class="fa-regular fa-user"></i></div>
                    <input type="text" name="login" placeholder="Username / Email / Mobile" value="<?= e($_POST['login'] ?? '') ?>" required autofocus>
                </div>

                <button type="submit" class="btn-gradient">Send Code to Mail <i class="fa-solid fa-arrow-right ms-1"></i></button>
            </form>

            <a href="login.php" class="back-link"><i class="fa-solid fa-arrow-left me-1"></i>Back to Login</a>
        </div>
    </main>
</body>
</html>
