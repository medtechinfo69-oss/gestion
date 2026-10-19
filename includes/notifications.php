<?php
/**
 * Notifications administrateur — journal anti-manipulation.
 *
 * Chaque action effectuée par un SUPERVISEUR (création / modification /
 * suppression / import / pièce jointe / RH / salaires) est enregistrée ici
 * dans une table INSERT-ONLY : aucune fonction UPDATE ni DELETE n'existe
 * dans ce module. La page dédiée réserve à l'administrateur les suppressions
 * unitaires et groupées ; un superviseur ne peut donc ni masquer ni altérer
 * la trace de ses propres actions.
 *
 * La table est créée automatiquement (CREATE TABLE IF NOT EXISTS) pour
 * fonctionner aussi sur l'hébergement sans import SQL manuel.
 */

if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Accès direct interdit.');
}

/**
 * Cree la table des notifications + colonnes d'audience (ciblages).
 *
 * Extension « vendeur <-> superviseur » SANS casser l'existant :
 *   - target_role     : 'admin' (défaut, ancien comportement), 'superviseur', 'vendeur'
 *   - target_user_id  : NULL = tout le rôle ; sinon un seul utilisateur
 *   - dossier_id      : rattachement au dossier concerné (filtre vendeur)
 *   - vendeur_id      : vendeur propriétaire du dossier (filtre vendeur)
 *
 * La verification (CREATE TABLE IF NOT EXISTS + SHOW COLUMNS) n'est faite
 * qu'UNE FOIS par requete : sans cette memorisation, chaque appel repetait
 * deux requetes MySQL, alors que la page notifications.php en declenche
 * cinq (historique, compteur, cloche, liste, marquage).
 */
