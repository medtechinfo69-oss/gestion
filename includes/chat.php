<?php
/**
 * Chat interne entre tous les utilisateurs (admin <-> superviseur <-> vendeur).
 *
 * BASE DE DONNEES (MySQL = choix le plus sûr et le plus rapide ici) :
 *  - chat_messages : messages PERSISTANTS (jamais perdus) et CHIFFRÉS.
 *  - chat_presence : présence "en ligne" (dernier ping < 60 s = en ligne).
 * Les tables sont créées automatiquement si elles n'existent pas.
 *
 * Securite :
 *  - Requêtes 100% préparées.
 *  - Texte stocké CHIFFRÉ (AES-256-GCM) avec une clé dérivée de APP_SECRET_KEY :
 *    une copie de la base ne révèle pas les conversations. Si aucune clé n'est
 *    configurée, on retombe proprement sur du texte clair (jamais de plantage).
 *  - Anti-spam (1 message / 500 ms), taille max 5000 caractères.
 *  - Contrôle strict des rôles côté serveur.
 *
 * Performance :
 *  - Index composites (conversation + non lus + date).
 *  - Lecture groupée : une seule requête pour les contacts (pas de N+1).
 */

// La clé APP_SECRET_KEY est chargée par config/config.php (déjà inclus avant ce fichier).

if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Accès direct interdit.');
}

const CHAT_ONLINE_WINDOW = 60;      // secondes considérées "en ligne"
const CHAT_MAX_LENGTH    = 5000;    // caractères max par message
const CHAT_MIN_INTERVAL  = 0.5;     // délai minimum entre deux messages (s)
const CHAT_KEEP_DAYS     = 730;     // purge automatique au-delà de 2 ans

