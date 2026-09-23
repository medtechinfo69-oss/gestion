<?php
/**
 * REPARATION DU SCHEMA DE LA BASE — interface web (administrateur uniquement).
 *
 * InfinityFree (et la plupart des hebergeurs mutualises) n'offre pas d'acces
 * SSH : impossible d'executer « php database/repair_schema.php ». Ce script
 * permet donc de lancer la meme reparation depuis le navigateur, d'un simple
 * clic, sans ligne de commande.
 *
 * Fonctionnement :
 *   - GET  : affiche un ecran d'information et un bouton de confirmation ;
 *   - POST : execute database/repair_schema.php et affiche le rapport.
 *
 * Deuxieme outil (carte « Nettoyage du chat (urgence) ») : supprime les
 * conversations du chat (tables chat_messages / chat_presence) quand la
 * table est corrompue ou sature l'espace de l'hebergement. Voir
 * chat_cleanup_all() dans includes/chat.php — aucune table metier
 * (dossiers, comptes, ...) n'est touchee.
 *
 * Troisieme outil (carte « Sauvegardes & restauration JSON ») : exporte
 * toute la base dans un fichier JSON (actions/backup_export.php) et la
 * restaure depuis un fichier (actions/backup_import.php). L'import est
 * un UPSERT : les lignes existantes sont mises a jour, jamais effacees.
 *
 * Securite :
 *   - require_admin() : reserve aux administrateurs connectes ;
 *   - csrf_require()  : la reparation ne s'execute qu'a la soumission du
 *     formulaire (protection CSRF contre une declenchement involontaire) ;
 *   - database/repair_schema.php reste inaccessible en direct (database/.htaccess) :
 *     seul cet ecran, protege, peut l'appeler.
 *
 * La reparation est NON destructive et idempotente : elle restaure les
 * PRIMARY KEY / AUTO_INCREMENT / cles UNIQUE perdues et ne supprime aucune
 * ligne metier. Elle peut etre relancee sans risque autant de fois que
 * necessaire. Elle corrige notamment : erreurs d'envoi du chat, doublons
 * de parametres, « Selectionnez au moins un vendeur », echecs d'export.
 */

require_once __DIR__ . '/includes/init.php';
require_admin();

$pageTitle = 'Réparation du schéma';
$pageSubtitle = 'Outil de maintenance de la base de données';
$activePage = 'repair_schema';

$log = '';
$ok = null;
$repairWarnings = 0;
$cleanLog = '';
$cleanOk = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string) ($_POST['do'] ?? 'repair');

    if ($action === 'chat_cleanup') {
        // Nettoyage URGENT du chat (conversations + presence).
        $keepDays = max(0, (int) ($_POST['keep_days'] ?? 0));
        ob_start();
        try {
            $cleanStats = chat_cleanup_all($db, $keepDays, true);
            $cleanOk = $cleanStats['ok'];
        } catch (Throwable $ex) {
            $cleanOk = false;
            echo 'ERREUR : ' . $ex->getMessage() . "\n";
        }
        $cleanLog = ob_get_clean();
    } else {
        // REPAIR_SCHEMA_LIBRARY : empeche database/repair_schema.php de
        // s'executer tout seul a l'inclusion (on l'appelle nous-memes).
        define('REPAIR_SCHEMA_LIBRARY', 1);
        require_once __DIR__ . '/database/repair_schema.php';

        ob_start();
        try {
            $dedupeBusiness = !empty($_POST['dedupe_business']);
            $repair = repair_schema_all($db, true, $dedupeBusiness);
            $ok = $repair['ok'];
            $repairWarnings = (int) ($repair['warnings'] ?? 0);
        } catch (Throwable $ex) {
            $ok = false;
            $repairWarnings = 0;
            echo 'ERREUR : ' . $ex->getMessage() . "\n";
        }
        $log = ob_get_clean();

        // Rapport persiste (journal local, protege par logs/.htaccess) :
        // permet de verifier ce qui s'est passe sur l'hebergeur, meme apres
        // avoir quitte la page. Echec silencieux si le dossier n'est pas
        // inscriptible (certains hebergements).
        @file_put_contents(
            __DIR__ . '/logs/repair-schema.log',
            '[' . date('Y-m-d H:i:s') . '] '
                . ($ok === null ? '?' : ($ok ? 'OK' : 'ERREUR'))
                . " (avertissements : {$repairWarnings})\n" . $log . "\n",
            FILE_APPEND
        );
    }
}

