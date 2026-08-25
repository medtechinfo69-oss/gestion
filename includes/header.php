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
<link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/style.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body class="<?= defined('SECURITY_MODE') && SECURITY_MODE ? 'security-mode' : '' ?>">
<div class="app-shell">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <div class="mark">
        <img src="<?= e(APP_URL) ?>/assets/img/assurialis-logo.jpg" alt="Assurialis">
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
        <span class="ico">&#128202;</span> Tableau de bord
      </a>
      <a href="<?= e(APP_URL) ?>/dossiers.php" class="nav-item <?= $activePage === 'dossiers' ? 'active' : '' ?>">
        <span class="ico">&#128193;</span> Dossiers
      </a>
      <?php if (is_admin()): ?>
      <a href="<?= e(APP_URL) ?>/dossier_form.php" class="nav-item <?= $activePage === 'dossier_form' ? 'active' : '' ?>">
        <span class="ico">&#10010;</span> Nouveau dossier
      </a>
      <?php endif; ?>
      <a href="<?= e(APP_URL) ?>/annulation.php" class="nav-item <?= $activePage === 'annulation' ? 'active' : '' ?>">
        <span class="ico">&#9888;</span> Dossiers annulés
      </a>

      <?php if (is_admin()): ?>
        <div class="nav-label">Administration</div>
        <a href="<?= e(APP_URL) ?>/vendeurs.php" class="nav-item <?= $activePage === 'vendeurs' ? 'active' : '' ?>">
          <span class="ico ico-vendeur" aria-hidden="true"></span> Vendeurs
        </a>
        <a href="<?= e(APP_URL) ?>/corbeille.php" class="nav-item <?= $activePage === 'corbeille' ? 'active' : '' ?>">
          <span class="ico">&#128465;</span> Corbeille
        </a>
      <?php endif; ?>

      <div class="nav-label">Compte</div>
      <a href="<?= e(APP_URL) ?>/profile.php" class="nav-item <?= $activePage === 'profile' ? 'active' : '' ?>">
        <span class="ico">&#9881;</span> Mon profil
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
