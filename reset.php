<?php
require_once __DIR__ . '/includes/db.php';

$password = '12345678';
$hash = password_hash($password, PASSWORD_BCRYPT);

$stmt = $pdo->prepare("
    UPDATE users
    SET 
        password_hash = :hash,
        is_active = 1
    WHERE username = :username
");

$stmt->execute([
    ':hash' => $hash,
    ':username' => 'admin'
]);

echo "Admin password reset successfully<br>";
echo "Username: admin<br>";
echo "Password: 12345678<br>";
echo "New Hash: " . htmlspecialchars($hash) . "<br>";
echo "Hash Length: " . strlen($hash);