<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$ipId = (int) ($_POST['id'] ?? 0);

if ($ipId <= 0) {
    set_flash('error', 'IP invalide.');
    redirect('superviseurs.php');
}

$stmt = $db->prepare('SELECT id, user_id, ip_address FROM superviseur_approved_ips WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $ipId]);
$ip = $stmt->fetch();

if (!$ip) {
    set_flash('error', 'IP introuvable.');
    redirect('superviseurs.php');
}

$db->prepare('DELETE FROM superviseur_approved_ips WHERE id = :id')->execute(['id' => $ipId]);

set_flash('success', 'IP ' . $ip['ip_address'] . ' supprimée. Le superviseur devra redemander une approbation pour cette adresse.');
redirect('superviseurs.php');
