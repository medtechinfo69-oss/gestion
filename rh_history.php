<?php
require_once __DIR__ . '/includes/init.php';
require_admin_or_superviseur();

$pageTitle = 'Historique des salaires';
$pageSubtitle = 'Consultez l\'évolution mensuelle d\'un salarié';
$activePage = 'rh_history';
$pdo = $db;

$eid = isset($_GET['employee_id']) ? (int) $_GET['employee_id'] : 0;
$employees = $pdo->query('SELECT id, employee_code, full_name FROM employees ORDER BY full_name')->fetchAll();
$rows = [];
$employee = null;

if ($eid > 0) {
  $stmt = $pdo->prepare('SELECT id, employee_code, full_name, hourly_rate FROM employees WHERE id=:id');
  $stmt->execute(['id' => $eid]);
  $employee = $stmt->fetch();

  if ($employee) {
    $stmt = $pdo->prepare('SELECT * FROM salary_records WHERE employee_id=:eid ORDER BY year DESC, month DESC');
    $stmt->execute(['eid' => $eid]);
    $rows = $stmt->fetchAll();
  }
}

$months = [
  1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin',
  7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
];

require __DIR__ . '/includes/header.php';
?>

<div class="content-card">
  <div class="card-head">
    <h2>Historique des salaires</h2>
  </div>
  <div class="table-wrap" style="padding:0 0 0;">
    <div class="filter-card" style="border:none;border-bottom:1px solid var(--color-line);border-radius:0;margin:0;">
      <form class="row gap-3 align-items-end">
        <div class="col-md-10">
          <label class="form-label">Salarié</label>
          <select name="employee_id" class="form-select">
            <option value="">Sélectionner un salarié</option>
            <?php foreach ($employees as $e): ?>
              <option value="<?= (int) $e['id'] ?>" <?= $eid === (int) $e['id'] ? 'selected' : '' ?>><?= e($e['full_name'] . ' — ' . $e['employee_code']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <button type="submit" class="btn btn-light w-100">Afficher</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($employee): ?>
<div class="content-card">
  <div class="card-head">
    <h2><?= e($employee['full_name']) ?></h2>
    <span class="muted"><?= e($employee['employee_code']) ?></span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Mois</th>
          <th>Année</th>
          <th>Heures</th>
          <th>Régime horaire utilisé</th>
          <th>Salaire</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($months[(int) $r['month']] ?? $r['month']) ?></td>
          <td><?= e($r['year']) ?></td>
          <td><?= format_nombre((float) $r['total_hours']) ?></td>
          <td><?= format_montant_tnd((float) $r['hourly_rate_used']) ?></td>
          <td><b><?= format_montant_tnd((float) $r['calculated_salary']) ?></b></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <tr>
          <td colspan="5">
            <div class="empty-state">
              <div class="empty-icon">&#128197;</div>
              Aucun historique disponible.
            </div>
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
