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
          <th>Nom</th>
          <th>Créée le</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($origines as $o): ?>
        <tr>
          <td><?= e($o['nom']) ?></td>
          <td><?= format_date($o['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>