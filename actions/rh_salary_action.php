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
  $normalDays = filter_var(str_replace(',', '.', (string) ($_POST['normal_worked_days'] ?? 0)), FILTER_VALIDATE_FLOAT);
  $absenceDays = filter_var(str_replace(',', '.', (string) ($_POST['unjustified_absence_days'] ?? 0)), FILTER_VALIDATE_FLOAT);
  $absenceHours = filter_var(str_replace(',', '.', (string) ($_POST['unjustified_absence_hours'] ?? 0)), FILTER_VALIDATE_FLOAT);
  $paidDays = filter_var(str_replace(',', '.', (string) ($_POST['paid_days'] ?? 0)), FILTER_VALIDATE_FLOAT);
  $paidHours = filter_var(str_replace(',', '.', (string) ($_POST['paid_hours'] ?? $_POST['total_hours'] ?? 0)), FILTER_VALIDATE_FLOAT);
  $lateCount = (int) ($_POST['late_count'] ?? 0);
  $rate = filter_var(str_replace(',', '.', (string) ($_POST['hourly_rate_used'] ?? '')), FILTER_VALIDATE_FLOAT);

  if ($id <= 0 || $normalDays === false || $normalDays < 0 || $absenceDays === false || $absenceDays < 0 || $absenceHours === false || $absenceHours < 0 || $paidDays === false || $paidDays < 0 || $paidHours === false || $paidHours < 0 || $lateCount < 0 || $rate === false || $rate < 0) {
    salary_redirect('Les données de paie doivent être positives.', 'error', $month, $year);
  }

  $roleStmt = $pdo->prepare('SELECT e.position FROM salary_records sr JOIN employees e ON e.id=sr.employee_id WHERE sr.id=:id');
  $roleStmt->execute(['id' => $id]);
  $salaryBase = $roleStmt->fetchColumn() === 'Responsable' ? (float) $paidDays : (float) $paidHours;
  $salary = round($salaryBase * (float) $rate, 2);
  $stmt = $pdo->prepare('UPDATE salary_records SET total_hours=:h, hourly_rate_used=:r, calculated_salary=:s, normal_worked_days=:normal, unjustified_absence_days=:absdays, unjustified_absence_hours=:abshours, paid_days=:paiddays, paid_hours=:paidhours, late_count=:late WHERE id=:id');
  $stmt->execute(['h' => $paidHours, 'r' => round((float) $rate, 2), 's' => $salary, 'normal' => $normalDays, 'absdays' => $absenceDays, 'abshours' => $absenceHours, 'paiddays' => $paidDays, 'paidhours' => $paidHours, 'late' => $lateCount, 'id' => $id]);
  salary_redirect('Salaire recalculé avec succès.', 'success', $month, $year);
}

if ($action === 'add') {
  $employeeId = (int) ($_POST['employee_id'] ?? 0);
  $normalDays = filter_var(str_replace(',', '.', (string) ($_POST['normal_worked_days'] ?? 0)), FILTER_VALIDATE_FLOAT);
  $absenceDays = filter_var(str_replace(',', '.', (string) ($_POST['unjustified_absence_days'] ?? 0)), FILTER_VALIDATE_FLOAT);
  $absenceHours = filter_var(str_replace(',', '.', (string) ($_POST['unjustified_absence_hours'] ?? 0)), FILTER_VALIDATE_FLOAT);
  $paidDays = filter_var(str_replace(',', '.', (string) ($_POST['paid_days'] ?? 0)), FILTER_VALIDATE_FLOAT);
  $paidHours = filter_var(str_replace(',', '.', (string) ($_POST['paid_hours'] ?? 0)), FILTER_VALIDATE_FLOAT);
  $lateCount = (int) ($_POST['late_count'] ?? 0);

  if ($employeeId <= 0 || $normalDays === false || $normalDays < 0 || $absenceDays === false || $absenceDays < 0 || $absenceHours === false || $absenceHours < 0 || $paidDays === false || $paidDays < 0 || $paidHours === false || $paidHours < 0 || $lateCount < 0) {
    salary_redirect('Veuillez vérifier les données de paie.', 'error', $month, $year);
  }

  $stmt = $pdo->prepare("SELECT id, hourly_rate, position FROM employees WHERE id=:id AND status='Active' LIMIT 1");
  $stmt->execute(['id' => $employeeId]);
  $employee = $stmt->fetch();

  if (!$employee) {
    salary_redirect('Salarié introuvable ou inactif.', 'error', $month, $year);
  }

  $rate = round((float) $employee['hourly_rate'], 2);
  $salaryBase = $employee['position'] === 'Responsable' ? (float) $paidDays : (float) $paidHours;
  $salary = round($salaryBase * $rate, 2);

  try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO salary_records(employee_id, month, year, total_hours, hourly_rate_used, calculated_salary, normal_worked_days, unjustified_absence_days, unjustified_absence_hours, paid_days, paid_hours, late_count) VALUES(:eid, :m, :y, :h, :r, :s, :normal, :absdays, :abshours, :paiddays, :paidhours, :late) ON DUPLICATE KEY UPDATE total_hours=VALUES(total_hours), hourly_rate_used=VALUES(hourly_rate_used), calculated_salary=VALUES(calculated_salary), normal_worked_days=VALUES(normal_worked_days), unjustified_absence_days=VALUES(unjustified_absence_days), unjustified_absence_hours=VALUES(unjustified_absence_hours), paid_days=VALUES(paid_days), paid_hours=VALUES(paid_hours), late_count=VALUES(late_count), updated_at=CURRENT_TIMESTAMP');
    $stmt->execute(['eid' => $employee['id'], 'm' => $month, 'y' => $year, 'h' => $paidHours, 'r' => $rate, 's' => $salary, 'normal' => $normalDays, 'absdays' => $absenceDays, 'abshours' => $absenceHours, 'paiddays' => $paidDays, 'paidhours' => $paidHours, 'late' => $lateCount]);
    $pdo->commit();
    salary_redirect('Salaire calculé et enregistré avec succès.', 'success', $month, $year);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('rh_salary_add: ' . $e->getMessage());
    salary_redirect('Impossible d\'enregistrer le salaire.', 'error', $month, $year);
  }
}

salary_redirect('Action inconnue.', 'error', $month, $year);
