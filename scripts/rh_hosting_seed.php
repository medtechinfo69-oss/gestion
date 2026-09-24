<?php
/**
 * IMPORT DES DONNEES RH VERS L'HEBERGEMENT (vendeurs + employes + salaires).
 *
 * A executer DEPUIS le serveur : MySQL n'est pas joignable de l'exterieur
 * (voir README §1.4 bis). Le fichier s'utilise generalement via
 * `php scripts/rh_push.php` (qui l'envoie, l'appelle et le supprime), mais il
 * peut aussi etre copie a la racine du site et appele au navigateur :
 *
 *   /rh_hosting_seed.php?token=LE_JETON&vendeurs=oui
 *   /rh_hosting_seed.php?token=LE_JETON&employes=oui&salaires=oui
 *
 * Les donnees proviennent :
 *   - de la liste VENDEURS ci-dessous (identique a readme.txt) ;
 *   - du fichier JSON rh_export.json place a cote de ce script
 *     (produit par `php scripts/rh_push.php` a partir de la base locale).
 *
 * Operation IDEMPOTENTE : chaque ligne est mise a jour si elle existe
 * (vendeur par username, employe par employee_code, salaire par
 * employe + mois + annee). Aucune ligne existante n'est supprimee.
 *
 * Securite : sans le jeton exact, le script repond 404. SUPPRIMEZ ce fichier
 * du serveur apres l'import.
 */

// ---------------------------------------------------------------------
// Jeton secret (identique a celui du script local scripts/rh_push.php).
// ---------------------------------------------------------------------
$EXPECTED_TOKEN = 'rh-2026-Byet-b22-9d74f0ab';

$given = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
if ($given === '' || !hash_equals($EXPECTED_TOKEN, $given)) {
    http_response_code(404);
    exit('Not found');
}

// Le script doit fonctionner a la racine du site OU dans scripts/.
$baseDir = is_file(__DIR__ . '/database/install.sql') ? __DIR__ : dirname(__DIR__);

header('Content-Type: text/plain; charset=UTF-8');

define('APP_INIT', true);
require $baseDir . '/config/config.php';

// ---------------------------------------------------------------------
// VENDEURS — liste identique a readme.txt (username, nom, e-mail, mot de passe).
// ---------------------------------------------------------------------
$VENDEURS = [
    ['alice',     'Alice MATHEY',       'alice@5assur.com',      'Ven@Alice2026!'],
    ['laurence',  'Laurence Ferrari',   'laurence@5assur.com',   'Ven@Laurence2026!'],
    ['sonia',     'MARQUES Sonia',      'sonia@5assur.com',      'Ven@Sonia2026!'],
    ['christine', 'BERTRAND Christine', 'christine@5assur.com',  'Ven@Christine2026!'],
    ['nina',      'Nina Lagarde',       'nina@5assur.com',       'Ven@Nina2026!'],
    ['emilie',    'MARTINEZ Emilie',    'emilie@5assur.com',     'Ven@Emilie2026!'],
    ['eva',       'LEMOINE Eva',        'eva@5assur.com',        'Ven@Eva2026!'],
    ['rosa',      'GOMEZ Rosa',         'rosa@5assur.com',       'Ven@Rosa2026!'],
];

$doVendeurs = (string) ($_GET['vendeurs'] ?? '') === 'oui';
$doEmployes = (string) ($_GET['employes'] ?? '') === 'oui';
$doSalaires = (string) ($_GET['salaires'] ?? '') === 'oui';

if (!$doVendeurs && !$doEmployes && !$doSalaires) {
    exit("Rien a faire : ajoutez &vendeurs=oui, &employes=oui et/ou &salaires=oui.\n");
}

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 20,
    ]);
} catch (Throwable $e) {
    exit('Connexion a la base impossible : ' . $e->getMessage() . "\n");
}

echo '=== IMPORT RH — base ' . DB_NAME . ' sur ' . DB_HOST . " ===\n";