// Sauvegardes JSON deja presentes sur le serveur (carte sauvegarde/restauration).
$backupDir = __DIR__ . '/backups';
$backupCount = 0;
if (is_dir($backupDir)) {
    foreach ((array) scandir($backupDir) as $backupEntry) {
        if (is_file($backupDir . '/' . $backupEntry)
            && strtolower((string) pathinfo((string) $backupEntry, PATHINFO_EXTENSION)) === 'json'
        ) {
            $backupCount++;
        }
    }
}

// Etat actuel des tables du chat (resume affiche dans la carte de nettoyage).
$chatStats = chat_tables_stats($db);
if ($chatStats['messages'] === null && $chatStats['presence'] === null) {
    $chatResume = 'tables du chat indisponibles';
} else {
    $chatResume = (int) ($chatStats['messages'] ?? 0) . ' message(s), '
        . (int) ($chatStats['presence'] ?? 0) . ' ligne(s) de présence'
        . ($chatStats['bytes'] !== null
            ? ' (~' . chat_format_octets((int) $chatStats['bytes']) . ')'
            : '');
}

require __DIR__ . '/includes/header.php';
?>

<?php if ($log !== ''): ?>
  <?php // Trois etats : vert = reussi ; ambre = reussi avec avertissements
        // (action manuelle requise, aucune donnee perdue) ; rouge = erreur. ?>
  <?php $repairClass = !$ok ? 'alert-error' : ($repairWarnings > 0 ? 'alert-warning' : 'alert-success'); ?>
  <div class="alert <?= $repairClass ?>" data-autohide>
    <button type="button" class="alert-close" onclick="this.parentElement.remove()" aria-label="Fermer">&times;</button>
    <?= !$ok
        ? 'La réparation s\'est terminée avec des erreurs : lisez le rapport ci-dessous. Aucune donnée n\'a été perdue.'
        : ($repairWarnings > 0
            ? "Réparation terminée : {$repairWarnings} point(s) demandent une action manuelle — lisez le rapport ci-dessous. Aucune donnée n'a été perdue."
            : 'Réparation terminée avec succès. Vous pouvez revenir à l\'application ; si un problème persistait, rechargez la page concernée.') ?>
  </div>
<?php endif; ?>

<?php if ($cleanLog !== ''): ?>
  <div class="alert <?= $cleanOk ? 'alert-success' : 'alert-error' ?>" data-autohide>
    <button type="button" class="alert-close" onclick="this.parentElement.remove()" aria-label="Fermer">&times;</button>
    <?= $cleanOk
        ? 'Nettoyage du chat terminé. La présence « en ligne » se régénérera automatiquement au prochain ping.'
        : 'Le nettoyage s\'est terminé avec des erreurs : lisez le rapport ci-dessous.' ?>
  </div>
<?php endif; ?>

<main class="settings-page repair-page">
<div class="content-card">
  <div class="card-head">
    <h2>Réparer le schéma de la base de données</h2>
  </div>

  <div class="card-body">
  <p><strong>Il ne supprime aucune donnée</strong> : les identifiants en double
    sont renumérotés, jamais effacés. Il est <strong>idempotent</strong> : le
    relancer ne change rien une fois tout réparé. Il corrige entre autres&nbsp;:</p>

    <ul>
      <li>le chat qui affiche «&nbsp;Erreur d'envoi&nbsp;» ou «&nbsp;non envoyé — réessayez&nbsp;»&nbsp;;</li>
      <li>les paramètres qui se dupliquent à chaque enregistrement&nbsp;;</li>
      <li>«&nbsp;Sélectionnez au moins un vendeur.&nbsp;» lors d'une suppression&nbsp;;</li>
      <li>«&nbsp;Ce numéro de portable existe déjà&nbsp;» à tort&nbsp;;</li>
      <li>les échecs d'enregistrement des exports sécurisés.</li>
    </ul>

    <?php if ($log !== ''): ?>
      <h3 class="repair-section-title">Rapport de réparation</h3>
      <pre class="repair-log"><?= e($log) ?></pre>
    <?php endif; ?>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="repair">
      <div class="repair-actions">
        <button type="submit" class="btn btn-primary"
                data-confirm="Dédoublonner aussi les tables métier ? La ligne la plus récente de chaque doublon sera conservée — action définitive."
                data-confirm-if="#dedupe-business:checked"
                data-confirm-danger>
          <?= $log !== '' ? 'Relancer la réparation' : 'Réparer le schéma maintenant' ?>
        </button>
        <span class="text-muted">Aucune donnée ne sera supprimée.</span>
      </div>
      <div class="repair-actions" style="margin-top: 12px;">
        <label class="text-muted" style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.85rem;">
          <input type="checkbox" id="dedupe-business" name="dedupe_business" value="1" style="width: auto;">
          Supprimer aussi les doublons métier (conserver la ligne la plus récente)
        </label>
      </div>
      </form>
  </div>
