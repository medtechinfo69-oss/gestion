<?php
require_once __DIR__ . '/includes/init.php';
require_admin_or_superviseur();

$pageTitle = 'Salaires mensuels';
$pageSubtitle = 'Gestion et contrôle de la paie';
$activePage = 'rh_salaries';
$pdo = $db;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_GET['action_handler']) && ($_POST['action'] ?? '') !== 'import_salaries') {
  require_once __DIR__ . '/actions/rh_salary_action.php';
  exit;
}

$requestedPeriod = isset($_GET['month']) || isset($_GET['year']);
$defaultMonth = (int) date('n');
$defaultYear = (int) date('Y');

if (!$requestedPeriod) {
  $latest = $pdo->query('SELECT month, year FROM salary_records ORDER BY year DESC, month DESC, id DESC LIMIT 1')->fetch();
  if ($latest) {
    $defaultMonth = (int) $latest['month'];
    $defaultYear = (int) $latest['year'];
  }
}

$month = isset($_GET['month']) ? (int) $_GET['month'] : $defaultMonth;
$year = isset($_GET['year']) ? (int) $_GET['year'] : $defaultYear;
$month = ($month >= 1 && $month <= 12) ? $month : (int) date('n');
$year = ($year >= 2000 && $year <= 2100) ? $year : (int) date('Y');

