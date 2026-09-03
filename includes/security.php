<?php
/**
 * Sécurité avancée : journalisation des événements de sécurité,
 * limitation de débit (rate limiting) et détection d'activité anormale.
 * Ce module ne bloque JAMAIS l'application si la table rate_limits
 * est absente (fail-open) : les protections se renforcent après
 * l'application du fichier database/upgrade_v2.sql.
 */

if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Accès direct interdit.');
}

/** Chemin du journal de sécurité (logs/security.log). */
function security_log_path(): string
{
    return dirname(__DIR__) . '/logs/security.log';
}

/**
 * Inscrit un événement de sécurité dans logs/security.log.
 * Ex : log_security_event('BRUTEFORCE', 'Tentative échouée pour admin');
 */
function log_security_event(string $type, string $detail): void
{
    try {
        $ip      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ua      = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200);
        $line    = sprintf(
            "[%s] %s %s (ip:%s ua:%s) %s%s",
            date('Y-m-d H:i:s'),
            $type,
            $detail,
            $ip,
            $ua,
            PHP_EOL,
            PHP_EOL
        );
        $dir = dirname(security_log_path());
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(security_log_path(), $line, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        // La journalisation ne doit jamais interrompre l'application.
    }
}

/**
 * Limitation de débit côté serveur basée sur la table rate_limits.
 * Retourne TRUE si la requête est autorisée, FALSE si elle dépasse la limite.
 *
 * @param PDO    $db       Connexion base de données.
 * @param string $action   Nom de l'action (login, upload, export...).
 * @param int    $max      Nombre maximal de tentatives autorisées.
 * @param int    $window   Fenêtre en secondes (ex : 300 pour 5 min).
 */
function rate_limit_allowed(PDO $db, string $action, int $max = 10, int $window = 300): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    // Identifiant composite IP + action
    $bucket = $action . '|' . $ip;

    try {
        // Nettoyage des entrées expirées (une fois toutes les ~50 requêtes)
        if (random_int(1, 50) === 1) {
            $db->prepare('DELETE FROM rate_limits WHERE window_start < (NOW() - INTERVAL 2 DAY)')
               ->execute();
        }

        $stmt = $db->prepare(
            'SELECT attempts, window_start
               FROM rate_limits
              WHERE bucket = :b
              LIMIT 1'
        );
        $stmt->execute(['b' => $bucket]);
        $row = $stmt->fetch();

        $now  = time();
        $start = strtotime((string) ($row['window_start'] ?? date('Y-m-d H:i:s')));
        $attempts = (int) ($row['attempts'] ?? 0);

        if (!$row || ($now - $start) > $window) {
            // Nouvelle fenêtre : réinitialise le compteur
            $stmt = $db->prepare(
                'INSERT INTO rate_limits (bucket, attempts, window_start)
                 VALUES (:b, 1, NOW())
                 ON DUPLICATE KEY UPDATE attempts = 1, window_start = NOW()'
            );
            $stmt->execute(['b' => $bucket]);
            return true;
        }

        if ($attempts >= $max) {
            return false;
        }

        $stmt = $db->prepare(
            'UPDATE rate_limits SET attempts = attempts + 1 WHERE bucket = :b'
        );
        $stmt->execute(['b' => $bucket]);
        return true;
    } catch (Throwable $e) {
        // Table absente ou erreur transitoire : ne pas bloquer l'application.
        return true;
    }
}

/**
 * Affiche une réponse HTTP 429 (Too Many Requests) et journalise.
 */
function rate_limit_deny(string $action): void
{
    log_security_event('RATE_LIMIT', 'Limite dépassée pour ' . $action);
    http_response_code(429);
    die('Trop de requêtes. Veuillez patienter avant de réessayer.');
}