/** Retourne les colonnes d'une table (pour filtrer les donnees importees). */
function table_columns(PDO $pdo, string $table): array
{
    $cols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll() as $c) {
        $cols[] = (string) $c['Field'];
    }
    return $cols;
}

// ---------------------------------------------------------------------
// 1. Vendeurs
// ---------------------------------------------------------------------
if ($doVendeurs) {
    echo "\n--- Vendeurs ---\n";
    $find = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
    $insert = $pdo->prepare(
        "INSERT INTO users (username, password_hash, role, nom_complet, email, is_active, can_supervise,
                            must_change_password, last_password_change, failed_attempts, locked_until)
         VALUES (:u, :p, 'vendeur', :n, :e, 1, 1, 0, NOW(), 0, NULL)"
    );
    $update = $pdo->prepare(
        "UPDATE users SET password_hash = :p, role = 'vendeur', nom_complet = :n, email = :e,
                is_active = 1, can_supervise = 1, must_change_password = 0,
                last_password_change = NOW(), failed_attempts = 0, locked_until = NULL
          WHERE id = :id"
    );

    $created = 0;
    $updated = 0;
    foreach ($VENDEURS as [$username, $nom, $email, $password]) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $find->execute(['u' => $username]);
        $id = (int) $find->fetchColumn();
        if ($id > 0) {
            $update->execute(['p' => $hash, 'n' => $nom, 'e' => $email, 'id' => $id]);
            $updated++;
            printf("  MAJ   %-12s %-22s %s\n", $username, $nom, $email);
        } else {
            $insert->execute(['u' => $username, 'p' => $hash, 'n' => $nom, 'e' => $email]);
            $created++;
            printf("  CREE  %-12s %-22s %s (id %d)\n", $username, $nom, $email, (int) $pdo->lastInsertId());
        }
    }
    echo "Vendeurs : $created cree(s), $updated mis a jour.\n";
    echo 'Total comptes vendeurs en base : '
        . (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'vendeur'")->fetchColumn() . "\n";
}

// ---------------------------------------------------------------------
// 2. Employes et fiches de salaire (fichier JSON produit par rh_push.php)
// ---------------------------------------------------------------------
$exportPath = null;
foreach ([__DIR__ . '/rh_export.json', __DIR__ . '/_rh_export.json', $baseDir . '/database/rh_export.json'] as $candidate) {
    if (is_file($candidate)) {
        $exportPath = $candidate;
        break;
    }
}

$employees = [];
$salaries = [];
if ($doEmployes || $doSalaires) {
    if ($exportPath === null) {
        echo "\nFichier rh_export.json introuvable : employes et salaires ignores.\n";
        $doEmployes = false;
        $doSalaires = false;
    } else {
        $data = json_decode((string) file_get_contents($exportPath), true);
        $employees = is_array($data) ? ($data['employees'] ?? []) : [];
        $salaries = is_array($data) ? ($data['salaries'] ?? []) : [];
        echo "\nSource : $exportPath (" . count($employees) . ' employes, ' . count($salaries) . " fiches)\n";
    }
}

