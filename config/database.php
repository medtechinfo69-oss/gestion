<?php
/**
 * Connexion PDO à la base de données (singleton).
 * Utilise systématiquement des requêtes préparées côté appelant
 * pour se prémunir des injections SQL.
 */

if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Accès direct interdit.');
}

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false, // requêtes préparées réelles côté MySQL
                // PERFORMANCE — connexions persistantes : la connexion reste
                // ouverte entre deux requêtes du même processus, ce qui
                // supprime le coût du handshake TCP + authentification MySQL
                // à chaque appel de page (gain sensible en développement).
                // ATTENTION hébergement mutualisé (InfinityFree, etc.) : les
                // connexions persistantes y sont interdites/limitées et
                // provoquent des « SQLSTATE[HY000] [2002] Connection refused »
                // dès que le quota de connexions simultanées est atteint.
                // Elles ne sont donc activées qu'en développement.
                PDO::ATTR_PERSISTENT         => (defined('APP_ENV') && APP_ENV === 'development'),
                // Force l'encodage en UTF-8 sur la connexion (déjà dans le DSN,
                // mais évite tout paramétrage par défaut côté serveur).
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET,
                // Empêche PDO de charger les colonnes inutilement lors de
                // certains SELECT si le pilote ne les demande pas.
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                // Un serveur MySQL injoignable ne doit pas faire attendre la
                // page indéfiniment (10 s max, puis message d'indisponibilité).
                PDO::ATTR_TIMEOUT            => 10,
            ];

            try {
                self::$instance = self::connectWithRetry($dsn, $options);

                // Optimise le cache de requêtes et la taille des tris/collations
                // pour cette session MySQL. Isolé dans son propre try/catch :
                // un hébergeur refusant « SET SESSION sql_mode » ne doit jamais
                // empêcher l'application de fonctionner.
                try {
                    self::$instance->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
                } catch (Throwable $e) {
                    error_log('DB sql_mode not applied: ' . $e->getMessage());
                }
            } catch (PDOException $e) {
                if (defined('APP_ENV') && APP_ENV === 'development') {
                    die('Erreur de connexion à la base de données : ' . $e->getMessage());
                }
                error_log('DB connection error: ' . $e->getMessage());
                self::renderDatabaseUnavailablePage();
            }
        }

        return self::$instance;
    }

    /**
     * Tente la connexion, puis la retente une fois en cas d'échec transitoire.
     * Les serveurs MySQL mutualisés refusent parfois une connexion de façon
     * ponctuelle (« [2002] Connection refused ») : une seconde tentative à
     * 400 ms d'intervalle suffit le plus souvent à passer.
     */
    private static function connectWithRetry(string $dsn, array $options): PDO
    {
        $attempts = 2;
        $lastError = null;

        for ($i = 1; $i <= $attempts; $i++) {
            try {
                return new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                $lastError = $e;
                if ($i < $attempts) {
                    usleep(400000); // 400 ms avant la nouvelle tentative
                }
            }
        }

        throw $lastError;
    }

    /**
     * Page d'indisponibilité affichée quand la base de données est injoignable.
     * Volontairement renvoyée en HTTP 200 : sur certains hébergements
     * mutualisés (InfinityFree), toute réponse 5xx est REMPLACÉE par une page
     * d'erreur générique de l'hébergeur (« HTTP ERROR 500 — Impossible de
     * traiter cette demande »). Un 200 garantit que CE message clair est vu.
     */
    private static function renderDatabaseUnavailablePage(): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
            header('Retry-After: 60');
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }

        echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>Service momentanément indisponible</title></head>'
            . '<body style="margin:0;font-family:system-ui,Segoe UI,Arial,sans-serif;'
            . 'background:#f3f4f6;display:flex;align-items:center;justify-content:center;min-height:100vh;">'
            . '<div style="max-width:520px;margin:24px;padding:28px 30px;background:#fff;border-radius:12px;'
            . 'box-shadow:0 10px 30px rgba(15,23,42,.12);text-align:center;">'
            . '<h1 style="margin:0 0 10px;font-size:20px;color:#1f2937;">Service momentanément indisponible</h1>'
            . '<p style="margin:0 0 14px;color:#374151;line-height:1.6;">'
            . 'La base de données n\'a pas pu être contactée. Le service reprend automatiquement '
            . 'dès que la connexion est rétablie.</p>'
            . '<p style="margin:0 0 20px;color:#6b7280;font-size:14px;">'
            . 'Merci de réessayer dans une minute. Si le problème persiste, contactez l\'administrateur.</p>'
            . '<a href="" style="display:inline-block;padding:10px 18px;background:#1f2937;color:#fff;'
            . 'text-decoration:none;border-radius:8px;font-weight:600;">Réessayer</a>'
            . '</div></body></html>';
        exit;
    }

    // Empêche le clonage et l'instanciation externe
    private function __construct() {}
    private function __clone() {}
}
