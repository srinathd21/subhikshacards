<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/whatsapp-api.php';

$result = subhiksha_send_whatsapp($conn, [
    'mobile' => '6383706866',
    'message' => 'Test message from Subhiksha Cards ERP',
    'related_module' => 'Test',
    'related_id' => 0,
    'ssl_verify' => false
]);

echo '<pre>';
print_r($result);
echo '</pre>';