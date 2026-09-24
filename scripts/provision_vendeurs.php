<?php
/**
 * Provisionnement initial des comptes vendeur + génération de readme.txt.
 *
 * - Crée / met à jour les comptes vendeur (mot de passe conforme à la
 *   politique : 12+ caractères, ASCII, majuscule, minuscule, chiffre,
 *   caractère spécial).
 * - Active tous les vendeurs (can_supervise = 1, is_active = 1) :
 *   l'option « Contact seul » a été supprimée.
 * - Pré-approuve les IP locales 127.0.0.1 et ::1 (la première connexion
 *   déclenche sinon une demande d'approbation côté administrateur).
 * - Régénère readme.txt (identifiants en clair) à la racine du projet.
 *
 * Usage : php scripts/provision_vendeurs.php
 * À n'utiliser qu'au provisionnement : relancer réinitialise les mots de
 * passe documentés dans readme.txt.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Accès réservé à la ligne de commande.');
}

define('APP_INIT', 1);
require __DIR__ . '/../config/config.local.php';
require __DIR__ . '/../includes/functions.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

// username et email sont uniques en base : les lignes existantes sont
// mises à jour, les autres créées. Les deux dernières entrées réactivent
// les vendeurs historiques hors « liste des 9 ».
$accounts = [
    ['username' => 'alice',          'nom' => 'Alice MATHEY',       'email' => 'alice@5assur.com',          'password' => 'Ven@Alice2026!'],
    ['username' => 'laurence',       'nom' => 'Laurence Ferrari',   'email' => 'laurence@5assur.com',       'password' => 'Ven@Laurence2026!'],
    ['username' => 'sonia',          'nom' => 'MARQUES Sonia',      'email' => 'sonia@5assur.com',          'password' => 'Ven@Sonia2026!'],
    ['username' => 'christine',      'nom' => 'BERTRAND Christine', 'email' => 'christine@5assur.com',      'password' => 'Ven@Christine2026!'],
    ['username' => 'nina',           'nom' => 'Nina Lagarde',       'email' => 'nina@5assur.com',           'password' => 'Ven@Nina2026!'],
    ['username' => 'emilie',         'nom' => 'MARTINEZ Emilie',    'email' => 'emilie@5assur.com',         'password' => 'Ven@Emilie2026!'],
    ['username' => 'eva',            'nom' => 'LEMOINE Eva',        'email' => 'eva@5assur.com',            'password' => 'Ven@Eva2026!'],
    ['username' => 'emma.chevalier', 'nom' => 'CHEVALIER Emma',     'email' => 'emma.chevalier@5assur.com', 'password' => 'Ven@Emma2026!'],
    ['username' => 'rosa',           'nom' => 'GOMEZ Rosa',         'email' => 'rosa@5assur.com',           'password' => 'Ven@Rosa2026!'],
    ['username' => 'helene',         'nom' => 'Hélène',             'email' => 'helene@5assur.com',         'password' => 'Ven@Helene2026!'],
    ['username' => 'justine',        'nom' => 'Justine',            'email' => 'justine@5assur.com',        'password' => 'Ven@Justine2026!'],
];

// Contrôle de politique AVANT toute écriture en base.
foreach ($accounts as $account) {
    $policyErrors = password_policy_errors($account['password']);
    if ($policyErrors) {
        fwrite(STDERR, "ERREUR ({$account['username']}) : mot de passe non conforme : " . implode(', ', $policyErrors) . PHP_EOL);
        exit(1);
    }
}

$find   = $pdo->prepare("SELECT id, role FROM users WHERE username = :u");
$update = $pdo->prepare(
    "UPDATE users SET nom_complet = :nom, email = :email, password_hash = :pwd,
            role = 'vendeur', is_active = 1, can_supervise = 1,
            must_change_password = 0, last_password_change = NOW(),
            failed_attempts = 0, locked_until = NULL
      WHERE id = :id"
);
$insert = $pdo->prepare(
    "INSERT INTO users (username, password_hash, role, nom_complet, email, is_active, can_supervise, must_change_password, last_password_change)
     VALUES (:u, :pwd, 'vendeur', :nom, :email, 1, 1, 0, NOW())"
);

$vendeurIds = [];
foreach ($accounts as $account) {
    $hash = password_hash($account['password'], PASSWORD_DEFAULT);
    $find->execute(['u' => $account['username']]);
    $row = $find->fetch();
    if ($row) {
        if ($row['role'] !== 'vendeur') {
            fwrite(STDERR, "ERREUR : '{$account['username']}' existe déjà avec le rôle '{$row['role']}' — abandon." . PHP_EOL);
            exit(1);
        }
        $update->execute(['nom' => $account['nom'], 'email' => $account['email'], 'pwd' => $hash, 'id' => $row['id']]);
        $id = (int) $row['id'];
        echo "MAJ   {$account['username']} (id {$id})" . PHP_EOL;
    } else {
        $insert->execute(['u' => $account['username'], 'pwd' => $hash, 'nom' => $account['nom'], 'email' => $account['email']]);
        $id = (int) $pdo->lastInsertId();
        echo "CREE  {$account['username']} (id {$id})" . PHP_EOL;
    }
    $vendeurIds[$id] = $account;
}

// Filet de sécurité : tout vendeur est connectable et actif.
$pdo->exec("UPDATE users SET can_supervise = 1, is_active = 1 WHERE role = 'vendeur'");

// IP locales pré-approuvées (schema : UNIQUE (user_id, ip_address)).
$adminId = (int) $pdo->query("SELECT id FROM users WHERE username = 'admin'")->fetchColumn() ?: null;
$approve = $pdo->prepare(
    "INSERT INTO superviseur_approved_ips (user_id, ip_address, user_agent, label, approved_by)
     VALUES (:uid, :ip, '', 'Local (pré-approuvé)', :admin)
     ON DUPLICATE KEY UPDATE label = 'Local (pré-approuvé)'"
);
foreach (array_keys($vendeurIds) as $uid) {
    foreach (['127.0.0.1', '::1'] as $ip) {
        $approve->execute(['uid' => $uid, 'ip' => $ip, 'admin' => $adminId ?: null]);
    }
}
echo 'IP locales 127.0.0.1 / ::1 pré-approuvées pour ' . count($vendeurIds) . ' vendeur(s).' . PHP_EOL;

// ---------------------------------------------------------------------
// readme.txt — identifiants vérifiés par password_verify contre la base.
// ---------------------------------------------------------------------
$known = [
    'admin' => 'Admin@2026',
    'emma'  => 'Superviseur@2026',
    'rabia' => 'Superviseur@2026',
];
$otherRows = $pdo->query(
    "SELECT username, role, nom_complet, password_hash FROM users WHERE role IN ('admin','superviseur') ORDER BY id"
)->fetchAll();

$pad = static function (string $s, int $len): string {
    return $s . str_repeat(' ', max(0, $len - mb_strlen($s)));
};

$lines = [];
$lines[] = '=====================================================================';
$lines[] = ' GESTION DES DOSSIERS - IDENTIFIANTS ET MOTS DE PASSE';
$lines[] = '=====================================================================';
$lines[] = ' Fichier  : readme.txt (confidentiel - accès web bloqué par .htaccess,';
$lines[] = '                         ignoré par git via .gitignore)';
$lines[] = ' Généré le : ' . date('d/m/Y H:i');
$lines[] = ' URL locale : http://localhost/gestion-dossiers-new/login.php';
$lines[] = ' URL prod   : https://assurialis-app.byethost22.com/login.php';
$lines[] = '---------------------------------------------------------------------';
$lines[] = ' HEBERGEMENT PRODUCTION - ByetHost (compte b22_42998523)';
$lines[] = '---------------------------------------------------------------------';
$lines[] = ' FTP   : ftpupload.net:21 - utilisateur b22_42998523 (/htdocs/)';
$lines[] = ' MySQL : sql206.byethost22.com:3306';
$lines[] = ' Base  : b22_42998523_assurialis - utilisateur b22_42998523';
$lines[] = ' Identifiants : .vscode/sftp.json (FTP), config/config.hosting.php (MySQL)';
$lines[] = ' Deploiement  : php scripts/deploy_ftp.php upload';
$lines[] = ' Import SQL   : scripts/db_setup.php?token=...&confirm=oui (serveur)';
$lines[] = '---------------------------------------------------------------------';
$lines[] = ' ADMINISTRATEUR / SUPERVISEURS';
$lines[] = '---------------------------------------------------------------------';
foreach ($otherRows as $row) {
    $documented = 'mot de passe modifié - non documenté ici';
    if (isset($known[$row['username']]) && password_verify($known[$row['username']], $row['password_hash'])) {
        $documented = $known[$row['username']];
    }
    $lines[] = $pad($row['username'], 8) . $pad($row['nom_complet'], 16) . $pad($row['role'], 14) . $documented;
}
$lines[] = '';
$lines[] = '---------------------------------------------------------------------';
$lines[] = ' VENDEURS (tous actifs, connectables, IP locales pré-approuvées)';
$lines[] = '---------------------------------------------------------------------';
$lines[] = $pad('Nom', 22) . $pad('Identifiant', 17) . $pad('E-mail', 28) . 'Mot de passe';
foreach ($accounts as $account) {
    $lines[] = $pad($account['nom'], 22) . $pad($account['username'], 17) . $pad($account['email'], 28) . $account['password'];
}
$lines[] = '';
$lines[] = '---------------------------------------------------------------------';
$lines[] = ' APPROBATION ADRESSE IP';
$lines[] = '---------------------------------------------------------------------';
$lines[] = ' 1. Première connexion depuis une IP inconnue : la session est mise';
$lines[] = '    en attente (« Session en attente d approbation »).';
$lines[] = ' 2. L administrateur ouvre Vendeurs puis approuve la demande dans';
$lines[] = '    « Demandes de session en attente ».';
$lines[] = ' 3. 127.0.0.1 et ::1 (machine locale) sont déjà approuvés : aucune';
$lines[] = '    étape supplémentaire depuis ce poste.';
$lines[] = '';
$lines[] = '---------------------------------------------------------------------';
$lines[] = ' POLITIQUE DE MOT DE PASSE';
$lines[] = '---------------------------------------------------------------------';
$lines[] = ' 12 caractères minimum, majuscule, minuscule, chiffre, caractère';
$lines[] = ' spécial ASCII, mot de passe non courant. Changement via Profil.';
$lines[] = '=====================================================================';

$readmePath = dirname(__DIR__) . '/readme.txt';
file_put_contents($readmePath, implode(PHP_EOL, $lines) . PHP_EOL);
echo 'readme.txt regeneré : ' . $readmePath . PHP_EOL;
echo 'Terminé.' . PHP_EOL;

