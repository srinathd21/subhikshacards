<?php
require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'system-config.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['settings_csrf'])) {
    $_SESSION['settings_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['settings_csrf'];
$message = '';
$messageType = 'success';
$whatsappApiFile = __DIR__ . '/includes/whatsapp-api.php';

function st_post(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function st_redirect(string $query = ''): void
{
    header('Location: system-config.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

function st_digits(string $value): string
{
    return (string)preg_replace('/\D+/', '', $value);
}

function st_is_secret_key(string $key): bool
{
    return (bool)preg_match(
        '/(?:secret|token|api[_-]?key|password|access[_-]?token)/i',
        $key
    );
}

function st_get_setting(
    mysqli $conn,
    string $key,
    string $default = ''
): string {
    $stmt = $conn->prepare(
        'SELECT setting_value
         FROM system_settings
         WHERE setting_key = ?
         LIMIT 1'
    );
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (string)$row['setting_value'] : $default;
}

function st_upsert_setting(
    mysqli $conn,
    string $key,
    string $value,
    string $type,
    string $description,
    int $isPublic,
    int $userId
): void {
    $stmt = $conn->prepare(
        'INSERT INTO system_settings
            (
                setting_key,
                setting_value,
                setting_type,
                description,
                is_public,
                updated_by,
                created_at,
                updated_at
            )
         VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            setting_type = VALUES(setting_type),
            description = VALUES(description),
            is_public = VALUES(is_public),
            updated_by = VALUES(updated_by),
            updated_at = NOW()'
    );
    $stmt->bind_param(
        'ssssii',
        $key,
        $value,
        $type,
        $description,
        $isPublic,
        $userId
    );
    $stmt->execute();
    $stmt->close();
}

function st_require_whatsapp_api(string $file): void
{
    if (!is_file($file)) {
        throw new RuntimeException(
            'includes/whatsapp-api.php was not found. Upload the updated WhatsApp API file first.'
        );
    }

    require_once $file;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        empty($_POST['csrf_token'])
        || !hash_equals($_SESSION['settings_csrf'], (string)$_POST['csrf_token'])
    ) {
        die('Invalid CSRF token.');
    }

    $action = st_post('action', 'save_setting');
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $transactionStarted = false;

    try {
        if ($action === 'save_whatsapp') {
            $enabled = isset($_POST['whatsapp_enabled']) ? '1' : '0';
            $businessAccountId = st_digits(
                st_post('meta_whatsapp_business_account_id')
            );
            $displayPhoneNumber = st_post(
                'meta_whatsapp_display_phone_number'
            );
            $phoneNumberId = st_digits(
                st_post('meta_whatsapp_phone_number_id')
            );
            $accessToken = st_post('meta_whatsapp_access_token');
            $graphVersion = st_post(
                'meta_whatsapp_graph_version',
                'v23.0'
            );
            $languageCode = st_post(
                'meta_whatsapp_language_code',
                'en'
            );

            if (
                $graphVersion !== ''
                && substr($graphVersion, 0, 1) !== 'v'
            ) {
                $graphVersion = 'v' . $graphVersion;
            }

            if ($businessAccountId === '') {
                throw new RuntimeException(
                    'WhatsApp Business Account ID is required.'
                );
            }

            if (!preg_match('/^v\d+\.\d+$/', $graphVersion)) {
                throw new RuntimeException(
                    'Graph API version must be in a format such as v23.0.'
                );
            }

            if (!preg_match('/^[a-z]{2}(?:_[A-Z]{2})?$/', $languageCode)) {
                throw new RuntimeException(
                    'Template language must be en, en_US or another valid language code.'
                );
            }

            $environmentToken = getenv(
                'SUBHIKSHA_WHATSAPP_ACCESS_TOKEN'
            );
            if (
                $environmentToken === false
                || trim((string)$environmentToken) === ''
            ) {
                $environmentToken = getenv(
                    'SUBHIKSHA_WHATSAPP_API_KEY'
                );
            }

            $existingToken = st_get_setting(
                $conn,
                'meta_whatsapp_access_token',
                ''
            );
            $effectiveToken = (
                $environmentToken !== false
                && trim((string)$environmentToken) !== ''
            )
                ? trim((string)$environmentToken)
                : ($accessToken !== '' ? $accessToken : $existingToken);

            if ($effectiveToken === '') {
                throw new RuntimeException(
                    'Meta WhatsApp access token is required.'
                );
            }

            $conn->begin_transaction();
            $transactionStarted = true;

            $metaSettings = [
                [
                    'whatsapp_provider',
                    'meta_cloud',
                    'text',
                    'Active WhatsApp provider'
                ],
                [
                    'whatsapp_enabled',
                    $enabled,
                    'boolean',
                    'Enable or disable WhatsApp integration'
                ],
                [
                    'meta_whatsapp_business_account_id',
                    $businessAccountId,
                    'text',
                    'Meta WhatsApp Business Account ID'
                ],
                [
                    'meta_whatsapp_display_phone_number',
                    $displayPhoneNumber,
                    'text',
                    'WhatsApp business display phone number'
                ],
                [
                    'meta_whatsapp_phone_number_id',
                    $phoneNumberId,
                    'text',
                    'Meta Phone Number ID used by the Messages API'
                ],
                [
                    'meta_whatsapp_graph_version',
                    $graphVersion,
                    'text',
                    'Meta Graph API version'
                ],
                [
                    'meta_whatsapp_language_code',
                    $languageCode,
                    'text',
                    'Default approved template language code'
                ]
            ];

            foreach ($metaSettings as $setting) {
                st_upsert_setting(
                    $conn,
                    $setting[0],
                    $setting[1],
                    $setting[2],
                    $setting[3],
                    0,
                    $userId
                );
            }

            /*
             * Blank means keep the existing token. The stored token is never
             * rendered back into the page.
             */
            if ($accessToken !== '') {
                st_upsert_setting(
                    $conn,
                    'meta_whatsapp_access_token',
                    $accessToken,
                    'text',
                    'Meta WhatsApp Cloud API access token',
                    0,
                    $userId
                );
            }

            $conn->commit();
            $transactionStarted = false;
            st_redirect('msg=whatsapp-saved');
        }

        if ($action === 'discover_phone_number') {
            st_require_whatsapp_api($whatsappApiFile);

            $phoneResult = subhiksha_meta_get_phone_numbers($conn);
            if (empty($phoneResult['success'])) {
                throw new RuntimeException(
                    'Unable to retrieve the Meta Phone Number ID: '
                    . (string)($phoneResult['message'] ?? 'Unknown error.')
                );
            }

            $phoneNumbers = (array)($phoneResult['data'] ?? []);
            if (empty($phoneNumbers)) {
                throw new RuntimeException(
                    'No WhatsApp phone number is connected to this WABA.'
                );
            }

            $targetDisplayNumber = st_digits(
                st_get_setting(
                    $conn,
                    'meta_whatsapp_display_phone_number',
                    ''
                )
            );
            $selectedPhone = null;

            foreach ($phoneNumbers as $phone) {
                if (
                    $targetDisplayNumber !== ''
                    && st_digits(
                        (string)($phone['display_phone_number'] ?? '')
                    ) === $targetDisplayNumber
                ) {
                    $selectedPhone = $phone;
                    break;
                }
            }

            if ($selectedPhone === null && count($phoneNumbers) === 1) {
                $selectedPhone = $phoneNumbers[0];
            }

            if ($selectedPhone === null) {
                $availableNumbers = [];
                foreach ($phoneNumbers as $phone) {
                    $availableNumbers[] = (string)(
                        $phone['display_phone_number']
                        ?? $phone['id']
                        ?? 'Unknown'
                    );
                }

                throw new RuntimeException(
                    'Multiple phone numbers were found. Enter the correct display number or Phone Number ID. Available: '
                    . implode(', ', $availableNumbers)
                );
            }

            $resolvedPhoneId = st_digits(
                (string)($selectedPhone['id'] ?? '')
            );
            if ($resolvedPhoneId === '') {
                throw new RuntimeException(
                    'Meta returned a phone record without a Phone Number ID.'
                );
            }

            $conn->begin_transaction();
            $transactionStarted = true;

            st_upsert_setting(
                $conn,
                'meta_whatsapp_phone_number_id',
                $resolvedPhoneId,
                'text',
                'Meta Phone Number ID used by the Messages API',
                0,
                $userId
            );
            st_upsert_setting(
                $conn,
                'meta_whatsapp_verified_name',
                (string)($selectedPhone['verified_name'] ?? ''),
                'text',
                'Meta verified WhatsApp business name',
                0,
                $userId
            );
            st_upsert_setting(
                $conn,
                'meta_whatsapp_quality_rating',
                (string)($selectedPhone['quality_rating'] ?? ''),
                'text',
                'Meta WhatsApp phone quality rating',
                0,
                $userId
            );

            $conn->commit();
            $transactionStarted = false;
            st_redirect('msg=phone-discovered');
        }

        if ($action === 'test_whatsapp') {
            $testMobile = st_post('test_mobile');
            if ($testMobile === '') {
                throw new RuntimeException(
                    'Enter a recipient mobile number for the template test.'
                );
            }

            st_require_whatsapp_api($whatsappApiFile);

            $testResult = subhiksha_send_template_whatsapp(
                $conn,
                'enquiry_completed',
                $testMobile,
                [
                    'customer_name' => 'Test Customer',
                    'enquiry_no' => 'TEST-0001',
                    'function_type' => 'Invitation Cards',
                    'order_type' => 'Customized'
                ],
                [
                    'related_module' => 'System Configuration',
                    'sent_by' => $userId
                ]
            );

            if (empty($testResult['success'])) {
                throw new RuntimeException(
                    (string)(
                        $testResult['message']
                        ?? 'Meta WhatsApp template test failed.'
                    )
                );
            }

            $message = 'Meta WhatsApp enquiry_completed template sent successfully.';
            $messageType = 'success';
        } elseif ($action === 'save_setting') {
            $id = (int)($_POST['id'] ?? 0);
            $key = st_post('setting_key');
            $value = st_post('setting_value');
            $type = st_post('setting_type', 'text');
            $description = st_post('description');
            $isPublic = isset($_POST['is_public']) ? 1 : 0;

            if ($key === '') {
                throw new RuntimeException('Setting key is required.');
            }

            if (st_is_secret_key($key)) {
                $isPublic = 0;
            }

            if ($id > 0) {
                $existingStmt = $conn->prepare(
                    'SELECT setting_key, setting_value
                     FROM system_settings
                     WHERE id = ?
                     LIMIT 1'
                );
                $existingStmt->bind_param('i', $id);
                $existingStmt->execute();
                $existingRow = $existingStmt
                    ->get_result()
                    ->fetch_assoc();
                $existingStmt->close();

                if (!$existingRow) {
                    throw new RuntimeException('Setting record not found.');
                }

                if (
                    st_is_secret_key(
                        (string)$existingRow['setting_key']
                    )
                    && $key !== (string)$existingRow['setting_key']
                ) {
                    throw new RuntimeException(
                        'A sensitive setting key cannot be renamed.'
                    );
                }

                if (
                    $value === ''
                    && st_is_secret_key(
                        (string)$existingRow['setting_key']
                    )
                ) {
                    $value = (string)$existingRow['setting_value'];
                }

                $stmt = $conn->prepare(
                    'UPDATE system_settings
                     SET setting_key = ?,
                         setting_value = ?,
                         setting_type = ?,
                         description = ?,
                         is_public = ?,
                         updated_by = ?,
                         updated_at = NOW()
                     WHERE id = ?'
                );
                $stmt->bind_param(
                    'ssssiii',
                    $key,
                    $value,
                    $type,
                    $description,
                    $isPublic,
                    $userId,
                    $id
                );
                $stmt->execute();
                $stmt->close();
                st_redirect('msg=updated');
            }

            $stmt = $conn->prepare(
                'INSERT INTO system_settings
                    (
                        setting_key,
                        setting_value,
                        setting_type,
                        description,
                        is_public,
                        updated_by,
                        created_at,
                        updated_at
                    )
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
            );
            $stmt->bind_param(
                'ssssii',
                $key,
                $value,
                $type,
                $description,
                $isPublic,
                $userId
            );
            $stmt->execute();
            $stmt->close();
            st_redirect('msg=created');
        }
    } catch (Throwable $e) {
        if ($transactionStarted) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }
        }

        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

if (($_GET['msg'] ?? '') === 'created') {
    $message = 'Setting created successfully.';
} elseif (($_GET['msg'] ?? '') === 'updated') {
    $message = 'Setting updated successfully.';
} elseif (($_GET['msg'] ?? '') === 'whatsapp-saved') {
    $message = 'Meta WhatsApp Cloud API settings saved successfully.';
} elseif (($_GET['msg'] ?? '') === 'phone-discovered') {
    $message = 'Meta Phone Number ID retrieved and saved successfully.';
}

$settings = [];
$settingsByKey = [];
$result = $conn->query(
    'SELECT * FROM system_settings ORDER BY setting_key ASC'
);

while ($row = $result->fetch_assoc()) {
    $settings[] = $row;
    $settingsByKey[(string)$row['setting_key']] = (string)$row['setting_value'];
}

$metaBusinessAccountId = (string)(
    $settingsByKey['meta_whatsapp_business_account_id']
    ?? '1756666668882701'
);
$metaDisplayPhoneNumber = (string)(
    $settingsByKey['meta_whatsapp_display_phone_number']
    ?? '+1 555-403-3998'
);
$metaPhoneNumberId = (string)(
    $settingsByKey['meta_whatsapp_phone_number_id']
    ?? ''
);
$metaGraphVersion = (string)(
    $settingsByKey['meta_whatsapp_graph_version']
    ?? 'v23.0'
);
$metaLanguageCode = (string)(
    $settingsByKey['meta_whatsapp_language_code']
    ?? 'en'
);
$metaVerifiedName = (string)(
    $settingsByKey['meta_whatsapp_verified_name']
    ?? ''
);
$metaQualityRating = (string)(
    $settingsByKey['meta_whatsapp_quality_rating']
    ?? ''
);
$waEnabled = ($settingsByKey['whatsapp_enabled'] ?? '0') === '1';

$environmentAccessToken = getenv(
    'SUBHIKSHA_WHATSAPP_ACCESS_TOKEN'
);
if (
    $environmentAccessToken === false
    || trim((string)$environmentAccessToken) === ''
) {
    $environmentAccessToken = getenv('SUBHIKSHA_WHATSAPP_API_KEY');
}
$waTokenFromEnvironment = (
    $environmentAccessToken !== false
    && trim((string)$environmentAccessToken) !== ''
);
$waAccessTokenConfigured = $waTokenFromEnvironment
    || trim(
        (string)($settingsByKey['meta_whatsapp_access_token'] ?? '')
    ) !== '';
$waConfigured = $metaBusinessAccountId !== ''
    && $metaPhoneNumberId !== ''
    && $waAccessTokenConfigured;

$templateDefinitions = [];
if (is_file($whatsappApiFile)) {
    require_once $whatsappApiFile;
    if (function_exists('subhiksha_wa_supported_template_keys')) {
        $templateDefinitions = subhiksha_wa_supported_template_keys();
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>System Configuration - Subhiksha Cards</title>
    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>
    <style>
    .master-page .page-head {
        padding: 24px 28px;
        margin-bottom: 18px
    }

    .master-page .page-head h1 {
        font-size: 30px;
        font-weight: 900;
        color: var(--text-main)
    }

    .master-stat-card {
        padding: 18px;
        min-height: 112px;
        display: flex;
        align-items: center;
        gap: 14px
    }

    .master-stat-icon {
        width: 52px;
        height: 52px;
        border-radius: 16px;
        display: grid;
        place-items: center;
        color: #fff;
        flex: 0 0 auto
    }

    .master-stat-card span {
        display: block;
        font-size: 12px;
        color: var(--text-muted);
        font-weight: 900;
        text-transform: uppercase
    }

    .master-stat-card strong {
        font-size: 22px;
        font-weight: 900;
        color: var(--text-main)
    }

    .master-card {
        padding: 24px
    }

    .master-title {
        font-size: 18px;
        font-weight: 900;
        color: var(--text-main);
        margin-bottom: 4px
    }

    .status-pill {
        display: inline-block;
        font-size: 11px;
        font-weight: 900;
        border-radius: 999px;
        padding: 5px 9px
    }

    .status-pill.active {
        color: var(--success-color);
        background: color-mix(in srgb, var(--success-color) 14%, transparent)
    }

    .status-pill.inactive {
        color: var(--danger-color);
        background: color-mix(in srgb, var(--danger-color) 14%, transparent)
    }

    .form-control,
    .form-select {
        border-radius: 14px;
        min-height: 46px
    }

    .modal-content {
        border: 0;
        border-radius: 22px;
        background: var(--card-bg);
        color: var(--text-main)
    }

    .modal-header,
    .modal-footer {
        border-color: var(--border-soft)
    }

    .small-muted {
        display: block;
        margin-top: 3px;
        color: var(--text-muted);
        font-size: 11px;
        font-weight: 700
    }

    .secret-value {
        letter-spacing: 2px;
        font-weight: 900
    }

    .api-note {
        border: 1px solid var(--border-soft);
        border-radius: 16px;
        padding: 14px;
        background: color-mix(in srgb, var(--primary-color) 6%, transparent)
    }

    .section-anchor {
        scroll-margin-top: 100px
    }

    .template-code {
        font-size: 12px;
        font-weight: 800;
        color: var(--primary-color)
    }

    .variable-list {
        font-size: 12px;
        line-height: 1.6;
        color: var(--text-muted)
    }

    @media(max-width:991px) {
        .master-card {
            padding: 18px
        }

        .master-page .page-head {
            padding: 20px
        }
    }
    </style>
</head>

<body class="<?= e(($theme['layout_density'] ?? '') === 'compact' ? 'layout-compact' : '') ?>">
    <div id="mobileOverlay"></div>
    <div class="app-shell">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>
        <main id="main">
            <?php include __DIR__ . '/includes/nav.php'; ?>

            <section class="page-section master-page">
                <div class="card-ui page-head">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                        <div>
                            <h1 class="mb-1">System Configuration</h1>
                            <p class="text-muted-custom mb-0">
                                Official Meta WhatsApp Cloud API and application settings.
                            </p>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="#whatsappSetup" class="btn btn-success rounded-pill px-4 fw-bold">
                                Meta WhatsApp Setup
                            </a>
                            <button id="newSettingBtn" class="btn btn-primary rounded-pill px-4 fw-bold"
                                data-bs-toggle="modal" data-bs-target="#settingModal">
                                New Setting
                            </button>
                        </div>
                    </div>
                </div>

                <?php if ($message): ?>
                <div class="alert alert-<?= e($messageType) ?> rounded-4 fw-bold">
                    <?= e($message) ?>
                </div>
                <?php endif; ?>

                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <div class="card-ui master-stat-card">
                            <div class="master-stat-icon bg-success">
                                <i data-lucide="message-circle"></i>
                            </div>
                            <div>
                                <span>WhatsApp</span>
                                <strong><?= $waEnabled ? 'Enabled' : 'Disabled' ?></strong>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card-ui master-stat-card">
                            <div class="master-stat-icon bg-primary">
                                <i data-lucide="key-round"></i>
                            </div>
                            <div>
                                <span>Access Token</span>
                                <strong><?= $waAccessTokenConfigured ? 'Configured' : 'Missing' ?></strong>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card-ui master-stat-card">
                            <div class="master-stat-icon bg-info">
                                <i data-lucide="phone"></i>
                            </div>
                            <div>
                                <span>Phone Number ID</span>
                                <strong><?= $metaPhoneNumberId !== '' ? 'Ready' : 'Missing' ?></strong>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card-ui master-stat-card">
                            <div class="master-stat-icon bg-warning">
                                <i data-lucide="plug-zap"></i>
                            </div>
                            <div>
                                <span>Integration</span>
                                <strong><?= $waConfigured ? 'Ready' : 'Incomplete' ?></strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="whatsappSetup" class="card-ui master-card mb-3 section-anchor">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-2 mb-4">
                        <div>
                            <h2 class="master-title">Meta WhatsApp Cloud API</h2>
                            <p class="text-muted-custom mb-0">
                                Configure the official Graph API credentials used by all ERP modules.
                            </p>
                        </div>
                        <span class="status-pill <?= $waConfigured ? 'active' : 'inactive' ?> align-self-start">
                            <?= $waConfigured ? 'CONFIGURED' : 'SETUP REQUIRED' ?>
                        </span>
                    </div>

                    <form method="post" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="save_whatsapp">

                        <div class="row g-3">
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="whatsapp_enabled"
                                        name="whatsapp_enabled" value="1" <?= $waEnabled ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-bold" for="whatsapp_enabled">
                                        Enable WhatsApp template messages
                                    </label>
                                </div>
                            </div>

                            <div class="col-lg-6">
                                <label class="form-label fw-bold">WhatsApp Business Account ID *</label>
                                <input type="text" inputmode="numeric" name="meta_whatsapp_business_account_id"
                                    class="form-control" required value="<?= e($metaBusinessAccountId) ?>">
                                <span class="small-muted">
                                    This WABA ID is used to retrieve the connected Phone Number ID.
                                </span>
                            </div>

                            <div class="col-lg-6">
                                <label class="form-label fw-bold">Business Display Number</label>
                                <input type="text" name="meta_whatsapp_display_phone_number" class="form-control"
                                    value="<?= e($metaDisplayPhoneNumber) ?>" placeholder="+1 555-403-3998">
                            </div>

                            <div class="col-lg-6">
                                <label class="form-label fw-bold">Meta Phone Number ID</label>
                                <input type="text" inputmode="numeric" name="meta_whatsapp_phone_number_id"
                                    class="form-control" value="<?= e($metaPhoneNumberId) ?>"
                                    placeholder="Save settings, then use Retrieve Phone Number ID">
                                <span class="small-muted">
                                    Do not enter the WABA ID here. This is the ID returned by the WABA phone_numbers
                                    API.
                                </span>
                            </div>

                            <div class="col-lg-6">
                                <label class="form-label fw-bold">Access Token *</label>
                                <div class="input-group">
                                    <input type="password" name="meta_whatsapp_access_token"
                                        id="meta_whatsapp_access_token" class="form-control" value=""
                                        autocomplete="new-password"
                                        placeholder="<?= $waAccessTokenConfigured ? 'Configured — leave blank to keep current token' : 'Paste a new Meta access token' ?>">
                                    <button class="btn btn-outline-secondary" type="button" id="toggleApiKey"
                                        aria-label="Show or hide access token">
                                        <i data-lucide="eye"></i>
                                    </button>
                                </div>
                                <span class="small-muted">
                                    The stored token is never displayed back.
                                    <?php if ($waTokenFromEnvironment): ?>
                                    The server environment token is active and takes priority.
                                    <?php endif; ?>
                                </span>
                            </div>

                            <div class="col-lg-3">
                                <label class="form-label fw-bold">Graph API Version</label>
                                <input type="text" name="meta_whatsapp_graph_version" class="form-control"
                                    value="<?= e($metaGraphVersion) ?>" placeholder="v23.0">
                            </div>

                            <div class="col-lg-3">
                                <label class="form-label fw-bold">Template Language</label>
                                <input type="text" name="meta_whatsapp_language_code" class="form-control"
                                    value="<?= e($metaLanguageCode) ?>" placeholder="en">
                            </div>

                            <div class="col-lg-3">
                                <label class="form-label fw-bold">Verified Name</label>
                                <input type="text" class="form-control" readonly value="<?= e($metaVerifiedName) ?>"
                                    placeholder="Available after Phone ID retrieval">
                            </div>

                            <div class="col-lg-3">
                                <label class="form-label fw-bold">Quality Rating</label>
                                <input type="text" class="form-control" readonly value="<?= e($metaQualityRating) ?>"
                                    placeholder="Available after Phone ID retrieval">
                            </div>

                            <div class="col-12">
                                <div class="api-note">
                                    <strong>Important:</strong>
                                    WABA ID and Phone Number ID are different.
                                    Save the WABA ID and token first. Then retrieve the correct Phone Number ID.
                                </div>
                            </div>

                            <div class="col-12 text-end">
                                <button class="btn btn-success rounded-pill px-4 fw-bold">
                                    Save Meta WhatsApp Settings
                                </button>
                            </div>
                        </div>
                    </form>

                    <hr class="my-4">

                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                        <div>
                            <h3 class="master-title">Retrieve Phone Number ID</h3>
                            <p class="text-muted-custom mb-0">
                                Reads the phone number connected to your WABA and saves its Meta Phone Number ID.
                            </p>
                        </div>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="discover_phone_number">
                            <button class="btn btn-outline-primary rounded-pill px-4 fw-bold"
                                <?= $metaBusinessAccountId === '' || !$waAccessTokenConfigured ? 'disabled' : '' ?>>
                                Retrieve Phone Number ID
                            </button>
                        </form>
                    </div>
                </div>

                <div class="card-ui master-card mb-3">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-2 mb-4">
                        <div>
                            <h2 class="master-title">Send Template Test</h2>
                            <p class="text-muted-custom mb-0">
                                Sends the approved enquiry_completed template with four sample variables.
                            </p>
                        </div>
                        <span class="status-pill active align-self-start">enquiry_completed</span>
                    </div>

                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="test_whatsapp">
                        <div class="row g-3 align-items-end">
                            <div class="col-lg-8">
                                <label class="form-label fw-bold">Recipient WhatsApp Number</label>
                                <input type="tel" name="test_mobile" class="form-control"
                                    placeholder="Example: 919876543210" value="<?= e(st_post('test_mobile')) ?>">
                                <span class="small-muted">
                                    Use a recipient number, not the Meta business sender number. In development mode,
                                    add it as an allowed test recipient first.
                                </span>
                            </div>
                            <div class="col-lg-4 d-grid">
                                <button class="btn btn-outline-success rounded-pill fw-bold"
                                    <?= !$waConfigured || !$waEnabled ? 'disabled' : '' ?>>
                                    Send enquiry_completed Test
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="card-ui master-card mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h2 class="master-title">Integrated Meta Templates</h2>
                            <p class="text-muted-custom mb-0">
                                Exact body-variable order used by the Cloud API.
                            </p>
                        </div>
                        <span class="status-pill active"><?= count($templateDefinitions) ?> TEMPLATES</span>
                    </div>

                    <div class="table-responsive">
                        <table class="table-ui">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Template Name</th>
                                    <th>Module</th>
                                    <th>Variable Order</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $templateIndex = 1; ?>
                                <?php foreach ($templateDefinitions as $templateKey => $definition): ?>
                                <tr>
                                    <td><?= $templateIndex++ ?></td>
                                    <td><span class="template-code"><?= e($templateKey) ?></span></td>
                                    <td><?= e($definition['module'] ?? '') ?></td>
                                    <td>
                                        <span class="variable-list">
                                            <?php foreach (($definition['variables'] ?? []) as $index => $variable): ?>
                                            <?= e(($index + 1) . '. ' . $variable) ?><?= $index < count($definition['variables']) - 1 ? ' · ' : '' ?>
                                            <?php endforeach; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card-ui master-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h2 class="master-title">All System Settings</h2>
                            <p class="text-muted-custom mb-0">
                                Sensitive values are masked automatically.
                            </p>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table-ui">
                            <thead>
                                <tr>
                                    <th>Key</th>
                                    <th>Value</th>
                                    <th>Type</th>
                                    <th>Public</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($settings as $setting): ?>
                                <?php $isSecret = st_is_secret_key((string)$setting['setting_key']); ?>
                                <tr>
                                    <td>
                                        <strong><?= e($setting['setting_key']) ?></strong>
                                        <span class="small-muted"><?= e($setting['description'] ?? '') ?></span>
                                    </td>
                                    <td>
                                        <?php if ($isSecret && (string)$setting['setting_value'] !== ''): ?>
                                        <span class="secret-value">••••••••••••</span>
                                        <?php else: ?>
                                        <?= e(mb_strimwidth((string)$setting['setting_value'], 0, 80, '...')) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($setting['setting_type']) ?></td>
                                    <td>
                                        <span
                                            class="status-pill <?= (int)$setting['is_public'] === 1 ? 'active' : 'inactive' ?>">
                                            <?= (int)$setting['is_public'] === 1 ? 'Yes' : 'No' ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-primary rounded-pill fw-bold js-edit"
                                            data-bs-toggle="modal" data-bs-target="#settingModal"
                                            data-id="<?= e($setting['id']) ?>"
                                            data-key="<?= e($setting['setting_key']) ?>"
                                            data-value="<?= $isSecret ? '' : e($setting['setting_value']) ?>"
                                            data-type="<?= e($setting['setting_type']) ?>"
                                            data-description="<?= e($setting['description']) ?>"
                                            data-public="<?= e($setting['is_public']) ?>"
                                            data-secret="<?= $isSecret ? '1' : '0' ?>">
                                            Edit
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>

        <div id="settingsOverlay"></div>
        <?php include __DIR__ . '/includes/rightsidebar.php'; ?>
    </div>

    <div class="modal fade" id="settingModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <form method="post" class="modal-content" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="save_setting">
                <input type="hidden" name="id" id="id">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modalTitle">New Setting</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Setting Key *</label>
                            <input name="setting_key" id="setting_key" required class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Type</label>
                            <select name="setting_type" id="setting_type" class="form-select">
                                <option>text</option>
                                <option>number</option>
                                <option>boolean</option>
                                <option>json</option>
                                <option>file</option>
                                <option>url</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Value</label>
                            <textarea name="setting_value" id="setting_value" rows="3" class="form-control"></textarea>
                            <span id="secretHelp" class="small-muted d-none">
                                Sensitive setting: leave blank to keep the current value.
                            </span>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Description</label>
                            <textarea name="description" id="description" rows="2" class="form-control"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-check">
                                <input type="checkbox" name="is_public" id="is_public" value="1"
                                    class="form-check-input">
                                <span class="form-check-label fw-bold">Public</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-bold"
                        data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button class="btn btn-primary rounded-pill px-4 fw-bold">
                        Save Setting
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php include __DIR__ . '/includes/script.php'; ?>
    <script>
    (function() {
        function setValue(id, value) {
            const element = document.getElementById(id);
            if (!element) return;

            if (element.type === 'checkbox') {
                element.checked = String(value) === '1';
            } else {
                element.value = value || '';
            }
        }

        document.getElementById('newSettingBtn')?.addEventListener('click', function() {
            ['id', 'setting_key', 'setting_value', 'description'].forEach(function(id) {
                setValue(id, '');
            });
            setValue('setting_type', 'text');
            setValue('is_public', '0');
            document.getElementById('secretHelp')?.classList.add('d-none');
            document.getElementById('modalTitle').textContent = 'New Setting';
        });

        document.querySelectorAll('.js-edit').forEach(function(button) {
            button.addEventListener('click', function() {
                setValue('id', button.dataset.id);
                setValue('setting_key', button.dataset.key);
                setValue('setting_value', button.dataset.value);
                setValue('setting_type', button.dataset.type);
                setValue('description', button.dataset.description);
                setValue('is_public', button.dataset.public);

                const isSecret = button.dataset.secret === '1';
                document.getElementById('secretHelp')?.classList.toggle('d-none', !isSecret);
                document.getElementById('modalTitle').textContent = 'Edit Setting';
            });
        });

        document.getElementById('toggleApiKey')?.addEventListener('click', function() {
            const input = document.getElementById('meta_whatsapp_access_token');
            if (!input) return;
            input.type = input.type === 'password' ? 'text' : 'password';
        });

        if (window.lucide) {
            lucide.createIcons();
        }
    })();
    </script>
</body>

</html>