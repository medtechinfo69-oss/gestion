<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
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

$filename = 'salaires_' . $year . '_' . $month . '.xlsx';
ob_start();
create_xlsx($filename, 'Salaires', $rows);
$fileContents = ob_get_clean();
$temporaryFile = tempnam(sys_get_temp_dir(), 'salary_export_');
$emailSent = false;
if ($temporaryFile !== false) {
  file_put_contents($temporaryFile, $fileContents);
  $emailSent = notify_admins($db, 'Export des salaires ' . $month . '/' . $year, 'L’export Excel des salaires a été généré par ' . (string) (current_user()['nom_complet'] ?? 'un administrateur') . '.', $temporaryFile, $filename);
  @unlink($temporaryFile);
}
set_flash($emailSent ? 'success' : 'error', $emailSent
  ? 'Le fichier Excel a été envoyé par e-mail.'
  : 'L’envoi e-mail a échoué. Vérifiez la configuration SMTP et les logs.');
redirect('rh_salaries.php?month=' . $month . '&year=' . $year);
