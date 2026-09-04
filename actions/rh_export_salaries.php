<?php
require_once __DIR__ . '/../includes/init.php';
require_admin_or_superviseur();
require_once __DIR__ . '/../includes/xlsx.php';

$month = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('n');
$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$month = ($month >= 1 && $month <= 12) ? $month : (int) date('n');
$year = ($year >= 2000 && $year <= 2100) ? $year : (int) date('Y');

$stmt = $db->prepare('SELECT e.employee_code, e.full_name, e.pseudo, sr.normal_worked_days, sr.unjustified_absence_days, sr.unjustified_absence_hours, sr.paid_days, sr.paid_hours, sr.late_count, sr.calculated_salary FROM salary_records sr JOIN employees e ON e.id = sr.employee_id WHERE sr.month=:m AND sr.year=:y ORDER BY e.full_name');
$stmt->execute(['m' => $month, 'y' => $year]);
$rows = [['Matricule', 'Nom & prénom', 'Peseudo', 'Jour Normalement Travaillé', 'ABS Non Justifiée en jours', 'ABS Non Justifiée en heures', 'Jour Payé', 'Heure Payée', 'Nb Retards', 'Salaire']];

while ($row = $stmt->fetch()) {
  $rows[] = [
    $row['employee_code'],
    $row['full_name'],
    $row['pseudo'] ?: '',
    $row['normal_worked_days'],
    $row['unjustified_absence_days'],
    $row['unjustified_absence_hours'],
    $row['paid_days'],
    $row['paid_hours'],
    $row['late_count'],
    $row['calculated_salary'],
  ];
}

create_xlsx('salaires_' . $year . '_' . $month . '.xlsx', 'Salaires', $rows);
