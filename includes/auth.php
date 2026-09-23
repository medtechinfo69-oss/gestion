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

/**
 * Vrai pour un compte dont le rôle est « vendeur ». Distinct de
 * is_superviseur() : un vendeur « can_supervise » agit comme un superviseur
 * tout en conservant son rôle.
 */
function is_vendeur_user(): bool
{
    return is_logged_in() && ($_SESSION['user']['role'] ?? '') === 'vendeur';
}

/** Vrai pour un superviseur « strict » (rôle = superviseur), hors vendeurs. */
function is_role_superviseur(): bool
{
    return is_logged_in() && ($_SESSION['user']['role'] ?? '') === 'superviseur';
}

/**
 * Vrai pour tout compte habilité à travailler comme un superviseur :
 *   - les superviseurs eux-mêmes ;
 *   - les VENDEURS dont le drapeau « can_supervise » est actif (rôle conservé
 *     « vendeur » mais mêmes accès : dashboard, dossiers, import, notifications,
 *     approbation IP).
 *
 * C'est LE prédicat qui pilote les autorisations dans toute l'application :
 * chaque endroit qui testait « is_superviseur() » pour autoriser une action
 * bénéficie donc automatiquement aux vendeurs connectables, sans divergence
 * de comportement entre les deux profils.
 */
function is_superviseur(): bool
{
    if (!is_logged_in()) {
        return false;
    }
    $role = $_SESSION['user']['role'] ?? '';
    if ($role === 'superviseur') {
        return true;
    }
    return $role === 'vendeur' && !empty($_SESSION['user']['can_supervise']);
}

/** Alias sémantique de is_superviseur() (profil superviseur ou vendeur habilité). */
function is_supervisor_like(): bool
{
    return is_superviseur();
}

/**
 * Vrai pour tout compte soumis à l'approbation d'adresse IP à la connexion :
 * superviseurs et vendeurs « superviseur-like ». Le drapeau est enregistré en
 * session à l'authentification (voir attempt_login).
 */
function requires_ip_approval(): bool
{
    return is_logged_in() && !empty($_SESSION['user']['requires_ip_approval']);
}

