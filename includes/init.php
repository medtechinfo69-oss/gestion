<?php
/**
 * Amorçage de l'application : à inclure en tout premier sur chaque page.
 * Charge la configuration, la connexion base de données et les
 * fonctions utilitaires, puis démarre une session sécurisée.
 */

define('APP_INIT', true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/functions.php';

// En-têtes de sécurité HTTP de base
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; img-src 'self' data:;");
if (APP_ENV !== 'development') {
    header('Strict-Transport-Security: max-age=63072000; includeSubDomains');
}

start_secure_session();

$db = Database::getConnection();
