<?php
require_once __DIR__ . '/includes/init.php';
require_admin();

$editId = filter_var($_GET['edit'] ?? '', FILTER_VALIDATE_INT) ?: 0;
$editVendeur = null;
if ($editId) {
  $editStmt = $db->prepare("SELECT id, nom_complet, email FROM users WHERE id = :id AND role = 'vendeur'");
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

$pageTitle = 'Vendeurs';
$pageSubtitle = 'Référentiel des vendeurs';
$activePage = 'vendeurs';
require __DIR__ . '/includes/header.php';
?>

<div class="card mb-16">
  <div class="card-header">
    <h2><?= $editVendeur ? 'Modifier le vendeur' : 'Nouveau vendeur' ?></h2>
  </div>
  <div class="card-body">
    <p class="help-text" style="margin-bottom:14px;">
      Un vendeur est un contact de dossier, pas un utilisateur de l’application. Il est enregistré avec son nom et son e-mail uniquement.
    </p>
    <form action="<?= e(APP_URL) ?>/actions/<?= $editVendeur ? 'vendeur_update.php' : 'vendeur_save.php' ?>" method="post" novalidate>
      <?= csrf_field() ?>
      <?php if ($editVendeur): ?><input type="hidden" name="id" value="<?= (int) $editVendeur['id'] ?>"><?php endif; ?>
      <div class="form-grid">
        <div class="form-group">
          <label for="nom_complet">Nom du vendeur <span class="req">*</span></label>
          <input type="text" id="nom_complet" name="nom_complet" maxlength="150" value="<?= e($editVendeur['nom_complet'] ?? '') ?>" required>
        </div>
        <div class="form-group">
          <label for="email">E-mail</label>
          <input type="email" id="email" name="email" maxlength="190" value="<?= e($editVendeur['email'] ?? '') ?>">
        </div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $editVendeur ? 'Enregistrer les modifications' : 'Ajouter le vendeur' ?></button>
        <?php if ($editVendeur): ?><a href="<?= e(APP_URL) ?>/vendeurs.php" class="btn btn-outline">Annuler</a><?php endif; ?>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h2>Vendeurs existants</h2></div>
  <div class="table-wrap">
    <?php if (!$vendeurs): ?>
      <div class="empty-state">
        <div class="ico">&#128101;</div>
        <p>Aucun vendeur enregistré.</p>
      </div>
    <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th class="selection-cell"><input type="checkbox" data-vendeur-select-all aria-label="Sélectionner tous les vendeurs affichés"></th>
          <th>Nom</th>
          <th>E-mail</th>
          <th class="text-center">Dossiers</th>
          <th class="text-center">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($vendeurs as $vd): ?>
        <tr id="vendeur-row-<?= (int) $vd['id'] ?>">
          <td class="selection-cell"><input type="checkbox" value="<?= (int) $vd['id'] ?>" data-vendeur-select aria-label="Sélectionner le vendeur <?= (int) $vd['id'] ?>"></td>
          <td><strong><?= e($vd['nom_complet']) ?></strong></td>
          <td><?= e($vd['email']) ?: '<span class="muted">—</span>' ?></td>
          <td class="text-center"><?= (int) $vd['nb_dossiers'] ?></td>
          <td class="text-center">
            <button type="button" class="btn btn-outline btn-sm" data-vendeur-edit data-id="<?= (int) $vd['id'] ?>" data-nom="<?= e($vd['nom_complet']) ?>" data-email="<?= e($vd['email'] ?? '') ?>">Modifier</button>
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
