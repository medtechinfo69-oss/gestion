<?php
require_once __DIR__ . '/includes/init.php';
require_admin();

$pageTitle = 'Historique des salaires';
$pageSubtitle = 'Évolution mensuelle des salaires';
$activePage = 'rh_history';
$pdo = $db;

$eid = max(0, (int) ($_GET['employee_id'] ?? 0));
$month = (int) ($_GET['month'] ?? 0);
if ($month < 1 || $month > 12) {
  $month = 0;
}
$year = (int) ($_GET['year'] ?? 0);
if ($year < 2000 || $year > 2100) {
  $year = 0;
}
$employees = $pdo->query('SELECT id, employee_code, full_name FROM employees ORDER BY id')->fetchAll();
$years = $pdo->query('SELECT DISTINCT year FROM salary_records ORDER BY year DESC')->fetchAll(PDO::FETCH_COLUMN);
$employee = null;
foreach ($employees as $e) {
  if ((int) $e['id'] === $eid) {
    $employee = $e;
    break;
  }
}

$where = [];
$params = [];
if ($eid > 0) {
  $where[] = 'sr.employee_id = :eid';
  $params['eid'] = $eid;
}
if ($month > 0) {
  $where[] = 'sr.month = :month';
  $params['month'] = $month;
}
if ($year > 0) {
  $where[] = 'sr.year = :year';
  $params['year'] = $year;
}
$sql = 'SELECT sr.*, e.full_name, e.employee_code FROM salary_records sr JOIN employees e ON e.id = sr.employee_id';
if ($where) {
  $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY sr.year DESC, sr.month DESC, e.full_name, e.id';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Limit displayed text only; the full value remains available in the tooltip.
$historyText = static function ($value): string {
  $text = (string) $value;
  return e(mb_strlen($text, 'UTF-8') > 35 ? mb_substr($text, 0, 34, 'UTF-8') . '…' : $text);
};
$attendanceClass = static function ($value): string {
  $count = (float) $value;
  return $count >= 3 ? 'attendance-red' : ($count >= 1 ? 'attendance-orange' : '');
};
// Formate des heures décimales en HH:MM (ex : 3.5 -> 03:30).
$historyTime = static function ($decimalHours): string {
  $hours = (int) $decimalHours;
  $minutes = (int) round(((float) $decimalHours - $hours) * 60);
  return str_pad((string) $hours, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) $minutes, 2, '0', STR_PAD_LEFT);
};

$months = [
  1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin',
  7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
];

require __DIR__ . '/includes/header.php';
?>

