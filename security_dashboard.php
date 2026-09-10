<?php
require_once __DIR__ . '/includes/init.php';
require_admin();

$pageTitle = 'Surveillance de sécurité';
$pageSubtitle = 'Tableau de bord de sécurité en temps réel';
$activePage = 'security_dashboard';

$security = new SecurityDashboard($db, current_user());

// Log this access
$security->logSecurityEvent('view', 'system', null, 'Access security dashboard');

$stats = $security->getDashboardStats();
$recentEvents = $security->getRecentSecurityEvents(20);
$suspiciousActivities = $security->detectSuspiciousActivity();

require __DIR__ . '/includes/header.php';
?>

<main class="settings-page">
<div class="content-card">
  <div class="card-head">
    <h2>Votre connexion</h2>
  </div>
  <div class="security-stats-grid">
    <div class="security-stat-card">
      <div class="security-stat-value" style="font-size:1.3rem;word-break:break-all;"><?= e(get_client_ip()) ?></div>
      <div class="security-stat-label">Adresse IP du PC connecté</div>
      <div class="security-stat-detail">Adresse réelle de l'ordinateur qui consulte cette page</div>
    </div>
  </div>
</div>
<div class="content-card">
  <div class="card-head">
    <h2>Statistiques de sécurité</h2>
    <span class="muted">Dernières 24 heures</span>
  </div>
  <div class="security-stats-grid">
    <div class="security-stat-card">
      <div class="security-stat-value"><?= (int) ($stats['logins_24h']['total'] ?? 0) ?></div>
      <div class="security-stat-label">Connexions (24h)</div>
      <div class="security-stat-detail"><?= (int) ($stats['logins_24h']['successful'] ?? 0) ?> réussies, <?= (int) ($stats['logins_24h']['failed'] ?? 0) ?> échouées</div>
    </div>
    <div class="security-stat-card">
      <div class="security-stat-value"><?= (int) ($stats['security_events_24h'] ?? 0) ?></div>
      <div class="security-stat-label">Événements de sécurité</div>
      <div class="security-stat-detail">Dernières 24 heures</div>
    </div>
    <div class="security-stat-card">
      <div class="security-stat-value"><?= (int) ($stats['active_sessions'] ?? 0) ?></div>
      <div class="security-stat-label">Sessions actives</div>
      <div class="security-stat-detail">Dernières 2 heures</div>
    </div>
    <div class="security-stat-card">
      <div class="security-stat-value"><?= (int) ($stats['permission_denials_24h'] ?? 0) ?></div>
      <div class="security-stat-label">Accès refusés</div>
      <div class="security-stat-detail">Dernières 24 heures</div>
    </div>
    <div class="security-stat-card security-stat-card--warning">
      <div class="security-stat-value"><?= (int) ($stats['unresolved_events'] ?? 0) ?></div>
      <div class="security-stat-label">Événements non résolus</div>
      <div class="security-stat-detail">Nécessitent une attention</div>
    </div>
    <div class="security-stat-card security-stat-card--danger">
      <div class="security-stat-value"><?= (int) ($stats['blocked_entities'] ?? 0) ?></div>
      <div class="security-stat-label">Entités bloquées</div>
      <div class="security-stat-detail">Utilisateurs/IPs bloqués</div>
    </div>
  </div>
</div>

<?php if ($suspiciousActivities): ?>
<div class="content-card security-alert-card">
  <div class="card-head">
    <h2>⚠ Activités suspectes détectées</h2>
  </div>
  <div class="alert alert-error">
    <?php foreach ($suspiciousActivities as $activity): ?>
      <div class="security-activity-item">
        <strong><?= e(ucfirst(str_replace('_', ' ', $activity['type']))) ?></strong>
        <span class="badge badge-<?= $activity['severity'] === 'critical' ? 'danger' : ($activity['severity'] === 'high' ? 'warning' : 'info') ?>">
          <?= e($activity['severity']) ?>
        </span>
        <p><?= e($activity['description']) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
