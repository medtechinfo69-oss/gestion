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
            ];

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                if (APP_ENV === 'development') {
                    die('Erreur de connexion à la base de données : ' . $e->getMessage());
                }
                error_log('DB connection error: ' . $e->getMessage());
                die('Une erreur est survenue. Veuillez réessayer plus tard.');
            }
        }

        return self::$instance;
    }

    // Empêche le clonage et l'instanciation externe
    private function __construct() {}
    private function __clone() {}
}