function admin_notifications_ensure(PDO $db): bool
{
    static $checked = false;
    if ($checked) {
        return true;
    }

    try {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS admin_notifications (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                actor_id INT UNSIGNED NULL,
                actor_username VARCHAR(60) NOT NULL DEFAULT '',
                actor_role VARCHAR(20) NOT NULL DEFAULT 'superviseur',
                action VARCHAR(30) NOT NULL,
                entity VARCHAR(30) NOT NULL,
                entity_id VARCHAR(60) NULL,
                title VARCHAR(255) NOT NULL,
                detail TEXT NULL,
                ip_address VARCHAR(45) NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                read_by INT UNSIGNED NULL,
                read_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_notif_unread (is_read, created_at),
                INDEX idx_notif_actor (actor_id, created_at),
                INDEX idx_notif_entity (entity, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Migration douce : ajoute les colonnes d'audience si absentes
        // (bases existantes créées avant cette extension).
        $cols = $db->query('SHOW COLUMNS FROM admin_notifications')->fetchAll(PDO::FETCH_ASSOC);
        $names = [];
        foreach ($cols as $c) {
            $names[] = (string) ($c['Field'] ?? '');
        }
        if (!in_array('target_role', $names, true)) {
            $db->exec("ALTER TABLE admin_notifications ADD COLUMN target_role VARCHAR(20) NOT NULL DEFAULT 'admin' AFTER actor_role");
        }
        if (!in_array('target_user_id', $names, true)) {
            $db->exec('ALTER TABLE admin_notifications ADD COLUMN target_user_id INT UNSIGNED NULL AFTER target_role');
        }
        if (!in_array('dossier_id', $names, true)) {
            $db->exec('ALTER TABLE admin_notifications ADD COLUMN dossier_id INT UNSIGNED NULL AFTER entity_id');
        }
        if (!in_array('vendeur_id', $names, true)) {
            $db->exec('ALTER TABLE admin_notifications ADD COLUMN vendeur_id INT UNSIGNED NULL AFTER dossier_id');
        }
        // Index de lecture par audience (cloche + page) : 1 seule requête.
        try {
            $idx = $db->query('SHOW INDEX FROM admin_notifications')->fetchAll(PDO::FETCH_ASSOC);
            $hasAudience = false;
            foreach ($idx as $i) {
                if (($i['Key_name'] ?? '') === 'idx_notif_audience') {
                    $hasAudience = true;
                    break;
                }
            }
            if (!$hasAudience) {
                $db->exec('ALTER TABLE admin_notifications ADD INDEX idx_notif_audience (target_role, target_user_id, is_read, created_at)');
            }
        } catch (Throwable $e) {
            // index optionnel : ne bloque jamais
        }

        // Auto-réparation : certaines bases ont perdu AUTO_INCREMENT sur id.
        // Sans cela, toute notification s'insère avec id = 0 (doublon / échec).
        $colsId = $db->query('SHOW COLUMNS FROM admin_notifications')->fetchAll(PDO::FETCH_ASSOC);
        $idType = 'bigint(20) unsigned';
        $idIsAuto = false;
        foreach ($colsId as $c) {
            if (($c['Field'] ?? '') === 'id') {
                $idType = (string) ($c['Type'] ?? $idType);
                $idIsAuto = stripos((string) ($c['Extra'] ?? ''), 'auto_increment') !== false;
                break;
            }
        }
        if (!$idIsAuto) {
            if ((int) $db->query('SELECT COUNT(*) FROM admin_notifications WHERE id <= 0')->fetchColumn() > 0) {
                $next = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM admin_notifications')->fetchColumn() + 1;
                $rows = $db->query('SELECT id FROM admin_notifications WHERE id <= 0')->fetchAll(PDO::FETCH_ASSOC);
                $upd = $db->prepare('UPDATE admin_notifications SET id = :new WHERE id = :old LIMIT 1');
                foreach ($rows as $r) {
                    $upd->execute(['new' => $next, 'old' => (int) $r['id']]);
                    $next++;
                }
            }
            $db->exec("ALTER TABLE admin_notifications MODIFY COLUMN id $idType NOT NULL AUTO_INCREMENT");
        }
        $checked = true;
        return true;
    } catch (Throwable $e) {
        error_log('admin_notifications_ensure: ' . $e->getMessage());
        // On ne memorise PAS l'echec : un appel ulterieur peut reussir
        // (table creee entre-temps par un autre script).
        return false;
    }
}

/**
 * Enregistre une notification. INSERT uniquement — jamais de mise à jour
 * ni de suppression : la trace est inaltérable par construction.
 * Ne bloque JAMAIS l'action principale en cas d'échec (fail-open).
 * $audience : ['role' => 'admin'|'superviseur'|'vendeur', 'user_id' => ?int,
 *              'dossier_id' => ?int, 'vendeur_id' => ?int]
 *   - 'admin' (défaut)  : visible par les admins (ancien comportement) ;
 *   - 'superviseur'     : visible par TOUS les superviseurs ;
 *   - 'vendeur'+user_id : visible par CE vendeur uniquement.
 */
function notify_superviseur_action(
    PDO $db,
    string $action,
    string $entity,
    $entityId,
    string $title,
    ?string $detail = null,
    array $audience = []
): void {
    try {
        admin_notifications_ensure($db);
        $user = function_exists('current_user') ? current_user() : null;
        $targetRole = substr((string) ($audience['role'] ?? 'admin'), 0, 20);
        if (!in_array($targetRole, ['admin', 'superviseur', 'vendeur'], true)) {
            $targetRole = 'admin';
        }
        $targetUserId = isset($audience['user_id']) ? (int) $audience['user_id'] : null;
        if ($targetUserId !== null && $targetUserId <= 0) {
            $targetUserId = null;
        }
        $dossierId = isset($audience['dossier_id']) ? (int) $audience['dossier_id'] : null;
        if ($dossierId !== null && $dossierId <= 0) {
            $dossierId = null;
        }
        $vendeurId = isset($audience['vendeur_id']) ? (int) $audience['vendeur_id'] : null;
        if ($vendeurId !== null && $vendeurId <= 0) {
            $vendeurId = null;
        }
        $base = [
            'aid'    => $user['id'] ?? null,
            'auser'  => substr((string) ($user['username'] ?? 'inconnu'), 0, 60),
            'arole'  => substr((string) ($user['role'] ?? 'superviseur'), 0, 20),
            'action' => substr($action, 0, 30),
            'entity' => substr($entity, 0, 30),
            'eid'    => $entityId === null ? null : substr((string) $entityId, 0, 60),
            'title'  => substr($title, 0, 255),
            'detail' => $detail,
            'ip'     => function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? null),
        ];
        // Tolère les bases où la migration d'audience n'a pas encore tourné.
        $hasAudienceCols = false;
        try {
            $cols = $db->query('SHOW COLUMNS FROM admin_notifications')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $c) {
                if ((string) ($c['Field'] ?? '') === 'target_role') {
                    $hasAudienceCols = true;
                    break;
                }
            }
        } catch (Throwable $e) {
            $hasAudienceCols = false;
        }
        if ($hasAudienceCols) {
            $stmt = $db->prepare(
                'INSERT INTO admin_notifications
                    (actor_id, actor_username, actor_role, target_role, target_user_id, action, entity, entity_id, dossier_id, vendeur_id, title, detail, ip_address)
                 VALUES (:aid, :auser, :arole, :trole, :tuid, :action, :entity, :eid, :did, :vid, :title, :detail, :ip)'
            );
            $stmt->execute($base + [
                'trole' => $targetRole, 'tuid' => $targetUserId,
                'did' => $dossierId, 'vid' => $vendeurId,
            ]);
        } else {
            $stmt = $db->prepare(
                'INSERT INTO admin_notifications
                    (actor_id, actor_username, actor_role, action, entity, entity_id, title, detail, ip_address)
                 VALUES (:aid, :auser, :arole, :action, :entity, :eid, :title, :detail, :ip)'
            );
            $stmt->execute($base);
        }
    } catch (Throwable $e) {
        error_log('notify_superviseur_action: ' . $e->getMessage());
    }
}

