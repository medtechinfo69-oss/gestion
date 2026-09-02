<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

$rawIds = $_POST['origine_ids'] ?? [];
$ids = [];
if (is_array($rawIds)) {
    foreach ($rawIds as $value) {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id !== false && $id > 0) {
            $ids[] = (int) $id;
        }
    }
}
$ids = array_values(array_unique($ids));

if (!$ids) {
    set_flash('error', 'Sélectionnez au moins une origine.');
    redirect('origines.php');
}

try {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("DELETE FROM origines WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    origines_valides($db, true);
    set_flash('success', count($ids) . ' origine(s) supprimée(s) avec succès.');
} catch (Throwable $e) {
    error_log('origine_bulk_delete error: ' . $e->getMessage());
    set_flash('error', 'Impossible de supprimer les origines sélectionnées.');
}

redirect('origines.php');
