<?php
/**
 * website-colors.php
 * Subhiksha Cards ERP - Fixed aligned Website Colors page with working live preview/save/reset.
 */

require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'website-colors.php');

$groups = [
    'layout'  => 'Layout Colors',
    'sidebar' => 'Sidebar Colors',
    'brand'   => 'Brand Colors',
    'tables'  => 'Table Colors',
    'forms'   => 'Form Colors',
    'status'  => 'Status Colors',
];

$controls = [
    'layout' => [
        ['body_bg', 'Body Background', '--body-bg'],
        ['topbar_bg', 'Topbar Background', '--topbar-bg'],
        ['topbar_text', 'Topbar Text', '--topbar-text'],
        ['card_bg', 'Card Background', '--card-bg'],
        ['card_header_bg', 'Card Header Background', '--card-header-bg'],
        ['border_soft', 'Border Color', '--border-soft'],
        ['text_main', 'Main Text Color', '--text-main'],
        ['text_muted', 'Muted Text Color', '--text-muted'],
    ],
    'sidebar' => [
        ['sidebar_bg_1', 'Sidebar Gradient Start', '--sidebar-bg-1'],
        ['sidebar_bg_2', 'Sidebar Gradient Middle', '--sidebar-bg-2'],
        ['sidebar_bg_3', 'Sidebar Gradient End', '--sidebar-bg-3'],
        ['sidebar_text', 'Sidebar Text', '--sidebar-text'],
        ['sidebar_active_bg_1', 'Active Background Start', '--sidebar-active-bg-1'],
        ['sidebar_active_bg_2', 'Active Background End', '--sidebar-active-bg-2'],
        ['sidebar_active_text', 'Active Text', '--sidebar-active-text'],
        ['sidebar_hover_bg', 'Hover Background', '--sidebar-hover-bg'],
        ['sidebar_hover_text', 'Hover Text', '--sidebar-hover-text'],
        ['sidebar_submenu_bg', 'Submenu Background', '--sidebar-submenu-bg'],
    ],
    'brand' => [
        ['brand_1', 'Brand Primary', '--brand-1'],
        ['brand_2', 'Brand Secondary', '--brand-2'],
        ['brand_text', 'Brand Button Text', '--brand-text'],
    ],
    'tables' => [
        ['table_header_bg', 'Table Header Background', '--table-header-bg'],
        ['table_header_text', 'Table Header Text', '--table-header-text'],
        ['table_row_hover', 'Table Row Hover', '--table-row-hover'],
    ],
    'forms' => [
        ['input_bg', 'Input Background', '--input-bg'],
        ['input_border', 'Input Border', '--input-border'],
        ['input_text', 'Input Text', '--input-text'],
    ],
    'status' => [
        ['success_color', 'Success Color', '--success-color'],
        ['warning_color', 'Warning Color', '--warning-color'],
        ['danger_color', 'Danger Color', '--danger-color'],
        ['info_color', 'Information Color', '--info-color'],
    ],
];

