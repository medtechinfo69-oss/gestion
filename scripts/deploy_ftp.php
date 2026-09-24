<?php
/**
 * Deploiement FTP de l'application vers l'hebergement mutualise (ByetHost).
 *
 * PHP natif + extension ftp (aucune dependance externe, disponible sur XAMPP).
 *
 * USAGE (depuis la racine du projet) :
 *   php scripts/deploy_ftp.php check                      Teste la connexion FTP
 *   php scripts/deploy_ftp.php list [chemin_distant]      Liste un dossier distant
 *   php scripts/deploy_ftp.php upload [options]           Televerse le projet
 *
 * OPTIONS de « upload » :
 *   --dry-run          Affiche ce qui serait envoye, sans rien televerser
 *   --force            Re-televerse meme les fichiers deja a jour
 *   --only=<prefixe>   Ne traite que les chemins commencant par <prefixe>
 *                      (ex : --only=actions/  --only=config/)
 *   --data-uploads     Inclut le contenu de uploads/dossiers (donnees locales)
 *   --with-readme      Inclut readme*.txt (identifiants en clair, deconseille)
 *
 * Les identifiants FTP sont lus dans .vscode/sftp.json (fichier local ignore
 * par git), avec repli sur les variables d'environnement FTP_HOST, FTP_USER,
 * FTP_PASS, FTP_PATH et FTP_PORT.
 *
 * NE JAMAIS committer de mot de passe : ce script ne contient aucun secret.
 *
 * @package GestionDossiers\Scripts
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ce script s\'execute uniquement en ligne de commande.');
}

const PROJECT_ROOT = __DIR__ . '/..';

/** Ecrit sur la sortie standard. */
function out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

/** Ecrit sur la sortie d'erreur. */
function err(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
}

/**
 * Charge la configuration FTP depuis .vscode/sftp.json puis l'environnement.
 *
 * @return array{host:string,port:int,user:string,pass:string,path:string,timeout:int}
 */
function load_ftp_config(): array
{
    $jsonFile = PROJECT_ROOT . '/.vscode/sftp.json';
    $json = [];
    if (is_file($jsonFile)) {
        $decoded = json_decode((string) file_get_contents($jsonFile), true);
        if (is_array($decoded)) {
            $json = $decoded;
        }
    }

    $host = trim((string) (getenv('FTP_HOST') ?: ($json['host'] ?? '')));
    $user = trim((string) (getenv('FTP_USER') ?: ($json['username'] ?? '')));
    $pass = (string) (getenv('FTP_PASS') ?: ($json['password'] ?? ''));
    $path = trim((string) (getenv('FTP_PATH') ?: ($json['remotePath'] ?? '/htdocs/')));
    $port = (int) (getenv('FTP_PORT') ?: ($json['port'] ?? 21));

    if ($host === '' || $user === '' || $pass === '') {
        err('Configuration FTP introuvable.');
        err('Renseignez .vscode/sftp.json (host, username, password, remotePath)');
        err('ou les variables d\'environnement FTP_HOST / FTP_USER / FTP_PASS.');
        exit(2);
    }

    return [
        'host'    => $host,
        'port'    => $port > 0 ? $port : 21,
        'user'    => $user,
        'pass'    => $pass,
        'path'    => '/' . trim($path, '/') . '/',
        'timeout' => 30,
    ];
}

/**
 * Ouvre une connexion FTP passive (obligatoire derriere un routeur/NAT).
 *
 * @param array<string,mixed> $cfg
 * @return mixed Ressource/objet FTP
 */
function ftp_open(array $cfg)
{
    $attempts = 3;

    for ($i = 1; $i <= $attempts; $i++) {
        $conn = @ftp_connect((string) $cfg['host'], (int) $cfg['port'], (int) $cfg['timeout']);
        if ($conn === false) {
            err(sprintf('Tentative %d/%d : connexion a %s:%d impossible.', $i, $attempts, $cfg['host'], $cfg['port']));
            sleep(2);
            continue;
        }

        if (@ftp_login($conn, (string) $cfg['user'], (string) $cfg['pass'])) {
            @ftp_pasv($conn, true);
            return $conn;
        }

        err(sprintf(
            'Tentative %d/%d : authentification refusee pour %s (les serveurs mutualises limitent parfois le nombre de sessions simultanees).',
            $i,
            $attempts,
            $cfg['user']
        ));
        @ftp_close($conn);
        sleep(3);
    }

    err('Connexion FTP impossible. Verifiez les identifiants et fermez les autres sessions FTP (VS Code, FileZilla...).');
    exit(3);
}

