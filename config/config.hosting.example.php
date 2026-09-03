<?php
if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Accès direct interdit.');
}

// Copiez ce fichier vers config.hosting.php puis renseignez vos valeurs.
define('DB_HOST', 'HOSTNAME_MYSQL');
define('DB_NAME', 'NOM_COMPLET_DE_LA_BASE');
define('DB_USER', 'UTILISATEUR_MYSQL');
define('DB_PASS', 'MOT_DE_PASSE_MYSQL');
define('DB_CHARSET', 'utf8mb4');
define('APP_NAME', 'Gestion des Dossiers');
define('APP_URL', 'https://votre-domaine.example');
define('APP_ENV', 'production');
define('SECURITY_MODE', true);
define('APP_SECRET_KEY', 'REMPLACEZ_PAR_UNE_CHAINE_ALEATOIRE_LONGUE_ET_SECRETE');
define('PASSWORD_MAX_AGE_DAYS', 90);
define('SESSION_LIFETIME', 7200);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900);
define('ITEMS_PER_PAGE', 25);
define('IMPORT_MAX_SIZE', 10 * 1024 * 1024);
define('UPLOAD_DIR', __DIR__ . '/../uploads/dossiers/');
define('UPLOAD_MAX_SIZE', 5 * 1024 * 1024);
define('UPLOAD_ALLOWED_EXT', ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'webm', 'opus', 'wma']);
define('UPLOAD_ALLOWED_MIME', [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/mp4', 'audio/aac', 'audio/flac', 'audio/webm', 'audio/opus', 'audio/x-ms-wma',
]);

date_default_timezone_set('Europe/Paris');
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/php-error.log');
