<?php
require_once __DIR__ . '/includes/init.php';
require_admin_or_superviseur();

$pageTitle = 'Employés';
$pageSubtitle = 'Ensemble des salariés enregistrés';
$activePage = 'rh_employees';
$pdo = $db;
$isAdmin = is_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_once __DIR__ . '/actions/rh_employee_action.php';
  exit;
}

$edit = null;
if (isset($_GET['edit'])) {
  $stmt = $pdo->prepare('SELECT * FROM employees WHERE id=:id');
  $stmt->execute(['id' => (int) $_GET['edit']]);
  $edit = $stmt->fetch();
}

$q = trim((string) ($_GET['q'] ?? ''));
$status = $_GET['status'] ?? '';
$position = $_GET['position'] ?? '';
$sort = ($_GET['sort'] ?? '') === 'matricule' ? 'matricule' : 'name';
$direction = strtoupper((string) ($_GET['direction'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

$sql = 'SELECT * FROM employees WHERE 1=1';
$args = [];
if ($q !== '') {
  $sql .= ' AND (full_name LIKE :q OR employee_code LIKE :q OR position LIKE :q)';
  $args['q'] = '%' . $q . '%';
}
if (in_array($status, ['Active', 'Inactive'], true)) {
  $sql .= ' AND status=:status';
  $args['status'] = $status;
}
if (in_array($position, ['Agent', 'Responsable'], true)) {
  $sql .= ' AND position=:position';
  $args['position'] = $position;
}
$sql .= $sort === 'matricule'
  ? ' ORDER BY employee_code ' . $direction
  : ' ORDER BY full_name ASC';

$nextDirection = ($sort === 'matricule' && $direction === 'ASC') ? 'DESC' : 'ASC';
$matriculeSortUrl = '?' . http_build_query([
  'q' => $q,
  'status' => $status,
  'position' => $position,
  'sort' => 'matricule',
  'direction' => $nextDirection,
]);

$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<div class="content-card">
  <div class="card-head">
    <h2>Employés</h2>
    <div class="d-flex gap-8 flex-wrap">
      <a class="btn btn-primary btn-sm" href="?new=1">+ Nouvel employé</a>
    </div>
  </div>
  <div class="table-wrap" style="padding:0 0 0;">
    <div class="filter-card" style="border:none;border-bottom:1px solid var(--color-line);border-radius:0;margin:0;">
      <form class="row gap-3">
        <div class="col-md-3">
          <label class="form-label">Recherche</label>
          <input name="q" class="form-control" value="<?= e($q) ?>" placeholder="Nom, matricule, poste...">
        </div>
        <div class="col-md-3">
          <label class="form-label">État</label>
          <select name="status" class="form-select">
            <option value="">Tous</option>
            <option value="Active" <?= $status === 'Active' ? 'selected' : '' ?>>Actif</option>
            <option value="Inactive" <?= $status === 'Inactive' ? 'selected' : '' ?>>Inactif</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Poste</label>
          <select name="position" class="form-select">
            <option value="">Tous</option>
            <option value="Agent" <?= $position === 'Agent' ? 'selected' : '' ?>>Agent</option>
            <option value="Responsable" <?= $position === 'Responsable' ? 'selected' : '' ?>>Responsable</option>
          </select>
        </div>
        <div class="col-md-3 d-flex align-items-end">
          <button type="submit" class="btn btn-light w-100">Filtrer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($edit || isset($_GET['new'])): ?>
<div class="form-card">
  <div class="card-head"><h2><?= $edit ? 'Modifier l\'employé' : 'Nouvel employé' ?></h2></div>
  <form method="post" action="actions/rh_employee_action.php">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">
    <div class="form-grid">
      <label>Matricule<input class="form-control" name="employee_code" required value="<?= e($edit['employee_code'] ?? '') ?>"></label>
      <label>Nom & prénom<input class="form-control" name="full_name" required value="<?= e($edit['full_name'] ?? '') ?>"></label>
      <label>Pseudo<input class="form-control" name="pseudo" value="<?= e($edit['pseudo'] ?? '') ?>"></label>
      <label>Poste<select class="form-control" name="position" required><option value="Agent" <?= ($edit['position'] ?? 'Agent') === 'Agent' ? 'selected' : '' ?>>Agent</option><option value="Responsable" <?= ($edit['position'] ?? '') === 'Responsable' ? 'selected' : '' ?>>Responsable</option></select></label>
          <label>Régime horaire (TND / h)<input class="form-control" name="hourly_rate" type="number" step="0.01" min="0" required value="<?= e($edit['hourly_rate'] ?? '') ?>"></label>
      <label>Statut<select class="form-control" name="status">
        <option value="Active" <?= ($edit['status'] ?? 'Active') === 'Active' ? 'selected' : '' ?>>Actif</option>
        <option value="Inactive" <?= ($edit['status'] ?? '') === 'Inactive' ? 'selected' : '' ?>>Inactif</option>
      </select></label>
    </div>
    <div class="actions-row">
      <a class="btn btn-secondary" href="rh_employees.php">Annuler</a>
      <button type="submit" class="btn btn-primary">Enregistrer</button>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="content-card">
  <div class="card-head">
    <h2>Liste des employés</h2>
    <span class="muted"><?= count($rows) ?> résultat(s)</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th><a href="<?= e($matriculeSortUrl) ?>" class="table-sort-link">Matricule <?= $sort === 'matricule' ? ($direction === 'ASC' ? '&#9650;' : '&#9660;') : '&#8597;' ?></a></th>
          <th>Nom complet</th>
          <th>Poste</th>
          <th>Régime horaire</th>
          <th>Statut</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr class="data-row">
          <td><b><?= e($r['employee_code']) ?></b></td>
          <td><?= e($r['full_name']) ?></td>
          <td><?= e($r['position'] ?: '—') ?></td>
          <td><?= format_montant_tnd((float) $r['hourly_rate']) ?></td>
          <td><span class="badge <?= $r['status'] === 'Active' ? 'badge-success' : 'badge-muted' ?>"><?= $r['status'] === 'Active' ? 'Actif' : 'Inactif' ?></span></td>
          <td class="nowrap">
            <a class="btn btn-sm btn-secondary" href="?edit=<?= (int) $r['id'] ?>">Modifier</a>
            <?php if ($isAdmin): ?>
            <form class="inline" method="post" action="actions/rh_employee_action.php" onsubmit="return confirm('Supprimer définitivement cet employé et tout son historique de salaire ?')">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button type="submit" class="btn btn-sm btn-danger">Supprimer définitivement</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <tr>
          <td colspan="6">
            <div class="empty-state">
              <div class="empty-icon">&#128188;</div>
              Aucun employé ne correspond aux critères.
            </div>
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
