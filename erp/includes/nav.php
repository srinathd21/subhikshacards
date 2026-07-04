<?php
/**
 * includes/nav.php
 * Subhiksha Cards ERP - Fixed topbar
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$displayName = trim((string)($_SESSION['name'] ?? $_SESSION['username'] ?? 'User')) ?: 'User';
$displayRole = trim((string)($_SESSION['role_name'] ?? $_SESSION['role'] ?? 'User')) ?: 'User';
$initial = strtoupper(substr($displayName, 0, 1));
?>
<header id="topbar">
    <div class="topbar-left">
        <button id="sidebarToggle" class="icon-btn topbar-action" type="button" aria-label="Toggle sidebar">
            <i data-lucide="menu"></i>
        </button>

        <div class="topbar-title-wrap">
            <h1 class="topbar-title">SUBHIKSHA CARDS</h1>
            <small class="topbar-subtitle">Invitation printing ERP &amp; CRM</small>
        </div>
    </div>

    <div class="topbar-actions">
        <button id="darkModeToggle" class="icon-btn topbar-action" type="button" aria-label="Toggle dark mode" title="Dark mode">
            <i data-lucide="moon"></i>
        </button>

        <div class="dropdown">
            <button class="user-dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="avatar"><?= e($initial) ?></span>
                <span class="user-chip-text">
                    <b><?= e($displayName) ?></b>
                    <small><?= e($displayRole) ?></small>
                </span>
                <i data-lucide="chevron-down"></i>
            </button>

            <div class="dropdown-menu dropdown-menu-end topbar-dropdown">
                <div class="px-3 py-2">
                    <strong><?= e($displayName) ?></strong>
                    <small class="d-block text-muted-custom"><?= e($displayRole) ?></small>
                </div>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item" href="profile.php"><i data-lucide="user-circle"></i> My Profile</a>
                <a class="dropdown-item text-danger" href="logout.php"><i data-lucide="log-out"></i> Logout</a>
            </div>
        </div>
    </div>
</header>
