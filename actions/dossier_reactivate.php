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

// Portée de la réactivation :
//  - 'all'            : tout réinitialiser (comportement historique)
//  - 'courrier'       : uniquement la section Courrier
//  - 'etat_contrat'   : uniquement la section État du contrat
//  - 'controle_qualite': uniquement la section Contrôle qualité
$scope = $_POST['scope'] ?? 'all';
if (!in_array($scope, ['all', 'courrier', 'etat_contrat', 'controle_qualite'], true)) {
    $scope = 'all';
}
$reinitialiseTout = ($scope === 'all');
$reinitialiseCourrier = $reinitialiseTout || ($scope === 'courrier');
$reinitialiseContrat = $reinitialiseTout || ($scope === 'etat_contrat');
$reinitialiseControle = $reinitialiseTout || ($scope === 'controle_qualite');

// Réinitialise l'état « Dossier complet » (nécessaire pour débloquer le Courrier).
$reinitialiseEtat = ($scope === 'courrier') && (($dossier['etat_dossier'] ?? '') === 'Dossier complet');
if ($reinitialiseTout && ($dossier['etat_dossier'] ?? '') === 'Dossier complet') {
    $reinitialiseEtat = true;
}
$nouvelEtat = $reinitialiseEtat ? 'Dossier incomplet' : ($dossier['etat_dossier'] ?? 'Dossier incomplet');
$reinitialiseContratValeur = ($dossier['etat_contrat'] ?? 'Actif') !== 'Actif' && $reinitialiseContrat;
$nouvelEtatContrat = $reinitialiseContratValeur ? 'Actif' : ($dossier['etat_contrat'] ?? 'Actif');
$reinitialiseMotif = $reinitialiseContratValeur && ($dossier['motif_annulation'] ?? null) !== null && $dossier['motif_annulation'] !== '';

$set = [
    'etat' => $nouvelEtat,
    'etat_contrat' => $nouvelEtatContrat,
    'uid' => current_user()['id'],
    'id' => $id,
    'date_dossier_complet' => $reinitialiseEtat ? null : $dossier['date_dossier_complet'],
    'date_contrat_non_actif' => $reinitialiseContratValeur ? null : $dossier['date_contrat_non_actif'],
    'date_courrier_supervision' => $reinitialiseCourrier ? null : $dossier['date_courrier_supervision'],
    'date_etat_contrat_supervision' => $reinitialiseContrat ? null : $dossier['date_etat_contrat_supervision'],
    'date_controle_qualite_supervision' => $reinitialiseControle ? null : $dossier['date_controle_qualite_supervision'],
    'motif_annulation' => $reinitialiseMotif ? null : $dossier['motif_annulation'],
];
$db->prepare('UPDATE dossiers
    SET date_dossier_complet = :date_dossier_complet,
        date_contrat_non_actif = :date_contrat_non_actif,
        date_courrier_supervision = :date_courrier_supervision,
        date_etat_contrat_supervision = :date_etat_contrat_supervision,
        date_controle_qualite_supervision = :date_controle_qualite_supervision,
        etat_dossier = :etat,
        etat_contrat = :etat_contrat,
        motif_annulation = :motif_annulation,
        updated_by = :uid
    WHERE id = :id')->execute($set);

log_dossier_history($db, $id, current_user()['id'], 'modification', 'date_dossier_complet', (string) $dossier['date_dossier_complet'], '');
if ($reinitialiseEtat) {
    log_dossier_history($db, $id, current_user()['id'], 'modification', 'etat_dossier', (string) $dossier['etat_dossier'], $nouvelEtat);
}
if ($reinitialiseContratValeur) {
    log_dossier_history($db, $id, current_user()['id'], 'modification', 'etat_contrat', (string) $dossier['etat_contrat'], $nouvelEtatContrat);
}
if ($reinitialiseMotif) {
    log_dossier_history($db, $id, current_user()['id'], 'modification', 'motif_annulation', (string) $dossier['motif_annulation'], '');
}
if ($reinitialiseCourrier) {
    log_dossier_history($db, $id, current_user()['id'], 'modification', 'date_courrier_supervision', (string) ($dossier['date_courrier_supervision'] ?? ''), '');
}
if ($reinitialiseContrat) {
    log_dossier_history($db, $id, current_user()['id'], 'modification', 'date_etat_contrat_supervision', (string) ($dossier['date_etat_contrat_supervision'] ?? ''), '');
}
if ($reinitialiseControle) {
    log_dossier_history($db, $id, current_user()['id'], 'modification', 'date_controle_qualite_supervision', (string) ($dossier['date_controle_qualite_supervision'] ?? ''), '');
}

$messagesScope = [
    'all' => 'Dossier réactivé pour la supervision.',
    'courrier' => 'Section Courrier réactivée pour la supervision.',
    'etat_contrat' => 'Section État du contrat réactivée pour la supervision.',
    'controle_qualite' => 'Section Contrôle qualité réactivée pour la supervision.',
];
set_flash('success', $messagesScope[$scope]);
redirect('dossier_view.php?id=' . $id);
