<?php
/**
 * Suppression d'un compte RH (ADMINISTRATEUR uniquement).
 * Le filtre `role = 'rh'` est non négociable : impossible de supprimer un
 * administrateur, un superviseur ou un vendeur en passant par cette action.
 */
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);

if (!$id) {
    set_flash('error', 'Compte RH introuvable.');
    redirect('rh_comptes.php');
}

try {
    $stmt = $db->prepare('DELETE FROM users WHERE id = :id AND role = \'rh\'');
    $stmt->execute(['id' => $id]);

    set_flash('success', 'Compte RH supprimé avec succès.');
} catch (Throwable $e) {
    error_log('rh_delete error: ' . $e->getMessage());
    set_flash('error', 'Impossible de supprimer ce compte RH.');
}

redirect('rh_comptes.php');