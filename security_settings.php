<?php
require_once __DIR__ . '/includes/init.php';
require_admin();

$pageTitle = 'Paramètres de sécurité';
$pageSubtitle = 'Configuration du système de sécurité';
$activePage = 'security_settings';

$security = new SecurityDashboard($db, current_user());
$security->logSecurityEvent('view', 'system', null, 'Access security settings');

$settings = $security->getSettings();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    
    $allowSupervisorExports = isset($_POST['allow_supervisor_exports']) ? 1 : 0;
    $alertOnBulkExport = isset($_POST['alert_on_bulk_export']) ? 1 : 0;
    $alertOnMultipleLogins = isset($_POST['alert_on_multiple_logins']) ? 1 : 0;
    $logSecurityEvents = isset($_POST['log_security_events']) ? 1 : 0;
    $sessionTimeout = (int) ($_POST['session_timeout'] ?? 7200);
    $maxLoginAttempts = (int) ($_POST['max_login_attempts'] ?? 5);
    $lockoutDuration = (int) ($_POST['lockout_duration'] ?? 900);
    $passwordExpiryDays = (int) ($_POST['password_expiry_days'] ?? 90);
    $passwordHistoryCount = (int) ($_POST['password_history_count'] ?? 5);
    $maxExportRows = (int) ($_POST['max_export_rows'] ?? 10000);
    $rateLimitWindow = (int) ($_POST['rate_limit_window'] ?? 900);
    $rateLimitMaxRequests = (int) ($_POST['rate_limit_max_requests'] ?? 100);
    
    $security->updateSetting('allow_supervisor_exports', $allowSupervisorExports, current_user()['id']);
    $security->updateSetting('alert_on_bulk_export', $alertOnBulkExport, current_user()['id']);
    $security->updateSetting('alert_on_multiple_logins', $alertOnMultipleLogins, current_user()['id']);
    $security->updateSetting('log_security_events', $logSecurityEvents, current_user()['id']);
    $security->updateSetting('session_timeout', $sessionTimeout, current_user()['id']);
    $security->updateSetting('max_login_attempts', $maxLoginAttempts, current_user()['id']);
    $security->updateSetting('lockout_duration', $lockoutDuration, current_user()['id']);
    $security->updateSetting('password_expiry_days', $passwordExpiryDays, current_user()['id']);
    $security->updateSetting('password_history_count', $passwordHistoryCount, current_user()['id']);
    $security->updateSetting('max_export_rows', $maxExportRows, current_user()['id']);
    $security->updateSetting('rate_limit_window', $rateLimitWindow, current_user()['id']);
    $security->updateSetting('rate_limit_max_requests', $rateLimitMaxRequests, current_user()['id']);
    
    $security->logSecurityEvent('update', 'setting', null, 'Updated security settings');
    
    $message = 'Paramètres de sécurité mis à jour avec succès.';
    
    // Clear cache
$settings = [];
foreach ($security->getSettings() as $key => $value) {
    $settings[$key] = $value;
}
}

require __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
<div class="alert alert-success" data-autohide>
  <button type="button" class="alert-close" onclick="this.parentElement.remove()" aria-label="Fermer">&times;</button>
  <?= e($message) ?>
</div>
<?php endif; ?>

<main class="settings-page">
<div class="content-card">
  <div class="card-head">
    <h2>Paramètres de sécurité</h2>
  </div>
  <form method="post">
    <?= csrf_field() ?>
    <div class="security-form-grid">
      <div class="security-section-title">Authentification</div>
      
      <label>Timeout de session (secondes)
        <input type="number" name="session_timeout" class="form-control" value="<?= e($settings['session_timeout'] ?? 7200) ?>">
      </label>
      
      <label>Tentatives de connexion max
        <input type="number" name="max_login_attempts" class="form-control" value="<?= e($settings['max_login_attempts'] ?? 5) ?>">
      </label>
      
      <label>Durée de blocage (secondes)
        <input type="number" name="lockout_duration" class="form-control" value="<?= e($settings['lockout_duration'] ?? 900) ?>">
      </label>
      
      <label>Expiration mot de passe (jours)
        <input type="number" name="password_expiry_days" class="form-control" value="<?= e($settings['password_expiry_days'] ?? 90) ?>">
      </label>
      
      <label>Historique mots de passe
        <input type="number" name="password_history_count" class="form-control" value="<?= e($settings['password_history_count'] ?? 5) ?>">
      </label>
      
      <div class="security-section-title">Limitation de débit (Rate Limiting)</div>
      
      <label>Fenêtre de limitation (secondes)
        <input type="number" name="rate_limit_window" class="form-control" value="<?= e($settings['rate_limit_window'] ?? 900) ?>">
      </label>
      
      <label>Requêtes max par fenêtre
        <input type="number" name="rate_limit_max_requests" class="form-control" value="<?= e($settings['rate_limit_max_requests'] ?? 100) ?>">
      </label>
      
      <div class="security-section-title">Exports et téléchargements</div>
      
      <label>Lignes max par export
        <input type="number" name="max_export_rows" class="form-control" value="<?= e($settings['max_export_rows'] ?? 10000) ?>">
      </label>
      
      <div class="security-form-row">
        <input type="checkbox" name="allow_supervisor_exports" value="1" id="allow_supervisor_exports" <?= $settings['allow_supervisor_exports'] ? 'checked' : '' ?>>
        <label for="allow_supervisor_exports">Autoriser les exports pour les superviseurs</label>
      </div>
      
      <div class="security-section-title">Alertes et notifications</div>
      
      <div class="security-form-row">
        <input type="checkbox" name="alert_on_bulk_export" value="1" id="alert_on_bulk_export" <?= $settings['alert_on_bulk_export'] ? 'checked' : '' ?>>
        <label for="alert_on_bulk_export">Envoyer une alerte lors d'export en masse (> 100 enregistrements)</label>
      </div>
      
      <div class="security-form-row">
        <input type="checkbox" name="alert_on_multiple_logins" value="1" id="alert_on_multiple_logins" <?= $settings['alert_on_multiple_logins'] ? 'checked' : '' ?>>
        <label for="alert_on_multiple_logins">Détecter les connexions multiples depuis IPs différentes</label>
      </div>
      
      <div class="security-form-row">
        <input type="checkbox" name="log_security_events" value="1" id="log_security_events" <?= $settings['log_security_events'] ? 'checked' : '' ?>>
        <label for="log_security_events">Journaliser tous les événements de sécurité</label>
      </div>
    </div>
    <div class="actions-row">
      <button type="submit" class="btn btn-primary">Enregistrer les paramètres</button>
    </div>
  </form>
</div>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
