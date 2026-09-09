<?php
require_once __DIR__ . '/includes/init.php';
require_admin();

$pageTitle = 'Salaires mensuels';
$pageSubtitle = 'Gestion et contrôle de la paie';
$activePage = 'rh_salaries';
$pdo = $db;
$isAdmin = is_admin();

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

function salary_import_header(string $value): string
{
  $value = trim(mb_strtolower($value));
  $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
  $value = $ascii !== false ? $ascii : $value;
  return trim(preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '');
}

function salary_import_value(array $data, array $aliases, $default = '')
{
  foreach ($aliases as $alias) {
    $wanted = salary_import_header($alias);
    if (isset($data[$wanted]) && trim((string) $data[$wanted]) !== '') {
      return $data[$wanted];
    }
    foreach ($data as $key => $value) {
      $actual = salary_import_header((string) $key);
      if (($actual === $wanted || strpos($actual, $wanted . ' ') === 0) && trim((string) $value) !== '') {
        return $value;
      }
    }
  }
  return $default;
}

function format_heure($decimalHours)
{
  $hours = (int) $decimalHours;
  $minutes = (int) round(($decimalHours - $hours) * 60);
  return str_pad((string) $hours, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) $minutes, 2, '0', STR_PAD_LEFT);
}

function salary_import_number($value)
{
  if (is_int($value) || is_float($value)) {
    return $value;
  }
  $value = trim(str_replace(["\xC2\xA0", ' '], '', (string) $value));
  if ($value === '') {
    return '';
  }
  $value = str_replace(',', '.', $value);
  if (is_numeric($value)) {
    return $value;
  }
  return preg_match('/-?\d+(?:\.\d+)?/', $value, $match) ? $match[0] : '';
}

function salary_import_excel_time_to_hours($value)
{
  // Only call for numeric cells with an Excel time/duration number format.
  return is_numeric($value) ? (float) $value * 24 : $value;
}

function salary_import_time_styles(ZipArchive $zip): array
{
  $xml = $zip->getFromName('xl/styles.xml');
  $styles = $xml !== false ? simplexml_load_string($xml) : false;
  if (!$styles) return [];

  $formats = [];
  foreach ($styles->numFmts->numFmt ?? [] as $format) {
    $formats[(int) $format['numFmtId']] = (string) $format['formatCode'];
  }
  $timeStyles = [];
  foreach ($styles->cellXfs->xf ?? [] as $style) {
    $formatId = (int) $style['numFmtId'];
    $format = $formats[$formatId] ?? '';
    // Ignore literals, escapes, colours and conditions; retain elapsed-time tokens.
    $format = preg_replace('/"[^"]*"|\x5c.|\[(?![hms]+\])[^\]]*\]/i', '', $format);
    $timeStyles[] = in_array($formatId, [18, 19, 20, 21, 32, 33, 45, 46, 47], true)
      || (bool) preg_match('/h|s|\[m+\]/i', $format);
  }
  return $timeStyles;
}

