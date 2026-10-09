<?php
require_once __DIR__ . '/includes/init.php';
require_login();

// Filet de sécurité contre un téléversement partiel : app_cache_status() vit
// dans includes/functions.php. Si ce fichier n'a pas été mis à jour sur
// l'hébergement, l'appel ci-dessous provoquerait une erreur HTTP 500. On
// définit donc un substitut neutre, uniquement si la fonction manque.
if (!function_exists('app_cache_status')) {
    function app_cache_status(): array
    {
        return [
            'available' => false, 'enabled' => false, 'memory_used' => 0, 'memory_total' => 0,
            'memory_free' => 0, 'cached_scripts' => 0, 'max_cached' => 0, 'hits' => 0,
            'misses' => 0, 'hit_rate' => null,
        ];
    }
}
if (!function_exists('app_cache_state')) {
    function app_cache_state(): array
    {
        return ['actif' => false, 'dossier' => '', 'entrees' => 0, 'ko' => 0.0, 'peremption' => false];
    }
}

$user = current_user();
$force = isset($_GET['force']) && !empty($user['must_change_password']);

$pageTitle = 'Paramètres';
$pageSubtitle = $force ? 'Vous devez définir un nouveau mot de passe pour continuer' : '';
$activePage = 'profile';
$maxUploadMb = max_import_size_bytes($db) / 1024 / 1024;
$cacheStatus = app_cache_status();
$cacheDisque = app_cache_state();
// Config SMTP (source unique : carte « E-mail (SMTP) », table settings) pour pré-remplir la carte admin.
$smtpCfg = function_exists('app_smtp_config') ? app_smtp_config() : ['host' => '', 'port' => 587, 'username' => '', 'password' => '', 'encryption' => 'tls', 'from' => '', 'alert_to' => ''];
$smtpPasswordSet = function_exists('app_setting') && app_setting($db, 'smtp_password', '') !== '';
$smtpConfigured = function_exists('app_mail_configured') ? app_mail_configured() : false;
require __DIR__ . '/includes/header.php';
?>

