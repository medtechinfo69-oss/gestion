<?php
require_once __DIR__ . '/includes/init.php';
require_login();

$user = current_user();
$isAdmin = is_admin();
$canAccessAll = can_access_dossiers();
$hideSupervisorColumns = is_superviseur()
  && in_array(mb_strtolower((string) ($user['username'] ?? '')), ['emma', 'rabia'], true);

$where = "WHERE d.etat_contrat <> 'Actif'";
$params = [];
if (!$canAccessAll) {
    $where .= ' AND d.vendeur_id = :vid';
    $params['vid'] = $user['id'];
}

$stmt = $db->prepare("SELECT d.*, u.nom_complet AS vendeur_nom FROM dossiers d
                       JOIN users u ON u.id = d.vendeur_id
                       $where ORDER BY d.date_vente DESC");
$stmt->execute($params);
$dossiers = $stmt->fetchAll();

$sumStmt = $db->prepare("SELECT COALESCE(SUM(d.ca_annuel),0) FROM dossiers d $where");
$sumStmt->execute($params);
$sumCaAnnule = (float) $sumStmt->fetchColumn();

$pageTitle = 'Dossiers annulés';
$pageSubtitle = $canAccessAll ? 'Mise à jour automatique — tous vendeurs' : 'Mise à jour automatique — vos dossiers';
$activePage = 'annulation';
require __DIR__ . '/includes/header.php';
?>

<div class="stat-grid" style="margin-bottom:20px;">
  <div class="stat-card tone-annule">
    <div class="label">Nombre de dossiers annulés</div>
    <div class="value"><?= count($dossiers) ?></div>
  </div>
  <div class="stat-card tone-annule">
    <div class="label">CA annuel concerné (annulé)</div>
    <div class="value"><?= format_montant($sumCaAnnule) ?></div>
    <div class="hint">Exclu du total « hors annulés » affiché ailleurs dans l'application</div>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <h2>Liste des dossiers annulés</h2>
  </div>
  <div class="table-wrap">
    <?php if (!$dossiers): ?>
      <div class="empty-state">
        <div class="ico">&#9888;</div>
        <p>Aucun dossier annulé pour le moment.</p>
      </div>
    <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Vendeur</th><th>Origine</th><th>Prod</th><th>Date vente</th><th>Civilité</th><th>Nom</th><th>Prénom</th>
          <?php if (!$hideSupervisorColumns): ?><th>Mail</th><th>Téléphone 1</th><th>Téléphone 2</th><th>NB d'assurés</th><th>Date naissance assuré</th><?php endif; ?>
          <?php if (!$hideSupervisorColumns): ?><th>Age assuré principal</th><?php endif; ?>
          <?php if (!$hideSupervisorColumns): ?><th>Adresse</th><th>CP</th><th>Ville</th><?php endif; ?>
          <th>Type de signature</th><th>CA-mois</th><th>CA-annuel</th><th>Date d'effet</th><th>Produit</th><th>Compagnie</th>
          <th>Etat du dossier</th><th>Courrier</th><th>Commentaire dossier</th><th>Etat du contrat</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($dossiers as $d): ?>
        <tr class="row-annule">
          <td><?= e($d['vendeur_nom']) ?></td><td><?= e($d['ta_origine']) ?></td><td><?= e($d['p_prod']) ?></td><td class="nowrap"><?= format_date($d['date_vente']) ?></td><td><?= e($d['civilite']) ?></td><td><a href="<?= e(APP_URL) ?>/dossier_view.php?id=<?= (int) $d['id'] ?>"><?= e($d['nom']) ?></a></td><td><?= e($d['prenom']) ?></td>
          <?php if (!$hideSupervisorColumns): ?><td><?= e($d['mail']) ?></td><td><?= e($d['telfix']) ?></td><td><?= e($d['portable']) ?></td><td><?= (int) $d['nombre_personnes'] ?></td><td><?= e($d['date_naissance_assure']) ?></td><?php endif; ?>
          <?php if (!$hideSupervisorColumns): ?><td><?= e($d['age_assure_principal']) ?></td><?php endif; ?>
          <?php if (!$hideSupervisorColumns): ?><td><?= e($d['adresse']) ?></td><td><?= e($d['cp']) ?></td><td><?= e($d['ville']) ?></td><?php endif; ?>
          <td><?= e($d['type_signature']) ?></td><td class="text-right"><?= format_montant((float) $d['ca_mois']) ?></td><td class="text-right"><?= format_montant((float) $d['ca_annuel']) ?></td><td><?= format_date($d['date_effet']) ?></td><td><?= e($d['produit']) ?></td><td><?= e($d['compagnie']) ?></td>
          <td><?= badge_etat($d['etat_dossier']) ?></td><td><?= e(implode(', ', courrier_values($d['courrier'] ?? ''))) ?: '<span class="muted">—</span>' ?></td><td><?= e($d['commentaire']) ?: '<span class="muted">—</span>' ?></td><td><?= badge_etat_contrat($d['etat_contrat']) ?></td>
          <td><a href="<?= e(APP_URL) ?>/dossier_view.php?id=<?= (int) $d['id'] ?>" class="btn btn-outline btn-sm">Voir</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
