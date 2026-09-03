<?php
require_once __DIR__ . '/includes/init.php';
require_admin_or_superviseur();

$pageTitle = 'Importer des dossiers';
$pageSubtitle = 'Importer un fichier Excel avec les mêmes colonnes que le tableau Dossiers';
$activePage = 'dossiers';
$topbarActions = '<a href="' . e(APP_URL) . '/dossiers.php" class="btn btn-outline">Retour aux dossiers</a>';
require __DIR__ . '/includes/header.php';
?>

<div class="card import-card">
  <div class="card-body">
    <h2>Importer un fichier Excel</h2>
    <p class="help-text">Formats acceptés : .xlsx ou .csv. La première ligne doit contenir les mêmes en-têtes que le tableau Dossiers.</p>
    <form action="<?= e(APP_URL) ?>/actions/dossiers_import.php" method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="form-group">
        <label for="fichier">Fichier Excel</label>
        <input type="file" id="fichier" name="fichier" accept=".xlsx,.csv" required>
        <div class="help-text">Taille maximale : <?= (int) (max_import_size_bytes($db) / 1024 / 1024) ?> Mo. Les dossiers sont importés uniquement si toutes les lignes sont valides.</div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Importer les dossiers</button>
        <a href="<?= e(APP_URL) ?>/dossiers.php" class="btn btn-outline">Annuler</a>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>