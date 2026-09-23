<?php
/**
 * Amorçage de l'application : à inclure en tout premier sur chaque page.
 * Charge la configuration, la connexion base de données et les
 * fonctions utilitaires, puis démarre une session sécurisée.
 */

define('APP_INIT', true);

// Configuration AVANT tout le reste : elle définit APP_ENV, APP_URL, DB_*.
// Elle doit être chargée en premier car le bloc opcache ci-dessous lit APP_ENV :
// sinon, sur un hébergement où opcache est chargé, PHP 8 lève une erreur fatale
// « Undefined constant "APP_ENV" » => page blanche HTTP 500.
require_once __DIR__ . '/../config/config.php';

// ---------------------------------------------------------------------------
// PERFORMANCE — configuration du cache d'opcodes (opcache).
//
// PHP recompile chaque fichier inclus à chaque requête sans opcache. Cette
// appli charge 10 fichiers via init.php : sans cache, ce sont des milliers
// de lectures/compilations par jour. Les directives sont posées ici (et non
// dans php.ini) pour que l'optimisation suive le code, y compris sur un
// hébergement mutualisé où php.ini n'est pas modifiable.
// ini_set ne peut pas activer opcache s'il est globalement désactivé, mais
// il règle finement le comportement quand opcache est déjà présent.
// ---------------------------------------------------------------------------
if (function_exists('opcache_get_status')) {
    // Lecture défensive : ne jamais planter si la config n'a pas défini APP_ENV.
    $appEnv = defined('APP_ENV') ? APP_ENV : 'production';
    @ini_set('opcache.enable', '1');
    @ini_set('opcache.validate_timestamps', $appEnv === 'development' ? '1' : '0');
    @ini_set('opcache.revalidate_freq', $appEnv === 'development' ? '0' : '60');
    @ini_set('opcache.memory_consumption', '128');
    @ini_set('opcache.max_accelerated_files', '4000');
    if ($appEnv !== 'development') {
        @ini_set('opcache.save_comments', '0');
        @ini_set('opcache.fast_shutdown', '1');
    }
}

// Buffer de sortie : permet à PHP/Apache de compresser la page entière d'un
// bloc (gain sur le temps de transfert) et laisse la possibilité d'envoyer
// des en-têtes (cache, redirection) après le début du rendu.
if (!ob_get_level()) {
    ob_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/security_integration.php';
require_once __DIR__ . '/SecurityDashboard.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/notifications.php';

// En-têtes de sécurité HTTP
header('X-Content-Type-Options: nosniff');
// SAMEORIGIN (et non DENY) : autorise le site à s'afficher dans ses propres
// iframes (transport de secours du chat), tout en bloquant les sites tiers.
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-XSS-Protection: 1; mode=block');
header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-site');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; form-action 'self'; base-uri 'self'; frame-ancestors 'self';");
if (APP_ENV !== 'development') {
    header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
    // Force cache refresh on production deployment
    header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
}

start_secure_session();

$db = Database::getConnection();

// ---------------------------------------------------------------------------
// Transport de secours du chat interne : sert l'API VIA LA PAGE COURANTE
// (?chat_api=status|messages|send). Certains hébergeurs gratuits filtrent les
// requêtes AJAX vers le dossier actions/ ; une requête vers la page elle-même
// passe toujours. Même sécurité que l'endpoint classique (rôles + CSRF).
// ---------------------------------------------------------------------------
if (isset($_REQUEST['chat_api'])
    && is_logged_in()
    && in_array($_SESSION['user']['role'] ?? '', ['admin', 'superviseur', 'vendeur'], true)) {
    // Réponse 100 % JSON : en dev (display_errors=1), tout warning échoé
    // avant le JSON casse r.json() côté client (bulle « non envoyé » à tort).
    while (ob_get_level() > 0) { @ob_end_clean(); }
    @ini_set('display_errors', '0');
    require_once __DIR__ . '/chat_api_handler.php';
    chat_api_handle($db, (string) $_REQUEST['chat_api']);
    exit;
}
