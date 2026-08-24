<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

$ids = $_POST['dossier_ids'] ?? [];
$validIds = [];
if (is_array($ids)) {
    foreach ($ids as $value) {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id !== false && $id > 0) {
            $validIds[] = (int) $id;
        }
    }
}
$ids = array_values(array_unique($validIds));

if (!$ids) {
    set_flash('error', 'Sélectionnez au moins un dossier.');
    redirect('dossiers.php');
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
try {
    $db->beginTransaction();
    $userId = (int) current_user()['id'];
    foreach ($ids as $id) {
        archive_dossier($db, $id, $userId);
    }
    $delete = $db->prepare("DELETE FROM dossiers WHERE id IN ($placeholders)");
    $delete->execute($ids);
    $deleted = $delete->rowCount();
    $db->commit();
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('dossier_bulk_delete error: ' . $e->getMessage());
    set_flash('error', 'Impossible de supprimer les dossiers sélectionnés.');
    redirect('dossiers.php');
}

set_flash('success', $deleted . ' dossier(s) déplacé(s) dans la corbeille.');
redirect('dossiers.php');
