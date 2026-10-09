<?php
require_once __DIR__ . '/../includes/init.php';
require_rh(); // administrateur OU compte RH (mêmes fonctions, module RH seul)

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: ' . APP_URL . '/rh_employees.php');
  exit;
}

if (!csrf_verify()) {
  set_flash('error', 'Jeton de sécurité invalide.');
  header('Location: ' . APP_URL . '/rh_employees.php');
  exit;
}

$pdo = $db;
// Les colonnes « nom » et « prenom » sont créées au besoin (migration
// idempotente) : elles évitent de confondre les deux informations.
$nomColonnes = rh_ensure_name_columns($pdo);
$action = $_POST['action'] ?? '';

function employee_redirect(string $message, string $type = 'error'): void
{
  set_flash($type, $message);
  header('Location: ' . APP_URL . '/rh_employees.php');
  exit;
}

if ($action === 'save') {
  $id = (int) ($_POST['id'] ?? 0);
  $code = trim((string) ($_POST['employee_code'] ?? ''));
  // Le formulaire saisit le nom et le prénom dans deux champs distincts.
  // On accepte encore `full_name` seul (ancien formulaire ou appel direct)
  // en le découpant, pour ne jamais perdre la saisie.
  $nom = trim((string) ($_POST['nom'] ?? ''));
  $prenom = trim((string) ($_POST['prenom'] ?? ''));
  if ($nom === '' && $prenom === '') {
    $decoupe = split_person_name((string) ($_POST['full_name'] ?? ''));
    $nom = $decoupe['nom'];
    $prenom = $decoupe['prenom'];
  }
  $name = trim($nom . ' ' . $prenom);
  $pseudo = trim((string) ($_POST['pseudo'] ?? ''));
  $position = trim((string) ($_POST['position'] ?? ''));
  $department = trim((string) ($_POST['department'] ?? ''));
  if ($id > 0 && !array_key_exists('department', $_POST)) {
    $existingDepartment = $pdo->prepare('SELECT department FROM employees WHERE id=:id');
    $existingDepartment->execute(['id' => $id]);
    $department = (string) ($existingDepartment->fetchColumn() ?: '');
  }
  $rateInput = trim((string) ($_POST['hourly_rate'] ?? ''));
  $status = $_POST['status'] ?? 'Active';
  $start = trim((string) ($_POST['start_date'] ?? ''));

  if ($code === '' || $name === '' || !in_array($position, ['Responsable', 'Agent'], true) || !is_numeric($rateInput) || (float) $rateInput < 0 || !in_array($status, ['Active', 'Inactive'], true)) {
    employee_redirect('Veuillez vérifier les champs obligatoires.');
  }

  $startDate = $start !== '' ? $start : null;
  $rate = round((float) $rateInput, 2);

  try {
    // La fiche employé et le recalcul de ses bulletins de salaire forment un
    // tout : soit les deux sont enregistrés, soit aucun des deux (commit plus
    // bas, rollback dans le catch).
    $pdo->beginTransaction();
    // Bulletins de salaire réellement recalculés par le changement de régime
    // horaire (ou de poste) : 0 si l'employé n'a pas encore de bulletin.
    $bulletinsRecalcules = 0;
    $params = [
      'code' => $code,
      'name' => $name,
      'pseudo' => $pseudo,
      'position' => $position,
      'department' => $department,
      'rate' => $rate,
      'status' => $status,
      'start' => $startDate,
    ];
    // Colonnes « nom » / « prenom » : écrites seulement si elles existent,
    // sinon la requête resterait valable sur une base non migrée.
    $nomSql = '';
    if ($nomColonnes) {
      $nomSql = ', nom = :nom, prenom = :prenom';
      $params['nom'] = $nom;
      $params['prenom'] = $prenom;
    }

    if ($id > 0) {
      $params['id'] = $id;
      $stmt = $pdo->prepare('UPDATE employees SET employee_code=:code, full_name=:name' . $nomSql . ', pseudo=:pseudo, position=:position, department=:department, hourly_rate=:rate, status=:status, start_date=:start WHERE id=:id');
      $stmt->execute($params);

      // Recalcul automatique des bulletins de salaire DÉJÀ enregistrés pour cet
      // employé : le régime horaire (et le poste) de la fiche viennent d'être
      // modifiés, donc `hourly_rate_used` et `calculated_salary` de chaque
      // bulletin doivent suivre immédiatement. Sans cela, les salaires
      // restaient calculés sur l'ancien taux jusqu'à un nouveau réimport du
      // rapport de pointage. Les autres colonnes (jours/heures payés, absences,
      // retards, heures sup, départs) ne sont pas touchées.
      $bulletinsRecalcules = rh_recalculer_salaires_employe($pdo, $id, $rate, $position);
    } else {
      $cols = 'employee_code, full_name, pseudo, position, department, hourly_rate, status, start_date';
      $vals = ':code, :name, :pseudo, :position, :department, :rate, :status, :start';
      if ($nomColonnes) {
        $cols .= ', nom, prenom';
        $vals .= ', :nom, :prenom';
      }
      $stmt = $pdo->prepare('INSERT INTO employees(' . $cols . ') VALUES(' . $vals . ')');
      $stmt->execute($params);
    }
    if ($pdo->inTransaction()) {
      $pdo->commit();
    }

    if (is_superviseur()) {
      $me = current_user();
      $who = (string) ($me['nom_complet'] ?? $me['username'] ?? 'Un superviseur');
      $label = $id > 0 ? ('Employe modifie : ' . $name) : ('Employe cree : ' . $name . ' (' . $code . ')');
      notify_superviseur_action($pdo, $id > 0 ? 'update' : 'create', 'employe', $id > 0 ? $id : null,
        $who . ' - ' . $label,
        $label . ' par ' . $who . '.');
    }

    // Le recalcul est annoncé explicitement : l'utilisateur doit savoir que les
    // salaires de cet employé ont été mis à jour (aucun message s'il n'avait
    // aucun bulletin, ou si rien ne changeait).
    $message = 'Employe enregistre avec succes.';
    if ($bulletinsRecalcules > 0) {
      $message .= ' ' . $bulletinsRecalcules . ' bulletin(s) de salaire recalculé(s) au nouveau régime horaire ('
        . format_montant_tnd($rate) . '/h).';
    }
    employee_redirect($message, 'success');
  } catch (PDOException $e) {
    // Rien ne doit rester à moitié appliqué : fiche ET bulletins sont annulés.
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    error_log('rh_employee_save: ' . $e->getMessage());
    employee_redirect($e->getCode() === '23000' ? 'Le matricule existe deja.' : 'Impossible d\'enregistrer l\'employe.');
  }
}

if ($action === 'delete') {
  if (!can_access_rh()) {
    employee_redirect('Seul un administrateur ou un compte RH peut supprimer un employé.');
  }
  $id = (int) ($_POST['id'] ?? 0);
  if ($id <= 0) {
    employee_redirect('Employé invalide.');
  }
  try {
    $pdo->beginTransaction();
    $salaryDelete = $pdo->prepare('DELETE FROM salary_records WHERE employee_id=:id');
    $salaryDelete->execute(['id' => $id]);
    $stmt = $pdo->prepare('DELETE FROM employees WHERE id=:id');
    $stmt->execute(['id' => $id]);
    $pdo->commit();
    employee_redirect('Employé et historique de salaire supprimés.', 'success');
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('rh_employee_delete: ' . $e->getMessage());
    employee_redirect('Impossible de supprimer cet employé.');
  }
}

employee_redirect('Action inconnue.');