<style>
  /* Toutes les colonnes et informations sont limitées à 35 caractères.
     Sélecteurs avec la même spécificité que le style global (20ch) mais
     déclarés APRÈS la feuille de style : ils gagnent la cascade. */
  .table-wrap table.history-table thead th,
  .table-wrap table.history-table tbody td {
    max-width: 35ch;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: middle;
  }
  .table-wrap table.history-table td:last-child {
    max-width: 35ch;
  }
  .history-table .history-text {
    display: inline-block;
    max-width: 35ch;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: middle;
  }
  .history-table .attendance-orange,
  .table-wrap table.history-table tbody td.attendance-orange { color: #FFA500; font-weight: 700; animation: history-blink-orange 1s step-start infinite; }
  .history-table .attendance-red,
  .table-wrap table.history-table tbody td.attendance-red { color: #FF2C2C; font-weight: 700; animation: history-blink-red 1s step-start infinite; }

  /* Clignotement du TEXTE uniquement (la couleur change, le fond de la
     cellule reste stable) pour les valeurs d'absentéisme. */
  @keyframes history-blink-red {
    0%, 100% { color: #FF2C2C; }
    50% { color: #ff9999; }
  }
  @keyframes history-blink-orange {
    0%, 100% { color: #FFA500; }
    50% { color: #ffd27f; }
  }

  /* Filtres : sélecteurs et bouton à la même hauteur (42px) + zone des
     options déroulantes confortable avec défilement. */
  .filter-card .form-select {
    height: 42px;
  }
  .filter-card .btn {
    height: 42px;
    white-space: nowrap;
  }
  .filter-card select option {
    padding: 6px 8px;
  }
</style>

<div class="content-card">
  <div class="card-head">
    <h2>Historique des salaires</h2>
  </div>
  <div class="table-wrap" style="padding:0 0 0;">
    <div class="filter-card" style="border:none;border-bottom:1px solid var(--color-line);border-radius:0;margin:0;">
      <form method="get" class="row gap-3 align-items-end justify-content-end">
        <div class="col-auto">
          <label class="form-label" for="history-employee">Salarié</label>
          <select id="history-employee" name="employee_id" class="form-select" style="min-width:260px;">
            <option value="" <?= $eid === 0 ? 'selected' : '' ?>>Tous</option>
            <?php foreach ($employees as $e): ?>
              <?php $employeeLabel = $e['full_name']; ?>
              <option value="<?= (int) $e['id'] ?>" title="<?= e($employeeLabel) ?>" <?= $eid === (int) $e['id'] ? 'selected' : '' ?>><?= $historyText($employeeLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto">
          <label class="form-label" for="history-month">Mois</label>
          <select id="history-month" name="month" class="form-select">
            <option value="" <?= $month === 0 ? 'selected' : '' ?>>Mois</option>
            <?php foreach ($months as $number => $label): ?>
              <option value="<?= $number ?>" <?= $month === $number ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto">
          <label class="form-label" for="history-year">Année</label>
          <select id="history-year" name="year" class="form-select">
            <option value="" <?= $year === 0 ? 'selected' : '' ?>>Années</option>
            <?php foreach ($years as $y): ?>
              <option value="<?= (int) $y ?>" <?= $year === (int) $y ? 'selected' : '' ?>><?= (int) $y ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-light">Afficher</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="content-card">
  <div class="card-head">
    <h2><?= $employee ? e($employee['full_name']) : 'Tous les salariés' ?></h2>
    <span class="muted">
      <?= $employee ? e($employee['employee_code']) : count($rows) . ' ligne(s)' ?>
      <?= $month > 0 ? ' — ' . e($months[$month]) : '' ?>
      <?= $year > 0 ? ' — ' . (int) $year : '' ?>
    </span>
  </div>
  <div class="table-wrap">
    <table class="history-table">
      <thead>
        <tr>
          <th>ID</th>
          <?php if (!$employee): ?>
          <th>Salarié</th>
          <?php endif; ?>
          <th>Mois</th>
          <th>Année</th>
          <th>Heures</th>
          <th>Jour Payé</th>
          <th>Régime horaire utilisé</th>
          <th>ABS Non Justifiée en jours</th>
          <th>ABS Non Justifiée en heures</th>
          <th>Nb Retards</th>
          <th>Salaire</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <?php $absDays = (float) $r['unjustified_absence_days']; ?>
        <?php $absHours = (float) $r['unjustified_absence_hours']; ?>
        <?php $lates = (int) $r['late_count']; ?>
        <tr>
          <td><?= (int) $r['employee_id'] ?></td>
          <?php if (!$employee): ?>
          <?php $rowLabel = $r['full_name']; ?>
          <td><span class="history-text" title="<?= e($rowLabel) ?>"><?= $historyText($rowLabel) ?></span></td>
          <?php endif; ?>
          <td><?= e($months[(int) $r['month']] ?? $r['month']) ?></td>
          <td><?= e($r['year']) ?></td>
          <td><?= format_nombre((float) $r['total_hours']) ?></td>
          <td><?= format_nombre((float) $r['paid_days']) ?></td>
          <td><?= format_montant_tnd((float) $r['hourly_rate_used']) ?></td>
          <td class="<?= $attendanceClass($absDays) ?>"><?= format_nombre($absDays) ?></td>
          <td class="<?= $attendanceClass($absHours) ?>"><?= $historyTime($absHours) ?></td>
          <td class="<?= $attendanceClass($lates) ?>"><?= $lates ?></td>
          <td><b><?= format_montant_tnd((float) $r['calculated_salary']) ?></b></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <tr>
          <td colspan="10">
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

<?php require __DIR__ . '/includes/footer.php'; ?>
