<?php
// Copy this file to config.php and set local values.
if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Acces direct interdit.');
}

define('DB_HOST', 'localhost');
define('DB_NAME', 'gestion_dossiers');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');
define('DB_CHARSET', 'utf8mb4');
define('APP_NAME', 'Gestion des Dossiers');
define('APP_URL', 'http://localhost/gestion-dossiers');
define('APP_ENV', 'development');
define('SECURITY_MODE', true);
define('SESSION_LIFETIME', 7200);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900);
define('ITEMS_PER_PAGE', 25);
define('IMPORT_MAX_SIZE', 10 * 1024 * 1024);
define('UPLOAD_DIR', __DIR__ . '/../uploads/dossiers/');
define('UPLOAD_MAX_SIZE', 5 * 1024 * 1024);
define('UPLOAD_ALLOWED_EXT', ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx']);
define('UPLOAD_ALLOWED_MIME', [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
]);
