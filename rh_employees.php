<?php
require_once __DIR__ . '/includes/init.php';
require_admin_or_superviseur();

$pageTitle = 'Employés';
$pageSubtitle = 'Ensemble des salariés enregistrés';
$activePage = 'rh_employees';
$pdo = $db;
$isAdmin = is_admin();

function employee_import_header(string $value): string
{
  $value = trim(mb_strtolower($value));
  $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
  $value = $ascii !== false ? $ascii : $value;
  return trim(preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '');
}

function employee_import_value(array $data, array $aliases, $default = '')
{
  foreach ($aliases as $alias) {
    $wanted = employee_import_header($alias);
    foreach ($data as $key => $value) {
      $actual = employee_import_header((string) $key);
      if (($actual === $wanted || strpos($actual, $wanted . ' ') === 0) && trim((string) $value) !== '') {
        return $value;
      }
    }
  }
  return $default;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_employees') {
  csrf_require();
  $errors = [];
  $created = 0;
  $updated = 0;
  $skipped = 0;

  $f = $_FILES['import_file'] ?? null;
  if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > max_import_size_bytes($pdo)) {
    set_flash('error', 'Fichier invalide. Vérifiez la taille et le format.');
    header('Location: ' . APP_URL . '/rh_employees.php');
    exit;
  }

  $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
  if ($ext !== 'xlsx') {
    set_flash('error', 'Format invalide. Sélectionnez un fichier .xlsx.');
    header('Location: ' . APP_URL . '/rh_employees.php');
    exit;
  }

  if (!class_exists('ZipArchive')) {
    set_flash('error', 'L\'extension PHP ZipArchive est requise.');
    header('Location: ' . APP_URL . '/rh_employees.php');
    exit;
  }

  $zip = new ZipArchive();
  if ($zip->open($f['tmp_name']) !== true) {
    set_flash('error', 'Le fichier Excel est invalide ou corrompu.');
    header('Location: ' . APP_URL . '/rh_employees.php');
    exit;
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
    header('Location: ' . APP_URL . '/rh_employees.php');
    exit;
  }

  $dom = new DOMDocument();
  if (!$dom->loadXML($sheet)) {
    $zip->close();
    set_flash('error', 'Impossible de lire le contenu du fichier Excel.');
    header('Location: ' . APP_URL . '/rh_employees.php');
    exit;
  }

  $rows = $dom->getElementsByTagName('row');
  $xpath = new DOMXPath($dom);
  $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
  $allRows = [];

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
    header('Location: ' . APP_URL . '/rh_employees.php');
    exit;
  }

  $headerKeywords = ['matricule', 'nom', 'prenom', 'nom et prenom', 'nom complet', 'pseudo', 'peseudo', 'poste', 'fonction', 'employee code', 'full name', 'position'];
  $headers = [];
  $headerRowIndex = -1;

  foreach ($allRows as $index => $vals) {
    $normalized = array_map(function ($h) { return employee_import_header((string) $h); }, $vals);
    $matchCount = 0;
    foreach ($normalized as $h) {
      foreach ($headerKeywords as $kw) {
        if (strpos($h, employee_import_header($kw)) !== false) { $matchCount++; break; }
      }
    }
    if ($matchCount >= 2 && empty($headers)) {
      $headers = $normalized;
      $headerRowIndex = $index;
      break;
    }
  }

  if (empty($headers)) {
    $headers = array_map(function ($h) { return employee_import_header((string) $h); }, $allRows[0]);
    $headerRowIndex = 0;
  }

  $pdo->beginTransaction();
  try {
    for ($i = $headerRowIndex + 1; $i < count($allRows); $i++) {
      $vals = $allRows[$i];
      $vals = array_pad($vals, count($headers), '');
      $data = [];
      foreach ($headers as $idx => $h) { $data[$h] = $vals[$idx] ?? ''; }

      $code = trim((string) employee_import_value($data, ['matricule', 'employee code', 'employee id', 'code'], $vals[0] ?? ''));
      if (preg_match('/^(\d+)\.0+$/', $code, $match)) {
        $code = $match[1];
      }

      $nom = trim((string) employee_import_value($data, ['nom', 'nom et prenom', 'nom & prenom', 'nom & pr&eacute;nom', 'nom complet', 'full name'], ''));
      $prenom = trim((string) employee_import_value($data, ['prenom', 'pr&eacute;nom', 'pr&eacute;nome'], ''));

      if ($nom !== '' && $prenom !== '') {
        $name = $nom . ' ' . $prenom;
      } elseif ($nom !== '') {
        $fullName = trim((string) employee_import_value($data, ['nom et prenom', 'nom & prenom', 'nom & pr&eacute;nom', 'nom complet', 'full name'], ''));
        $name = $fullName !== '' ? $fullName : $nom;
      } else {
        $name = trim((string) employee_import_value($data, ['nom et prenom', 'nom & prenom', 'nom & pr&eacute;nom', 'nom complet', 'full name'], ''));
      }

      $position = trim((string) employee_import_value($data, ['poste', 'position', 'fonction', 'role'], 'Agent'));
      $position = in_array(strtolower($position), ['responsable', 'responsable'], true) ? 'Responsable' : 'Agent';

      $pseudo = trim((string) employee_import_value($data, ['pseudo', 'peseudo'], ''));
      $rate = employee_import_value($data, ['r&eacute;gime horaire', 'r&eacute;gime', 'taux horaire', 'hourly rate', 'regime'], 0);
      $rate = is_numeric($rate) ? round((float) $rate, 2) : 5.000;

      if (!$code) {
        $errors[] = 'Ligne ' . ($i + 1) . ' : matricule absent.';
        continue;
      }

      if (!$name) {
        $errors[] = 'Ligne ' . ($i + 1) . ' : nom absent.';
        continue;
      }

      $stmt = $pdo->prepare('SELECT id FROM employees WHERE employee_code=:code LIMIT 1');
      $stmt->execute(['code' => $code]);
      $existing = $stmt->fetch();

      if ($existing) {
        $employeeUpdates = [];
        $employeeParams = ['code' => $code];
        if ($name) {
          $employeeUpdates[] = 'full_name = :full_name';
          $employeeParams['full_name'] = $name;
        }
        if ($pseudo) {
          $employeeUpdates[] = 'pseudo = :pseudo';
          $employeeParams['pseudo'] = $pseudo;
        }
        if ($rate > 0) {
          $employeeUpdates[] = 'hourly_rate = :hourly_rate';
          $employeeParams['hourly_rate'] = $rate;
        }
        $employeeUpdates[] = 'position = :position';
        $employeeParams['position'] = $position;

        if ($employeeUpdates) {
          $pdo->prepare('UPDATE employees SET ' . implode(', ', $employeeUpdates) . ' WHERE employee_code = :code')->execute($employeeParams);
        }
        $updated++;
      } else {
        $pdo->prepare('INSERT INTO employees(employee_code, full_name, pseudo, position, contract_type, hourly_rate, status, start_date) VALUES(:code, :name, :pseudo, :position, :contract, :rate, :status, :start)')->execute([
          'code' => $code, 'name' => $name, 'pseudo' => $pseudo, 'position' => $position, 'contract' => 'CDI', 'rate' => $rate, 'status' => 'Active', 'start' => date('Y-m-d')
        ]);
        $created++;
      }
    }
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('employee_import error: ' . $e->getMessage());
    set_flash('error', 'Import annulé : ' . $e->getMessage());
    header('Location: ' . APP_URL . '/rh_employees.php');
    exit;
  }

  $zip->close();

  $parts = [];
  if ($created > 0) $parts[] = $created . ' créé(s)';
  if ($updated > 0) $parts[] = $updated . ' mis à jour';
  if ($skipped > 0) $parts[] = $skipped . ' ignoré(s)';

  $msg = 'Import terminé : ' . implode(', ', $parts) . '.';
  if ($errors) {
    $msg .= ' ' . count($errors) . ' erreur(s) : ' . implode(' | ', array_slice($errors, 0, 5));
  }
  set_flash(($created > 0 || $updated > 0) ? 'success' : 'error', $msg);
  header('Location: ' . APP_URL . '/rh_employees.php');
  exit;
}

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
$sort = ($_GET['sort'] ?? '') === 'matricule' ? 'matricule' : 'matricule';
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
  ? ' ORDER BY CAST(employee_code AS UNSIGNED) ' . $direction . ', employee_code ' . $direction
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
