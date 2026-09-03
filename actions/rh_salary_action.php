<?php
require_once __DIR__ . '/../includes/init.php';
require_admin_or_superviseur();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: ' . APP_URL . '/rh_salaries.php');
  exit;
}

if (!csrf_verify()) {
  set_flash('error', 'Jeton de sécurité invalide.');
  header('Location: ' . APP_URL . '/rh_salaries.php');
  exit;
}

$pdo = $db;
$action = $_POST['action'] ?? '';
$month = isset($_POST['month']) ? (int) $_POST['month'] : (int) date('n');
$year = isset($_POST['year']) ? (int) $_POST['year'] : (int) date('Y');

function salary_redirect(string $message, string $type = 'error', int $month = 0, int $year = 0): void
{
  set_flash($type, $message);
  $query = $month > 0 && $year > 0 ? '?month=' . $month . '&year=' . $year : '';
  header('Location: ' . APP_URL . '/rh_salaries.php' . $query);
  exit;
}

if ($action === 'import_salaries') {
  $_GET['action_handler'] = '1';
  require __DIR__ . '/../rh_salaries.php';
  exit;
}

if ($action === 'update') {
  $id = (int) ($_POST['id'] ?? 0);
  $hours = filter_var(str_replace(',', '.', (string) ($_POST['total_hours'] ?? '')), FILTER_VALIDATE_FLOAT);
  $rate = filter_var(str_replace(',', '.', (string) ($_POST['hourly_rate_used'] ?? '')), FILTER_VALIDATE_FLOAT);

  if ($id <= 0 || $hours === false || $hours < 0 || $rate === false || $rate < 0) {
    salary_redirect('Les heures et le régime horaire doivent être des nombres positifs.', 'error', $month, $year);
  }

  $stmt = $pdo->prepare('UPDATE salary_records SET total_hours=:h, hourly_rate_used=:r, calculated_salary=:s WHERE id=:id');
  $stmt->execute(['h' => round((float) $hours, 2), 'r' => round((float) $rate, 2), 's' => round((float) $hours * (float) $rate, 2), 'id' => $id]);
  salary_redirect('Salaire recalculé avec succès.', 'success', $month, $year);
}

if ($action === 'add') {
  $employeeId = (int) ($_POST['employee_id'] ?? 0);
  $hours = filter_var(str_replace(',', '.', (string) ($_POST['total_hours'] ?? '')), FILTER_VALIDATE_FLOAT);

  if ($employeeId <= 0 || $hours === false || $hours < 0) {
    salary_redirect('Veuillez vérifier l\'employé et le nombre d\'heures.', 'error', $month, $year);
  }

  $stmt = $pdo->prepare("SELECT id, hourly_rate FROM employees WHERE id=:id AND status='Active' LIMIT 1");
  $stmt->execute(['id' => $employeeId]);
  $employee = $stmt->fetch();

  if (!$employee) {
    salary_redirect('Salarié introuvable ou inactif.', 'error', $month, $year);
  }

  $hours = round((float) $hours, 2);
  $rate = round((float) $employee['hourly_rate'], 2);
  $salary = round($hours * $rate, 2);

  try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO salary_records(employee_id, month, year, total_hours, hourly_rate_used, calculated_salary) VALUES(:eid, :m, :y, :h, :r, :s) ON DUPLICATE KEY UPDATE total_hours=VALUES(total_hours), hourly_rate_used=VALUES(hourly_rate_used), calculated_salary=VALUES(calculated_salary), updated_at=CURRENT_TIMESTAMP');
    $stmt->execute(['eid' => $employee['id'], 'm' => $month, 'y' => $year, 'h' => $hours, 'r' => $rate, 's' => $salary]);
    $pdo->commit();
    salary_redirect('Salaire calculé et enregistré avec succès.', 'success', $month, $year);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('rh_salary_add: ' . $e->getMessage());
    salary_redirect('Impossible d\'enregistrer le salaire.', 'error', $month, $year);
  }
}

salary_redirect('Action inconnue.', 'error', $month, $year);
