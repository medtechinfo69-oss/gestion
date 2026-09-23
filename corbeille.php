<?php
require_once __DIR__ . '/includes/init.php';
require_admin();

$stmt = $db->query('SELECT t.*, u.nom_complet AS deleted_by_name
                    FROM dossier_trash t
                    JOIN users u ON u.id = t.deleted_by
                    ORDER BY t.deleted_at DESC');
$items = $stmt->fetchAll();

$pageTitle = 'Corbeille';
$pageSubtitle = 'Dossiers supprimés récupérables';
$activePage = 'corbeille';
$topbarActions = '';

if ($items) {
    ob_start();
    ?>
    <form action="<?= e(APP_URL) ?>/actions/dossier_trash_delete.php" method="post" style="display:inline;">
      <?= csrf_field() ?>
      <input type="hidden" name="empty_all" value="1">
      <button type="submit" class="btn btn-danger btn-sm" data-confirm="Supprimer définitivement TOUS les dossiers de la corbeille ? Cette action est irréversible.">Supprimer tout</button>
    </form>
    <?php
    $topbarActions = ob_get_clean();
}

require __DIR__ . '/includes/header.php';
?>

<div class="card">
  <div class="card-header">
    <h2>Dossiers dans la corbeille</h2>
    <div class="flex gap-8" style="align-items:center;">
      <span class="muted"><?= count($items) ?> élément(s)</span>
    </div>
  </div>
  <div class="table-wrap">
    <?php if (!$items): ?>
      <div class="empty-state">
        <div class="ico">&#128465;</div>
        <p>La corbeille est vide.</p>
      </div>
    <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th class="selection-cell"><input type="checkbox" data-trash-select-all aria-label="Sélectionner tous les dossiers de la corbeille"></th>
          <th>Dossier</th><th>Vendeur</th><th>Supprimé par</th><th>Date</th><th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($items as $item):
          $dossier = json_decode($item['dossier_data'], true) ?: [];
      ?>
        <tr class="row-annule">
          <td class="selection-cell"><input type="checkbox" name="trash_ids[]" value="<?= (int) $item['id'] ?>" data-trash-select aria-label="Sélectionner le dossier #<?= (int) $item['original_dossier_id'] ?>"></td>
          <td><strong><?= e(($dossier['nom'] ?? '') . ' ' . ($dossier['prenom'] ?? '')) ?></strong><br><span class="muted">#<?= (int) $item['original_dossier_id'] ?></span></td>
          <td><?= e($dossier['vendeur_id'] ?? '') ?></td>
          <td><?= e($item['deleted_by_name']) ?></td>
          <td class="nowrap"><?= e(format_date(substr($item['deleted_at'], 0, 10))) ?></td>
          <td class="nowrap">
            <span class="row-actions">
            <form action="<?= e(APP_URL) ?>/actions/dossier_restore.php" method="post" data-confirm="Restaurer ce dossier dans la liste active ?" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
              <button type="submit" class="btn btn-primary btn-sm btn-icon-action" title="Restaurer" aria-label="Restaurer"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/></svg></button>
            </form>
            <form action="<?= e(APP_URL) ?>/actions/dossier_trash_delete.php" method="post" data-confirm="Supprimer définitivement ce dossier de la corbeille ?" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm btn-icon-action" title="Supprimer définitivement" aria-label="Supprimer définitivement"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg></button>
            </form>
            </span>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php if ($items): ?>
  <div class="bulk-actions" data-bulk-actions>
    <span class="bulk-count" data-trash-selection-count>0 dossier sélectionné</span>
    <div class="flex gap-8">
      <button type="button" class="btn btn-danger btn-sm" data-trash-delete-selected disabled>Supprimer la sélection</button>
    </div>
  </div>
  <form action="<?= e(APP_URL) ?>/actions/dossier_trash_delete.php" method="post" data-trash-delete-form>
    <?= csrf_field() ?>
  </form>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
