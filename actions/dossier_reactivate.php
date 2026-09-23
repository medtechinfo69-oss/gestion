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

// Réinitialise les états de supervision pour que le dossier redevienne
// modifiable par le superviseur :
// - etat_dossier « Dossier complet » verrouillait le courrier (message
//   « Merci de contacter l'administrateur »).
// - etat_contrat non « Actif » verrouillait État du contrat pour le superviseur.
// Le contrôle qualité, lui, se débloque en remettant sa date de supervision à NULL.
$reinitialiseEtat = ($dossier['etat_dossier'] ?? '') === 'Dossier complet';
$nouvelEtat = $reinitialiseEtat ? 'Dossier incomplet' : ($dossier['etat_dossier'] ?? 'Dossier incomplet');
$reinitialiseContrat = ($dossier['etat_contrat'] ?? 'Actif') !== 'Actif';
$nouvelEtatContrat = 'Actif';
$reinitialiseMotif = ($dossier['motif_annulation'] ?? null) !== null && $dossier['motif_annulation'] !== '';

$db->prepare('UPDATE dossiers SET date_dossier_complet = NULL, date_contrat_non_actif = NULL,
    date_courrier_supervision = NULL, date_etat_contrat_supervision = NULL,
    date_controle_qualite_supervision = NULL, etat_dossier = :etat, etat_contrat = :etat_contrat,
    motif_annulation = NULL, updated_by = :uid WHERE id = :id')
    ->execute(['etat' => $nouvelEtat, 'etat_contrat' => $nouvelEtatContrat, 'uid' => current_user()['id'], 'id' => $id]);

log_dossier_history($db, $id, current_user()['id'], 'modification', 'date_dossier_complet', (string) $dossier['date_dossier_complet'], '');
if ($reinitialiseEtat) {
    log_dossier_history($db, $id, current_user()['id'], 'modification', 'etat_dossier', (string) $dossier['etat_dossier'], $nouvelEtat);
}
if ($reinitialiseContrat) {
    log_dossier_history($db, $id, current_user()['id'], 'modification', 'etat_contrat', (string) $dossier['etat_contrat'], $nouvelEtatContrat);
}
if ($reinitialiseMotif) {
    log_dossier_history($db, $id, current_user()['id'], 'modification', 'motif_annulation', (string) $dossier['motif_annulation'], '');
}
log_dossier_history($db, $id, current_user()['id'], 'modification', 'date_contrat_non_actif', (string) $dossier['date_contrat_non_actif'], '');
log_dossier_history($db, $id, current_user()['id'], 'modification', 'date_courrier_supervision', (string) ($dossier['date_courrier_supervision'] ?? ''), '');
log_dossier_history($db, $id, current_user()['id'], 'modification', 'date_etat_contrat_supervision', (string) ($dossier['date_etat_contrat_supervision'] ?? ''), '');
log_dossier_history($db, $id, current_user()['id'], 'modification', 'date_controle_qualite_supervision', (string) ($dossier['date_controle_qualite_supervision'] ?? ''), '');

set_flash('success', 'Dossier réactivé pour la supervision.');
redirect('dossier_view.php?id=' . $id);