</div>

<div class="content-card">
  <div class="card-head">
    <h2>Nettoyage du chat</h2>
  </div>
  <div class="card-body">
    <p class="text-muted">
      État actuel du chat : <strong><?= e($chatResume) ?></strong>.
    </p>
    <p><strong>Attention : les conversations supprimées sont définitivement
    perdues.</strong> Seules les tables du chat sont touchées — aucun dossier,
    compte, salaire ou paramètre n'est modifié. La présence «&nbsp;en
    ligne&nbsp;» se régénère automatiquement au prochain ping du widget.</p>

    <?php if ($cleanLog !== ''): ?>
      <h3 class="repair-section-title">Rapport de nettoyage</h3>
      <pre class="repair-log"><?= e($cleanLog) ?></pre>
      <?php if ($cleanOk): ?>
        <p class="text-muted">
          Si le chat posait encore problème, relancez «&nbsp;Réparer le schéma
          maintenant&nbsp;» ci-dessus.
        </p>
      <?php endif; ?>
    <?php endif; ?>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="chat_cleanup">
      <div class="repair-actions">
        <label for="chat-keep-days" class="text-muted">Conserver&nbsp;:</label>
        <select id="chat-keep-days" name="keep_days" class="form-control">
          <option value="0">Tout supprimer (urgence)</option>
          <option value="30">Nettoyer au-delà de 30 jours</option>
          <option value="90">Nettoyer au-delà de 90 jours</option>
          <option value="<?= (int) CHAT_KEEP_DAYS ?>">Nettoyer au-delà de 2 ans (purge standard)</option>
        </select>
        <button type="submit" class="btn btn-danger"
                data-confirm="Nettoyer le chat maintenant ? Les conversations concernées seront DÉFINITIVEMENT supprimées.">
          Nettoyer le chat maintenant
        </button>
      </div>
        </form>
 </div>
</div>

<div class="content-card">
 <div class="card-head">
    <h2>Sauvegardes &amp; restauration</h2>
 </div>
 <div class="card-body">
    <h3 class="repair-section-title">1. Sauvegarder (export)</h3>
    <p class="text-muted">Télécharge un instantané complet au format JSON.</p>

    <div class="repair-actions">
      <a class="btn btn-primary" href="<?= e(APP_URL) ?>/actions/backup_export.php"
         download>Exporter la sauvegarde JSON</a>
      <span class="text-muted">Aucune donnée n'est modifiée par l'export.</span>
    </div>

    <h3 class="repair-section-title">2. Restaurer (import)</h3>
    <p><strong>Attention :</strong> l'import fusionne la sauvegarde dans la base
    actuelle (les lignes existantes sont mises à jour à partir de l'identifiant,
    jamais supprimées). Faites d'abord un export si vous voulez pouvoir revenir
    en arrière. Taille maximale : 20&nbsp;Mo.</p>

    <form method="post" action="<?= e(APP_URL) ?>/actions/backup_import.php"
          enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="repair-actions">
        <input type="file" name="backup" class="form-control" accept=".json,application/json" required>
        <button type="submit" class="btn btn-danger"
                data-confirm="Restaurer cette sauvegarde maintenant ? Les dossiers et comptes existants seront mis à jour à partir du fichier.">
          Importer la sauvegarde
        </button>
      </div>
    </form>
 </div>
</div>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>