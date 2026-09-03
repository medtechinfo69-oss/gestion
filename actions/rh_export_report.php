<?php
require_once __DIR__ . '/../includes/init.php';
require_admin_or_superviseur();
require_once __DIR__ . '/../includes/xlsx.php';

$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$year = ($year >= 2000 && $year <= 2100) ? $year : (int) date('Y');

$stmt = $db->prepare('SELECT COALESCE(NULLIF(e.department, ""), "Non renseigné") department, COUNT(DISTINCT e.id) employees, COALESCE(SUM(sr.total_hours), 0) total_hours, COALESCE(SUM(sr.calculated_salary), 0) total_salary FROM employees e LEFT JOIN salary_records sr ON sr.employee_id = e.id AND sr.year = :y GROUP BY e.department ORDER BY total_salary DESC');
$stmt->execute(['y' => $year]);
$rows = [['Département', 'Employés', 'Total des heures', 'Total des salaires']];

while ($row = $stmt->fetch()) {
  $rows[] = [
    $row['department'],
    $row['employees'],
    $row['total_hours'],
    $row['total_salary'],
  ];
}

create_xlsx('rapport_' . $year . '.xlsx', 'Rapport', $rows);
