<?php
require_once __DIR__ . '/includes/init.php';
require_admin();

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
    <h2>Nouveau vendeur</h2>
  </div>
  <div class="card-body">
    <p class="help-text" style="margin-bottom:14px;">
      Un vendeur est un contact de dossier, pas un utilisateur de l’application. Il est enregistré avec son nom et son e-mail uniquement.
    </p>
    <form action="<?= e(APP_URL) ?>/actions/vendeur_save.php" method="post" novalidate>
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="form-group">
          <label for="nom_complet">Nom du vendeur <span class="req">*</span></label>
          <input type="text" id="nom_complet" name="nom_complet" maxlength="150" required>
        </div>
        <div class="form-group">
          <label for="email">E-mail</label>
          <input type="email" id="email" name="email" maxlength="190">
        </div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Ajouter le vendeur</button>
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
          <th>Nom</th>
          <th>E-mail</th>
          <th class="text-center">Dossiers</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($vendeurs as $vd): ?>
        <tr>
          <td><strong><?= e($vd['nom_complet']) ?></strong></td>
          <td><?= e($vd['email']) ?: '<span class="muted">—</span>' ?></td>
          <td class="text-center"><?= (int) $vd['nb_dossiers'] ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
