<?php
require_once __DIR__ . '/includes/init.php';
require_admin();

$pageTitle = 'Journaux de sécurité';
$pageSubtitle = 'Historique complet des activités et événements';
$activePage = 'security_logs';

$security = new SecurityDashboard($db, current_user());
$security->logSecurityEvent('view', 'system', null, 'Access security logs');

// Filters
$userFilter = $_GET['user'] ?? '';
$actionFilter = $_GET['action'] ?? '';
$resourceFilter = $_GET['resource'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

if ($userFilter !== '') {
    $where[] = 'username LIKE :user';
    $params['user'] = '%' . $userFilter . '%';
}
if ($actionFilter !== '') {
    $where[] = 'action_type = :action';
    $params['action'] = $actionFilter;
}
if ($resourceFilter !== '') {
    $where[] = 'resource_type = :resource';
    $params['resource'] = $resourceFilter;
}
if ($dateFrom) {
    $where[] = 'DATE(created_at) >= :date_from';
    $params['date_from'] = $dateFrom;
}
if ($dateTo) {
    $where[] = 'DATE(created_at) <= :date_to';
    $params['date_to'] = $dateTo;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Count total
$countSql = "SELECT COUNT(*) FROM security_audit_log $whereSql";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$totalPages = (int) ceil($total / $perPage);

// Get logs
$sql = "SELECT * FROM security_audit_log $whereSql ORDER BY created_at DESC LIMIT $perPage OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$summaryUsers = (int) $db->query('SELECT COUNT(DISTINCT username) FROM security_audit_log')->fetchColumn();
$summaryDenied = (int) $db->query('SELECT COUNT(*) FROM security_audit_log WHERE access_granted = 0')->fetchColumn();
$summaryToday = (int) $db->query('SELECT COUNT(*) FROM security_audit_log WHERE DATE(created_at) = CURDATE()')->fetchColumn();

require __DIR__ . '/includes/header.php';
?>

<div class="content-card security-logs-page">
  <div class="card-head">
    <div>
      <h2>Journaux de sécurité</h2>
      <div class="muted">Historique complet des activités et événements</div>
    </div>
    <span class="muted"><?= $total ?> événement(s)</span>
  </div>

  <div class="security-summary-grid">
    <div class="summary-card summary-card--primary">
      <span class="summary-label">Total</span>
      <strong><?= number_format($total, 0, ',', ' ') ?></strong>
    </div>
    <div class="summary-card summary-card--secondary">
      <span class="summary-label">Utilisateurs</span>
      <strong><?= number_format($summaryUsers, 0, ',', ' ') ?></strong>
    </div>
    <div class="summary-card summary-card--warning">
      <span class="summary-label">Accès refusés</span>
      <strong><?= number_format($summaryDenied, 0, ',', ' ') ?></strong>
    </div>
    <div class="summary-card summary-card--success">
      <span class="summary-label">Aujourd’hui</span>
      <strong><?= number_format($summaryToday, 0, ',', ' ') ?></strong>
    </div>
  </div>

  <form method="get" class="filter-bar">
    <div class="filter-field">
      <label>Utilisateur</label>
      <input type="text" name="user" class="form-control" value="<?= e($userFilter) ?>" placeholder="Nom d'utilisateur">
    </div>
    <div class="filter-field">
      <label>Action</label>
      <select name="action" class="form-select">
        <option value="">Toutes</option>
        <option value="view" <?= $actionFilter === 'view' ? 'selected' : '' ?>>Vue</option>
        <option value="create" <?= $actionFilter === 'create' ? 'selected' : '' ?>>Création</option>
        <option value="update" <?= $actionFilter === 'update' ? 'selected' : '' ?>>Modification</option>
        <option value="delete" <?= $actionFilter === 'delete' ? 'selected' : '' ?>>Suppression</option>
        <option value="export" <?= $actionFilter === 'export' ? 'selected' : '' ?>>Export</option>
        <option value="download" <?= $actionFilter === 'download' ? 'selected' : '' ?>>Téléchargement</option>
        <option value="permission_denied" <?= $actionFilter === 'permission_denied' ? 'selected' : '' ?>>Accès refusé</option>
      </select>
    </div>
    <div class="filter-field">
      <label>Ressource</label>
      <select name="resource" class="form-select">
        <option value="">Toutes</option>
        <option value="dossier" <?= $resourceFilter === 'dossier' ? 'selected' : '' ?>>Dossiers</option>
        <option value="employee" <?= $resourceFilter === 'employee' ? 'selected' : '' ?>>Employés</option>
        <option value="salary" <?= $resourceFilter === 'salary' ? 'selected' : '' ?>>Salaires</option>
        <option value="user" <?= $resourceFilter === 'user' ? 'selected' : '' ?>>Utilisateurs</option>
        <option value="export" <?= $resourceFilter === 'export' ? 'selected' : '' ?>>Exports</option>
      </select>
    </div>
    <div class="filter-field">
      <label>Date début</label>
      <input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>">
    </div>
    <div class="filter-field">
      <label>Date fin</label>
      <input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>">
    </div>
    <div class="filter-actions">
      <button type="submit" class="btn btn-primary">Filtrer</button>
      <a href="security_logs.php" class="btn btn-secondary">Réinitialiser</a>
    </div>
  </form>

  <div class="table-card">
    <div class="table-wrap security-logs-table">
      <table>
        <thead>
          <tr>
            <th>Date/Heure</th>
            <th>Utilisateur</th>
            <th>Rôle</th>
            <th>Action</th>
            <th>Ressource</th>
            <th>Description</th>
            <th>IP</th>
            <th>Accès</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $log): ?>
          <tr class="data-row">
            <td class="nowrap"><?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?></td>
            <td><?= e($log['username']) ?></td>
            <td><span class="badge badge-<?= $log['user_role'] === 'admin' ? 'danger' : ($log['user_role'] === 'superviseur' ? 'warning' : 'info') ?>"><?= e($log['user_role']) ?></span></td>
            <td><span class="action-pill action-pill--<?= e($log['action_type']) ?>"><?= e($log['action_type']) ?></span></td>
            <td><?= e($log['resource_type']) ?></td>
            <td class="description-cell"><?= e($log['resource_description'] ?? '-') ?></td>
            <td class="mono"><?= e($log['ip_address']) ?></td>
            <td>
              <?php if ($log['access_granted']): ?>
                <span class="badge badge-success">✓ Autorisé</span>
              <?php else: ?>
                <span class="badge badge-danger">✗ Refusé</span>
                <div class="security-denial-reason"><?= e($log['denial_reason']) ?></div>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$logs): ?>
          <tr>
            <td colspan="8">
              <div class="empty-state">
                <div class="empty-icon">&#128274;</div>
                Aucun journal trouvé pour ces critères.
              </div>
            </td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($totalPages > 1): ?>
  <div class="pagination">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
      <?php if ($i === $page): ?>
        <span class="current"><?= $i ?></span>
      <?php else: ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
      <?php endif; ?>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
