<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
if (!$id) {
    redirect('dossiers.php');
}

$stmt = $db->prepare('SELECT * FROM dossiers WHERE id = :id');
$stmt->execute(['id' => $id]);
$dossier = $stmt->fetch();

if (!$dossier) {
    set_flash('error', 'Dossier introuvable.');
    redirect('dossiers.php');
}

// Supprime les fichiers physiques des pièces jointes avant suppression en base
$attachStmt = $db->prepare('SELECT nom_fichier FROM dossier_attachments WHERE dossier_id = :id');
$attachStmt->execute(['id' => $id]);
$files = $attachStmt->fetchAll(PDO::FETCH_COLUMN);

try {
    $db->beginTransaction();
    // ON DELETE CASCADE supprime automatiquement les lignes attachments/historique liées
    $del = $db->prepare('DELETE FROM dossiers WHERE id = :id');
    $del->execute(['id' => $id]);
    $db->commit();
} catch (PDOException $e) {
    $db->rollBack();
    error_log('dossier_delete error: ' . $e->getMessage());
    set_flash('error', 'Impossible de supprimer ce dossier.');
    redirect('dossier_view.php?id=' . $id);
}

foreach ($files as $filename) {
    $path = UPLOAD_DIR . basename($filename);
    if (is_file($path)) {
        @unlink($path);
    }
}

set_flash('success', 'Dossier supprimé.');
redirect('dossiers.php');
