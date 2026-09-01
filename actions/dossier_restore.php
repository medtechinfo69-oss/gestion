<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

$trashId = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
if (!$trashId) {
    redirect('corbeille.php');
}

$stmt = $db->prepare('SELECT * FROM dossier_trash WHERE id = :id');
$stmt->execute(['id' => $trashId]);
$item = $stmt->fetch();
if (!$item) {
    set_flash('error', 'Élément introuvable dans la corbeille.');
    redirect('corbeille.php');
}

$dossier = json_decode($item['dossier_data'], true);
$attachments = json_decode($item['attachments_data'], true);
$historique = json_decode($item['historique_data'], true);
if (!is_array($dossier) || !is_array($attachments) || !is_array($historique)) {
    set_flash('error', 'Les données de ce dossier sont invalides.');
    redirect('corbeille.php');
}

$dossierColumns = ['id', 'vendeur_id', 'ta_origine', 'p_prod', 'date_vente', 'civilite', 'nom', 'prenom', 'mail', 'telfix', 'portable', 'nombre_personnes', 'date_naissance_assure', 'age_assure_principal', 'adresse', 'cp', 'ville', 'type_signature', 'ca_mois', 'ca_annuel', 'date_effet', 'produit', 'compagnie', 'courrier', 'etat_dossier', 'date_dossier_complet', 'etat_contrat', 'date_contrat_non_actif', 'commentaire', 'motif_annulation', 'created_by', 'updated_by', 'created_at', 'updated_at'];
$dossier = array_intersect_key($dossier, array_flip($dossierColumns));

try {
    $db->beginTransaction();
    $columns = array_keys($dossier);
    $placeholders = array_map(function ($column) { return ':' . $column; }, $columns);
    $insert = $db->prepare('INSERT INTO dossiers (' . implode(',', $columns) . ') VALUES (' . implode(',', $placeholders) . ')');
    $insert->execute(array_combine($placeholders, array_values($dossier)));

    foreach ($attachments as $attachment) {
        $attachment = array_intersect_key($attachment, array_flip(['nom_original', 'nom_fichier', 'type_mime', 'taille', 'uploaded_by', 'created_at']));
        if (!$attachment) continue;
        $attachment['dossier_id'] = $dossier['id'];
        $columns = array_keys($attachment);
        $placeholders = array_map(function ($column) { return ':' . $column; }, $columns);
        $stmt = $db->prepare('INSERT INTO dossier_attachments (' . implode(',', $columns) . ') VALUES (' . implode(',', $placeholders) . ')');
        $stmt->execute(array_combine($placeholders, array_values($attachment)));
    }

    foreach ($historique as $entry) {
        $entry = array_intersect_key($entry, array_flip(['user_id', 'action', 'champ', 'ancienne_valeur', 'nouvelle_valeur', 'created_at']));
        if (!$entry) continue;
        $entry['dossier_id'] = $dossier['id'];
        $columns = array_keys($entry);
        $placeholders = array_map(function ($column) { return ':' . $column; }, $columns);
        $stmt = $db->prepare('INSERT INTO dossier_historique (' . implode(',', $columns) . ') VALUES (' . implode(',', $placeholders) . ')');
        $stmt->execute(array_combine($placeholders, array_values($entry)));
    }

    $stmt = $db->prepare('DELETE FROM dossier_trash WHERE id = :id');
    $stmt->execute(['id' => $trashId]);
    $db->commit();
} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('dossier_restore error: ' . $e->getMessage());
    set_flash('error', 'Impossible de restaurer ce dossier. Vérifiez qu’il n’existe pas déjà.');
    redirect('corbeille.php');
}

set_flash('success', 'Dossier restauré avec succès.');
redirect('dossier_view.php?id=' . (int) $dossier['id']);
