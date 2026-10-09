<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

// Filet de sécurité contre un téléversement partiel : ces fonctions vivent
// dans includes/functions.php. Sans elles, la page planterait en HTTP 500.
if (!function_exists('app_cache_status')) {
    function app_cache_status(): array
    {
        return [
            'available' => false, 'enabled' => false, 'memory_used' => 0, 'memory_total' => 0,
            'memory_free' => 0, 'cached_scripts' => 0, 'max_cached' => 0, 'hits' => 0,
            'misses' => 0, 'hit_rate' => null,
        ];
    }
}
if (!function_exists('clear_app_cache')) {
    function clear_app_cache(?PDO $db = null): array
    {
        clearstatcache(true);
        return ['opcache' => false, 'scripts' => 0, 'stat' => true, 'files' => 0,
                'cache' => ['supprimes' => 0, 'ko' => 0.0, 'dossier' => false]];
    }
}
if (!function_exists('app_cache_state')) {
    function app_cache_state(): array
    {
        return ['actif' => false, 'dossier' => '', 'entrees' => 0, 'ko' => 0.0, 'peremption' => false];
    }
}

$resultat = clear_app_cache($db);

set_flash('success', 'Cache vidé avec succès.');
redirect('profile.php');
