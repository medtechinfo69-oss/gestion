<?php
/**
 * Moteur de sauvegarde partagé — utilisé par :
 *   - scripts/backup_daily.php          (tâche planifiée Windows, 21:00)
 *   - actions/backup_autorun.php        (déclenchement automatique côté serveur)
 *
 * Produit, dans le dossier de sauvegarde passé en paramètre :
 *   - backup_YYYY-MM-DD_HH-mm-ss.json          : export complet de la base
 *     (format « gestion-dossiers-backup », identique à l'export manuel
 *     actions/backup_export.php => restaurable via « Restaurer ») ;
 *   - backup_YYYY-MM-DD_HH-mm-ss_fichiers.zip  : pièces jointes de
 *     uploads/dossiers (si le dossier en contient).
 *
 * Chaque exécution crée de NOUVEAUX fichiers horodatés : rien n'est écrasé.
 */

if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Accès direct interdit.');
}

/**
 * Repère (timestamp Unix) de la dernière sauvegarde présente dans le dossier,
 * déduit du nom du fichier le plus récent (backup_YYYY-MM-DD_HH-mm-ss.json).
 * Renvoie 0 si aucune sauvegarde n'existe.
 */
function gd_last_backup_time(string $backupDir): int
{
    $last = 0;
    if (!is_dir($backupDir)) {
        return 0;
    }
    foreach ((array) scandir($backupDir) as $entry) {
        if (preg_match('/^backup_(\d{4})-(\d{2})-(\d{2})_(\d{2})-(\d{2})-(\d{2})\.json$/', (string) $entry, $m) !== 1) {
            continue;
        }
        $ts = mktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]);
        if ($ts > $last) {
            $last = $ts;
        }
    }
    return $last;
}

/**
 * Exécute une sauvegarde complète. Renvoie un tableau descriptif en cas de
 * succès, ou false en cas d'échec (détail consigné dans logs/backup.log).
 */
function gd_run_backup(string $backupDir): array|false
{
    global $db; // connexion PDO fournie par includes/init.php ou database.php

    if (!is_dir($backupDir) && !@mkdir($backupDir, 0755, true)) {
        gd_backup_log($backupDir, 'ERREUR impossible de créer le dossier de sauvegarde.');
        return false;
    }

    // ---------------------------------------------------------------------------
    // 1. Export JSON — même format que l'export manuel (actions/backup_export.php).
    // ---------------------------------------------------------------------------
    $tables = ['users', 'dossiers', 'dossier_historique', 'dossier_attachments', 'login_log', 'dossier_trash', 'chat_messages', 'chat_presence'];
    $backup = [
        'format' => 'gestion-dossiers-backup',
        'version' => 1,
        'created_at' => date('c'),
        'tables' => [],
    ];
    foreach ($tables as $table) {
        try {
            $backup['tables'][$table] = $db->query('SELECT * FROM `' . $table . '`')->fetchAll();
        } catch (Throwable $e) {
            // Table absente (module jamais utilisé) : ignorée sans faire échouer le reste.
            $backup['tables'][$table] = [];
        }
    }

    try {
        $data = json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        gd_backup_log($backupDir, 'ERREUR encodage JSON : ' . $e->getMessage());
        return false;
    }

    // ---------------------------------------------------------------------------
    // 2. Écriture horodatée (jamais d'écrasement), de façon atomique.
    // ---------------------------------------------------------------------------
    $stamp = date('Y-m-d_H-i-s'); // backup_YYYY-MM-DD_HH-mm-ss
    $jsonPath = $backupDir . '/backup_' . $stamp . '.json';
    $tmpPath = $jsonPath . '.tmp';
    if (@file_put_contents($tmpPath, $data, LOCK_EX) === false) {
        gd_backup_log($backupDir, 'ERREUR écriture ' . $tmpPath);
        return false;
    }
    if (!@rename($tmpPath, $jsonPath)) {
        gd_backup_log($backupDir, 'ERREUR renommage ' . $tmpPath . ' -> ' . $jsonPath);
        return false;
    }

    // ---------------------------------------------------------------------------
    // 3. Pièces jointes (uploads/dossiers) — zip horodaté si des fichiers existent.
    // ---------------------------------------------------------------------------
    $uploadDir = defined('UPLOAD_DIR') ? UPLOAD_DIR : dirname(__DIR__, 2) . '/uploads/dossiers/';
    $files = [];
    if (is_dir($uploadDir)) {
        foreach ((array) scandir($uploadDir) as $entry) {
            if (is_file($uploadDir . $entry) && $entry !== '.htaccess') {
                $files[$entry] = $uploadDir . $entry;
            }
        }
    }

    $zipPath = null;
    if ($files) {
        $zipPath = $backupDir . '/backup_' . $stamp . '_fichiers.zip';
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                foreach ($files as $name => $path) {
                    $zip->addFile($path, $name);
                }
                $zip->close();
            } else {
                $zipPath = null;
                gd_backup_log($backupDir, 'AVERTISSEMENT création du zip des pièces jointes impossible.');
            }
        } else {
            gd_backup_log($backupDir, 'AVERTISSEMENT ext. ZipArchive absente : pièces jointes non incluses.');
        }
    }

    // ---------------------------------------------------------------------------
    // 4. Validation : le JSON doit être décodable et porter le bon format.
    // ---------------------------------------------------------------------------
    $check = json_decode((string) file_get_contents($jsonPath), true);
    if (!is_array($check) || ($check['format'] ?? '') !== 'gestion-dossiers-backup') {
        gd_backup_log($backupDir, 'ERREUR validation du fichier ' . basename($jsonPath));
        return false;
    }

    $size = @filesize($jsonPath) ?: 0;
    $msg = 'OK backup_' . $stamp . '.json (' . number_format($size / 1024, 1) . ' Ko)'
        . ' — lignes : ' . implode(',', array_map(static function ($t) use ($check) {
            return $t . '=' . count((array) ($check['tables'][$t] ?? []));
        }, $tables));
    if ($zipPath && is_file($zipPath)) {
        $msg .= ' + ' . basename($zipPath) . ' (' . number_format((filesize($zipPath) ?: 0) / 1024, 1) . ' Ko, ' . count($files) . ' fichiers)';
    }
    gd_backup_log($backupDir, $msg);

    return [
        'json' => $jsonPath,
        'zip' => ($zipPath && is_file($zipPath)) ? $zipPath : null,
        'stamp' => $stamp,
        'jsonsize' => $size,
        'tables' => $backup['tables'],
    ];
}

/** Consigne un message dans logs/backup.log (à côté du dossier de sauvegarde). */
function gd_backup_log(string $backupDir, string $message): void
{
    $logFile = dirname($backupDir) . '/logs/backup.log';
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
}
