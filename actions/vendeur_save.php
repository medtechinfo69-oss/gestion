<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();
users_schema_ensure($db); // garantit la colonne can_supervise
$nomComplet = clean_str($_POST['nom_complet'] ?? '');
$username   = clean_str($_POST['username'] ?? '');
$email      = clean_email_input($_POST['email'] ?? '');
$password   = (string) ($_POST['password'] ?? '');
$isActive   = isset($_POST['is_active']) ? (int) (bool) $_POST['is_active'] : 1;
// TOUS les vendeurs sont désormais connectables (l'option « Contact seul » a été
// supprimée). Le drapeau can_supervise vaut donc toujours 1, quel que soit le POST.
$canSupervise = 1;

$errors = [];
$emailIgnore = false;

if ($nomComplet === '' || mb_strlen($nomComplet) > 150) {
    $errors[] = 'Le nom du vendeur est obligatoire.';
}

// E-mail : ne bloque PLUS la création (cf. superviseur_save.php).
if ($email !== '' && !email_plausible($email)) {
    $email = '';
    $emailIgnore = true;
}

// Identifiant : généré à partir du nom si vide, sinon validé.
if ($username === '') {
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
} elseif (mb_strlen($username) > 60) {
    $errors[] = 'L\'identifiant ne doit pas dépasser 60 caractères.';
}

// Mot de passe : exigé pour tout vendeur (tous sont connectables).
if ($password === '' || !password_is_strong($password)) {
    $errors[] = 'Le mot de passe du vendeur doit contenir au moins 12 caractères, une majuscule, une minuscule, un chiffre et un caractère spécial.';
}

if ($errors) {
    set_flash('error', implode(' ', $errors));
    redirect('vendeurs.php');
}

try {
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $db->prepare(
        'INSERT INTO users (username, password_hash, role, nom_complet, email, is_active, can_supervise, must_change_password, last_password_change)
         VALUES (:u, :p, \'vendeur\', :n, :e, :a, :cs, :mcp, NOW())'
    );
    $stmt->execute([
        'u'   => $username,
        'p'   => $hash,
        'n'   => $nomComplet,
        'e'   => $email !== '' ? $email : null,
        'a'   => $isActive,
        'cs'  => $canSupervise,
        'mcp' => 1,
    ]);
} catch (PDOException $e) {
    error_log('vendeur_save error: ' . $e->getMessage());
    set_flash('error', 'Impossible de créer ce compte vendeur. Cet identifiant (ou cet e-mail) existe peut-être déjà.');
    redirect('vendeurs.php');
}

$msg = 'Vendeur ajouté. Il peut se connecter : sa première connexion devra être approuvée par un administrateur.';
set_flash('success', $msg
    . ($emailIgnore ? ' Attention : l\'adresse e-mail saisie n\'a pas été enregistrée (format non reconnu).' : ''));
redirect('vendeurs.php');
