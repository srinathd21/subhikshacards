<?php
/**
 * includes/nav.php
 * Subhiksha Cards ERP - Fixed topbar
 */

$displayName = trim((string)($_SESSION['name'] ?? 'Admin')) ?: 'Admin';
$displayRole = trim((string)($_SESSION['role_name'] ?? $_SESSION['role'] ?? 'Admin')) ?: 'Admin';
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
        <button id="settingsToggle" class="icon-btn topbar-action" type="button" aria-label="Appearance settings" title="Appearance settings">
            <i data-lucide="settings"></i>
        </button>

        <button id="darkModeToggle" class="icon-btn topbar-action" type="button" aria-label="Toggle dark mode" title="Dark mode">
            <i data-lucide="moon"></i>
        </button>

        <div class="dropdown">
            <button class="icon-btn topbar-action position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications">
                <i data-lucide="bell"></i>
                <span class="notification-dot"></span>
            </button>

            <div class="dropdown-menu dropdown-menu-end topbar-dropdown notification-menu">
                <div class="dropdown-header-custom">
                    <strong>Notifications</strong>
                    <span class="badge rounded-pill text-bg-danger">0</span>
                </div>

                <a class="dropdown-item notification-item" href="customer-approvals.php">
                    <span class="notification-icon"><i data-lucide="badge-help"></i></span>
                    <span>
                        <b>Design approval pending</b>
                        <small>Review customer approval requests.</small>
                    </span>
                </a>

                <a class="dropdown-item notification-item" href="job-cards.php">
                    <span class="notification-icon"><i data-lucide="briefcase"></i></span>
                    <span>
                        <b>Production tracking</b>
                        <small>Check current job cards.</small>
                    </span>
                </a>
            </div>
        </div>

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
                <a class="dropdown-item" href="profile.php"><i data-lucide="user"></i> Profile</a>
                <a class="dropdown-item" href="website-colors.php"><i data-lucide="palette"></i> Theme Settings</a>
                <a class="dropdown-item text-danger" href="logout.php"><i data-lucide="log-out"></i> Logout</a>
            </div>
        </div>
    </div>
</header>
