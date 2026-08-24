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

try {
    $db->beginTransaction();
    archive_dossier($db, $id, (int) current_user()['id']);
    $del = $db->prepare('DELETE FROM dossiers WHERE id = :id');
    $del->execute(['id' => $id]);
    $db->commit();
} catch (PDOException $e) {
    $db->rollBack();
    error_log('dossier_delete error: ' . $e->getMessage());
    set_flash('error', 'Impossible de supprimer ce dossier.');
    redirect('dossier_view.php?id=' . $id);
}

set_flash('success', 'Dossier déplacé dans la corbeille.');
redirect('dossiers.php');
