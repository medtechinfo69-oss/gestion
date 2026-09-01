<?php
require_once __DIR__ . '/includes/init.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$flashes = get_flashes();
$oldUsername = $_SESSION['old_username'] ?? '';
unset($_SESSION['old_username']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Connexion · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/style.css?v=<?= APP_ENV === 'development' ? time() : (int) filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body class="login">
<div class="login-shell">
    <section class="login-brand">
      <img src="<?= e(APP_URL) ?>/assets/img/assurialis-logo.jpg" alt="Assurialis">
      <h1>Gestion des dossiers</h1>
      <p>Une solution simple et professionnelle pour gérer les employés, importer les heures et suivre les salaires mensuels.</p>
    </section>
    <section class="login-box">
    <h2>Connexion</h2>
    <p>Accédez à votre espace d'administration RH.</p>
    <?php foreach ($flashes as $flash): ?>
      <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>
    <form action="<?= e(APP_URL) ?>/actions/login_action.php" method="post" autocomplete="off" novalidate>
      <?= csrf_field() ?>
      <label for="username">Identifiant
        <input type="text" id="username" name="username" value="<?= e($oldUsername) ?>" autocomplete="username" required autofocus>
      </label>
      <label for="password">Mot de passe
        <input type="password" id="password" name="password" autocomplete="current-password" required>
      </label>
      <button type="submit" class="btn btn-primary">Se connecter</button>
    </form>
    <div class="login-note">Accès protégé · Session sécurisée · Déconnexion automatique après inactivité</div>
    </section>
</div>
</body>
</html>