function can_access_dossiers(): bool
{
    return is_admin() || is_supervisor_like();
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

    if (!in_array($_SESSION['user']['role'] ?? '', ['admin', 'superviseur', 'vendeur'], true)) {
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
    if (!is_admin() && !is_supervisor_like()) {
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
    $ip = get_client_ip();

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

    // Un compte inactif est refusé. Les VENDEURS sont désormais autorisés à se
    // connecter : ceux portant le drapeau « can_supervise » disposent des mêmes
    // accès que les superviseurs (dashboard, dossiers, import, notifications).
    if (!$user['is_active']) {
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

    // Approbation d'adresse IP : s'applique aux superviseurs ET aux vendeurs
    // « superviseur-like » (can_supervise = 1). Une IP inconnue crée une demande
    // en attente qu'un administrateur doit approuver depuis la page dédiée.
    $canSupervise = ($user['role'] === 'vendeur' && !empty($user['can_supervise']));
    $requiresIpApproval = ($user['role'] === 'superviseur' || $canSupervise);

    if ($requiresIpApproval) {
        try {
            $stmt = $db->prepare('SELECT id FROM superviseur_approved_ips WHERE user_id = :uid AND ip_address = :ip LIMIT 1');
            $stmt->execute(['uid' => $user['id'], 'ip' => $ip]);
            $approved = (bool) $stmt->fetchColumn();
        } catch (PDOException $e) {
            // Table absente sur l'hébergement : on tente de la créer, sinon on refuse la connexion proprement.
            if (!superviseur_tables_ensure($db)) {
                $logIp(false);
                return ['success' => false, 'message' => 'Module de supervision indisponible. Contactez un administrateur.'];
            }
            $approved = false;
        }

        if (!$approved) {
            try {
                $stmt = $db->prepare('INSERT INTO superviseur_sessions (user_id, username, ip_address, user_agent, status) VALUES (:uid, :uname, :ip, :ua, \'pending\')');
                $stmt->execute([
                    'uid' => $user['id'],
                    'uname' => $user['username'],
                    'ip' => $ip,
                    'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                ]);
            } catch (PDOException $e) {
                superviseur_tables_ensure($db);
            }

            $logIp(false);
            return ['success' => false, 'message' => 'Cette session doit être approuvée par un administrateur avant connexion.'];
        }
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
        // Drapeaux d'habilitations : un vendeur « can_supervise » conserve son
        // rôle mais agit comme un superviseur (cf. is_supervisor_like()).
        'can_supervise'         => $canSupervise,
        'requires_ip_approval'  => $requiresIpApproval,
    ];

    // Empreinte de session (détournement de session)
    $_SESSION['session_fingerprint'] = compute_session_fingerprint();

    if ($requiresIpApproval) {
        try {
            $updIp = $db->prepare('UPDATE superviseur_approved_ips SET last_used_at = NOW() WHERE user_id = :uid AND ip_address = :ip');
            $updIp->execute(['uid' => $user['id'], 'ip' => $ip]);
        } catch (Throwable $e) {
            // table may not exist yet
        }
    }

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

function is_superviseur_ip_approved(PDO $db, int $userId, string $ip): bool
{
    try {
        $stmt = $db->prepare('SELECT COUNT(*) FROM superviseur_approved_ips WHERE user_id = :uid AND ip_address = :ip');
        $stmt->execute(['uid' => $userId, 'ip' => $ip]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return true;
    }
}

function approve_superviseur_ip(PDO $db, int $userId, string $ip, string $userAgent, ?int $approvedByUserId, ?string $label = null): bool
{
    try {
        $stmt = $db->prepare('INSERT INTO superviseur_approved_ips (user_id, ip_address, user_agent, label, approved_by, approved_at) VALUES (:uid, :ip, :ua, :label, :by, NOW()) ON DUPLICATE KEY UPDATE approved_at = NOW(), label = VALUES(label)');
        return $stmt->execute([
            'uid' => $userId,
            'ip' => $ip,
            'ua' => substr($userAgent, 0, 500),
            'label' => $label,
            'by' => $approvedByUserId,
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

function deny_superviseur_session(PDO $db, int $sessionId, ?int $adminUserId, ?string $reason = null): bool
{
    try {
        $stmt = $db->prepare('UPDATE superviseur_sessions SET status = \'denied\', approved_by = :by, denial_reason = :reason, approved_at = NOW() WHERE id = :sid AND status = \'pending\'');
        return $stmt->execute(['by' => $adminUserId, 'reason' => $reason, 'sid' => $sessionId]);
    } catch (Throwable $e) {
        return false;
    }
}

function get_superviseur_pending_sessions(PDO $db): array
{
    try {
        $stmt = $db->query('SELECT s.*, u.email FROM superviseur_sessions s JOIN users u ON u.id = s.user_id WHERE s.status = \'pending\' ORDER BY s.created_at DESC');
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function get_superviseur_approved_ips(PDO $db, int $userId): array
{
    try {
        $stmt = $db->prepare('SELECT * FROM superviseur_approved_ips WHERE user_id = :uid ORDER BY approved_at DESC');
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
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

/**
 * Crée les tables de supervision si elles n'existent pas (utile sur hébergement
 * où database/install.sql n'a pas encore été importé). Retourne true si OK.
 */
function superviseur_tables_ensure(PDO $db): bool
{
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS superviseur_approved_ips (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            label VARCHAR(100) NULL,
            approved_by_user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NULL,
            UNIQUE KEY uq_user_ip (user_id, ip_address)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS superviseur_sessions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            username VARCHAR(100) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            user_agent VARCHAR(500) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            decided_by INT UNSIGNED NULL,
            decided_at DATETIME NULL,
            reason VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Les tables existent déjà (CREATE IF NOT EXISTS inopérant) : on s'assure
        // que leur structure est correcte (clé primaire, auto-increment, colonnes).
        superviseur_schema_repair($db);
        return true;
    } catch (Throwable $e) {
        error_log('superviseur_tables_ensure: ' . $e->getMessage());
        return false;
    }
}

/**
 * Répare le schéma des tables de supervision.
 *
 * Sur certaines bases, `superviseur_sessions` / `superviseur_approved_ips`
 * ont perdu leur PRIMARY KEY et AUTO_INCREMENT : les nouvelles lignes
 * s'insèrent alors avec id = 0 et l'approbation d'une session échoue avec
 * « Demande invalide. » (session_id = 0). Cette fonction restaure la clé
 * primaire, l'auto-increment, déduplique les lignes et aligne les colonnes
 * des deux variantes de schéma (approved_* / decided_*). Idempotente.
 */
function superviseur_schema_repair(PDO $db): bool
{
    static $done = false;
    if ($done) {
        return true;
    }
    $done = true;

    try {
        foreach (['superviseur_sessions', 'superviseur_approved_ips'] as $table) {
            if (!$db->query('SHOW TABLES LIKE ' . $db->quote($table))->fetchColumn()) {
                continue;
            }

            $cols = $db->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            if (!$cols) {
                continue;
            }
            $names = array_column($cols, 'Field');

            // --- Alignement des colonnes entre les deux variantes de schéma ---
            if ($table === 'superviseur_sessions') {
                // Variante ancienne : approved_by / approved_at / denial_reason
                if (!in_array('approved_by', $names, true)) {
                    $db->exec("ALTER TABLE superviseur_sessions ADD COLUMN approved_by INT UNSIGNED NULL");
                }
                if (!in_array('approved_at', $names, true)) {
                    $db->exec("ALTER TABLE superviseur_sessions ADD COLUMN approved_at DATETIME NULL");
                }
                if (!in_array('denial_reason', $names, true)) {
                    $db->exec("ALTER TABLE superviseur_sessions ADD COLUMN denial_reason VARCHAR(255) NULL");
                }
                // Variante récente : decided_by / decided_at / reason
                if (!in_array('decided_by', $names, true)) {
                    $db->exec("ALTER TABLE superviseur_sessions ADD COLUMN decided_by INT UNSIGNED NULL");
                }
                if (!in_array('decided_at', $names, true)) {
                    $db->exec("ALTER TABLE superviseur_sessions ADD COLUMN decided_at DATETIME NULL");
                }
                if (!in_array('reason', $names, true)) {
                    $db->exec("ALTER TABLE superviseur_sessions ADD COLUMN reason VARCHAR(255) NULL");
                }
            } else {
                // superviseur_approved_ips
                if (!in_array('label', $names, true)) {
                    $db->exec("ALTER TABLE superviseur_approved_ips ADD COLUMN label VARCHAR(150) NULL");
                }
                if (!in_array('user_agent', $names, true)) {
                    $db->exec("ALTER TABLE superviseur_approved_ips ADD COLUMN user_agent VARCHAR(500) NOT NULL DEFAULT ''");
                }
                if (!in_array('approved_by', $names, true)) {
                    $db->exec("ALTER TABLE superviseur_approved_ips ADD COLUMN approved_by INT UNSIGNED NULL");
                }
                if (!in_array('approved_at', $names, true)) {
                    $db->exec("ALTER TABLE superviseur_approved_ips ADD COLUMN approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
                }
                if (!in_array('approved_by_user_id', $names, true)) {
                    $db->exec("ALTER TABLE superviseur_approved_ips ADD COLUMN approved_by_user_id INT UNSIGNED NULL");
                }
                if (!in_array('created_at', $names, true)) {
                    $db->exec("ALTER TABLE superviseur_approved_ips ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
                }
                if (!in_array('last_used_at', $names, true)) {
                    $db->exec("ALTER TABLE superviseur_approved_ips ADD COLUMN last_used_at DATETIME NULL");
                }
            }

            // --- Restauration de la clé primaire et de l'auto-increment ---
            $indexes = $db->query("SHOW INDEX FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            $hasPrimary = false;
            foreach ($indexes as $idx) {
                if (($idx['Key_name'] ?? '') === 'PRIMARY') {
                    $hasPrimary = true;
                    break;
                }
            }

            $idIsAuto = false;
            $idType = 'bigint(20) unsigned';
            foreach ($cols as $c) {
                if (($c['Field'] ?? '') === 'id') {
                    $idType = (string) ($c['Type'] ?? $idType);
                    $idIsAuto = stripos((string) ($c['Extra'] ?? ''), 'auto_increment') !== false;
                    break;
                }
            }

            if ($hasPrimary && $idIsAuto) {
                continue; // schéma déjà sain
            }

            // Déduplique les id avant de poser la clé primaire.
            $dups = $db->query("SELECT id, COUNT(*) c FROM `$table` GROUP BY id HAVING c > 1")->fetchAll(PDO::FETCH_ASSOC);
            if ($dups) {
                $del = $db->prepare("DELETE FROM `$table` WHERE id = :id LIMIT 1");
                foreach ($dups as $d) {
                    for ($i = 1; $i < (int) $d['c']; $i++) {
                        $del->execute(['id' => (int) $d['id']]);
                    }
                }
            }

            // Renumérote les lignes dont l'id est nul ou négatif.
            if ((int) $db->query("SELECT COUNT(*) FROM `$table` WHERE id <= 0")->fetchColumn() > 0) {
                $nextId = (int) $db->query("SELECT COALESCE(MAX(id), 0) FROM `$table`")->fetchColumn() + 1;
                $rows = $db->query("SELECT id FROM `$table` WHERE id <= 0")->fetchAll(PDO::FETCH_ASSOC);
                $upd = $db->prepare("UPDATE `$table` SET id = :new WHERE id = :old LIMIT 1");
                foreach ($rows as $r) {
                    $upd->execute(['new' => $nextId, 'old' => (int) $r['id']]);
                    $nextId++;
                }
            }

            if ($hasPrimary) {
                $db->exec("ALTER TABLE `$table` DROP PRIMARY KEY");
            }
            // MySQL impose que la colonne AUTO_INCREMENT soit clé dans la même instruction.
            $db->exec("ALTER TABLE `$table` MODIFY COLUMN id $idType NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)");
        }

        return true;
    } catch (Throwable $e) {
        error_log('superviseur_schema_repair: ' . $e->getMessage());
        return false;
    }
}
