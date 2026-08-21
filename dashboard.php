<?php
require_once __DIR__ . '/includes/init.php';
require_login();

$user = current_user();
$isAdmin = is_admin();
$canAccessAll = can_access_dossiers();

// ---------------------------------------------------------------------
// Statistiques globales (ou filtrées sur le vendeur connecté)
// Reprend exactement les règles du bloc "STATISTIQUES" du classeur :
//   TOTAL CA-ANNUEL (hors Annulé), Nb dossiers complets/non complets/annulés
// ---------------------------------------------------------------------
$where = '';
$params = [];
if (!$canAccessAll) {
    $where = 'WHERE vendeur_id = :vid';
    $params['vid'] = $user['id'];
}

$stmt = $db->prepare("SELECT
        COALESCE(SUM(CASE WHEN etat_contrat = 'Actif' THEN ca_annuel ELSE 0 END), 0) AS total_ca_annuel,
        SUM(CASE WHEN etat_dossier = 'Dossier complet' THEN 1 ELSE 0 END) AS nb_complets,
        SUM(CASE WHEN etat_dossier = 'Dossier incomplet' THEN 1 ELSE 0 END) AS nb_non_complets,
        SUM(CASE WHEN etat_contrat <> 'Actif' THEN 1 ELSE 0 END) AS nb_annules,
        COUNT(*) AS nb_total
    FROM dossiers $where");
$stmt->execute($params);
$stats = $stmt->fetch();

// Répartition par vendeur (visible pour l'administrateur uniquement)
$parVendeur = [];
if ($isAdmin) {
    $stmt = $db->query("SELECT u.nom_complet,
            COALESCE(SUM(CASE WHEN d.etat_contrat = 'Actif' THEN d.ca_annuel ELSE 0 END), 0) AS total_ca,
            SUM(CASE WHEN d.etat_dossier = 'Dossier complet' THEN 1 ELSE 0 END) AS nb_complets,
            SUM(CASE WHEN d.etat_dossier = 'Dossier incomplet' THEN 1 ELSE 0 END) AS nb_non_complets,
            SUM(CASE WHEN d.etat_contrat <> 'Actif' THEN 1 ELSE 0 END) AS nb_annules,
            COUNT(d.id) AS nb_total
        FROM users u
        LEFT JOIN dossiers d ON d.vendeur_id = u.id
              WHERE u.role = 'vendeur'
        GROUP BY u.id, u.nom_complet
        ORDER BY total_ca DESC");
    $parVendeur = $stmt->fetchAll();
}

// Derniers dossiers ajoutés
$sqlRecent = "SELECT d.*, u.nom_complet AS vendeur_nom FROM dossiers d
              JOIN users u ON u.id = d.vendeur_id $where
              ORDER BY d.created_at DESC LIMIT 8";
$stmt = $db->prepare($sqlRecent);
$stmt->execute($params);
$recents = $stmt->fetchAll();

$pageTitle = 'Tableau de bord';
$pageSubtitle = $isAdmin ? 'Vue d’ensemble de tous les dossiers' : 'Vue d’ensemble de vos dossiers';
$activePage = 'dashboard';
require __DIR__ . '/includes/header.php';
?>

<div class="stat-grid">
  <div class="stat-card tone-total">
    <div class="label">Total CA annuel (hors annulés)</div>
    <div class="value"><?= format_montant((float) $stats['total_ca_annuel']) ?></div>
    <div class="hint"><?= (int) $stats['nb_total'] ?> dossier(s) au total</div>
  </div>
  <div class="stat-card tone-complet">
    <div class="label">Dossiers complets</div>
    <div class="value"><?= (int) $stats['nb_complets'] ?></div>
    <div class="hint">Prêts à être transmis</div>
  </div>
  <div class="stat-card tone-noncomplet">
    <div class="label">Dossiers non complets</div>
    <div class="value"><?= (int) $stats['nb_non_complets'] ?></div>
    <div class="hint">Pièces manquantes</div>
  </div>
  <div class="stat-card tone-annule">
    <div class="label">Dossiers annulés</div>
    <div class="value"><?= (int) $stats['nb_annules'] ?></div>
    <div class="hint">Hors calcul du CA</div>
  </div>
</div>

<?php if ($isAdmin && $parVendeur): ?>
<div class="card mb-16">
  <div class="card-header">
    <h2>Performance par vendeur</h2>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Vendeur</th>
          <th class="text-right">CA annuel (hors annulés)</th>
          <th class="text-center">Complets</th>
          <th class="text-center">Non complets</th>
          <th class="text-center">Annulés</th>
          <th class="text-center">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($parVendeur as $v): ?>
        <tr>
          <td><?= e($v['nom_complet']) ?></td>
          <td class="text-right"><strong><?= format_montant((float) $v['total_ca']) ?></strong></td>
          <td class="text-center"><?= (int) $v['nb_complets'] ?></td>
          <td class="text-center"><?= (int) $v['nb_non_complets'] ?></td>
          <td class="text-center"><?= (int) $v['nb_annules'] ?></td>
          <td class="text-center"><?= (int) $v['nb_total'] ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <h2>Derniers dossiers</h2>
    <a href="<?= e(APP_URL) ?>/dossiers.php" class="btn btn-outline btn-sm">Voir tous les dossiers</a>
  </div>
  <div class="table-wrap">
    <?php if (!$recents): ?>
      <div class="empty-state">
        <div class="ico">&#128193;</div>
        <p>Aucun dossier pour le moment.</p>
      </div>
    <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Date vente</th>
          <th>Client</th>
          <?php if ($canAccessAll): ?><th>Vendeur</th><?php endif; ?>
          <th>Produit</th>
          <th class="text-right">CA annuel</th>
          <th>État</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recents as $d): ?>
        <tr class="<?= row_class_etat($d['etat_dossier']) ?>">
          <td class="nowrap"><?= format_date($d['date_vente']) ?></td>
          <td><a href="<?= e(APP_URL) ?>/dossier_view.php?id=<?= (int) $d['id'] ?>"><?= e($d['nom'] . ' ' . $d['prenom']) ?></a></td>
          <?php if ($canAccessAll): ?><td><?= e($d['vendeur_nom']) ?></td><?php endif; ?>
          <td><?= e($d['produit']) ?></td>
          <td class="text-right"><?= format_montant((float) $d['ca_annuel']) ?></td>
          <td><?= badge_etat($d['etat_dossier']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
