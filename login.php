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
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#1f2937">
<script>
/* Thème clair/sombre — exécuté AVANT le premier rendu (aucun flash) :
     choix mémorisé (localStorage « gd-theme »), sinon préférence système.
     Même script que includes/header.php : la page de connexion ne charge
     pas app.js, la bascule est donc déléguée au document. */
(function () {
  var root = document.documentElement;
  var THEME_KEY = 'gd-theme';
  var theme = null;
  try { theme = localStorage.getItem(THEME_KEY); } catch (e) { }
  if (theme !== 'light' && theme !== 'dark') {
    theme = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
  }
  var metaTheme = document.querySelector('meta[name="theme-color"]');
  function paint(t) {
    if (metaTheme) metaTheme.setAttribute('content', t === 'dark' ? '#0A1120' : '#F2F8FA');
  }
  root.setAttribute('data-theme', theme);
  paint(theme);
  function syncLabels() {
    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
      var dark = root.getAttribute('data-theme') === 'dark';
      btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
      btn.setAttribute('aria-label', dark ? 'Passer en mode clair' : 'Passer en mode sombre');
      btn.setAttribute('title', dark ? 'Mode clair' : 'Mode sombre');
    });
  }
  document.addEventListener('click', function (event) {
    var btn = event.target && event.target.closest ? event.target.closest('[data-theme-toggle]') : null;
    if (!btn) return;
    var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    var update = function () {
      root.setAttribute('data-theme', next);
      try { localStorage.setItem(THEME_KEY, next); } catch (e) { }
      paint(next);
      syncLabels();
    };
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!reduced && typeof document.startViewTransition === 'function') {
      /* Révélation en onde : le nouveau thème se propage en cercle depuis
         le bouton cliqué (positions stockées dans --theme-origin-*). */
      var rect = btn.getBoundingClientRect();
      root.style.setProperty('--theme-origin-x', Math.round(rect.left + rect.width / 2) + 'px');
      root.style.setProperty('--theme-origin-y', Math.round(rect.top + rect.height / 2) + 'px');
      document.startViewTransition(update);
    } else {
      update();
    }
  });
  document.addEventListener('DOMContentLoaded', syncLabels);
})();
</script>
<link rel="canonical" href="<?= e(APP_URL) ?>/login.php">
<link rel="icon" type="image/png" sizes="32x32" href="<?= e(APP_URL) ?>/assets/img/favicon-32.png">
  <link rel="apple-touch-icon" sizes="180x180" href="<?= e(APP_URL) ?>/assets/img/favicon-180.png">
<link rel="preload" as="style" href="<?= e(APP_URL) ?>/assets/css/style.css?v=<?= e(asset_version('css/style.css')) ?>">
<link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/style.css?v=<?= e(asset_version('css/style.css')) ?>">
</head>
<body class="login">
<button type="button" class="theme-toggle theme-toggle--floating" data-theme-toggle aria-pressed="false"
  aria-label="Passer en mode sombre" title="Mode sombre">
  <svg class="theme-ico theme-ico-sun" viewBox="0 0 24 24" aria-hidden="true">
    <circle cx="12" cy="12" r="4" />
    <path d="M12 2.5v2.2M12 19.3v2.2M2.5 12h2.2M19.3 12h2.2M5.3 5.3l1.5 1.5M17.2 17.2l1.5 1.5M5.3 18.7l1.5-1.5M17.2 6.8l1.5-1.5" />
  </svg>
  <svg class="theme-ico theme-ico-moon" viewBox="0 0 24 24" aria-hidden="true">
    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
  </svg>
</button>
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

