<?php
require_once __DIR__ . '/includes/init.php';
require_login();

$user = current_user();
$isAdmin = is_admin();
$canAccessAll = can_access_dossiers();
$hideSupervisorColumns = is_superviseur()
  && in_array(mb_strtolower((string) ($user['username'] ?? '')), ['emma', 'rabia'], true);

// ---------------------------------------------------------------------
// Filtres
// ---------------------------------------------------------------------
$search      = trim($_GET['q'] ?? '');
$etatFilter  = $_GET['etat'] ?? '';
$etatContratFilter = $_GET['etat_contrat'] ?? '';
$vendeurFilter = filter_var($_GET['vendeur'] ?? '', FILTER_VALIDATE_INT) ?: 0;
$compagnieFilter = trim($_GET['compagnie'] ?? '');
$dateFrom = parse_date_fr($_GET['date_from'] ?? '') ?: '';
$dateTo   = parse_date_fr($_GET['date_to'] ?? '') ?: '';

$sortableColumns = [
  'date_vente' => 'd.date_vente', 'nom' => 'd.nom', 'ca_mois' => 'd.ca_mois', 'ca_annuel' => 'd.ca_annuel',
    'etat_dossier' => 'd.etat_dossier', 'compagnie' => 'd.compagnie', 'created_at' => 'd.created_at',
];
$sort = $_GET['sort'] ?? 'date_vente';
$sort = array_key_exists($sort, $sortableColumns) ? $sort : 'date_vente';
$dir = (isset($_GET['dir']) && strtolower($_GET['dir']) === 'asc') ? 'ASC' : 'DESC';

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = ITEMS_PER_PAGE;
$offset = ($page - 1) * $perPage;

// ---------------------------------------------------------------------
// Construction de la requête (paramètres liés, jamais de concaténation brute)
// ---------------------------------------------------------------------
$conditions = [];
$params = [];

if (!$canAccessAll) {
    $conditions[] = 'd.vendeur_id = :own_vendeur';
    $params['own_vendeur'] = $user['id'];
}

if ($search !== '') {
    $conditions[] = '(d.nom LIKE :q1 OR d.prenom LIKE :q2 OR d.mail LIKE :q3 OR d.portable LIKE :q4 OR d.ville LIKE :q5 OR d.produit LIKE :q6)';
    $like = '%' . $search . '%';
    $params['q1'] = $like; $params['q2'] = $like; $params['q3'] = $like;
    $params['q4'] = $like; $params['q5'] = $like; $params['q6'] = $like;
}

if (in_array($etatFilter, etats_dossier_valides(), true)) {
    $conditions[] = 'd.etat_dossier = :etat';
    $params['etat'] = $etatFilter;
}

if (in_array($etatContratFilter, etats_contrat_valides(), true)) {
  $conditions[] = 'd.etat_contrat = :etat_contrat';
  $params['etat_contrat'] = $etatContratFilter;
}

if ($canAccessAll && $vendeurFilter) {
    $conditions[] = 'd.vendeur_id = :vendeur_id';
    $params['vendeur_id'] = $vendeurFilter;
}

if ($compagnieFilter !== '') {
    $conditions[] = 'd.compagnie = :compagnie';
    $params['compagnie'] = $compagnieFilter;
}

if ($dateFrom) {
    $conditions[] = 'd.date_vente >= :date_from';
    $params['date_from'] = $dateFrom;
}
if ($dateTo) {
    $conditions[] = 'd.date_vente <= :date_to';
    $params['date_to'] = $dateTo;
}

$whereSql = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