/** Verifie l'existence d'un dossier distant sans changer le dossier courant. */
function remote_dir_exists($conn, string $path): bool
{
    $current = @ftp_pwd($conn);
    if (@ftp_chdir($conn, $path)) {
        if (is_string($current) && $current !== '') {
            @ftp_chdir($conn, $current);
        }
        return true;
    }
    return false;
}

/** Cree un dossier distant (et ses parents) si necessaire. */
function remote_mkdir($conn, string $path): bool
{
    $path = '/' . trim($path, '/');
    if ($path === '/' || remote_dir_exists($conn, $path)) {
        return true;
    }

    $parts = array_values(array_filter(explode('/', $path), static fn(string $p): bool => $p !== ''));
    $built = '';
    foreach ($parts as $part) {
        $built .= '/' . $part;
        if (!remote_dir_exists($conn, $built) && !@ftp_mkdir($conn, $built)) {
            return false;
        }
    }

    return true;
}

/** Taille d'un fichier distant, ou -1 s'il n'existe pas. */
function remote_size($conn, string $path): int
{
    $size = @ftp_size($conn, $path);
    if ($size === false && function_exists('ftp_mlsd')) {
        // Certains serveurs ne repondent pas a SIZE : repli sur MLSD.
        $dir = dirname($path);
        $base = basename($path);
        $entries = @ftp_mlsd($conn, $dir);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if (($entry['name'] ?? '') === $base && isset($entry['size'])) {
                    return (int) $entry['size'];
                }
            }
        }
    }
    return $size === false ? -1 : (int) $size;
}

/**
 * Indique si un chemin relatif (fichier ou dossier) doit etre ignore.
 *
 * @param bool $withUploads inclure les donnees locales de uploads/dossiers
 * @param bool $withReadme  inclure readme*.txt (identifiants en clair)
 */
function should_skip(string $relative, bool $withUploads, bool $withReadme): bool
{
    $rel = strtolower(str_replace('\\', '/', $relative));
    $base = basename($rel);

    // Dossiers techniques et fichiers de travail : jamais deployes.
    foreach (['.git', '.vscode', 'node_modules'] as $skip) {
        if ($rel === $skip || str_starts_with($rel, $skip . '/')) {
            return true;
        }
    }

    if (in_array($base, ['.gitignore', '.gitkeep', '.DS_Store', 'Thumbs.db'], true)) {
        return true;
    }

    // Journaux d'execution : seul le .htaccess de protection est conserve.
    if (str_starts_with($rel, 'logs/') && $base !== '.htaccess') {
        return true;
    }

    // Pieces jointes locales (donnees du poste, pas du serveur).
    if (str_starts_with($rel, 'uploads/dossiers/') && !$withUploads) {
        return !in_array($base, ['index.php', '.htaccess'], true);
    }

    // Fichier d'identifiants en clair.
    if (!$withReadme && preg_match('/^readme[0-9]*\.txt$/', $base) === 1) {
        return true;
    }

    return false;
}

/**
 * Parcourt recursivement le projet et retourne les dossiers puis les fichiers
 * a deployer, en chemin relatif (les dossiers exclus ne sont pas explores).
 *
 * @return array{dirs:list<string>,files:array<string,string>}
 */
function collect_tree(bool $withUploads, bool $withReadme, ?string $onlyPrefix): array
{
    $root = realpath(PROJECT_ROOT);
    if ($root === false) {
        err('Racine du projet introuvable.');
        exit(2);
    }

    $dirs = [];
    $files = [];
    $prefix = $onlyPrefix !== null ? strtolower(str_replace('\\', '/', trim($onlyPrefix, '/'))) : null;

    $walk = static function (string $dir, string $relative) use (&$walk, &$dirs, &$files, $root, $withUploads, $withReadme, $prefix): void {
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            $rel = $relative === '' ? $entry : $relative . '/' . $entry;

            if (should_skip($rel, $withUploads, $withReadme)) {
                continue;
            }

            if (is_dir($path)) {
                $dirs[] = $rel;
                $walk($path, $rel);
                continue;
            }

            if ($prefix !== null && !str_starts_with(strtolower($rel), $prefix)) {
                continue;
            }

            $files[$rel] = $path;
        }
    };

    $walk($root, '');
    ksort($dirs);
    ksort($files);

    return ['dirs' => array_values($dirs), 'files' => $files];
}