function redirect_salary_page($month, $year): void
{
  header('Location: ' . APP_URL . '/rh_salaries.php?month=' . (int) $month . '&year=' . (int) $year);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
  $id = (int) ($_POST['id'] ?? 0);
  $mTarget = isset($_POST['month']) ? (int) $_POST['month'] : $month;
  $yTarget = isset($_POST['year']) ? (int) $_POST['year'] : $year;
  $h = filter_var(str_replace(',', '.', (string) ($_POST['total_hours'] ?? '')), FILTER_VALIDATE_FLOAT);
  $r = filter_var(str_replace(',', '.', (string) ($_POST['hourly_rate_used'] ?? '')), FILTER_VALIDATE_FLOAT);

  if ($id <= 0 || $h === false || $h < 0 || $r === false || $r < 0) {
    set_flash('error', 'Les heures et le régime horaire doivent être des nombres positifs.');
    redirect_salary_page($mTarget, $yTarget);
  }

  $stmt = $pdo->prepare('UPDATE salary_records SET total_hours=:h, hourly_rate_used=:r, calculated_salary=:s WHERE id=:id');
  $stmt->execute(['h' => round((float) $h, 2), 'r' => round((float) $r, 2), 's' => round((float) $h * (float) $r, 2), 'id' => $id]);
  set_flash('success', 'Salaire recalculé avec succès.');
  redirect_salary_page($mTarget, $yTarget);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
  $empId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT);
  $mTarget = isset($_POST['month']) ? (int) $_POST['month'] : (int) date('n');
  $yTarget = isset($_POST['year']) ? (int) $_POST['year'] : (int) date('Y');
  $hoursInput = str_replace(',', '.', trim((string) ($_POST['total_hours'] ?? '')));
  $h = is_numeric($hoursInput) ? (float) $hoursInput : -1;

  if ($empId === false || $empId === null) {
    set_flash('error', 'Veuillez sélectionner un employé.');
    redirect_salary_page($mTarget, $yTarget);
  } elseif ($h < 0) {
    set_flash('error', 'Le nombre d\'heures doit être un nombre positif.');
    redirect_salary_page($mTarget, $yTarget);
  }

  $stmt = $pdo->prepare("SELECT id, hourly_rate FROM employees WHERE id=:id AND status='Active' LIMIT 1");
  $stmt->execute(['id' => $empId]);
  $emp = $stmt->fetch();

  if (!$emp) {
    set_flash('error', 'Salarié introuvable ou inactif.');
    redirect_salary_page($mTarget, $yTarget);
  }

  $rate = round((float) $emp['hourly_rate'], 2);
  $calc = round($h * $rate, 2);

  try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id FROM salary_records WHERE employee_id=:eid AND month=:m AND year=:y LIMIT 1');
    $stmt->execute(['eid' => $emp['id'], 'm' => $mTarget, 'y' => $yTarget]);
    $record = $stmt->fetch();

    if ($record) {
      $stmt = $pdo->prepare('UPDATE salary_records SET total_hours=:h, hourly_rate_used=:r, calculated_salary=:s WHERE id=:id');
      $stmt->execute(['h' => $h, 'r' => $rate, 's' => $calc, 'id' => $record['id']]);
    } else {
      $stmt = $pdo->prepare('INSERT INTO salary_records(employee_id, month, year, total_hours, hourly_rate_used, calculated_salary) VALUES(:eid, :m, :y, :h, :r, :s)');
      $stmt->execute(['eid' => $emp['id'], 'm' => $mTarget, 'y' => $yTarget, 'h' => $h, 'r' => $rate, 's' => $calc]);
    }
    $pdo->commit();
    set_flash('success', 'Salaire calculé et enregistré avec succès.');
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('rh_salary_add: ' . $e->getMessage());
    set_flash('error', 'Impossible d\'enregistrer le salaire.');
  }
  redirect_salary_page($mTarget, $yTarget);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_salaries') {
  csrf_require();
  $errors = [];
  $ok = 0;
  $mTarget = $month;
  $yTarget = $year;

  $f = $_FILES['import_file'] ?? null;
  if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > max_import_size_bytes($pdo)) {
    set_flash('error', 'Fichier invalide. Vérifiez la taille et le format.');
    redirect_salary_page($mTarget, $yTarget);
  }

  $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
  if ($ext !== 'xlsx') {
    set_flash('error', 'Format invalide. Sélectionnez un fichier .xlsx.');
    redirect_salary_page($mTarget, $yTarget);
  }

  if (!class_exists('ZipArchive')) {
    set_flash('error', 'L\'extension PHP ZipArchive est requise.');
    redirect_salary_page($mTarget, $yTarget);
  }

  $zip = new ZipArchive();
  if ($zip->open($f['tmp_name']) !== true) {
    set_flash('error', 'Le fichier Excel est invalide ou corrompu.');
    redirect_salary_page($mTarget, $yTarget);
  }

  $shared = [];
  $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
  if ($sharedXml !== false) {
    $x = simplexml_load_string($sharedXml);
    if ($x) {
      foreach ($x->si as $si) {
        $shared[] = (string) $si->t;
      }
    }
  }

  $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
  if ($sheet === false) {
    $zip->close();
    set_flash('error', 'La première feuille Excel est introuvable.');
    redirect_salary_page($mTarget, $yTarget);
  }

  $dom = new DOMDocument();
  if (!$dom->loadXML($sheet)) {
    $zip->close();
    set_flash('error', 'Impossible de lire le contenu du fichier Excel.');
    redirect_salary_page($mTarget, $yTarget);
  }

  $rows = $dom->getElementsByTagName('row');
  $xpath = new DOMXPath($dom);
  $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
  $allRows = [];

  foreach ($rows as $row) {
    $vals = [];
    $cells = $xpath->query('.//x:c', $row);
    $sorted = [];
    foreach ($cells as $c) {
      if (!$c instanceof DOMElement) {
        continue;
      }
      $r = $c->getAttribute('r');
      $col = '';
      if (preg_match('/^([A-Z]+)/', $r, $m)) { $col = $m[1]; }
      $sorted[] = ['col' => $col, 'c' => $c];
    }
    usort($sorted, function ($a, $b) { return strcmp($a['col'], $b['col']); });
    foreach ($sorted as $item) {
      $c = $item['c'];
      $v = '';
      $vNode = $xpath->query('.//x:v', $c)->item(0);
      if ($vNode) {
        $v = $vNode->nodeValue;
        if ($c->getAttribute('t') === 's' && isset($shared[(int) $v])) {
          $v = $shared[(int) $v];
        }
      } else {
        $isNode = $xpath->query('.//x:is', $c)->item(0);
        if ($isNode) {
          $tNode = $xpath->query('.//x:t', $isNode)->item(0);
          if ($tNode) { $v = $tNode->nodeValue; }
        }
      }
      $vals[] = $v;
    }
    if (!empty($vals)) { $allRows[] = $vals; }
  }

  if (empty($allRows)) {
    $zip->close();
    set_flash('error', 'Fichier vide.');
    redirect_salary_page($mTarget, $yTarget);
  }

  $headerKeywords = ['matricule', 'nom & prénom', 'nom complet', 'nom', 'prénom', 'pseudo', 'peseudo', 'poste', 'jour normalement travaillé', 'abs non justifiée en jours', 'abs non justifiée en heures', 'jour payé', 'heure payée', 'nb retards', 'régime horaire', 'employee code', 'full name', 'position', 'total hours', 'hours', 'hourly rate'];
  $headers = [];
  $headerRowIndex = -1;

  foreach ($allRows as $index => $vals) {
    $normalized = array_map(function ($h) { return strtolower(trim((string) $h)); }, $vals);
    $matchCount = 0;
    foreach ($normalized as $h) {
      foreach ($headerKeywords as $kw) {
        if (strpos($h, $kw) !== false) { $matchCount++; break; }
      }
    }
    if ($matchCount >= 2 && empty($headers)) {
      $headers = $normalized;
      $headerRowIndex = $index;
      break;
    }
  }

  if (empty($headers)) {
    $headers = array_map(function ($h) { return strtolower(trim((string) $h)); }, $allRows[0]);
    $headerRowIndex = 0;
  }

  $firstRowAllNumeric = true;
  foreach ($allRows[0] as $v) { if (!is_numeric($v)) { $firstRowAllNumeric = false; break; } }
  if ($firstRowAllNumeric) {
    $headers = ['col0', 'col1', 'col2', 'col3', 'col4', 'col5', 'col6', 'col7', 'col8', 'col9'];
    $headerRowIndex = 0;
  }

  for ($i = $headerRowIndex + 1; $i < count($allRows); $i++) {
    $vals = array_slice($allRows[$i], 0, 10);
    $vals = array_pad($vals, 10, '');
    $data = [];
    if (!empty($headers)) {
      foreach ($headers as $idx => $h) { $data[$h] = $vals[$idx] ?? ''; }
    }
    if (!$data && !empty($headers)) { $data = []; foreach ($headers as $idx => $h) { $data[$h] = $vals[$idx] ?? ''; } }

    $code = trim((string) ($data['matricule'] ?? $data['employee id'] ?? $data['employee_code'] ?? $data['code'] ?? $vals[0] ?? ''));
    $name = trim((string) ($data['nom & prénom'] ?? $data['nom complet'] ?? $data['full_name'] ?? $data['name'] ?? ''));
    if ($name === '') {
      $name = trim((string) ($data['nom'] ?? '') . ' ' . ($data['prénom'] ?? $data['prenom'] ?? ''));
    }
    if ($name === '') {
      $name = trim((string) ($vals[0] ?? ''));
    }
    $monthVal = $mTarget;
    $yearVal = $yTarget;
    $normalDays = $data['jour normalement travaillé'] ?? 0;
    $absenceDays = $data['abs non justifiée en jours'] ?? 0;
    $absenceHours = $data['abs non justifiée en heures'] ?? 0;
    $paidDays = $data['jour payé'] ?? 0;
    $paidHours = $data['heure payée'] ?? 0;
    $lateCount = $data['nb retards'] ?? 0;
    $rate = $data['régime horaire'] ?? $data['hourly_rate'] ?? $data['taux horaire'] ?? 0;
    foreach (['normalDays', 'absenceDays', 'absenceHours', 'paidDays', 'paidHours', 'lateCount', 'rate'] as $numberKey) {
      $$numberKey = is_string($$numberKey) ? str_replace(',', '.', trim($$numberKey)) : $$numberKey;
    }

    if (!$code || !is_numeric($normalDays) || (float) $normalDays < 0 || !is_numeric($absenceDays) || (float) $absenceDays < 0 || !is_numeric($absenceHours) || (float) $absenceHours < 0 || !is_numeric($paidDays) || (float) $paidDays < 0 || !is_numeric($paidHours) || (float) $paidHours < 0 || !is_numeric($lateCount) || (int) $lateCount < 0) {
      $errors[] = 'Ligne ' . ($i + 1) . ' : données invalides.';
      continue;
    }

    $stmt = $pdo->prepare('SELECT id, hourly_rate, full_name, position FROM employees WHERE employee_code=:code LIMIT 1');
    $stmt->execute(['code' => $code]);
    $emp = $stmt->fetch();

    if (!$emp) {
      if (!$name) {
        $errors[] = 'Ligne ' . ($i + 1) . ' : nouveau matricule sans nom.';
        continue;
      }
      $position = trim((string) ($data['poste'] ?? $data['position'] ?? 'Agent'));
      $position = in_array($position, ['Responsable', 'Agent'], true) ? $position : 'Agent';
      $empRate = is_numeric($rate) ? (float) $rate : 0;
      if ($empRate <= 0) {
        $errors[] = 'Ligne ' . ($i + 1) . ' : nouveau salarié sans régime horaire valide.';
        continue;
      }
      $pseudo = trim((string) ($data['peseudo'] ?? $data['pseudo'] ?? ''));
      $pdo->prepare('INSERT INTO employees(employee_code, full_name, pseudo, position, contract_type, hourly_rate, status, start_date) VALUES(:code, :name, :pseudo, :position, :contract, :rate, :status, :start)')->execute([
        'code' => $code, 'name' => $name, 'pseudo' => $pseudo, 'position' => $position, 'contract' => 'CDI', 'rate' => $empRate, 'status' => 'Active', 'start' => date('Y-m-d')
      ]);
      $empId = (int) $pdo->lastInsertId();
      $emp = ['hourly_rate' => $empRate, 'position' => $position];
    } else {
      $empId = (int) $emp['id'];
    }

    $position = $emp['position'] ?? ($position ?? 'Agent');
    $rate = (float) ($emp['hourly_rate'] ?? $rate);
    if (!is_numeric($rate) || (float) $rate <= 0) {
      $errors[] = 'Ligne ' . ($i + 1) . ' : régime horaire manquant.';
      continue;
    }
    $rate = (float) $rate;
    $calcBase = $position === 'Responsable' ? (float) $paidDays : (float) $paidHours;
    $calc = round($calcBase * $rate, 2);
    $sql = 'INSERT INTO salary_records(employee_id, month, year, total_hours, hourly_rate_used, calculated_salary, normal_worked_days, unjustified_absence_days, unjustified_absence_hours, paid_days, paid_hours, late_count) VALUES(:eid, :m, :y, :h, :r, :s, :normal, :absdays, :abshours, :paiddays, :paidhours, :late) ON DUPLICATE KEY UPDATE total_hours=VALUES(total_hours), hourly_rate_used=VALUES(hourly_rate_used), calculated_salary=VALUES(calculated_salary), normal_worked_days=VALUES(normal_worked_days), unjustified_absence_days=VALUES(unjustified_absence_days), unjustified_absence_hours=VALUES(unjustified_absence_hours), paid_days=VALUES(paid_days), paid_hours=VALUES(paid_hours), late_count=VALUES(late_count), updated_at=CURRENT_TIMESTAMP';
    $pdo->prepare($sql)->execute(['eid' => $empId, 'm' => $monthVal, 'y' => $yearVal, 'h' => $paidHours, 'r' => $rate, 's' => $calc, 'normal' => $normalDays, 'absdays' => $absenceDays, 'abshours' => $absenceHours, 'paiddays' => $paidDays, 'paidhours' => $paidHours, 'late' => (int) $lateCount]);
    $ok++;
    $mTarget = $monthVal;
    $yTarget = $yearVal;
  }

  $zip->close();
  $msg = 'Import terminé : ' . $ok . ' ligne(s) traitée(s).';
  if ($errors) {
    $msg .= ' ' . count($errors) . ' ligne(s) ignorée(s). ' . implode(' | ', array_slice($errors, 0, 5));
  }
  set_flash(($ok > 0 && empty($errors)) ? 'success' : 'error', $msg);
  redirect_salary_page($mTarget, $yTarget);
}

