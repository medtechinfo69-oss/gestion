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
  <?php /* SEO — application privée : exclut la page des moteurs de recherche. */ ?>
  <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
  <meta name="googlebot" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
  <meta name="description" content="<?= e(APP_NAME) ?> — application privée de gestion des dossiers, des employés et des salaires.">
  <meta name="referrer" content="strict-origin-when-cross-origin">
  <meta name="color-scheme" content="light">
  <meta name="theme-color" content="#1f2937">
  <?php if (!defined('CACHE_VERSION')): ?>
    <link rel="canonical" href="<?= e(APP_URL . strtok($_SERVER['REQUEST_URI'] ?? '', '?')) ?>">
  <?php endif; ?>
  <link rel="icon" type="image/png" sizes="512x512" href="<?= e(APP_URL) ?>/assets/img/favicon-512x512.png">
  <?php /* Précharge la feuille de style : le navigateur la récupère en parallèle
           du HTML au lieu d'attendre de l'avoir entièrement analysé. */ ?>
  <link rel="preload" as="style" href="<?= e(APP_URL) ?>/assets/css/style.css?v=<?= e(asset_version('css/style.css')) ?>">
  <link rel="stylesheet"
    href="<?= e(APP_URL) ?>/assets/css/style.css?v=<?= e(asset_version('css/style.css')) ?>">
</head>

