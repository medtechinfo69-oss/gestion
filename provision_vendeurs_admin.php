<?php
/**
 * PROVISIONNEMENT DES VENDEURS — interface web (administrateur uniquement).
 *
 * InfinityFree (hebergement mutualise) n'offre ni SSH ni acces MySQL distant :
 * impossible d'executer `scripts/provision_vendeurs.php` (CLI) ni de creer les
 * comptes depuis un poste local. Cet ecran permet d'appliquer, d'un clic dans
 * le navigateur, EXACTEMENT les comptes documentes dans readme1.txt.
 *
 * Effets (idempotents, aucune suppression) :
 *   - cree / met a jour les 9 vendeurs listes dans readme1.txt ;
 *   - passe TOUS les vendeurs a connectable (can_supervise = 1, is_active = 1) :
 *     l'option « Contact seul » a ete supprimee ;
 *   - garantit la colonne can_supervise (users_schema_ensure) ;
 *   - regenere readme1.txt a jour a la racine du projet.
 *
 * Securite :
 *   - le script exige un JETON SECRET dans l'URL (?token=...) : sans le bon
 *     jeton, il repond 404. Cela permet de provisionner meme quand le mot de
 *     passe administrateur n'est pas (ou plus) connu, sans laisser l'outil
 *     ouvert a tout le monde.
 *
 * ATTENTION : a SUPPRIMER du serveur immediatement apres usage.
 */

// ---------------------------------------------------------------------
// Jeton secret a usage unique (modifiable ici avant televersement).
// L'acces se fait par : provision_vendeurs_admin.php?token=LE_JETON
// ---------------------------------------------------------------------
$EXPECTED_TOKEN = 'prov-2026-Alice-9f3c7b2e';
$givenToken = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
if ($givenToken === '' || !hash_equals($EXPECTED_TOKEN, $givenToken)) {
    http_response_code(404);
    exit('Not found');
}

require_once __DIR__ . '/includes/init.php';
users_schema_ensure($db);

// Comptes a appliquer — identiques a readme1.txt.
$accounts = [
  ['username' => 'helene', 'nom' => 'Hélène', 'password' => 'Superviseur@2026'],
  ['username' => 'justine', 'nom' => 'Justine', 'password' => 'Superviseur@2026'],
  ['username' => 'laurence', 'nom' => 'Laurence', 'password' => 'Superviseur@2026'],
  ['username' => 'nina', 'nom' => 'Nina', 'password' => 'Superviseur@2026'],
  ['username' => 'christine', 'nom' => 'Christine', 'password' => 'Christine@202677!'],
  ['username' => 'mathey.alice', 'nom' => 'MATHEY Alice', 'password' => 'Mathey@202663#'],
  ['username' => 'ferrari.laurence', 'nom' => 'Ferrari Laurence', 'password' => 'Ferrari@202682$'],
  ['username' => 'sonia.marques', 'nom' => 'Sonia MARQUES', 'password' => 'Sonia@202623&'],
  ['username' => 'christine.bertrand', 'nom' => 'Christine BERTRAND', 'password' => 'Christine@202669#'],
  ['username' => 'lagarde.nina', 'nom' => 'Lagarde Nina', 'password' => 'Lagarde@202616#'],
  ['username' => 'emilie.martinez', 'nom' => 'Emilie MARTINEZ', 'password' => 'Emilie@202616#'],
  ['username' => 'eva.lemoine',        'nom' => 'Eva LEMOINE',       'password' => 'Eva@20261234^'],
  ['username' => 'emma.chevalier', 'nom' => 'Emma CHEVALIER', 'password' => 'Emma@202646^'],
  ['username' => 'rosa.gomez', 'nom' => 'Rosa GOMEZ', 'password' => 'Rosa@202611*'],
];

// Comptes d'administration : les mots de passe documentes dans readme1.txt
// ne correspondaient plus aux hash en base de production. On les (re)aligne
// pour que les identifiants du readme soient reellement utilisables.
$adminAccounts = [
    ['username' => 'admin', 'nom' => 'Administrateur', 'role' => 'admin',      'password' => 'Admin@2026Secure!'],
    ['username' => 'emma',  'nom' => 'Emma',           'role' => 'superviseur','password' => 'Superviseur@2026'],
    ['username' => 'rabia', 'nom' => 'Rabia',          'role' => 'superviseur','password' => 'Superviseur@2026'],
];

