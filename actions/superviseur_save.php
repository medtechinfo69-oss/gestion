<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

$nomComplet = clean_str($_POST['nom_complet'] ?? '');
$username = clean_str($_POST['username'] ?? '');
$email = clean_str($_POST['email'] ?? '');
$password = (string) ($_POST['password'] ?? '');

$errors = [];

if ($nomComplet === '' || mb_strlen($nomComplet) > 150) {
    $errors[] = 'Le nom est obligatoire.';
}

if ($username === '' || mb_strlen($username) > 60) {
    $errors[] = 'L\'identifiant est obligatoire.';
}

if ($password === '' || !password_is_strong($password)) {
    $errors[] = 'Le mot de passe doit contenir au moins 10 caractères, une majuscule, une minuscule et un chiffre.';
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'L\'e-mail doit être valide si vous le renseignez.';
}

if ($errors) {
    set_flash('error', implode(' ', $errors));
    redirect('superviseurs.php');
}

try {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare(
        'INSERT INTO users (username, password_hash, role, nom_complet, email, is_active, must_change_password)
         VALUES (:u, :p, \'superviseur\', :n, :e, 1, 1)'
    );
    $stmt->execute([
        'u' => $username,
        'p' => $hash,
        'n' => $nomComplet,
        'e' => $email !== '' ? $email : null,
    ]);

    set_flash('success', 'Superviseur créé avec succès. Il devra changer le mot de passe à la première connexion.');
} catch (Throwable $e) {
    error_log('superviseur_save error: ' . $e->getMessage());
    set_flash('error', 'Impossible de créer ce superviseur. L\'identifiant existe peut-être déjà.');
}

redirect('superviseurs.php');
