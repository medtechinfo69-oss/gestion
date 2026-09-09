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
    <div class="security-stat-card">
      <div class="security-stat-value" style="font-size:1.3rem;word-break:break-all;"><?= e($_SERVER['SERVER_ADDR'] ?? '127.0.0.1') ?></div>
      <div class="security-stat-label">Adresse IP du serveur</div>
      <div class="security-stat-detail">Machine hébergeant l'application</div>
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

<div class="content-card">
  <div class="card-head">
    <h2>Événements récents</h2>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Date/Heure</th>
          <th>Utilisateur</th>
          <th>Action</th>
          <th>Ressource</th>
          <th>Description</th>
          <th>IP</th>
          <th>Accès</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recentEvents as $event): ?>
        <tr class="data-row">
          <td><?= date('d/m/Y H:i:s', strtotime($event['created_at'])) ?></td>
          <td><?= e($event['username']) ?></td>
          <td><span class="badge badge-<?= $event['action_type'] === 'permission_denied' ? 'danger' : ($event['action_type'] === 'export' ? 'warning' : 'info') ?>">
            <?= e($event['action_type']) ?>
          </span></td>
          <td><?= e($event['resource_type']) ?></td>
          <td><?= e($event['resource_description'] ?? $event['request_url']) ?></td>
          <td><?= e($event['ip_address']) ?></td>
          <td>
            <?php if ($event['access_granted']): ?>
              <span class="badge badge-success">Autorisé</span>
            <?php else: ?>
              <span class="badge badge-danger">Refusé</span>
              <div style="font-size: 12px; color: #666;"><?= e($event['denial_reason']) ?></div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$recentEvents): ?>
        <tr>
          <td colspan="7">
            <div class="empty-state">
              <div class="empty-icon">&#128274;</div>
              Aucun événement de sécurité récent.
            </div>
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
