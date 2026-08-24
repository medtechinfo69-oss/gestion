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
<link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/style.css">
</head>
<body>
<div class="login-shell">
  <div class="login-card">
    <div class="top">
      <div class="mark">
        <img src="<?= e(APP_URL) ?>/assets/img/assurialis-logo.jpg" alt="Assurialis">
      </div>
      <p>Plateforme de gestion des dossiers d'assurance</p>
    </div>
    <div class="body">
      <?php foreach ($flashes as $flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
      <?php endforeach; ?>

      <form action="<?= e(APP_URL) ?>/actions/login_action.php" method="post" novalidate>
        <?= csrf_field() ?>
        <div class="form-group">
          <label for="username">Identifiant</label>
          <input type="text" id="username" name="username" value="<?= e($oldUsername) ?>" autocomplete="username" required autofocus>
        </div>
        <div class="form-group">
          <label for="password">Mot de passe</label>
          <input type="password" id="password" name="password" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Se connecter</button>
      </form>

      <!-- <div class="login-demo">
        <strong>Comptes de démonstration :</strong><br>
        Admin — identifiant <span class="kbd">admin</span> / mot de passe <span class="kbd">Admin@2026</span><br>
        Superviseurs : <span class="kbd">emma</span> ou <span class="kbd">rabia</span> / mot de passe <span class="kbd">Superviseur@2026</span><br>
        <em>Un changement de mot de passe sera demandé à la première connexion.</em>
      </div> -->
    </div>
  </div>
</div>
</body>
</html>
