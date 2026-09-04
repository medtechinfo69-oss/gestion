<?php
require_once __DIR__ . '/../includes/init.php';
require_admin_or_superviseur();

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
  $name = trim((string) ($_POST['full_name'] ?? ''));
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
    if ($id > 0) {
      $stmt = $pdo->prepare('UPDATE employees SET employee_code=:code, full_name=:name, pseudo=:pseudo, position=:position, department=:department, hourly_rate=:rate, status=:status, start_date=:start WHERE id=:id');
      $stmt->execute([
        'code' => $code,
        'name' => $name,
        'pseudo' => $pseudo,
        'position' => $position,
        'department' => $department,
        'rate' => $rate,
        'status' => $status,
        'start' => $startDate,
        'id' => $id,
      ]);
    } else {
      $stmt = $pdo->prepare('INSERT INTO employees(employee_code, full_name, pseudo, position, department, hourly_rate, status, start_date) VALUES(:code, :name, :pseudo, :position, :department, :rate, :status, :start)');
      $stmt->execute([
        'code' => $code,
        'name' => $name,
        'pseudo' => $pseudo,
        'position' => $position,
        'department' => $department,
        'rate' => $rate,
        'status' => $status,
        'start' => $startDate,
      ]);
    }
    employee_redirect('Employé enregistré avec succès.', 'success');
  } catch (PDOException $e) {
    error_log('rh_employee_save: ' . $e->getMessage());
    employee_redirect($e->getCode() === '23000' ? 'Le matricule existe déjà.' : 'Impossible d\'enregistrer l\'employé.');
  }
}

if ($action === 'delete') {
  if (!is_admin()) {
    employee_redirect('Seul un administrateur peut supprimer un employé.');
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
