<?php
$pdo = new PDO('mysql:host=localhost', 'root', '');
$pdo->exec('USE gestion_dossiers');

// Set password to 'Test123456' for emma
$newPassword = password_hash('Test123456', PASSWORD_DEFAULT);
$stmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE username = :username');
$stmt->execute(['hash' => $newPassword, 'username' => 'emma']);

echo "✓ Password updated for emma. New password: Test123456\n";
?>
