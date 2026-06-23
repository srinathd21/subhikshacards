<?php
/**
 * login.php
 * Subhiksha Card ERP - Login Page with Bcrypt Password Verification + Logo
 */

require_once __DIR__ . '/includes/db.php';

redirectIfLoggedIn();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login    = trim($_POST['login'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $csrf     = $_POST['csrf_token'] ?? '';

    if (!validateCsrfToken($csrf)) {
        $error = 'Invalid request. Please refresh and try again.';
    } elseif ($login === '' || $password === '') {
        $error = 'Please enter username/email/mobile and password.';
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                u.id,
                u.role_id,
                u.name,
                u.email,
                u.mobile,
                u.username,
                u.password_hash,
                u.profile_image,
                u.is_active,
                r.role_name,
                r.role_key
            FROM users u
            INNER JOIN roles r ON r.id = u.role_id
            WHERE 
                u.username = :login_username
                OR u.email = :login_email
                OR u.mobile = :login_mobile
            LIMIT 1
        ");

        $stmt->execute([
            ':login_username' => $login,
            ':login_email'    => $login,
            ':login_mobile'   => $login
        ]);

        $user = $stmt->fetch();

        if (!$user) {
            $error = 'Invalid login details.';
        } elseif ((int)$user['is_active'] !== 1) {
            $error = 'Your account is inactive. Please contact Admin.';
        } else {
            /*
             | Bcrypt password verification.
             | New users must be saved using:
             | password_hash($password, PASSWORD_BCRYPT)
             */
            $storedHash = trim((string)$user['password_hash']);
            $passwordOk = false;

            if (password_verify($password, $storedHash)) {
                $passwordOk = true;

                if (password_needs_rehash($storedHash, PASSWORD_BCRYPT)) {
                    $newHash = password_hash($password, PASSWORD_BCRYPT);

                    $rehashStmt = $pdo->prepare("
                        UPDATE users
                        SET password_hash = :password_hash,
                            updated_at = NOW()
                        WHERE id = :id
                    ");
                    $rehashStmt->execute([
                        ':password_hash' => $newHash,
                        ':id'            => (int)$user['id']
                    ]);
                }
            }

            /*
             | Temporary fallback for old/plain text passwords.
             | First successful login converts old plain password to bcrypt.
             | Remove this block after all users are migrated.
             */
            if (!$passwordOk && hash_equals($storedHash, $password)) {
                $passwordOk = true;

                $newHash = password_hash($password, PASSWORD_BCRYPT);

                $rehashStmt = $pdo->prepare("
                    UPDATE users
                    SET password_hash = :password_hash,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $rehashStmt->execute([
                    ':password_hash' => $newHash,
                    ':id'            => (int)$user['id']
                ]);
            }

            if (!$passwordOk) {
                $error = 'Invalid login details.';
            } else {
                session_regenerate_id(true);

                $_SESSION['user_id']       = (int)$user['id'];
                $_SESSION['role_id']       = (int)$user['role_id'];
                $_SESSION['name']          = $user['name'];
                $_SESSION['email']         = $user['email'];
                $_SESSION['mobile']        = $user['mobile'];
                $_SESSION['username']      = $user['username'];
                $_SESSION['role_name']     = $user['role_name'];
                $_SESSION['role_key']      = $user['role_key'];
                $_SESSION['profile_image'] = $user['profile_image'];
                $_SESSION['logged_in_at']  = date('Y-m-d H:i:s');

                $update = $pdo->prepare("
                    UPDATE users
                    SET last_login_at = NOW()
                    WHERE id = :id
                ");
                $update->execute([
                    ':id' => (int)$user['id']
                ]);

                activityLog(
                    $pdo,
                    (int)$user['id'],
                    (int)$user['role_id'],
                    'login',
                    'Authentication',
                    'users',
                    (int)$user['id'],
                    null,
                    [
                        'username' => $user['username'],
                        'role'     => $user['role_name'],
                        'login_at' => date('Y-m-d H:i:s')
                    ],
                    'User logged in successfully'
                );

                header('Location: dashboard.php');
                exit;
            }
        }
    }
}

$token = csrfToken();
$logoPath = 'assets/img/subhiksha-logo.png';
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Login | Subhiksha Cards ERP</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap and Font Awesome CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

    <style>
    :root {
        --primary: #003f82;
        --secondary: #ef8700;
        --dark: #111827;
        --muted: #6b7280;
        --border: #e5e7eb;
    }

    body {
        min-height: 100vh;
        margin: 0;
        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        background-color: #f3f4f6;
    }

    .login-page {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px;
    }

    .login-box {
        width: 100%;
        max-width: 1040px;
        background: #fff;
        border-radius: 28px;
        overflow: hidden;
        box-shadow: 0 30px 100px rgba(15, 23, 42, .30);
        display: grid;
        grid-template-columns: 1fr 430px;
    }

    .login-left {
        padding: 54px;
        color: #fff;
        background: linear-gradient(135deg, rgba(0, 63, 130, .96), rgba(239, 135, 0, .92));
        position: relative;
        overflow: hidden;
    }

    .login-left::after {
        content: "";
        position: absolute;
        width: 280px;
        height: 280px;
        right: -80px;
        bottom: -90px;
        border-radius: 50%;
        background: rgba(255, 255, 255, .14);
    }

    .brand-logo-box {
        background: #fff;
        border-radius: 22px;
        padding: 14px 18px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 24px;
        box-shadow: 0 14px 32px rgba(0, 0, 0, .16);
    }

    .brand-logo {
        width: 210px;
        max-width: 100%;
        height: auto;
        display: block;
    }

    .login-left h1 {
        font-weight: 800;
        font-size: 38px;
        line-height: 1.14;
        margin-bottom: 16px;
    }

    .login-left p {
        max-width: 540px;
        font-size: 16px;
        line-height: 1.7;
        opacity: .94;
    }

    .feature-list {
        margin-top: 32px;
        display: grid;
        gap: 14px;
        position: relative;
        z-index: 1;
    }

    .feature-item {
        display: flex;
        gap: 12px;
        align-items: center;
        font-weight: 600;
    }

    .feature-item span {
        width: 34px;
        height: 34px;
        border-radius: 12px;
        background: rgba(255, 255, 255, .18);
        display: grid;
        place-items: center;
    }

    .login-right {
        padding: 42px 36px;
        background: #fff;
    }

    .mobile-brand {
        display: none;
        text-align: center;
        margin-bottom: 20px;
    }

    .mobile-brand img {
        width: 190px;
        max-width: 100%;
        height: auto;
    }

    .login-title {
        font-size: 26px;
        font-weight: 800;
        color: var(--dark);
        margin-bottom: 6px;
    }

    .login-subtitle {
        color: var(--muted);
        font-size: 14px;
        margin-bottom: 28px;
    }

    .form-label {
        font-weight: 700;
        color: #374151;
        font-size: 14px;
    }

    .input-group-text {
        background: #f9fafb;
        border-color: var(--border);
        color: #6b7280;
        border-radius: 14px 0 0 14px;
    }

    .form-control {
        border-color: var(--border);
        padding: 12px 14px;
        border-radius: 0 14px 14px 0;
        font-size: 15px;
    }

    .form-control:focus {
        box-shadow: 0 0 0 .2rem rgba(0, 63, 130, .12);
        border-color: #66a3d9;
    }

    .password-toggle {
        border-color: var(--border);
        border-radius: 0 14px 14px 0;
    }

    .btn-login {
        width: 100%;
        padding: 13px 16px;
        border-radius: 15px;
        border: 0;
        color: #fff;
        font-weight: 800;
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        box-shadow: 0 14px 30px rgba(0, 63, 130, .25);
    }

    .btn-login:hover {
        color: #fff;
        opacity: .96;
    }

    .alert {
        border-radius: 14px;
        font-size: 14px;
    }

    .login-footer {
        margin-top: 24px;
        text-align: center;
        color: var(--muted);
        font-size: 13px;
    }

    @media (max-width: 900px) {
        .login-box {
            grid-template-columns: 1fr;
            max-width: 450px;
        }

        .login-left {
            display: none;
        }

        .login-right {
            padding: 34px 24px;
        }

        .mobile-brand {
            display: block;
        }
    }
    </style>
</head>

<body>

    <div class="login-page">
        <div class="login-box">

            <div class="login-left">
                <div class="brand-logo-box">
                    <img src="<?= e($logoPath) ?>" alt="Subhiksha Cards Logo" class="brand-logo">
                </div>

                <h1>Subhiksha Cards ERP</h1>
                <p>
                    Manage enquiries, follow-ups, quotations, proforma bills, job cards,
                    proofing, printing production, dispatch and customer tracking.
                </p>

                <div class="feature-list">
                    <div class="feature-item">
                        <span><i class="fa-solid fa-phone"></i></span>
                        Enquiry and follow-up management
                    </div>
                    <div class="feature-item">
                        <span><i class="fa-solid fa-file-invoice"></i></span>
                        Quotation and proforma billing
                    </div>
                    <div class="feature-item">
                        <span><i class="fa-solid fa-print"></i></span>
                        Design, proofing and printing workflow
                    </div>
                    <div class="feature-item">
                        <span><i class="fa-brands fa-whatsapp"></i></span>
                        WhatsApp approval and tracking
                    </div>
                </div>
            </div>

            <div class="login-right">
                <div class="mobile-brand">
                    <img src="<?= e($logoPath) ?>" alt="Subhiksha Cards Logo">
                </div>

                <h2 class="login-title">Welcome Back</h2>
                <p class="login-subtitle">Login with username, email or mobile number</p>

                <?php if ($error !== ''): ?>
                <div class="alert alert-danger">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>
                    <?= e($error) ?>
                </div>
                <?php endif; ?>

                <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">

                    <div class="mb-3">
                        <label class="form-label">Username / Email / Mobile</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fa-solid fa-user"></i>
                            </span>
                            <input type="text" name="login" class="form-control" placeholder="Enter username"
                                value="<?= e($_POST['login'] ?? '') ?>" required autofocus>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fa-solid fa-lock"></i>
                            </span>
                            <input type="password" name="password" id="password" class="form-control"
                                placeholder="Enter password" required>
                            <button type="button" class="btn btn-outline-secondary password-toggle"
                                onclick="togglePassword()">
                                <i class="fa-solid fa-eye" id="eyeIcon"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-login mt-2">
                        <i class="fa-solid fa-right-to-bracket me-1"></i>
                        Login
                    </button>
                </form>

                <div class="login-footer">
                    Printing & Card Production Management System
                </div>
            </div>

        </div>
    </div>

    <script>
    function togglePassword() {
        const password = document.getElementById('password');
        const icon = document.getElementById('eyeIcon');

        if (password.type === 'password') {
            password.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            password.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
    </script>

</body>

</html>