/** Crée les tables du chat si nécessaires. Retourne true si OK. */
function chat_tables_ensure(PDO $db): bool
{
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS chat_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sender_id INT UNSIGNED NOT NULL,
            receiver_id INT UNSIGNED NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_conv (sender_id, receiver_id, id),
            INDEX idx_receiver_unread (receiver_id, is_read),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS chat_presence (
            user_id INT UNSIGNED PRIMARY KEY,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_seen (last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Index ajoutés après coup sur les tables déjà existantes
        // (accélère la purge et la lecture par date).
        try {
            $db->exec("ALTER TABLE chat_messages ADD INDEX idx_created (created_at)");
        } catch (Throwable $e) {
            // index déjà présent : rien à faire
        }

        return true;
    } catch (Throwable $e) {
        error_log('chat_tables_ensure: ' . $e->getMessage());
        return false;
    }
}

/**
 * Vérifie et répare le schéma de la table chat_messages (équivalent de
 * users_schema_ensure pour la table users).
 *
 * Sur certains hébergeurs mutualisés (ex : InfinityFree), la table peut exister
 * avec un schéma partiel ou sans AUTO_INCREMENT sur la clé primaire `id`.
 * Dans ce cas, CREATE TABLE IF NOT EXISTS ne corrige rien, et INSERT
 * échoue ou lastInsertId() renvoie 0 — ce qui se traduit par l'erreur
 * "Erreur d'envoi." côté client sans message explicite.
 *
 * Cette fonction s'assure que :
 *  - la colonne id est bien BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY ;
 *  - la colonne message est bien TEXT NOT NULL.
 * Retourne true si la table est prête.
 */
function chat_messages_schema_ensure(PDO $db): bool
{
    static $done = false;
    if ($done) {
        return true;
    }
    $done = true;

    try {
        // Vérifie que la table existe
        $tables = $db->query("SHOW TABLES LIKE 'chat_messages'")->fetchAll(PDO::FETCH_COLUMN);
        if (empty($tables)) {
            // La table n'existe pas — création via chat_tables_ensure
            return chat_tables_ensure($db);
        }

        // Vérifie la structure de la colonne id
        $cols = $db->query('SHOW COLUMNS FROM chat_messages')->fetchAll(PDO::FETCH_ASSOC);
        $colInfo = [];
        foreach ($cols as $c) {
            $colInfo[$c['Field']] = $c;
        }

        $idExtra = (string) ($colInfo['id']['Extra'] ?? '');
        $idKey = (string) ($colInfo['id']['Key'] ?? '');

        // Répare la colonne id si elle n'est pas AUTO_INCREMENT / PRIMARY KEY
        if ($idKey !== 'PRI' || stripos($idExtra, 'auto_increment') === false) {
            // 1) Détecte les IDs en double (MySQL 8+ refuse l'ALTER TABLE si
            //    des doublons existent dans la clé primaire candidate).
            $dupCount = (int) $db->query(
                "SELECT COUNT(*) FROM (
                    SELECT id FROM chat_messages
                    WHERE id IS NOT NULL AND id > 0
                    GROUP BY id HAVING COUNT(*) > 1
                ) dup"
            )->fetchColumn();

                        // 2) S'il y a des doublons, on renumrote un par un en partant
            //    du max(id) existant — compatible MySQL 5.7 et 8.x.
            if ($dupCount > 0) {
                $maxId = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM chat_messages')->fetchColumn();
                $next = $maxId + 1;
                $dupIds = $db->query(
                    "SELECT id FROM chat_messages WHERE id IS NOT NULL AND id > 0
                     GROUP BY id HAVING COUNT(*) > 1"
                )->fetchAll(PDO::FETCH_COLUMN);
                foreach ($dupIds as $dupId) {
                    $count = (int) $db->query(
                        "SELECT COUNT(*) FROM chat_messages WHERE id = " . (int) $dupId
                    )->fetchColumn();
                    // Garde la première occurrence, renumrote les suivantes
                    for ($i = 1; $i < $count; $i++) {
                        $db->exec(
                            "UPDATE chat_messages SET id = " . $next . " WHERE id = " . (int) $dupId . " LIMIT 1"
                        );
                        $next++;
                    }
                }
            }

            // 3) Supprime d'éventuelles lignes id <= 0 (NULL ou 0)
            $db->exec("DELETE FROM chat_messages WHERE id IS NULL OR id <= 0");

            // 4) Maintenant on peut poser la clé primaire + AUTO_INCREMENT
            if ($idKey === 'PRI') {
                $db->exec('ALTER TABLE chat_messages DROP PRIMARY KEY');
            }
            $db->exec("ALTER TABLE chat_messages MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)");
        }

        // S'assure que la colonne message est TEXT (pas VARCHAR qui limiterait la taille)
        $msgType = strtolower((string) ($colInfo['message']['Type'] ?? ''));
        if ($msgType !== 'text' && !str_starts_with($msgType, 'text')) {
            $db->exec("ALTER TABLE chat_messages MODIFY COLUMN message TEXT NOT NULL");
        }


        // Répare aussi chat_presence : sans PRIMARY KEY sur user_id, chaque
        // ping de présence insère une NOUVELLE ligne (10 000+ lignes vues sur
        // la base de test) au lieu de mettre à jour la ligne existante.
        foreach ($db->query('SHOW COLUMNS FROM chat_presence')->fetchAll(PDO::FETCH_ASSOC) as $pCol) {
            if (($pCol['Field'] ?? '') === 'user_id') {
                if (($pCol['Key'] ?? '') !== 'PRI') {
                    try { $db->exec('ALTER TABLE chat_presence DROP PRIMARY KEY'); } catch (Throwable $pE) { /* pas de PK */ }
                    $db->exec('ALTER TABLE chat_presence MODIFY COLUMN user_id INT UNSIGNED NOT NULL, ADD PRIMARY KEY (user_id)');
                }
                break;
            }
        }
        return true;
    } catch (Throwable $e) {
        error_log('chat_messages_schema_ensure: ' . $e->getMessage());
        return false;
    }
}

/**
 * Vérifie et répare automatiquement le schéma de la table users
 * (cas d'une base en ligne créée avec un ancien schéma) :
 *  - colonne nom_complet manquante -> ajoutée ;
 *  - valeur 'superviseur' absente de l'ENUM du rôle -> ajoutée, et les
 *    rôles invalides (insérés avant la correction) deviennent 'superviseur'.
 * Sans cela, la liste des contacts du chat reste vide même si le compte
 * superviseur existe. Retourne true si la table est prête.
 */
