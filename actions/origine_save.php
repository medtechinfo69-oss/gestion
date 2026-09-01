<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

$nom = clean_str($_POST['nom'] ?? '');

if ($nom === '' || mb_strlen($nom) > 255) {
    set_flash('error', 'Le nom de l\'origine est obligatoire et doit contenir au maximum 255 caractères.');
    redirect('origines.php');
}

try {
    $stmt = $db->prepare('INSERT IGNORE INTO origines (nom) VALUES (:nom)');
    $stmt->execute(['nom' => $nom]);
    origines_valides($db, true);
    set_flash('success', 'Origine ajoutée avec succès.');
} catch (Throwable $e) {
    error_log('origine_save error: ' . $e->getMessage());
    if (str_contains($e->getMessage(), 'origines')) {
        set_flash('error', 'Table origines introuvable. Veuillez contacter l\'administrateur.');
        redirect('dossiers.php');
    }
    set_flash('error', 'Impossible d\'ajouter cette origine.');
}

redirect('origines.php');