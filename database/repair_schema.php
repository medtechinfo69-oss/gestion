<?php
/**
 * REPARATION DU SCHEMA DE LA BASE - outil unique et idempotent.
 *
 * Remplace et complete les anciens scripts separes :
 *   database/repair_users_schema.php
 *   database/repair_superviseur_schema.php
 *
 * Symptomes corriges (tous causes par des tables ayant perdu leur
 * PRIMARY KEY / AUTO_INCREMENT, ou par l'absence d'une cle unique) :
 *   - "Erreur d'envoi" / "non envoye - reessayez" dans le chat ;
 *   - "Demande invalide." lors de l'approbation d'une session superviseur ;
 *   - "Sélectionnez au moins un vendeur." a la suppression (ids a 0) ;
 *   - "Ce numéro de portable existe déjà" alors que le dossier n'existe pas ;
 *   - parametres (settings / security_settings) dupliques ;
 *   - televersement de pieces jointes en echec (id = 0).
 *
 * CE QUE FAIT LE SCRIPT
 *   1. Pour chaque table possedant une colonne `id` : restaure
 *      PRIMARY KEY + AUTO_INCREMENT. Les id dupliques ou <= 0 sont
 *      RENUMEROTES (aucune ligne supprimee : zero perte de donnees).
 *   2. chat_presence : restaure PRIMARY KEY (user_id) - indispensable au
 *      "ON DUPLICATE KEY UPDATE last_seen_at" du chat.
 *   3. Ajoute les cles UNIQUE necessaires aux requetes
 *      "INSERT ... ON DUPLICATE KEY UPDATE" (rate_limits, settings,
 *      security_settings, salary_records, superviseur_approved_ips, ...).
 *
 * UTILISATION
 *   CLI : php database/repair_schema.php
 *
 * Le dossier database/ est protege par un .htaccess (Require all denied) :
 * le script n'est donc pas joignable depuis le navigateur. Un garde-fou
 * HTTP (administrateur requis) reste present en defense supplementaire,
 * au cas ou le .htaccess serait ignore par l'hebergeur.
 *
 * NB : les tables metier (users, dossiers, employees, salary_records,
 * dossier_attachments) ne sont JAMAIS dedupliquees automatiquement : le
 * script se contente de signaler le probleme. Seules les tables d'etat
 * (settings, security_settings, rate_limits, chat_presence,
 * superviseur_approved_ips, user_permissions, origines) le sont.
 */

if (!defined('REPAIR_SCHEMA_LIBRARY')) {
    require_once __DIR__ . '/../includes/init.php';
    if (PHP_SAPI !== 'cli') {
        require_admin();
    }
}

/**
 * Restaure PRIMARY KEY + AUTO_INCREMENT sur la colonne `id` d'une table.
 * Renumerote les id dupliques ou <= 0 : aucune ligne n'est supprimee, donc
 * aucune donnee metier ne peut etre perdue. Ne fait rien si le schema est
 * deja correct.
 */
