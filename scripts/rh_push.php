<?php
/**
 * ENVOI DES DONNEES RH LOCALES (XAMPP) VERS L'HEBERGEMENT.
 *
 * Le MySQL de l'hebergeur n'est pas joignable depuis un poste local : ce script
 * exporte les employes et les fiches de salaire de la base XAMPP, puis les
 * importe SUR LE SERVEUR via scripts/rh_hosting_seed.php.
 *
 * USAGE (depuis la racine du projet) :
 *   php scripts/rh_push.php                       tout (vendeurs + employes + salaires)
 *   php scripts/rh_push.php --sans-vendeurs       n'envoie pas la liste readme.txt
 *   php scripts/rh_push.php --sans-salaires       n'envoie que les employes
 *   php scripts/rh_push.php --dry-run             exporte sans rien televerser
 *   php scripts/rh_push.php --keep-remote         laisse les fichiers temporaires
 *
 * Sources de configuration :
 *   - local  : config/config.local.php      (base XAMPP)
 *   - serveur: config/config.hosting.php    (APP_URL publique)
 *   - FTP    : .vscode/sftp.json            (identifiants, jamais committes)
 *
 * Aucun secret n'est ecrit dans ce fichier. Les fichiers temporaires
 * (_rh_hosting_seed.php et _rh_export.json) sont supprimes du serveur a la fin.
 *
 * @package GestionDossiers\Scripts
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ce script s\'execute uniquement en ligne de commande.');
}

const PROJECT_ROOT = __DIR__ . '/..';

function out(string $m): void { fwrite(STDOUT, $m . PHP_EOL); }
function err(string $m): void { fwrite(STDERR, $m . PHP_EOL); }

/**
 * Lit une valeur de configuration dans un fichier PHP, qu'elle soit declaree
 * par define('NOM', 'valeur') ou par $NOM = 'valeur';.
 */
function read_define(string $file, string $constant): ?string
{
    if (!is_file($file)) {
        return null;
    }
    $code = (string) file_get_contents($file);

    if (preg_match("/define\\(\\s*'" . preg_quote($constant, '/') . "'\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'/", $code, $m) === 1) {
        return stripcslashes($m[1]);
    }

    if (preg_match('/\\$' . preg_quote($constant, '/') . "\\s*=\\s*'((?:[^'\\\\]|\\\\.)*)'/", $code, $m) === 1) {
        return stripcslashes($m[1]);
    }

    return null;
}

/** Exporte les donnees RH de la base locale vers un fichier JSON. */
function export_local_rh(array &$summary): array
{
    $host = read_define(PROJECT_ROOT . '/config/config.local.php', 'DB_HOST') ?? 'localhost';
    $name = read_define(PROJECT_ROOT . '/config/config.local.php', 'DB_NAME') ?? 'gestion_dossiers-new';
    $user = read_define(PROJECT_ROOT . '/config/config.local.php', 'DB_USER') ?? 'root';
    $pass = read_define(PROJECT_ROOT . '/config/config.local.php', 'DB_PASS') ?? '';

    $pdo = new PDO('mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4', $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $employees = $pdo->query('SELECT * FROM employees ORDER BY id')->fetchAll();
    $salaries = $pdo->query('SELECT * FROM salary_records ORDER BY id')->fetchAll();

    // Les identifiants locaux ne sont pas transposables : les fiches de salaire
    // sont rattachees par matricule (employee_code), reconnu par l'hebergement.
    $codeById = [];
    foreach ($employees as $e) {
        $codeById[(int) $e['id']] = (string) $e['employee_code'];
    }
    $portable = [];
    foreach ($salaries as $s) {
        $code = $codeById[(int) $s['employee_id']] ?? null;
        if ($code === null) {
            continue;
        }
        $s['employee_code'] = $code;
        unset($s['employee_id'], $s['id'], $s['updated_at']);
        $portable[] = $s;
    }

    $summary['local_db'] = $name;
    $summary['employees'] = count($employees);
    $summary['salaries'] = count($portable);

    $json = json_encode(
        ['generated_at' => date('c'), 'source_db' => $name, 'employees' => $employees, 'salaries' => $portable],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    $path = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'rh_export.json';
    if (file_put_contents($path, (string) $json) === false) {
        throw new RuntimeException('Ecriture impossible : ' . $path);
    }
    $summary['json_path'] = $path;

    return ['path' => $path, 'employees' => count($employees), 'salaries' => count($portable)];
}

/** Charge la configuration FTP (.vscode/sftp.json). */
function ftp_config(): array
{
    $json = json_decode((string) @file_get_contents(PROJECT_ROOT . '/.vscode/sftp.json'), true);
    if (!is_array($json) || empty($json['host']) || empty($json['username'])) {
        throw new RuntimeException('Configuration FTP introuvable dans .vscode/sftp.json');
    }
    return [
        'host' => (string) $json['host'],
        'port' => (int) ($json['port'] ?? 21),
        'user' => (string) $json['username'],
        'pass' => (string) ($json['password'] ?? ''),
        'path' => '/' . trim((string) ($json['remotePath'] ?? '/htdocs/'), '/') . '/',
    ];
}

/** Connexion FTP passive. */
function ftp_open(array $cfg)
{
    $conn = @ftp_connect($cfg['host'], $cfg['port'], 30);
    if ($conn === false || !@ftp_login($conn, $cfg['user'], $cfg['pass'])) {
        throw new RuntimeException('Connexion FTP impossible (' . $cfg['host'] . ').');
    }
    @ftp_pasv($conn, true);
    return $conn;
}

/** Requete HTTP GET avec resolution du defi JavaScript iFastNet/ByetHost. */
function http_get_solved(string $url, bool $verbose = false): array
{
    $request = static function (string $url, ?string $challenge): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124 Safari/537.36',
        ]);
        if ($challenge !== null) {
            curl_setopt($ch, CURLOPT_COOKIE, '__test=' . $challenge);
        }
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $body];
    };

    [$code, $body] = $request($url, null);

    // Page intermediaire iFastNet : le cookie __test est calcule en JavaScript
    // (AES-128-CBC, precedemment slowAES.decrypt(c, 2, a, b)).
    if (preg_match('/toNumbers\("([0-9a-f]+)"\),b=toNumbers\("([0-9a-f]+)"\),c=toNumbers\("([0-9a-f]+)"\)/', $body, $m) === 1) {
        $plain = openssl_decrypt(
            hex2bin($m[3]),
            'aes-128-cbc',
            hex2bin($m[1]),
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            (string) hex2bin($m[2])
        );
        if ($verbose) {
            out('  defi anti-bot resolu (cookie __test).');
        }
        [$code, $body] = $request($url, bin2hex((string) $plain));
    }

    return [$code, $body];
}