function wc_theme_value(array $theme, string $key): string
{
    $fallbacks = [
        'sidebar_bg_1'          => '#10192E',
        'sidebar_bg_2'          => '#1E3A5F',
        'sidebar_bg_3'          => '#315C8A',
        'sidebar_text'          => '#FFFFFF',
        'sidebar_active_bg_1'   => '#F59E0B',
        'sidebar_active_bg_2'   => '#FFB84D',
        'sidebar_active_text'   => '#FFFFFF',
        'sidebar_hover_bg'      => '#1E3A5F',
        'sidebar_hover_text'    => '#FFFFFF',
        'sidebar_submenu_bg'    => '#172554',
        'body_bg'               => '#F7F9FC',
        'topbar_bg'             => '#FFFFFF',
        'topbar_text'           => '#0F172A',
        'card_bg'               => '#FFFFFF',
        'card_header_bg'        => '#FFFFFF',
        'border_soft'           => '#E2E8F0',
        'text_main'             => '#0F172A',
        'text_muted'            => '#64748B',
        'brand_1'               => '#0F766E',
        'brand_2'               => '#0EA5E9',
        'brand_text'            => '#FFFFFF',
        'table_header_bg'       => '#F1F5F9',
        'table_header_text'     => '#334155',
        'table_row_hover'       => '#F8FAFC',
        'input_bg'              => '#FFFFFF',
        'input_border'          => '#CBD5E1',
        'input_text'            => '#0F172A',
        'success_color'         => '#16A34A',
        'warning_color'         => '#F59E0B',
        'danger_color'          => '#DC2626',
        'info_color'            => '#2563EB',
    ];

    if ($key === 'sidebar_bg_1') {
        $value = (string)($theme['sidebar_bg_1'] ?? $theme['sidebar_bg'] ?? $fallbacks[$key]);
    } else {
        $value = (string)($theme[$key] ?? $fallbacks[$key] ?? '#FFFFFF');
    }

    $value = strtoupper(trim($value));
    return preg_match('/^#[0-9A-F]{6}$/', $value) ? $value : ($fallbacks[$key] ?? '#FFFFFF');
}