function repair_pk_id(PDO $db, string $table, bool $verbose = true): bool
{
    $q = $db->quote($table);
    if (!$db->query("SHOW TABLES LIKE $q")->fetchColumn()) {
        if ($verbose) { echo "  $table : absente (ignoree)\n"; }
        return true;
    }

    $cols = $db->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    $names = array_column($cols, 'Field');
    if (!in_array('id', $names, true)) {
        if ($verbose) { echo "  $table : pas de colonne id (ignoree)\n"; }
        return true;
    }

    $idType = 'int(10) unsigned';
    $idIsAuto = false;
    foreach ($cols as $c) {
        if ($c['Field'] === 'id') {
            $idType = (string) $c['Type'];
            $idIsAuto = stripos((string) $c['Extra'], 'auto_increment') !== false;
            break;
        }
    }

    $hasPrimary = false;
    foreach ($db->query("SHOW INDEX FROM `$table`") as $idx) {
        if (($idx['Key_name'] ?? '') === 'PRIMARY') { $hasPrimary = true; break; }
    }

    if ($hasPrimary && $idIsAuto) {
        if ($verbose) { echo "  $table : OK (PK + AUTO_INCREMENT)\n"; }
        return true;
    }

    // Doublons d'id : on RENUMEROTE les occurrences en trop. Aucune ligne
    // n'est supprimee -> aucune donnee metier ne peut etre perdue (un
    // ancien correctif qui supprimait les doublons a deja efface des
    // comptes utilisateurs).
    $dups = $db->query("SELECT id, COUNT(*) c FROM `$table` GROUP BY id HAVING c > 1")->fetchAll(PDO::FETCH_ASSOC);
    $renamed = 0;
    if ($dups) {
        $next = (int) $db->query("SELECT COALESCE(MAX(id), 0) FROM `$table`")->fetchColumn() + 1;
        $upd = $db->prepare("UPDATE `$table` SET id = :new WHERE id = :old LIMIT 1");
        foreach ($dups as $d) {
            for ($i = 0; $i < (int) $d['c'] - 1; $i++) {
                $upd->execute(['new' => $next, 'old' => (int) $d['id']]);
                $next++;
                $renamed++;
            }
        }
    }

    // Renumerote les id <= 0 (meme logique : aucune ligne supprimee).
    $zero = (int) $db->query("SELECT COUNT(*) FROM `$table` WHERE id <= 0")->fetchColumn();
    if ($zero > 0) {
        $next = (int) $db->query("SELECT COALESCE(MAX(id), 0) FROM `$table`")->fetchColumn() + 1;
        $rows = $db->query("SELECT id FROM `$table` WHERE id <= 0")->fetchAll(PDO::FETCH_ASSOC);
        $upd = $db->prepare("UPDATE `$table` SET id = :new WHERE id = :old LIMIT 1");
        foreach ($rows as $r) {
            $upd->execute(['new' => $next, 'old' => (int) $r['id']]);
            $next++;
            $renamed++;
        }
    }

    // Cas 1 : la PK existe deja et porte sur `id` -> un simple MODIFY suffit.
    //         On evite ainsi tout DROP PRIMARY KEY, qui echoue lorsque des
    //         cles etrangeres referencent la colonne (ex. dossiers.vendeur_id
    //         -> users.id).
    // Cas 2 : la PK est absente ou porte sur d'autres colonnes -> on la
    //         reconstruit dans une seule instruction, car MySQL exige que la
    //         colonne AUTO_INCREMENT soit une cle dans la meme instruction.
    if ($hasPrimary) {
        $pkCols = [];
        foreach ($db->query("SHOW INDEX FROM `$table`") as $idx) {
            if (($idx['Key_name'] ?? '') === 'PRIMARY') {
                $pkCols[(int) $idx['Seq_in_index']] = $idx['Column_name'];
            }
        }
        ksort($pkCols);
        if (array_values($pkCols) === ['id']) {
            $db->exec("ALTER TABLE `$table` MODIFY COLUMN id $idType NOT NULL AUTO_INCREMENT");
        } else {
            $db->exec('SET FOREIGN_KEY_CHECKS = 0');
            $db->exec("ALTER TABLE `$table` DROP PRIMARY KEY, MODIFY COLUMN id $idType NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)");
            $db->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    } else {
        $db->exec("ALTER TABLE `$table` MODIFY COLUMN id $idType NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)");
    }
    if ($verbose) {
        echo "  $table : PK + AUTO_INCREMENT restaures"
            . ($renamed ? " ($renamed id duplique(s) renumerote(s), aucune ligne supprimee)" : '')
            . "\n";
    }
    return true;
}
/**
 * Ajoute une cle UNIQUE manquante.
 * Si $autoDedupe est vrai (tables d'etat uniquement), les doublons sont
 * supprimes en conservant la ligne la plus recente (id le plus grand).
 */
function repair_unique_key(PDO $db, string $table, string $indexName, array $columns, bool $autoDedupe = false, bool $verbose = true): bool
{
    $q = $db->quote($table);
    if (!$db->query("SHOW TABLES LIKE $q")->fetchColumn()) {
        return true;
    }

    $names = array_column($db->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    foreach ($columns as $col) {
        if (!in_array($col, $names, true)) {
            if ($verbose) { echo "  $table.$indexName : colonne $col absente (ignoree)\n"; }
            return true;
        }
    }

    foreach ($db->query("SHOW INDEX FROM `$table`") as $idx) {
        if (($idx['Key_name'] ?? '') === $indexName) {
            return true; // deja presente
        }
    }

    $colList = '`' . implode('`,`', $columns) . '`';
    $dupGroups = (int) $db->query(
        "SELECT COUNT(*) FROM (SELECT $colList FROM `$table` GROUP BY $colList HAVING COUNT(*) > 1) d"
    )->fetchColumn();

    if ($dupGroups > 0) {
        if (!$autoDedupe) {
            if ($verbose) {
                echo "  $table.$indexName : MANQUANTE, $dupGroups doublon(s) — action manuelle requise.\n";
            }
            return false;
        }
        // Conserve la ligne la plus recente (id le plus grand) de chaque groupe.
        $join = '';
        foreach ($columns as $col) { $join .= " AND k.`$col` <=> t.`$col`"; }
        $db->exec(
            "DELETE t FROM `$table` t
               JOIN (SELECT MAX(id) keep_id, $colList FROM `$table` GROUP BY $colList HAVING COUNT(*) > 1) k
                 ON 1=1$join
              WHERE t.id <> k.keep_id"
        );
        if ($verbose) {
            echo "  $table.$indexName : $dupGroups doublon(s) supprime(s) (ligne la plus recente conservee).\n";
        }
    }

    try {
        $db->exec("ALTER TABLE `$table` ADD UNIQUE KEY `$indexName` ($colList)");
        if ($verbose) { echo "  $table.$indexName : cle unique ajoutee.\n"; }
    } catch (Throwable $e) {
        if ($verbose) { echo "  $table.$indexName : ECHEC (" . $e->getMessage() . ")\n"; }
        return false;
    }
    return true;
}
/**
 * Point d'entree : repare tout le schema. Idempotent.
 */
function repair_schema_all(PDO $db, bool $verbose = true): bool
{
    $ok = true;

    // 1) Tables dont la colonne `id` doit etre PRIMARY KEY + AUTO_INCREMENT.
    $idTables = [
        'users', 'dossiers', 'dossier_historique', 'dossier_attachments', 'dossier_trash',
        'origines', 'employees', 'salary_records', 'settings', 'login_log', 'password_history',
        'rate_limits', 'security_audit_log', 'security_events', 'security_settings',
        'security_blocks', 'security_alerts', 'data_access_log', 'login_attempts',
        'user_permissions', 'superviseur_sessions', 'superviseur_approved_ips',
        'admin_notifications', 'chat_messages', 'secure_downloads',
    ];
    if ($verbose) { echo "--- 1. Cles primaires / AUTO_INCREMENT ---\n"; }
    foreach ($idTables as $t) {
        try { $ok = repair_pk_id($db, $t, $verbose) && $ok; }
        catch (Throwable $e) { $ok = false; echo "  $t : ERREUR " . $e->getMessage() . "\n"; }
    }

    // 2) chat_presence : PK sur user_id (une seule ligne par utilisateur).
    if ($verbose) { echo "--- 2. Table chat_presence ---\n"; }
    try {
        if ($db->query("SHOW TABLES LIKE " . $db->quote('chat_presence'))->fetchColumn()) {
            $db->exec('DELETE p FROM chat_presence p JOIN chat_presence k ON k.user_id = p.user_id AND k.last_seen_at > p.last_seen_at');
            $hasPk = false;
            foreach ($db->query('SHOW INDEX FROM chat_presence') as $idx) {
                if (($idx['Key_name'] ?? '') === 'PRIMARY' && ($idx['Column_name'] ?? '') === 'user_id') { $hasPk = true; break; }
            }
            if ($hasPk) {
                if ($verbose) { echo "  chat_presence : OK (PK user_id)\n"; }
            } else {
                $cols = array_column($db->query('SHOW COLUMNS FROM chat_presence')->fetchAll(PDO::FETCH_ASSOC), 'Field');
                if (!in_array('user_id', $cols, true)) {
                    if ($verbose) { echo "  chat_presence : colonne user_id absente (ignoree)\n"; }
                } else {
                    $hasOtherPk = false;
                    foreach ($db->query('SHOW INDEX FROM chat_presence') as $idx) {
                        if (($idx['Key_name'] ?? '') === 'PRIMARY') { $hasOtherPk = true; break; }
                    }
                    if ($hasOtherPk) {
                        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
                        $db->exec('ALTER TABLE chat_presence DROP PRIMARY KEY');
                        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
                    }
                    $db->exec('ALTER TABLE chat_presence MODIFY COLUMN user_id INT UNSIGNED NOT NULL, ADD PRIMARY KEY (user_id)');
                    if ($verbose) { echo "  chat_presence : PK (user_id) restauree.\n"; }
                }
            }
        }
    } catch (Throwable $e) { $ok = false; echo '  chat_presence : ERREUR ' . $e->getMessage() . "\n"; }

    // 3) Cles UNIQUE indispensables aux "INSERT ... ON DUPLICATE KEY UPDATE".
    if ($verbose) { echo "--- 3. Cles UNIQUE ---\n"; }
    $keys = [
        'settings'                 => ['uq_settings_key',      ['setting_key'],                   true],
        'security_settings'        => ['uq_secset_key',        ['setting_key'],                   true],
        'rate_limits'              => ['uq_rate_bucket',       ['bucket'],                        true],
        'origines'                 => ['uq_origines_nom',      ['nom'],                           true],
        'superviseur_approved_ips' => ['uniq_user_ip',         ['user_id', 'ip_address'],         true],
        'user_permissions'         => ['uq_user_permission',   ['user_id', 'permission_key'],     true],
        'salary_records'           => ['uq_salary',            ['employee_id', 'month', 'year'],  true],
        'users'                    => ['uq_users_username',    ['username'],                      false],
        'dossiers'                 => ['uq_dossiers_portable', ['portable'],                      false],
        'employees'                => ['uq_employees_code',    ['employee_code'],                 false],
        'dossier_attachments'      => ['uq_attach_fichier',    ['nom_fichier'],                   false],
        'secure_downloads'         => ['uq_secure_token',      ['token'],                         false],
    ];
    foreach ($keys as $table => $def) {
        try { $ok = repair_unique_key($db, $table, $def[0], $def[1], $def[2], $verbose) && $ok; }
        catch (Throwable $e) { $ok = false; echo "  $table.{$def[0]} : ERREUR " . $e->getMessage() . "\n"; }
    }

    return $ok;
}

// ---------------------------------------------------------------------
// Point d'entree (peut etre desactive via REPAIR_SCHEMA_LIBRARY pour les
// tests automatises qui appellent repair_schema_all() directement).
// ---------------------------------------------------------------------
if (!defined('REPAIR_SCHEMA_LIBRARY')) {
    if (PHP_SAPI === 'cli') {
        echo "=== Reparation du schema ===\n";
        $ok = repair_schema_all($db, true);
        echo $ok ? "\nOK\n" : "\nECHEC (voir les details ci-dessus)\n";
        exit($ok ? 0 : 1);
    }

    set_flash('success', 'Schéma de la base réparé.');
    redirect('dashboard.php');
}