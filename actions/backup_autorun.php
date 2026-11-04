<?php
/**
 * Déclenchement automatique de la sauvegarde quotidienne côté serveur.
 *
 * But : garantir la sauvegarde de 21:00 (Africa/Tunis) même quand le PC est
 * éteint, puisque le site hébergé (ByetHost) tourne 24h/24. Le script est
 * appelé à la volée par includes/footer.php sur chaque chargement de page ;
 * il ne fait réellement rien si :
 *   - il n'est pas encore 21:00 aujourd'hui ;
 *   - une sauvegarde datant d'aujourd'hui existe déjà (idempotent) ;
 *   - le fichier de verrou est plus jeune que 1 h (anti-rafale si plusieurs
 *     visiteurs chargent une page en même temps).
 *
 * Aucune sortie, aucune erreur visible : la navigation ne doit jamais être
 * perturbée. Toute anomalie est consignée dans logs/backup.log.
 *
 * Peut aussi être appelé manuellement par un cron externe :
 *   https://<site>/actions/backup_autorun.php?key=<AUTOBACKUP_KEY>
 * (sans la clé, seule l'exécution inline par footer.php est permise).
 */

if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Accès direct interdit.');
}

function gd_backup_autorun(): void
{
    try {
        date_default_timezone_set('Africa/Tunis');
        $backupDir = dirname(__DIR__) . '/backup';
        $lockFile = $backupDir . '/.autorun.lock';

        // Déjà une sauvegarde datant d'aujourd'hui (heure de Tunisie) ? Rien à faire.
        if (
            gd_last_backup_time($backupDir) > 0
            && date('Y-m-d', gd_last_backup_time($backupDir)) === date('Y-m-d')
        ) {
            return;
        }

        // Verrou anti-rafale : une seule tentative par heure maximum.
        if (is_file($lockFile) && (time() - (int) filemtime($lockFile)) < 3600) {
            return;
        }
        @mkdir($backupDir, 0755, true);
        @file_put_contents($lockFile, (string) time());

        // Il n'est pas encore 21:00 aujourd'hui : rien à faire (le PC local,
        // lui, est géré par la tâche planifiée Windows à 21:00 pile).
        if ((int) date('G') < 21) {
            return;
        }

        gd_run_backup($backupDir);
    } catch (Throwable $e) {
        // Jamais d'erreur visible pour l'utilisateur.
        @file_put_contents(
            dirname(__DIR__) . '/logs/backup.log',
            '[' . date('Y-m-d H:i:s') . '] ERREUR autorun : ' . $e->getMessage() . PHP_EOL,
            FILE_APPEND
        );
    }
}

gd_backup_autorun();
