<?php
/**
 * login.php
 * Subhiksha Cards ERP - Gradient Responsive Login UI
 * Admin account link removed.
 * Ecommer logo is used inside the profile icon and stays visible on mobile.
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

            /* Temporary fallback for old/plain text passwords. */
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
$brandLogoPath = 'assets/img/subhiksha-logo.png';
$profileLogoPath = 'assets/img/ecommer-logo.png';
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Login | Subhiksha Cards ERP</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

    <style>
    :root {
        --blue: #2563eb;
        --purple: #7c3aed;
        --pink: #ec4899;
        --dark: #111827;
        --muted: #64748b;
        --border: #dbe3f0;
    }

    * {
        box-sizing: border-box;
    }

    html,
    body {
        min-height: 100%;
        margin: 0;
        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        overflow-x: hidden;
        background:
            radial-gradient(circle at 0% 0%, rgba(37, 99, 235, .22), transparent 34%),
            radial-gradient(circle at 100% 0%, rgba(236, 72, 153, .20), transparent 32%),
            radial-gradient(circle at 100% 100%, rgba(124, 58, 237, .17), transparent 36%),
            linear-gradient(135deg, #edf5ff 0%, #f7f0ff 48%, #fff1f8 100%);
    }

    .login-page {
        min-height: 100dvh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px 18px 34px;
    }

    .login-wrapper {
        width: 100%;
        max-width: 940px;
    }

    .login-box {
        width: 100%;
        min-height: 560px;
        background: rgba(255, 255, 255, .84);
        border: 1px solid rgba(255, 255, 255, .9);
        border-radius: 28px;
        overflow: hidden;
        display: grid;
        grid-template-columns: 1fr .92fr;
        box-shadow: 0 28px 70px rgba(30, 41, 59, .22);
        backdrop-filter: blur(18px);
    }

    .login-left {
        position: relative;
        padding: 40px 46px;
        color: #fff;
        overflow: hidden;
        background:
            radial-gradient(circle at 86% 10%, rgba(255, 255, 255, .28), transparent 9%),
            radial-gradient(circle at 4% 94%, rgba(236, 72, 153, .80), transparent 30%),
            radial-gradient(circle at 100% 92%, rgba(6, 182, 212, .65), transparent 34%),
            linear-gradient(135deg, #2563eb 0%, #4f46e5 38%, #7c3aed 64%, #ec4899 100%);
    }

    .login-left::before {
        content: "";
        position: absolute;
        width: 650px;
        height: 650px;
        top: -260px;
        right: -250px;
        border-radius: 45%;
        background: rgba(255, 255, 255, .11);
        transform: rotate(28deg);
    }

    .login-left::after {
        content: "";
        position: absolute;
        width: 440px;
        height: 440px;
        right: -170px;
        bottom: -160px;
        border-radius: 50%;
        border: 2px solid rgba(255, 255, 255, .30);
        background: rgba(255, 255, 255, .08);
    }

    .brand-logo-box {
        position: relative;
        z-index: 2;
        background: #fff;
        border-radius: 16px;
        padding: 11px 16px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 24px;
        box-shadow: 0 18px 34px rgba(15, 23, 42, .18);
    }

    .brand-logo {
        width: 190px;
        max-width: 100%;
        height: auto;
        display: block;
    }

    .login-left-content {
        position: relative;
        z-index: 2;
        max-width: 520px;
    }

    .login-left h1 {
        font-size: clamp(28px, 3.2vw, 38px);
        font-weight: 900;
        line-height: 1.08;
        margin: 0 0 14px;
        letter-spacing: -.03em;
    }

    .title-line {
        width: 58px;
        height: 5px;
        border-radius: 99px;
        margin-bottom: 18px;
        background: linear-gradient(90deg, #fb7185, #a78bfa, #22d3ee);
    }

    .login-left p {
        max-width: 450px;
        font-size: 15px;
        line-height: 1.6;
        opacity: .96;
        margin: 0;
    }

    .feature-list {
        margin-top: 24px;
        display: grid;
        max-width: 500px;
    }

    .feature-item {
        display: grid;
        grid-template-columns: 46px 1fr;
        gap: 14px;
        align-items: center;
        padding: 11px 0;
        border-bottom: 1px solid rgba(255, 255, 255, .16);
    }

    .feature-item:last-child {
        border-bottom: 0;
    }

    .feature-icon {
        width: 46px;
        height: 46px;
        border-radius: 14px;
        display: grid;
        place-items: center;
        color: #fff;
        font-size: 16px;
        border: 1px solid rgba(255, 255, 255, .20);
        box-shadow: 0 14px 25px rgba(15, 23, 42, .14);
    }

    .feature-icon.blue {
        background: linear-gradient(135deg, rgba(96, 165, 250, .88), rgba(124, 58, 237, .72));
    }

    .feature-icon.purple {
        background: linear-gradient(135deg, rgba(139, 92, 246, .88), rgba(217, 70, 239, .72));
    }

    .feature-icon.pink {
        background: linear-gradient(135deg, rgba(236, 72, 153, .86), rgba(14, 165, 233, .58));
    }

    .feature-icon.orange {
        background: linear-gradient(135deg, rgba(249, 115, 22, .90), rgba(236, 72, 153, .78));
    }

    .feature-title {
        font-size: 14px;
        font-weight: 800;
        margin-bottom: 4px;
    }

    .feature-subtitle {
        font-size: 12.5px;
        line-height: 1.45;
        opacity: .88;
    }

    .login-right {
        position: relative;
        padding: 42px 44px;
        background:
            radial-gradient(circle at 100% 100%, rgba(37, 99, 235, .07), transparent 32%),
            linear-gradient(180deg, rgba(255, 255, 255, .98), rgba(255, 255, 255, .92));
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .login-form-area {
        position: relative;
        z-index: 2;
        width: 100%;
        max-width: 360px;
    }

    .user-badge {
        width: auto;
        height: auto;
        margin: 0 auto 24px;
        border-radius: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        background: transparent;
        border: 0;
        box-shadow: none;
        visibility: visible !important;
        opacity: 1 !important;
    }

    .profile-logo {
        width: 174px;
        height: auto;
        max-height: 48px;
        object-fit: contain;
        display: block;
        visibility: visible !important;
        opacity: 1 !important;
    }

    .login-title {
        text-align: center;
        font-size: 28px;
        font-weight: 900;
        color: #111c44;
        margin: 0 0 8px;
    }

    .login-subtitle {
        text-align: center;
        color: #63708a;
        font-size: 13.5px;
        margin: 0 0 26px;
    }

    .input-modern {
        position: relative;
        display: flex;
        align-items: center;
        height: 54px;
        border: 1px solid rgba(148, 163, 184, .42);
        background: rgba(255, 255, 255, .75);
        border-radius: 16px;
        overflow: hidden;
        transition: .22s ease;
        margin-bottom: 18px;
    }

    .input-modern:focus-within {
        border-color: rgba(79, 70, 229, .55);
        box-shadow: 0 0 0 4px rgba(79, 70, 229, .10);
        background: #fff;
    }

    .input-icon {
        width: 52px;
        height: 100%;
        display: grid;
        place-items: center;
        font-size: 17px;
        color: #4f46e5;
        border-right: 1px solid rgba(226, 232, 240, .85);
        background: rgba(248, 250, 252, .72);
    }

    .input-modern input {
        width: 100%;
        height: 100%;
        border: 0;
        outline: 0;
        padding: 0 18px;
        background: transparent;
        color: #111827;
        font-size: 15px;
        font-weight: 600;
    }

    .input-modern input::placeholder {
        color: #76839d;
        font-weight: 500;
    }

    .password-eye {
        width: 46px;
        height: 100%;
        border: 0;
        background: transparent;
        color: #64748b;
        display: grid;
        place-items: center;
        cursor: pointer;
    }

    .form-options {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
        margin: 12px 0 22px;
        color: #52607a;
        font-size: 13px;
    }

    .remember {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        user-select: none;
    }

    .remember input {
        width: 17px;
        height: 17px;
        accent-color: #4f46e5;
    }

    .forgot-link {
        color: #1d4ed8;
        text-decoration: none;
        font-weight: 800;
    }

    .btn-login {
        width: 100%;
        height: 56px;
        border: 0;
        border-radius: 16px;
        color: #fff;
        font-size: 16px;
        font-weight: 900;
        background: linear-gradient(100deg, #2563eb 0%, #7c3aed 48%, #ec4899 100%);
        box-shadow: 0 18px 32px rgba(79, 70, 229, .24), 0 10px 18px rgba(236, 72, 153, .14);
        transition: .22s ease;
    }

    .btn-login:hover {
        transform: translateY(-2px);
        color: #fff;
    }

    .btn-login i {
        margin-left: 10px;
    }

    .alert {
        border-radius: 16px;
        font-size: 14px;
        border: 0;
        margin-bottom: 20px;
    }

    .page-footer {
        text-align: center;
        color: #4d5872;
        font-size: 13px;
        margin-top: 18px;
    }

    @media (max-width: 991px) {

        html,
        body {
            width: 100%;
            min-height: 100%;
            margin: 0;
            overflow-x: hidden !important;
            overflow-y: auto !important;
            -webkit-overflow-scrolling: touch;
        }

        .login-page {
            width: 100%;
            min-height: 100vh;
            min-height: 100dvh;
            padding: 16px 12px;
            display: flex;
            align-items: center !important;
            justify-content: center !important;
            overflow: visible !important;
        }

        .login-wrapper {
            width: 100%;
            max-width: 430px;
            margin: 0 auto;
        }

        .login-box {
            width: 100%;
            display: block;
            min-height: auto;
            border-radius: 24px;
            overflow: hidden;
        }

        .login-left {
            display: none !important;
        }

        .login-right {
            width: 100%;
            min-height: auto;
            padding: 34px 18px 30px;
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-form-area {
            width: 100%;
            max-width: 100%;
        }

        .user-badge {
            width: 190px;
            height: 66px;
            margin: 0 auto 18px;
            border-radius: 20px;
            padding: 9px 16px;
            display: grid !important;
            place-items: center;
            visibility: visible !important;
            opacity: 1 !important;
        }

        .login-title {
            font-size: 26px;
            margin-bottom: 8px;
        }

        .login-subtitle {
            font-size: 13px;
            margin-bottom: 24px;
        }

        .input-modern {
            height: 56px;
            border-radius: 14px;
            margin-bottom: 16px;
        }

        .input-icon {
            width: 50px;
            font-size: 17px;
        }

        .input-modern input {
            font-size: 14px;
            padding: 0 12px;
        }

        .password-eye {
            width: 44px;
        }

        .form-options {
            flex-direction: row;
            align-items: center;
            margin: 12px 0 22px;
            font-size: 13px;
            gap: 10px;
        }

        .btn-login {
            height: 58px;
            font-size: 16px;
            border-radius: 14px;
        }

        .page-footer {
            font-size: 12px;
            margin-top: 16px;
            padding: 0 12px;
            text-align: center;
        }
    }

    @media (max-width: 991px) and (max-height: 680px) {
        .login-page {
            align-items: flex-start !important;
            padding-top: 16px;
            padding-bottom: 24px;
        }
    }

    @media (max-width: 360px) {
        .login-page {
            padding: 10px 8px 22px;
        }

        .login-right {
            padding: 26px 14px;
        }

        .login-title {
            font-size: 24px;
        }

        .form-options {
            flex-direction: column;
            align-items: flex-start;
        }
    }

    @media (max-width: 991px) {
        .user-badge {
            width: auto;
            height: auto;
            margin: 0 auto 22px;
            background: transparent;
            border: 0;
            box-shadow: none;
        }

        .profile-logo {
            width: 165px;
            height: auto;
            max-height: 46px;
            object-fit: contain;
        }
    }
    </style>
</head>

<body>
    <main class="login-page">
        <div class="login-wrapper">
            <div class="login-box">

                <section class="login-left">
                    <div class="brand-logo-box">
                        <img src="<?= e($brandLogoPath) ?>" alt="Subhiksha Cards Logo" class="brand-logo">
                    </div>

                    <div class="login-left-content">
                        <h1>Subhiksha Cards ERP</h1>
                        <div class="title-line"></div>
                        <p>A complete enterprise resource planning solution designed for the cards industry to
                            streamline operations and drive growth.</p>

                        <div class="feature-list">
                            <div class="feature-item">
                                <div class="feature-icon blue"><i class="fa-solid fa-chart-simple"></i></div>
                                <div>
                                    <div class="feature-title">Business Dashboard</div>
                                    <div class="feature-subtitle">Real-time insights and KPIs at a glance</div>
                                </div>
                            </div>
                            <div class="feature-item">
                                <div class="feature-icon purple"><i class="fa-solid fa-layer-group"></i></div>
                                <div>
                                    <div class="feature-title">Inventory Management</div>
                                    <div class="feature-subtitle">Track stock, materials and supplies efficiently</div>
                                </div>
                            </div>
                            <div class="feature-item">
                                <div class="feature-icon pink"><i class="fa-solid fa-user-group"></i></div>
                                <div>
                                    <div class="feature-title">CRM & Customer Management</div>
                                    <div class="feature-subtitle">Manage leads, customers and relationships</div>
                                </div>
                            </div>
                            <div class="feature-item">
                                <div class="feature-icon orange"><i class="fa-solid fa-file-lines"></i></div>
                                <div>
                                    <div class="feature-title">Orders & Production</div>
                                    <div class="feature-subtitle">Streamline orders, production and dispatch</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="login-right">
                    <div class="login-form-area">
                        <div class="user-badge">
                            <img src="<?= e($profileLogoPath) ?>" alt="Ecommer Logo" class="profile-logo">
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

                            <div class="input-modern">
                                <div class="input-icon"><i class="fa-regular fa-user"></i></div>
                                <input type="text" name="login" placeholder="Username / Email / Mobile"
                                    value="<?= e($_POST['login'] ?? '') ?>" required autofocus>
                            </div>

                            <div class="input-modern">
                                <div class="input-icon"><i class="fa-solid fa-lock"></i></div>
                                <input type="password" name="password" id="password" placeholder="Password" required>
                                <button type="button" class="password-eye" onclick="togglePassword()"
                                    aria-label="Show or hide password">
                                    <i class="fa-regular fa-eye" id="eyeIcon"></i>
                                </button>
                            </div>

                            <div class="form-options">
                                <label class="remember">
                                    <input type="checkbox" checked>
                                    <span>Remember me</span>
                                </label>
                            </div>

                            <button type="submit" class="btn-login">
                                Login <i class="fa-solid fa-arrow-right"></i>
                            </button>
                        </form>
                    </div>
                </section>

            </div>

            <div class="page-footer">© <?= date('Y') ?> Subhiksha Cards ERP. All rights reserved.</div>
        </div>
    </main>

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