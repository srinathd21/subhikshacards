<?php
$sidebarTextOptions = [
    '#334155' => 'Dark Slate',
    '#ffffff' => 'White',
    '#0f172a' => 'Black',
    '#dbeafe' => 'Soft Blue',
];

$currentSidebarText = $theme['sidebar_text'] ?? '#334155';
$currentDensity = $theme['layout_density'] ?? 'comfortable';
?>

<style>
#settingsPanel {
    position: fixed;
    top: 0;
    right: -470px;
    width: 460px;
    max-width: 100%;
    height: 100vh;
    background: var(--card-bg, #fff);
    color: var(--text-main, #0f172a);
    z-index: 1060;
    display: flex;
    flex-direction: column;
    box-shadow: -18px 0 48px rgba(15, 23, 42, .18);
    transition: right .25s ease;
}

#settingsPanel.open {
    right: 0
}

.settings-reference-header {
    min-height: 56px;
    padding: 0 18px;
    border-bottom: 1px solid var(--border-soft, #e2e8f0);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
}

.settings-reference-header h2 {
    margin: 0;
    font-size: 20px;
    line-height: 1.15;
    font-weight: 800;
}

.settings-reference-header p {
    margin: 2px 0 0;
    color: var(--text-muted, #64748b);
    font-size: 15px;
    line-height: 1.2;
}

.settings-close-btn {
    width: 40px;
    height: 40px;
    padding: 0;
    display: grid;
    place-items: center;
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: var(--text-main, #0f172a);
}

.settings-close-btn:hover {
    background: rgba(148, 163, 184, .12)
}

.settings-close-btn svg {
    width: 28px;
    height: 28px;
    stroke-width: 2.1
}

.settings-reference-body {
    padding: 22px 18px 28px;
    overflow-y: auto;
    flex: 1;
}

.settings-reference-note {
    padding: 20px;
    border: 1px solid #93c5fd;
    border-radius: 20px;
    background: #dbeafe;
    color: #5b708f;
    font-size: 16px;
    line-height: 1.55;
    margin-bottom: 34px;
}

.settings-reference-field {
    margin-bottom: 34px
}

.settings-reference-label {
    display: block;
    margin-bottom: 12px;
    color: #60738f;
    font-size: 15px;
    font-weight: 800;
    text-transform: uppercase;
}

.settings-reference-color {
    width: 100%;
    height: 56px;
    padding: 7px;
    border: 1px solid var(--border-soft, #d7dee8);
    border-radius: 18px;
    background: var(--card-bg, #fff);
    cursor: pointer;
    overflow: hidden;
}

.settings-reference-color::-webkit-color-swatch-wrapper {
    padding: 0
}

.settings-reference-color::-webkit-color-swatch {
    border: 0;
    border-radius: 11px;
}

.settings-reference-color::-moz-color-swatch {
    border: 0;
    border-radius: 11px;
}

.settings-reference-select {
    width: 100%;
    height: 56px;
    padding: 0 48px 0 15px;
    border: 1px solid var(--border-soft, #d7dee8);
    border-radius: 18px;
    background-color: var(--card-bg, #fff);
    color: var(--text-main, #1f2937);
    font-size: 16px;
    font-weight: 700;
}

.settings-reference-actions {
    padding-top: 4px;
    padding-bottom: 8px;
}

.settings-reference-actions .btn {
    min-height: 48px;
    border-radius: 14px;
    font-weight: 800;
}

#themeMessage {
    min-height: 20px
}

@media(max-width:575.98px) {
    #settingsPanel {
        width: 100%;
        right: -100%
    }

    .settings-reference-header {
        padding: 0 14px
    }

    .settings-reference-body {
        padding: 22px 16px 26px
    }
}
</style>

<aside id="settingsPanel" aria-hidden="true">
    <div class="settings-reference-header">
        <div>
            <h2>Dashboard Settings</h2>
            <p>Customize Subhiksha Cards appearance</p>
        </div>

        <button id="settingsClose" class="settings-close-btn" type="button" aria-label="Close dashboard settings">
            <i data-lucide="x"></i>
        </button>
    </div>

    <div class="settings-reference-body thin-scrollbar">
        <div class="settings-reference-note">
            Sidebar, topbar and sidebar text colors are customizable in light mode only. Dark mode automatically uses
            readable dark colors.
        </div>

        <div class="settings-reference-field">
            <label class="settings-reference-label" for="sidebarColorPicker">Sidebar Color</label>
            <input id="sidebarColorPicker" class="settings-reference-color js-theme-control" type="color"
                value="<?= e($theme['sidebar_bg'] ?? '#2f3a45') ?>" data-theme-key="sidebar_bg"
                data-css-variable="--sidebar-bg" aria-label="Sidebar color">
        </div>

        <div class="settings-reference-field">
            <label class="settings-reference-label" for="topbarColorPicker">Topbar Color</label>
            <input id="topbarColorPicker" class="settings-reference-color js-theme-control" type="color"
                value="<?= e($theme['topbar_bg'] ?? '#ffffff') ?>" data-theme-key="topbar_bg"
                data-css-variable="--topbar-bg" aria-label="Topbar color">
        </div>

        <div class="settings-reference-field">
            <label class="settings-reference-label" for="primaryColorPicker">Primary Brand Color</label>
            <input id="primaryColorPicker" class="settings-reference-color js-theme-control" type="color"
                value="<?= e($theme['brand_1'] ?? '#0f766e') ?>" data-theme-key="brand_1" data-css-variable="--brand-1"
                aria-label="Primary brand color">
        </div>

        <div class="settings-reference-field">
            <label class="settings-reference-label" for="secondaryColorPicker">Secondary Brand Color</label>
            <input id="secondaryColorPicker" class="settings-reference-color js-theme-control" type="color"
                value="<?= e($theme['brand_2'] ?? '#2563eb') ?>" data-theme-key="brand_2" data-css-variable="--brand-2"
                aria-label="Secondary brand color">
        </div>

        <div class="settings-reference-field">
            <label class="settings-reference-label" for="sidebarTextSelect">Sidebar Text Color</label>
            <select id="sidebarTextSelect" class="settings-reference-select" data-theme-key="sidebar_text"
                data-css-variable="--sidebar-text">
                <?php foreach ($sidebarTextOptions as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $currentSidebarText === $value ? 'selected' : '' ?>>
                    <?= e($label) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="settings-reference-field">
            <label class="settings-reference-label" for="densitySelect">Layout Density</label>
            <select id="densitySelect" class="settings-reference-select" data-theme-key="layout_density">
                <option value="comfortable" <?= $currentDensity === 'comfortable' ? 'selected' : '' ?>>Comfortable
                </option>
                <option value="compact" <?= $currentDensity === 'compact' ? 'selected' : '' ?>>Compact</option>
            </select>
        </div>

        <div class="settings-reference-actions">
            <button id="saveThemeSettings" class="btn btn-primary w-100" type="button">
                <span class="save-theme-label">Save Theme</span>
            </button>

            <button id="resetCustomization" class="btn btn-outline-secondary w-100 mt-2" type="button">
                Reset to Database Values
            </button>

            <div id="themeMessage" class="small mt-2" role="status"></div>
        </div>
    </div>
</aside>