if ($doEmployes) {
    echo "\n--- Employes ---\n";
    $empCols = table_columns($pdo, 'employees');
    $find = $pdo->prepare('SELECT id FROM employees WHERE employee_code = :c LIMIT 1');
    $created = 0;
    $updated = 0;
    $skipped = 0;

    foreach ($employees as $row) {
        $row = array_intersect_key($row, array_flip($empCols));
        unset($row['id'], $row['updated_at']);
        $code = trim((string) ($row['employee_code'] ?? ''));
        if ($code === '' || trim((string) ($row['full_name'] ?? '')) === '') {
            $skipped++;
            continue;
        }
        $row['employee_code'] = $code;

        $find->execute(['c' => $code]);
        $id = (int) $find->fetchColumn();

        if ($id > 0) {
            $sets = [];
            $args = ['id' => $id];
            foreach ($row as $col => $value) {
                $sets[] = '`' . $col . '` = :' . $col;
                $args[$col] = $value;
            }
            $pdo->prepare('UPDATE employees SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($args);
            $updated++;
            printf("  MAJ   %-6s %-22s %-12s %.2f %s\n", $code, (string) $row['full_name'], (string) ($row['position'] ?? ''), (float) ($row['hourly_rate'] ?? 0), (string) ($row['status'] ?? ''));
        } else {
            $cols = array_keys($row);
            $ph = array_map(static fn(string $c): string => ':' . $c, $cols);
            $pdo->prepare('INSERT INTO employees (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', $ph) . ')')->execute($row);
            $created++;
            printf("  CREE  %-6s %-22s %-12s %.2f %s\n", $code, (string) $row['full_name'], (string) ($row['position'] ?? ''), (float) ($row['hourly_rate'] ?? 0), (string) ($row['status'] ?? ''));
        }
    }
    echo "Employes : $created cree(s), $updated mis a jour, $skipped ignore(s).\n";
    echo 'Total employes en base : ' . (int) $pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn() . "\n";
}

if ($doSalaires) {
    echo "\n--- Fiches de salaire ---\n";
    $salCols = table_columns($pdo, 'salary_records');

    // Correspondance matricule -> id sur l'hebergement (les identifiants locaux
    // ne sont pas transposables).
    $codeToId = [];
    foreach ($pdo->query('SELECT id, employee_code FROM employees')->fetchAll() as $r) {
        $codeToId[(string) $r['employee_code']] = (int) $r['id'];
    }

    $find = $pdo->prepare('SELECT id FROM salary_records WHERE employee_id = :e AND month = :m AND year = :y LIMIT 1');
    $created = 0;
    $updated = 0;
    $skipped = 0;

    foreach ($salaries as $row) {
        $code = trim((string) ($row['employee_code'] ?? ''));
        if ($code === '' || !isset($codeToId[$code])) {
            $skipped++;
            continue;
        }

        $row['employee_id'] = $codeToId[$code];
        $row = array_intersect_key($row, array_flip($salCols));
        unset($row['id'], $row['updated_at']);
        if (!isset($row['month'], $row['year'])) {
            $skipped++;
            continue;
        }

        $find->execute(['e' => $row['employee_id'], 'm' => $row['month'], 'y' => $row['year']]);
        $id = (int) $find->fetchColumn();

        if ($id > 0) {
            $sets = [];
            $args = ['id' => $id];
            foreach ($row as $col => $value) {
                $sets[] = '`' . $col . '` = :' . $col;
                $args[$col] = $value;
            }
            $pdo->prepare('UPDATE salary_records SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($args);
            $updated++;
            printf("  MAJ   %-6s %02d/%d  %.2f h  %.2f\n", $code, (int) $row['month'], (int) $row['year'], (float) ($row['total_hours'] ?? 0), (float) ($row['calculated_salary'] ?? 0));
        } else {
            $cols = array_keys($row);
            $ph = array_map(static fn(string $c): string => ':' . $c, $cols);
            $pdo->prepare('INSERT INTO salary_records (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', $ph) . ')')->execute($row);
            $created++;
            printf("  CREE  %-6s %02d/%d  %.2f h  %.2f\n", $code, (int) $row['month'], (int) $row['year'], (float) ($row['total_hours'] ?? 0), (float) ($row['calculated_salary'] ?? 0));
        }
    }
    echo "Fiches de salaire : $created creee(s), $updated mise(s) a jour, $skipped ignoree(s).\n";
    echo 'Total fiches en base : ' . (int) $pdo->query('SELECT COUNT(*) FROM salary_records')->fetchColumn() . "\n";
}

echo "\n=== FIN DE L'IMPORT ===\n";
echo "\n>>> SUPPRIMER CE FICHIER ET rh_export.json DU SERVEUR MAINTENANT <<<\n";


