<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);

if (!$id) {
    set_flash('error', 'Vendeur introuvable.');
    redirect('vendeurs.php');
}

// Un vendeur rattaché à des dossiers ne peut pas être supprimé :
// il s'agit d'un référentiel, pas d'un utilisateur de l'application.
$check = $db->prepare('SELECT COUNT(*) FROM dossiers WHERE vendeur_id = :id');
$check->execute(['id' => $id]);
$nbDossiers = (int) $check->fetchColumn();

if ($nbDossiers > 0) {
    set_flash('error', 'Impossible de supprimer ce vendeur : il est rattaché à ' . $nbDossiers . ' dossier(s). Réaffectez ou supprimez d\'abord ces dossiers.');
    redirect('vendeurs.php');
}

try {
    $stmt = $db->prepare('DELETE FROM users WHERE id = :id AND role = \'vendeur\'');
    $stmt->execute(['id' => $id]);

    set_flash('success', 'Vendeur supprimé avec succès.');
} catch (Throwable $e) {
    error_log('vendeur_delete error: ' . $e->getMessage());
    set_flash('error', 'Impossible de supprimer ce vendeur.');
}

redirect('vendeurs.php');
