<?php
/**
 * Protection CSRF (Cross-Site Request Forgery).
 * Un jeton unique par session est généré et doit accompagner
 * chaque formulaire POST. Toute requête POST sans jeton valide
 * est rejetée.
 */

if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Accès direct interdit.');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_verify(): bool
{
    $sent = $_POST['csrf_token'] ?? '';
    $stored = $_SESSION['csrf_token'] ?? '';

    if ($sent === '' || $stored === '') {
        return false;
    }

    return hash_equals($stored, $sent);
}

/**
 * À appeler en tête de chaque script de traitement POST.
 * Interrompt l'exécution avec un message clair si le jeton est invalide.
 */
function csrf_require(): void
{
    // $db est posée par includes/init.php dans la portée globale : sans ce
    // « global », la variable était indéfinie dans la portée locale d'une
    // fonction, donc max_import_size_bytes() recevait null alors qu'elle exige
    // un PDO => TypeError, page blanche HTTP 500 AU LIEU du message « fichier
    // trop volumineux » (sur une requête multipart dépassant post_max_size).
    global $db;

    // PHP vide $_POST quand une requête multipart dépasse post_max_size.
    // Dans ce cas, on distingue le dépassement de taille d'un vrai jeton invalide.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST === []
        && isset($_SERVER['CONTENT_LENGTH'])
        && (int) $_SERVER['CONTENT_LENGTH'] > 0) {
        http_response_code(413);
        // Repli défensif : si $db n'est pas disponible (script qui appellerait
        // csrf_require() sans init.php), on retombe sur la constante pour ne
        // jamais transformer ce message d'erreur en erreur fatale.
        $maxBytes = ($db instanceof PDO)
            ? max_import_size_bytes($db)
            : (defined('IMPORT_MAX_SIZE') ? (int) IMPORT_MAX_SIZE : 30 * 1024 * 1024);
        $configuredMaxUploadMb = $maxBytes / 1024 / 1024;
        set_flash('error', 'Le fichier envoyé dépasse la taille maximale autorisée par les paramètres (' . (int) $configuredMaxUploadMb . ' Mo).');
        $back = $_SERVER['HTTP_REFERER'] ?? (APP_URL . '/dashboard.php');
        header('Location: ' . $back);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
        http_response_code(403);
        set_flash('error', 'Jeton de sécurité invalide ou expiré. Veuillez réessayer.');
        $back = $_SERVER['HTTP_REFERER'] ?? (APP_URL . '/dashboard.php');
        header('Location: ' . $back);
        exit;
    }
}
