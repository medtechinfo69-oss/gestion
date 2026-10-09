<?php
require_once __DIR__ . '/includes/init.php';
require_rh(); // administrateur OU compte RH (mêmes fonctions, module RH seul)

// Filet de sécurité contre un téléversement partiel : format_heure() est
// défini dans includes/functions.php (il vivait auparavant dans ce fichier).
// Si functions.php n'est pas à jour sur l'hébergement, la page planterait
// en HTTP 500 : on fournit donc un substitut.
if (!function_exists('format_heure')) {
    function format_heure($decimalHours): string
    {
        $hours = (int) $decimalHours;
        $minutes = (int) round(((float) $decimalHours - $hours) * 60);
        if ($minutes === 60) {
            $hours++;
            $minutes = 0;
        }
        return str_pad((string) $hours, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) $minutes, 2, '0', STR_PAD_LEFT);
    }
}

$pageTitle = 'Historique des salaires';
$pageSubtitle = 'Évolution mensuelle des salaires';
$activePage = 'rh_history';
$pdo = $db;
// Colonnes « nom » / « prenom » (migration idempotente) : elles permettent de
// présenter le nom et le prénom séparément au lieu d'une colonne unique.
$nomColonnes = rh_ensure_name_columns($pdo);

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
$sql = 'SELECT sr.*, e.full_name, e.employee_code, e.pseudo'
     . ($nomColonnes ? ', e.nom, e.prenom' : '')
     . ' FROM salary_records sr JOIN employees e ON e.id = sr.employee_id';
if ($where) {
  $sql .= ' WHERE ' . implode(' AND ', $where);
}
// Tri par matricule du plus petit au plus grand (01, 02, 03 … 20, 21 … 99).
// employee_code est stocké en texte, parfois avec des zéros initiaux : un tri
// alphabétique donnerait « 1, 10, 11, 2 ». On trie donc numériquement, les
// matricules non numériques restant classés à la suite par ordre alphabétique.
// À matricule égal, les mois les plus récents apparaissent en premier.
$sql .= " ORDER BY (e.employee_code REGEXP '^[0-9]+$') DESC,"
      . ' CAST(e.employee_code AS UNSIGNED) ASC,'
      . ' e.employee_code ASC,'
      . ' sr.year DESC, sr.month DESC';
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
// Le formatage des heures utilise le helper partagé format_heure() (voir
// includes/functions.php), afin que rh_history.php et rh_salaries.php
// affichent rigoureusement la même valeur.

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

  /* Filtres — même design compact que « Salaires mensuels »
     (rh_salaries.php) : champs 36px alignés à droite, recherche (ici la
     liste « Salarié ») plus large, « Mois » / « Année » au format compact. */
  .salary-filter-card { overflow: visible; }
  .salary-filter-card .card-head { padding: 14px 18px; }
  .salary-filter-card .card-head h2 { font-size: 1rem; }
  .salary-filter-card .salary-filter-body { padding: 12px 18px 14px; }
  .salary-filter-card .filter-card {
    border: none;
    border-radius: 0;
    margin: 0;
    padding: 0;
    background: transparent;
    box-shadow: none;
  }
  .salary-filter-form {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: flex-end;
    gap: 10px 12px;
    margin: 0;
  }
  .salary-filter-form .sf-field { margin: 0; min-width: 0; }
  /* Même hauteur pour les 3 champs, mais « Salarié » plus large. */
  .salary-filter-form .sf-search { flex: 1 1 200px; width: 220px; max-width: 260px; min-width: 180px; }
  .salary-filter-form .sf-month,
  .salary-filter-form .sf-year { flex: 0 1 150px; width: 150px; max-width: 160px; min-width: 140px; }
  .salary-filter-form .sf-actions { flex: 0 0 auto; display: flex; align-items: flex-end; gap: 8px; }
  .salary-filter-card .form-label { margin-bottom: 4px; font-size: 0.74rem; }
  /* Même hauteur stricte pour les 3 champs : sélecteur renforcé (0,3,0+)
     pour battre la règle globale 42px « .filter-card input[type=…] »
     (0,2,1) qui écraserait sinon les 36px de la page. */
  .salary-filter-card .filter-card .form-control,
  .salary-filter-card .filter-card .form-select,
  .salary-filter-card .filter-card input[type=text],
  .salary-filter-card .filter-card input[type=number],
  .salary-filter-card .filter-card select {
    height: 36px;
    min-height: 36px;
    max-height: 36px;
    padding: 6px 10px;
    font-size: 0.85rem;
    line-height: 1.4;
    border-radius: 8px;
    box-sizing: border-box;
    vertical-align: middle;
  }
  .salary-filter-card .btn { height: 36px; padding: 0 14px; font-size: 0.83rem; border-radius: 8px; }
  .salary-filter-card .filter-reset { height: 36px; }
  .salary-filter-card .filter-card select option {
    padding: 6px 8px;
  }

  @media (max-width: 640px) {
    .salary-filter-card .salary-filter-body { padding: 10px 14px 12px; }
    .salary-filter-form .sf-search,
    .salary-filter-form .sf-month,
    .salary-filter-form .sf-year { flex: 1 1 100%; width: 100%; max-width: none; }
    .salary-filter-form .sf-actions { flex: 1 1 100%; }
    .salary-filter-form .sf-actions .btn { flex: 1 1 auto; justify-content: center; }
  }
