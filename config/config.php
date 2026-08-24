<?php
/** Sélectionne automatiquement la configuration locale ou hébergée. */
if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Accès direct interdit.');
}

$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$isLocal = $host === '' || strpos($host, 'localhost') === 0 || strpos($host, '127.0.0.1') === 0;
$configFile = $isLocal ? __DIR__ . '/config.local.php' : __DIR__ . '/config.hosting.php';

if (!is_file($configFile)) {
    http_response_code(500);
    exit('Configuration manquante : copiez config.hosting.example.php vers config.hosting.php et renseignez les identifiants MySQL de votre hebergeur.');
}

require_once $configFile;
