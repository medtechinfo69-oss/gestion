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

$newStatus = $vendeur['is_active'] ? 0 : 1;
$upd = $db->prepare('UPDATE users SET is_active = :s WHERE id = :id');
$upd->execute(['s' => $newStatus, 'id' => $id]);

set_flash('success', $newStatus
    ? 'Compte vendeur réactivé.'
    : 'Compte vendeur désactivé. Ses dossiers restent conservés et consultables par un administrateur.');
redirect('vendeurs.php');