</style>

<div class="content-card salary-filter-card">
  <div class="card-head">
    <h2>Historique des salaires</h2>
  </div>
  <div class="salary-filter-body">
    <div class="filter-card">
      <form method="get" action="rh_history.php" class="salary-filter-form">
        <div class="sf-field sf-search">
          <label class="form-label" for="history-employee">Salarié</label>
          <select id="history-employee" name="employee_id" class="form-select">
            <option value="" <?= $eid === 0 ? 'selected' : '' ?>>Tous</option>
            <?php foreach ($employees as $e): ?>
              <?php $employeeLabel = (string) ($e['full_name'] ?? ''); ?>
              <option value="<?= (int) $e['id'] ?>" title="<?= e($employeeLabel) ?>" <?= $eid === (int) $e['id'] ? 'selected' : '' ?>><?= $historyText($employeeLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="sf-field sf-month">
          <label class="form-label" for="history-month">Mois</label>
          <select id="history-month" name="month" class="form-select">
            <option value="" <?= $month === 0 ? 'selected' : '' ?>>Mois</option>
            <?php foreach ($months as $number => $label): ?>
              <option value="<?= $number ?>" <?= $month === $number ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="sf-field sf-year">
          <label class="form-label" for="history-year">Année</label>
          <select id="history-year" name="year" class="form-select">
            <option value="" <?= $year === 0 ? 'selected' : '' ?>>Années</option>
            <?php foreach ($years as $y): ?>
              <option value="<?= (int) $y ?>" <?= $year === (int) $y ? 'selected' : '' ?>><?= (int) $y ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="sf-actions">
          <button type="submit" class="btn btn-light">Afficher</button>
          <?php if ($eid > 0 || $month > 0 || $year > 0): ?>
            <a class="btn btn-outline filter-reset" href="rh_history.php" title="Effacer les filtres">Réinitialiser</a>
          <?php endif; ?>
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
          <th>Matricule</th>
          <?php if (!$employee): ?>
          <th>Nom</th>
          <th>Prénom</th>
          <?php endif; ?>
          <th>Pseudo</th>
          <th>Mois</th>
          <th>Année</th>
          <th>Jour Normalement<br>Travaillé</th>
          <th>ABS Non Justifiée<br>en jours</th>
          <th>ABS Non Justifiée<br>en heures</th>
          <th>Jour Payé</th>
          <th>Heure Payée</th>
          <th>N Retards</th>
          <th>Heures Sup</th>
          <th>N Départ</th>
          <th>Régime horaire utilisé</th>
          <th style="min-width:120px;white-space:nowrap;">Salaire</th>
          <th>Total heures</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <?php $absDays = (float) $r['unjustified_absence_days']; ?>
        <?php $absHours = (float) $r['unjustified_absence_hours']; ?>
        <?php $lates = (int) $r['late_count']; ?>
        <tr>
          <td><?= e($r['employee_code']) ?></td>
          <?php if (!$employee): ?>
          <?php
          // Nom / prénom séparés : on utilise les colonnes dédiées, sinon on
          // re-découpe `full_name` à la volée (base non migrée). Une valeur
          // absente est affichée « Non renseigné ».
          if ($nomColonnes) {
            $rowNom = nom_ou_non_renseigne($r['nom'] ?? '');
            $rowPrenom = nom_ou_non_renseigne($r['prenom'] ?? '');
          } else {
            $decoupe = split_person_name((string) ($r['full_name'] ?? ''));
            $rowNom = nom_ou_non_renseigne($decoupe['nom']);
            $rowPrenom = nom_ou_non_renseigne($decoupe['prenom']);
          }
          ?>
          <td><span class="history-text" title="<?= e($rowNom) ?>"><?= $historyText($rowNom) ?></span></td>
          <td><span class="history-text" title="<?= e($rowPrenom) ?>"><?= $historyText($rowPrenom) ?></span></td>
          <?php endif; ?>
          <?php $rowPseudo = (string) ($r['pseudo'] ?? ''); ?>
          <td><span class="history-text" title="<?= e($rowPseudo) ?>"><?= $historyText($rowPseudo) ?></span></td>
          <td><?= e($months[(int) $r['month']] ?? $r['month']) ?></td>
          <td><?= e($r['year']) ?></td>
          <td><?= format_nombre((float) $r['normal_worked_days']) ?></td>
          <td class="<?= $attendanceClass($absDays) ?>"><?= format_nombre($absDays) ?></td>
          <td class="<?= $attendanceClass($absHours) ?>"><?= format_heure($absHours) ?></td>
          <td><?= format_nombre((float) $r['paid_days']) ?></td>
          <td><?= format_heure((float) $r['paid_hours']) ?></td>
          <td class="<?= $attendanceClass($lates) ?>"><?= (int) $lates ?></td>
          <td><?= format_heure((float) $r['heures_sup']) ?></td>
          <td class="<?= $attendanceClass((int) $r['n_depart']) ?>"><?= (int) $r['n_depart'] ?></td>
          <td><?= format_montant_tnd((float) $r['hourly_rate_used']) ?></td>
          <td style="white-space:nowrap;"><b><?= format_montant_tnd((float) $r['calculated_salary']) ?></b></td>
          <td><?= format_heure((float) $r['total_hours']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <tr>
          <td colspan="16">
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
