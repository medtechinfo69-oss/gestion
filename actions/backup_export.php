<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$tables = ['users', 'dossiers', 'dossier_historique', 'dossier_attachments', 'login_log', 'dossier_trash'];
$backup = [
    'format' => 'gestion-dossiers-backup',
    'version' => 1,
    'created_at' => date('c'),
    'tables' => [],
];
foreach ($tables as $table) {
    $backup['tables'][$table] = $db->query('SELECT * FROM `' . $table . '`')->fetchAll();
}

$data = json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
$filename = 'gestion-dossiers-backup-' . date('Y-m-d_H-i-s') . '.json';
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($data));
echo $data;
exit;
