<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

$rawIds = $_POST['vendeur_ids'] ?? [];
$ids = [];
if (is_array($rawIds)) {
    foreach ($rawIds as $value) {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id !== false && $id > 0) {
            $ids[] = (int) $id;
        }
    }
}
$ids = array_values(array_unique($ids));

if (!$ids) {
    set_flash('error', 'Sélectionnez au moins un vendeur.');
    redirect('vendeurs.php');
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$check = $db->prepare("SELECT u.id, u.nom_complet, COUNT(d.id) AS nb_dossiers
                       FROM users u
                       LEFT JOIN dossiers d ON d.vendeur_id = u.id
                       WHERE u.id IN ($placeholders) AND u.role = 'vendeur'
                       GROUP BY u.id, u.nom_complet");
$check->execute($ids);
$blocked = [];
$deletable = [];
foreach ($check->fetchAll() as $vendeur) {
    if ((int) $vendeur['nb_dossiers'] > 0) {
        $blocked[] = $vendeur['nom_complet'];
    } else {
        $deletable[] = (int) $vendeur['id'];
    }
}

if ($deletable) {
    $deletePlaceholders = implode(',', array_fill(0, count($deletable), '?'));
    $delete = $db->prepare("DELETE FROM users WHERE id IN ($deletePlaceholders) AND role = 'vendeur'");
    $delete->execute($deletable);
    $deleted = $delete->rowCount();
} else {
    $deleted = 0;
}

$message = $deleted . ' vendeur(s) supprimé(s).';
if ($blocked) {
    $message .= ' Impossible de supprimer : ' . implode(', ', $blocked) . ' possède(nt) encore des dossiers.';
}
set_flash($blocked ? 'error' : 'success', $message);
redirect('vendeurs.php');
