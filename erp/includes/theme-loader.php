<?php
/**
 * Global database-driven theme loader.
 * This file expects $conn and e() to already be available.
 */

$themeDefaults = [
    'sidebar_bg'             => '#2f3a45',
    'sidebar_text'           => '#ffffff',
    'sidebar_active_bg_1'    => '#ffbf00',
    'sidebar_active_bg_2'    => '#ffcd38',
    'sidebar_active_text'    => '#ffffff',
    'sidebar_hover_bg'       => '#43505d',
    'sidebar_hover_text'     => '#ffffff',
    'sidebar_submenu_bg'     => '#29343e',

    'topbar_bg'              => '#ffffff',
    'topbar_text'            => '#0f172a',
    'body_bg'                => '#f7f9fc',
    'card_bg'                => '#ffffff',
    'card_header_bg'         => '#ffffff',

    'text_main'              => '#0f172a',
    'text_muted'             => '#64748b',
    'border_soft'            => '#e2e8f0',

    'brand_1'                => '#ffc61a',
    'brand_2'                => '#ff9f0a',
    'brand_text'             => '#ffffff',

    'table_header_bg'        => '#f1f5f9',
    'table_header_text'      => '#475569',
    'table_row_hover'        => '#f8fafc',

    'input_bg'               => '#ffffff',
    'input_border'           => '#cbd5e1',
    'input_text'             => '#0f172a',

    'success_color'          => '#16a34a',
    'warning_color'          => '#f59e0b',
    'danger_color'           => '#dc2626',
    'info_color'             => '#2563eb',

    'settings_note_bg'       => '#eff6ff',
    'settings_note_border'   => '#bfdbfe',
    'settings_preview_bg'    => '#f8fafc',

    'layout_density'         => 'comfortable',
];

$theme = $themeDefaults;

try {
    $result = $conn->query(
        "SELECT setting_key, setting_value
         FROM website_color_settings
         WHERE is_active = 1"
    );

    while ($row = $result->fetch_assoc()) {
        $key = (string)$row['setting_key'];
        if (array_key_exists($key, $theme)) {
            $theme[$key] = (string)$row['setting_value'];
        }
    }
} catch (Throwable $e) {
    // Defaults keep the application usable if the settings table is unavailable.
}

function theme_css_value(array $theme, string $key, string $fallback = ''): string
{
    $value = trim((string)($theme[$key] ?? $fallback));

    if ($key === 'layout_density') {
        return in_array($value, ['comfortable', 'compact'], true)
            ? $value
            : 'comfortable';
    }

    // Theme colour inputs are intentionally limited to six-digit hex values.
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
        $value = $fallback;
    }

    return $value;
}
?>
<style id="databaseThemeVariables">
:root {
    --sidebar-bg: <?= e(theme_css_value($theme, 'sidebar_bg', $themeDefaults['sidebar_bg'])) ?>;
    --sidebar-text: <?= e(theme_css_value($theme, 'sidebar_text', $themeDefaults['sidebar_text'])) ?>;
    --sidebar-active-bg-1: <?= e(theme_css_value($theme, 'sidebar_active_bg_1', $themeDefaults['sidebar_active_bg_1'])) ?>;
    --sidebar-active-bg-2: <?= e(theme_css_value($theme, 'sidebar_active_bg_2', $themeDefaults['sidebar_active_bg_2'])) ?>;
    --sidebar-active-text: <?= e(theme_css_value($theme, 'sidebar_active_text', $themeDefaults['sidebar_active_text'])) ?>;
    --sidebar-hover-bg: <?= e(theme_css_value($theme, 'sidebar_hover_bg', $themeDefaults['sidebar_hover_bg'])) ?>;
    --sidebar-hover-text: <?= e(theme_css_value($theme, 'sidebar_hover_text', $themeDefaults['sidebar_hover_text'])) ?>;
    --sidebar-submenu-bg: <?= e(theme_css_value($theme, 'sidebar_submenu_bg', $themeDefaults['sidebar_submenu_bg'])) ?>;

    --topbar-bg: <?= e(theme_css_value($theme, 'topbar_bg', $themeDefaults['topbar_bg'])) ?>;
    --topbar-text: <?= e(theme_css_value($theme, 'topbar_text', $themeDefaults['topbar_text'])) ?>;
    --body-bg: <?= e(theme_css_value($theme, 'body_bg', $themeDefaults['body_bg'])) ?>;
    --card-bg: <?= e(theme_css_value($theme, 'card_bg', $themeDefaults['card_bg'])) ?>;
    --card-header-bg: <?= e(theme_css_value($theme, 'card_header_bg', $themeDefaults['card_header_bg'])) ?>;

    --text-main: <?= e(theme_css_value($theme, 'text_main', $themeDefaults['text_main'])) ?>;
    --text-muted: <?= e(theme_css_value($theme, 'text_muted', $themeDefaults['text_muted'])) ?>;
    --border-soft: <?= e(theme_css_value($theme, 'border_soft', $themeDefaults['border_soft'])) ?>;

    --brand-1: <?= e(theme_css_value($theme, 'brand_1', $themeDefaults['brand_1'])) ?>;
    --brand-2: <?= e(theme_css_value($theme, 'brand_2', $themeDefaults['brand_2'])) ?>;
    --brand-text: <?= e(theme_css_value($theme, 'brand_text', $themeDefaults['brand_text'])) ?>;

    --table-header-bg: <?= e(theme_css_value($theme, 'table_header_bg', $themeDefaults['table_header_bg'])) ?>;
    --table-header-text: <?= e(theme_css_value($theme, 'table_header_text', $themeDefaults['table_header_text'])) ?>;
    --table-row-hover: <?= e(theme_css_value($theme, 'table_row_hover', $themeDefaults['table_row_hover'])) ?>;

    --input-bg: <?= e(theme_css_value($theme, 'input_bg', $themeDefaults['input_bg'])) ?>;
    --input-border: <?= e(theme_css_value($theme, 'input_border', $themeDefaults['input_border'])) ?>;
    --input-text: <?= e(theme_css_value($theme, 'input_text', $themeDefaults['input_text'])) ?>;

    --success-color: <?= e(theme_css_value($theme, 'success_color', $themeDefaults['success_color'])) ?>;
    --warning-color: <?= e(theme_css_value($theme, 'warning_color', $themeDefaults['warning_color'])) ?>;
    --danger-color: <?= e(theme_css_value($theme, 'danger_color', $themeDefaults['danger_color'])) ?>;
    --info-color: <?= e(theme_css_value($theme, 'info_color', $themeDefaults['info_color'])) ?>;

    --settings-note-bg: <?= e(theme_css_value($theme, 'settings_note_bg', $themeDefaults['settings_note_bg'])) ?>;
    --settings-note-border: <?= e(theme_css_value($theme, 'settings_note_border', $themeDefaults['settings_note_border'])) ?>;
    --settings-preview-bg: <?= e(theme_css_value($theme, 'settings_preview_bg', $themeDefaults['settings_preview_bg'])) ?>;
}
</style>