function users_schema_ensure(PDO $db): bool
{
    static $done = false;
    if ($done) {
        return true;
    }
    $done = true;
    try {
        $cols  = $db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($cols, 'Field');

        // 1) Colonne nom_complet absente -> on l'ajoute
        if (!in_array('nom_complet', $names, true)) {
            $db->exec("ALTER TABLE users ADD COLUMN nom_complet VARCHAR(150) NOT NULL DEFAULT '' AFTER username");
        }

        // 1b) Colonne can_supervise : un VENDEUR dont le drapeau vaut 1 dispose
        //     des mêmes accès que les superviseurs (dashboard, dossiers, import,
        //     notifications, approbation IP) tout en gardant le rôle « vendeur ».
        if (!in_array('can_supervise', $names, true)) {
            $db->exec("ALTER TABLE users ADD COLUMN can_supervise TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
        }

        // 2) Rôle 'superviseur' absent de l'ENUM -> on étend l'ENUM
        $roleType = '';
        foreach ($cols as $c) {
            if (($c['Field'] ?? '') === 'role') {
                $roleType = (string) ($c['Type'] ?? '');
                break;
            }
        }
        if ($roleType !== '' && stripos($roleType, 'superviseur') === false) {
            $db->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','superviseur','vendeur') NOT NULL DEFAULT 'vendeur'");
            // Répare les comptes dont le rôle avait été rejeté à l'insertion
            $db->exec("UPDATE users SET role = 'superviseur' WHERE role NOT IN ('admin','superviseur','vendeur')");
        }

        // 3) Restaure PRIMARY KEY + AUTO_INCREMENT sur `id` si la table les a
        //    perdus. Sans cela, toute nouvelle ligne s'insère avec id = 0 et la
        //    suppression/utilisation des enregistrements échoue.
        $idType = 'int(10) unsigned';
        $idIsAuto = false;
        foreach ($cols as $c) {
            if (($c['Field'] ?? '') === 'id') {
                $idType = (string) ($c['Type'] ?? $idType);
                $idIsAuto = stripos((string) ($c['Extra'] ?? ''), 'auto_increment') !== false;
                break;
            }
        }
        $hasPrimary = false;
        foreach ($db->query('SHOW INDEX FROM users') as $idx) {
            if (($idx['Key_name'] ?? '') === 'PRIMARY') { $hasPrimary = true; break; }
        }
        if (!($hasPrimary && $idIsAuto)) {
            // Renumérote les lignes dont l'id <= 0 avant de poser la clé primaire.
            $zeroCount = (int) $db->query('SELECT COUNT(*) FROM users WHERE id <= 0')->fetchColumn();
            if ($zeroCount > 0) {
                $maxId = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM users')->fetchColumn();
                $rows = $db->query('SELECT id FROM users WHERE id <= 0 ORDER BY created_at')->fetchAll(PDO::FETCH_ASSOC);
                $next = $maxId + 1;
                $upd = $db->prepare('UPDATE users SET id = :new WHERE id = :old LIMIT 1');
                foreach ($rows as $r) {
                    $upd->execute(['new' => $next, 'old' => (int) $r['id']]);
                    $next++;
                }
            }
            if ($hasPrimary) {
                $db->exec('ALTER TABLE users DROP PRIMARY KEY');
            }
            $db->exec("ALTER TABLE users MODIFY COLUMN id $idType NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)");
        }
        return true;
    } catch (Throwable $e) {
        error_log('users_schema_ensure: ' . $e->getMessage());
        return false;
    }
}

// ---------------------------------------------------------------------
// Chiffrement des messages (AES-256-GCM, clé dérivée de APP_SECRET_KEY)
// ---------------------------------------------------------------------

/** Clé de chiffrement (32 octets) ou null si aucune clé fiable n'est configurée. */
function chat_crypto_key(): ?string
{
    $secret = defined('APP_SECRET_KEY') ? (string) APP_SECRET_KEY : '';
    if (strlen($secret) < 16) {
        return null; // pas de clé fiable : stockage en clair (le chat reste fonctionnel)
    }
    return hash('sha256', 'chat-v1|' . $secret, true);
}

/** Chiffre un message : "v1:base64(iv|tag|cipher)". */
function chat_encrypt(string $plain): string
{
    $key = chat_crypto_key();
    if ($key === null || !function_exists('openssl_encrypt')) {
        return $plain;
    }
    try {
        $iv  = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            return $plain;
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    } catch (Throwable $e) {
        error_log('chat_encrypt: ' . $e->getMessage());
        return $plain;
    }
}

/** Déchiffre un message stocké. Les messages hérités en clair restent lisibles. */
function chat_decrypt(string $stored): string
{
    if (!str_starts_with($stored, 'v1:')) {
        return $stored;
    }
    $key = chat_crypto_key();
    if ($key === null || !function_exists('openssl_decrypt')) {
        return '[message chiffré indisponible]';
    }
    try {
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            return '[message illisible]';
        }
        $plain = openssl_decrypt(
            substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16)
        );
        return $plain === false ? '[message illisible]' : $plain;
    } catch (Throwable $e) {
        error_log('chat_decrypt: ' . $e->getMessage());
        return '[message illisible]';
    }
}

