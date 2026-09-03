<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
if (!$id) {
    redirect('vendeurs.php');
}

$stmt = $db->prepare("SELECT * FROM users WHERE id = :id AND role = 'vendeur'");
$stmt->execute(['id' => $id]);
$vendeur = $stmt->fetch();

if (!$vendeur) {
    set_flash('error', 'Vendeur introuvable.');
    redirect('vendeurs.php');
}

$tempPassword = generate_temp_password();
$hash = password_hash($tempPassword, PASSWORD_DEFAULT);

$upd = $db->prepare('UPDATE users SET password_hash = :p, must_change_password = 1, failed_attempts = 0, locked_until = NULL, last_password_change = NOW() WHERE id = :id');
$upd->execute(['p' => $hash, 'id' => $id]);

// Historique des mots de passe (anti-réutilisation)
if (function_exists('record_password_change')) {
    record_password_change($db, (int) $id, $hash);
}

log_security_event('PASSWORD_RESET', "Mot de passe réinitialisé pour {$vendeur['username']}");

$_SESSION['new_vendeur_creds'] = ['username' => $vendeur['username'], 'password' => $tempPassword];
set_flash('success', 'Mot de passe réinitialisé pour ' . $vendeur['nom_complet'] . '.');
redirect('vendeurs.php');
