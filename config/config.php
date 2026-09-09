<?php
/** Sélectionne automatiquement la configuration locale ou hébergée. */
if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Accès direct interdit.');
}

$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$host = preg_replace('/:\d+$/', '', $host); // retire le port éventuel
// Accès local : localhost, 127.0.0.1 ou toute IP du réseau LAN (poste du même PC ou autre PC du réseau)
$isLocal = $host === '' || $host === 'localhost' || $host === '127.0.0.1'
    || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
    || strpos($host, '::1') === 0;
$configFile = $isLocal ? __DIR__ . '/config.local.php' : __DIR__ . '/config.hosting.php';

if (!is_file($configFile)) {
    http_response_code(500);
    exit('Configuration manquante : copiez config.hosting.example.php vers config.hosting.php et renseignez les identifiants MySQL de votre hebergeur.');
}

require_once $configFile;

if (!defined('ITEMS_PER_PAGE')) {
    define('ITEMS_PER_PAGE', 25);
}
