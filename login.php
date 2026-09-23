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
<?php /* SEO — page publique mais privée : ne doit jamais être indexée. */ ?>
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
<meta name="googlebot" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
<meta name="description" content="Accès sécurisé à <?= e(APP_NAME) ?> : gestion des dossiers, des employés et des salaires.">
<meta name="referrer" content="strict-origin-when-cross-origin">
<meta name="color-scheme" content="light">
<meta name="theme-color" content="#1f2937">
<link rel="canonical" href="<?= e(APP_URL) ?>/login.php">
<link rel="icon" type="image/png" sizes="512x512" href="<?= e(APP_URL) ?>/assets/img/favicon-512x512.png">
<link rel="preload" as="style" href="<?= e(APP_URL) ?>/assets/css/style.css?v=<?= e(asset_version('css/style.css')) ?>">
<link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/style.css?v=<?= e(asset_version('css/style.css')) ?>">
</head>
<body class="login">
<div class="login-shell">
    <section class="login-brand">
      <img src="<?= e(APP_URL) ?>/assets/img/logo.JPG" alt="Logo — Gestion des dossiers">
      <h1>Gestion des dossiers</h1>
      <p>Une solution simple et professionnelle pour gérer les employés, importer les heures et suivre les salaires mensuels.</p>
    </section>
    <section class="login-box">
    <h2>Connexion</h2>
    <p>Accédez à votre espace d'administration</p>
    <?php foreach ($flashes as $flash): ?>
      <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>
    <form action="<?= e(APP_URL) ?>/actions/login_action.php" method="post" autocomplete="off" novalidate>
      <?= csrf_field() ?>
      <label for="username">Identifiant
        <input type="text" id="username" name="username" value="<?= e($oldUsername) ?>" autocomplete="username" required autofocus>
      </label>
      <label for="password">Mot de passe
        <span class="password-field">
          <input type="password" id="password" name="password" autocomplete="current-password" required>
          <button type="button" class="password-toggle" aria-label="Afficher le mot de passe" aria-pressed="false" data-password-toggle>
            <svg class="icon-eye" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
              <circle cx="12" cy="12" r="3"></circle>
            </svg>
            <svg class="icon-eye-off" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
              <line x1="1" y1="1" x2="23" y2="23"></line>
            </svg>
          </button>
        </span>
      </label>
      <button type="submit" class="btn btn-primary">Se connecter</button>
    </form>
    <div class="login-note">Accès protégé · Session sécurisée · Déconnexion automatique après inactivité</div>
    </section>
</div>
<script>
(function () {
  document.querySelectorAll('[data-password-toggle]').forEach(function (btn) {
    var input = btn.closest('.password-field').querySelector('input');
    if (!input) return;
    btn.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.classList.toggle('is-visible', show);
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      btn.setAttribute('aria-label', show ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
    });
  });
})();
</script>
</body>
</html>

