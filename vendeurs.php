<?php
require_once __DIR__ . '/includes/init.php';
require_admin();
users_schema_ensure($db); // garantit la colonne can_supervise
superviseur_tables_ensure($db); // file d'attente d'approbation IP (partagée)
superviseur_schema_repair($db);

$editId = filter_var($_GET['edit'] ?? '', FILTER_VALIDATE_INT) ?: 0;
$editVendeur = null;
if ($editId) {
  $editStmt = $db->prepare("SELECT id, nom_complet, username, email, is_active, can_supervise FROM users WHERE id = :id AND role = 'vendeur'");
  $editStmt->execute(['id' => $editId]);
  $editVendeur = $editStmt->fetch();
}

$stmt = $db->query("SELECT u.*,
        COUNT(d.id) AS nb_dossiers,
        COALESCE(SUM(CASE WHEN d.etat_contrat = 'Actif' THEN d.ca_annuel ELSE 0 END),0) AS total_ca
    FROM users u
    LEFT JOIN dossiers d ON d.vendeur_id = u.id
    WHERE u.role = 'vendeur'
    GROUP BY u.id
    ORDER BY u.nom_complet");
$vendeurs = $stmt->fetchAll();

// Demandes de session en attente : superviseurs et vendeurs « superviseur-like »
// partagent la même file d'approbation (approbation par adresse IP).
$pendingSessions = [];
try {
    $stmt = $db->query("SELECT s.*, u.email, u.role FROM superviseur_sessions s JOIN users u ON u.id = s.user_id WHERE s.status = 'pending' ORDER BY s.created_at DESC");
    $pendingSessions = $stmt->fetchAll();
} catch (Throwable $e) {
    $pendingSessions = [];
}

$approvedIps = [];
foreach ($vendeurs as $s) {
    try {
        $stmt = $db->prepare('SELECT * FROM superviseur_approved_ips WHERE user_id = :uid ORDER BY approved_at DESC');
        $stmt->execute(['uid' => $s['id']]);
        $approvedIps[$s['id']] = $stmt->fetchAll();
    } catch (Throwable $e) {
        $approvedIps[$s['id']] = [];
    }
}

$pageTitle = 'Vendeurs';
$pageSubtitle = 'Gérer les vendeurs';
$activePage = 'vendeurs';
require __DIR__ . '/includes/header.php';
?>

<?php if ($pendingSessions): ?>
<div class="card mb-16" style="border-left: 4px solid #f59e0b;">
 <div class="card-header">
    <h2>Demandes de session en attente</h2>
    <span class="badge badge-warning"><?= count($pendingSessions) ?></span>
 </div>
 <div class="table-wrap">
    <table class="data-table data-table--wide">
      <thead>
        <tr>
          <th>Utilisateur</th>
          <th>Rôle</th>
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
          <td><?= ($session['role'] ?? '') === 'superviseur' ? 'Superviseur' : 'Vendeur' ?></td>
          <td><code><?= e($session['ip_address']) ?></code></td>
          <td><?= e(substr($session['user_agent'], 0, 60)) ?></td>
          <td><?= date('d/m/Y H:i', strtotime($session['created_at'])) ?></td>
          <td class="text-center">
            <form method="post" action="<?= e(APP_URL) ?>/actions/superviseur_session_action.php" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
              <input type="hidden" name="action" value="approve">
              <input type="hidden" name="return_to" value="vendeurs.php">
              <button type="submit" class="btn btn-success btn-sm">Approuver</button>
            </form>
            <form method="post" action="<?= e(APP_URL) ?>/actions/superviseur_session_action.php" style="display:inline;" onsubmit="var r=prompt('Motif du refus :'); if(r===null){return false;} this.querySelector('input[name=reason]').value=r;">
              <?= csrf_field() ?>
              <input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
              <input type="hidden" name="action" value="deny">
              <input type="hidden" name="return_to" value="vendeurs.php">
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

<div id="vendeur-modal" class="confirm-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="vendeur-modal-title">
 <div class="confirm-dialog superviseur-dialog">
    <h2 id="vendeur-modal-title">Nouveau vendeur</h2>
    <form id="vendeur-modal-form" method="post"
          action="<?= e(APP_URL) ?>/actions/vendeur_save.php"
          data-create-action="<?= e(APP_URL) ?>/actions/vendeur_save.php"
          data-edit-action="<?= e(APP_URL) ?>/actions/vendeur_update.php"
          novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="id" id="vendeur-modal-id" value="">
      <div class="superviseur-fields">
        <div class="form-group">
          <label for="vendeur-modal-nom">Nom complet <span class="req">*</span></label>
          <input type="text" id="vendeur-modal-nom" name="nom_complet" maxlength="150" required>
        </div>
        <div class="form-group">
          <label for="vendeur-modal-username">Identifiant <span class="req">*</span></label>
          <input type="text" id="vendeur-modal-username" name="username" maxlength="60">
          <div class="help-text">Généré automatiquement à partir du nom si laissé vide.</div>
        </div>
        <div class="form-group">
          <label for="vendeur-modal-email">E-mail</label>
          <input type="email" id="vendeur-modal-email" name="email" maxlength="190">
        </div>
        <div class="form-group">
          <label for="vendeur-modal-active">Statut</label>
          <select id="vendeur-modal-active" name="is_active">
            <option value="1">Actif</option>
            <option value="0">Inactif</option>
          </select>
        </div>
        <div class="form-group">
          <label>Accès application</label>
          <input type="hidden" id="vendeur-modal-supervise" name="can_supervise" value="1">
          <div class="help-text">Tous les vendeurs sont connectables : ils ont accès au tableau de bord, aux dossiers, à l’ajout manuel, à l’import et aux notifications. Leur connexion est soumise à l’approbation de leur adresse IP par un administrateur.</div>
        </div>
        <div class="form-group">
          <label for="vendeur-modal-password">Mot de passe</label>
          <div class="password-wrapper" style="display:flex;align-items:center;gap:8px;">
            <input type="password" id="vendeur-modal-password" name="password" autocomplete="new-password" minlength="12" style="flex:1;" placeholder="Obligatoire à la création">
            <button type="button" id="password-toggle" class="btn btn-outline" aria-pressed="false" title="Afficher/Masquer">Afficher</button>
          </div>
          <div class="help-text" id="password-help">12 caractères minimum, avec au moins une majuscule, une minuscule, un chiffre et un caractère spécial. Laisser vide pour conserver le mot de passe actuel.</div>
          <div id="password-strength" class="password-strength" aria-live="polite">
            <div class="password-strength-bar" aria-hidden="true"></div>
            <span class="password-strength-label">Très faible</span>
          </div>
          <div id="password-strength-hints" class="password-strength-hints" style="display:none;">
            <ul id="password-hints-list"></ul>
          </div>
        </div>
      </div>
      <div class="superviseur-actions">
        <button type="button" class="btn btn-outline" id="vendeur-modal-cancel">Annuler</button>
        <button type="submit" class="btn btn-primary" id="vendeur-modal-submit">Enregistrer</button>
      </div>
    </form>
 </div>
</div>

<div class="card">
 <div class="card-header">
    <h2>Vendeurs existants</h2>
    <button type="button" class="btn btn-primary" id="vendeur-new-btn">Nouveau vendeur</button>
 </div>
 <div class="table-wrap">
    <?php if (!$vendeurs): ?>
      <div class="empty-state">
        <div class="ico">&#128101;</div>
        <p>Aucun vendeur enregistré.</p>
      </div>
    <?php else: ?>
    <table class="data-table data-table--wide">
      <thead>
        <tr>
          <th class="selection-cell"><input type="checkbox" data-vendeur-select-all aria-label="Sélectionner tous les vendeurs affichés"></th>
          <th>Nom</th>
          <th>Identifiant</th>
          <th>E-mail</th>
          <th>Statut</th>
          <th>Accès</th>
          <th>IPs approuvées</th>
          <th class="text-center">Dossiers</th>
          <th class="text-center">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($vendeurs as $vd):
          $canSupervise = !empty($vd['can_supervise']);
        ?>
        <tr id="vendeur-row-<?= (int) $vd['id'] ?>">
          <td class="selection-cell"><input type="checkbox" value="<?= (int) $vd['id'] ?>" data-vendeur-select aria-label="Sélectionner le vendeur <?= (int) $vd['id'] ?>"></td>
          <td><strong><?= e($vd['nom_complet']) ?></strong></td>
          <td><?= e($vd['username'] ?? '') ?: '<span class="muted">—</span>' ?></td>
          <td><?= e($vd['email']) ?: '<span class="muted">—</span>' ?></td>
          <td><?= !empty($vd['is_active']) ? 'Actif' : 'Inactif' ?></td>
          <td><span class="badge badge-complet">Connectable</span></td>
          <td>
            <?php if ($canSupervise && !empty($approvedIps[$vd['id']])): ?>
              <?php foreach ($approvedIps[$vd['id']] as $ip): ?>
                <div style="margin-bottom:4px;display:flex;align-items:center;gap:6px;">
                  <div>
                    <code><?= e($ip['ip_address']) ?></code>
                    <?php if (!empty($ip['label'])): ?><span class="muted">(<?= e($ip['label']) ?>)</span><?php endif; ?>
                    <br><span class="muted" style="font-size:0.75rem;"><?= date('d/m/Y H:i', strtotime($ip['approved_at'])) ?></span>
                  </div>
                  <form action="<?= e(APP_URL) ?>/actions/superviseur_ip_delete.php" method="post"
                        data-confirm="Supprimer l'IP <?= e($ip['ip_address']) ?> ? Le vendeur devra redemander une approbation pour cette adresse."
                        style="display:inline;margin-left:auto;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $ip['id'] ?>">
                    <input type="hidden" name="return_to" value="vendeurs.php">
                    <button type="submit" class="btn btn-danger btn-sm btn-icon-action" title="Supprimer cette IP" aria-label="Supprimer cette IP"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg></button>
                  </form>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <span class="muted">Aucune</span>
            <?php endif; ?>
          </td>
          <td class="text-center"><?= (int) $vd['nb_dossiers'] ?></td>
          <td class="text-center">
            <span class="row-actions">
            <button type="button" class="btn btn-outline btn-sm btn-icon-action" data-vendeur-edit
              data-id="<?= (int) $vd['id'] ?>"
              data-nom="<?= e($vd['nom_complet']) ?>"
              data-username="<?= e($vd['username'] ?? '') ?>"
              data-email="<?= e($vd['email'] ?? '') ?>"
              data-active="<?= (int) (!empty($vd['is_active'])) ?>"
              title="Modifier" aria-label="Modifier"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg></button>
            <form action="<?= e(APP_URL) ?>/actions/vendeur_delete.php" method="post" data-confirm="Supprimer le vendeur <?= e($vd['nom_complet']) ?> ?" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $vd['id'] ?>">
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
  <?php if ($vendeurs): ?>
  <div class="bulk-actions" data-vendeur-bulk-actions>
    <span class="bulk-count" data-vendeur-selection-count>0 vendeur sélectionné</span>
    <button type="button" class="btn btn-danger btn-sm" data-vendeur-delete disabled>Supprimer la sélection</button>
  </div>
  <form action="<?= e(APP_URL) ?>/actions/vendeur_bulk_delete.php" method="post" data-vendeur-delete-form>
    <?= csrf_field() ?>
  </form>
  <?php endif; ?>
</div>

<div id="vendeur-edit-modal" class="confirm-modal" style="display:none;" aria-hidden="true">
  <div class="confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="vendeur-edit-title">
    <h2 id="vendeur-edit-title">Modifier le vendeur</h2>
    <form id="vendeur-edit-form" action="<?= e(APP_URL) ?>/actions/vendeur_update.php" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="id" id="vendeur-edit-id">
      <div class="form-group" style="margin-bottom:16px;">
        <label for="vendeur-edit-name">Nom du vendeur</label>
        <input type="text" id="vendeur-edit-name" name="nom_complet" maxlength="150" required>
      </div>
      <div class="form-group" style="margin-bottom:16px;">
        <label for="vendeur-edit-email">E-mail</label>
        <input type="email" id="vendeur-edit-email" name="email" maxlength="190" placeholder="Optionnel">
      </div>
      <div class="confirm-actions">
        <button type="button" class="btn btn-outline" data-vendeur-close>Annuler</button>
        <button type="submit" class="btn btn-primary">Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
