<?php
require_once __DIR__ . '/includes/init.php';
require_admin();

$stmt = $db->prepare("SELECT id, username, nom_complet, email, is_active
                      FROM users
                      WHERE role = 'superviseur'
                      ORDER BY nom_complet");
$stmt->execute();
$superviseurs = $stmt->fetchAll();

$pageTitle = 'Superviseurs';
$pageSubtitle = 'Gérer les superviseurs';
$activePage = 'superviseurs';
require __DIR__ . '/includes/header.php';
?>

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
