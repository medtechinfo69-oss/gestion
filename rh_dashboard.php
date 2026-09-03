<?php
require_once __DIR__ . '/includes/init.php';
require_admin_or_superviseur();

$user = current_user();
$isAdmin = is_admin();
$pageTitle = 'Tableau de bord';
$pageSubtitle = 'Vue d\'ensemble de la gestion RH';
$activePage = 'rh_dashboard';

$currentMonth = (int) date('n');
$currentYear = (int) date('Y');
$month = isset($_GET['month']) ? (int) $_GET['month'] : $currentMonth;
$year = isset($_GET['year']) ? (int) $_GET['year'] : $currentYear;
$month = ($month >= 1 && $month <= 12) ? $month : $currentMonth;
$year = ($year >= 2000 && $year <= 2100) ? $year : $currentYear;

$months = [
  1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin',
  7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
];

$totalEmployees = (int) $db->query('SELECT COUNT(*) FROM employees')->fetchColumn();
$activeEmployees = (int) $db->query("SELECT COUNT(*) FROM employees WHERE status='Active'")->fetchColumn();

$stmt = $db->prepare('SELECT COALESCE(SUM(calculated_salary),0) total, COALESCE(SUM(total_hours),0) hours, COALESCE(AVG(calculated_salary),0) avg FROM salary_records WHERE month=:m AND year=:y');
$stmt->execute(['m' => $month, 'y' => $year]);
$monthStats = $stmt->fetch();

$salaryByMonth = array_fill(1, 12, 0);
$hoursByMonth = array_fill(1, 12, 0);
$stmt = $db->prepare('SELECT month, COALESCE(SUM(calculated_salary),0) salary, COALESCE(SUM(total_hours),0) hours FROM salary_records WHERE year=:y GROUP BY month ORDER BY month');
$stmt->execute(['y' => $year]);
foreach ($stmt->fetchAll() as $r) {
  $salaryByMonth[(int) $r['month']] = (float) $r['salary'];
  $hoursByMonth[(int) $r['month']] = (float) $r['hours'];
}

$maxSalary = max(array_values($salaryByMonth));
$maxSalaryMonth = (int) array_search($maxSalary, $salaryByMonth, true);
$minSalary = min(array_values($salaryByMonth));
$minSalaryMonth = (int) array_search($minSalary, $salaryByMonth, true);
$maxHours = max(array_values($hoursByMonth));
$maxHoursMonth = (int) array_search($maxHours, $hoursByMonth, true);
$minHours = min(array_values($hoursByMonth));
$minHoursMonth = (int) array_search($minHours, $hoursByMonth, true);

$recentEmployees = [];
if ($isAdmin) {
  $stmt = $db->query('SELECT * FROM employees ORDER BY created_at DESC LIMIT 8');
  $recentEmployees = $stmt->fetchAll();
}

require __DIR__ . '/includes/header.php';
?>

<div class="stats-grid">
  <div class="stat-card stat-card--accent">
    <div class="stat-accent"></div>
    <div class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16.5 18.5v-1a3.5 3.5 0 0 0-3.5-3.5H9A3.5 3.5 0 0 0 5.5 17.5v1"/><circle cx="12" cy="8" r="3.2"/><path d="M18.5 10.2a2.7 2.7 0 0 1 0 5.4"/><path d="M5.5 10.2a2.7 2.7 0 0 0 0 5.4"/></svg></div>
    <div class="stat-content">
      <span class="stat-label">Total employés</span>
      <strong class="stat-value"><?= (int) $totalEmployees ?></strong>
    </div>
  </div>
  <div class="stat-card stat-card--secondary">
    <div class="stat-accent"></div>
    <div class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 1v23M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
    <div class="stat-content">
      <span class="stat-label">Salaires du mois</span>
      <strong class="stat-value"><?= format_montant_tnd((float) $monthStats['total']) ?></strong>
    </div>
  </div>
  <div class="stat-card stat-card--success">
    <div class="stat-accent"></div>
    <div class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg></div>
    <div class="stat-content">
      <span class="stat-label">Heures travaillées</span>
      <strong class="stat-value"><?= format_nombre((float) $monthStats['hours']) ?></strong>
    </div>
  </div>
  <div class="stat-card stat-card--accent">
    <div class="stat-accent"></div>
    <div class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M18 20V10M12 20V4M6 20v-6"/></svg></div>
    <div class="stat-content">
      <span class="stat-label">Moyenne / salarié</span>
      <strong class="stat-value"><?= format_montant_tnd((float) $monthStats['avg']) ?></strong>
    </div>
  </div>
</div>