$periodFilter = ($_GET['filter_period'] ?? '') === '1';
$q = trim((string) ($_GET['q'] ?? ''));

$sql = 'SELECT sr.*, e.full_name, e.employee_code, e.pseudo, e.position FROM salary_records sr JOIN employees e ON e.id = sr.employee_id WHERE 1=1';
$args = [];
if ($periodFilter) {
  $sql .= ' AND sr.month=:m AND sr.year=:y';
  $args['m'] = $month;
  $args['y'] = $year;
}
if ($q !== '') {
  $sql .= ' AND (e.full_name LIKE :q OR e.employee_code LIKE :q)';
  $args['q'] = '%' . $q . '%';
}
$sql .= ' ORDER BY sr.year DESC, sr.month DESC, e.full_name';

$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll();

$totalSalaries = 0;
$totalHours = 0;
foreach ($rows as $r) {
  $totalSalaries += (float) $r['calculated_salary'];
  $totalHours += (float) $r['total_hours'];
}

$allEmployees = $pdo->query('SELECT id, employee_code, full_name, position, hourly_rate FROM employees WHERE status="Active" ORDER BY full_name')->fetchAll();

$months = [
  1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin',
  7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
];
$displayMonth = $month;
$displayYear = $year;

require __DIR__ . '/includes/header.php';
?>

