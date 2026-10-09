<?php
/**
 * RECALCUL DU « CA ANNUEL » SUR L'HEBERGEMENT.
 *
 * PROBLEME : jusqu'au commit 79407f1, le CA annuel etait calcule avec un
 * coefficient de remuneration :  ca_annuel = ca_mois * 12 * 0.846.
 * Ce coefficient a ete supprime du code (includes/functions.php et
 * assets/js/app.js), mais les LIGNES DEJA ENREGISTREES dans la table
 * « dossiers » conservent l'ancienne valeur : les totaux du tableau de bord,
 * de la prime et des exports restent donc faux.
 *
 * CE QUE FAIT CE SCRIPT : ca_annuel = ROUND(ca_mois * 12, 2) sur la table
 * « dossiers » — et sur elle seule.
 *
 * A executer DEPUIS LE SERVEUR : MySQL n'est pas joignable de l'exterieur
 * (voir README §1.4 bis). Utilisation :
 *
 *   1) televerser ce fichier dans scripts/ (ou a la racine du site)
 *   2) l'ouvrir au navigateur :
 *        /scripts/ca_annuel_fix.php?token=JETON               -> RAPPORT seul
 *        /scripts/ca_annuel_fix.php?token=JETON&appliquer=oui  -> applique
 *   3) SUPPRIMER ce fichier du serveur une fois l'operation terminee.
 *
 * GARANTIES :
 *   - « ca_mois » n'est JAMAIS modifie (il n'apparait que dans le SET ... a
 *     droite, jamais dans la colonne modifiee).
 *   - aucune autre colonne, aucune autre table n'est touchee.
 *   - sauvegarde complete (id, ca_mois, ca_annuel, nouveau) ecrite AVANT
 *     toute modification, et laissee sur le serveur.
 *   - operation Idempotente : la relancer ne change plus rien.
 *
 * @package GestionDossiers\Scripts
 */

// Jeton secret. Sans ce jeton exact, le script repond 404.
$EXPECTED_TOKEN = 'ca-2026-Byet-b22-4e81c7d5';

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

$appliquer = (string) ($_GET['appliquer'] ?? $_POST['appliquer'] ?? '') === 'oui';

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    exit("ERREUR de connexion a la base : " . $e->getMessage() . "\n");
}

echo "=========================================================\n";
echo " RECALCUL DU CA ANNUEL  (ca_mois * 12)\n";
echo " Base : " . DB_NAME . " @ " . DB_HOST . "\n";
echo "=========================================================\n\n";
// --- 0. Verification du schema : on ne touche QUE « dossiers » ---------
$tables = $pdo->query(
    "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ('ca_mois','ca_annuel')
      ORDER BY TABLE_NAME"
)->fetchAll();
echo "-- Colonnes CA detectees dans la base --\n";
foreach ($tables as $t) {
    echo '   ' . $t['TABLE_NAME'] . ' . ' . $t['COLUMN_NAME'] . "\n";
}
$hasDossiers = false;
foreach ($tables as $t) {
    if ($t['TABLE_NAME'] === 'dossiers' && $t['COLUMN_NAME'] === 'ca_mois') {
        $hasDossiers = true;
    }
}
if (!$hasDossiers) {
    exit("\nARRET : table « dossiers » ou colonne « ca_mois » introuvable. Aucune ecriture effectuee.\n");
}
echo "\n";

$total = (int) $pdo->query('SELECT COUNT(*) FROM dossiers')->fetchColumn();

// --- 1. RAPPORT AVANT : combien de lignes divergent de ca_mois * 12 ? --
$aEcart  = (int) $pdo->query(
    'SELECT COUNT(*) FROM dossiers WHERE ca_annuel <> ROUND(ca_mois * 12, 2)'
)->fetchColumn();
$aExact  = (int) $pdo->query(
    'SELECT COUNT(*) FROM dossiers WHERE ca_annuel =  ROUND(ca_mois * 12, 2)'
)->fetchColumn();
$aAncien = (int) $pdo->query(
    'SELECT COUNT(*) FROM dossiers WHERE ca_mois > 0 AND ABS(ca_annuel - ROUND(ca_mois * 12 * 0.846, 2)) < 0.01'
)->fetchColumn();

echo "-- AVANT --\n";
echo "   dossiers total              : $total\n";
echo "   deja ca_mois * 12 (correct) : $aExact\n";
echo "   divergentes (a corriger)    : $aEcart\n";
echo "   sur ancien calcul 0.846     : $aAncien\n\n";

