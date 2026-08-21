<?php
/**
 * Utilitaire autonome : génère un hash de mot de passe compatible avec la
 * table `users.password_hash`.
 *
 * Usage (ligne de commande uniquement) :
 *   php generate_password_hash.php "MonMotDePasse123!"
 *
 * ATTENTION : ce fichier n'est jamais appelé par l'application web et ne
 * doit PAS être déployé sur un serveur de production (ou doit être
 * supprimé après usage). Il n'est fourni que pour faciliter la création
 * ou réinitialisation manuelle d'un compte directement en base.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ce script ne peut être exécuté qu’en ligne de commande.');
}

if ($argc < 2 || trim($argv[1]) === '') {
    fwrite(STDERR, "Usage : php generate_password_hash.php \"MotDePasse\"\n");
    exit(1);
}

$password = $argv[1];
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "Mot de passe : {$password}\n";
echo "Hash bcrypt  : {$hash}\n";
echo "\nRequête SQL exemple :\n";
echo "UPDATE users SET password_hash = '{$hash}' WHERE username = 'admin';\n";