<section class="salary-import-panel">
  <div class="salary-import-heading">
    <div>
      <span class="eyebrow">Import paie</span>
      <h2>Importer les heures et régimes</h2>
      <p>Ajoutez plusieurs bulletins en une seule opération.</p>
    </div>
    <span class="salary-import-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 16V4M7 9l5-5 5 5"/><path d="M5 14v4.5A1.5 1.5 0 0 0 6.5 20h11a1.5 1.5 0 0 0 1.5-1.5V14"/></svg></span>
  </div>
  <form method="post" action="rh_salaries.php?month=<?= (int) $displayMonth ?>&year=<?= (int) $displayYear ?>" enctype="multipart/form-data" class="salary-import-form">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="import_salaries">
    <label class="salary-dropzone" for="salaryImportFile" data-salary-dropzone>
      <input type="file" id="salaryImportFile" name="import_file" accept=".xlsx" required data-salary-file>
      <span class="salary-dropzone-mark" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 16V4M7 9l5-5 5 5"/><path d="M5 14v4.5A1.5 1.5 0 0 0 6.5 20h11a1.5 1.5 0 0 0 1.5-1.5V14"/></svg></span>
      <span class="salary-dropzone-copy"><strong data-salary-file-name>Choisir un fichier XLSX</strong><small>ou déposez-le ici · <?= (int) (max_import_size_bytes($pdo) / 1024 / 1024) ?> Mo maximum</small></span>
      <span class="salary-dropzone-action">Parcourir</span>
    </label>
    <div class="salary-import-footer">
      <p><b>Entêtes acceptées :</b> Matricule, Nom, prénom, Peseudo, Jour Normalement Travaillé, ABS Non Justifiée en jours, ABS Non Justifiée en heures, Jour Payé, Heure Payée, Nb Retards.</p>
      <button type="submit" class="btn btn-primary btn-sm">Importer le fichier</button>
    </div>
  </form>
