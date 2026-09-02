<?php
require_once __DIR__ . '/includes/init.php';
require_login();

$user = current_user();

// Fetch latest user data from database
$stmt = $db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $user['id']]);
$dbUser = $stmt->fetch();

echo "=== DEBUG INFO ===\n";
echo "Current User ID: " . $user['id'] . "\n";
echo "Current User Role: " . $user['role'] . "\n";
if ($dbUser) {
    echo "Database User Found: YES\n";
    echo "DB Username: " . ($dbUser['username'] ?? 'NULL') . "\n";
    echo "DB Nom Complet: " . ($dbUser['nom_complet'] ?? 'NULL') . "\n";
} else {
    echo "Database User Found: NO\n";
}
?>
