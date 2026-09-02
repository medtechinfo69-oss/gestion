<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);

if (!$id) {
    set_flash('error', 'Superviseur introuvable.');
    redirect('superviseurs.php');
}

try {
    $stmt = $db->prepare('DELETE FROM users WHERE id = :id AND role = \'superviseur\'');
    $stmt->execute(['id' => $id]);

    set_flash('success', 'Superviseur supprimé avec succès.');
} catch (Throwable $e) {
    error_log('superviseur_delete error: ' . $e->getMessage());
    set_flash('error', 'Impossible de supprimer ce superviseur.');
}

redirect('superviseurs.php');
