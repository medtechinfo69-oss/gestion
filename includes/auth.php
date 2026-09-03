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

    $sessionLifetime = defined('SESSION_LIFETIME') ? (int) SESSION_LIFETIME : 7200;
    if (isset($_SESSION['user_preferences']['session_lifetime'])) {
        $sessionLifetime = max(900, min(21600, (int) $_SESSION['user_preferences']['session_lifetime']));
    }

    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_name('gdsid');
    session_start();

    // Vérification de l'empreinte de session (anti fixation / détournement)
    // La session est invalidée uniquement si l'empreinte EXISTE et diffère ;
    // les anciennes sessions sans empreinte sont mises à niveau sans coupure.
    if (isset($_SESSION['user']) && isset($_SESSION['session_fingerprint'])) {
        if (!session_fingerprint_matches()) {
            $_SESSION = [];
            session_destroy();
            session_start();
        }
    }
    if (isset($_SESSION['user']) && empty($_SESSION['session_fingerprint'])) {
        $_SESSION['session_fingerprint'] = compute_session_fingerprint();
    }

    $activeLimit = isset($_SESSION['user_preferences']['session_lifetime'])
        ? max(900, min(21600, (int) $_SESSION['user_preferences']['session_lifetime']))
        : $sessionLifetime;

    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $activeLimit) {
        session_unset();
        session_destroy();
        session_start();
    }
    $_SESSION['last_activity'] = time();

    if (empty($_SESSION['created_at'])) {
        $_SESSION['created_at'] = time();
    } elseif (time() - $_SESSION['created_at'] > 900) {
        session_regenerate_id(true);
        $_SESSION['created_at'] = time();
    }
}

/**
 * Calcul de l'empreinte de session : basée sur le navigateur.
 * N'utilise PAS l'adresse IP (compatible réseaux mobiles / IP dynamique).
 */
function compute_session_fingerprint(): string
{
    $secret = defined('APP_SECRET_KEY') ? APP_SECRET_KEY : 'gestion-dossiers';
    $input = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        . '|'
        . substr((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 0, 50)
        . '|'
        . (defined('APP_URL') ? APP_URL : '');
    return hash_hmac('sha256', $input, $secret);
}

/** Compare l'empreinte courante à celle stockée en session. */
function session_fingerprint_matches(): bool
{
    $stored = (string) ($_SESSION['session_fingerprint'] ?? '');
    if ($stored === '') {
        return true;
    }
    return hash_equals($stored, compute_session_fingerprint());
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function default_user_preferences(): array
{
    return [
        'items_per_page' => (int) (ITEMS_PER_PAGE ?? 25),
        'session_lifetime' => (int) (SESSION_LIFETIME ?? 7200),
        'compact_mode' => 0,
    ];
}

function user_preferences(): array
{
    if (!isset($_SESSION['user_preferences'])) {
        $_SESSION['user_preferences'] = default_user_preferences();
    }

    return array_merge(default_user_preferences(), $_SESSION['user_preferences']);
}

function set_user_preference(string $key, mixed $value): void
{
    $prefs = user_preferences();
    $prefs[$key] = $value;
    $_SESSION['user_preferences'] = $prefs;
}

function get_items_per_page(): int
{
    $value = (int) (user_preferences()['items_per_page'] ?? ITEMS_PER_PAGE);
    return max(10, min(100, $value));
}

function get_session_lifetime(): int
{
    $value = (int) (user_preferences()['session_lifetime'] ?? SESSION_LIFETIME);
    return max(900, min(21600, $value));
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

function require_admin_or_superviseur(): void
{
    require_login();
    if (!is_admin() && !is_superviseur()) {
        http_response_code(403);
        set_flash('error', 'Accès réservé aux administrateurs et superviseurs.');
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
        'INSERT INTO login_log (username, ip_address, ip_safe, success) VALUES (:u, :ip, :ip_safe, :s)'
    );
    $knownIp = is_known_ip($db, $ip);
    $logIp = function (bool $success) use ($logStmt, $username, $ip, $knownIp): void {
        $logStmt->execute([
            'u' => $username,
            'ip' => $ip,
            'ip_safe' => $knownIp ? 1 : 0,
            's' => $success ? 1 : 0,
        ]);
    };

    if (!$user) {
        $logIp(false);
        // Réponse volontairement identique en cas d'identifiant inconnu
        return ['success' => false, 'message' => 'Identifiants incorrects.'];
    }

    if (!$user['is_active'] || $user['role'] === 'vendeur') {
        $logIp(false);
        return ['success' => false, 'message' => 'Ce compte a été désactivé. Contactez un administrateur.'];
    }

    if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
        $remaining = (int) ceil((strtotime($user['locked_until']) - time()) / 60);
        $logIp(false);
        return ['success' => false, 'message' => "Compte temporairement verrouillé. Réessayez dans {$remaining} min."];
    }

    if (!password_verify($password, $user['password_hash'])) {
        $attempts = (int) $user['failed_attempts'] + 1;
        $lockedUntil = null;
        if ($attempts >= MAX_LOGIN_ATTEMPTS) {
            $lockedUntil = date('Y-m-d H:i:s', time() + LOGIN_LOCKOUT_TIME);
            $attempts = 0;
            log_security_event('ACCOUNT_LOCK', "Verrouillage du compte {$username} après " . MAX_LOGIN_ATTEMPTS . " échecs");
        }
        $upd = $db->prepare('UPDATE users SET failed_attempts = :a, locked_until = :l WHERE id = :id');
        $upd->execute(['a' => $attempts, 'l' => $lockedUntil, 'id' => $user['id']]);

        $logIp(false);
        return ['success' => false, 'message' => 'Identifiants incorrects.'];
    }

    // Mot de passe correct mais peut-être expiré (politique d'expiration)
    $passwordExpired = password_is_expired(
        $db,
        (int) $user['id'],
        defined('PASSWORD_MAX_AGE_DAYS') ? (int) PASSWORD_MAX_AGE_DAYS : 90
    );

    // Succès : réinitialise le compteur d'échecs et enregistre la connexion
    $upd = $db->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = :id');
    $upd->execute(['id' => $user['id']]);

    $logIp(true);

    // Anti fixation de session : nouvel identifiant après authentification
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id'                    => (int) $user['id'],
        'username'              => $user['username'],
        'role'                  => $user['role'],
        'nom_complet'           => $user['nom_complet'],
        'must_change_password'  => (bool) $user['must_change_password'],
    ];

    // Empreinte de session (détournement de session)
    $_SESSION['session_fingerprint'] = compute_session_fingerprint();

    return ['success' => true, 'user' => $_SESSION['user'], 'password_expired' => $passwordExpired];
}

/**
 * Vérifie si une adresse IP figure déjà dans les connexions réussies.
 * Sert de base de confiance pour détecter une activité inhabituelle.
 */
function is_known_ip(PDO $db, string $ip): bool
{
    try {
        $stmt = $db->prepare('SELECT COUNT(*) FROM login_log WHERE ip_address = :ip AND success = 1');
        $stmt->execute(['ip' => $ip]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return true;
    }
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