<div class="rh-period-toolbar">
  <form method="get" class="flex gap-8" style="align-items:center;">
    <select id="month" name="month" class="form-select form-control-sm">
      <option value="">Mois</option>
      <?php for ($i = 1; $i <= 12; $i++): ?>
        <option value="<?= $i ?>" <?= $month === $i ? 'selected' : '' ?>><?= e($months[$i]) ?></option>
      <?php endfor; ?>
    </select>
    <input type="number" id="year" name="year" class="form-select form-control-sm" value="<?= e($year) ?>" min="2000" max="2100">
    <button type="submit" class="btn btn-primary btn-sm">Actualiser</button>
  </form>
</div>

<div class="charts-container">
  <section class="chart-card chart-card--primary">
    <div class="chart-card-header">
      <div class="chart-title-group">
        <h2 class="chart-title">Évolution des salaires</h2>
        <div class="chart-subtitle"><?= e($year) ?> · Visualisation mensuelle</div>
      </div>
      <span class="chart-badge"><?= e($year) ?></span>
    </div>
    <div class="chart-canvas-wrapper"><canvas id="salaryChart" data-values='<?= json_encode(array_values($salaryByMonth)) ?>' data-labels='<?= json_encode(array_values($months)) ?>'></canvas></div>
    <div class="chart-metrics">
      <div class="metric"><span class="metric-label">Total annuel</span><strong class="metric-value"><?= format_montant_tnd(array_sum($salaryByMonth)) ?></strong></div>
      <div class="metric"><span class="metric-label">Moyenne / mois</span><strong class="metric-value"><?= format_montant_tnd(array_sum($salaryByMonth) / 12) ?></strong></div>
      <div class="metric"><span class="metric-label">Pic mensuel</span><strong class="metric-value"><?= format_montant_tnd($maxSalary) ?></strong><span class="metric-sub"><?= e($months[$maxSalaryMonth]) ?></span></div>
      <div class="metric"><span class="metric-label">Mois minimum</span><strong class="metric-value"><?= format_montant_tnd($minSalary) ?></strong><span class="metric-sub"><?= e($months[$minSalaryMonth]) ?></span></div>
    </div>
  </section>

  <section class="chart-card chart-card--secondary">
    <div class="chart-card-header">
      <div class="chart-title-group">
        <h2 class="chart-title">Heures travaillées</h2>
        <div class="chart-subtitle"><?= e($year) ?> · Visualisation mensuelle</div>
      </div>
      <span class="chart-badge"><?= e($year) ?></span>
    </div>
    <div class="chart-canvas-wrapper"><canvas id="hoursChart" data-values='<?= json_encode(array_values($hoursByMonth)) ?>' data-labels='<?= json_encode(array_values($months)) ?>'></canvas></div>
    <div class="chart-metrics">
      <div class="metric"><span class="metric-label">Total annuel</span><strong class="metric-value"><?= format_nombre(array_sum($hoursByMonth)) ?> h</strong></div>
      <div class="metric"><span class="metric-label">Moyenne / mois</span><strong class="metric-value"><?= format_nombre(array_sum($hoursByMonth) / 12) ?> h</strong></div>
      <div class="metric"><span class="metric-label">Pic mensuel</span><strong class="metric-value"><?= format_nombre($maxHours) ?> h</strong><span class="metric-sub"><?= e($months[$maxHoursMonth]) ?></span></div>
      <div class="metric"><span class="metric-label">Mois minimum</span><strong class="metric-value"><?= format_nombre($minHours) ?> h</strong><span class="metric-sub"><?= e($months[$minHoursMonth]) ?></span></div>
    </div>
  </section>
</div>

<div class="card">
  <div class="card-header">
    <h2>Derniers employés ajoutés</h2>
    <a href="<?= e(APP_URL) ?>/rh_employees.php" class="btn btn-outline btn-sm">Voir tous les employés</a>
  </div>
  <div class="table-wrap">
    <?php if (!$recentEmployees): ?>
      <div class="empty-state">
        <div class="ico">&#128188;</div>
        <p>Aucun employé pour le moment.</p>
      </div>
    <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Matricule</th>
          <th>Nom complet</th>
          <th>Département</th>
          <th>Poste</th>
          <th>Statut</th>
          <th>Date d'entrée</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recentEmployees as $e): ?>
        <tr>
          <td><b><?= e($e['employee_code']) ?></b></td>
          <td><?= e($e['full_name']) ?></td>
          <td><?= e($e['department'] ?: '—') ?></td>
          <td><?= e($e['position'] ?: '—') ?></td>
          <td><span class="badge <?= $e['status'] === 'Active' ? 'badge-success' : 'badge-muted' ?>"><?= $e['status'] === 'Active' ? 'Actif' : 'Inactif' ?></span></td>
          <td><?= $e['start_date'] ? format_date($e['start_date']) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
