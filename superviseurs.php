<?php
require_once __DIR__ . '/includes/init.php';
require_admin();

$stmt = $db->prepare("SELECT id, username, nom_complet, email, is_active
                      FROM users
                      WHERE role = 'superviseur'
                      ORDER BY nom_complet");
$stmt->execute();
$superviseurs = $stmt->fetchAll();

$pendingSessions = [];
try {
    $stmt = $db->query('SELECT s.*, u.email FROM superviseur_sessions s JOIN users u ON u.id = s.user_id WHERE s.status = \'pending\' ORDER BY s.created_at DESC');
    $pendingSessions = $stmt->fetchAll();
} catch (Throwable $e) {
    $pendingSessions = [];
}

$approvedIps = [];
foreach ($superviseurs as $s) {
    try {
        $stmt = $db->prepare('SELECT * FROM superviseur_approved_ips WHERE user_id = :uid ORDER BY approved_at DESC');
        $stmt->execute(['uid' => $s['id']]);
        $approvedIps[$s['id']] = $stmt->fetchAll();
    } catch (Throwable $e) {
        $approvedIps[$s['id']] = [];
    }
}

$pageTitle = 'Superviseurs';
$pageSubtitle = 'Gérer les superviseurs';
$activePage = 'superviseurs';
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
          <th>Superviseur</th>
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
          <td><?= e(substr($session['user_agent'], 0, 60)) ?></td>
          <td><?= date('d/m/Y H:i', strtotime($session['created_at'])) ?></td>
          <td class="text-center">
            <form method="post" action="<?= e(APP_URL) ?>/actions/superviseur_session_action.php" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
              <input type="hidden" name="action" value="approve">
              <button type="submit" class="btn btn-success btn-sm">Approuver</button>
            </form>
            <form method="post" action="<?= e(APP_URL) ?>/actions/superviseur_session_action.php" style="display:inline;" onsubmit="var r=prompt('Motif du refus :'); if(r===null){return false;} this.querySelector('input[name=reason]').value=r;">
              <?= csrf_field() ?>
              <input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
              <input type="hidden" name="action" value="deny">
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
    <h2>Superviseurs</h2>
    <button type="button" class="btn btn-primary" id="btn-new-superviseur">Nouveau superviseur</button>
  </div>
  <div class="table-wrap">
    <?php if (!$superviseurs): ?>
      <div class="empty-state">
        <div class="ico">&#128101;</div>
        <p>Aucun superviseur enregistré.</p>
      </div>
    <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Nom</th>
          <th>Identifiant</th>
          <th>E-mail</th>
          <th>Statut</th>
          <th>IPs approuvées</th>
          <th class="text-center">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($superviseurs as $s): ?>
        <tr id="superviseur-row-<?= (int) $s['id'] ?>">
          <td><?= e($s['nom_complet']) ?></td>
          <td><?= e($s['username']) ?></td>
          <td><?= e($s['email'] ?? '—') ?></td>
          <td><?= $s['is_active'] ? 'Actif' : 'Inactif' ?></td>
          <td>
            <?php if (!empty($approvedIps[$s['id']])): ?>
              <?php foreach ($approvedIps[$s['id']] as $ip): ?>
                <div style="margin-bottom:4px;display:flex;align-items:center;gap:6px;">
                  <div>
                    <code><?= e($ip['ip_address']) ?></code>
                    <?php if (!empty($ip['label'])): ?><span class="muted">(<?= e($ip['label']) ?>)</span><?php endif; ?>
                    <br><span class="muted" style="font-size:0.75rem;"><?= date('d/m/Y H:i', strtotime($ip['approved_at'])) ?></span>
                  </div>
                  <form action="<?= e(APP_URL) ?>/actions/superviseur_ip_delete.php" method="post"
                        data-confirm="Supprimer l'IP <?= e($ip['ip_address']) ?> ? Le superviseur devra redemander une approbation pour cette adresse."
                        style="display:inline;margin-left:auto;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $ip['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm" title="Supprimer cette IP" aria-label="Supprimer cette IP">&times;</button>
                  </form>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <span class="muted">Aucune</span>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <button type="button" class="btn btn-outline btn-sm" data-superviseur-edit
              data-id="<?= (int) $s['id'] ?>"
              data-nom="<?= e($s['nom_complet']) ?>"
              data-username="<?= e($s['username']) ?>"
              data-email="<?= e($s['email'] ?? '') ?>"
              data-active="<?= (int) $s['is_active'] ?>">Modifier</button>
            <form action="<?= e(APP_URL) ?>/actions/superviseur_delete.php" method="post" data-confirm="Supprimer ce superviseur ?" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">Supprimer</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<div id="superviseur-modal" class="confirm-modal" style="display:none;" role="dialog" aria-modal="true">
  <div class="confirm-dialog" style="width:min(100%,520px);">
    <h2 id="modal-title">Nouveau superviseur</h2>
    <form id="superviseur-form" method="post" data-create-action="<?= e(APP_URL) ?>/actions/superviseur_save.php" data-edit-action="<?= e(APP_URL) ?>/actions/superviseur_update.php" action="<?= e(APP_URL) ?>/actions/superviseur_save.php" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="id" id="modal-id">
      <div class="form-group" style="margin-bottom:14px;">
        <label for="modal-nom">Nom <span class="req">*</span></label>
        <input type="text" id="modal-nom" name="nom_complet" maxlength="150" required>
      </div>
      <div class="form-group" style="margin-bottom:14px;">
        <label for="modal-username">Identifiant <span class="req">*</span></label>
        <input type="text" id="modal-username" name="username" maxlength="60" required>
      </div>
      <div class="form-group" style="margin-bottom:14px;">
        <label for="modal-email">E-mail</label>
        <input type="email" id="modal-email" name="email" maxlength="190">
      </div>
      <div class="form-group" style="margin-bottom:14px;">
        <label for="modal-active">Statut</label>
        <select id="modal-active" name="is_active">
          <option value="1">Actif</option>
          <option value="0">Inactif</option>
        </select>
      </div>
      <input type="hidden" id="password-id" name="password_id" value="">
      <div class="form-group" style="margin-bottom:14px;">
        <label for="modal-password">Mot de passe</label>
        <div class="password-wrapper" style="display:flex;align-items:center;gap:8px;">
          <input type="password" id="modal-password" name="password" autocomplete="new-password" minlength="10" style="flex:1;">
          <button type="button" id="password-toggle" class="btn btn-outline" aria-pressed="false" title="Afficher/Masquer">Afficher</button>
        </div>
        <div class="help-text" id="password-help">10 caractères minimum, avec au moins une majuscule, une minuscule et un chiffre. Laisser vide pour conserver le mot de passe actuel.</div>
        <div id="password-strength" class="password-strength" aria-live="polite">
          <div class="password-strength-bar" aria-hidden="true"></div>
          <span class="password-strength-label">Très faible</span>
        </div>
        <div id="password-strength-hints" class="password-strength-hints" style="display:none;">
          <ul id="password-hints-list"></ul>
        </div>
      </div>
      <div class="confirm-actions">
        <button type="button" class="btn btn-outline" id="modal-cancel">Annuler</button>
        <button type="submit" class="btn btn-primary" id="modal-submit">Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
