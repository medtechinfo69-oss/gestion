<?php
/**
 * Authentification, gestion de session sécurisée et contrôle d'accès
 * par rôle (admin / vendeur).
 */

if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Accès direct interdit.');
}

/**
 * Démarre une session sécurisée. À appeler une seule fois, avant tout
 * envoi de sortie HTML.
 */
function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');

    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,   // cookie envoyé uniquement en HTTPS si dispo
        'httponly' => true,       // inaccessible en JavaScript
        'samesite' => 'Lax',      // limite les attaques CSRF cross-site
    ]);

    session_name('gdsid'); // nom de cookie neutre (n'expose pas la techno)
    session_start();

    // Expiration par inactivité
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
        session_unset();
        session_destroy();
        session_start();
    }
    $_SESSION['last_activity'] = time();

    // Régénération périodique de l'identifiant de session (anti fixation)
    if (empty($_SESSION['created_at'])) {
        $_SESSION['created_at'] = time();
    } elseif (time() - $_SESSION['created_at'] > 900) {
        session_regenerate_id(true);
        $_SESSION['created_at'] = time();
    }
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

function is_admin(): bool
{
    return is_logged_in() && $_SESSION['user']['role'] === 'admin';
}

function is_superviseur(): bool
{
    return is_logged_in() && $_SESSION['user']['role'] === 'superviseur';
}

function can_access_dossiers(): bool
{
    return is_admin() || is_superviseur();
}

function require_dossier_access(): void
{
    require_login();
    if (!can_access_dossiers()) {
        http_response_code(403);
        set_flash('error', 'Accès réservé aux administrateurs et superviseurs.');
        header('Location: ' . APP_URL . '/dashboard.php');
        exit;
    }
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }

    if (!in_array($_SESSION['user']['role'] ?? '', ['admin', 'superviseur'], true)) {
        logout_user();
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }

    // Force le changement de mot de passe avant tout accès au reste de l'application
    $user = current_user();
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $exempt = in_array($script, ['profile.php', 'logout.php', 'profile_update.php'], true);
    if (!empty($user['must_change_password']) && !$exempt) {
        header('Location: ' . APP_URL . '/profile.php?force=1');
        exit;
    }
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        set_flash('error', 'Accès réservé aux administrateurs.');
        header('Location: ' . APP_URL . '/dashboard.php');
        exit;
    }
}

/**
 * Authentifie un utilisateur. Gère le verrouillage temporaire après
 * plusieurs échecs (protection brute force) et journalise la tentative.
 */
function attempt_login(PDO $db, string $username, string $password): array
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $stmt = $db->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
    $stmt->execute(['u' => $username]);
    $user = $stmt->fetch();

    $logStmt = $db->prepare(
        'INSERT INTO login_log (username, ip_address, success) VALUES (:u, :ip, :s)'
    );

    if (!$user) {
        $logStmt->execute(['u' => $username, 'ip' => $ip, 's' => 0]);
        // Réponse volontairement identique en cas d'identifiant inconnu
        return ['success' => false, 'message' => 'Identifiants incorrects.'];
    }

    if (!$user['is_active'] || $user['role'] === 'vendeur') {
        $logStmt->execute(['u' => $username, 'ip' => $ip, 's' => 0]);
        return ['success' => false, 'message' => 'Ce compte a été désactivé. Contactez un administrateur.'];
    }

    if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
        $remaining = (int) ceil((strtotime($user['locked_until']) - time()) / 60);
        $logStmt->execute(['u' => $username, 'ip' => $ip, 's' => 0]);
        return ['success' => false, 'message' => "Compte temporairement verrouillé. Réessayez dans {$remaining} min."];
    }

    if (!password_verify($password, $user['password_hash'])) {
        $attempts = (int) $user['failed_attempts'] + 1;
        $lockedUntil = null;
        if ($attempts >= MAX_LOGIN_ATTEMPTS) {
            $lockedUntil = date('Y-m-d H:i:s', time() + LOGIN_LOCKOUT_TIME);
            $attempts = 0;
        }
        $upd = $db->prepare('UPDATE users SET failed_attempts = :a, locked_until = :l WHERE id = :id');
        $upd->execute(['a' => $attempts, 'l' => $lockedUntil, 'id' => $user['id']]);

        $logStmt->execute(['u' => $username, 'ip' => $ip, 's' => 0]);
        return ['success' => false, 'message' => 'Identifiants incorrects.'];
    }

    // Succès : réinitialise le compteur d'échecs et enregistre la connexion
    $upd = $db->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = :id');
    $upd->execute(['id' => $user['id']]);

    $logStmt->execute(['u' => $username, 'ip' => $ip, 's' => 1]);

    // Anti fixation de session : nouvel identifiant après authentification
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id'                    => (int) $user['id'],
        'username'              => $user['username'],
        'role'                  => $user['role'],
        'nom_complet'           => $user['nom_complet'],
        'must_change_password'  => (bool) $user['must_change_password'],
    ];

    return ['success' => true, 'user' => $_SESSION['user']];
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}
