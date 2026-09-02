<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
$nom = clean_str($_POST['nom'] ?? '');

if (!$id || $nom === '' || mb_strlen($nom) > 255) {
    set_flash('error', 'Données invalides. Le nom est obligatoire et doit contenir au maximum 255 caractères.');
    redirect('origines.php');
}

try {
    $checkStmt = $db->prepare('SELECT id FROM origines WHERE id = :id');
    $checkStmt->execute(['id' => $id]);
    if (!$checkStmt->fetch()) {
        set_flash('error', 'Origine introuvable.');
        redirect('origines.php');
    }

    $stmt = $db->prepare('UPDATE origines SET nom = :nom WHERE id = :id');
    $stmt->execute(['nom' => $nom, 'id' => $id]);
    origines_valides($db, true);
    set_flash('success', 'Origine modifiée avec succès.');
} catch (Throwable $e) {
    error_log('origine_edit error: ' . $e->getMessage());
    set_flash('error', 'Impossible de modifier cette origine. Elle existe peut-être déjà.');
}

redirect('origines.php');
