<?php
require_once __DIR__ . '/includes/init.php';
require_admin_or_superviseur();

$pageTitle = 'Rapports';
$pageSubtitle = 'Synthèse annuelle par département';
$activePage = 'rh_reports';
$pdo = $db;

$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$year = ($year >= 2000 && $year <= 2100) ? $year : (int) date('Y');

$stmt = $pdo->prepare('SELECT COALESCE(NULLIF(e.department, ""), "Non renseigné") department, COUNT(DISTINCT e.id) employees, COALESCE(SUM(sr.total_hours), 0) total_hours, COALESCE(SUM(sr.calculated_salary), 0) total_salary FROM employees e LEFT JOIN salary_records sr ON sr.employee_id = e.id AND sr.year = :y GROUP BY e.department ORDER BY total_salary DESC');
$stmt->execute(['y' => $year]);
$rows = $stmt->fetchAll();

$grandH = 0;
$grandS = 0;
foreach ($rows as $r) {
  $grandH += (float) $r['total_hours'];
  $grandS += (float) $r['total_salary'];
}

require __DIR__ . '/includes/header.php';
?>

<div class="content-card">
  <div class="card-head">
    <h2>Rapports</h2>
  </div>
  <div class="table-wrap" style="padding:0 0 0;">
    <div class="filter-card" style="border:none;border-bottom:1px solid var(--color-line);border-radius:0;margin:0;">
      <form method="get" class="row gap-3 align-items-end">
        <div class="col-md-10">
          <label class="form-label">Année</label>
          <input type="number" name="year" class="form-select" value="<?= e($year) ?>" min="2000" max="2100">
        </div>
        <div class="col-md-2">
          <button type="submit" class="btn btn-light w-100">Afficher</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="stat-grid">
  <div class="stat-card tone-total">
    <div class="label">Année</div>
    <div class="value"><?= e($year) ?></div>
  </div>
  <div class="stat-card tone-complet">
    <div class="label">Départements</div>
    <div class="value"><?= count($rows) ?></div>
  </div>
  <div class="stat-card tone-noncomplet">
    <div class="label">Heures annuelles</div>
    <div class="value"><?= format_nombre($grandH) ?></div>
  </div>
  <div class="stat-card tone-annule">
    <div class="label">Salaires annuels</div>
    <div class="value"><?= format_montant_tnd($grandS) ?></div>
  </div>
</div>

<div class="content-card">
  <div class="card-head">
    <h2>Synthèse par département</h2>
    <div class="d-flex gap-8 flex-wrap">
      <span class="muted"><?= count($rows) ?> ligne(s)</span>
      <a class="btn btn-sm btn-secondary" href="actions/rh_export_report.php?year=<?= e($year) ?>">Exporter Excel</a>
    </div>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Département</th>
          <th>Employés</th>
          <th>Total des heures</th>
          <th>Total des salaires</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><b><?= e($r['department']) ?></b></td>
          <td><?= e($r['employees']) ?></td>
          <td><?= format_nombre((float) $r['total_hours']) ?></td>
          <td><b><?= format_montant_tnd((float) $r['total_salary']) ?></b></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <tr>
          <td colspan="4">
            <div class="empty-state">
              <div class="empty-icon">&#128202;</div>
              Aucune donnée pour l'année sélectionnée.
            </div>
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
