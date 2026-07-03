<?php
require_once __DIR__ . '/includes/auth.php';

$helperPath = __DIR__ . '/includes/whatsapp-api.php';
if (!file_exists($helperPath)) {
    die('WhatsApp helper file missing: ' . htmlspecialchars($helperPath, ENT_QUOTES, 'UTF-8'));
}

require_once $helperPath;

if (!function_exists('subhiksha_watzup_get_sent_messages')) {
    echo '<pre>';
    echo "Function subhiksha_watzup_get_sent_messages() not found.\n";
    echo "Replace this file first: /erp/includes/whatsapp-api.php\n";
    echo "Loaded helper path: " . $helperPath . "\n";
    echo '</pre>';
    exit;
}

$result = subhiksha_watzup_get_sent_messages($conn, 10, 1, [
    // Use false only on localhost when SSL certificate verification creates issues.
    'ssl_verify' => false
]);

echo '<pre>';
print_r($result);
echo '</pre>';
