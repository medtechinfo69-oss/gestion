<?php
require_once __DIR__ . '/includes/init.php';
require_admin();

$month = isset($_GET['month']) ? max(1, min(12, (int) $_GET['month'])) : (int) date('n');
$year = isset($_GET['year']) ? max(2000, min(2100, (int) $_GET['year'])) : (int) date('Y');

$months = [
  1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin',
  7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
];

$startDate = sprintf('%04d-%02d-01', $year, $month);
$nextMonth = $month === 12 ? 1 : $month + 1;
$nextYear = $month === 12 ? $year + 1 : $year;
$nextMonthStartDate = sprintf('%04d-%02d-01', $nextYear, $nextMonth);
$cutoffDate = sprintf('%04d-%02d-05', $nextYear, $nextMonth);

$stmt = $db->prepare(
  'SELECT u.id AS vendeur_id, u.nom_complet AS vendeur,
          COALESCE(SUM(CASE WHEN d.etat_dossier = :complete_status_1 AND d.date_dossier_complet IS NOT NULL
                             AND d.date_vente >= :production_start_1 AND d.date_vente < :production_end_1
                             AND d.date_dossier_complet <= :cutoff_date_1
                             THEN d.ca_annuel ELSE 0 END), 0) AS ca_annuel_complet,
          COALESCE(SUM(CASE WHEN d.etat_contrat <> :active_status_1 AND d.date_contrat_non_actif IS NOT NULL
                             AND d.date_vente >= :production_start_2 AND d.date_vente < :production_end_2
                             AND d.date_contrat_non_actif <= :cutoff_date_2
                             THEN d.ca_annuel ELSE 0 END), 0) AS ca_annuel_annule
   FROM users u
   LEFT JOIN dossiers d ON d.vendeur_id = u.id
   WHERE u.role = :role_1
   GROUP BY u.id, u.nom_complet
   ORDER BY u.nom_complet ASC'
);

$stmt->execute([
  'complete_status_1' => 'Dossier complet',
  'active_status_1' => 'Actif',
  'production_start_1' => $startDate,
  'production_end_1' => $nextMonthStartDate,
  'cutoff_date_1' => $cutoffDate,
  'production_start_2' => $startDate,
  'production_end_2' => $nextMonthStartDate,
  'cutoff_date_2' => $cutoffDate,
  'role_1' => 'vendeur',
]);

$rows = $stmt->fetchAll();
foreach ($rows as &$row) {
  $row['ca_annuel_complet'] = (float) $row['ca_annuel_complet'];
  $row['ca_annuel_annule'] = (float) $row['ca_annuel_annule'];
  $row['prime'] = $row['ca_annuel_complet'] - $row['ca_annuel_annule'];
}
unset($row);

$pageTitle = 'Prime';
$pageSubtitle = 'Prime par vendeur';
$activePage = 'prime';
require __DIR__ . '/includes/header.php';
?>

<div class="content-card">
  <div class="card-head">
    <h2>Prime</h2>
    <div class="d-flex gap-8 flex-wrap">
      <span class="muted">Validation prise en compte jusqu’au 5 du mois suivant</span>
    </div>
  </div>
  <div class="table-wrap" style="padding:0 0 0;">
    <div class="filter-card" style="border:none;border-bottom:1px solid var(--color-line);border-radius:0;margin:0;">
      <form method="get" class="row gap-3 align-items-end">
        <div class="col-md-4">
          <label class="form-label">Mois</label>
          <select name="month" class="form-select">
            <?php foreach ($months as $m => $label): ?>
              <option value="<?= (int) $m ?>" <?= $month === $m ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Année</label>
          <input type="number" name="year" class="form-control" value="<?= e($year) ?>" min="2000" max="2100">
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <button type="submit" class="btn btn-primary w-100">Filtrer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="content-card">
  <div class="card-head">
    <h2><?= e($months[$month]) ?> <?= e($year) ?></h2>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Vendeur</th>
          <th>Mois</th>
          <th>CA annuel(dossier complet)</th>
          <th>CA annuel(dossier annulé)</th>
          <th>Prime</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
        <tr>
          <td><b><?= e($row['vendeur']) ?></b></td>
          <td><?= e($months[$month]) ?></td>
          <td><?= format_montant((float) $row['ca_annuel_complet']) ?></td>
          <td><?= format_montant((float) $row['ca_annuel_annule']) ?></td>
          <td><b><?= format_montant((float) $row['prime']) ?></b></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <tr>
          <td colspan="5">
            <div class="empty-state">
              <div class="empty-icon">&#128202;</div>
              Aucune donnée pour ce mois.
            </div>
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