/** Affiche le contenu d'un dossier distant. */
function mode_list(array $cfg, string $remotePath): int
{
    $conn = ftp_open($cfg);
    out(sprintf('Connecte a %s en tant que %s — dossier courant : %s', $cfg['host'], $cfg['user'], @ftp_pwd($conn)));

    $target = $remotePath !== '' ? $remotePath : $cfg['path'];
    if (!remote_dir_exists($conn, $target)) {
        err('Dossier distant introuvable : ' . $target);
        @ftp_close($conn);
        return 1;
    }

    out('--- ' . $target . ' ---');
    $entries = @ftp_mlsd($conn, $target);
    if (is_array($entries)) {
        usort($entries, static fn(array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));
        foreach ($entries as $entry) {
            if (in_array((string) $entry['name'], ['.', '..'], true)) {
                continue;
            }
            $type = ($entry['type'] ?? '') === 'dir' ? 'DIR ' : '    ';
            $size = isset($entry['size']) ? (int) $entry['size'] : 0;
            out(sprintf('%s%-40s %10d', $type, (string) $entry['name'], $size));
        }
    } else {
        $names = @ftp_nlist($conn, $target);
        foreach ((array) $names as $name) {
            out('  ' . $name);
        }
    }

    @ftp_close($conn);
    return 0;
}

/** Teste la connexion et affiche les informations du serveur. */
function mode_check(array $cfg): int
{
    $conn = ftp_open($cfg);
    out('OK — connexion FTP et authentification reussies.');
    out('  hote          : ' . $cfg['host'] . ':' . $cfg['port']);
    out('  utilisateur   : ' . $cfg['user']);
    out('  dossier racine: ' . (string) @ftp_pwd($conn));
    out('  systeme       : ' . (string) @ftp_systype($conn));
    out('  dossier web   : ' . $cfg['path'] . ' (' . (remote_dir_exists($conn, $cfg['path']) ? 'present' : 'absent') . ')');
    @ftp_close($conn);
    return 0;
}

/**
 * Televerse l'arborescence du projet vers le serveur.
 *
 * @param array<string,mixed> $cfg
 * @param array{dryRun:bool,force:bool,withUploads:bool,withReadme:bool,only:?string,verbose:bool} $opt
 */
