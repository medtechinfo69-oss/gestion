<?php
$pdo = new PDO('mysql:host=localhost', 'root', '');
$pdo->exec('USE gestion_dossiers');
$stmt = $pdo->query('SELECT id, username, nom_complet, role FROM users');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "=== Current Database Values ===\n";
foreach ($rows as $row) {
    echo 'ID: ' . $row['id'] . ', Username: ' . $row['username'] . ', Nom: ' . ($row['nom_complet'] ?: 'NULL') . ', Role: ' . $row['role'] . "\n";
}
?>