<main class="settings-page">
  <section class="profile-hero">
    <div class="profile-avatar" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.2"/><path d="M5 19c1.4-3.2 3.7-4.8 7-4.8s5.6 1.6 7 4.8"/></svg></div>
    <div class="profile-hero-copy">
      <span class="eyebrow">Espace personnel</span>
      <h2><?= e($user['nom_complet']) ?></h2>
      <p><?= e($user['username']) ?> · <?= e(role_label((string) ($user['role'] ?? ''))) ?></p>
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
          <div class="settings-form-row"><div class="form-group"><label for="max_upload_mb">Taille maximale des fichiers (Mo)</label><input type="number" id="max_upload_mb" name="max_upload_mb" value="<?= $maxUploadMb ?>" min="1" max="100" required><div class="help-text">Limite appliquée aux imports et aux pièces jointes des dossiers.</div></div><button type="submit" class="btn btn-primary">Enregistrer les paramètres</button></div>
        </form>
      </section>
      <section class="settings-card settings-card-wide smtp-card">
        <div class="settings-card-heading smtp-head"><span class="settings-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 7l8.5 6 8.5-6"/></svg></span><div><span class="mini-title">E-mail (SMTP)</span><h3>Compte d'envoi</h3></div><span class="smtp-badge <?= $smtpConfigured ? 'ok' : 'ko' ?>"><span class="dot"></span><?= $smtpConfigured ? 'Actif' : 'À configurer' ?></span></div>
        <form action="<?= e(APP_URL) ?>/actions/profile_update.php" method="post" novalidate>
          <?= csrf_field() ?>
          <div class="smtp-grid">
            <div class="form-group"><label for="smtp_host">Serveur SMTP <span class="req">*</span></label><input type="text" id="smtp_host" name="smtp_host" value="<?= e((string) ($smtpCfg['host'] ?? '')) ?>" placeholder="smtp.gmail.com" required autocomplete="off" title="Serveur SMTP (ex. smtp.gmail.com)"></div>
            <div class="form-group"><label for="smtp_port">Port <span class="req">*</span></label><input type="number" id="smtp_port" name="smtp_port" value="<?= (int) ($smtpCfg['port'] ?? 587) ?>" min="1" max="65535" required title="587 = TLS, 465 = SSL, 25 = sans chiffrement"></div>
            <div class="form-group"><label for="smtp_encryption">Chiffrement <span class="req">*</span></label><select id="smtp_encryption" name="smtp_encryption"><option value="tls" <?= (($smtpCfg['encryption'] ?? 'tls') === 'tls') ? 'selected' : '' ?>>TLS / STARTTLS</option><option value="ssl" <?= (($smtpCfg['encryption'] ?? '') === 'ssl') ? 'selected' : '' ?>>SSL</option><option value="none" <?= (($smtpCfg['encryption'] ?? '') === 'none') ? 'selected' : '' ?>>Aucun</option></select></div>
            <div class="form-group"><label for="smtp_username">Compte / identifiant <span class="req">*</span></label><input type="email" id="smtp_username" name="smtp_username" value="<?= e((string) ($smtpCfg['username'] ?? '')) ?>" placeholder="votre-compte@gmail.com" required autocomplete="username" title="Adresse e-mail complète du compte d'envoi"></div>
            <div class="form-group"><label for="smtp_password">Mot de passe <?= $smtpPasswordSet ? '' : '<span class="req">*</span>' ?></label><div class="smtp-pass-wrap"><input type="password" id="smtp_password" name="smtp_password" placeholder="<?= $smtpPasswordSet ? '••••••••' : 'Mot de passe d’application' ?>" autocomplete="new-password" <?= $smtpPasswordSet ? '' : 'required' ?> title="Mot de passe d’application — laissez vide pour conserver"><button type="button" class="smtp-pass-toggle" data-target="smtp_password" title="Afficher / masquer">👁</button></div></div>
            <div class="form-group"><label for="smtp_from">Expéditeur (From) <span class="req">*</span></label><input type="email" id="smtp_from" name="smtp_from" value="<?= e((string) ($smtpCfg['from'] ?? '')) ?>" placeholder="votre-compte@gmail.com" required autocomplete="email"></div>
            <div class="form-group"><label for="smtp_alert_to">Notifications</label><input type="email" id="smtp_alert_to" name="smtp_alert_to" value="<?= e((string) ($smtpCfg['alert_to'] ?? '')) ?>" placeholder="destinataire@exemple.com" autocomplete="email"></div>
            <div class="form-group"><label for="smtp_test_to">E-mail de test</label><input type="email" id="smtp_test_to" name="smtp_test_to" value="" placeholder="test@exemple.com" autocomplete="email"></div>
          </div>
          <div class="smtp-actions">
            <button type="submit" class="btn btn-primary" name="save_smtp_settings" value="1">Enregistrer</button>
            <button type="submit" class="btn btn-outline" name="save_smtp_and_test" value="1">Enregistrer et tester</button>
          </div>
        </form>
        <script>
        (function () {
          var btn = document.querySelector('.smtp-pass-toggle');
          if (!btn) return;
          btn.addEventListener('click', function () {
            var input = document.getElementById(btn.getAttribute('data-target'));
            if (!input) return;
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.textContent = show ? '🙈' : '👁';
          });
        })();
        </script>
      </section>
    <?php endif; ?>

    <section class="settings-card settings-card-password">
      <div class="settings-card-heading"><span class="settings-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></span><div><span class="mini-title">Protection</span><h3>Changer le mot de passe</h3></div></div>
      <form action="<?= e(APP_URL) ?>/actions/profile_update.php" method="post" novalidate>
        <?= csrf_field() ?>
        <div class="settings-grid">
          <div class="form-group"><label for="current_password">Mot de passe actuel <span class="req">*</span></label><input type="password" id="current_password" name="current_password" autocomplete="current-password" required></div>
          <div class="form-group"><label for="new_password">Nouveau mot de passe <span class="req">*</span></label><input type="password" id="new_password" name="new_password" autocomplete="new-password" required minlength="12"><div class="help-text"></div></div>
          <div class="form-group"><label for="confirm_password">Confirmer le nouveau mot de passe <span class="req">*</span></label><input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required minlength="12"></div>
        </div>
        <div class="form-actions compact-actions"><button type="submit" class="btn btn-primary">Mettre à jour</button></div>
      </form>
    </section>

    <section class="settings-card settings-card-performance">
      <div class="settings-card-heading"><span class="settings-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/><circle cx="9" cy="6" r="2"/><circle cx="15" cy="12" r="2"/><circle cx="11" cy="18" r="2"/></svg></span><div><span class="mini-title">Performance</span><h3>Préférences de navigation</h3></div></div>
      <form action="<?= e(APP_URL) ?>/actions/profile_update.php" method="post" novalidate>
        <?= csrf_field() ?><input type="hidden" name="save_preferences" value="1">
        <div class="settings-grid"><div class="form-group"><label for="items_per_page">Éléments par page</label><select id="items_per_page" name="items_per_page"><?php foreach ([10, 25, 50, 100] as $value): ?><option value="<?= (int) $value ?>" <?= (int) user_preferences()['items_per_page'] === $value ? 'selected' : '' ?>><?= (int) $value ?></option><?php endforeach; ?></select></div><div class="form-group"><label for="session_lifetime">Durée d’inactivité</label><select id="session_lifetime" name="session_lifetime"><?php foreach ([900, 1800, 3600, 7200, 10800] as $value): ?><option value="<?= (int) $value ?>" <?= (int) user_preferences()['session_lifetime'] === $value ? 'selected' : '' ?>><?= (int) ($value / 60) ?> minutes</option><?php endforeach; ?></select></div></div>
        <div class="form-actions compact-actions"><button type="submit" class="btn btn-outline">Enregistrer</button></div>
      </form>

      <?php // Bloc CACHE : réservé EXCLUSIVEMENT a l'administrateur.
            // C'est une information d'exploitation (et un bouton d'action) :
            // inutile pour un superviseur, un vendeur ou un compte RH, qui
            // n'ont de toute facon pas le droit de vider le cache.
            // On masque donc le bloc entier, pas seulement le bouton. ?>
      <?php if (is_admin()): ?>
      <div class="perf-cache-block">
        <div class="settings-form-row">
          <div class="form-group">
            <label for="cache-state">Cache de l'application</label>
            <?php if ($cacheDisque['actif']): ?>
              <p class="help-text" id="cache-state" style="margin:0;">
                Cache <strong>actif</strong> ·
                <?= (int) $cacheDisque['entrees'] ?> entrée(s) · <?= number_format($cacheDisque['ko'], 1, ',', ' ') ?> Ko
                <?php if ($cacheDisque['peremption']): ?> · entrées périmées purgées au prochain accès<?php endif; ?>
                <?php if (!$cacheStatus['available']): ?>
                  <br>opcache n'est pas disponible sur cet hébergement : c'est ce cache applicatif qui évite les requêtes répétées.
                <?php endif; ?>
              </p>
            <?php else: ?>
              <p class="help-text" id="cache-state" style="margin:0;">
                Cache indisponible (dossier temporaire non inscriptible) : l'application reste pleinement fonctionnelle, sans cache.
              </p>
            <?php endif; ?>
            <?php if ($cacheStatus['available'] && $cacheStatus['enabled']): ?>
              <p class="help-text" style="margin:4px 0 0;">
                opcache PHP : <strong>actif</strong> · <?= number_format($cacheStatus['memory_used'] / 1048576, 1, ',', ' ') ?> Mo utilisés · <?= (int) $cacheStatus['cached_scripts'] ?> script(s) en cache<?= $cacheStatus['hit_rate'] !== null ? ' · taux ' . number_format($cacheStatus['hit_rate'], 1, ',', ' ') . ' %' : '' ?>.
              </p>
            <?php endif; ?>
            <div class="help-text">À vider après le dépôt d'une mise à jour, pour que PHP recharge immédiatement les fichiers modifiés.</div>
          </div>
          <form action="<?= e(APP_URL) ?>/actions/clear_cache.php" method="post" style="display:inline;" data-confirm="Vider le cache de l'application ?">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-outline">Vider le cache</button>
          </form>
        </div>
      </div>
      <?php endif; ?>
    </section>

  </div>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
