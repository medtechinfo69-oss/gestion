<?php
require_once __DIR__ . '/includes/init.php';
require_login();

$user = current_user();
$force = isset($_GET['force']) && !empty($user['must_change_password']);

$pageTitle = 'Paramètres';
$pageSubtitle = $force ? 'Vous devez définir un nouveau mot de passe pour continuer' : '';
$activePage = 'profile';
$maxUploadMb = max(1, min(100, (int) app_setting($db, 'max_upload_mb', (string) (IMPORT_MAX_SIZE / 1024 / 1024))));
require __DIR__ . '/includes/header.php';
?>

<main class="settings-page">
  <section class="profile-hero">
    <div class="profile-avatar" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.2"/><path d="M5 19c1.4-3.2 3.7-4.8 7-4.8s5.6 1.6 7 4.8"/></svg></div>
    <div class="profile-hero-copy">
      <span class="eyebrow">Espace personnel</span>
      <h2><?= e($user['nom_complet']) ?></h2>
      <p><?= e($user['username']) ?> · <?= $user['role'] === 'admin' ? 'Administrateur' : ($user['role'] === 'superviseur' ? 'Superviseur' : 'Vendeur') ?></p>
    </div>
    <span class="profile-status"><i></i> Compte actif</span>
  </section>

  <?php if ($force): ?>
    <div class="alert alert-info">Pour votre sécurité, veuillez choisir un nouveau mot de passe avant de continuer.</div>
  <?php endif; ?>

  <div class="settings-card-grid">
    <?php if (is_admin()): ?>
      <section class="settings-card settings-card-wide settings-card-warm">
        <div class="settings-card-heading"><span class="settings-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 16V4M7 9l5-5 5 5"/><path d="M5 14v4.5A1.5 1.5 0 0 0 6.5 20h11a1.5 1.5 0 0 0 1.5-1.5V14"/></svg></span><div><span class="mini-title">Configuration générale</span><h3>Réglages de l'application</h3></div></div>
        <form action="<?= e(APP_URL) ?>/actions/profile_update.php" method="post" novalidate>
          <?= csrf_field() ?><input type="hidden" name="save_app_settings" value="1">
          <div class="settings-form-row"><div class="form-group"><label for="max_upload_mb">Taille maximale d'import (Mo)</label><input type="number" id="max_upload_mb" name="max_upload_mb" value="<?= $maxUploadMb ?>" min="1" max="100" required><div class="help-text">Limite appliquée aux imports de dossiers et de salaires.</div></div><button type="submit" class="btn btn-primary">Enregistrer les paramètres</button></div>
        </form>
      </section>
    <?php endif; ?>

    <section class="settings-card settings-card-password">
      <div class="settings-card-heading"><span class="settings-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></span><div><span class="mini-title">Protection</span><h3>Changer le mot de passe</h3></div></div>
      <form action="<?= e(APP_URL) ?>/actions/profile_update.php" method="post" novalidate>
        <?= csrf_field() ?>
        <div class="form-group mb-16"><label for="current_password">Mot de passe actuel <span class="req">*</span></label><input type="password" id="current_password" name="current_password" autocomplete="current-password" required></div>
        <div class="form-group mb-16"><label for="new_password">Nouveau mot de passe <span class="req">*</span></label><input type="password" id="new_password" name="new_password" autocomplete="new-password" required minlength="12"><div class="help-text">12 caractères minimum, avec majuscule, minuscule, chiffre et caractère spécial.</div></div>
        <div class="form-group"><label for="confirm_password">Confirmer le nouveau mot de passe <span class="req">*</span></label><input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required minlength="12"></div>
        <div class="form-actions compact-actions"><button type="submit" class="btn btn-primary">Mettre à jour</button></div>
      </form>
    </section>

    <section class="settings-card">
      <div class="settings-card-heading"><span class="settings-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/><circle cx="9" cy="6" r="2"/><circle cx="15" cy="12" r="2"/><circle cx="11" cy="18" r="2"/></svg></span><div><span class="mini-title">Performance</span><h3>Préférences de navigation</h3></div></div>
      <form action="<?= e(APP_URL) ?>/actions/profile_update.php" method="post" novalidate>
        <?= csrf_field() ?><input type="hidden" name="save_preferences" value="1">
        <div class="settings-grid"><div class="form-group"><label for="items_per_page">Éléments par page</label><select id="items_per_page" name="items_per_page"><?php foreach ([10, 25, 50, 100] as $value): ?><option value="<?= (int) $value ?>" <?= (int) user_preferences()['items_per_page'] === $value ? 'selected' : '' ?>><?= (int) $value ?></option><?php endforeach; ?></select></div><div class="form-group"><label for="session_lifetime">Durée d’inactivité</label><select id="session_lifetime" name="session_lifetime"><?php foreach ([900, 1800, 3600, 7200, 10800] as $value): ?><option value="<?= (int) $value ?>" <?= (int) user_preferences()['session_lifetime'] === $value ? 'selected' : '' ?>><?= (int) ($value / 60) ?> minutes</option><?php endforeach; ?></select></div></div>
        <div class="form-actions compact-actions"><button type="submit" class="btn btn-outline">Enregistrer</button></div>
      </form>
    </section>

    <section class="settings-card settings-card-security">
      <div class="settings-card-heading"><span class="settings-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 19 6v5c0 4.5-2.8 8-7 10-4.2-2-7-5.5-7-10V6l7-3Z"/><path d="m9 12 2 2 4-4"/></svg></span><div><span class="mini-title">Sécurité</span><h3>Sécurité active</h3></div><span class="security-badge">Protégé</span></div>
      <div class="security-list"><div class="security-item"><span>Requêtes SQL préparées</span><strong>Activé</strong></div><div class="security-item"><span>Protection CSRF</span><strong>Activé</strong></div><div class="security-item"><span>Sessions HttpOnly / SameSite</span><strong>Activé</strong></div><div class="security-item"><span>Expiration automatique</span><strong><?= (int) (get_session_lifetime() / 60) ?> minutes</strong></div><div class="security-item"><span>Contrôle d'accès par rôle</span><strong>Activé</strong></div></div>
    </section>
  </div>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
