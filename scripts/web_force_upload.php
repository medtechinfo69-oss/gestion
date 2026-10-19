<?php
// Upload FORCE via NAVIGATEUR (contourne le terminal bloqué).
// Ouvrez dans votre navigateur (XAMPP allumé) :
//   http://localhost/gestion-dossiers-new/scripts/web_force_upload.php?key=dl20261019
// Le script envoie les 12 fichiers modifiés vers /htdocs/ sur ByetHost via FTP
// (identifiants lus dans .vscode/sftp.json) et affiche le résultat.
// Supprimez ce fichier après usage.
declare(strict_types=1);
if (($_GET['key'] ?? '') !== 'dl20261019') { http_response_code(403); exit('Clé invalide. Ajoutez ?key=dl20261019 à l\'URL.'); }
set_time_limit(300);
$root = realpath(__DIR__ . '/..');
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
$cfg = json_decode((string) @file_get_contents($root . '/.vscode/sftp.json'), true) ?: [];
$host = $cfg['host'] ?? 'ftpupload.net'; $port = (int)($cfg['port'] ?? 21);
$user = $cfg['username'] ?? ''; $pass = $cfg['password'] ?? '';
$remoteBase = '/' . trim($cfg['remotePath'] ?? '/htdocs/', '/') . '/';
header('Content-Type: text/html; charset=utf-8');
echo '<meta charset="utf-8"><h2>Force upload vers ByetHost</h2><pre>';
$conn = @ftp_connect($host, $port, 30);
if (!$conn) { echo "ERREUR connexion $host:$port\n"; exit; }
if (!@ftp_login($conn, $user, $pass)) { echo "ERREUR login $user\n"; exit; }
@ftp_pasv($conn, true);
echo "Connecté à $host ($user)\n";
$ok = 0; $ko = 0;
foreach ($files as $rel) {
  $local = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
  $remote = $remoteBase . str_replace('\\', '/', $rel);
  if (!is_file($local)) { echo "ABSENT local : $rel\n"; $ko++; continue; }
  $dir = dirname(str_replace('\\', '/', $rel));
  if ($dir !== '.' && $dir !== '') {
    $parts = explode('/', $dir); $built = rtrim($remoteBase, '/');
    foreach ($parts as $p) { $built .= '/' . $p; @ftp_mkdir($conn, $built); }
  }
  if (@ftp_put($conn, $remote, $local, FTP_BINARY)) {
    echo "OK   $rel (" . filesize($local) . " o)\n"; $ok++;
  } else {
    echo "ECHEC $rel\n"; $ko++;
  }
}
@ftp_close($conn);
echo "\nTerminé : $ok OK / $ko ECHEC.\n";
echo "Ensuite : videz le cache (Ctrl+F5), puis admin -> Réparation BDD sur https://assurialis-app.byethost22.com\n";
echo '</pre>';