// ---------------------------------------------------------------------------
// Point d'entree
// ---------------------------------------------------------------------------
$flags = array_slice($_SERVER['argv'] ?? [], 1);
$dryRun      = in_array('--dry-run', $flags, true);
$keepRemote  = in_array('--keep-remote', $flags, true);
$doVendeurs  = !in_array('--sans-vendeurs', $flags, true);
$doSalaires  = !in_array('--sans-salaires', $flags, true);
$verbose     = in_array('--verbose', $flags, true) || in_array('-v', $flags, true);

$summary = ['local_db' => '', 'json_path' => ''];

try {
    $seedFile = PROJECT_ROOT . '/scripts/rh_hosting_seed.php';
    $token = read_define($seedFile, 'EXPECTED_TOKEN');
    if ($token === null || $token === '') {
        throw new RuntimeException('Jeton introuvable dans scripts/rh_hosting_seed.php');
    }
    $appUrl = read_define(PROJECT_ROOT . '/config/config.hosting.php', 'APP_URL');
    if ($appUrl === null || $appUrl === '') {
        throw new RuntimeException('APP_URL introuvable dans config/config.hosting.php');
    }

    out('=== Export de la base locale (XAMPP) ===');
    $export = export_local_rh($summary);
    out(sprintf('  base %s : %d employe(s), %d fiche(s) de salaire', $summary['local_db'], $export['employees'], $export['salaries']));
    out('  fichier JSON : ' . $export['path']);

    if ($dryRun) {
        out('--dry-run : aucun fichier envoye.');
        exit(0);
    }

    $cfg = ftp_config();
    $conn = ftp_open($cfg);
    $remoteSeed = $cfg['path'] . '_rh_hosting_seed.php';
    $remoteJson = $cfg['path'] . '_rh_export.json';

    out('=== Envoi vers ' . $cfg['host'] . ' ' . $cfg['path'] . ' ===');
    foreach ([[$remoteSeed, $seedFile], [$remoteJson, (string) $export['path']]] as [$remote, $local]) {
        if (!@ftp_put($conn, $remote, $local, FTP_BINARY)) {
            throw new RuntimeException('Envoi FTP impossible : ' . $remote);
        }
        out(sprintf('  up %s (%d o)', $remote, (int) filesize($local)));
    }

    $query = ['token' => $token, 'employes' => 'oui'];
    if ($doVendeurs) {
        $query['vendeurs'] = 'oui';
    }
    if ($doSalaires) {
        $query['salaires'] = 'oui';
    }
    $url = rtrim($appUrl, '/') . '/_rh_hosting_seed.php?' . http_build_query($query);

    out('=== Import sur le serveur ===');
    [$code, $body] = http_get_solved($url, $verbose);
    out('  HTTP ' . $code . ' — ' . strlen($body) . ' octets');
    out('');
    out($body);

    $imported = ($code === 200 && strpos($body, "FIN DE L'IMPORT") !== false);

    if (!$keepRemote) {
        out('=== Nettoyage des fichiers temporaires ===');
        out('  ' . ($keepRemote ? '' : '') . '_rh_hosting_seed.php : ' . (@ftp_delete($conn, $remoteSeed) ? 'supprime' : 'a supprimer manuellement'));
        out('  _rh_export.json          : ' . (@ftp_delete($conn, $remoteJson) ? 'supprime' : 'a supprimer manuellement'));
    }
    @ftp_close($conn);

    if (!$imported) {
        err('L\'import ne s\'est pas termine correctement (voir le rapport ci-dessus).');
        exit(1);
    }

    out('Import RH termine.');
    exit(0);
} catch (Throwable $e) {
    err('ERREUR : ' . $e->getMessage());
    exit(1);
}

