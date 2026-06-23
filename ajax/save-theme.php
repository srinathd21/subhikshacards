<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');

try {
    $mode = $_POST['mode'] ?? '';
    if (!in_array($mode, ['light', 'dark'], true)) {
        throw new RuntimeException('Invalid theme mode.');
    }

    $_SESSION['theme_mode'] = $mode;
    echo json_encode(['success' => true, 'message' => 'Theme saved.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
