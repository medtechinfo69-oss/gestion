<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
if (!$id) {
    set_flash('error', 'Origine introuvable.');
    redirect('origines.php');
}

try {
    $stmt = $db->prepare('DELETE FROM origines WHERE id = :id');
    $stmt->execute(['id' => $id]);
    origines_valides($db, true);
    set_flash('success', 'Origine supprimée avec succès.');
} catch (Throwable $e) {
    error_log('origine_delete error: ' . $e->getMessage());
    set_flash('error', 'Impossible de supprimer cette origine. Elle est peut-être utilisée dans des dossiers.');
}

redirect('origines.php');