function mode_upload(array $cfg, array $opt): int
{
    $tree = collect_tree($opt['withUploads'], $opt['withReadme'], $opt['only']);
    $total = count($tree['files']);

    if ($total === 0) {
        err('Aucun fichier a televerser (verifiez --only).');
        return 1;
    }

    $conn = ftp_open($cfg);
    out(sprintf('FTP %s — utilisateur %s — cible %s', $cfg['host'], $cfg['user'], $cfg['path']));
    out(sprintf('%d dossier(s) et %d fichier(s) a traiter.%s', count($tree['dirs']), $total, $opt['dryRun'] ? ' (simulation)' : ''));

    if (!remote_dir_exists($conn, $cfg['path']) && !remote_mkdir($conn, $cfg['path'])) {
        err('Impossible de creer le dossier web ' . $cfg['path']);
        @ftp_close($conn);
        return 1;
    }

    $created = 0;
    foreach ($tree['dirs'] as $dir) {
        $remote = $cfg['path'] . $dir;
        if (remote_dir_exists($conn, $remote)) {
            continue;
        }
        if (remote_mkdir($conn, $remote)) {
            $created++;
            out('  mkdir ' . $dir . '/');
        } else {
            err('  ECHEC mkdir ' . $dir . '/');
        }
    }

    $uploaded = 0;
    $skipped = 0;
    $failed = [];
    $index = 0;

    foreach ($tree['files'] as $rel => $local) {
        $index++;
        $remote = $cfg['path'] . $rel;
        $localSize = (int) filesize($local);
        // L'hebergeur re-encode les images (jpg/png) a l'envoi : leur taille au
        // repos differe toujours de la taille locale. On considere donc qu'une
        // image distante existante est a jour, sauf avec --force.
        $isImage = preg_match('/\.(jpe?g|png|gif|webp|bmp|ico)$/i', $rel) === 1;

        if ($opt['dryRun']) {
            out(sprintf('  [%d/%d] %s (%d o)', $index, $total, $rel, $localSize));
            continue;
        }

        $remoteSize = remote_size($conn, $remote);

        // Fichier deja present avec la bonne taille : rien a faire.
        if (!$opt['force'] && $remoteSize > 0 && ($remoteSize === $localSize || $isImage)) {
            $skipped++;
            if ($opt['verbose']) {
                out(sprintf('  [%d/%d] = %s', $index, $total, $rel));
            }
            continue;
        }

        $parent = dirname($remote);
        if (!remote_dir_exists($conn, $parent)) {
            remote_mkdir($conn, $parent);
        }

        $ok = (bool) @ftp_put($conn, $remote, $local, FTP_BINARY);
        if ($ok && $isImage) {
            // Verification par simple presence : la taille ne peut pas correspondre.
            $ok = remote_size($conn, $remote) > 0;
        } elseif ($ok) {
            $remoteActual = remote_size($conn, $remote);
            if ($remoteActual !== -1 && $remoteActual !== $localSize) {
                err(sprintf('  taille incorrecte pour %s (%d o attendus, %d recus) : nouvel essai', $rel, $localSize, $remoteActual));
                $ok = (bool) @ftp_put($conn, $remote, $local, FTP_BINARY);
            }
        }

        if ($ok) {
            $uploaded++;
            out(sprintf('  [%d/%d] up %s (%d o)', $index, $total, $rel, $localSize));
        } else {
            $failed[] = $rel;
            err(sprintf('  [%d/%d] ECHEC %s', $index, $total, $rel));
        }
    }

    @ftp_close($conn);

    out('-------------------------------------------------------------');
    out(sprintf('Dossiers crees   : %d', $created));
    out(sprintf('Fichiers envoyes : %d', $uploaded));
    out(sprintf('Deja a jour      : %d', $skipped));
    out(sprintf('Echecs           : %d', count($failed)));
    if ($failed !== []) {
        foreach ($failed as $rel) {
            err('  - ' . $rel);
        }
        return 1;
    }

    out('Deploiement termine.');
    return 0;
}

// ---------------------------------------------------------------------------
// Point d'entree
// ---------------------------------------------------------------------------
$argv = $_SERVER['argv'] ?? [];
$command = $argv[1] ?? 'upload';
$flags = array_slice($argv, 2);

$only = null;
$verbose = false;
$unknown = [];
foreach ($flags as $flag) {
    if (in_array($flag, ['--dry-run', '--force', '--data-uploads', '--with-readme'], true)) {
        continue;
    }
    if ($flag === '--verbose' || $flag === '-v') {
        $verbose = true;
        continue;
    }
    if (str_starts_with($flag, '--only=')) {
        $only = substr($flag, 7);
        continue;
    }
    $unknown[] = $flag;
}

if ($unknown !== [] && $command !== 'list') {
    err('Option inconnue : ' . implode(' ', $unknown));
    exit(2);
}

$config = load_ftp_config();

switch ($command) {
    case 'check':
        exit(mode_check($config));

    case 'list':
        exit(mode_list($config, (string) ($argv[2] ?? '')));

    case 'upload':
        exit(mode_upload($config, [
            'dryRun'      => in_array('--dry-run', $flags, true),
            'force'       => in_array('--force', $flags, true),
            'withUploads' => in_array('--data-uploads', $flags, true),
            'withReadme'  => in_array('--with-readme', $flags, true),
            'only'        => $only,
            'verbose'     => $verbose,
        ]));

    default:
        err('Commande inconnue : ' . $command);
        err('Commandes disponibles : check, list [chemin], upload [options]');
        exit(2);
}