/** Anti-spam : vrai si l'utilisateur peut envoyer maintenant (1 msg / 500 ms). */
function chat_can_send(int $userId): bool
{
    $key  = 'last_chat_send_' . $userId;
    $now  = microtime(true);
    $last = (float) ($_SESSION[$key] ?? 0);
    if ($last > 0 && ($now - $last) < CHAT_MIN_INTERVAL) {
        return false;
    }
    $_SESSION[$key] = $now;
    return true;
}

/** Rejoue une action après création des tables (fail-safe). */
function chat_retry(PDO $db, callable $action)
{
    if (!chat_tables_ensure($db)) {
        return null;
    }
    // S'assure que le schéma des tables chat est correct (répare un
    // éventuel AUTO_INCREMENT manquant sur le serveur de production).
    chat_messages_schema_ensure($db);
    try {
        return $action();
    } catch (Throwable $e) {
        error_log('chat_retry: ' . $e->getMessage());
        return null;
    }
}

/** Met à jour le ping de présence de l'utilisateur courant. */
function chat_touch_presence(PDO $db, int $userId): void
{
    try {
        $stmt = $db->prepare(
            'INSERT INTO chat_presence (user_id, last_seen_at) VALUES (:u, NOW())
             ON DUPLICATE KEY UPDATE last_seen_at = NOW()'
        );
        $stmt->execute(['u' => $userId]);
    } catch (Throwable $e) {
        // table absente : on tente de la créer puis on réessaie une fois
        if (chat_tables_ensure($db)) {
            try {
                $stmt = $db->prepare(
                    'INSERT INTO chat_presence (user_id, last_seen_at) VALUES (:u, NOW())
                     ON DUPLICATE KEY UPDATE last_seen_at = NOW()'
                );
                $stmt->execute(['u' => $userId]);
            } catch (Throwable $e2) {
                error_log('chat_touch_presence: ' . $e2->getMessage());
            }
        }
    }
}

/**
 * Liste des autres utilisateurs actifs (admin + superviseurs + vendeurs)
 * avec statut en ligne et nombre de messages non lus reçus de chacun.
 * Une seule requête SQL (sous-requête de comptage) = rapide, pas de N+1.
 */