function salary_import_time_to_decimal($value)
{
  if ($value === '' || $value === null) return 0;

  $str = trim((string) $value);
  if ($str === '') return 0;

  if (preg_match('/^(\d+):([0-5]\d)(?::([0-5]\d))?$/', $str, $m)) {
    $hours = (int) ($m[1] ?? 0);
    $minutes = (int) ($m[2] ?? 0);
    $seconds = isset($m[3]) ? (int) $m[3] : 0;
    return $hours + ($minutes / 60) + ($seconds / 3600);
  }

  // Numeric values are already decimal hours; Excel durations were converted on read.
  $str = str_replace(',', '.', $str);
  return is_numeric($str) ? (float) $str : -1;
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
  $inserts = [];
  $mTarget = $month;
  $yTarget = $year;

  $allEmployees = [];
  foreach ($pdo->query('SELECT id, employee_code, full_name, pseudo, hourly_rate, position FROM employees WHERE status="Active"') as $row) {
    $allEmployees[trim((string) $row['employee_code'])] = $row;
  }

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
        $text = '';
        $textNodes = $si->xpath('.//*[local-name()="t"]') ?: [];
        foreach ($textNodes as $textNode) {
          $text .= (string) $textNode;
        }
        $shared[] = $text !== '' ? $text : (string) $si;
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
  $timeStyles = salary_import_time_styles($zip);

  foreach ($rows as $row) {
    $vals = [];
    $cells = $xpath->query('.//x:c', $row);
    foreach ($cells as $c) {
      if (!$c instanceof DOMElement) {
        continue;
      }
      $r = $c->getAttribute('r');
      $columnLetters = '';
      if (preg_match('/^([A-Z]+)/', $r, $m)) { $columnLetters = $m[1]; }
      $columnIndex = 0;
      foreach (str_split($columnLetters) as $letter) {
        $columnIndex = ($columnIndex * 26) + ord($letter) - 64;
      }
      $columnIndex--;
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
          $textNodes = $xpath->query('.//x:t', $isNode);
          foreach ($textNodes as $textNode) { $v .= $textNode->nodeValue; }
        }
      }
      if (in_array($c->getAttribute('t'), ['', 'n'], true)
          && ($timeStyles[(int) $c->getAttribute('s')] ?? false)) {
        $v = salary_import_excel_time_to_hours($v);
      }
      if ($columnIndex >= 0) { $vals[$columnIndex] = $v; }
    }
    if ($vals) {
      ksort($vals);
      $vals = array_replace(array_fill(0, max(array_keys($vals)) + 1, ''), $vals);
    }
    if (!empty($vals)) { $allRows[] = $vals; }
  }

  if (empty($allRows)) {
    $zip->close();
    set_flash('error', 'Fichier vide.');
    redirect_salary_page($mTarget, $yTarget);
  }

  $headerKeywords = [
    'matricule', 'nom', 'prenom', 'nom et prenom', 'nom complet',
    'pseudo', 'peseudo', 'poste',
    'jr normalement travaill', 'jour normalement travaill',
    'abs non justif', 'abs nj',
    'j pay', 'jour pay',
    'h pay', 'heure pay',
    'nb retards', 'retards', 'n retards',
    'n depart', 'n d&eacute;part', 'n depart a',
    'heures sup', 'heure sup', 'sup',
    'r&eacute;gime horaire', 'r&eacute;gime', 'taux horaire',
    'employee code', 'full name', 'position', 'total hours', 'hours'
  ];
  $headers = [];
  $headerRowIndex = -1;

  foreach ($allRows as $index => $vals) {
    $normalized = array_map(function ($h) { return salary_import_header((string) $h); }, $vals);
    $matchCount = 0;
    foreach ($normalized as $h) {
      foreach ($headerKeywords as $kw) {
        if (strpos($h, salary_import_header($kw)) !== false) { $matchCount++; break; }
      }
    }
    if ($matchCount >= 2 && empty($headers)) {
      $headers = $normalized;
      $headerRowIndex = $index;
      break;
    }
  }

  if (empty($headers)) {
    $headers = array_map(function ($h) { return salary_import_header((string) $h); }, $allRows[0]);
    $headerRowIndex = 0;
  }

  $firstRowAllNumeric = true;
  foreach ($allRows[0] as $v) { if (!is_numeric($v)) { $firstRowAllNumeric = false; break; } }
  if ($firstRowAllNumeric) {
    $headers = array_map(static function ($index) { return 'col' . $index; }, array_keys($allRows[0]));
    $headerRowIndex = 0;
  }

  // The attendance report has an unlabelled pseudo column G, beside the merged name.
  if (($headers[1] ?? '') === 'matricule' && str_starts_with($headers[4] ?? '', 'nom')
      && ($headers[6] ?? '') === '' && str_starts_with($headers[13] ?? '', 'h pay')) {
    $headers[6] = 'pseudo';
  }

  for ($i = $headerRowIndex + 1; $i < count($allRows); $i++) {
    $vals = $allRows[$i];
    $vals = array_pad($vals, count($headers), '');

    $nonEmptyCount = 0;
    foreach ($vals as $v) {
      if (trim((string) $v) !== '') $nonEmptyCount++;
    }
    if ($nonEmptyCount < 3) continue;

    $data = [];
    if (!empty($headers)) {
      foreach ($headers as $idx => $h) { $data[$h] = $vals[$idx] ?? ''; }
    }
    if (!$data && !empty($headers)) { $data = []; foreach ($headers as $idx => $h) { $data[$h] = $vals[$idx] ?? ''; } }

    $code = trim((string) salary_import_value($data, ['Matricule', 'Employee ID', 'Employee code', 'Code'], $vals[0] ?? ''));
    $code = preg_replace('/[\s\-–—:]+$/', '', $code);
    $code = preg_replace('/^[\s\-–—:]+/', '', $code);
    $code = trim((string) $code);
    $code = str_replace([' ', '-', '–', '—'], '', $code);
    $name = trim((string) salary_import_value($data, ['Nom & prénom', 'Nom, prénom', 'Nom et prénom', 'Nom complet', 'Full name', 'Name']));
    if ($name === '') {
      $name = trim((string) salary_import_value($data, ['Nom']) . ' ' . (string) salary_import_value($data, ['Prénom', 'Prenom']));
    }
    if ($name === '') {
      $name = trim((string) ($vals[1] ?? ''));
      if (isset($vals[2]) && isset($headers[2]) && strpos($headers[2], 'prenom') !== false) {
        $name .= ' ' . trim((string) $vals[2]);
      }
    }
    $monthVal = $mTarget;
    $yearVal = $yTarget;
    $normalDays = salary_import_value($data, ['Jour normalement travaillé', 'Jr Normalement Travaillé', 'Normal worked days'], 0);
    $absenceDays = salary_import_value($data, ['ABS non justifiée en jours', 'Unjustified absence days'], 0);
    $absenceHours = salary_import_value($data, ['ABS non justifiée en heures', 'Unjustified absence hours'], $data['abs non justifiee'] ?? 0);
    $paidDays = salary_import_value($data, ['Jour payé', 'J Payé', 'Paid days'], 0);
    $paidHours = salary_import_value($data, ['Heure payée', 'H Payée', 'Paid hours', 'Total hours', 'Hours'], 0);
    $lateCount = salary_import_value($data, ['Nb retards', 'N Retards', 'Late count'], 0);
    $fileRate = salary_import_value($data, ['Régime horaire', 'Hourly rate', 'Taux horaire'], 0);
    foreach (['normalDays', 'absenceDays', 'paidDays', 'lateCount', 'fileRate'] as $numberKey) {
      $$numberKey = salary_import_number($$numberKey);
    }
    $absenceHours = salary_import_time_to_decimal($absenceHours);
    $paidHours = salary_import_time_to_decimal($paidHours);

    if (!$code || !is_numeric($normalDays) || (float) $normalDays < 0 || !is_numeric($absenceDays) || (float) $absenceDays < 0 || !is_numeric($absenceHours) || (float) $absenceHours < 0 || !is_numeric($paidDays) || (float) $paidDays < 0 || !is_numeric($paidHours) || (float) $paidHours < 0 || !is_numeric($lateCount) || (int) $lateCount < 0) {
      $errors[] = 'Ligne ' . ($i + 1) . ' : données invalides.';
      continue;
    }

    $emp = $allEmployees[$code] ?? null;
    if (!$emp) {
      $errors[] = 'Ligne ' . ($i + 1) . ' : matricule introuvable ( ' . $code . ' ).';
      continue;
    }
    $empId = (int) $emp['id'];
    $empRate = (float) $emp['hourly_rate'];
    if ($fileRate > 0) {
      $empRate = $fileRate;
    }
    if ($empRate <= 0) {
      $empRate = 10.00;
    }
    $position = trim((string) salary_import_value($data, ['Poste', 'Position'], $emp['position'] ?? 'Agent'));
    $empPosition = in_array($position, ['Responsable', 'Agent'], true) ? $position : ($emp['position'] ?? 'Agent');
    $pseudo = trim((string) salary_import_value($data, ['Pseudo', 'Peseudo'], $emp['pseudo'] ?? ''));
    $empName = $name !== '' ? $name : $emp['full_name'];
    $pdo->prepare('UPDATE employees SET full_name=:name, pseudo=:pseudo, hourly_rate=:rate, position=:position WHERE id=:id')->execute([
      'name' => $empName, 'pseudo' => $pseudo, 'rate' => $empRate, 'position' => $empPosition, 'id' => $empId
    ]);
    $position = $empPosition;
    $rate = $empRate;
    $calcBase = $position === 'Responsable' ? (float) $paidDays : (float) $paidHours;
    $calc = round($calcBase * $rate, 2);
    $inserts[] = ['eid' => $empId, 'm' => $monthVal, 'y' => $yearVal, 'h' => $paidHours, 'r' => $rate, 's' => $calc, 'normal' => $normalDays, 'absdays' => $absenceDays, 'abshours' => $absenceHours, 'paiddays' => $paidDays, 'paidhours' => $paidHours, 'late' => (int) $lateCount];
    $ok++;
    $mTarget = $monthVal;
    $yTarget = $yearVal;
  }

  if (!empty($inserts)) {
    $pdo->beginTransaction();
    foreach ($inserts as $ins) {
      $sql = 'INSERT INTO salary_records(employee_id, month, year, total_hours, hourly_rate_used, calculated_salary, normal_worked_days, unjustified_absence_days, unjustified_absence_hours, paid_days, paid_hours, late_count) VALUES(:eid, :m, :y, :h, :r, :s, :normal, :absdays, :abshours, :paiddays, :paidhours, :late) ON DUPLICATE KEY UPDATE total_hours=VALUES(total_hours), hourly_rate_used=VALUES(hourly_rate_used), calculated_salary=VALUES(calculated_salary), normal_worked_days=VALUES(normal_worked_days), unjustified_absence_days=VALUES(unjustified_absence_days), unjustified_absence_hours=VALUES(unjustified_absence_hours), paid_days=VALUES(paid_days), paid_hours=VALUES(paid_hours), late_count=VALUES(late_count), updated_at=CURRENT_TIMESTAMP';
      $pdo->prepare($sql)->execute($ins);
    }
    $pdo->commit();
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
$sql .= ' ORDER BY sr.year DESC, sr.month DESC, CAST(e.employee_code AS UNSIGNED) ASC, e.employee_code ASC';

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
      <p><b>En-têtes acceptées :</b> Matricule, Nom, Prénom, Pseudo, Jr Normalement Travaillé, ABS Non Justifiée en jours, ABS Non Justifiée en heures, J Payé, H Payée, Nb Retards, Heures Sup, N Départ, Régime horaire.</p>
      <button type="submit" class="btn btn-primary btn-sm">Importer le fichier</button>
    </div>
  </form>
</section>

  <div class="content-card">
    <div class="card-head">
      <h2>Salaires mensuels</h2>
      <div class="d-flex gap-8 flex-wrap">
         <button id="newSalaryBtn" class="btn btn-primary btn-sm" type="button">+ Nouveau salarié</button>
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
        <label>Heures Sup<input class="form-control" type="number" step="0.01" min="0" name="heures_sup" required value="0"></label>
        <label>N Départ<input class="form-control" type="number" step="1" min="0" name="n_depart" required value="0"></label>
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
  <?php if ($isAdmin && $rows): ?>
  <div class="bulk-actions-bar" style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;margin-bottom:16px;gap:12px;flex-wrap:wrap;">
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:500;color:#495057;user-select:none;">
        <input type="checkbox" id="selectAllSalaries" style="width:18px;height:18px;cursor:pointer;accent-color:#0d6efd;">
        <span>Tout sélectionner</span>
      </label>
      <span id="selectionCount" style="display:none;padding:4px 12px;background:#0d6efd;color:white;border-radius:12px;font-size:13px;font-weight:500;"></span>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
      <form method="post" action="actions/rh_salary_action.php" id="bulkDeleteForm" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="bulk_delete">
        <input type="hidden" name="month" value="<?= $displayMonth ?>">
        <input type="hidden" name="year" value="<?= $displayYear ?>">
        <div id="selectedIdsContainer"></div>
        <button type="submit" class="btn btn-danger btn-sm" id="bulkDeleteBtn" data-confirm="Supprimer les bulletins sélectionnés ?" style="opacity:0.5;cursor:not-allowed;">
          🗑 Supprimer la sélection
        </button>
      </form>
      <form method="post" action="actions/rh_salary_action.php" id="deleteAllForm" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_all">
        <input type="hidden" name="month" value="<?= $displayMonth ?>">
        <input type="hidden" name="year" value="<?= $displayYear ?>">
        <button type="button" class="btn btn-outline btn-sm" id="deleteAllBtn" data-confirm="Supprimer TOUS les bulletins de ce mois ? Cette action est irréversible !" data-confirm-target="deleteAllForm" style="border-color:#dc3545;color:#dc3545;">
          🗑🗑 Supprimer tous
        </button>
      </form>
    </div>
  </div>
  <?php endif; ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <?php if ($isAdmin): ?><th style="width:50px;text-align:center;vertical-align:middle;"><input type="checkbox" id="selectAllCheckboxes" style="width:18px;height:18px;cursor:pointer;accent-color:#0d6efd;"></th><?php endif; ?>
          <th>ID</th>
          <th>Nom & prénom</th>
          <th>Pseudo</th>
          <th>Jour Normalement Travaillé</th>
          <th>ABS Non Justifiée en jours</th>
          <th>ABS Non Justifiée en heures</th>
          <th>Jour Payé</th>
          <th>Heure Payée</th>
          <th>Nb Retards</th>
          <th style="min-width:140px;white-space:nowrap;">Salaire</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr class="data-row">
          <?php if ($isAdmin): ?><td style="width:50px;text-align:center;vertical-align:middle;"><input type="checkbox" name="salary_ids[]" value="<?= (int) $r['id'] ?>" class="salary-checkbox" style="width:18px;height:18px;cursor:pointer;accent-color:#0d6efd;"></td><?php endif; ?>
          <td><a href="<?= e(APP_URL) ?>/rh_employees.php?edit=<?= (int) $r['employee_id'] ?>" style="color:inherit;text-decoration:underline;font-weight:600;"><?= e($r['employee_code']) ?></a></td>
          <td><b><?= e($r['full_name']) ?></b></td>
          <td><?= e($r['pseudo'] ?? '') ?></td>
          <td><?= format_nombre((float) $r['normal_worked_days']) ?></td>
          <td><?= format_nombre((float) $r['unjustified_absence_days']) ?></td>
          <td><?= format_heure((float) $r['unjustified_absence_hours']) ?></td>
          <td><?= format_nombre((float) $r['paid_days']) ?></td>
          <td><?= format_heure((float) $r['paid_hours']) ?></td>
          <td><?= (int) $r['late_count'] ?></td>
          <td style="white-space:nowrap;"><b><?= format_montant_tnd((float) $r['calculated_salary']) ?></b></td>
          <td class="nowrap">
            <button class="btn btn-sm btn-secondary btn-icon" type="button" data-open-inline-edit title="Modifier" aria-label="Modifier"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg></button>
            <?php if ($isAdmin): ?>
            <form class="inline" method="post" action="actions/rh_salary_action.php" data-confirm="Supprimer ce bulletin de salaire ?">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <input type="hidden" name="month" value="<?= $displayMonth ?>">
              <input type="hidden" name="year" value="<?= $displayYear ?>">
              <button type="submit" class="btn btn-sm btn-danger btn-icon" title="Supprimer" aria-label="Supprimer"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6h14z"/><path d="M10 11v6M14 11v6"/></svg></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <tr class="edit-row" style="display:none">
          <td colspan="12">
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
                  <label>Nb Retards<input class="form-control" name="late_count" type="number" step="1" min="0" value="<?= (int) $r['late_count'] ?>" required></label>
                  <label>Heures Sup<input class="form-control" name="heures_sup" type="number" step="0.01" min="0" value="<?= e($r['heures_sup']) ?>" required></label>
                  <label>N Départ<input class="form-control" name="n_depart" type="number" step="1" min="0" value="<?= (int) $r['n_depart'] ?>" required></label>
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
          <td colspan="12">
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

<script>
document.addEventListener('DOMContentLoaded', function() {
  var selectAll = document.getElementById('selectAllSalaries');
  var selectAllHeader = document.getElementById('selectAllCheckboxes');
  var checkboxes = document.querySelectorAll('.salary-checkbox');
  var bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
  var selectionCount = document.getElementById('selectionCount');
  var bulkDeleteForm = document.getElementById('bulkDeleteForm');
  var selectedIdsContainer = document.getElementById('selectedIdsContainer');

  function updateSelectionUI() {
    var checked = document.querySelectorAll('.salary-checkbox:checked');
    var count = checked.length;
    var allChecked = count > 0 && count === checkboxes.length;

    if (selectAll) selectAll.checked = allChecked;
    if (selectAllHeader) selectAllHeader.checked = allChecked;

    if (bulkDeleteBtn) {
      if (count > 0) {
        bulkDeleteBtn.disabled = false;
        bulkDeleteBtn.style.opacity = '1';
        bulkDeleteBtn.style.cursor = 'pointer';
      } else {
        bulkDeleteBtn.disabled = true;
        bulkDeleteBtn.style.opacity = '0.5';
        bulkDeleteBtn.style.cursor = 'not-allowed';
      }
    }

    if (selectionCount) {
      if (count > 0) {
        selectionCount.textContent = count + ' sélectionné(s)';
        selectionCount.style.display = 'inline';
      } else {
        selectionCount.style.display = 'none';
      }
    }
  }

  function toggleAll(source) {
    checkboxes.forEach(function(cb) {
      cb.checked = source.checked;
    });
    updateSelectionUI();
  }

  if (selectAll) {
    selectAll.addEventListener('change', function() { toggleAll(this); });
  }

  if (selectAllHeader) {
    selectAllHeader.addEventListener('change', function() { toggleAll(this); });
  }

  checkboxes.forEach(function(cb) {
    cb.addEventListener('change', updateSelectionUI);
  });

  if (bulkDeleteForm) {
    bulkDeleteForm.addEventListener('submit', function(e) {
      var checked = document.querySelectorAll('.salary-checkbox:checked');
      if (checked.length === 0) {
        e.preventDefault();
        alert('Veuillez sélectionner au moins un bulletin à supprimer.');
        return false;
      }

      if (selectedIdsContainer) {
        selectedIdsContainer.innerHTML = '';
        checked.forEach(function(cb) {
          var input = document.createElement('input');
          input.type = 'hidden';
          input.name = 'salary_ids[]';
          input.value = cb.value;
          selectedIdsContainer.appendChild(input);
        });
      }

      return true;
    });
  }

  updateSelectionUI();
});
</script>
