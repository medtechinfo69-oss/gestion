<?php
// Force upload : envoie UNIQUEMENT les fichiers de la MAJ DL manuelle + cache bump.
// Usage CLI : php scripts/force_upload_dl.php
// Ecrit le resultat dans uploads/force_upload_log.txt (lisible ensuite).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
define('PROJECT_ROOT_FU', __DIR__ . '/..');
$files = [
  'dossier_form.php',
  'dossiers.php',
  'dossier_view.php',
  'includes/functions.php',
  'actions/dossier_save.php',
  'actions/dossiers_import.php',
  'actions/dossiers_export.php',
  'actions/backup_import.php',
  'database/install.sql',
  'database/repair_schema.php',
  'database/migration_dl_resiliation.sql',
  'config/config.hosting.php',
];
$cmd = 'php ' . escapeshellarg(PROJECT_ROOT_FU . '/scripts/deploy_ftp.php')
  . ' upload --force --files=' . escapeshellarg(implode(',', $files));
$logFile = PROJECT_ROOT_FU . '/uploads/force_upload_log.txt';
@mkdir(dirname($logFile), 0777, true);
file_put_contents($logFile, date('Y-m-d H:i:s') . " CMD: $cmd\n");
exec('cd ' . escapeshellarg(PROJECT_ROOT_FU) . ' && ' . $cmd . ' 2>&1', $out, $code);
file_put_contents($logFile, "EXIT: $code\n" . implode("\n", $out) . "\n", FILE_APPEND);
echo "EXIT:$code\n" . implode("\n", $out) . "\n";