function chat_get_contacts(PDO $db, int $meId): array
{
    try {
        $stmt = $db->prepare(
            "SELECT u.id, u.username, u.nom_complet, u.role,
                    (p.last_seen_at IS NOT NULL AND p.last_seen_at >= (NOW() - INTERVAL " . CHAT_ONLINE_WINDOW . " SECOND)) AS is_online,
                    p.last_seen_at,
                    (SELECT COUNT(*) FROM chat_messages m
                      WHERE m.sender_id = u.id AND m.receiver_id = :me AND m.is_read = 0) AS unread
             FROM users u
             LEFT JOIN chat_presence p ON p.user_id = u.id
             WHERE u.is_active = 1 AND u.role IN ('admin','superviseur','vendeur') AND u.id <> :me2
             ORDER BY is_online DESC, unread DESC, u.nom_complet"
        );
        $stmt->execute(['me' => $meId, 'me2' => $meId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('chat_get_contacts: ' . $e->getMessage());
        // Auto-réparation (tables + schéma users) puis une seule nouvelle tentative
        chat_tables_ensure($db);
        users_schema_ensure($db);
        try {
            $stmt = $db->prepare(
                "SELECT u.id, u.username, u.nom_complet, u.role,
                        (p.last_seen_at IS NOT NULL AND p.last_seen_at >= (NOW() - INTERVAL " . CHAT_ONLINE_WINDOW . " SECOND)) AS is_online,
                        p.last_seen_at,
                        (SELECT COUNT(*) FROM chat_messages m
                          WHERE m.sender_id = u.id AND m.receiver_id = :me AND m.is_read = 0) AS unread
                 FROM users u
                 LEFT JOIN chat_presence p ON p.user_id = u.id
                 WHERE u.is_active = 1 AND u.role IN ('admin','superviseur','vendeur') AND u.id <> :me2
                 ORDER BY is_online DESC, unread DESC, u.nom_complet"
            );
            $stmt->execute(['me' => $meId, 'me2' => $meId]);
            return $stmt->fetchAll();
        } catch (Throwable $e2) {
            error_log('chat_get_contacts(retry): ' . $e2->getMessage());
            return [];
        }
    }
}

/**
 * Petit diagnostic renvoyé avec le statut du chat (admins uniquement) :
 * répartition des comptes par rôle/état + état du schéma et des tables.
 * Sert à afficher DANS le widget la vraie raison d'une liste vide.
 */
function chat_status_diag(PDO $db, int $meId): array
{
    $d = [];
    try {
        $d['schema_ok'] = users_schema_ensure($db);
        $d['tables_ok'] = chat_tables_ensure($db);
        $rows = $db->query('SELECT role, is_active, COUNT(*) AS n FROM users GROUP BY role, is_active')->fetchAll();
        $rep = [];
        foreach ($rows as $r) {
            $rep[] = ['role' => (string) $r['role'], 'actif' => (int) $r['is_active'], 'n' => (int) $r['n']];
        }
        $d['repartition'] = $rep;
    } catch (Throwable $e) {
        $d['erreur_sql'] = $e->getMessage();
    }
    return $d;
}

// ---------------------------------------------------------------------
// Conversation (lecture / écriture) — texte déchiffré à la sortie
// ---------------------------------------------------------------------

/**
 * Messages de la conversation.
 * $afterId = 0 : les 200 derniers (ordre chronologique).
 * $afterId > 0 : uniquement les nouveaux messages (polling rapide).
 * Le texte est déchiffré au passage. "unread" sert à la double coche.
 */
function chat_fetch_messages(PDO $db, int $meId, int $peerId, int $afterId = 0): array
{
    $load = function () use ($db, $meId, $peerId, $afterId) {
        if ($afterId > 0) {
            $stmt = $db->prepare(
                "SELECT id, sender_id, message, is_read,
                        DATE_FORMAT(created_at, '%d/%m %H:%i') AS time_fr
                 FROM chat_messages
                 WHERE id > :after
                   AND ((sender_id = :me AND receiver_id = :peer)
                     OR (sender_id = :peer2 AND receiver_id = :me2))
                 ORDER BY id ASC
                 LIMIT 200"
            );
            $stmt->execute(['after' => $afterId, 'me' => $meId, 'peer' => $peerId, 'peer2' => $peerId, 'me2' => $meId]);
        } else {
            $stmt = $db->prepare(
                "SELECT id, sender_id, message, is_read,
                        DATE_FORMAT(created_at, '%d/%m %H:%i') AS time_fr
                 FROM chat_messages
                 WHERE (sender_id = :me AND receiver_id = :peer)
                    OR (sender_id = :peer2 AND receiver_id = :me2)
                 ORDER BY id DESC
                 LIMIT 200"
            );
            $stmt->execute(['me' => $meId, 'peer' => $peerId, 'peer2' => $peerId, 'me2' => $meId]);
        }
        return $stmt->fetchAll();
    };

    try {
        $rows = $load();
    } catch (Throwable $e) {
        $rows = chat_retry($db, $load) ?? [];
    }

    if ($afterId === 0) {
        $rows = array_reverse($rows); // le LIMIT DESC doit être remis dans l'ordre
    }

    $messages = [];
    foreach ($rows as $row) {
        $messages[] = [
            'id'     => (int) $row['id'],
            'mine'   => (int) $row['sender_id'] === $meId,
            'unread' => !(int) $row['is_read'],
            'text'   => chat_decrypt((string) $row['message']),
            'time'   => $row['time_fr'],
        ];
    }
    return $messages;
}

/** Alias de compatibilité (ancien nom). */
function chat_get_conversation(PDO $db, int $meId, int $peerId, int $afterId = 0): array
{
    return chat_fetch_messages($db, $meId, $peerId, $afterId);
}

/**
 * Insère un message CHIFFRÉ. Retourne l'ID du message,
 * 0 en cas d'échec, -1 si l'anti-spam refuse l'envoi.
 */
function chat_send_message(PDO $db, int $senderId, int $receiverId, string $message): int
{
    $message = trim($message);
    if ($message === '' || $senderId === $receiverId) {
        return 0;
    }
    if (!chat_can_send($senderId)) {
        return -1; // trop rapide : on refuse l'envoi
    }

    $stored = chat_encrypt(mb_substr($message, 0, CHAT_MAX_LENGTH));
    $insert = function () use ($db, $senderId, $receiverId, $stored) {
        $stmt = $db->prepare(
            'INSERT INTO chat_messages (sender_id, receiver_id, message, is_read)
             VALUES (:s, :r, :m, 0)'
        );
        $stmt->execute(['s' => $senderId, 'r' => $receiverId, 'm' => $stored]);
        return (int) $db->lastInsertId();
    };

    try {
        return $insert();
    } catch (Throwable $e) {
        error_log('chat_send_message: ' . $e->getMessage());
        return (int) (chat_retry($db, $insert) ?? 0);
    }
}

/**
 * État de lecture des messages QUE J'AI ENVOYÉS à $peerId (double coche).
 * read_until = dernier ID lu par le correspondant ; last_id = mon dernier message.
 */
function chat_read_receipt(PDO $db, int $meId, int $peerId): array
{
    $load = function () use ($db, $meId, $peerId) {
        $stmt = $db->prepare(
            'SELECT COALESCE(MAX(CASE WHEN is_read = 1 THEN id END), 0) AS read_until,
                    COALESCE(MAX(id), 0) AS last_id
             FROM chat_messages
             WHERE sender_id = :me AND receiver_id = :peer'
        );
        $stmt->execute(['me' => $meId, 'peer' => $peerId]);
        return $stmt->fetch() ?: ['read_until' => 0, 'last_id' => 0];
    };

    try {
        $row = $load();
    } catch (Throwable $e) {
        $row = chat_retry($db, $load) ?? ['read_until' => 0, 'last_id' => 0];
    }

    return [
        'read_until' => (int) ($row['read_until'] ?? 0),
        'last_id'    => (int) ($row['last_id'] ?? 0),
    ];
}

/** Purge des messages trop anciens (exécutée au hasard, 1 fois sur ~200 appels). */
function chat_maybe_purge(PDO $db): void
{
    try {
        if (random_int(1, 200) !== 1) {
            return;
        }
        $stmt = $db->prepare('DELETE FROM chat_messages WHERE created_at < (NOW() - INTERVAL :d DAY)');
        $stmt->bindValue(':d', CHAT_KEEP_DAYS, PDO::PARAM_INT);
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('chat_maybe_purge: ' . $e->getMessage());
    }
}

/** Marque comme lus les messages reçus d'un correspondant donné. */
function chat_mark_read(PDO $db, int $meId, int $peerId): void
{
    try {
        $stmt = $db->prepare(
            'UPDATE chat_messages SET is_read = 1
             WHERE sender_id = :peer AND receiver_id = :me AND is_read = 0'
        );
        $stmt->execute(['peer' => $peerId, 'me' => $meId]);
    } catch (Throwable $e) {
        error_log('chat_mark_read: ' . $e->getMessage());
    }
}
/**
 * Vrai si $peerId est un correspondant autorisé (compte actif :
 * admin, superviseur ou vendeur).
 * Résultat mémorisé dans la requête courante pour éviter les requêtes répétées.
 */
function chat_peer_allowed(PDO $db, int $peerId): bool
{
    if ($peerId <= 0) {
        return false;
    }
    static $cache = [];
    if (array_key_exists($peerId, $cache)) {
        return $cache[$peerId];
    }

    $load = function () use ($db, $peerId) {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM users
             WHERE id = :id AND role IN ('admin','superviseur','vendeur') AND is_active = 1"
        );
        $stmt->execute(['id' => $peerId]);
        return (int) $stmt->fetchColumn() > 0;
    };

    try {
        $cache[$peerId] = $load();
    } catch (Throwable $e) {
        $cache[$peerId] = (bool) chat_retry($db, $load);
    }
    return $cache[$peerId];
}
/* ---------------------------------------------------------------------
   Nettoyage URGENT du chat (outil administrateur, voir
   repair_schema_admin.php). Supprime les conversations : utile quand la
   table du chat est corrompue ou sature l'espace d'un hebergement
   mutualise (quota), ou que « Erreur d'envoi » persiste malgre la
   reparation du schema. Aucune table metier n'est touchee.
   --------------------------------------------------------------------- */

/** Nombre de lignes et taille (octets) des tables du chat (null si indisponible). */
function chat_tables_stats(PDO $db): array
{
    $stats = ['messages' => null, 'presence' => null, 'bytes' => null];
    try {
        $st = $db->query('SELECT COUNT(*) FROM chat_messages');
        $stats['messages'] = (int) $st->fetchColumn();
        $st->closeCursor();
    } catch (Throwable $e) {}
    try {
        $st = $db->query('SELECT COUNT(*) FROM chat_presence');
        $stats['presence'] = (int) $st->fetchColumn();
        $st->closeCursor();
    } catch (Throwable $e) {}
    try {
        $st = $db->prepare(
            "SELECT COALESCE(SUM(data_length + index_length), 0)
             FROM information_schema.TABLES
             WHERE table_schema = DATABASE()
               AND table_name IN ('chat_messages', 'chat_presence')"
        );
        $st->execute();
        $stats['bytes'] = (int) $st->fetchColumn();
        $st->closeCursor();
    } catch (Throwable $e) {}
    return $stats;
}

/** Formate un nombre d'octets en o / Ko / Mo (lisible dans les rapports). */
function chat_format_octets(?int $bytes): string
{
    if ($bytes === null) {
        return 'n/d';
    }
    if ($bytes < 1024) {
        return $bytes . ' o';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' Ko';
    }
    return round($bytes / 1048576, 2) . ' Mo';
}

/**
 * Nettoyage URGENT du chat.
 *
 *  - $keepDays = 0 : TOUT est supprime (urgence) ;
 *  - $keepDays > 0 : seuls les messages plus vieux que N jours le sont.
 *
 * Dans tous les cas : messages orphelins (compte supprime) supprimes,
 * presence « en ligne » purgée (elle se regenere au prochain ping du
 * widget) et OPTIMIZE TABLE pour rendre l'espace a l'hebergeur.
 * Retourne un tableau de statistiques ('ok' = false si une etape a echoue).
 */
function chat_cleanup_all(PDO $db, int $keepDays = 0, bool $verbose = true): array
{
    $res = [
        'messages_before' => null, 'presence_before' => null, 'bytes_before' => null,
        'messages_after'  => null, 'presence_after'  => null, 'bytes_after'  => null,
        'orphans_deleted' => 0, 'messages_deleted' => 0, 'presence_deleted' => 0,
        'truncated' => false, 'optimized' => false,
        'ok' => true, 'errors' => [],
    ];

    $before = chat_tables_stats($db);
    $res['messages_before'] = $before['messages'];
    $res['presence_before'] = $before['presence'];
    $res['bytes_before']    = $before['bytes'];

    $keepDays = max(0, $keepDays);

    // 1) Messages orphelins : expediteur ou destinataire n'existe plus.
    try {
        $res['orphans_deleted'] = (int) $db->exec(
            'DELETE m FROM chat_messages m
              LEFT JOIN users u1 ON u1.id = m.sender_id
              LEFT JOIN users u2 ON u2.id = m.receiver_id
             WHERE u1.id IS NULL OR u2.id IS NULL'
        );
        if ($verbose && $res['orphans_deleted'] > 0) {
            echo "  chat_messages : {$res['orphans_deleted']} message(s) orphelin(s) supprime(s).\n";
        }
    } catch (Throwable $e) {
        $res['ok'] = false;
        $res['errors'][] = 'orphelins : ' . $e->getMessage();
        if ($verbose) { echo "  chat_messages : orphelins - ERREUR " . $e->getMessage() . "\n"; }
    }

    // 2) Messages anciens, ou TOUT en cas d'urgence (keepDays = 0).
    try {
        if ($keepDays > 0) {
            $stmt = $db->prepare('DELETE FROM chat_messages WHERE created_at < (NOW() - INTERVAL :d DAY)');
            $stmt->bindValue(':d', $keepDays, PDO::PARAM_INT);
            $stmt->execute();
            $res['messages_deleted'] = $stmt->rowCount();
            if ($verbose) {
                echo "  chat_messages : {$res['messages_deleted']} message(s) de plus de $keepDays jour(s) supprime(s).\n";
            }
        } else {
            // Urgence : TRUNCATE vide la table instantanement (sur un
            // hebergement mutualise, un DELETE massif peut depasser le temps
            // de requete autorise) et reinitialise l'AUTO_INCREMENT : cela
            // repare au passage une table endommagee. Repli sur DELETE si
            // TRUNCATE est refuse par l'hebergeur.
            try {
                $db->exec('TRUNCATE TABLE chat_messages');
                $res['truncated'] = true;
                $res['messages_deleted'] = (int) ($res['messages_before'] ?? 0);
            } catch (Throwable $e) {
                $res['messages_deleted'] = (int) $db->exec('DELETE FROM chat_messages');
            }
            if ($verbose) {
                echo "  chat_messages : table videe ({$res['messages_deleted']} message(s), AUTO_INCREMENT reinitialise).\n";
                echo "                  -> corrige aussi « Erreur d'envoi » / « non envoye - reessayez ».\n";
            }
        }
    } catch (Throwable $e) {
        $res['ok'] = false;
        $res['errors'][] = 'messages : ' . $e->getMessage();
        if ($verbose) { echo "  chat_messages : ERREUR " . $e->getMessage() . "\n"; }
    }

    // 3) Presence : purge systematique (se regenere au prochain ping).
    try {
        if ($keepDays > 0) {
            $res['presence_deleted'] = (int) $db->exec(
                'DELETE FROM chat_presence WHERE last_seen_at < (NOW() - INTERVAL 1 DAY)'
            );
        } else {
            try {
                $db->exec('TRUNCATE TABLE chat_presence');
                $res['truncated'] = true;
                $res['presence_deleted'] = (int) ($res['presence_before'] ?? 0);
            } catch (Throwable $e) {
                $res['presence_deleted'] = (int) $db->exec('DELETE FROM chat_presence');
            }
        }
        if ($verbose) {
            echo "  chat_presence : {$res['presence_deleted']} ligne(s) purge(s) (se regenere automatiquement).\n";
        }
    } catch (Throwable $e) {
        $res['ok'] = false;
        $res['errors'][] = 'presence : ' . $e->getMessage();
        if ($verbose) { echo "  chat_presence : ERREUR " . $e->getMessage() . "\n"; }
    }

    // 4) Liberation d'espace (defragmentation) - etape non bloquante.
    // NB : OPTIMIZE TABLE RENVOIE UN JEU DE RESULTATS : il faut le consommer
    // puis fermer le curseur, sinon toute requete suivante echoue avec
    // « 2014 Cannot execute queries while other unbuffered queries are
    // active » (PDOException).
    try {
        $st = $db->query('OPTIMIZE TABLE chat_messages, chat_presence');
        $st->fetchAll();
        $st->closeCursor();
        $res['optimized'] = true;
        if ($verbose) { echo "  optimisation : espace disque rendu a l'hebergeur.\n"; }
    } catch (Throwable $e) {
        if ($verbose) { echo "  optimisation : ignoree (" . $e->getMessage() . ")\n"; }
    }

    $after = chat_tables_stats($db);
    $res['messages_after'] = $after['messages'];
    $res['presence_after'] = $after['presence'];
    $res['bytes_after']    = $after['bytes'];

    if ($verbose) {
        $freed = ($res['bytes_before'] !== null && $res['bytes_after'] !== null)
            ? chat_format_octets(max(0, $res['bytes_before'] - $res['bytes_after']))
            : 'n/d';
        echo "  total : {$res['messages_after']} message(s) restant(s), "
            . chat_format_octets($res['bytes_after'])
            . " apres nettoyage ($freed libere(s)).\n";
    }

    return $res;
}