/** Notifie les SUPERVISEURS d'une action faite par un VENDEUR. */
function notify_vendeur_action(
    PDO $db,
    string $action,
    string $entity,
    $entityId,
    string $title,
    ?string $detail = null,
    ?int $dossierId = null,
    ?int $vendeurId = null
): void {
    notify_superviseur_action($db, $action, $entity, $entityId, $title, $detail,
        ['role' => 'admin', 'dossier_id' => $dossierId, 'vendeur_id' => $vendeurId]);
    notify_superviseur_action($db, $action, $entity, $entityId, $title, $detail,
        ['role' => 'superviseur', 'dossier_id' => $dossierId, 'vendeur_id' => $vendeurId]);
}

/** Notifie le VENDEUR propriétaire quand son dossier est touché. */
function notify_vendeur_owner(
    PDO $db,
    int $vendeurId,
    string $action,
    string $entity,
    $entityId,
    string $title,
    ?string $detail = null,
    ?int $dossierId = null
): void {
    if ($vendeurId <= 0) {
        return;
    }
    notify_superviseur_action($db, $action, $entity, $entityId, $title, $detail,
        ['role' => 'admin', 'dossier_id' => $dossierId, 'vendeur_id' => $vendeurId]);
    notify_superviseur_action($db, $action, $entity, $entityId, $title, $detail,
        ['role' => 'vendeur', 'user_id' => $vendeurId, 'dossier_id' => $dossierId, 'vendeur_id' => $vendeurId]);
}

/** Clause d'audience : limite la lecture aux notifications du rôle. */
function role_notifications_audience_where(PDO $db): array
{
    $user = function_exists('current_user') ? current_user() : null;
    $uid = (int) ($user['id'] ?? 0);
    $hasCols = false;
    try {
        $cols = $db->query('SHOW COLUMNS FROM admin_notifications')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            if ((string) ($c['Field'] ?? '') === 'target_role') {
                $hasCols = true;
                break;
            }
        }
    } catch (Throwable $e) {
        $hasCols = false;
    }
    if (!$hasCols) {
        return ['sql' => '1=1', 'params' => []];
    }
    if (function_exists('is_admin') && is_admin()) {
        return ['sql' => "COALESCE(target_role,'admin') = 'admin'", 'params' => []];
    }
    if (function_exists('is_role_superviseur') && is_role_superviseur()) {
        return ['sql' => "COALESCE(target_role,'') = 'superviseur'", 'params' => []];
    }
    if (function_exists('is_vendeur_user') && is_vendeur_user()) {
        return ['sql' => "COALESCE(target_role,'') = 'vendeur' AND (target_user_id IS NULL OR target_user_id = 0 OR target_user_id = :me_uid)", 'params' => ['me_uid' => $uid]];
    }
    return ['sql' => '1=0', 'params' => []];
}

