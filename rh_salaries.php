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

$pageTitle = 'Salaires mensuels';
$pageSubtitle = 'Gestion et contrôle de la paie';
$activePage = 'rh_salaries';
$pdo = $db;
// Droits d'écriture dans le module RH : administrateur ET compte RH ont
// exactement les mêmes fonctions (création, modification, suppression).
$isAdmin = can_access_rh();
// Colonnes « nom » / « prenom » (migration idempotente et non destructive).
// Elles permettent à l'import de poser le nom et le prénom dans deux colonnes
// distinctes au lieu de les coller dans `full_name`.
$nomColonnes = rh_ensure_name_columns($pdo);

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

/**
 * Clé de recherche d'un matricule, insensible à la forme rencontrée dans les
 * rapports de pointage : « 1 - », « 01 », « 1 » et « 01 » désignent le même
 * employé.
 *
 * Sans cette normalisation, la ligne était rejetée à tort en « matricule
 * introuvable » : la base enregistre certains matricules avec des zéros
 * initiaux (01, 02, 03…) tandis que le rapport de pointage les exporte sans
 * remplissage (1, 2, 3…).
 */
function salary_import_code_key($code): string
{
    $key = mb_strtoupper(trim((string) $code));
    $key = preg_replace('/[^A-Z0-9]+/', '', $key) ?? '';
    if ($key !== '' && ctype_digit($key)) {
        $key = ltrim($key, '0');
        if ($key === '') {
            $key = '0';
        }
    }
    return $key;
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

/**
 * Vrai si la colonne est un COMPTEUR ou un nombre de JOURS — donc PAS une durée.
 *
 * Excel applique très souvent le format horaire d'une colonne à sa voisine
 * (colonne recopiée depuis « H Payée » : la mise en forme se propage) : la
 * lecture multipliait alors la valeur par 24 et « 3 retards » devenait 72. Ces
 * colonnes sont donc réinjectées depuis la lecture BRUTE (voir $allRowsRaw).
 *
 * L'en-tête est déjà NORMALISÉ (accents et ponctuation retirés par
 * salary_import_header()).
 */
function salary_import_is_counter_column(string $normalizedHeader): bool
{
    if ($normalizedHeader === '') {
        return false;
    }
    // Compteurs explicites : « Nb Retards », « N Départ ».
    if (preg_match('/retard|depart/', $normalizedHeader)) {
        return true;
    }
    // Nombres de jours : « J Payé », « Jr Normalement Travaillé »,
    // « ABS Non Justifiée en jours », « Nombre de personnes ».
    return (bool) preg_match('/en jours$|^j ?pay|^jour ?pay|^jr |^jour normal|nombre de personnes/', $normalizedHeader);
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
  $createdEmployees = [];
  $noRateEmployees = [];
  $mTarget = $month;
  $yTarget = $year;

  // Index des employés par matricule NORMALISÉ : « 1 », « 01 » et « 1 - »
  // retombent sur la même entrée (voir salary_import_code_key).
  $allEmployees = [];
  foreach ($pdo->query('SELECT id, employee_code, full_name, pseudo, hourly_rate, position FROM employees WHERE status="Active"') as $row) {
    $allEmployees[salary_import_code_key($row['employee_code'])] = $row;
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
  // Valeurs BRUTES, relevées AVANT la conversion d'un format Excel en heures :
  // elles servent plus loin à réaffirmer les colonnes de COMPTEUR et de JOURS
  // (Nb Retards, N Départ, J Payé…), qui ne doivent jamais être multipliées
  // par 24. Voir $allRowsRaw et salary_import_is_counter_column().
  $allRowsRaw = [];
  $timeStyles = salary_import_time_styles($zip);

  foreach ($rows as $row) {
    $vals = [];
    $rawVals = [];
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
      $raw = $v;
      if (in_array($c->getAttribute('t'), ['', 'n'], true)
          && ($timeStyles[(int) $c->getAttribute('s')] ?? false)) {
        $v = salary_import_excel_time_to_hours($v);
      }
      if ($columnIndex >= 0) {
        $vals[$columnIndex] = $v;
        $rawVals[$columnIndex] = $raw;
      }
    }
    if ($vals) {
      ksort($vals);
      $vals = array_replace(array_fill(0, max(array_keys($vals)) + 1, ''), $vals);
      if ($rawVals) {
        ksort($rawVals);
        $rawVals = array_replace(array_fill(0, max(array_keys($rawVals)) + 1, ''), $rawVals);
      }
    }
    if (!empty($vals)) {
      $allRows[] = $vals;
      $allRowsRaw[] = $rawVals;
    }
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

  // Toute l'importation se fait DANS une transaction : la création automatique
  // d'une fiche employé et l'insertion des bulletins de salaire deviennent
  // atomiques. Avant, les fiches étaient créées hors transaction : si
  // l'insertion des salaires échouait, il restait des employés orphelins sans
  // aucun bulletin. En cas d'erreur, rien n'est conservé.
  $importEnCours = false;
  try {
    $pdo->beginTransaction();
    $importEnCours = true;
  } catch (Throwable $e) {
    error_log('rh_salary_import_begin: ' . $e->getMessage());
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

    // Compteurs et jours : on repart de la valeur BRUTE, jamais de la valeur
    // convertie en heures. Sans cela, un « Nb Retards » = 3 dont la cellule porte
    // un format horaire était enregistré à 72 (3 × 24).
    $rawRow = $allRowsRaw[$i] ?? null;
    if (is_array($rawRow)) {
        foreach ($headers as $idx => $h) {
            if (salary_import_is_counter_column((string) $h) && array_key_exists($idx, $rawRow)) {
                $data[$h] = $rawRow[$idx];
            }
        }
    }

    $code = trim((string) salary_import_value($data, ['Matricule', 'Employee ID', 'Employee code', 'Code'], $vals[0] ?? ''));
    $code = preg_replace('/[\s\-–—:]+$/', '', $code);
    $code = preg_replace('/^[\s\-–—:]+/', '', $code);
    $code = trim((string) $code);
    $code = str_replace([' ', '-', '–', '—'], '', $code);
    // Le rapport peut livrer soit deux colonnes (« Nom » / « Prénom »), soit
    // une seule colonne « Nom » — parfois intitulée « Nom & prénom » — qui
    // contient le nom ET le prénom. On en déduit donc TOUJOURS un couple
    // (nom, prénom) distinct : plus de fiche « EL KHOUDINARIMENE » où les deux
    // informations se retrouvent collées dans une seule colonne.
    $nomSource = trim((string) salary_import_value($data, ['Nom']));
    $prenomSource = trim((string) salary_import_value($data, ['Prénom', 'Prenom']));
    $combineSource = trim((string) salary_import_value(
      $data,
      ['Nom & prénom', 'Nom, prénom', 'Nom et prénom', 'Nom prenom', 'Nom complet', 'Full name', 'Name']
    ));

    if ($nomSource === '' && $prenomSource === '' && $combineSource === '') {
      // Repli : rapport sans en-tête reconnu, le nom est en colonne B.
      $nomSource = trim((string) ($vals[1] ?? ''));
      if (isset($vals[2]) && isset($headers[2]) && strpos($headers[2], 'prenom') !== false) {
        $prenomSource = trim((string) $vals[2]);
      }
    }

    if ($nomSource !== '' && $prenomSource !== '') {
      // Deux colonnes dédiées : on les respecte telles quelles.
      $nom = $nomSource;
      $prenom = $prenomSource;
    } else {
      // Une seule valeur : on la découpe. Les sauts de ligne du rapport
      // (« MAAMAR⏎WAFA ») sont ramenés à un espace par split_person_name().
      $decoupe = split_person_name($nomSource !== '' ? $nomSource : $combineSource);
      $nom = $decoupe['nom'];
      $prenom = $decoupe['prenom'] !== '' ? $decoupe['prenom'] : $prenomSource;
    }
    $name = normaliser_nom($nom . ' ' . $prenom);
    $pseudo = trim(preg_replace('/\s+/u', ' ', (string) salary_import_value($data, ['Pseudo', 'Peseudo'], '')) ?? '');
    $monthVal = $mTarget;
    $yearVal = $yTarget;
    $normalDays = salary_import_value($data, ['Jour normalement travaillé', 'Jr Normalement Travaillé', 'Normal worked days'], 0);
    $absenceDays = salary_import_value($data, ['ABS non justifiée en jours', 'Unjustified absence days'], 0);
    // Les clés de $data sont NORMALISÉES par salary_import_header() : l'accent
    // de « Justifiée » devient « justifi ee » (espace inséré par iconv). Interrogée
    // en brut, la clé $data['abs non justifiee'] n'existait donc jamais et les
    // heures d'absence retombaient toujours à 0. On interroge la clé normalisée.
    $absenceHoursKey = salary_import_header('ABS non justifiée');
    $absenceHours = salary_import_value($data, ['ABS non justifiée en heures', 'Unjustified absence hours'], $data[$absenceHoursKey] ?? 0);
    $paidDays = salary_import_value($data, ['Jour payé', 'J Payé', 'Paid days'], 0);
    $paidHours = salary_import_value($data, ['Heure payée', 'H Payée', 'Paid hours', 'Total hours', 'Hours'], 0);
    // Aliases élargis : le rapport de pointage nomme cette colonne de façons
    // très variables. salary_import_value() compare la version NORMALISÉE de
    // l'en-tête (accents et ponctuation retirés), d'où « Nbr retards »,
    // « Nb. Retards »… qui retombent tous sur « nb retards ».
    $lateCount = salary_import_value($data, ['Nb retards', 'N Retards', 'Nb de retards', 'Nbr retards', 'Nombre de retards', 'Retards', 'Retard', 'Late count'], 0);
    $overtimeHours = salary_import_value($data, ['Heures Sup', 'Heure Sup', 'Heures supplémentaires', 'Overtime hours'], 0);
    $lateDepartures = salary_import_value($data, ['N Départ A', 'N Départ', 'N Depart', 'Nombre de départs', 'Departures'], 0);
    $fileRate = salary_import_value($data, ['Régime horaire', 'Hourly rate', 'Taux horaire'], 0);
    foreach (['normalDays', 'absenceDays', 'paidDays', 'lateCount', 'fileRate'] as $numberKey) {
      $$numberKey = salary_import_number($$numberKey);
    }
    $absenceHours = salary_import_time_to_decimal($absenceHours);
    $paidHours = salary_import_time_to_decimal($paidHours);
    // « Heures Sup » est une durée Excel : déjà convertie en heures à la
    // lecture, salary_import_time_to_decimal ne fait donc qu'un passage en
    // nombre. « N Départ » est un simple compteur.
    $overtimeHours = salary_import_time_to_decimal($overtimeHours);
    $lateDepartures = salary_import_number($lateDepartures);

    // Un COMPTEUR illisible ou vide vaut zéro, et ne doit jamais faire perdre le
    // bulletin de salaire de l'employé : avant correction, une cellule « - » ou
    // « N/A » dans la colonne « Nb Retards » faisait ignorer toute la ligne.
    if ($lateCount === '' || !is_numeric($lateCount) || (int) $lateCount < 0) {
        if (trim((string) $lateCount) !== '' && (string) $lateCount !== '0') {
            error_log('rh_salary_import_retards: valeur illisible (' . (string) $lateCount . ') => 0, ligne ' . ($i + 1));
        }
        $lateCount = 0;
    }
    if ($lateDepartures === '' || !is_numeric($lateDepartures) || (int) $lateDepartures < 0) {
        $lateDepartures = 0;
    }

    if (!$code || !is_numeric($normalDays) || (float) $normalDays < 0 || !is_numeric($absenceDays) || (float) $absenceDays < 0 || !is_numeric($absenceHours) || (float) $absenceHours < 0 || !is_numeric($paidDays) || (float) $paidDays < 0 || !is_numeric($paidHours) || (float) $paidHours < 0) {
      $errors[] = 'Ligne ' . ($i + 1) . ' : données invalides.';
      continue;
    }

    $emp = $allEmployees[salary_import_code_key($code)] ?? null;
    if (!$emp) {
      // Le matricule du rapport de pointage n'existe pas encore dans la base.
      // Plutôt que de perdre la ligne de salaire, la fiche employé est créée
      // automatiquement (taux horaire 0 => le calcul bascule sur le taux de
      // repli de 10,00 € ; le poste et le taux restent à compléter depuis la
      // page « Employés »). La création est signalée dans le message final.
      try {
        // Le nom et le prénom sont posés séparément dès la création. Une
        // information absente du rapport est écrite « Non renseigné » plutôt
        // que laissée vide, pour que la fiche soit tout de suite lisible.
        $nomEmploye = $nom !== '' ? $nom : 'Non renseigné';
        $prenomEmploye = $prenom !== '' ? $prenom : 'Non renseigné';
        $fullEmploye = $name !== '' ? $name : ('Matricule ' . $code);
        if ($nom === '' && $prenom === '') {
          $nomEmploye = $fullEmploye;
          $prenomEmploye = 'Non renseigné';
        }

        $insertCols = 'employee_code, full_name, pseudo, position, department, contract_type, hourly_rate, status';
        $insertVals = ':code, :name, :pseudo, :position, :department, :contract_type, 0, "Active"';
        $insertParams = [
          'code' => $code,
          'name' => $fullEmploye,
          'pseudo' => $pseudo,
          'position' => 'Agent',
          'department' => '',
          'contract_type' => '',
        ];
        if ($nomColonnes) {
          $insertCols .= ', nom, prenom';
          $insertVals .= ', :nom, :prenom';
          $insertParams['nom'] = $nomEmploye;
          $insertParams['prenom'] = $prenomEmploye;
        }
        $pdo->prepare('INSERT INTO employees (' . $insertCols . ') VALUES (' . $insertVals . ')')
            ->execute($insertParams);
        $emp = [
            'id' => (int) $pdo->lastInsertId(),
            'employee_code' => $code,
            'full_name' => $fullEmploye,
            'nom' => $nomEmploye,
            'prenom' => $prenomEmploye,
            'pseudo' => $pseudo,
            'hourly_rate' => 0,
            'position' => 'Agent',
        ];
        $allEmployees[salary_import_code_key($code)] = $emp;
        $createdEmployees[$code] = (string) $emp['full_name'];
      } catch (Throwable $e) {
        error_log('rh_salary_import_auto_employee: ' . $e->getMessage());
        $errors[] = 'Ligne ' . ($i + 1) . ' : matricule introuvable ( ' . $code . ' ).';
        continue;
      }
    }
    $empId = (int) $emp['id'];
    $empRate = (float) $emp['hourly_rate'];
    if ($fileRate > 0) {
      $empRate = $fileRate;
    }
    // L'import ne modifie plus la fiche employé : on utilise uniquement
    // les informations déjà enregistrées dans rh_employees.php.
    //
    // « Régime horaire » (TND/h) non renseigné sur la fiche => AUCUN taux n'est
    // inventé : le salaire reste à 0,00 TND, exactement comme le saisie manuelle
    // (voir $calc = $h * $rate plus haut). Il suffira de renseigner le régime
    // horaire dans rh_employees.php puis de RÉIMPORTER le fichier : le calcul
    // se met à jour (INSERT ... ON DUPLICATE KEY UPDATE).
    $position = $emp['position'] ?? 'Agent';
    $rate = $empRate > 0 ? round($empRate, 2) : 0.0;
    if ($rate <= 0) {
      $noRateEmployees[$code] = (string) $emp['full_name'];
    }
    $calcBase = $position === 'Responsable' ? (float) $paidDays : (float) $paidHours;
    $calc = round($calcBase * $rate, 2);
    // total_hours = total réellement travaillé sur le mois = heures payées +
    // heures supplémentaires. Avant, cette colonne recevait les heures payées,
    // ce qui la rendait identique à paid_hours (colonne « Heures » de
    // rh_history.php) et faussait le cumul « Total heures ».
    $totalHours = round((float) $paidHours + (float) $overtimeHours, 2);
    $inserts[] = ['eid' => $empId, 'm' => $monthVal, 'y' => $yearVal, 'h' => $totalHours, 'r' => $rate, 's' => $calc, 'normal' => $normalDays, 'absdays' => $absenceDays, 'abshours' => $absenceHours, 'paiddays' => $paidDays, 'paidhours' => $paidHours, 'late' => (int) $lateCount, 'heuresup' => $overtimeHours, 'ndepart' => (int) $lateDepartures];
    $ok++;
    $mTarget = $monthVal;
    $yTarget = $yearVal;
  }

  if (!empty($inserts)) {
    try {
      foreach ($inserts as $ins) {
        $sql = 'INSERT INTO salary_records(employee_id, month, year, total_hours, hourly_rate_used, calculated_salary, normal_worked_days, unjustified_absence_days, unjustified_absence_hours, paid_days, paid_hours, late_count, heures_sup, n_depart) VALUES(:eid, :m, :y, :h, :r, :s, :normal, :absdays, :abshours, :paiddays, :paidhours, :late, :heuresup, :ndepart) ON DUPLICATE KEY UPDATE total_hours=VALUES(total_hours), hourly_rate_used=VALUES(hourly_rate_used), calculated_salary=VALUES(calculated_salary), normal_worked_days=VALUES(normal_worked_days), unjustified_absence_days=VALUES(unjustified_absence_days), unjustified_absence_hours=VALUES(unjustified_absence_hours), paid_days=VALUES(paid_days), paid_hours=VALUES(paid_hours), late_count=VALUES(late_count), heures_sup=VALUES(heures_sup), n_depart=VALUES(n_depart), updated_at=CURRENT_TIMESTAMP';
        $pdo->prepare($sql)->execute($ins);
      }
      if ($importEnCours) {
        $pdo->commit();
        $importEnCours = false;
      }
    } catch (Throwable $e) {
      if ($importEnCours && $pdo->inTransaction()) {
        $pdo->rollBack();
      }
      error_log('rh_salary_import_insert: ' . $e->getMessage());
      $zip->close();
      set_flash('error', 'Erreur lors de l\'enregistrement des salaires : aucune donnée n\'a été enregistrée.');
      redirect_salary_page($mTarget, $yTarget);
    }
  } elseif ($importEnCours && $pdo->inTransaction()) {
    $pdo->rollBack();
    $importEnCours = false;
  }

  $zip->close();
  // Message volontairement court : « Import terminé : N ligne(s) traitée(s). »
  // Seules les ERREURS sont affichées, pour ne jamais masquer une ligne perdue.
  // Les fiches créées automatiquement et les salariés sans « Régime horaire »
  // ne polluent plus l'écran : ils restent consultables sur la page « Employés »
  // et le détail est tracé dans le journal serveur.
  if ($createdEmployees || $noRateEmployees) {
    error_log('rh_salary_import: ' . $ok . ' ligne(s) traitée(s)'
      . ($createdEmployees ? ' | fiches créées : ' . implode(', ', array_keys($createdEmployees)) : '')
      . ($noRateEmployees ? ' | sans régime horaire (salaire 0,00 TND) : ' . implode(', ', array_keys($noRateEmployees)) : ''));
  }
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
  // Meme contrainte que dans rh_employees.php : avec ATTR_EMULATE_PREPARES = false,
  // un marqueur nomme REUTILISE (:q sur plusieurs colonnes) provoque un
  // « SQLSTATE[HY093] Invalid parameter number ». Un marqueur distinct par colonne.
  $searchCols = ['e.full_name'];
  if ($nomColonnes) {
    $searchCols[] = 'e.nom';
    $searchCols[] = 'e.prenom';
  }
  $searchCols[] = 'e.employee_code';
  $clauses = [];
  foreach ($searchCols as $i => $col) {
    $clauses[] = $col . ' LIKE :q' . $i;
    $args['q' . $i] = '%' . $q . '%';
  }
  $sql .= ' AND (' . implode(' OR ', $clauses) . ')';
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

<style>
  /* Page « Salaires mensuels » — carte filtre compacte + tableau sans
     défilement horizontal (tout tient sur 100% de la carte). */
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
  /* Même hauteur pour les 3 champs, mais Recherche plus large. */
  .salary-filter-form .sf-search { flex: 1 1 200px; width: 220px; max-width: 260px; min-width: 180px; }
  .salary-filter-form .sf-month,
  .salary-filter-form .sf-year { flex: 0 1 150px; width: 150px; max-width: 160px; min-width: 140px; }
  .salary-filter-form .sf-actions { flex: 0 0 auto; display: flex; align-items: flex-end; gap: 8px; }
  .salary-filter-card .form-label { margin-bottom: 4px; font-size: 0.74rem; }
  /* Même hauteur stricte pour les 3 champs : sélecteur renforcé (0,3,0+)
     pour battre la règle globale 42px « .filter-card input[type=…] »
     (0,2,1) qui écrasait sinon les 36px de la page. */
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

  /* Carte « Bulletins du mois » : tableau fluide 100%, texte qui s'enroule,
     aucune barre de défilement horizontale. */
  .salary-list-card { overflow: visible; }
  .salary-list-card .card-head { padding: 14px 18px; gap: 8px 12px; flex-wrap: wrap; }
  .salary-list-card .card-head h2 { font-size: 1rem; }
  .salary-list-card .table-wrap {
    overflow: visible;
    max-height: none;
    padding: 0;
  }
  .salary-list-card table {
    width: 100% !important;
    min-width: 0 !important;
    max-width: 100%;
    table-layout: auto;
    border-collapse: collapse;
    margin: 0;
    font-size: 0.83rem;
  }
  .salary-list-card thead th,
  .salary-list-card tbody td {
    max-width: none;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: normal;
    overflow-wrap: break-word;
    word-break: normal;
    padding: 8px 10px;
    vertical-align: middle;
  }
  .salary-list-card thead th {
    font-size: 0.68rem;
    line-height: 1.35;
    vertical-align: bottom;
  }
  .salary-list-card tbody td { border-bottom: 1px solid var(--color-line); }
  .salary-list-card tbody tr:last-child td { border-bottom: none; }
  .salary-list-card td.num,
  .salary-list-card th.num { text-align: right; font-variant-numeric: tabular-nums; }
  .salary-list-card .col-check { width: 36px; text-align: center; }
  .salary-list-card .col-id { width: 62px; }
  .salary-list-card .col-action { width: 76px; }
  .salary-list-card .col-salary { min-width: 0 !important; }
  .salary-list-card .bulk-actions-bar { margin: 12px 18px; }

  @media (max-width: 900px) {
    .salary-list-card thead th,
    .salary-list-card tbody td { padding: 7px 8px; font-size: 0.79rem; }
    .salary-list-card .col-id { width: 54px; }
    .salary-list-card .col-action { width: 64px; }
  }
  @media (max-width: 640px) {
    .salary-filter-card .salary-filter-body { padding: 10px 14px 12px; }
    .salary-filter-form .sf-search,
    .salary-filter-form .sf-month,
    .salary-filter-form .sf-year { flex: 1 1 100%; max-width: none; }
    .salary-filter-form .sf-actions { flex: 1 1 100%; }
    .salary-filter-form .sf-actions .btn { flex: 1 1 auto; justify-content: center; }
    .salary-list-card .bulk-actions-bar { margin: 10px 14px; }
  }
</style>

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

  <div class="content-card salary-filter-card">
    <div class="card-head">
      <h2>Salaires mensuels</h2>
      <div class="d-flex gap-8 flex-wrap">
         <button id="newSalaryBtn" class="btn btn-primary btn-sm" type="button">+ Nouveau salarié</button>
      </div>
    </div>
  <div class="salary-filter-body">
    <div class="filter-card">
      <form method="get" action="rh_salaries.php" class="salary-filter-form">
        <input type="hidden" name="filter_period" value="1">
        <div class="sf-field sf-search">
          <label class="form-label" for="rh-sal-q">Recherche</label>
          <input type="text" id="rh-sal-q" name="q" class="form-control" value="<?= e($q) ?>" placeholder="Nom ou matricule">
        </div>
        <div class="sf-field sf-month">
          <label class="form-label" for="rh-sal-month">Mois</label>
          <select id="rh-sal-month" name="month" class="form-select">
            <?php for ($i = 1; $i <= 12; $i++): ?>
              <option value="<?= $i ?>" <?= $displayMonth === $i ? 'selected' : '' ?>><?= e($months[$i]) ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="sf-field sf-year">
          <label class="form-label" for="rh-sal-year">Année</label>
          <input type="number" id="rh-sal-year" name="year" class="form-control" min="2000" max="2100" step="1" value="<?= e($displayYear) ?>">
        </div>
        <div class="sf-actions">
          <button type="submit" class="btn btn-light">Filtrer</button>
          <?php if ($periodFilter || $q !== ''): ?>
            <a class="btn btn-outline btn-reset" href="rh_salaries.php" title="Réinitialiser les filtres" aria-label="Réinitialiser les filtres"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/></svg></a>
          <?php endif; ?>
        </div>
      </form>
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

<div class="content-card salary-list-card">
  <div class="card-head">
    <h2>Bulletins du mois</h2>
    <span class="muted"><?= count($rows) ?> salarié(s)</span>
  </div>
  <?php if ($isAdmin && $rows): ?>
  <div class="bulk-actions-bar" style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;gap:12px;flex-wrap:wrap;">
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
      <?php
        // ATTENTION : ce bouton ne supprime QUE le mois affiché, jamais la
        // liste complète. Le libellé nomme donc explicitement la période
        // visée, sans quoi l'utilisateur croit supprimer toutes les périodes
        // alors qu'un seul mois est effacé.
        $deleteAllLabel   = 'Supprimer ' . $months[$displayMonth] . ' ' . $displayYear;
        $deleteAllConfirm = 'Supprimer TOUS les bulletins de ' . $months[$displayMonth] . ' ' . $displayYear
          . ' ? Cette action est irréversible !';
      ?>
      <form method="post" action="actions/rh_salary_action.php" id="deleteAllForm" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_all">
        <input type="hidden" name="month" value="<?= $displayMonth ?>">
        <input type="hidden" name="year" value="<?= $displayYear ?>">
        <button type="button" class="btn btn-outline btn-sm" id="deleteAllBtn" data-confirm="<?= e($deleteAllConfirm) ?>" data-confirm-target="deleteAllForm" style="border-color:#dc3545;color:#dc3545;">
          🗑🗑 <?= e($deleteAllLabel) ?>
        </button>
      </form>
    </div>
  </div>
  <?php endif; ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <?php if ($isAdmin): ?><th class="col-check"><input type="checkbox" id="selectAllCheckboxes" style="width:18px;height:18px;cursor:pointer;accent-color:#0d6efd;"></th><?php endif; ?>
          <th class="col-id">ID</th>
          <th>Nom &amp; prénom</th>
          <th>Pseudo</th>
          <th class="num">J. travaillés</th>
          <th class="num">ABS (j)</th>
          <th class="num">ABS (h)</th>
          <th class="num">J. payés</th>
          <th class="num">H. payées</th>
          <th class="num">Retards</th>
          <th class="num col-salary">Salaire</th>
          <th class="col-action">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr class="data-row">
          <?php if ($isAdmin): ?><td class="col-check" style="text-align:center;vertical-align:middle;"><input type="checkbox" name="salary_ids[]" value="<?= (int) $r['id'] ?>" class="salary-checkbox" style="width:18px;height:18px;cursor:pointer;accent-color:#0d6efd;"></td><?php endif; ?>
          <td class="col-id"><a href="<?= e(APP_URL) ?>/rh_employees.php?edit=<?= (int) $r['employee_id'] ?>" style="color:inherit;text-decoration:underline;font-weight:600;"><?= e($r['employee_code']) ?></a></td>
          <td><b><?= e($r['full_name']) ?></b></td>
          <td><?= e($r['pseudo'] ?? '') ?></td>
          <td class="num"><?= format_nombre((float) $r['normal_worked_days']) ?></td>
          <td class="num"><?= format_nombre((float) $r['unjustified_absence_days']) ?></td>
          <td class="num"><?= format_heure((float) $r['unjustified_absence_hours']) ?></td>
          <td class="num"><?= format_nombre((float) $r['paid_days']) ?></td>
          <td class="num"><?= format_heure((float) $r['paid_hours']) ?></td>
          <td class="num"><?= (int) $r['late_count'] ?></td>
          <td class="num"><b><?= format_montant_tnd((float) $r['calculated_salary']) ?></b></td>
          <td class="nowrap"><div style="display:flex;align-items:center;gap:8px;">
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
            <?php endif; ?></div>
          </td>
        </tr>
        <tr class="edit-row" style="display:none">
          <?php // 12 colonnes avec la case « Tout sélectionner » ($isAdmin), 11 sinon. ?>
          <td colspan="<?= $isAdmin ? 12 : 11 ?>">
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
          <td colspan="<?= $isAdmin ? 12 : 11 ?>">
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
