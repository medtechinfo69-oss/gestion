<?php
/**
 * Gestion des comptes RH — RESERVEE A L'ADMINISTRATEUR.
 *
 * Copie fonctionnelle et visuelle de superviseurs.php : memes colonnes, meme
 * tableau, meme popup, ET MEME GESTION DES IPs APPROUVEES (demandes de
 * session en attente + adresses de confiance). Meme securite, memes actions.
 *
 * Le role « rh » ne gere PAS les comptes RH (ni la page, ni les actions
 * rh_save.php / rh_update.php / rh_delete.php, qui appellent require_admin()).
 * Un compte RH ne dispose que des pages de son module (tableau RH, employes,
 * salaires, historique) ou il cree, modifie et supprime les employes et les
 * bulletins de salaire (voir rh_allowed_pages() dans includes/auth.php).
 */
require_once __DIR__ . '/includes/init.php';
require_admin(); // les comptes RH ne gerent pas les comptes RH
users_schema_ensure($db); // garantit la presence du role « rh » dans l'ENUM
superviseur_tables_ensure($db); // cree au besoin les tables de supervision (demandes de session, IPs)
superviseur_schema_repair($db); // restaure cle primaire + auto-increment

$stmt = $db->prepare("SELECT id, username, nom_complet, email, is_active, last_login
                      FROM users
                      WHERE role = 'rh'
                      ORDER BY nom_complet");
$stmt->execute();
$rhComptes = $stmt->fetchAll();

/**
 * Demandes de session en attente pour les comptes RH UNIQUEMENT.
 * On filtre sur le role pour ne pas melanger les demandes des superviseurs
 * et des vendeurs (qui ont leur propre page).
 */
$pendingSessions = [];
try {
    $stmt = $db->prepare(
        "SELECT s.*, u.email, u.role
           FROM superviseur_sessions s
           JOIN users u ON u.id = s.user_id
          WHERE s.status = 'pending' AND u.role = 'rh'
          ORDER BY s.created_at DESC"
    );
    $stmt->execute();
    $pendingSessions = $stmt->fetchAll();
} catch (Throwable $e) {
    $pendingSessions = [];
}

// Fuseau de reference des horodatages : aligne sur le poste de travail
// (W. Central Africa = UTC+1). Evite d'afficher une heure en avance.
$displayTimezone = new DateTimeZone('Africa/Algiers');

/**
 * Les DATETIME MySQL (CURRENT_TIMESTAMP) sont stockes avec le fuseau du
 * serveur MySQL. On detecte son decalage, puis on affiche la valeur dans le
 * fuseau local de l'utilisateur pour que date et heure soient exactes.
 */
function format_pending_session_datetime(PDO $db, ?string $sqlDateTime, DateTimeZone $displayTimezone): string
{
    static $serverOffsetSeconds = null;
    $value = trim((string) $sqlDateTime);
    if ($value === '') {
        return '—';
    }

    try {
        if ($serverOffsetSeconds === null) {
            $row = $db->query('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS offset_seconds')->fetch();
            $serverOffsetSeconds = (int) ($row['offset_seconds'] ?? 0);
        }

        $stored = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        $secondsToRemove = $serverOffsetSeconds;
        $utcValue = $stored->modify('-' . $secondsToRemove . ' seconds');
        return $utcValue->setTimezone($displayTimezone)->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return date('d/m/Y H:i', strtotime($value) ?: time());
    }
}

// IPs deja approuvees pour chaque compte RH.
$approvedIps = [];
foreach ($rhComptes as $s) {
    try {
        $stmt = $db->prepare('SELECT * FROM superviseur_approved_ips WHERE user_id = :uid ORDER BY approved_at DESC');
        $stmt->execute(['uid' => $s['id']]);
        $approvedIps[$s['id']] = $stmt->fetchAll();
    } catch (Throwable $e) {
        $approvedIps[$s['id']] = [];
    }
}

$pageTitle = 'Comptes RH';
$pageSubtitle = 'Gerer les acces au module Gestion RH';
$activePage = 'rh_comptes';
require __DIR__ . '/includes/header.php';
?>

<?php if ($pendingSessions): ?>
<div class="card mb-16" style="border-left: 4px solid #f59e0b;">
  <div class="card-header">
    <h2>Demandes de session en attente</h2>
    <span class="badge badge-warning"><?= count($pendingSessions) ?></span>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Compte RH</th>
          <th>Adresse IP</th>
          <th>Navigateur</th>
          <th>Date</th>
          <th class="text-center">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pendingSessions as $session): ?>
        <tr>
          <td><?= e($session['username']) ?></td>
          <td><code><?= e($session['ip_address']) ?></code></td>
          <td><?= e(substr((string) $session['user_agent'], 0, 60)) ?></td>
          <td><?= format_pending_session_datetime($db, $session['created_at'] ?? null, $displayTimezone) ?></td>
          <td class="text-center">
            <form method="post" action="<?= e(APP_URL) ?>/actions/superviseur_session_action.php" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
              <input type="hidden" name="action" value="approve">
              <input type="hidden" name="return_to" value="rh_comptes.php">
              <button type="submit" class="btn btn-success btn-sm">Approuver</button>
            </form>
            <form method="post" action="<?= e(APP_URL) ?>/actions/superviseur_session_action.php" style="display:inline;" onsubmit="var r=prompt('Motif du refus :'); if(r===null){return false;} this.querySelector('input[name=reason]').value=r;">
              <?= csrf_field() ?>
              <input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
              <input type="hidden" name="action" value="deny">
              <input type="hidden" name="return_to" value="rh_comptes.php">
              <input type="hidden" name="reason" value="">
              <button type="submit" class="btn btn-danger btn-sm">Refuser</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card mb-16">
  <div class="card-header">
    <h2>Comptes RH</h2>
    <button type="button" class="btn btn-primary" id="btn-new-rh">Nouveau compte RH</button>
  </div>
  <div class="table-wrap">
    <?php if (!$rhComptes): ?>
      <div class="empty-state">
        <div class="ico">&#128101;</div>
        <p>Aucun compte RH enregistre.</p>
      </div>
    <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Nom</th>
          <th>Identifiant</th>
          <th>E-mail</th>
          <th>Statut</th>
          <th>IPs approuvees</th>
          <th class="text-center">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rhComptes as $r): ?>
        <tr id="rh-row-<?= (int) $r['id'] ?>">
          <td><?= e($r['nom_complet']) ?></td>
          <td><?= e($r['username']) ?></td>
          <td><?= e($r['email'] ?? '—') ?></td>
          <td><?= $r['is_active'] ? 'Actif' : 'Inactif' ?></td>
          <td>
            <?php if (!empty($approvedIps[$r['id']])): ?>
              <?php foreach ($approvedIps[$r['id']] as $ip): ?>
                <div style="margin-bottom:4px;display:flex;align-items:center;gap:6px;">
                  <div>
                    <code><?= e($ip['ip_address']) ?></code>
                    <?php if (!empty($ip['label'])): ?><span class="muted">(<?= e($ip['label']) ?>)</span><?php endif; ?>
                    <br><span class="muted" style="font-size:0.75rem;"><?= format_datetime_tn($ip['approved_at']) ?></span>
                  </div>
                  <form action="<?= e(APP_URL) ?>/actions/superviseur_ip_delete.php" method="post"
                        data-confirm="Supprimer l'IP <?= e($ip['ip_address']) ?> ? Le compte RH devra redemander une approbation pour cette adresse."
                        style="display:inline;margin-left:auto;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $ip['id'] ?>">
                    <input type="hidden" name="return_to" value="rh_comptes.php">
                    <button type="submit" class="btn btn-danger btn-sm btn-icon-action" title="Supprimer cette IP" aria-label="Supprimer cette IP"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg></button>
                  </form>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <span class="muted">Aucune</span>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <span class="row-actions">
              <button type="button" class="btn btn-outline btn-sm btn-icon-action" data-rh-edit
                data-id="<?= (int) $r['id'] ?>"
                data-nom="<?= e($r['nom_complet']) ?>"
                data-username="<?= e($r['username']) ?>"
                data-email="<?= e($r['email'] ?? '') ?>"
                data-active="<?= (int) $r['is_active'] ?>" title="Modifier" aria-label="Modifier"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg></button>
              <form action="<?= e(APP_URL) ?>/actions/rh_delete.php" method="post" data-confirm="Supprimer ce compte RH ?" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm btn-icon-action" title="Supprimer" aria-label="Supprimer"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg></button>
              </form>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>


