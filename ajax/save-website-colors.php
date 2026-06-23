<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');

try {
    require_permission($conn, 'can_edit', 'website-colors.php');

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        throw new RuntimeException('Invalid request.');
    }

    $colors = $data['colors'] ?? [];
    $layoutDensity = $data['layout_density'] ?? 'comfortable';

    if (!is_array($colors)) {
        $colors = [];
    }

    if (!in_array($layoutDensity, ['comfortable', 'compact'], true)) {
        $layoutDensity = 'comfortable';
    }

    $colors['layout_density'] = $layoutDensity;

    $allowed = [
        'sidebar_bg_1','sidebar_bg_2','sidebar_bg_3','sidebar_text','sidebar_active_bg_1','sidebar_active_bg_2',
        'sidebar_active_text','sidebar_hover_bg','sidebar_hover_text','sidebar_submenu_bg','body_bg','topbar_bg',
        'topbar_text','card_bg','card_header_bg','border_soft','text_main','text_muted','brand_1','brand_2',
        'brand_text','table_header_bg','table_header_text','table_row_hover','input_bg','input_border','input_text',
        'success_color','warning_color','danger_color','info_color','layout_density'
    ];

    foreach ($colors as $key => $value) {
        if (!in_array($key, $allowed, true)) {
            continue;
        }

        $value = trim((string)$value);

        if ($key !== 'layout_density' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
            continue;
        }

        $check = $conn->prepare("SELECT id FROM website_color_settings WHERE setting_key = ? LIMIT 1");
        $check->bind_param('s', $key);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();

        if ($existing) {
            $stmt = $conn->prepare("UPDATE website_color_settings SET setting_value = ?, is_active = 1, updated_at = NOW() WHERE setting_key = ?");
            $stmt->bind_param('ss', $value, $key);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare("INSERT INTO website_color_settings (setting_key, setting_value, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())");
            $stmt->bind_param('ss', $key, $value);
            $stmt->execute();
            $stmt->close();
        }
    }

    echo json_encode(['success' => true, 'message' => 'Colors saved successfully.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
