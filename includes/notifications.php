<?php
/**
 * Notifications administrateur — journal anti-manipulation.
 *
 * Chaque action effectuée par un SUPERVISEUR (création / modification /
 * suppression / import / pièce jointe / RH / salaires) est enregistrée ici
 * dans une table INSERT-ONLY : aucune fonction UPDATE ni DELETE n'existe
 * dans ce module, et la page dédiée n'offre que la lecture. Un superviseur
 * ne peut donc ni masquer ni altérer la trace de ses propres actions.
 *
 * La table est créée automatiquement (CREATE TABLE IF NOT EXISTS) pour
 * fonctionner aussi sur l'hébergement sans import SQL manuel.
 */

if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Accès direct interdit.');
}

/** Crée la table des notifications si elle n'existe pas encore. */
function admin_notifications_ensure(PDO $db): bool
{
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

        // Auto-réparation : certaines bases ont perdu AUTO_INCREMENT sur id.
        // Sans cela, toute notification s'insère avec id = 0 (doublon / échec).
        $cols = $db->query('SHOW COLUMNS FROM admin_notifications')->fetchAll(PDO::FETCH_ASSOC);
        $idType = 'bigint(20) unsigned';
        $idIsAuto = false;
        foreach ($cols as $c) {
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
        return true;
    } catch (Throwable $e) {
        error_log('admin_notifications_ensure: ' . $e->getMessage());
        return false;
    }
}

/**
 * Enregistre une notification. INSERT uniquement — jamais de mise à jour
 * ni de suppression : la trace est inaltérable par construction.
 * Ne bloque JAMAIS l'action principale en cas d'échec (fail-open).
 */
function notify_superviseur_action(
    PDO $db,
    string $action,
    string $entity,
    $entityId,
    string $title,
    ?string $detail = null
): void {
    try {
        admin_notifications_ensure($db);
        $user = function_exists('current_user') ? current_user() : null;
        $stmt = $db->prepare(
            'INSERT INTO admin_notifications
                (actor_id, actor_username, actor_role, action, entity, entity_id, title, detail, ip_address)
             VALUES (:aid, :auser, :arole, :action, :entity, :eid, :title, :detail, :ip)'
        );
        $stmt->execute([
            'aid'    => $user['id'] ?? null,
            'auser'  => substr((string) ($user['username'] ?? 'inconnu'), 0, 60),
            'arole'  => substr((string) ($user['role'] ?? 'superviseur'), 0, 20),
            'action' => substr($action, 0, 30),
            'entity' => substr($entity, 0, 30),
            'eid'    => $entityId === null ? null : substr((string) $entityId, 0, 60),
            'title'  => substr($title, 0, 255),
            'detail' => $detail,
            'ip'     => function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? null),
        ]);
    } catch (Throwable $e) {
        error_log('notify_superviseur_action: ' . $e->getMessage());
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
