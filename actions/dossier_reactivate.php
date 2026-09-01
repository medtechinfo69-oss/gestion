<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
if (!$id) {
    set_flash('error', 'Dossier introuvable.');
    redirect('dossiers.php');
}

$stmt = $db->prepare('SELECT * FROM dossiers WHERE id = :id');
$stmt->execute(['id' => $id]);
$dossier = $stmt->fetch();

if (!$dossier) {
    set_flash('error', 'Dossier introuvable.');
    redirect('dossiers.php');
}

$db->prepare('UPDATE dossiers SET date_dossier_complet = NULL, date_contrat_non_actif = NULL, updated_by = :uid WHERE id = :id')
    ->execute(['uid' => current_user()['id'], 'id' => $id]);

log_dossier_history($db, $id, current_user()['id'], 'modification', 'date_dossier_complet', (string) $dossier['date_dossier_complet'], '');
log_dossier_history($db, $id, current_user()['id'], 'modification', 'date_contrat_non_actif', (string) $dossier['date_contrat_non_actif'], '');

set_flash('success', 'Dossier réactivé pour la supervision.');
redirect('dossier_view.php?id=' . $id);