$flatControls = [];
foreach ($controls as $items) {
    foreach ($items as $item) {
        $flatControls[] = $item;
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Website Colors - Subhiksha Cards</title>
    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>

    <style>
    .website-colors-page {
        overflow-x: hidden;
    }

    .website-colors-head {
        padding: 24px 28px;
        margin-bottom: 18px;
    }

    .website-colors-head h1 {
        font-size: 30px;
        line-height: 1.2;
        font-weight: 900;
        color: var(--text-main);
    }

    .website-colors-head p {
        font-size: 15px;
        max-width: 780px;
    }

    .website-color-summary {
        margin-bottom: 18px;
    }

    .summary-theme-card {
        min-height: 112px;
        padding: 18px;
        display: flex;
        align-items: center;
        gap: 14px;
        overflow: hidden;
    }

    .summary-theme-icon {
        width: 52px;
        height: 52px;
        border-radius: 16px;
        display: grid;
        place-items: center;
        color: #fff;
        flex: 0 0 auto;
    }

    .summary-theme-icon svg {
        width: 24px;
        height: 24px;
    }

    .summary-theme-card span,
    .summary-theme-card small {
        display: block;
        color: var(--text-muted);
        font-weight: 800;
        font-size: 12px;
    }

    .summary-theme-card strong {
        display: block;
        color: var(--text-main);
        font-size: 18px;
        line-height: 1.25;
        font-weight: 900;
        margin: 2px 0;
        word-break: break-word;
    }

    .website-color-layout {
        align-items: flex-start;
    }

    .website-color-main-card,
    .website-preview-card {
        padding: 26px 28px;
    }

    .website-color-group+.website-color-group {
        margin-top: 34px;
    }

    .website-color-group h2 {
        font-size: 20px;
        font-weight: 900;
        margin: 0 0 18px;
        color: var(--text-main);
    }

    .website-color-field {
        border: 1px solid var(--border-soft);
        background: color-mix(in srgb, var(--card-bg) 92%, var(--body-bg));
        border-radius: 18px;
        padding: 16px;
        height: 100%;
    }

    .website-color-field label {
        display: block;
        color: var(--text-main);
        font-size: 13px;
        font-weight: 900;
        margin-bottom: 12px;
        line-height: 1.3;
    }

    .website-color-input-wrap {
        display: grid;
        grid-template-columns: 58px minmax(0, 1fr);
        gap: 12px;
        align-items: center;
    }

    .website-color-input-wrap input[type="color"] {
        width: 58px;
        height: 48px;
        border: 1px solid var(--input-border);
        border-radius: 14px;
        background: var(--input-bg);
        padding: 4px;
        cursor: pointer;
    }

    .website-color-input-wrap .form-control {
        height: 48px;
        border-radius: 14px;
        font-weight: 800;
        text-transform: uppercase;
        background: var(--input-bg);
        border-color: var(--input-border);
        color: var(--input-text);
    }

    .website-color-input-wrap .form-control.is-invalid {
        border-color: var(--danger-color);
    }

    .website-sidebar-gradient-note {
        margin: 0 0 18px;
        padding: 13px 15px;
        border-radius: 15px;
        background: linear-gradient(135deg, var(--sidebar-bg-1, #10192E), var(--sidebar-bg-2, #1E3A5F), var(--sidebar-bg-3, #315C8A));
        color: var(--sidebar-text, #fff);
        font-weight: 800;
        font-size: 13px;
    }

    .website-preview-sticky {
        position: sticky;
        top: 92px;
    }

    .live-pill {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 6px 12px;
        background: color-mix(in srgb, var(--success-color) 16%, transparent);
        color: var(--success-color);
        font-size: 12px;
        font-weight: 900;
    }

    .website-mini-browser {
        border: 1px solid var(--border-soft);
        background: var(--card-bg);
        border-radius: 22px;
        overflow: hidden;
    }

    .browser-dots {
        height: 44px;
        display: flex;
        align-items: center;
        gap: 7px;
        padding: 0 14px;
        border-bottom: 1px solid var(--border-soft);
        background: var(--card-header-bg);
    }

    .browser-dots span {
        width: 10px;
        height: 10px;
        border-radius: 999px;
        background: var(--text-muted);
        opacity: .55;
    }

    .preview-layout {
        display: grid;
        grid-template-columns: 118px minmax(0, 1fr);
        min-height: 360px;
        background: var(--body-bg);
    }

    .preview-sidebar {
        background: var(--sidebar-bg);
        color: var(--sidebar-text);
        padding: 14px 10px;
    }

    .preview-logo {
        width: 34px;
        height: 34px;
        border-radius: 12px;
        margin-bottom: 14px;
        background: linear-gradient(135deg, var(--brand-1), var(--brand-2));
    }

    .preview-menu {
        border-radius: 10px;
        padding: 9px 10px;
        font-size: 11px;
        font-weight: 900;
        color: var(--sidebar-text);
        margin-bottom: 7px;
    }

    .preview-menu:hover {
        background: var(--sidebar-hover-bg);
        color: var(--sidebar-hover-text);
    }

    .preview-menu.active {
        color: var(--sidebar-active-text);
        background: linear-gradient(135deg, var(--sidebar-active-bg-1), var(--sidebar-active-bg-2));
    }

    .preview-content {
        padding: 14px;
        display: grid;
        align-content: start;
        gap: 12px;
        background: var(--body-bg);
    }

    .preview-card {
        background: var(--card-bg);
        border: 1px solid var(--border-soft);
        border-radius: 16px;
        padding: 15px;
        color: var(--text-main);
    }

    .preview-card h3 {
        font-size: 17px;
        margin: 0 0 7px;
        font-weight: 900;
        color: var(--text-main);
    }

    .preview-card p {
        color: var(--text-muted);
        font-size: 12px;
        line-height: 1.5;
        margin-bottom: 12px;
    }

    .preview-card button {
        border: 0;
        background: linear-gradient(135deg, var(--brand-1), var(--brand-2));
        color: var(--brand-text);
        border-radius: 12px;
        padding: 8px 12px;
        font-size: 12px;
        font-weight: 900;
    }

    .preview-card input {
        width: 100%;
        border: 1px solid var(--input-border);
        background: var(--input-bg);
        color: var(--input-text);
        border-radius: 12px;
        padding: 9px 10px;
        font-size: 12px;
        font-weight: 800;
    }

    .preview-table-head {
        margin-top: 10px;
        background: var(--table-header-bg);
        color: var(--table-header-text);
        border-radius: 12px;
        padding: 9px 10px;
        font-size: 12px;
        font-weight: 900;
    }

    .preview-statuses {
        display: flex;
        flex-wrap: wrap;
        gap: 7px;
    }

    .preview-statuses span {
        border-radius: 999px;
        padding: 6px 9px;
        color: #fff;
        font-size: 10px;
        font-weight: 900;
    }

    .preview-statuses .success {
        background: var(--success-color);
    }

    .preview-statuses .warning {
        background: var(--warning-color);
    }

    .preview-statuses .danger {
        background: var(--danger-color);
    }

    .preview-statuses .info {
        background: var(--info-color);
    }

    .website-density-card {
        max-width: 420px;
    }

    @media (max-width: 1199.98px) {
        .website-preview-sticky {
            position: static;
        }

        .website-color-main-card,
        .website-preview-card {
            padding: 20px;
        }
    }

    @media (max-width: 575px) {
        .website-colors-head {
            padding: 18px;
        }

        .website-colors-head h1 {
            font-size: 24px;
        }

        .website-color-main-card,
        .website-preview-card {
            padding: 16px;
        }

        .summary-theme-card {
            min-height: auto;
        }

        .website-color-input-wrap {
            grid-template-columns: 52px minmax(0, 1fr);
        }

        .website-color-input-wrap input[type="color"] {
            width: 52px;
        }

        .preview-layout {
            grid-template-columns: 96px minmax(0, 1fr);
        }
    }

    /* =========================================================
       TOAST MESSAGE FIX
       Shows toast for Save, Reset, Success and Error messages.
       ========================================================= */
    .subhiksha-toast-wrap {
        position: fixed;
        top: 88px;
        right: 22px;
        z-index: 9999;
        display: grid;
        gap: 12px;
        width: min(380px, calc(100vw - 28px));
        pointer-events: none;
    }

    .subhiksha-toast {
        pointer-events: auto;
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 14px 15px;
        border-radius: 18px;
        background: var(--card-bg, #fff);
        color: var(--text-main, #0f172a);
        border: 1px solid var(--border-soft, #e2e8f0);
        box-shadow: 0 18px 45px rgba(15, 23, 42, .18);
        transform: translateX(110%);
        opacity: 0;
        transition: .25s ease;
        overflow: hidden;
        position: relative;
    }

    .subhiksha-toast.show {
        transform: translateX(0);
        opacity: 1;
    }

    .subhiksha-toast::before {
        content: "";
        position: absolute;
        inset: 0 auto 0 0;
        width: 5px;
        background: var(--brand-1, #0f766e);
    }

    .subhiksha-toast.success::before {
        background: var(--success-color, #16a34a);
    }

    .subhiksha-toast.error::before {
        background: var(--danger-color, #dc2626);
    }

    .subhiksha-toast.warning::before {
        background: var(--warning-color, #f59e0b);
    }

    .subhiksha-toast.info::before {
        background: var(--info-color, #2563eb);
    }

    .subhiksha-toast-icon {
        width: 36px;
        height: 36px;
        border-radius: 13px;
        display: grid;
        place-items: center;
        flex: 0 0 auto;
        color: #fff;
        background: var(--brand-1, #0f766e);
    }

    .subhiksha-toast.success .subhiksha-toast-icon {
        background: var(--success-color, #16a34a);
    }

    .subhiksha-toast.error .subhiksha-toast-icon {
        background: var(--danger-color, #dc2626);
    }

    .subhiksha-toast.warning .subhiksha-toast-icon {
        background: var(--warning-color, #f59e0b);
    }

    .subhiksha-toast.info .subhiksha-toast-icon {
        background: var(--info-color, #2563eb);
    }

    .subhiksha-toast-title {
        font-size: 14px;
        font-weight: 900;
        margin-bottom: 3px;
        color: var(--text-main, #0f172a);
    }

    .subhiksha-toast-message {
        font-size: 12px;
        font-weight: 700;
        color: var(--text-muted, #64748b);
        line-height: 1.4;
    }

    .subhiksha-toast-close {
        margin-left: auto;
        border: 0;
        background: transparent;
        color: var(--text-muted, #64748b);
        padding: 0;
        line-height: 1;
        font-weight: 900;
        font-size: 20px;
        cursor: pointer;
    }

    @media (max-width: 575px) {
        .subhiksha-toast-wrap {
            top: 76px;
            right: 14px;
            left: 14px;
            width: auto;
        }

        .subhiksha-toast {
            border-radius: 16px;
        }
    }
    </style>
</head>

<body class="<?= e(($theme['layout_density'] ?? '') === 'compact' ? 'layout-compact' : '') ?>">
    <div id="subhikshaToastWrap" class="subhiksha-toast-wrap" aria-live="polite" aria-atomic="true"></div>
    <div id="mobileOverlay"></div>

    <div class="app-shell">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main id="main">
            <?php include __DIR__ . '/includes/nav.php'; ?>

            <section class="page-section website-colors-page">
                <div class="card-ui website-colors-head">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                        <div>
                            <h1 class="mb-1">Website Colors</h1>
                            <p class="text-muted-custom mb-0">
                                Update the Subhiksha Cards admin panel theme. Changes preview live before saving.
                            </p>
                        </div>

                        <div class="d-flex gap-2 flex-wrap">
                            <button type="button" id="resetWebsitePreview"
                                class="btn btn-outline-secondary rounded-pill px-4 fw-bold">
                                Reset Preview
                            </button>

                            <?php if (can_edit($conn, 'website-colors.php')): ?>
                            <button type="button" id="saveWebsiteColors"
                                class="btn btn-primary rounded-pill px-4 fw-bold">
                                Save Colors
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="row g-3 website-color-summary">
                    <div class="col-12 col-md-6 col-xxl-3">
                        <div class="card-ui summary-theme-card h-100">
                            <div class="summary-theme-icon js-sidebar-summary-gradient">
                                <i data-lucide="panel-left"></i>
                            </div>
                            <div>
                                <span>Sidebar Gradient</span>
                                <strong
                                    data-summary-key="sidebar_bg_1"><?= e(wc_theme_value($theme, 'sidebar_bg_1')) ?></strong>
                                <small data-summary-extra="sidebar_gradient">
                                    Middle: <?= e(wc_theme_value($theme, 'sidebar_bg_2')) ?> → End:
                                    <?= e(wc_theme_value($theme, 'sidebar_bg_3')) ?>
                                </small>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6 col-xxl-3">
                        <div class="card-ui summary-theme-card h-100">
                            <div class="summary-theme-icon" style="background:var(--brand-1)">
                                <i data-lucide="palette"></i>
                            </div>
                            <div>
                                <span>Brand Primary</span>
                                <strong data-summary-key="brand_1"><?= e(wc_theme_value($theme, 'brand_1')) ?></strong>
                                <small>Live primary color</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6 col-xxl-3">
                        <div class="card-ui summary-theme-card h-100">
                            <div class="summary-theme-icon" style="background:linear-gradient(135deg,#8b5cf6,#5b4df5)">
                                <i data-lucide="layout-dashboard"></i>
                            </div>
                            <div>
                                <span>Body BG</span>
                                <strong data-summary-key="body_bg"><?= e(wc_theme_value($theme, 'body_bg')) ?></strong>
                                <small>Page background</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6 col-xxl-3">
                        <div class="card-ui summary-theme-card h-100">
                            <div class="summary-theme-icon" style="background:var(--text-main)">
                                <i data-lucide="type"></i>
                            </div>
                            <div>
                                <span>Text Main</span>
                                <strong
                                    data-summary-key="text_main"><?= e(wc_theme_value($theme, 'text_main')) ?></strong>
                                <small>UI main text</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 website-color-layout">
                    <div class="col-12 col-xl-8">
                        <div class="card-ui website-color-main-card">
                            <?php foreach ($controls as $groupKey => $items): ?>
                            <section class="website-color-group">
                                <h2><?= e($groups[$groupKey] ?? ucwords($groupKey)) ?></h2>

                                <?php if ($groupKey === 'sidebar'): ?>
                                <div class="website-sidebar-gradient-note">
                                    Sidebar background uses dynamic 3 color gradient: Start Color → Middle Color → End
                                    Color.
                                </div>
                                <?php endif; ?>

                                <div class="row g-3">
                                    <?php foreach ($items as [$key, $label, $variable]): ?>
                                    <?php $value = wc_theme_value($theme, $key); ?>
                                    <div class="col-12 col-md-6 col-xxl-4">
                                        <div class="website-color-field">
                                            <label for="color_<?= e($key) ?>"><?= e($label) ?></label>

                                            <div class="website-color-input-wrap">
                                                <input type="color" id="color_<?= e($key) ?>" value="<?= e($value) ?>"
                                                    class="js-website-color-picker" data-theme-key="<?= e($key) ?>"
                                                    data-css-variable="<?= e($variable) ?>"
                                                    data-original-value="<?= e($value) ?>">

                                                <input type="text" value="<?= e($value) ?>"
                                                    class="form-control js-website-color-text"
                                                    data-theme-key="<?= e($key) ?>"
                                                    data-css-variable="<?= e($variable) ?>"
                                                    data-original-value="<?= e($value) ?>" maxlength="7"
                                                    spellcheck="false">
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                            <?php endforeach; ?>

                            <section class="website-color-group">
                                <h2>Layout Density</h2>

                                <div class="website-density-card">
                                    <div class="website-color-field">
                                        <label for="websiteDensitySelect">Density</label>
                                        <select id="websiteDensitySelect" class="form-select"
                                            data-original-value="<?= e($theme['layout_density'] ?? 'comfortable') ?>">
                                            <option value="comfortable"
                                                <?= ($theme['layout_density'] ?? 'comfortable') === 'comfortable' ? 'selected' : '' ?>>
                                                Comfortable
                                            </option>
                                            <option value="compact"
                                                <?= ($theme['layout_density'] ?? '') === 'compact' ? 'selected' : '' ?>>
                                                Compact
                                            </option>
                                        </select>
                                    </div>
                                </div>
                            </section>

                            <div id="websiteColorMessage" class="mt-3 small fw-semibold" role="status"></div>
                        </div>
                    </div>

                    <div class="col-12 col-xl-4">
                        <div class="card-ui website-preview-card website-preview-sticky">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                <div>
                                    <h2 class="fs-5 fw-bold mb-1">Live Preview</h2>
                                    <p class="text-muted-custom mb-0">This preview updates instantly.</p>
                                </div>
                                <span class="live-pill">Live</span>
                            </div>

                            <div class="website-mini-browser">
                                <div class="browser-dots"><span></span><span></span><span></span></div>

                                <div class="preview-layout">
                                    <aside class="preview-sidebar">
                                        <div class="preview-logo"></div>
                                        <div class="preview-menu active">Dashboard</div>
                                        <div class="preview-menu">Orders</div>
                                        <div class="preview-menu">Customers</div>
                                        <div class="preview-menu">Reports</div>
                                    </aside>

                                    <div class="preview-content">
                                        <div class="preview-card">
                                            <h3>Dashboard Card</h3>
                                            <p>Main text, muted text, card and border colors.</p>
                                            <button type="button">Brand Button</button>
                                        </div>

                                        <div class="preview-card">
                                            <h3>Form & Table</h3>
                                            <input type="text" value="Input preview" readonly>
                                            <div class="preview-table-head">Table Header</div>
                                        </div>

                                        <div class="preview-statuses">
                                            <span class="success">Success</span>
                                            <span class="warning">Warning</span>
                                            <span class="danger">Danger</span>
                                            <span class="info">Info</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <p class="small text-muted-custom mb-0 mt-3">
                                Select any color on the left. The full page and this preview update immediately.
                            </p>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <div id="settingsOverlay"></div>
        <?php include __DIR__ . '/includes/rightsidebar.php'; ?>
    </div>

    <?php include __DIR__ . '/includes/script.php'; ?>

    <script>
    (function() {
        const body = document.body;
        const root = document.documentElement;
        const hexRegex = /^#[0-9A-Fa-f]{6}$/;

        function showToast(type, title, message) {
            const wrap = document.getElementById('subhikshaToastWrap');
            if (!wrap) return;

            const toast = document.createElement('div');
            const iconMap = {
                success: 'check-circle-2',
                error: 'x-circle',
                warning: 'alert-triangle',
                info: 'info'
            };

            toast.className = 'subhiksha-toast ' + (type || 'info');
            toast.innerHTML =
                '<div class="subhiksha-toast-icon"><i data-lucide="' + (iconMap[type] || 'info') + '"></i></div>' +
                '<div class="flex-grow-1">' +
                '<div class="subhiksha-toast-title">' + title + '</div>' +
                '<div class="subhiksha-toast-message">' + message + '</div>' +
                '</div>' +
                '<button type="button" class="subhiksha-toast-close" aria-label="Close">&times;</button>';

            wrap.appendChild(toast);

            if (window.lucide && typeof window.lucide.createIcons === 'function') {
                window.lucide.createIcons();
            }

            requestAnimationFrame(function() {
                toast.classList.add('show');
            });

            const close = function() {
                toast.classList.remove('show');
                setTimeout(function() {
                    toast.remove();
                }, 260);
            };

            toast.querySelector('.subhiksha-toast-close')?.addEventListener('click', close);
            setTimeout(close, 3800);
        }

        function normalizeHex(value) {
            value = String(value || '').trim().toUpperCase();
            if (value !== '' && !value.startsWith('#')) {
                value = '#' + value;
            }
            return value;
        }

        function validHex(value) {
            return hexRegex.test(value);
        }

        function setMessage(text, type) {
            const message = document.getElementById('websiteColorMessage');
            if (!message) return;
            message.textContent = text;
            message.className = 'mt-3 small fw-semibold ' + (type === 'error' ? 'text-danger' : 'text-success');
        }

        function setSummary(key, value) {
            document.querySelectorAll('[data-summary-key="' + CSS.escape(key) + '"]').forEach(function(item) {
                item.textContent = value;
            });
        }

        function getRootVar(name, fallback) {
            const value = getComputedStyle(root).getPropertyValue(name).trim();
            return value || fallback;
        }

        function applySidebarGradient() {
            const start = getRootVar('--sidebar-bg-1', '#10192E');
            const middle = getRootVar('--sidebar-bg-2', '#1E3A5F');
            const end = getRootVar('--sidebar-bg-3', '#315C8A');
            const gradient = 'linear-gradient(180deg, ' + start + ' 0%, ' + middle + ' 48%, ' + end + ' 100%)';

            root.style.setProperty('--sidebar-bg', gradient);

            const sidebar = document.getElementById('sidebar');
            if (sidebar) sidebar.style.background = gradient;

            document.querySelectorAll(
                '.preview-sidebar, .website-sidebar-gradient-note, .js-sidebar-summary-gradient').forEach(
                function(el) {
                    el.style.background = gradient;
                });

            setSummary('sidebar_bg_1', start + ' → ' + middle + ' → ' + end);

            document.querySelectorAll('[data-summary-extra="sidebar_gradient"]').forEach(function(item) {
                item.textContent = 'Middle: ' + middle + ' → End: ' + end;
            });
        }

        function setLinkedControls(key, value) {
            document.querySelectorAll('[data-theme-key="' + CSS.escape(key) + '"]').forEach(function(control) {
                if (control.value !== value) {
                    control.value = value;
                }
            });
        }

        function applyColor(key, value, cssVariable) {
            value = normalizeHex(value);

            if (!validHex(value)) {
                return false;
            }

            root.style.setProperty(cssVariable, value);
            setLinkedControls(key, value);
            setSummary(key, value);

            if (['sidebar_bg_1', 'sidebar_bg_2', 'sidebar_bg_3'].includes(key)) {
                applySidebarGradient();
            }

            return true;
        }

        document.querySelectorAll('.js-website-color-picker').forEach(function(picker) {
            picker.addEventListener('input', function() {
                const key = picker.dataset.themeKey;
                const variable = picker.dataset.cssVariable;
                const value = normalizeHex(picker.value);

                applyColor(key, value, variable);
                setMessage('Preview updated. Click Save Colors to store in database.', 'success');
            });
        });

        document.querySelectorAll('.js-website-color-text').forEach(function(input) {
            input.addEventListener('input', function() {
                const key = input.dataset.themeKey;
                const variable = input.dataset.cssVariable;
                const value = normalizeHex(input.value);

                input.value = value;

                if (applyColor(key, value, variable)) {
                    input.classList.remove('is-invalid');
                    setMessage('Preview updated. Click Save Colors to store in database.',
                        'success');
                } else {
                    input.classList.add('is-invalid');
                    setMessage('Enter a valid HEX color like #FFC61A.', 'error');
                }
            });
        });

        const densitySelect = document.getElementById('websiteDensitySelect');
        if (densitySelect) {
            densitySelect.addEventListener('change', function() {
                body.classList.toggle('layout-compact', densitySelect.value === 'compact');
                setMessage('Layout preview updated. Click Save Colors to store in database.', 'success');
            });
        }

        document.getElementById('resetWebsitePreview')?.addEventListener('click', function() {
            document.querySelectorAll('.js-website-color-picker, .js-website-color-text').forEach(function(
                control) {
                const key = control.dataset.themeKey;
                const variable = control.dataset.cssVariable;
                const original = normalizeHex(control.dataset.originalValue || control.value);

                control.value = original;
                control.classList.remove('is-invalid');
                applyColor(key, original, variable);
            });

            if (densitySelect) {
                const originalDensity = densitySelect.dataset.originalValue || 'comfortable';
                densitySelect.value = originalDensity;
                body.classList.toggle('layout-compact', originalDensity === 'compact');
            }

            applySidebarGradient();
            setMessage('Preview reset to database values.', 'success');
            showToast('info', 'Preview Reset', 'Theme preview restored to the saved database colors.');
        });

        document.getElementById('saveWebsiteColors')?.addEventListener('click', async function() {
            const saveButton = this;
            const colors = {};
            let hasInvalid = false;

            document.querySelectorAll('.js-website-color-text').forEach(function(input) {
                const key = input.dataset.themeKey;
                const value = normalizeHex(input.value);

                input.classList.remove('is-invalid');

                if (!validHex(value)) {
                    input.classList.add('is-invalid');
                    hasInvalid = true;
                    return;
                }

                colors[key] = value;
            });

            if (hasInvalid) {
                setMessage('Please correct invalid HEX values before saving.', 'error');
                showToast('error', 'Invalid Color', 'Please correct invalid HEX values before saving.');
                return;
            }

            const payload = {
                colors: colors,
                layout_density: densitySelect ? densitySelect.value : 'comfortable'
            };

            const oldText = saveButton.textContent;
            saveButton.disabled = true;
            saveButton.textContent = 'Saving...';
            showToast('info', 'Saving Colors', 'Please wait while the theme colors are saved.');

            try {
                const response = await fetch('ajax/save-website-colors.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(payload)
                });

                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Unable to save colors.');
                }

                Object.entries(colors).forEach(function([key, value]) {
                    document.querySelectorAll('[data-theme-key="' + CSS.escape(key) + '"]')
                        .forEach(function(control) {
                            control.dataset.originalValue = value;
                        });
                });

                if (densitySelect) {
                    densitySelect.dataset.originalValue = payload.layout_density;
                }

                setMessage(result.message || 'Colors saved successfully.', 'success');
                showToast('success', 'Colors Saved', result.message ||
                    'Website colors saved successfully.');
            } catch (error) {
                setMessage(error.message ||
                    'Unable to save colors. Check ajax/save-website-colors.php.', 'error');
                showToast('error', 'Save Failed', error.message ||
                    'Unable to save colors. Check ajax/save-website-colors.php.');
            } finally {
                saveButton.disabled = false;
                saveButton.textContent = oldText;
            }
        });

        applySidebarGradient();

        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    })();
    </script>
</body>

</html>