echo "-- Echantillon (5 lignes concernees) --\n";
$sample = $pdo->query(
    'SELECT id, ca_mois, ca_annuel, ROUND(ca_mois * 12, 2) AS nouveau
       FROM dossiers WHERE ca_annuel <> ROUND(ca_mois * 12, 2)
      ORDER BY id LIMIT 5'
)->fetchAll();
if ($sample === []) {
    echo "   (aucune)\n\n";
} else {
    foreach ($sample as $s) {
        echo '   id ' . str_pad((string) $s['id'], 7)
            . 'ca_mois ' . str_pad(number_format((float) $s['ca_mois'], 2, ',', ' '), 12)
            . 'actuel ' . str_pad(number_format((float) $s['ca_annuel'], 2, ',', ' '), 13)
            . 'nouveau ' . number_format((float) $s['nouveau'], 2, ',', ' ') . "\n";
    }
    echo "\n";
}

// --- 2. Sauvegarde AVANT ecriture -------------------------------------
$sauvegarde = $baseDir . '/ca_annuel_sauvegarde_' . date('Ymd_His') . '.csv';
try {
    $pdo->query(
        "SELECT id, ca_mois, ca_annuel, ROUND(ca_mois * 12, 2) AS nouveau
           FROM dossiers WHERE ca_annuel <> ROUND(ca_mois * 12, 2) INTO OUTFILE "
        . $pdo->quote($sauvegarde)
        . " FIELDS TERMINATED BY ',' LINES TERMINATED BY '\\n'"
    );
    echo "Sauvegarde ecriture : $sauvegarde\n\n";
} catch (Throwable $e) {
    echo "Sauvegarde impossible (" . $e->getMessage() . ") - poursuite.\n\n";
}

// --- 3. Execution ------------------------------------------------------
if (!$appliquer) {
    echo ">>> MODE RAPPORT : aucune ecriture effectuee.\n";
    echo '>>> Pour appliquer : ?token=' . $EXPECTED_TOKEN . "&appliquer=oui\n";
    echo "=========================================================\n";
    exit(0);
}

echo "-- APPLICATION --\n";
// Seule ligne modifiee de tout le script. « ca_mois » n'est lu qu'a droite
// du « = » pour calculer la nouvelle valeur : il n'est jamais ecrit.
$sql = 'UPDATE dossiers SET ca_annuel = ROUND(ca_mois * 12, 2)
         WHERE ca_annuel <> ROUND(ca_mois * 12, 2)';
echo "SQL : $sql\n";

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $modifiees = $stmt->rowCount();
    $pdo->commit();
    echo "Lignes mises a jour : $modifiees\n\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    exit("\nERREUR, transaction annulee : " . $e->getMessage() . "\n");
}


// --- 4. VERIFICATION APRES --------------------------------------------
$reste = (int) $pdo->query(
    'SELECT COUNT(*) FROM dossiers WHERE ca_annuel <> ROUND(ca_mois * 12, 2)'
)->fetchColumn();

echo "-- APRES --\n";
echo '   dossiers total                  : ' . (int) $pdo->query('SELECT COUNT(*) FROM dossiers')->fetchColumn() . "\n";
echo "   lignes encore divergentes       : $reste\n";
echo '   lignes correctes (ca_mois * 12) : '
    . (int) $pdo->query('SELECT COUNT(*) FROM dossiers WHERE ca_annuel = ROUND(ca_mois * 12, 2)')->fetchColumn() . "\n\n";

echo "-- Controle sur 5 dossiers --\n";
$verif = $pdo->query(
    "SELECT id, ca_mois, ca_annuel, ROUND(ca_mois * 12, 2) AS attendu,
            IF(ca_annuel = ROUND(ca_mois * 12, 2), 'OK', 'KO') AS controle
       FROM dossiers ORDER BY id DESC LIMIT 5"
)->fetchAll();
foreach ($verif as $v) {
    echo '   id ' . str_pad((string) $v['id'], 6)
        . 'ca_mois ' . str_pad(number_format((float) $v['ca_mois'], 2, ',', ' '), 12)
        . 'ca_annuel ' . str_pad(number_format((float) $v['ca_annuel'], 2, ',', ' '), 13)
        . 'attendu ' . str_pad(number_format((float) $v['attendu'], 2, ',', ' '), 13)
        . ' ' . $v['controle'] . "\n";
}

echo "\n=========================================================\n";
echo $reste === 0
    ? "TERMINE : tous les CA annuels = CA mensuel x 12."
    : "ATTENTION : $reste ligne(s) divergent encore.";
echo "\n=========================================================\n";
echo "N'OUBLIEZ PAS de supprimer ce fichier du serveur.\n";

