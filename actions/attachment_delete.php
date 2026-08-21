<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
$dossierId = filter_var($_POST['dossier_id'] ?? '', FILTER_VALIDATE_INT);

if (!$id || !$dossierId) {
    redirect('dossiers.php');
}

$stmt = $db->prepare('SELECT * FROM dossier_attachments WHERE id = :id AND dossier_id = :d');
$stmt->execute(['id' => $id, 'd' => $dossierId]);
$attachment = $stmt->fetch();

if (!$attachment) {
    set_flash('error', 'Pièce jointe introuvable.');
    redirect('dossier_view.php?id=' . $dossierId);
}

$del = $db->prepare('DELETE FROM dossier_attachments WHERE id = :id');
$del->execute(['id' => $id]);

$path = UPLOAD_DIR . basename($attachment['nom_fichier']);
if (is_file($path)) {
    @unlink($path);
}

log_dossier_history($db, $dossierId, current_user()['id'], 'suppression_piece_jointe', null, $attachment['nom_original'], null);

set_flash('success', 'Pièce jointe supprimée.');
redirect('dossier_view.php?id=' . $dossierId);
