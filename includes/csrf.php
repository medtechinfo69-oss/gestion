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
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
        http_response_code(403);
        set_flash('error', 'Jeton de sécurité invalide ou expiré. Veuillez réessayer.');
        $back = $_SERVER['HTTP_REFERER'] ?? (APP_URL . '/dashboard.php');
        header('Location: ' . $back);
        exit;
    }
}