</section>

<div class="content-card">
  <div class="card-head">
    <h2>Salaires mensuels</h2>
    <div class="d-flex gap-8 flex-wrap">
       <button id="newSalaryBtn" class="btn btn-primary btn-sm" type="button">+ Nouveau salarié</button>
      <a class="btn btn-secondary btn-sm" href="actions/rh_export_salaries.php?month=<?= $displayMonth ?>&year=<?= $displayYear ?>">Exporter Excel</a>
    </div>
  </div>
  <div class="table-wrap" style="padding:0 0 0;">
    <div class="filter-card" style="border:none;border-bottom:1px solid var(--color-line);border-radius:0;margin:0;">
      <form class="row gap-3">
        <div class="col-md-3">
          <label class="form-label">Recherche</label>
          <input name="q" class="form-control" value="<?= e($q) ?>" placeholder="Nom ou matricule">
        </div>
        <div class="col-md-3">
          <label class="form-label">Mois</label>
          <select name="month" class="form-select">
            <?php for ($i = 1; $i <= 12; $i++): ?>
              <option value="<?= $i ?>" <?= $displayMonth === $i ? 'selected' : '' ?>><?= e($months[$i]) ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Année</label>
          <input type="number" name="year" class="form-control" value="<?= e($displayYear) ?>">
        </div>
        <div class="col-md-1 d-flex align-items-end gap-1">
          <input type="hidden" name="filter_period" value="1">
          <button type="submit" class="btn btn-light w-100">Filtrer</button>
        </div>
      </form>
      <div style="margin-top:10px">
        <a class="btn btn-sm btn-secondary" href="rh_salaries.php">Afficher toutes les périodes</a>
      </div>
    </div>
  </div>