/** Non lues pour le rôle courant (cloche superviseur / vendeur). */
function role_notifications_unread_count(PDO $db): int
{
    try {
        if (function_exists('is_admin') && is_admin()) {
            return 0;
        }
        $allowed = (function_exists('is_role_superviseur') && is_role_superviseur())
            || (function_exists('is_vendeur_user') && is_vendeur_user());
        if (!$allowed) {
            return 0;
        }
        admin_notifications_ensure($db);
        $a = role_notifications_audience_where($db);
        $stmt = $db->prepare('SELECT COUNT(*) FROM admin_notifications WHERE is_read = 0 AND ' . $a['sql']);
        $stmt->execute($a['params']);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Nombre de notifications non lues (badge de la cloche). Admin uniquement. */
function admin_notifications_unread_count(PDO $db): int
{
    try {
        if (!function_exists('is_admin') || !is_admin()) {
            return 0;
        }
        admin_notifications_ensure($db);
        return (int) $db->query('SELECT COUNT(*) FROM admin_notifications WHERE is_read = 0')->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Dernières notifications du rôle courant (menu cloche). */
function role_notifications_latest(PDO $db, int $limit = 8): array
{
    try {
        if (function_exists('is_admin') && is_admin()) {
            return [];
        }
        $allowed = (function_exists('is_role_superviseur') && is_role_superviseur())
            || (function_exists('is_vendeur_user') && is_vendeur_user());
        if (!$allowed) {
            return [];
        }
        admin_notifications_ensure($db);
        $limit = max(1, min(50, $limit));
        $a = role_notifications_audience_where($db);
        $stmt = $db->prepare(
            'SELECT * FROM admin_notifications WHERE ' . $a['sql'] . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit
        );
        $stmt->execute($a['params']);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Historique paginé du rôle courant (page mes_notifications.php). */
function role_notifications_history(PDO $db, int $page = 1, int $perPage = 25, string $action = ''): array
{
    $out = ['items' => [], 'total' => 0, 'pages' => 1, 'page' => 1];
    try {
        if (function_exists('is_admin') && is_admin()) {
            return $out;
        }
        $allowed = (function_exists('is_role_superviseur') && is_role_superviseur())
            || (function_exists('is_vendeur_user') && is_vendeur_user());
        if (!$allowed) {
            return $out;
        }
        admin_notifications_ensure($db);
        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        $a = role_notifications_audience_where($db);
        $where = [$a['sql']];
        $params = $a['params'];
        if ($action !== '') {
            $where[] = '`action` = :faction';
            $params['faction'] = $action;
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $count = $db->prepare("SELECT COUNT(*) FROM admin_notifications $whereSql");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $stmt = $db->prepare(
            "SELECT * FROM admin_notifications $whereSql ORDER BY created_at DESC, id DESC LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute($params);
        $out = ['items' => $stmt->fetchAll(), 'total' => $total, 'pages' => $pages, 'page' => $page];
    } catch (Throwable $e) {
    }
    return $out;
}

/** Marque UNE notification du rôle courant comme lue (vérifie l'audience). */
function role_notifications_mark_one_read(PDO $db, int $id): bool
{
    try {
        if ($id <= 0) {
            return false;
        }
        $me = function_exists('current_user') ? current_user() : null;
        admin_notifications_ensure($db);
        $a = role_notifications_audience_where($db);
        $params = $a['params'] + ['nid' => $id, 'me' => $me['id'] ?? null];
        $stmt = $db->prepare(
            'UPDATE admin_notifications SET is_read = 1, read_by = :me, read_at = NOW() WHERE id = :nid AND ' . $a['sql']
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/** Marque TOUTES les notifications du rôle courant comme lues. */
function role_notifications_mark_all_read(PDO $db): int
{
    try {
        $me = function_exists('current_user') ? current_user() : null;
        admin_notifications_ensure($db);
        $a = role_notifications_audience_where($db);
        $params = $a['params'] + ['me' => $me['id'] ?? null];
        $stmt = $db->prepare(
            'UPDATE admin_notifications SET is_read = 1, read_by = :me, read_at = NOW() WHERE is_read = 0 AND ' . $a['sql']
        );
        $stmt->execute($params);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Dernières notifications pour le menu déroulant de la cloche. Admin uniquement. */
function admin_notifications_latest(PDO $db, int $limit = 10): array
{
    try {
        if (!function_exists('is_admin') || !is_admin()) {
            return [];
        }
        admin_notifications_ensure($db);
        $limit = max(1, min(50, $limit));
        $stmt = $db->query(
            'SELECT * FROM admin_notifications ORDER BY created_at DESC, id DESC LIMIT ' . $limit
        );
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Marque toutes les notifications comme lues. Admin uniquement. Retourne le nb marqué. */
function admin_notifications_mark_all_read(PDO $db): int
{
    try {
        if (!function_exists('is_admin') || !is_admin()) {
            return 0;
        }
        admin_notifications_ensure($db);
        $me = function_exists('current_user') ? current_user() : null;
        $stmt = $db->prepare(
            'UPDATE admin_notifications SET is_read = 1, read_by = :me, read_at = NOW() WHERE is_read = 0'
        );
        $stmt->execute(['me' => $me['id'] ?? null]);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Historique paginé + filtres (fonctionne même si table vide). Admin uniquement. */
function admin_notifications_history(PDO $db, int $page = 1, int $perPage = 25, string $actor = '', string $action = ''): array
{
    $out = ['items' => [], 'total' => 0, 'pages' => 1, 'page' => 1];
    try {
        if (!function_exists('is_admin') || !is_admin()) {
            return $out;
        }
        admin_notifications_ensure($db);
        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        $where = [];
        $params = [];
        if ($actor !== '') {
            $where[] = 'actor_username LIKE :actor';
            $params['actor'] = '%' . $actor . '%';
        }
        if ($action !== '') {
            $where[] = '`action` = :action';
            $params['action'] = $action;
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $count = $db->prepare("SELECT COUNT(*) FROM admin_notifications $whereSql");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $stmt = $db->prepare(
            "SELECT * FROM admin_notifications $whereSql ORDER BY created_at DESC, id DESC LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute($params);
        $out = ['items' => $stmt->fetchAll(), 'total' => $total, 'pages' => $pages, 'page' => $page];
    } catch (Throwable $e) {
        // table absente => historique vide, pas d'erreur fatale
    }
    return $out;
}

/** Libellé français d'une action pour l'affichage. */
function admin_notification_action_label(string $action): string
{
    $labels = [
        'create' => 'Création',
        'update' => 'Modification',
        'delete' => 'Suppression',
        'import' => 'Import',
        'upload' => 'Ajout pièce jointe',
        'salary' => 'Paie',
        'employee' => 'Employé',
        'restore' => 'Restauration',
        'reactivate' => 'Réactivation',
    ];
    return $labels[$action] ?? ucfirst($action);
}

/** Icône (emoji texte, sans dépendance) associée à une action. */
function admin_notification_icon(string $action): string
{
    $icons = [
        'create' => '&#10133;',
        'update' => '&#9998;',
        'delete' => '&#128465;',
        'import' => '&#8681;',
        'upload' => '&#128206;',
        'salary' => '&#128178;',
        'employee' => '&#128100;',
        'restore' => '&#8617;',
        'reactivate' => '&#8635;',
    ];
    return $icons[$action] ?? '&#9679;';
}
