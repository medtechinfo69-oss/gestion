<?php
/**
 * INSTALLATION / DIAGNOSTIC DE LA BASE DE DONNEES DEPUIS LE NAVIGATEUR.
 *
 * Les hebergements mutualises (ByetHost, InfinityFree, ...) n'autorisent ni SSH
 * ni connexion MySQL distante : impossible d'executer `database/install.sql`
 * ou database/repair_schema.php depuis un poste local. Cet ecran applique le
 * fichier SQL DEPUIS le serveur.
 *
 * UTILISATION (le fichier doit etre accessible par le navigateur) :
 *   1. Copiez ce fichier a la RACINE du site par FTP :
 *        /htdocs/scripts/db_setup.php  ->  /htdocs/db_setup.php
 *      (le dossier scripts/ est protege : scripts/.htaccess = Require all denied)
 *   2. Diagnostic seul (aucune ecriture) :
 *        https://VOTRE-DOMAINE/db_setup.php?token=LE_JETON&check=1
 *   3. Import de database/install.sql — NON destructif (CREATE TABLE IF NOT
 *      EXISTS + INSERT IGNORE), donc relancable sans risque :
 *        https://VOTRE-DOMAINE/db_setup.php?token=LE_JETON&confirm=oui
 *   4. SUPPRIMEZ IMMEDIATEMENT /htdocs/db_setup.php du serveur.
 *
 * Securite : sans le jeton exact, le script repond 404 et n'execute rien.
 * Le mot de passe de la base n'est jamais affiche.
 */

// ---------------------------------------------------------------------
// Jeton secret : modifiez-le avant chaque usage.
// ---------------------------------------------------------------------
$EXPECTED_TOKEN = 'inst-2026-Byet-b22-3c81d5ea';

$given = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
if ($given === '' || !hash_equals($EXPECTED_TOKEN, $given)) {
    http_response_code(404);
    exit('Not found');
}

// Le script doit fonctionner a la racine du site OU dans scripts/.
$baseDir = is_file(__DIR__ . '/database/install.sql') ? __DIR__ : dirname(__DIR__);

header('Content-Type: text/plain; charset=UTF-8');

define('APP_INIT', true);
require $baseDir . '/config/config.php';

$checkMode = (string) ($_GET['check'] ?? '') !== '';
$confirm   = (string) ($_GET['confirm'] ?? '') === 'oui';

echo "=== HEBERGEMENT ===\n";
echo 'PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ') — ' . date('d/m/Y H:i:s') . "\n";
foreach (['pdo_mysql', 'mbstring', 'fileinfo', 'zip', 'openssl', 'curl', 'gd', 'json', 'session'] as $ext) {
    printf("  %-10s %s\n", $ext, extension_loaded($ext) ? 'OK' : 'MANQUANTE');
}
echo 'APP_ENV=' . (defined('APP_ENV') ? APP_ENV : '?') . ' APP_URL=' . (defined('APP_URL') ? APP_URL : '?') . "\n";
echo 'DB_HOST=' . DB_HOST . ' DB_NAME=' . DB_NAME . ' DB_USER=' . DB_USER
    . ' DB_PASS=' . (DB_PASS === '' ? '(vide)' : 'defini') . "\n";
echo 'upload_max_filesize=' . ini_get('upload_max_filesize') . ' memory_limit=' . ini_get('memory_limit')
    . ' max_execution_time=' . ini_get('max_execution_time') . "\n";

echo "\n=== DROITS D'ECRITURE ===\n";
foreach (['uploads/dossiers', 'uploads/tmp', 'logs'] as $dir) {
    $path = $baseDir . '/' . $dir;
    if (!is_dir($path)) {
        printf("  %-20s ABSENT\n", $dir);
        continue;
    }
    $probe = $path . '/.probe-' . bin2hex(random_bytes(4)) . '.tmp';
    $ok = @file_put_contents($probe, 'test') !== false;
    if ($ok) {
        @unlink($probe);
    }
    printf("  %-20s %s\n", $dir, $ok ? 'ECRITURE OK' : 'ECRITURE REFUSEE');
}

echo "\n=== BASE DE DONNEES ===\n";
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 20,
    ]);
    echo 'connexion : OK — ' . $pdo->query('SELECT VERSION()')->fetchColumn()
        . ' — utilisateur ' . $pdo->query('SELECT CURRENT_USER()')->fetchColumn() . "\n";

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo 'tables presentes : ' . count($tables) . "\n";

    $required = ['users', 'dossiers', 'dossier_historique', 'dossier_attachments', 'login_log',
        'settings', 'rate_limits', 'chat_messages', 'chat_presence', 'salary_records', 'origines',
        'admin_notifications'];
    $missing = array_values(array_diff($required, $tables));
    echo 'tables requises manquantes : ' . ($missing === [] ? 'aucune' : implode(', ', $missing)) . "\n";

    if (in_array('users', $tables, true)) {
        echo 'comptes (users) : ' . (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() . "\n";
        foreach ($pdo->query('SELECT username, role, is_active FROM users ORDER BY id LIMIT 40')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            echo '  - ' . str_pad((string) $r['username'], 24) . str_pad((string) $r['role'], 12) . 'actif=' . $r['is_active'] . "\n";
        }
    }
} catch (Throwable $e) {
    echo 'ERREUR BDD : ' . $e->getMessage() . "\n";
    exit;
}

if ($checkMode) {
    echo "\n(diagnostic seul — aucune modification effectuee)\n";
    exit;
}

if (!$confirm) {
    echo "\nImport non confirme : ajoutez &confirm=oui a l'URL pour appliquer database/install.sql.\n";
    exit;
}

// ---------------------------------------------------------------------
// Import de database/install.sql
// ---------------------------------------------------------------------
$sqlFile = $baseDir . '/database/install.sql';
$sql = is_file($sqlFile) ? (string) file_get_contents($sqlFile) : '';
if ($sql === '') {
    exit("\ndatabase/install.sql introuvable : " . $sqlFile . "\n");
}

// Les commentaires de ligne sont retires, puis le fichier est decoupe sur le
// point-virgule en fin de ligne (install.sql ne contient ni trigger ni
// procedure stockee : un decoupage simple suffit).
$body = (string) preg_replace('/^\s*--.*$/m', '', $sql);
$statements = preg_split('/;\s*\n/', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [];

echo "\n=== IMPORT DE database/install.sql ===\n";
$executed = 0;
$errors = [];
foreach ($statements as $i => $statement) {
    $statement = trim($statement);
    if ($statement === '') {
        continue;
    }
    $label = substr((string) preg_replace('/\s+/', ' ', $statement), 0, 70);
    try {
        $pdo->exec($statement);
        $executed++;
        printf("#%02d OK    %s\n", $i, $label);
    } catch (Throwable $e) {
        $errors[] = $label;
        printf("#%02d ECHEC %s\n     -> %s\n", $i, $label, $e->getMessage());
    }
}

echo "\nStatements executes : {$executed} / " . count($statements) . "\n";
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo 'tables presentes : ' . count($tables) . "\n";
if (in_array('users', $tables, true)) {
    echo 'comptes (users) : ' . (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() . "\n";
}
echo 'erreurs : ' . count($errors) . "\n";
echo "\n>>> SUPPRIMER CE FICHIER DU SERVEUR MAINTENANT <<<\n";