</div>

<div class="form-card" id="manualSalaryCard" style="display:none">
  <div class="card-head"><h2>Saisie et calcul manuel du salaire</h2></div>
  <form method="post" action="actions/rh_salary_action.php">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="add">
    <div class="form-grid">
      <label>Employé<select class="form-control" name="employee_id" id="manualEmpSelect" required>
        <option value="">-- Sélectionner un employé --</option>
        <?php foreach ($allEmployees as $emp): ?>
          <option value="<?= (int) $emp['id'] ?>" data-rate="<?= (float) $emp['hourly_rate'] ?>" data-position="<?= e($emp['position'] ?? 'Agent') ?>"><?= e($emp['employee_code']) ?> — <?= e($emp['full_name']) ?> (<?= e($emp['position'] ?? 'Agent') ?>)</option>
        <?php endforeach; ?>
      </select></label>
      <label>Mois<select class="form-control" name="month" required>
        <?php for ($i = 1; $i <= 12; $i++): ?>
          <option value="<?= $i ?>" <?= $displayMonth === $i ? 'selected' : '' ?>><?= e($months[$i]) ?></option>
        <?php endfor; ?>
      </select></label>
      <label>Année<input class="form-control" type="number" name="year" value="<?= e($displayYear) ?>" min="2000" max="2100" required></label>
        <label>Jour Normalement Travaillé<input class="form-control" type="number" step="0.01" min="0" name="normal_worked_days" required></label>
        <label>ABS Non Justifiée en jours<input class="form-control" type="number" step="0.01" min="0" name="unjustified_absence_days" required value="0"></label>
        <label>ABS Non Justifiée en heures<input class="form-control" type="number" step="0.01" min="0" name="unjustified_absence_hours" required value="0"></label>
        <label>Jour Payé<input class="form-control" type="number" step="0.01" min="0" name="paid_days" required value="0"></label>
        <label>Heure Payée<input class="form-control" type="number" step="0.01" min="0" name="paid_hours" required value="0"></label>
        <label>Nb Retards<input class="form-control" type="number" step="1" min="0" name="late_count" required value="0"></label>
        <label>Régime horaire (TND / h)<input class="form-control form-readonly" type="text" id="manualRate" readonly value="—" aria-readonly="true"></label>
        <label>Salaire calculé (Aperçu)<input class="form-control form-readonly" type="text" id="manualCalcDisplay" readonly value="0,00 TND"></label>
    </div>
    <div class="actions-row">
      <button type="button" class="btn btn-secondary" data-close-salary-card>Annuler</button>
      <button type="submit" class="btn btn-primary">Calculer et enregistrer le salaire</button>
    </div>
  </form>
</div>

