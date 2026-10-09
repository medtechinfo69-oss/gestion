<?php
require_once __DIR__ . '/includes/init.php';
require_rh(); // administrateur OU compte RH (mêmes fonctions, module RH seul)

$pageTitle = 'Employés';
$pageSubtitle = 'Ensemble des salariés enregistrés';
$activePage = 'rh_employees';
$pdo = $db;
// Droits d'écriture dans le module RH : administrateur ET compte RH ont
// exactement les mêmes fonctions (création, modification, suppression).
$isAdmin = can_access_rh();
// Colonnes « nom » et « prenom » : migration idempotente et non destructive.
// Tant qu'elles existent, le nom et le prénom ne sont plus confondus dans une
// seule colonne ; sinon on retombe proprement sur `full_name` seul.
$nomColonnes = rh_ensure_name_columns($pdo);

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

      // Le fichier peut fournir soit deux colonnes distinctes (Nom / Prénom),
      // soit une seule colonne « Nom » (parfois intitulée « Nom et prénom »)
      // contenant le nom ET le prénom — les deux informations étaient alors
      // recopiées telles quelles dans une seule colonne. On ramène donc
      // toujours le résultat à un couple (nom, prénom) séparé.
      $nomCol = trim((string) employee_import_value($data, ['nom'], ''));
      $prenomCol = trim((string) employee_import_value($data, ['prenom', 'pr&eacute;nom', 'pr&eacute;nome'], ''));
      $combineCol = trim((string) employee_import_value(
        $data,
        ['nom et prenom', 'nom & prenom', 'nom & pr&eacute;nom', 'nom prenom', 'nom complet', 'full name'],
        ''
      ));

      if ($nomCol !== '' && $prenomCol !== '') {
        // Deux colonnes dédiées : on les respecte sans rien découper.
        $nom = $nomCol;
        $prenom = $prenomCol;
      } elseif ($nomCol !== '') {
        // Colonne « Nom » seule : elle peut contenir « NOM Prénom ».
        $decoupe = split_person_name($nomCol);
        $nom = $decoupe['nom'];
        $prenom = $decoupe['prenom'];
      } elseif ($prenomCol !== '' && $combineCol !== '') {
        $decoupe = split_person_name($combineCol);
        $nom = $decoupe['nom'];
        $prenom = $decoupe['prenom'];
      } elseif ($combineCol !== '') {
        $decoupe = split_person_name($combineCol);
        $nom = $decoupe['nom'];
        $prenom = $decoupe['prenom'];
      } else {
        $nom = '';
        $prenom = $prenomCol;
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

      if ($nom === '' && $prenom === '') {
        $errors[] = 'Ligne ' . ($i + 1) . ' : nom absent.';
        continue;
      }

      // Une information manquante est signalée explicitement dans son champ
      // plutôt que laissée vide. `full_name` reste la concaténation des deux,
      // pour ne rien changer aux écrans qui l'affichent encore.
      $nom = $nom !== '' ? $nom : 'Non renseigné';
      $prenom = $prenom !== '' ? $prenom : 'Non renseigné';
      $name = trim($nom . ' ' . $prenom);

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
        if ($nomColonnes) {
          $employeeUpdates[] = 'nom = :nom';
          $employeeUpdates[] = 'prenom = :prenom';
          $employeeParams['nom'] = $nom;
          $employeeParams['prenom'] = $prenom;
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
        $insertCols = 'employee_code, full_name, pseudo, position, contract_type, hourly_rate, status, start_date';
        $insertVals = ':code, :name, :pseudo, :position, :contract, :rate, :status, :start';
        $insertParams = [
          'code' => $code, 'name' => $name, 'pseudo' => $pseudo, 'position' => $position,
          'contract' => 'CDI', 'rate' => $rate, 'status' => 'Active', 'start' => date('Y-m-d'),
        ];
        if ($nomColonnes) {
          $insertCols .= ', nom, prenom';
          $insertVals .= ', :nom, :prenom';
          $insertParams['nom'] = $nom;
          $insertParams['prenom'] = $prenom;
        }
        $pdo->prepare('INSERT INTO employees(' . $insertCols . ') VALUES(' . $insertVals . ')')->execute($insertParams);
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

// Valeurs de filtre : toujours castées enchaîne. Sans ce cast, un lien
// forgé du type « ?q[]=a » (tableau) provoquerait un warning PHP sur
// trim()/strtoupper() et casserait la page en production.
$q = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$position = (string) ($_GET['position'] ?? '');
// Vrai lorsqu'au moins un filtre est actif : pilote l'affichage du bouton
// « Réinitialiser » (affiche uniquement quand il sert a quelque chose).
$hasFilters = $q !== '' || in_array($status, ['Active', 'Inactive'], true) || in_array($position, ['Agent', 'Responsable'], true);
$sort = ($_GET['sort'] ?? '') === 'matricule' ? 'matricule' : 'matricule';
$direction = strtoupper((string) ($_GET['direction'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

$sql = 'SELECT * FROM employees WHERE 1=1';
$args = [];
if ($q !== '') {
  // PDO est configure avec ATTR_EMULATE_PREPARES = false (requetes preparees
  // REELLES cote MySQL) : MySQL n'accepte alors pas qu'un meme marqueur nomme
  // (:q) apparaisse plusieurs fois dans la requete, et execute() leve un
  // « SQLSTATE[HY093] Invalid parameter number ». On utilise donc un marqueur
  // DISTINCT par colonne, comme le fait deja dossiers.php.
  // Sans ce correctif, toute recherche par mot-cle faisait planter la page.
  $searchCols = ['full_name'];
  if ($nomColonnes) {
    $searchCols[] = 'nom';
    $searchCols[] = 'prenom';
  }
  $searchCols[] = 'employee_code';
  $searchCols[] = 'position';
  $clauses = [];
  foreach ($searchCols as $i => $col) {
    $clauses[] = $col . ' LIKE :q' . $i;
    $args['q' . $i] = '%' . $q . '%';
  }
  $sql .= ' AND (' . implode(' OR ', $clauses) . ')';
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

// Affichage du nom / prénom : on privilégie les colonnes dédiées. Si
// l'hébergement a refusé la migration, on re-découpe `full_name` à la volée
// afin que les deux informations restent visibles séparément.
$employeNom = static function (array $row) use ($nomColonnes): string {
  if ($nomColonnes) {
    return nom_ou_non_renseigne($row['nom'] ?? '');
  }
  return nom_ou_non_renseigne(split_person_name((string) ($row['full_name'] ?? ''))['nom']);
};
$employePrenom = static function (array $row) use ($nomColonnes): string {
  if ($nomColonnes) {
    return nom_ou_non_renseigne($row['prenom'] ?? '');
  }
  return nom_ou_non_renseigne(split_person_name((string) ($row['full_name'] ?? ''))['prenom']);
};

require __DIR__ . '/includes/header.php';
?>

<style>
  /* Filtre « Employés » — même design compact que « Salaires mensuels »
     (rh_salaries.php) : carte resserrée, champs 36px alignés à droite,
     recherche plus large, listes « État » / « Poste » au même format que « Mois ». */
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
    <h2>Employés</h2>
    <div class="d-flex gap-8 flex-wrap">
      <a class="btn btn-primary btn-sm" href="?new=1">+ Nouvel employé</a>
    </div>
  </div>
  <div class="salary-filter-body">
    <div class="filter-card">
      <form method="get" action="rh_employees.php" class="salary-filter-form">
        <div class="sf-field sf-search">
          <label class="form-label" for="rh-emp-q">Recherche</label>
          <input type="text" id="rh-emp-q" name="q" class="form-control" value="<?= e($q) ?>" placeholder="Nom, matricule, poste...">
        </div>
        <div class="sf-field sf-month">
          <label class="form-label" for="rh-emp-status">État</label>
          <select id="rh-emp-status" name="status" class="form-select">
            <option value="">Tous</option>
            <option value="Active" <?= $status === 'Active' ? 'selected' : '' ?>>Actif</option>
            <option value="Inactive" <?= $status === 'Inactive' ? 'selected' : '' ?>>Inactif</option>
          </select>
        </div>
        <div class="sf-field sf-year">
          <label class="form-label" for="rh-emp-position">Poste</label>
          <select id="rh-emp-position" name="position" class="form-select">
            <option value="">Tous</option>
            <option value="Agent" <?= $position === 'Agent' ? 'selected' : '' ?>>Agent</option>
            <option value="Responsable" <?= $position === 'Responsable' ? 'selected' : '' ?>>Responsable</option>
          </select>
        </div>
        <div class="sf-actions">
          <button type="submit" class="btn btn-light">Filtrer</button>
          <?php if ($hasFilters): ?>
            <a class="btn btn-outline filter-reset" href="rh_employees.php" title="Effacer les filtres">Réinitialiser</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
// Le formulaire saisit le nom et le prénom séparément ; `full_name` reste
// dérivé des deux (rafraîchi à l'enregistrement) pour ne rien casser ailleurs.
$formNom = $nomColonnes ? (string) ($edit['nom'] ?? '') : split_person_name((string) ($edit['full_name'] ?? ''))['nom'];
$formPrenom = $nomColonnes ? (string) ($edit['prenom'] ?? '') : split_person_name((string) ($edit['full_name'] ?? ''))['prenom'];
?>
<?php if ($edit || isset($_GET['new'])): ?>
<div class="form-card">
  <div class="card-head"><h2><?= $edit ? 'Modifier l\'employé' : 'Nouvel employé' ?></h2></div>
  <form method="post" action="actions/rh_employee_action.php">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">
    <div class="form-grid">
      <label>Matricule<input class="form-control" name="employee_code" required value="<?= e($edit['employee_code'] ?? '') ?>"></label>
      <label>Nom<input class="form-control" name="nom" required value="<?= e($formNom) ?>"></label>
      <label>Prénom<input class="form-control" name="prenom" required value="<?= e($formPrenom) ?>"></label>
      <label>Pseudo<input class="form-control" name="pseudo" value="<?= e($edit['pseudo'] ?? '') ?>"></label>
      <label>Poste<select class="form-control" name="position" required><option value="Agent" <?= ($edit['position'] ?? 'Agent') === 'Agent' ? 'selected' : '' ?>>Agent</option><option value="Responsable" <?= ($edit['position'] ?? '') === 'Responsable' ? 'selected' : '' ?>>Responsable</option></select></label>
          <label title="Modifier ce taux recalcule automatiquement, à l'enregistrement, les bulletins de salaire déjà enregistrés pour cet employé (jours payés × taux pour un Responsable, heures payées × taux sinon).">Régime horaire (TND / h)<input class="form-control" name="hourly_rate" type="number" step="0.01" min="0" required value="<?= e($edit['hourly_rate'] ?? '') ?>"></label>
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
          <th>Nom</th>
          <th>Prénom</th>
          <th>Pseudo</th>
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
          <td><?= e($employeNom($r)) ?></td>
          <td><?= e($employePrenom($r)) ?></td>
          <td><?= e($r['pseudo'] ?: '—') ?></td>
          <td><?= e($r['position'] ?: '—') ?></td>
          <td><?= format_montant_tnd((float) $r['hourly_rate']) ?></td>
          <td><span class="badge <?= $r['status'] === 'Active' ? 'badge-success' : 'badge-muted' ?>"><?= $r['status'] === 'Active' ? 'Actif' : 'Inactif' ?></span></td>
          <td class="nowrap">
            <span class="row-actions">
            <a class="btn btn-sm btn-secondary btn-icon-action" href="?edit=<?= (int) $r['id'] ?>" title="Modifier" aria-label="Modifier"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg></a>
            <?php if ($isAdmin): ?>
            <form class="inline" method="post" action="actions/rh_employee_action.php" data-confirm="Supprimer définitivement cet employé et tout son historique de salaire ?">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button type="submit" class="btn btn-sm btn-danger btn-icon-action" title="Supprimer définitivement" aria-label="Supprimer définitivement"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg></button>
            </form>
            <?php endif; ?>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <tr>
          <td colspan="7">
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
