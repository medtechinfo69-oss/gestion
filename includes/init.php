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
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/functions.php';

// En-têtes de sécurité HTTP
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-XSS-Protection: 1; mode=block');
header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-site');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; form-action 'self'; base-uri 'self'; frame-ancestors 'none';");
if (APP_ENV !== 'development') {
    header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
    // Force cache refresh on production deployment
    header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
}

start_secure_session();

$db = Database::getConnection();
