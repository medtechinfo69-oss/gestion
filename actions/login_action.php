<?php
require_once __DIR__ . '/../includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('login.php');
}

csrf_require();

// Limitation de débit : 10 tentatives max par 15 minutes par IP
if (!rate_limit_allowed($db, 'login', 10, 900)) {
    log_security_event('BRUTEFORCE', 'Tentatives de connexion répétées bloquées');
    http_response_code(429);
    set_flash('error', 'Trop de tentatives de connexion. Veuillez patienter 15 minutes avant de réessayer.');
    redirect('login.php');
}

$username = clean_str($_POST['username'] ?? '');
$password = (string) ($_POST['password'] ?? '');
$_SESSION['old_username'] = $username;

if ($username === '' || $password === '') {
    set_flash('error', 'Veuillez renseigner votre identifiant et votre mot de passe.');
    redirect('login.php');
}

$result = attempt_login($db, $username, $password);

if (!$result['success']) {
    log_security_event('LOGIN_FAIL', "Tentative échouée pour {$username}");
    set_flash('error', $result['message']);
    redirect('login.php');
}

unset($_SESSION['old_username']);

if (!empty($result['user']['must_change_password']) || !empty($result['password_expired'])) {
    if (!empty($result['password_expired'])) {
        set_flash('info', 'Votre mot de passe a expiré. Pour votre sécurité, veuillez en définir un nouveau.');
    } else {
        set_flash('info', 'Pour votre sécurité, veuillez définir un nouveau mot de passe.');
    }
    redirect('profile.php?force=1');
}

set_flash('success', 'Bienvenue, ' . $result['user']['nom_complet'] . '.');
redirect('dashboard.php');
