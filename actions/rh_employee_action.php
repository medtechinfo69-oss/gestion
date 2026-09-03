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
  $position = trim((string) ($_POST['position'] ?? ''));
  $department = trim((string) ($_POST['department'] ?? ''));
  $contract = trim((string) ($_POST['contract_type'] ?? ''));
  $rateInput = trim((string) ($_POST['hourly_rate'] ?? ''));
  $status = $_POST['status'] ?? 'Active';
  $start = trim((string) ($_POST['start_date'] ?? ''));

  if ($code === '' || $name === '' || !is_numeric($rateInput) || (float) $rateInput < 0 || !in_array($status, ['Active', 'Inactive'], true)) {
    employee_redirect('Veuillez vérifier les champs obligatoires.');
  }

  $startDate = $start !== '' ? $start : null;
  $rate = round((float) $rateInput, 2);

  try {
    if ($id > 0) {
      $stmt = $pdo->prepare('UPDATE employees SET employee_code=:code, full_name=:name, position=:position, department=:department, contract_type=:contract, hourly_rate=:rate, status=:status, start_date=:start WHERE id=:id');
      $stmt->execute([
        'code' => $code,
        'name' => $name,
        'position' => $position,
        'department' => $department,
        'contract' => $contract,
        'rate' => $rate,
        'status' => $status,
        'start' => $startDate,
        'id' => $id,
      ]);
    } else {
      $stmt = $pdo->prepare('INSERT INTO employees(employee_code, full_name, position, department, contract_type, hourly_rate, status, start_date) VALUES(:code, :name, :position, :department, :contract, :rate, :status, :start)');
      $stmt->execute([
        'code' => $code,
        'name' => $name,
        'position' => $position,
        'department' => $department,
        'contract' => $contract,
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
  $id = (int) ($_POST['id'] ?? 0);
  if ($id <= 0) {
    employee_redirect('Employé invalide.');
  }
  $check = $pdo->prepare('SELECT COUNT(*) FROM salary_records WHERE employee_id=:id');
  $check->execute(['id' => $id]);
  if ((int) $check->fetchColumn() > 0) {
    employee_redirect('Impossible de supprimer un employé ayant un historique de salaire.');
  }
  $stmt = $pdo->prepare('DELETE FROM employees WHERE id=:id');
  $stmt->execute(['id' => $id]);
  employee_redirect('Employé supprimé.', 'success');
}

employee_redirect('Action inconnue.');
