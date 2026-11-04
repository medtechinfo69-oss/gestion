<?php
/**
 * Sauvegarde automatique quotidienne — exécutée par le planificateur Windows
 * (tâche « GestionDossiers Backup Quotidien », tous les jours à 21:00
 * Africa/Tunis, avec rattrapage si le PC était éteint).
 *
 * La logique est partagée avec le déclenchement automatique côté serveur :
 * voir includes/backup_runner.php et actions/backup_autorun.php.
 *
 * Usage CLI :  php scripts/backup_daily.php [--force]
 *   --force : sauvegarde même si celle du jour existe déjà (utile pour tester).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Accès direct interdit (CLI uniquement).');
}

date_default_timezone_set('Africa/Tunis');

define('APP_INIT', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/backup_runner.php';

$backupDir = __DIR__ . '/../backup';

try {
    $db = Database::getConnection();
} catch (Throwable $e) {
    gd_backup_log($backupDir, 'ERREUR connexion base : ' . $e->getMessage());
    exit(1);
}

// Déjà sauvegardé aujourd'hui (heure de Tunisie) ? On ne refait pas, sauf --force.
$force = in_array('--force', (array) ($_SERVER['argv'] ?? []), true);
$last = gd_last_backup_time($backupDir);
if (!$force && $last > 0 && date('Y-m-d', $last) === date('Y-m-d')) {
    echo 'Sauvegarde du jour deja presente (' . date('Y-m-d H:i:s', $last) . ') : rien a faire.' . PHP_EOL;
    exit(0);
}

$result = gd_run_backup($backupDir);
echo $result ? 'Sauvegarde OK : ' . basename($result['json']) . PHP_EOL : 'Sauvegarde ECHOUEE (voir logs/backup.log).' . PHP_EOL;
exit($result ? 0 : 1);
