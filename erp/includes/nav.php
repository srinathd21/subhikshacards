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
$currentNavUserId = (int)($_SESSION['user_id'] ?? 0);

$navNotifications = [];
$navUnreadNotificationCount = 0;

try {
    if (isset($conn) && $conn instanceof mysqli && $currentNavUserId > 0) {
        $res = $conn->query("SHOW TABLES LIKE 'notifications'");
        $hasNotificationsTable = $res && $res->num_rows > 0;
        if ($res) $res->free();

        if ($hasNotificationsTable) {
            $notificationReadId = max(0, (int)($_GET['notification_read'] ?? 0));
            if ($notificationReadId > 0) {
                $stmt = $conn->prepare("
                    UPDATE notifications
                    SET is_read = 1,
                        read_at = COALESCE(read_at, NOW())
                    WHERE id = ?
                      AND user_id = ?
                ");
                $stmt->bind_param('ii', $notificationReadId, $currentNavUserId);
                $stmt->execute();
                $stmt->close();
            }

            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0");
            $stmt->bind_param('i', $currentNavUserId);
            $stmt->execute();
            $countRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $navUnreadNotificationCount = (int)($countRow['total'] ?? 0);

            $stmt = $conn->prepare("
                SELECT id, title, message, related_module, related_id, is_read, created_at
                FROM notifications
                WHERE user_id = ?
                ORDER BY is_read ASC, id DESC
                LIMIT 8
            ");
            $stmt->bind_param('i', $currentNavUserId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $navNotifications[] = $row;
            $stmt->close();
        }
    }
} catch (Throwable $e) {
    $navNotifications = [];
    $navUnreadNotificationCount = 0;
}

function navNotificationHref(array $notification): string
{
    $module = strtolower(trim((string)($notification['related_module'] ?? '')));
    $relatedId = (int)($notification['related_id'] ?? 0);
    $notificationId = (int)($notification['id'] ?? 0);

    if ($module === 'job_cards' && $relatedId > 0) {
        return 'job_card_view.php?id=' . $relatedId . '&notification_read=' . $notificationId;
    }

    return 'dashboard.php?notification_read=' . $notificationId;
}
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
        <div class="dropdown">
            <button class="icon-btn topbar-action position-relative" type="button" data-bs-toggle="dropdown"
                aria-expanded="false" aria-label="Notifications" title="Notifications">
                <i data-lucide="bell"></i>
                <?php if ($navUnreadNotificationCount > 0): ?>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                    style="font-size:10px;min-width:18px">
                    <?= $navUnreadNotificationCount > 99 ? '99+' : number_format($navUnreadNotificationCount) ?>
                </span>
                <?php endif; ?>
            </button>

            <div class="dropdown-menu dropdown-menu-end topbar-dropdown p-0"
                style="width:min(390px,92vw);max-height:440px;overflow:auto">
                <div class="px-3 py-3 border-bottom d-flex justify-content-between align-items-center gap-2">
                    <div>
                        <strong>Notifications</strong>
                        <small class="d-block text-muted-custom"><?= number_format($navUnreadNotificationCount) ?>
                            unread</small>
                    </div>
                    <i data-lucide="bell-ring" style="width:18px;height:18px"></i>
                </div>

                <?php if (!$navNotifications): ?>
                <div class="px-3 py-4 text-center text-muted-custom">No notifications.</div>
                <?php else: ?>
                <?php foreach ($navNotifications as $notification): ?>
                <a class="dropdown-item px-3 py-3 border-bottom <?= empty($notification['is_read']) ? 'fw-bold' : '' ?>"
                    href="<?= e(navNotificationHref($notification)) ?>" style="white-space:normal">
                    <div class="d-flex gap-2 align-items-start">
                        <span
                            class="mt-1 <?= empty($notification['is_read']) ? 'text-primary' : 'text-muted-custom' ?>">●</span>
                        <span class="flex-grow-1">
                            <strong class="d-block"><?= e($notification['title'] ?? 'Notification') ?></strong>
                            <?php if (!empty($notification['message'])): ?>
                            <small class="d-block text-muted-custom mt-1"><?= e($notification['message']) ?></small>
                            <?php endif; ?>
                            <small class="d-block text-muted-custom mt-1">
                                <?= !empty($notification['created_at']) ? e(date('d-m-Y h:i A', strtotime((string)$notification['created_at']))) : '' ?>
                            </small>
                        </span>
                    </div>
                </a>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <button id="darkModeToggle" class="icon-btn topbar-action" type="button" aria-label="Toggle dark mode"
            title="Dark mode">
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