<div class="content-card">
  <div class="card-head">
    <h2>Bulletins du mois</h2>
    <span class="muted"><?= count($rows) ?> salarié(s)</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Matricule</th>
          <th>Nom & prénom</th>
          <th>Peseudo</th>
          <th>Jour Normalement Travaillé</th>
          <th>ABS Non Justifiée en jours</th>
          <th>ABS Non Justifiée en heures</th>
          <th>Jour Payé</th>
          <th>Heure Payée</th>
          <th>Nb Retards</th>
          <th>Salaire</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr class="data-row">
          <td><?= e($r['employee_code']) ?></td>
          <td><b><?= e($r['full_name']) ?></b></td>
          <td><?= e($r['pseudo'] ?: '—') ?></td>
          <td><?= format_nombre((float) $r['normal_worked_days']) ?></td>
          <td><?= format_nombre((float) $r['unjustified_absence_days']) ?></td>
          <td><?= format_nombre((float) $r['unjustified_absence_hours']) ?></td>
          <td><?= format_nombre((float) $r['paid_days']) ?></td>
          <td><?= format_nombre((float) $r['paid_hours']) ?></td>
          <td><?= (int) $r['late_count'] ?></td>
          <td><b><?= format_montant_tnd((float) $r['calculated_salary']) ?></b></td>
          <td>
            <button class="btn btn-sm btn-secondary" type="button" data-open-inline-edit>Modifier</button>
          </td>
        </tr>
        <tr class="edit-row" style="display:none">
          <td colspan="11">
            <form method="post" action="actions/rh_salary_action.php" class="inline-edit-form" data-position="<?= e($r['position'] ?? 'Agent') ?>">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <input type="hidden" name="month" value="<?= $displayMonth ?>">
              <input type="hidden" name="year" value="<?= $displayYear ?>">
              <div class="edit-inline-card">
                <div class="edit-inline-header">
                  <strong><?= e($r['full_name']) ?></strong>
                  <span class="muted" id="editCode"><?= e($r['employee_code']) ?></span>
                </div>
                <div class="form-grid form-grid--half">
                  <label>Jour Normalement Travaillé<input class="form-control" name="normal_worked_days" type="number" step="0.01" min="0" value="<?= e($r['normal_worked_days']) ?>" required></label>
                  <label>ABS Non Justifiée en jours<input class="form-control" name="unjustified_absence_days" type="number" step="0.01" min="0" value="<?= e($r['unjustified_absence_days']) ?>" required></label>
                  <label>ABS Non Justifiée en heures<input class="form-control" name="unjustified_absence_hours" type="number" step="0.01" min="0" value="<?= e($r['unjustified_absence_hours']) ?>" required></label>
                  <label>Jour Payé<input class="form-control" name="paid_days" type="number" step="0.01" min="0" value="<?= e($r['paid_days']) ?>" required></label>
                  <label>Heure Payée<input class="form-control" name="paid_hours" type="number" step="0.01" min="0" value="<?= e($r['paid_hours']) ?>" required></label>
                  <label>Nb Retards<input class="form-control" name="late_count" type="number" step="1" min="0" value="<?= e($r['late_count']) ?>" required></label>
                  <label>Régime horaire<input class="form-control form-readonly" name="hourly_rate_used" type="number" step="0.01" min="0" value="<?= e($r['hourly_rate_used']) ?>" readonly aria-readonly="true"></label>
                </div>
                <div class="edit-calc-preview">Salaire calculé : <strong class="inline-preview"><?= format_montant_tnd((float) $r['calculated_salary']) ?></strong></div>
                <div class="edit-inline-actions">
                  <button type="button" class="btn btn-secondary btn-sm" data-cancel-inline-edit>Annuler</button>
                  <button type="submit" class="btn btn-primary btn-sm">Enregistrer</button>
                </div>
              </div>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <tr>
          <td colspan="11">
            <div class="empty-state">
              <div class="empty-icon">&#128202;</div>
              Aucun salaire ne correspond à ces critères.
            </div>
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
