<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

$file = $_FILES['backup'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 20 * 1024 * 1024) {
    set_flash('error', 'Fichier de sauvegarde invalide ou trop volumineux.');
    redirect('profile.php');
}

try {
    $backup = json_decode(file_get_contents($file['tmp_name']), true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    set_flash('error', 'Le fichier de sauvegarde JSON est invalide.');
    redirect('profile.php');
}

$allowed = [
    'users' => ['id', 'username', 'password_hash', 'role', 'nom_complet', 'email', 'is_active', 'must_change_password', 'failed_attempts', 'locked_until', 'last_login', 'created_at', 'updated_at'],
    'dossiers' => ['id', 'vendeur_id', 'ta_origine', 'p_prod', 'date_vente', 'civilite', 'nom', 'prenom', 'mail', 'telfix', 'portable', 'nombre_personnes', 'date_naissance_assure', 'age_assure_principal', 'adresse', 'cp', 'ville', 'type_signature', 'ca_mois', 'ca_annuel', 'date_effet', 'produit', 'compagnie', 'courrier', 'date_courrier_supervision', 'etat_dossier', 'etat_contrat', 'date_etat_contrat_supervision', 'controle_qualite', 'date_controle_qualite_supervision', 'commentaire', 'motif_annulation', 'created_by', 'updated_by', 'created_at', 'updated_at'],
    'dossier_historique' => ['id', 'dossier_id', 'user_id', 'action', 'champ', 'ancienne_valeur', 'nouvelle_valeur', 'created_at'],
    'dossier_attachments' => ['id', 'dossier_id', 'nom_original', 'nom_fichier', 'type_mime', 'taille', 'uploaded_by', 'created_at'],
    'login_log' => ['id', 'username', 'ip_address', 'success', 'created_at'],
    'dossier_trash' => ['id', 'original_dossier_id', 'dossier_data', 'attachments_data', 'historique_data', 'deleted_by', 'deleted_at'],
];

if (($backup['format'] ?? '') !== 'gestion-dossiers-backup' || !is_array($backup['tables'] ?? null)) {
    set_flash('error', 'Format de sauvegarde non reconnu.');
    redirect('profile.php');
}

try {
    $db->beginTransaction();
    foreach (['users', 'dossiers', 'dossier_historique', 'dossier_attachments', 'login_log', 'dossier_trash'] as $table) {
        if (!is_array($backup['tables'][$table] ?? null)) continue;
        foreach ($backup['tables'][$table] as $row) {
            if (!is_array($row)) continue;
            $row = array_intersect_key($row, array_flip($allowed[$table]));
            if (!$row) continue;
            $columns = array_keys($row);
            $names = array_map(function ($column) { return '`' . $column . '`'; }, $columns);
            $params = array_map(function ($column) { return ':' . $column; }, $columns);
            $updates = array_map(function ($column) { return '`' . $column . '` = VALUES(`' . $column . '`)'; }, $columns);
            $sql = 'INSERT INTO `' . $table . '` (' . implode(',', $names) . ') VALUES (' . implode(',', $params) . ') ON DUPLICATE KEY UPDATE ' . implode(',', $updates);
            $stmt = $db->prepare($sql);
            $stmt->execute($row);
        }
    }
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('backup_import error: ' . $e->getMessage());
    set_flash('error', 'Import impossible. La base a été laissée dans son état précédent.');
    redirect('profile.php');
}

set_flash('success', 'Sauvegarde importée avec succès.');
redirect('profile.php');
