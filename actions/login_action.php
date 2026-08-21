<?php
require_once __DIR__ . '/../includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('login.php');
}

csrf_require();

$username = clean_str($_POST['username'] ?? '');
$password = (string) ($_POST['password'] ?? '');
$_SESSION['old_username'] = $username;

if ($username === '' || $password === '') {
    set_flash('error', 'Veuillez renseigner votre identifiant et votre mot de passe.');
    redirect('login.php');
}

$result = attempt_login($db, $username, $password);

if (!$result['success']) {
    set_flash('error', $result['message']);
    redirect('login.php');
}

unset($_SESSION['old_username']);

if (!empty($result['user']['must_change_password'])) {
    set_flash('info', 'Pour votre sécurité, veuillez définir un nouveau mot de passe.');
    redirect('profile.php?force=1');
}

set_flash('success', 'Bienvenue, ' . $result['user']['nom_complet'] . '.');
redirect('dashboard.php');
