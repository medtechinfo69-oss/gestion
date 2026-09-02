<?php
require_once __DIR__ . '/includes/init.php';
require_admin();

$origines = [];
$tableError = null;
try {
    $stmt = $db->query('SELECT id, nom, created_at FROM origines ORDER BY nom');
    $origines = $stmt->fetchAll();
} catch (Throwable $e) {
    $tableError = 'Table origines introuvable. Veuillez contacter l\'administrateur.';
}

$pageTitle = 'Origines';
$pageSubtitle = 'Référentiel des origines';
$activePage = 'origines';
require __DIR__ . '/includes/header.php';
?>

<?php if ($tableError): ?>
  <div class="alert alert-error" style="margin-bottom:16px;"><?= e($tableError) ?></div>
<?php endif; ?>

<div class="card mb-16">
  <div class="card-header">
    <h2>Nouvelle origine</h2>
  </div>
  <div class="card-body">
    <?php if ($tableError): ?>
      <p class="help-text">La table est manquante. Veuillez contacter l'administrateur pour la créer.</p>
    <?php else: ?>
    <form action="<?= e(APP_URL) ?>/actions/origine_save.php" method="post" novalidate>
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="form-group">
          <label for="nom">Nom de l'origine <span class="req">*</span></label>
          <input type="text" id="nom" name="nom" maxlength="255" required>
        </div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Ajouter l'origine</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-header"><h2>Origines existantes</h2></div>
  <div class="table-wrap">
    <?php if (!$origines): ?>
      <div class="empty-state">
        <div class="ico">&#128218;</div>
        <p>Aucune origine enregistrée.</p>
      </div>
    <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th class="selection-cell"><input type="checkbox" data-origine-select-all aria-label="Sélectionner toutes les origines affichées"></th>
          <th>Nom</th>
          <th>Créée le</th>
          <th class="text-center">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($origines as $o): ?>
        <tr id="origine-row-<?= (int) $o['id'] ?>">
          <td class="selection-cell"><input type="checkbox" value="<?= (int) $o['id'] ?>" data-origine-select aria-label="Sélectionner l'origine <?= e($o['nom']) ?>"></td>
          <td><?= e($o['nom']) ?></td>
          <td><?= format_date($o['created_at']) ?></td>
          <td class="text-center">
            <button type="button" class="btn btn-outline btn-sm" data-origine-edit data-id="<?= (int) $o['id'] ?>" data-nom="<?= e($o['nom']) ?>">Modifier</button>
            <form action="<?= e(APP_URL) ?>/actions/origine_delete.php" method="post" data-confirm="Supprimer cette origine ?" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">Supprimer</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?php if ($origines): ?>
  <div class="bulk-actions" data-origine-bulk-actions>
    <span class="bulk-count" data-origine-selection-count>0 origine sélectionnée</span>
    <button type="button" class="btn btn-danger btn-sm" data-origine-delete-selected disabled>Supprimer la sélection</button>
  </div>
  <form action="<?= e(APP_URL) ?>/actions/origine_bulk_delete.php" method="post" data-origine-delete-form>
    <?= csrf_field() ?>
  </form>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
<div id="origine-edit-modal" class="confirm-modal" style="display:none;" aria-hidden="true">
  <div class="confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="origine-edit-title">
    <h2 id="origine-edit-title">Modifier l'origine</h2>
    <form id="origine-edit-form" action="<?= e(APP_URL) ?>/actions/origine_edit.php" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="id" id="origine-edit-id">
      <div class="form-group" style="margin-bottom:16px;">
        <label for="origine-edit-name">Nom de l'origine</label>
        <input type="text" id="origine-edit-name" name="nom" maxlength="255" required>
      </div>
      <div class="confirm-actions">
        <button type="button" class="btn btn-outline" data-origine-close>Annuler</button>
        <button type="submit" class="btn btn-primary">Enregistrer</button>
      </div>
    </form>
  </div>
</div>