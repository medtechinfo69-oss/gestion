<?php
require_once __DIR__ . '/../includes/init.php';
require_login();
csrf_require();

$user = current_user();

$current = (string) ($_POST['current_password'] ?? '');
$new = (string) ($_POST['new_password'] ?? '');
$confirm = (string) ($_POST['confirm_password'] ?? '');

$stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
$stmt->execute(['id' => $user['id']]);
$dbUser = $stmt->fetch();

if (!$dbUser || !password_verify($current, $dbUser['password_hash'])) {
    set_flash('error', 'Mot de passe actuel incorrect.');
    redirect('profile.php' . (!empty($user['must_change_password']) ? '?force=1' : ''));
}

if ($new !== $confirm) {
    set_flash('error', 'Les deux nouveaux mots de passe ne correspondent pas.');
    redirect('profile.php' . (!empty($user['must_change_password']) ? '?force=1' : ''));
}

if (!password_is_strong($new)) {
    set_flash('error', 'Le nouveau mot de passe doit contenir au moins 10 caractères, une majuscule, une minuscule et un chiffre.');
    redirect('profile.php' . (!empty($user['must_change_password']) ? '?force=1' : ''));
}

if (password_verify($new, $dbUser['password_hash'])) {
    set_flash('error', 'Le nouveau mot de passe doit être différent de l’ancien.');
    redirect('profile.php' . (!empty($user['must_change_password']) ? '?force=1' : ''));
}

$hash = password_hash($new, PASSWORD_DEFAULT);
$upd = $db->prepare('UPDATE users SET password_hash = :p, must_change_password = 0 WHERE id = :id');
$upd->execute(['p' => $hash, 'id' => $user['id']]);

$_SESSION['user']['must_change_password'] = false;

set_flash('success', 'Votre mot de passe a été mis à jour.');
redirect('dashboard.php');
