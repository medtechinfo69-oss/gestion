<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

$nomComplet = clean_str($_POST['nom_complet'] ?? '');
$email = clean_str($_POST['email'] ?? '');

$errors = [];

if ($nomComplet === '' || mb_strlen($nomComplet) > 150) {
    $errors[] = 'Le nom du vendeur est obligatoire.';
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Adresse e-mail invalide.';
}

if (!$errors) {
    $baseUsername = strtolower(preg_replace('/[^a-z0-9]+/i', '.', iconv('UTF-8', 'ASCII//TRANSLIT', $nomComplet)) ?: 'vendeur');
    $baseUsername = trim($baseUsername, '.') ?: 'vendeur';
    $username = $baseUsername;
    $suffix = 2;
    while (true) {
        $chk = $db->prepare('SELECT id FROM users WHERE username = :u');
        $chk->execute(['u' => $username]);
        if (!$chk->fetch()) break;
        $username = $baseUsername . $suffix++;
    }
}

if ($errors) {
    set_flash('error', implode(' ', $errors));
    redirect('vendeurs.php');
}

$hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

try {
    $stmt = $db->prepare(
        'INSERT INTO users (username, password_hash, role, nom_complet, email, is_active, must_change_password)
         VALUES (:u, :p, "vendeur", :n, :e, 0, 0)'
    );
    $stmt->execute([
        'u' => $username, 'p' => $hash, 'n' => $nomComplet,
        'e' => $email !== '' ? $email : null,
    ]);
} catch (PDOException $e) {
    error_log('vendeur_save error: ' . $e->getMessage());
    set_flash('error', 'Impossible de créer ce compte vendeur.');
    redirect('vendeurs.php');
}

set_flash('success', 'Vendeur ajouté. Il ne peut pas se connecter à l’application.');
redirect('vendeurs.php');