<div id="rh-modal" class="confirm-modal superviseur-modal" style="display:none;" role="dialog" aria-modal="true">
  <div class="confirm-dialog superviseur-dialog">
    <h2 id="rh-modal-title">Nouveau compte RH</h2>
    <form id="rh-form" method="post" data-create-action="<?= e(APP_URL) ?>/actions/rh_save.php" data-edit-action="<?= e(APP_URL) ?>/actions/rh_update.php" action="<?= e(APP_URL) ?>/actions/rh_save.php" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="id" id="rh-modal-id">
      <div class="superviseur-fields">
        <div class="form-group">
          <label for="rh-modal-nom">Nom <span class="req">*</span></label>
          <input type="text" id="rh-modal-nom" name="nom_complet" maxlength="150" required>
        </div>
        <div class="form-group">
          <label for="rh-modal-username">Identifiant <span class="req">*</span></label>
          <input type="text" id="rh-modal-username" name="username" maxlength="60" required>
        </div>
        <div class="form-group">
          <label for="rh-modal-email">E-mail</label>
          <input type="email" id="rh-modal-email" name="email" maxlength="190">
        </div>
        <div class="form-group">
          <label for="rh-modal-active">Statut</label>
          <select id="rh-modal-active" name="is_active">
            <option value="1">Actif</option>
            <option value="0">Inactif</option>
          </select>
        </div>
        <div class="form-group">
          <label for="rh-modal-password">Mot de passe</label>
          <div class="password-wrapper" style="display:flex;align-items:center;gap:8px;">
            <input type="password" id="rh-modal-password" name="password" autocomplete="new-password" minlength="12" style="flex:1;">
            <button type="button" id="rh-password-toggle" class="btn btn-outline" aria-pressed="false" title="Afficher/Masquer">Afficher</button>
          </div>
          <div class="help-text" id="rh-password-help">12 caracteres minimum, avec une majuscule, une minuscule, un chiffre et un caractere special. Laisser vide pour conserver le mot de passe actuel.</div>
          <div id="rh-password-strength" class="password-strength" aria-live="polite">
            <div class="password-strength-bar" aria-hidden="true"></div>
            <span class="password-strength-label">Tres faible</span>
          </div>
          <div id="rh-password-strength-hints" class="password-strength-hints" style="display:none;">
            <ul id="rh-password-hints-list"></ul>
          </div>
        </div>
      </div>
      <div class="superviseur-actions">
        <button type="button" class="btn btn-outline" id="rh-modal-cancel">Annuler</button>
        <button type="submit" class="btn btn-primary" id="rh-modal-submit">Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>