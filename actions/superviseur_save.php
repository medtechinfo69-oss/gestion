<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

$nomComplet = clean_str($_POST['nom_complet'] ?? '');
$username = clean_str($_POST['username'] ?? '');
$email = clean_email_input($_POST['email'] ?? '');
$password = (string) ($_POST['password'] ?? '');

$errors = [];
$emailIgnore = false;

if ($nomComplet === '' || mb_strlen($nomComplet) > 150) {
    $errors[] = 'Le nom est obligatoire.';
}

if ($username === '' || mb_strlen($username) > 60) {
    $errors[] = 'L\'identifiant est obligatoire.';
}

if ($password === '' || !password_is_strong($password)) {
    $errors[] = 'Le mot de passe doit contenir au moins 12 caractères, une majuscule, une minuscule, un chiffre et un caractère spécial.';
}

// E-mail : ne bloque PLUS la création du compte.
// - vide            -> NULL (champ optionnel)
// - plausible/valide (même accentée) -> enregistrée telle quelle
// - inexploitable   -> ignorée, avec un simple avertissement
if ($email !== '' && !email_plausible($email)) {
    $email = '';
    $emailIgnore = true;
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



    set_flash('success', 'Superviseur créé avec succès. Il devra changer le mot de passe à la première connexion.'
        . ($emailIgnore ? ' Attention : l\'adresse e-mail saisie n\'a pas été enregistrée (format non reconnu).' : ''));
} catch (Throwable $e) {
    error_log('superviseur_save error: ' . $e->getMessage());
    set_flash('error', 'Impossible de créer ce superviseur. Cet identifiant (ou cet e-mail) existe déjà.');
}

redirect('superviseurs.php');
