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
    <link
        href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Montserrat:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <style>
    :root {
        --wine: #6f1832;
        --wine-dark: #3e0d1d;
        --rose: #b86d7e;
        --blush: #f7e8e5;
        --cream: #fffaf2;
        --gold: #b68b42;
        --gold-light: #e5c98d;
        --ink: #3d2630;
        --muted: #7f6870;
    }

    * {
        box-sizing: border-box;
    }

    html,
    body {
        min-height: 100%;
        margin: 0;
    }

    body {
        font-family: "Montserrat", sans-serif;
        color: var(--ink);
        overflow-x: hidden;
        background:
            radial-gradient(circle at 12% 12%, rgba(255, 255, 255, .75), transparent 25%),
            radial-gradient(circle at 88% 82%, rgba(255, 255, 255, .55), transparent 28%),
            linear-gradient(135deg, #f5dedf 0%, #f8ece6 46%, #eed5d8 100%);
    }

    body::before {
        content: "";
        position: fixed;
        inset: 0;
        pointer-events: none;
        opacity: .45;
        background-image:
            linear-gradient(rgba(111, 24, 50, .035) 1px, transparent 1px),
            linear-gradient(90deg, rgba(111, 24, 50, .035) 1px, transparent 1px);
        background-size: 28px 28px;
    }

    .petal {
        position: fixed;
        top: -60px;
        width: 14px;
        height: 22px;
        border-radius: 80% 15% 80% 15%;
        background: linear-gradient(145deg, #fff, #e6aab5);
        opacity: .72;
        z-index: 2;
        pointer-events: none;
        animation: petalFall linear infinite;
    }

    @keyframes petalFall {
        0% {
            transform: translate3d(0, -8vh, 0) rotate(0deg);
        }

        50% {
            transform: translate3d(42px, 52vh, 0) rotate(180deg);
        }

        100% {
            transform: translate3d(-28px, 112vh, 0) rotate(360deg);
        }
    }

    .page-shell {
        min-height: 100dvh;
        width: 100%;
        padding: 0;
        position: relative;
        z-index: 3;
    }

    .invitation-stage {
        width: 100%;
        min-height: 100dvh;
        perspective: 1800px;
        position: relative;
    }

    .invitation-card {
        position: relative;
        min-height: 100dvh;
        border-radius: 0;
        overflow: hidden;
        background: var(--cream);
        border: 1px solid rgba(182, 139, 66, .42);
        box-shadow: 0 38px 90px rgba(72, 25, 39, .30);
        transform-style: preserve-3d;
    }

    .invitation-card::before,
    .invitation-card::after {
        content: "";
        position: absolute;
        width: 340px;
        height: 340px;
        border: 1px solid rgba(182, 139, 66, .2);
        border-radius: 50%;
        z-index: 0;
    }

    .invitation-card::before {
        left: -185px;
        top: -175px;
    }

    .invitation-card::after {
        right: -170px;
        bottom: -195px;
    }

    .card-inside {
        min-height: 100dvh;
        display: grid;
        grid-template-columns: 1.12fr .88fr;
        position: relative;
        z-index: 1;
        opacity: 0;
        transform: scale(.97);
        animation: revealInside .8s ease 1.75s forwards;
    }

    @keyframes revealInside {
        to {
            opacity: 1;
            transform: scale(1);
        }
    }

    .wedding-panel {
        position: relative;
        overflow: hidden;
        color: #fff;
        padding: 48px 52px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        background:
            linear-gradient(rgba(63, 8, 28, .48), rgba(63, 8, 28, .64)),
            radial-gradient(circle at 24% 20%, rgba(255, 255, 255, .18), transparent 22%),
            linear-gradient(135deg, #8b2747 0%, #5e1530 52%, #35101e 100%);
    }

    .wedding-panel::before {
        content: "";
        position: absolute;
        inset: 18px;
        border: 1px solid rgba(229, 201, 141, .6);
        border-radius: 24px;
        pointer-events: none;
    }

    .floral-corner {
        position: absolute;
        width: 230px;
        height: 230px;
        opacity: .34;
        background:
            radial-gradient(circle at 30% 28%, #fff 0 7%, transparent 8%),
            radial-gradient(circle at 52% 18%, #f7ccd4 0 6%, transparent 7%),
            radial-gradient(circle at 70% 38%, #fff 0 6%, transparent 7%),
            radial-gradient(ellipse at 44% 54%, #c9a767 0 8%, transparent 9%),
            radial-gradient(ellipse at 65% 66%, #c9a767 0 7%, transparent 8%);
        filter: blur(.2px);
    }

    .floral-top {
        top: -26px;
        left: -28px;
        transform: rotate(-14deg);
    }

    .floral-bottom {
        right: -35px;
        bottom: -42px;
        transform: rotate(162deg);
    }

    .brand-logo-wrap {
        position: relative;
        z-index: 2;
        display: inline-flex;
        align-items: center;
        width: fit-content;
        padding: 10px 16px;
        border-radius: 14px;
        background: rgba(255, 255, 255, .96);
        box-shadow: 0 14px 28px rgba(0, 0, 0, .18);
    }

    .brand-logo-wrap img {
        width: 190px;
        max-height: 56px;
        object-fit: contain;
    }

    .invite-copy {
        position: relative;
        z-index: 2;
        max-width: 510px;
        margin: 28px 0;
    }

    .invite-kicker {
        font-size: 12px;
        letter-spacing: .28em;
        text-transform: uppercase;
        color: var(--gold-light);
        font-weight: 700;
        margin-bottom: 14px;
    }

    .invite-copy h1 {
        font-family: "Cormorant Garamond", serif;
        font-size: clamp(48px, 6vw, 78px);
        line-height: .94;
        margin: 0;
        font-weight: 700;
        text-shadow: 0 8px 24px rgba(0, 0, 0, .18);
    }

    .ornament {
        display: flex;
        align-items: center;
        gap: 12px;
        margin: 24px 0;
        color: var(--gold-light);
    }

    .ornament::before,
    .ornament::after {
        content: "";
        width: 78px;
        height: 1px;
        background: linear-gradient(90deg, transparent, var(--gold-light));
    }

    .ornament::after {
        background: linear-gradient(90deg, var(--gold-light), transparent);
    }

    .invite-copy p {
        max-width: 470px;
        font-size: 15px;
        line-height: 1.8;
        color: rgba(255, 255, 255, .88);
        margin: 0;
    }

    .features {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
        position: relative;
        z-index: 2;
    }

    .feature {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 11px 13px;
        border-radius: 14px;
        background: rgba(255, 255, 255, .08);
        border: 1px solid rgba(255, 255, 255, .12);
        backdrop-filter: blur(7px);
        font-size: 12px;
        font-weight: 600;
    }

    .feature i {
        color: var(--gold-light);
    }

    .login-panel {
        position: relative;
        padding: 54px 58px;
        display: flex;
        align-items: center;
        background:
            radial-gradient(circle at 95% 8%, rgba(182, 139, 66, .11), transparent 24%),
            radial-gradient(circle at 4% 94%, rgba(184, 109, 126, .10), transparent 25%),
            var(--cream);
    }

    .login-panel::before {
        content: "";
        position: absolute;
        inset: 20px;
        border: 1px solid rgba(182, 139, 66, .32);
        border-radius: 22px;
        pointer-events: none;
    }

    .login-area {
        width: 100%;
        max-width: 410px;
        margin: auto;
        position: relative;
        z-index: 2;
    }

    .ecommer-logo {
        display: flex;
        justify-content: center;
        margin-bottom: 19px;
    }

    .ecommer-logo img {
        width: 176px;
        max-height: 52px;
        object-fit: contain;
    }

    .login-heading {
        text-align: center;
        font-family: "Cormorant Garamond", serif;
        font-size: 38px;
        font-weight: 700;
        color: var(--wine-dark);
        margin: 0;
    }

    .login-subtitle {
        text-align: center;
        color: var(--muted);
        font-size: 13px;
        line-height: 1.6;
        margin: 8px 0 24px;
    }

    .mini-ornament {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 10px;
        color: var(--gold);
        margin-bottom: 24px;
    }

    .mini-ornament::before,
    .mini-ornament::after {
        content: "";
        width: 55px;
        height: 1px;
        background: var(--gold-light);
    }

    .form-control-wrap {
        position: relative;
        display: flex;
        align-items: center;
        min-height: 58px;
        border: 1px solid #ddcbc3;
        border-radius: 15px;
        background: rgba(255, 255, 255, .8);
        overflow: hidden;
        margin-bottom: 16px;
        transition: .22s ease;
    }

    .form-control-wrap:focus-within {
        border-color: var(--gold);
        box-shadow: 0 0 0 4px rgba(182, 139, 66, .11);
        background: #fff;
    }

    .field-icon {
        width: 54px;
        height: 58px;
        display: grid;
        place-items: center;
        color: var(--wine);
        border-right: 1px solid #eaded9;
        background: #fffaf7;
    }

    .form-control-wrap input[type="text"],
    .form-control-wrap input[type="password"] {
        width: 100%;
        height: 58px;
        border: 0;
        outline: 0;
        padding: 0 16px;
        background: transparent;
        color: var(--ink);
        font-size: 14px;
        font-weight: 600;
    }

    .form-control-wrap input::placeholder {
        color: #a38c94;
        font-weight: 500;
    }

    .eye-btn {
        width: 48px;
        height: 58px;
        border: 0;
        background: transparent;
        color: #8a727a;
    }

    .form-meta {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin: 5px 0 22px;
        color: #745d66;
        font-size: 12.5px;
    }

    .remember {
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .remember input {
        width: 16px;
        height: 16px;
        accent-color: var(--wine);
    }

    .login-btn {
        width: 100%;
        min-height: 58px;
        border: 0;
        border-radius: 15px;
        color: #fff;
        font-weight: 700;
        letter-spacing: .02em;
        background: linear-gradient(135deg, #8a2545, #5b142f);
        box-shadow: 0 14px 28px rgba(91, 20, 47, .24);
        transition: .22s ease;
    }

    .login-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 18px 34px rgba(91, 20, 47, .29);
    }

    .login-btn:disabled {
        opacity: .8;
        transform: none;
    }

    .secure-note {
        margin-top: 16px;
        text-align: center;
        font-size: 11.5px;
        color: #8a747c;
    }

    .secure-note i {
        color: var(--gold);
        margin-right: 5px;
    }

    .alert {
        border: 1px solid #edc4c9;
        background: #fff0f1;
        color: #8a2134;
        border-radius: 14px;
        font-size: 13px;
        margin-bottom: 17px;
    }

    .page-footer {
        position: absolute;
        left: 0;
        right: 0;
        bottom: 9px;
        z-index: 8;
        text-align: center;
        color: rgba(86, 49, 61, .76);
        font-size: 11px;
        pointer-events: none;
    }

    .card-cover {
        position: absolute;
        inset: 0;
        z-index: 20;
        transform-origin: left center;
        transform-style: preserve-3d;
        border-radius: 0;
        overflow: hidden;
        background:
            linear-gradient(rgba(61, 9, 28, .18), rgba(61, 9, 28, .35)),
            radial-gradient(circle at 22% 22%, rgba(255, 255, 255, .15), transparent 18%),
            linear-gradient(135deg, #8d2949 0%, #5b142f 52%, #35101e 100%);
        box-shadow: 15px 0 34px rgba(52, 14, 27, .28);
        animation: openInvitation 1.65s cubic-bezier(.68, -.04, .29, 1.02) .45s forwards;
        backface-visibility: hidden;
    }

    @keyframes openInvitation {
        0% {
            transform: rotateY(0deg);
        }

        65% {
            transform: rotateY(-112deg);
        }

        100% {
            transform: rotateY(-178deg);
            visibility: hidden;
        }
    }

    .cover-inner {
        position: absolute;
        inset: 22px;
        border: 1px solid rgba(229, 201, 141, .7);
        border-radius: 24px;
        display: grid;
        place-items: center;
        text-align: center;
        color: #fff;
        padding: 30px;
    }

    .cover-logo {
        width: 210px;
        padding: 12px 16px;
        border-radius: 15px;
        background: #fff;
        margin: 0 auto 30px;
    }

    .cover-logo img {
        width: 100%;
        max-height: 62px;
        object-fit: contain;
    }

    .cover-title {
        font-family: "Cormorant Garamond", serif;
        font-size: clamp(42px, 6vw, 72px);
        line-height: 1;
        margin: 0;
    }

    .cover-caption {
        margin-top: 16px;
        color: var(--gold-light);
        text-transform: uppercase;
        letter-spacing: .28em;
        font-size: 11px;
        font-weight: 700;
    }

    .wax-seal {
        width: 76px;
        height: 76px;
        border-radius: 50%;
        margin: 28px auto 0;
        display: grid;
        place-items: center;
        font-family: "Cormorant Garamond", serif;
        font-size: 27px;
        font-weight: 700;
        color: #ffe9bd;
        background: radial-gradient(circle at 35% 30%, #a83a54, #64162f 68%);
        border: 5px double rgba(255, 225, 171, .62);
        box-shadow: 0 12px 24px rgba(0, 0, 0, .28);
    }

    @media (max-width: 900px) {

        html,
        body {
            width: 100%;
            min-height: 100%;
            overflow-x: hidden;
            overflow-y: auto;
            background: var(--cream);
        }

        body::before {
            opacity: .28;
        }

        .page-shell,
        .invitation-stage,
        .invitation-card,
        .card-inside {
            width: 100%;
            min-height: 100dvh;
        }

        /* Mobile shows only the credential-entry section. */
        .wedding-panel,
        .card-cover {
            display: none !important;
        }

        .card-inside {
            display: block;
            opacity: 1;
            transform: none;
            animation: none;
        }

        .invitation-card {
            border: 0;
            border-radius: 0;
            box-shadow: none;
            overflow: visible;
        }

        .login-panel {
            width: 100%;
            min-height: 100dvh;
            padding: 40px 22px 64px;
            display: flex;
            align-items: center;
            justify-content: center;
            background:
                radial-gradient(circle at 92% 8%, rgba(182, 139, 66, .15), transparent 25%),
                radial-gradient(circle at 6% 92%, rgba(184, 109, 126, .13), transparent 27%),
                linear-gradient(145deg, #fffaf2 0%, #fff7f1 52%, #f9ebe8 100%);
        }

        .login-panel::before {
            inset: 12px;
            border-radius: 20px;
        }

        .login-area {
            width: 100%;
            max-width: 430px;
            margin: auto;
        }

        .ecommer-logo {
            margin-bottom: 18px;
        }

        .ecommer-logo img {
            width: 165px;
            max-height: 48px;
        }

        .login-heading {
            font-size: 36px;
        }

        .login-subtitle {
            margin-bottom: 17px;
        }

        .mini-ornament {
            margin-bottom: 20px;
        }

        .page-footer {
            position: absolute;
            bottom: 12px;
            padding: 0 18px;
            background: transparent;
        }
    }

    @media (max-width: 560px) {
        .login-panel {
            min-height: 100dvh;
            padding: 34px 16px 58px;
        }

        .login-panel::before {
            inset: 9px;
            border-radius: 17px;
        }

        .login-heading {
            font-size: 33px;
        }

        .login-subtitle {
            font-size: 12.5px;
        }

        .form-control-wrap,
        .field-icon,
        .eye-btn,
        .form-control-wrap input[type="text"],
        .form-control-wrap input[type="password"] {
            min-height: 54px;
            height: 54px;
        }

        .login-btn {
            min-height: 54px;
        }

        .petal {
            opacity: .42;
        }
    }

    @media (max-width: 380px) {
        .login-panel {
            padding: 28px 13px 54px;
        }

        .login-heading {
            font-size: 30px;
        }

        .form-meta {
            font-size: 11px;
        }

        .ecommer-logo img {
            width: 150px;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .card-cover {
            animation: none;
            display: none;
        }

        .card-inside {
            animation: none;
            opacity: 1;
            transform: none;
        }

        .petal {
            display: none;
        }
    }
    </style>
</head>

<body>
    <span class="petal" style="left:7%;animation-duration:10s;animation-delay:-2s"></span>
    <span class="petal" style="left:18%;animation-duration:13s;animation-delay:-8s"></span>
    <span class="petal" style="left:33%;animation-duration:11s;animation-delay:-5s"></span>
    <span class="petal" style="left:54%;animation-duration:14s;animation-delay:-7s"></span>
    <span class="petal" style="left:71%;animation-duration:12s;animation-delay:-4s"></span>
    <span class="petal" style="left:88%;animation-duration:15s;animation-delay:-9s"></span>

    <main class="page-shell">
        <div class="invitation-stage">
            <div class="invitation-card">
                <div class="card-cover" aria-hidden="true">
                    <div class="cover-inner">
                        <div>
                            <div class="cover-logo">
                                <img src="<?= e($brandLogoPath) ?>" alt="Subhiksha Cards Logo">
                            </div>
                            <div class="cover-caption">You are cordially invited</div>
                            <h1 class="cover-title">Welcome to<br>Subhiksha Cards</h1>
                            <div class="wax-seal">SC</div>
                        </div>
                    </div>
                </div>

                <div class="card-inside">
                    <section class="wedding-panel">
                        <div class="floral-corner floral-top"></div>
                        <div class="floral-corner floral-bottom"></div>

                        <div class="brand-logo-wrap">
                            <img src="<?= e($brandLogoPath) ?>" alt="Subhiksha Cards Logo">
                        </div>

                        <div class="invite-copy">
                            <div class="invite-kicker">A celebration of creativity & business</div>
                            <h1>Welcome to<br>Subhiksha Cards ERP</h1>
                            <div class="ornament"><i class="fa-solid fa-diamond"></i></div>
                            <p>
                                Step into a beautifully organised workspace designed to manage customers,
                                inventory, orders, production and business performance with elegance and ease.
                            </p>
                        </div>

                        <div class="features">
                            <div class="feature"><i class="fa-solid fa-chart-line"></i> Business Dashboard</div>
                            <div class="feature"><i class="fa-solid fa-boxes-stacked"></i> Inventory Control</div>
                            <div class="feature"><i class="fa-solid fa-users"></i> Customer Management</div>
                            <div class="feature"><i class="fa-solid fa-layer-group"></i> Orders & Production</div>
                        </div>
                    </section>

                    <section class="login-panel">
                        <div class="login-area">
                            <div class="ecommer-logo">
                                <img src="<?= e($profileLogoPath) ?>" alt="Ecommer Logo">
                            </div>

                            <h2 class="login-heading">Welcome Back</h2>
                            <p class="login-subtitle">Enter your credentials to open your business workspace.</p>
                            <div class="mini-ornament"><i class="fa-solid fa-gem"></i></div>

                            <?php if ($error !== ''): ?>
                            <div class="alert alert-danger">
                                <i class="fa-solid fa-triangle-exclamation me-1"></i>
                                <?= e($error) ?>
                            </div>
                            <?php endif; ?>

                            <form method="post" autocomplete="off" id="loginForm">
                                <input type="hidden" name="csrf_token" value="<?= e($token) ?>">

                                <div class="form-control-wrap">
                                    <div class="field-icon"><i class="fa-regular fa-user"></i></div>
                                    <input type="text" name="login" id="loginInput"
                                        placeholder="Username / Email / Mobile" value="<?= e($_POST['login'] ?? '') ?>"
                                        required>
                                </div>

                                <div class="form-control-wrap">
                                    <div class="field-icon"><i class="fa-solid fa-lock"></i></div>
                                    <input type="password" name="password" id="password" placeholder="Password"
                                        required>
                                    <button type="button" class="eye-btn" onclick="togglePassword()"
                                        aria-label="Show or hide password">
                                        <i class="fa-regular fa-eye" id="eyeIcon"></i>
                                    </button>
                                </div>

                                <div class="form-meta">
                                    <label class="remember">
                                        <input type="checkbox" name="remember" value="1" checked>
                                        <span>Remember me</span>
                                    </label>
                                    <span><i class="fa-solid fa-shield-halved me-1"></i>Secure login</span>
                                </div>

                                <button type="submit" class="login-btn" id="loginButton">
                                    <span id="loginButtonText">Login</span>
                                    <i class="fa-solid fa-arrow-right ms-2" id="loginButtonIcon"></i>
                                </button>
                            </form>

                            <div class="secure-note">
                                <i class="fa-solid fa-lock"></i>Your credentials are protected and encrypted.
                            </div>
                        </div>
                    </section>
                </div>
            </div>

            <div class="page-footer">© <?= date('Y') ?> Subhiksha Cards ERP. All rights reserved.</div>
        </div>
    </main>

    <script>
    function togglePassword() {
        const password = document.getElementById('password');
        const icon = document.getElementById('eyeIcon');
        const isHidden = password.type === 'password';
        password.type = isHidden ? 'text' : 'password';
        icon.classList.toggle('fa-eye', !isHidden);
        icon.classList.toggle('fa-eye-slash', isHidden);
    }

    window.addEventListener('load', function() {
        const login = document.getElementById('loginInput');
        const cover = document.querySelector('.card-cover');

        if (cover) {
            cover.addEventListener('animationend', function() {
                cover.style.display = 'none';
            }, {
                once: true
            });
        }

        if (login && window.innerWidth > 900) {
            setTimeout(() => login.focus(), 2350);
        }
    });

    document.getElementById('loginForm').addEventListener('submit', function() {
        const button = document.getElementById('loginButton');
        const text = document.getElementById('loginButtonText');
        const icon = document.getElementById('loginButtonIcon');
        button.disabled = true;
        text.textContent = 'Opening...';
        icon.className = 'fa-solid fa-circle-notch fa-spin ms-2';
    });
    </script>
</body>

</html>