$countStmt = $db->prepare("SELECT COUNT(*) FROM dossiers d $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$orderSql = $sortableColumns[$sort] . ' ' . $dir;
$sql = "SELECT d.*, u.nom_complet AS vendeur_nom
        FROM dossiers d
        JOIN users u ON u.id = d.vendeur_id
        $whereSql
        ORDER BY $orderSql
        LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue(':' . $k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$dossiers = $stmt->fetchAll();

// Total du CA affiché pour la sélection filtrée (hors annulés)
$sumStmt = $db->prepare("SELECT COALESCE(SUM(CASE WHEN d.etat_contrat = 'Actif' THEN d.ca_annuel ELSE 0 END),0)
                          FROM dossiers d $whereSql");
$sumStmt->execute($params);
$sumCa = (float) $sumStmt->fetchColumn();

$sumCompleteStmt = $db->prepare("SELECT COALESCE(SUM(CASE WHEN d.etat_dossier = 'Dossier complet' THEN d.ca_annuel ELSE 0 END),0)
                                 FROM dossiers d $whereSql");
$sumCompleteStmt->execute($params);
$sumCaComplete = (float) $sumCompleteStmt->fetchColumn();

// Liste des vendeurs actifs pour le filtre (admin)
$vendeurs = [];
if ($canAccessAll) {
  $vendeurs = $db->query("SELECT id, nom_complet FROM users WHERE role = 'vendeur' ORDER BY nom_complet")->fetchAll();
}
$compagnies = $db->query("SELECT DISTINCT compagnie FROM dossiers ORDER BY compagnie")->fetchAll(PDO::FETCH_COLUMN);

function sort_link(string $col, string $label, string $sort, string $dir): string
{
    $newDir = ($sort === $col && $dir === 'ASC') ? 'desc' : 'asc';
    $arrow = $sort === $col ? ($dir === 'ASC' ? ' &uarr;' : ' &darr;') : '';
    $qs = $_GET;
    $qs['sort'] = $col;
    $qs['dir'] = $newDir;
    return '<a href="?' . http_build_query($qs) . '">' . e($label) . $arrow . '</a>';
}

$pageTitle = 'Dossiers';
$pageSubtitle = $canAccessAll ? 'Ensemble des dossiers enregistrés' : 'Vos dossiers';
$activePage = 'dossiers';
$exportQuery = $_GET;
unset($exportQuery['page']);
$exportUrl = APP_URL . '/actions/dossiers_export.php' . ($exportQuery ? '?' . http_build_query($exportQuery) : '');
$topbarActions = '';
if ($isAdmin) {
  $topbarActions .= '<a href="' . e(APP_URL) . '/dossier_form.php" class="btn btn-accent">+ Nouveau dossier</a> '
    . '<a href="' . e(APP_URL) . '/dossiers_import.php" class="btn btn-outline">Importer Excel</a> ';
}
if ($canAccessAll) {
  $topbarActions .= '<a href="' . e($exportUrl) . '" class="btn btn-outline">Exporter Excel</a>';
}
require __DIR__ . '/includes/header.php';
?>

<div class="card">
  <form class="toolbar" method="get" action="">
    <div class="form-group">
      <label for="q">Recherche</label>
      <input type="search" id="q" name="q" placeholder="Nom, mail, portable, ville…" value="<?= e($search) ?>">
    </div>
    <div class="form-group">
      <label for="etat">État</label>
      <select id="etat" name="etat">
        <option value="">Tous</option>
        <?php foreach (etats_dossier_valides() as $etat): ?>
          <option value="<?= e($etat) ?>" <?= $etatFilter === $etat ? 'selected' : '' ?>><?= e($etat) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label for="etat_contrat">État du contrat</label>
      <select id="etat_contrat" name="etat_contrat">
        <option value="">Tous</option>
        <?php foreach (etats_contrat_valides() as $etatContrat): ?>
          <option value="<?= e($etatContrat) ?>" <?= $etatContratFilter === $etatContrat ? 'selected' : '' ?>><?= e($etatContrat) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($canAccessAll): ?>
    <div class="form-group">
      <label for="vendeur">Vendeur</label>
      <select id="vendeur" name="vendeur">
        <option value="">Tous</option>
        <?php foreach ($vendeurs as $v): ?>
          <option value="<?= (int) $v['id'] ?>" <?= $vendeurFilter === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['nom_complet']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="form-group">
      <label for="compagnie">Compagnie</label>
      <select id="compagnie" name="compagnie">
        <option value="">Toutes</option>
        <?php foreach ($compagnies as $c): ?>
          <option value="<?= e($c) ?>" <?= $compagnieFilter === $c ? 'selected' : '' ?>><?= e($c) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label for="date_from">Vente du</label>
      <input type="date" id="date_from" name="date_from" value="<?= e($dateFrom) ?>">
    </div>
    <div class="form-group">
      <label for="date_to">au</label>
      <input type="date" id="date_to" name="date_to" value="<?= e($dateTo) ?>">
    </div>
    <div class="form-group toolbar-action">
      <button type="submit" class="btn btn-primary btn-sm">Filtrer</button>
    </div>
    <?php if ($search || $etatFilter || $etatContratFilter || $vendeurFilter || $compagnieFilter || $dateFrom || $dateTo): ?>
    <div class="form-group toolbar-action">
      <a href="<?= e(APP_URL) ?>/dossiers.php" class="btn btn-outline btn-sm">Réinitialiser</a>
    </div>
    <?php endif; ?>
  </form>

  <div class="table-wrap">
    <?php if (!$dossiers): ?>
      <div class="empty-state">
        <div class="ico">&#128193;</div>
        <p>Aucun dossier ne correspond à ces critères.</p>
      </div>
    <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Vendeur</th><th>Origine</th><th>Prod</th>
          <th><?= sort_link('date_vente', 'Date vente', $sort, $dir) ?></th><th>Civilité</th><th><?= sort_link('nom', 'Nom', $sort, $dir) ?></th><th>Prénom</th>
          <?php if (!$hideSupervisorColumns): ?><th>Mail</th><th>Téléphone 1</th><th>Téléphone 2</th><th>NB d'assurés</th><th>Date naissance assuré</th><?php endif; ?>
          <?php if (!$hideSupervisorColumns): ?><th>Age assuré principal</th><?php endif; ?>
          <?php if (!$hideSupervisorColumns): ?><th>Adresse</th><th>CP</th><th>Ville</th><?php endif; ?>
          <th>Type de signature</th>
          <th class="text-right"><?= sort_link('ca_mois', 'CA-mois', $sort, $dir) ?></th><th class="text-right">CA-annuel</th><th>Date d'effet</th><th>Produit</th><th><?= sort_link('compagnie', 'Compagnie', $sort, $dir) ?></th>
          <th>Etat du dossier</th><th>Courrier</th><th>Commentaire dossier</th><th>Etat du contrat</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($dossiers as $d): ?>
        <tr class="<?= row_class_etat($d['etat_dossier']) ?>">
          <td><?= e($d['vendeur_nom']) ?></td><td><?= e($d['ta_origine']) ?></td><td><?= e($d['p_prod']) ?></td>
          <td class="nowrap"><?= format_date($d['date_vente']) ?></td><td><?= e($d['civilite']) ?></td>
          <td><a href="<?= e(APP_URL) ?>/dossier_view.php?id=<?= (int) $d['id'] ?>"><?= e($d['nom']) ?></a></td><td><?= e($d['prenom']) ?></td>
          <?php if (!$hideSupervisorColumns): ?><td><?= e($d['mail']) ?></td><td><?= e($d['telfix']) ?></td><td><?= e($d['portable']) ?></td><td><?= (int) $d['nombre_personnes'] ?></td><td><?= e($d['date_naissance_assure']) ?></td><?php endif; ?>
          <?php if (!$hideSupervisorColumns): ?><td><?= e($d['age_assure_principal']) ?></td><?php endif; ?>
          <?php if (!$hideSupervisorColumns): ?><td><?= e($d['adresse']) ?></td><td><?= e($d['cp']) ?></td><td><?= e($d['ville']) ?></td><?php endif; ?>
          <td><?= e($d['type_signature']) ?></td>
          <td class="text-right nowrap"><?= format_montant((float) $d['ca_mois']) ?></td><td class="text-right nowrap"><?= format_montant((float) $d['ca_annuel']) ?></td><td><?= format_date($d['date_effet']) ?></td><td><?= e($d['produit']) ?></td><td><?= e($d['compagnie']) ?></td>
          <td><?= badge_etat($d['etat_dossier']) ?></td><td><?= e(implode(', ', courrier_values($d['courrier'] ?? ''))) ?: '<span class="muted">—</span>' ?></td><td><?= e($d['commentaire']) ?: '<span class="muted">—</span>' ?></td><td><?= badge_etat_contrat($d['etat_contrat']) ?></td>
          <td class="nowrap">
            <a href="<?= e(APP_URL) ?>/dossier_view.php?id=<?= (int) $d['id'] ?>" class="btn btn-outline btn-sm">Voir</a>
            <?php if ($isAdmin): ?>
              <a href="<?= e(APP_URL) ?>/dossier_form.php?id=<?= (int) $d['id'] ?>" class="btn btn-outline btn-sm">Modifier</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php if ($totalPages > 1): ?>
  <div class="pagination">
    <?php for ($p = 1; $p <= $totalPages; $p++):
        $qs = $_GET; $qs['page'] = $p; ?>
      <?php if ($p === $page): ?>
        <span class="current"><?= $p ?></span>
      <?php else: ?>
        <a href="?<?= http_build_query($qs) ?>"><?= $p ?></a>
      <?php endif; ?>
    <?php endfor; ?>
    <span class="muted" style="margin-left:10px;"><?= $total ?> dossier(s) au total</span>
  </div>
  <?php endif; ?>

  <div class="dossiers-totals">
    <div><strong>Total CA annuel :</strong> <?= format_montant($sumCa) ?></div>
    <div><strong>Total chiffre d'affaire (dossier complet) :</strong> <?= format_montant($sumCaComplete) ?></div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