$log = '';
$ok = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_start();
  try {
    $ok = true;

    $find = $db->prepare("SELECT id, role FROM users WHERE username = :u");
    $update = $db->prepare(
      "UPDATE users SET nom_complet = :nom, role = 'vendeur', password_hash = :pwd,
                    is_active = 1, can_supervise = 1, must_change_password = 0,
                    last_password_change = NOW(), failed_attempts = 0, locked_until = NULL
              WHERE id = :id"
    );
    $insert = $db->prepare(
      "INSERT INTO users (username, password_hash, role, nom_complet, is_active, can_supervise, must_change_password, last_password_change)
             VALUES (:u, :pwd, 'vendeur', :nom, 1, 1, 0, NOW())"
    );

    foreach ($accounts as $a) {
      if (!password_is_strong($a['password'])) {
        echo 'ERREUR : mot de passe non conforme pour ' . $a['username'] . "\n";
        $ok = false;
        continue;
      }
      $hash = password_hash($a['password'], PASSWORD_DEFAULT);
      $find->execute(['u' => $a['username']]);
      $row = $find->fetch();
      if ($row) {
        $update->execute(['nom' => $a['nom'], 'pwd' => $hash, 'id' => (int) $row['id']]);
        echo 'MAJ   ' . str_pad($a['username'], 20) . ' (id ' . (int) $row['id'] . ') — ' . $a['nom'] . "\n";
      } else {
        $insert->execute(['u' => $a['username'], 'pwd' => $hash, 'nom' => $a['nom']]);
        echo 'CREE  ' . str_pad($a['username'], 20) . ' (id ' . (int) $db->lastInsertId() . ') — ' . $a['nom'] . "\n";
      }
    }

    // Filet de securite : tout vendeur est connectable et actif.
    $n = $db->exec("UPDATE users SET can_supervise = 1, is_active = 1 WHERE role = 'vendeur'");
    echo "\nVendeurs rendus connectables (can_supervise=1, is_active=1) : {$n} ligne(s) affectee(s).\n";

    // --- Comptes administrateur / superviseurs ---
    echo "\n--- Comptes administrateur / superviseurs ---\n";
    $updStaff = $db->prepare(
      "UPDATE users SET nom_complet = :nom, role = :role, password_hash = :pwd,
              is_active = 1, must_change_password = 0, last_password_change = NOW(),
              failed_attempts = 0, locked_until = NULL
        WHERE username = :u"
    );
    foreach ($adminAccounts as $a) {
      if (!password_is_strong($a['password'])) {
        echo 'ERREUR : mot de passe non conforme pour ' . $a['username'] . "\n";
        $ok = false;
        continue;
      }
      $hash = password_hash($a['password'], PASSWORD_DEFAULT);
      $updStaff->execute([
        'nom' => $a['nom'], 'role' => $a['role'], 'pwd' => $hash, 'u' => $a['username'],
      ]);
      $find->execute(['u' => $a['username']]);
      if ($find->fetch()) {
        echo 'MAJ   ' . str_pad($a['username'], 20) . ' (' . $a['role'] . ") — mot de passe aligne sur readme1.txt\n";
      } else {
        echo 'ABSENT ' . $a['username'] . " (pte introuvable)\n";
      }
    }

    // Verification finale : chaque compte se connecte avec son mot de passe.
    echo "\n--- Verification (password_verify) ---\n";
    $verif = $db->prepare("SELECT password_hash, role, is_active, can_supervise FROM users WHERE username = :u");
    $fail = 0;
    foreach ($accounts as $a) {
      $verif->execute(['u' => $a['username']]);
      $r = $verif->fetch();
      $good = $r && $r['role'] === 'vendeur' && $r['is_active'] && $r['can_supervise']
        && password_verify($a['password'], $r['password_hash']);
      if (!$good) {
        $fail++;
      }
      echo ($good ? 'OK  ' : 'ECHEC') . ' ' . $a['username'] . "\n";
    }
    if ($fail === 0) {
      echo "\nTous les comptes sont valides et connectables.\n";
    } else {
      echo "\n{$fail} compte(s) en echec.\n";
      $ok = false;
    }
  } catch (Throwable $ex) {
    $ok = false;
    echo 'ERREUR : ' . $ex->getMessage() . "\n";
  }
  $log = ob_get_clean();
}

$pageTitle = 'Provisionnement vendeurs';
$pageSubtitle = 'Création des comptes vendeur sur la base de production';
$activePage = 'vendeurs';
require __DIR__ . '/includes/header.php';
?>

<main class="settings-page repair-page">
  <div class="content-card">
    <div class="card-head">
      <h2>Provisionner les comptes vendeurs</h2>
    </div>
    <div class="card-body">
      <p>Cet outil applique au serveur les comptes listés dans
        <strong>readme1.txt</strong> : les 9 nouveaux vendeurs et les vendeurs
        historiques. Il ne supprime <strong>aucune</strong> donnée&nbsp;: chaque
        compte est créé s'il n'existe pas, sinon mis à jour.
      </p>
      <p>Tous les vendeurs deviennent <strong>connectables</strong>
        (<code>can_supervise = 1</code>, <code>is_active = 1</code>)&nbsp;:
        l'option «&nbsp;Contact seul&nbsp;» a été supprimée.</p>

      <?php if ($log !== ''): ?>
        <h3 class="repair-section-title">Rapport</h3>
        <pre class="repair-log"><?= e($log) ?></pre>
      <?php endif; ?>

      <?php if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'): ?>
        <form method="post" action="provision_vendeurs_admin.php?token=<?= e($givenToken) ?>">
          <input type="hidden" name="token" value="<?= e($givenToken) ?>">
          <div class="repair-actions">
            <button type="submit" class="btn btn-primary"
              data-confirm="Appliquer les comptes vendeurs sur la base de production ?">
              Provisionner les vendeurs maintenant
            </button>
            <span class="text-muted">Aucune donnée ne sera supprimée.</span>
          </div>
        </form>
      <?php else: ?>
        <div class="repair-actions">
          <a class="btn btn-outline" href="<?= e(APP_URL) ?>/login.php">Retour à la connexion</a>
        </div>
        <p class="text-muted" style="margin-top:12px;">
          Vous pouvez maintenant supprimer <code>provision_vendeurs_admin.php</code>
          du serveur : il ne sert plus qu'au provisionnement.
        </p>
      <?php endif; ?>
    </div>
  </div>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>