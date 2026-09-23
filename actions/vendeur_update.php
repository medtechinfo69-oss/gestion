<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
$nomComplet = clean_str($_POST['nom_complet'] ?? '');
$username   = clean_str($_POST['username'] ?? '');
$email      = clean_email_input($_POST['email'] ?? '');
$password   = (string) ($_POST['password'] ?? '');
$isActive   = isset($_POST['is_active']) ? (int) (bool) $_POST['is_active'] : 1;
// Tous les vendeurs sont connectables : le drapeau can_supervise reste à 1.
$canSupervise = 1;

if (!$id || $nomComplet === '' || mb_strlen($nomComplet) > 150) {
    set_flash('error', 'Le nom du vendeur est obligatoire.');
    redirect('vendeurs.php');
}

// Récupère l'enregistrement pour connaître l'identifiant courant et le rôle.
$cur = $db->prepare("SELECT username FROM users WHERE id = :id AND role = 'vendeur'");
$cur->execute(['id' => $id]);
$currentVendeur = $cur->fetch();
if (!$currentVendeur) {
    set_flash('error', 'Vendeur introuvable.');
    redirect('vendeurs.php');
}

// Identifiant : si vide, on conserve l'existant.
if ($username === '') {
    $username = (string) $currentVendeur['username'];
} elseif (mb_strlen($username) > 60) {
    set_flash('error', 'L\'identifiant ne doit pas dépasser 60 caractères.');
    redirect('vendeurs.php');
}

// E-mail : tolérant (cf. superviseur_update.php).
$emailIgnore = false;
if ($email !== '' && !email_plausible($email)) {
    $email = '';
    $emailIgnore = true;
}

if ($password !== '' && !password_is_strong($password)) {
    set_flash('error', 'Le mot de passe doit contenir au moins 12 caractères, une majuscule, une minuscule, un chiffre et un caractère spécial.');
    redirect('vendeurs.php');
}

// Identifiant unique ?
$chk = $db->prepare('SELECT id FROM users WHERE username = :u AND id <> :id');
$chk->execute(['u' => $username, 'id' => $id]);
if ($chk->fetch()) {
    set_flash('error', 'Cet identifiant est déjà utilisé par un autre compte.');
    redirect('vendeurs.php');
}

try {
    $params = [
        'nom'  => $nomComplet,
        'u'    => $username,
        'e'    => $email !== '' ? $email : null,
        'a'    => $isActive,
        'cs'   => $canSupervise,
        'id'   => $id,
    ];
    $sql = 'UPDATE users SET nom_complet = :nom, username = :u, email = :e, is_active = :a, can_supervise = :cs';
    if ($password !== '') {
        $sql .= ', password_hash = :p, must_change_password = 1, last_password_change = NOW()';
        $params['p'] = password_hash($password, PASSWORD_DEFAULT);
    }
    $sql .= " WHERE id = :id AND role = 'vendeur'";
    $db->prepare($sql)->execute($params);
} catch (Throwable $e) {
    error_log('vendeur_update error: ' . $e->getMessage());
    set_flash('error', 'Impossible de mettre à jour ce vendeur. L\'identifiant existe peut-être déjà.');
    redirect('vendeurs.php');
}

set_flash('success', ($password !== ''
    ? 'Vendeur mis à jour avec succès. Le mot de passe a été modifié.'
    : 'Vendeur mis à jour avec succès.')
    . ($emailIgnore ? ' Attention : l\'adresse e-mail saisie a été ignorée (format non reconnu).' : ''));
redirect('vendeurs.php');