<body>
  <div class="app-shell">
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-brand">
        <div class="sidebar-brand-text">
          <div class="sidebar-brand-title"><img class="sidebar-title-icon"
              src="<?= e(APP_URL) ?>/assets/img/favicon-512x512.png" alt="" aria-hidden="true"> Gestion Prod & RH</div>
        </div>
        <button type="button" class="sidebar-collapse" id="sidebar-collapse" data-sidebar-collapse
          aria-label="Réduire le menu" aria-expanded="true" title="Réduire le menu">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M15 6l-6 6 6 6" />
          </svg>
        </button>
      </div>
      <?php if ($user): ?>
        <div class="sidebar-user">
          <div class="name">Bienvenue,</div>
          <span class="role badge-role-<?= e($user['role']) ?>" style="background:none;padding:0;">
            <?php if (is_vendeur_user()): ?>
              <?php // Vendeur connecté : on affiche son nom (ex. « Laurence ferrari »)
                    // au lieu du libellé générique « Vendeur ». ?>
              <?= e((string) ($user['nom_complet'] ?? $user['username'] ?? 'Vendeur')) ?>
            <?php else: ?>
              <?= $user['role'] === 'admin' ? 'Administrateur' : 'Superviseur' ?>
            <?php endif; ?>
          </span>
        </div>
      <?php endif; ?>

      <nav class="nav-group">
        <div class="nav-label">Gestion Prod</div>
        <a href="<?= e(APP_URL) ?>/dashboard.php" class="nav-item <?= $activePage === 'dashboard' ? 'active' : '' ?>">
          <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24">
              <path
                d="M4 4.75A.75.75 0 0 1 4.75 4h5.5a.75.75 0 0 1 .75.75v5.5a.75.75 0 0 1-.75.75h-5.5A.75.75 0 0 1 4 10.25v-5.5Zm9 0A.75.75 0 0 1 13.75 4h5.5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-.75.75h-5.5a.75.75 0 0 1-.75-.75v-3.5ZM4 14.75A.75.75 0 0 1 4.75 14h5.5a.75.75 0 0 1 .75.75v5.5a.75.75 0 0 1-.75.75h-5.5A.75.75 0 0 1 4 20.25v-5.5Zm9 0a.75.75 0 0 1 .75-.75h5.5a.75.75 0 0 1 .75.75v5.5a.75.75 0 0 1-.75.75h-5.5a.75.75 0 0 1-.75-.75v-5.5Z" />
            </svg></span> Tableau de bord
        </a>
        <a href="<?= e(APP_URL) ?>/dossiers.php"
          class="nav-item <?= in_array($activePage, ['dossiers', 'dossier_form', 'corbeille'], true) ? 'active' : '' ?>">
          <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24">
              <path
                d="M3.5 7.5A2.5 2.5 0 0 1 6 5h3.3l1.5 2H18a2.5 2.5 0 0 1 2.5 2.5v7A2.5 2.5 0 0 1 18 19H6a2.5 2.5 0 0 1-2.5-2.5v-9Z" />
              <path d="M3.5 9h17" />
            </svg></span> Dossiers
        </a>
        <div class="nav-submenu">
          <?php // « Nouveau dossier » : réservé à l'admin et au superviseur strict.
                // Un vendeur (même can_supervise = 1) ne voit que sa propre liste. ?>
          <?php if (is_admin() || is_role_superviseur()): ?>
            <a href="<?= e(APP_URL) ?>/dossier_form.php"
              class="nav-item nav-subitem <?= $activePage === 'dossier_form' ? 'active' : '' ?>">
              <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24">
                  <path d="M12 5v14M5 12h14" />
                </svg></span> Nouveau dossier
            </a>
          <?php endif; ?>
          <?php if (is_admin()): ?>
            <a href="<?= e(APP_URL) ?>/corbeille.php"
              class="nav-item nav-subitem <?= $activePage === 'corbeille' ? 'active' : '' ?>">
              <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24">
                  <path d="M4.5 7.5h15" />
                  <path d="M9 7.5V5.8A1.8 1.8 0 0 1 10.8 4h2.4A1.8 1.8 0 0 1 15 5.8v1.7" />
                  <path d="M6.8 7.5 7.6 18.2a1.8 1.8 0 0 0 1.8 1.6h5.2a1.8 1.8 0 0 0 1.8-1.6l.8-10.7" />
                </svg></span> Corbeille
            </a>
          <?php endif; ?>
        </div>
        <?php if (is_admin()): ?>
          <a href="<?= e(APP_URL) ?>/prime.php" class="nav-item <?= $activePage === 'prime' ? 'active' : '' ?>">
            <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24">
                <path d="M12 2v20M17 6h-6a3 3 0 0 0 0 6h4a3 3 0 0 1 0 6H7" />
              </svg></span> Prime
          </a>
        <?php endif; ?>
          <?php if (is_admin()): ?>
          <a href="<?= e(APP_URL) ?>/origines.php" class="nav-item <?= $activePage === 'origines' ? 'active' : '' ?>">
            <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24">
                  <path d="M5.5 4.5h8l4 4V19a1.5 1.5 0 0 1-1.5 1.5h-10A1.5 1.5 0 0 1 5.5 19V6A1.5 1.5 0 0 1 7 4.5Z" />
                  <path d="M13.5 4.5v4h4" />
                <path d="M8.5 12h7M8.5 15.5h7" />
              </svg></span> Origine
          </a>
          <?php endif; ?>
        <?php if (is_admin()): ?>
          <div class="nav-label">Gestion RH</div>
          <a href="<?= e(APP_URL) ?>/rh_dashboard.php"
            class="nav-item <?= $activePage === 'rh_dashboard' ? 'active' : '' ?>">
            <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24">
                <path
                  d="M4 4.75A.75.75 0 0 1 4.75 4h5.5a.75.75 0 0 1 .75.75v5.5a.75.75 0 0 1-.75.75h-5.5A.75.75 0 0 1 4 10.25v-5.5Zm9 0A.75.75 0 0 1 13.75 4h5.5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-.75.75h-5.5a.75.75 0 0 1-.75-.75v-3.5ZM4 14.75A.75.75 0 0 1 4.75 14h5.5a.75.75 0 0 1 .75.75v5.5a.75.75 0 0 1-.75.75h-5.5A.75.75 0 0 1 4 20.25v-5.5Zm9 0a.75.75 0 0 1 .75-.75h5.5a.75.75 0 0 1 .75.75v5.5a.75.75 0 0 1-.75.75h-5.5a.75.75 0 0 1-.75-.75v-5.5Z" />
              </svg></span> Tableau RH
          </a>
          <a href="<?= e(APP_URL) ?>/rh_employees.php"
            class="nav-item <?= $activePage === 'rh_employees' ? 'active' : '' ?>">
            <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24">
                <path d="M16.5 18.5v-1a3.5 3.5 0 0 0-3.5-3.5H9A3.5 3.5 0 0 0 5.5 17.5v1" />
                <circle cx="12" cy="8" r="3.2" />
                <path d="M18.5 10.2a2.7 2.7 0 0 1 0 5.4" />
                <path d="M5.5 10.2a2.7 2.7 0 0 0 0 5.4" />
              </svg></span> Employés
          </a>
          <a href="<?= e(APP_URL) ?>/rh_salaries.php"
            class="nav-item <?= $activePage === 'rh_salaries' ? 'active' : '' ?>">
            <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24">
                <path d="M12 1v23M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
              </svg></span> Salaires
          </a>
          <a href="<?= e(APP_URL) ?>/rh_history.php" class="nav-item <?= $activePage === 'rh_history' ? 'active' : '' ?>">
            <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24">
                <path d="M3 12a9 9 0 1 0 3-6.7" />
                <path d="M3 4v5h5" />
                <path d="M12 7v5l3 2" />
              </svg></span> Historique
          </a>
        <?php endif; ?>

        <?php if (is_admin()): ?>
          <div class="nav-label">Compte</div>
          <a href="<?= e(APP_URL) ?>/vendeurs.php" class="nav-item <?= $activePage === 'vendeurs' ? 'active' : '' ?>">
            <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24">
                <path d="M16.5 18.5v-1a3.5 3.5 0 0 0-3.5-3.5H9A3.5 3.5 0 0 0 5.5 17.5v1" />
                <circle cx="12" cy="8" r="3.2" />
                <path d="M18.5 10.2a2.7 2.7 0 0 1 0 5.4" />
                <path d="M5.5 10.2a2.7 2.7 0 0 0 0 5.4" />
              </svg></span> Vendeurs
          </a>
          
          <a href="<?= e(APP_URL) ?>/superviseurs.php"
            class="nav-item <?= $activePage === 'superviseurs' ? 'active' : '' ?>">
            <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24">
                <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12z" />
                <path d="M20 21c0-3.3-3.6-6-8-6s-8 2.7-8 6" />
              </svg></span> Superviseurs
          </a>
        <?php endif; ?>

        <div class="nav-label">Administration</div>
        <a href="<?= e(APP_URL) ?>/profile.php" class="nav-item <?= $activePage === 'profile' ? 'active' : '' ?>">
          <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24">
              <circle cx="12" cy="8" r="3.2" />
              <path d="M5 18.5c1.5-2.5 4-3.8 7-3.8s5.5 1.3 7 3.8" />
            </svg></span> Paramètres
        </a>

        <?php if (is_admin()): ?>
          <div class="nav-label">Sécurité</div>
          <a href="<?= e(APP_URL) ?>/security_dashboard.php"
            class="nav-item <?= $activePage === 'security_dashboard' ? 'active' : '' ?>">
            <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
              </svg></span> Surveillance
          </a>
          <a href="<?= e(APP_URL) ?>/repair_schema_admin.php"
            class="nav-item <?= $activePage === 'repair_schema' ? 'active' : '' ?>">
            <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24">
                <path
                  d="M20.4 6.2a5.6 5.6 0 0 1-7.4 6.8l-6.6 6.6a1.9 1.9 0 0 1-2.7-2.7l6.6-6.6a5.6 5.6 0 0 1 6.8-7.4l-2.9 2.9.6 2.6 2.6.6 3-2.2z" />
              </svg></span> Réparation BDD
          </a>
        <?php endif; ?>
      </nav>

      <div class="sidebar-footer">
        <form action="<?= e(APP_URL) ?>/actions/logout.php" method="post">
          <?= csrf_field() ?>
          <button type="submit" class="btn-logout">
            <svg class="btn-logout-ico" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M12 3v9" />
              <path d="M18.4 6.6a9 9 0 1 1-12.8 0" />
            </svg><span class="btn-logout-text"> Se déconnecter</span>
          </button>
        </form>
      </div>
    </aside>

    <div class="main">
      <header class="topbar">
        <div class="topbar-title flex gap-12" style="align-items:center;">
          <button class="menu-toggle" data-sidebar-toggle aria-label="Ouvrir le menu">&#9776;</button>
          <div>
            <h1><?= e($pageTitle) ?></h1>
            <?php if (!empty($pageSubtitle)): ?>
              <div class="subtitle"><?= e($pageSubtitle) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <?php if (is_admin()): ?>
          <?php
          $notifUnread = admin_notifications_unread_count($db);
          $notifLatest = admin_notifications_latest($db, 8);
          ?>
          <div class="notif-wrap">
            <a href="<?= e(APP_URL) ?>/notifications.php" class="notif-bell" title="Notifications de supervision" aria-label="Notifications de supervision">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22c1.1 0 2-.9 2-2h-4a2 2 0 0 0 2 2zm6-6v-5a6 6 0 0 0-4.5-5.8V4.5a1.5 1.5 0 0 0-3 0v.7A6 6 0 0 0 6 11v5l-2 2v1h16v-1l-2-2z" /></svg>
              <?php if ($notifUnread > 0): ?>
                <span class="notif-count"><?= $notifUnread > 99 ? '99+' : (int) $notifUnread ?></span>
              <?php endif; ?>
            </a>
            <div class="notif-dropdown">
              <div class="notif-dropdown-head">
                <strong>Activité des superviseurs</strong>
                <?php if ($notifUnread > 0): ?><span class="badge badge-noncomplet"><?= (int) $notifUnread ?> non lue<?= $notifUnread > 1 ? 's' : '' ?></span><?php endif; ?>
              </div>
              <?php if (!$notifLatest): ?>
                <div class="notif-empty">Aucune activité de superviseur pour le moment.</div>
              <?php else: ?>
                <?php foreach ($notifLatest as $notif): ?>
                  <a class="notif-item<?= empty($notif['is_read']) ? ' notif-unread' : '' ?>" href="<?= e(APP_URL) ?>/notifications.php">
                    <span class="notif-ico"><?= admin_notification_icon((string) ($notif['action'] ?? '')) ?></span>
                    <span class="notif-body">
                      <span class="notif-title"><?= e((string) ($notif['title'] ?? '')) ?></span>
                      <span class="notif-meta"><?= e((string) ($notif['actor_username'] ?? '')) ?> &middot; <?= e(date('d/m/Y H:i', strtotime((string) ($notif['created_at'] ?? 'now')))) ?></span>
                    </span>
                  </a>
                <?php endforeach; ?>
              <?php endif; ?>
              <a class="notif-all" href="<?= e(APP_URL) ?>/notifications.php">Tout voir &rarr;</a>
            </div>
          </div>
        <?php endif; ?>
        <div id="topbar-actions"><?= $topbarActions ?? '' ?></div>
      </header>

      <div class="content">
        <?php 
          $showDuplicatesDownload = $_SESSION['show_duplicates_download'] ?? false;
          foreach (get_flashes() as $flash): 
        ?>
          <div class="alert alert-<?= e($flash['type']) ?>" <?= ($flash['type'] !== 'error' && !($flash['type'] === 'success' && $showDuplicatesDownload)) ? 'data-autohide' : '' ?>>
            <button type="button" class="alert-close" onclick="this.parentElement.remove()"
              aria-label="Fermer">&times;</button>
            <?= e($flash['message']) ?>
            <?php if ($flash['type'] === 'success' && $showDuplicatesDownload): ?>
              <br><br>
              <a href="<?= APP_URL ?>/actions/download_duplicates.php" class="btn btn-outline btn-sm" style="display:inline-block; margin-top:8px;">
                📥 Télécharger les doublons détectés (XLSX)
              </a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if ($showDuplicatesDownload) { unset($_SESSION['show_duplicates_download']); } ?>
