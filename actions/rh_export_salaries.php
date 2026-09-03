<?php
require_once __DIR__ . '/../includes/init.php';
require_admin_or_superviseur();
require_once __DIR__ . '/../includes/xlsx.php';

$month = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('n');
$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$month = ($month >= 1 && $month <= 12) ? $month : (int) date('n');
$year = ($year >= 2000 && $year <= 2100) ? $year : (int) date('Y');

$stmt = $db->prepare('SELECT e.full_name, e.employee_code, e.department, e.position, sr.month, sr.year, sr.total_hours, sr.hourly_rate_used, sr.calculated_salary FROM salary_records sr JOIN employees e ON e.id = sr.employee_id WHERE sr.month=:m AND sr.year=:y ORDER BY e.full_name');
$stmt->execute(['m' => $month, 'y' => $year]);
$rows = [['Nom complet', 'Matricule', 'Département', 'Poste', 'Mois', 'Année', 'Total des heures', 'Régime horaire', 'Salaire']];

while ($row = $stmt->fetch()) {
  $rows[] = [
    $row['full_name'],
    $row['employee_code'],
    $row['department'] ?: '',
    $row['position'] ?: '',
    $row['month'],
    $row['year'],
    $row['total_hours'],
    $row['hourly_rate_used'],
    $row['calculated_salary'],
  ];
}

create_xlsx('salaires_' . $year . '_' . $month . '.xlsx', 'Salaires', $rows);
