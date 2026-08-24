<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
$nomComplet = clean_str($_POST['nom_complet'] ?? '');
$email = clean_str($_POST['email'] ?? '');

if (!$id || $nomComplet === '' || mb_strlen($nomComplet) > 150 || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    set_flash('error', 'Le nom et une adresse e-mail valide sont obligatoires.');
    redirect('vendeurs.php' . ($id ? '?edit=' . $id : ''));
}

$stmt = $db->prepare("UPDATE users SET nom_complet = :nom, email = :email WHERE id = :id AND role = 'vendeur'");
$stmt->execute(['nom' => $nomComplet, 'email' => $email, 'id' => $id]);

if (!$stmt->rowCount()) {
    $check = $db->prepare("SELECT id FROM users WHERE id = :id AND role = 'vendeur'");
    $check->execute(['id' => $id]);
    if (!$check->fetch()) {
        set_flash('error', 'Vendeur introuvable.');
        redirect('vendeurs.php');
    }
}

set_flash('success', 'Vendeur modifié avec succès.');
redirect('vendeurs.php');
