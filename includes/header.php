<?php
/**
 * En-tête de mise en page commune à toutes les pages authentifiées.
 * Variables attendues avant inclusion :
 *   $pageTitle   (string) titre affiché dans <title> et la barre du haut
 *   $activePage  (string) identifiant de l'élément de menu actif
 *   $pageSubtitle (string, optionnel)
 */
$pageTitle = $pageTitle ?? APP_NAME;
$activePage = $activePage ?? '';
$user = current_user();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
<link rel="icon" type="image/jpeg" href="<?= e(APP_URL) ?>/assets/img/assurialis-logo.jpg">
<link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/style.css?v=<?= defined('CACHE_VERSION') ? CACHE_VERSION : (int) filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body class="<?= defined('SECURITY_MODE') && SECURITY_MODE && !is_superviseur() ? 'security-mode' : '' ?>">
<div class="app-shell">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <div class="mark">
        <img src="<?= e(APP_URL) ?>/assets/img/assurialis-logo.jpg" alt="Assurialis">
      </div>
      <div class="sidebar-brand-text">
        <div class="sidebar-brand-title">Gestion des dossiers</div>
        <div class="sidebar-brand-subtitle">Administration RH</div>
      </div>
    </div>
    <?php if ($user): ?>
    <div class="sidebar-user">
      <div class="name"><?= e($user['nom_complet']) ?></div>
      <span class="role badge-role-<?= e($user['role']) ?>" style="background:none;padding:0;">
        <?= $user['role'] === 'admin' ? 'Administrateur' : ($user['role'] === 'superviseur' ? 'Superviseur' : 'Vendeur') ?>
      </span>
    </div>
    <?php endif; ?>

    <nav class="nav-group">
      <a href="<?= e(APP_URL) ?>/dashboard.php" class="nav-item <?= $activePage === 'dashboard' ? 'active' : '' ?>">
        <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 4.75A.75.75 0 0 1 4.75 4h5.5a.75.75 0 0 1 .75.75v5.5a.75.75 0 0 1-.75.75h-5.5A.75.75 0 0 1 4 10.25v-5.5Zm9 0A.75.75 0 0 1 13.75 4h5.5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-.75.75h-5.5a.75.75 0 0 1-.75-.75v-3.5ZM4 14.75A.75.75 0 0 1 4.75 14h5.5a.75.75 0 0 1 .75.75v5.5a.75.75 0 0 1-.75.75h-5.5A.75.75 0 0 1 4 20.25v-5.5Zm9 0a.75.75 0 0 1 .75-.75h5.5a.75.75 0 0 1 .75.75v5.5a.75.75 0 0 1-.75.75h-5.5a.75.75 0 0 1-.75-.75v-5.5Z"/></svg></span> Tableau de bord
      </a>
      <a href="<?= e(APP_URL) ?>/dossiers.php" class="nav-item <?= in_array($activePage, ['dossiers', 'dossier_form', 'corbeille'], true) ? 'active' : '' ?>">
        <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3.5 7.5A2.5 2.5 0 0 1 6 5h3.3l1.5 2H18a2.5 2.5 0 0 1 2.5 2.5v7A2.5 2.5 0 0 1 18 19H6a2.5 2.5 0 0 1-2.5-2.5v-9Z"/><path d="M3.5 9h17"/></svg></span> Dossiers
      </a>
      <div class="nav-submenu">
      <?php if (is_admin() || is_superviseur()): ?>
      <a href="<?= e(APP_URL) ?>/dossier_form.php" class="nav-item nav-subitem <?= $activePage === 'dossier_form' ? 'active' : '' ?>">
        <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></span> Nouveau dossier
      </a>
      <?php endif; ?>
      <?php if (is_admin()): ?>
      <a href="<?= e(APP_URL) ?>/corbeille.php" class="nav-item nav-subitem <?= $activePage === 'corbeille' ? 'active' : '' ?>">
        <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4.5 7.5h15"/><path d="M9 7.5V5.8A1.8 1.8 0 0 1 10.8 4h2.4A1.8 1.8 0 0 1 15 5.8v1.7"/><path d="M6.8 7.5 7.6 18.2a1.8 1.8 0 0 0 1.8 1.6h5.2a1.8 1.8 0 0 0 1.8-1.6l.8-10.7"/></svg></span> Corbeille
      </a>
      <?php endif; ?>
      </div>

      <?php if (is_admin()): ?>
        <div class="nav-label">Administration</div>
        <a href="<?= e(APP_URL) ?>/vendeurs.php" class="nav-item <?= $activePage === 'vendeurs' ? 'active' : '' ?>">
          <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16.5 18.5v-1a3.5 3.5 0 0 0-3.5-3.5H9A3.5 3.5 0 0 0 5.5 17.5v1"/><circle cx="12" cy="8" r="3.2"/><path d="M18.5 10.2a2.7 2.7 0 0 1 0 5.4"/><path d="M5.5 10.2a2.7 2.7 0 0 0 0 5.4"/></svg></span> Vendeurs
        </a>
        <a href="<?= e(APP_URL) ?>/origines.php" class="nav-item <?= $activePage === 'origines' ? 'active' : '' ?>">
          <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5.5 4.5h8l4 4V19a1.5 1.5 0 0 1-1.5 1.5h-10A1.5 1.5 0 0 1 5.5 19V6A1.5 1.5 0 0 1 7 4.5Z"/><path d="M13.5 4.5v4h4"/><path d="M8.5 12h7M8.5 15.5h7"/></svg></span> Origine
        </a>
        <a href="<?= e(APP_URL) ?>/superviseurs.php" class="nav-item <?= $activePage === 'superviseurs' ? 'active' : '' ?>">
          <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12z"/><path d="M20 21c0-3.3-3.6-6-8-6s-8 2.7-8 6"/></svg></span> Superviseurs
        </a>
      <?php endif; ?>

      <?php if (is_admin()): ?>
        <div class="nav-label">Espace RH · TND</div>
        <a href="<?= e(APP_URL) ?>/rh_dashboard.php" class="nav-item <?= $activePage === 'rh_dashboard' ? 'active' : '' ?>">
          <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 4.75A.75.75 0 0 1 4.75 4h5.5a.75.75 0 0 1 .75.75v5.5a.75.75 0 0 1-.75.75h-5.5A.75.75 0 0 1 4 10.25v-5.5Zm9 0A.75.75 0 0 1 13.75 4h5.5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-.75.75h-5.5a.75.75 0 0 1-.75-.75v-3.5ZM4 14.75A.75.75 0 0 1 4.75 14h5.5a.75.75 0 0 1 .75.75v5.5a.75.75 0 0 1-.75.75h-5.5A.75.75 0 0 1 4 20.25v-5.5Zm9 0a.75.75 0 0 1 .75-.75h5.5a.75.75 0 0 1 .75.75v5.5a.75.75 0 0 1-.75.75h-5.5a.75.75 0 0 1-.75-.75v-5.5Z"/></svg></span> Tableau de bord
        </a>
        <a href="<?= e(APP_URL) ?>/rh_employees.php" class="nav-item <?= $activePage === 'rh_employees' ? 'active' : '' ?>">
          <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16.5 18.5v-1a3.5 3.5 0 0 0-3.5-3.5H9A3.5 3.5 0 0 0 5.5 17.5v1"/><circle cx="12" cy="8" r="3.2"/><path d="M18.5 10.2a2.7 2.7 0 0 1 0 5.4"/><path d="M5.5 10.2a2.7 2.7 0 0 0 0 5.4"/></svg></span> Employés
        </a>
        <a href="<?= e(APP_URL) ?>/rh_salaries.php" class="nav-item <?= $activePage === 'rh_salaries' ? 'active' : '' ?>">
          <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 1v23M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></span> Salaires mensuels
        </a>
        <a href="<?= e(APP_URL) ?>/rh_history.php" class="nav-item <?= $activePage === 'rh_history' ? 'active' : '' ?>">
          <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg></span> Historique
        </a>
        <a href="<?= e(APP_URL) ?>/rh_reports.php" class="nav-item <?= $activePage === 'rh_reports' ? 'active' : '' ?>">
          <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M18 20V10M12 20V4M6 20v-6"/></svg></span> Rapports
        </a>
      <?php endif; ?>

      <div class="nav-label">Compte</div>
      <a href="<?= e(APP_URL) ?>/profile.php" class="nav-item <?= $activePage === 'profile' ? 'active' : '' ?>">
        <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.2"/><path d="M5 18.5c1.5-2.5 4-3.8 7-3.8s5.5 1.3 7 3.8"/></svg></span> Paramètres
      </a>
    </nav>

    <div class="sidebar-footer">
      <form action="<?= e(APP_URL) ?>/actions/logout.php" method="post">
        <?= csrf_field() ?>
        <button type="submit" class="btn-logout">&#8592; Se déconnecter</button>
      </form>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <div class="flex gap-12" style="align-items:center;">
        <button class="menu-toggle" data-sidebar-toggle aria-label="Ouvrir le menu">&#9776;</button>
        <div>
          <h1><?= e($pageTitle) ?></h1>
          <?php if (!empty($pageSubtitle)): ?>
            <div class="subtitle"><?= e($pageSubtitle) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <div id="topbar-actions"><?= $topbarActions ?? '' ?></div>
    </header>

    <div class="content">
      <?php foreach (get_flashes() as $flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>" <?= $flash['type'] !== 'error' ? 'data-autohide' : '' ?>>
          <button type="button" class="alert-close" onclick="this.parentElement.remove()" aria-label="Fermer">&times;</button>
          <?= e($flash['message']) ?>
        </div>
      <?php endforeach; ?>
