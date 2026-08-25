<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

$emptyAll = !empty($_POST['empty_all']);
$trashIds = $_POST['trash_ids'] ?? [];
$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);

if ($emptyAll) {
    $stmt = $db->query('DELETE FROM dossier_trash');
    set_flash('success', 'La corbeille a été entièrement vidée.');
    redirect('corbeille.php');
}

if (is_array($trashIds) && $trashIds) {
    $cleanIds = array_filter(array_map('intval', $trashIds));
    if ($cleanIds) {
        $inQuery = implode(',', array_fill(0, count($cleanIds), '?'));
        $stmt = $db->prepare("DELETE FROM dossier_trash WHERE id IN ($inQuery)");
        $stmt->execute(array_values($cleanIds));
        set_flash('success', count($cleanIds) . ' dossier(s) supprimé(s) définitivement de la corbeille.');
        redirect('corbeille.php');
    }
}

if ($id) {
    $stmt = $db->prepare('DELETE FROM dossier_trash WHERE id = :id');
    $stmt->execute(['id' => $id]);
    set_flash($stmt->rowCount() ? 'success' : 'error', $stmt->rowCount() ? 'Dossier supprimé définitivement.' : 'Élément introuvable dans la corbeille.');
    redirect('corbeille.php');
}

redirect('corbeille.php');
