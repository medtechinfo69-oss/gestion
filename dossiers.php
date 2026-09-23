<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/security_integration.php';
require_login();

$user = current_user();
$isAdmin = is_admin();
$canAccessAll = can_access_dossiers();
$hideSupervisorColumns = is_superviseur();
$userName  = (string) ($user['nom_complet'] ?? $user['username'] ?? '');
$userEmail = (string) ($user['email'] ?? '');

sec_log('view', 'dossier', null, 'Access dossiers list');

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
$dateFromInjection = parse_date_fr($_GET['date_from_injection'] ?? '') ?: '';

$etatContratSelected = is_array($etatContratFilter) ? $etatContratFilter : ($etatContratFilter !== '' ? [$etatContratFilter] : []);

$sortableColumns = [
  'date_vente' => 'd.date_vente', 'nom' => 'd.nom', 'ca_mois' => 'd.ca_mois', 'ca_annuel' => 'd.ca_annuel',
    'etat_dossier' => 'd.etat_dossier', 'compagnie' => 'd.compagnie', 'created_at' => 'd.created_at',
    'date_injection' => 'd.date_injection',
];
$sort = $_GET['sort'] ?? 'date_vente';
$sort = array_key_exists($sort, $sortableColumns) ? $sort : 'date_vente';
$dir = (isset($_GET['dir']) && strtolower($_GET['dir']) === 'asc') ? 'ASC' : 'DESC';

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = get_items_per_page();
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
    $searchColumns = [
        'd.nom', 'd.prenom', 'd.civilite', 'd.mail', 'd.telfix', 'd.portable',
        'd.date_naissance_assure', 'd.age_assure_principal', 'd.adresse', 'd.cp', 'd.ville',
        'd.type_signature', 'd.produit', 'd.compagnie', 'd.ta_origine', 'd.p_prod',
        'd.etat_dossier', 'd.etat_contrat', 'd.controle_qualite', 'd.commentaire',
        'd.motif_annulation', 'd.date_injection',
        'CAST(d.ca_mois AS CHAR)', 'CAST(d.ca_annuel AS CHAR)', 'CAST(d.nombre_personnes AS CHAR)',
        'CAST(d.date_vente AS CHAR)', 'CAST(d.date_effet AS CHAR)', 'CAST(d.date_courrier_supervision AS CHAR)',
        'CAST(d.date_etat_contrat_supervision AS CHAR)', 'CAST(d.date_controle_qualite_supervision AS CHAR)',
        'CAST(d.created_at AS CHAR)', 'CAST(d.date_dossier_complet AS CHAR)', 'CAST(d.date_contrat_non_actif AS CHAR)'
    ];
    $searchClauses = [];
    foreach ($searchColumns as $idx => $field) {
        $paramKey = 'q' . $idx;
        $searchClauses[] = $field . ' LIKE :' . $paramKey;
        $params[$paramKey] = '%' . $search . '%';
    }
    $conditions[] = '(' . implode(' OR ', $searchClauses) . ')';
}

if (in_array($etatFilter, etats_dossier_valides(), true)) {
    $conditions[] = 'd.etat_dossier = :etat';
    $params['etat'] = $etatFilter;
}

$etatContratValues = [];
if ($etatContratFilter !== '') {
    $rawValues = is_array($etatContratFilter) ? $etatContratFilter : explode(',', $etatContratFilter);
    $allowed = etats_contrat_valides(true);
    foreach ($rawValues as $v) {
        $v = trim((string) $v);
        if ($v !== '' && in_array($v, $allowed, true)) {
            $etatContratValues[] = $v;
        }
    }
    $etatContratValues = array_values(array_unique($etatContratValues));
    if (!empty($etatContratValues)) {
        // Placeholders nommés : le reste du script lie les paramètres par nom
        // (bindValue(':' . $k)), donc des « ? » anonymes provoqueraient
        // « SQLSTATE[HY093]: Invalid parameter number ».
        $placeholders = [];
        foreach ($etatContratValues as $i => $val) {
            $placeholders[] = ':ec' . $i;
            $params['ec' . $i] = $val;
        }
        $conditions[] = 'd.etat_contrat IN (' . implode(',', $placeholders) . ')';
    }
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
if ($dateFromInjection) {
    $conditions[] = 'd.date_injection = :date_from_injection';
    $params['date_from_injection'] = $dateFromInjection;
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
if ($canAccessAll) {
  $topbarActions .= '<a href="' . e(APP_URL) . '/dossier_form.php" class="btn btn-accent">+ Nouveau dossier</a> '
    . '<a href="' . e(APP_URL) . '/dossiers_import.php" class="btn btn-outline">Importer Excel</a> ';
}
if ($isAdmin) {
  $topbarActions .= '<form method="post" action="' . e(APP_URL) . '/actions/dossier_secure_export.php" class="" style="display:inline-block;vertical-align:top;margin:0;">' . csrf_field() . '
    <input type="hidden" name="q" value="' . e($search) . '">
    <input type="hidden" name="etat" value="' . e($etatFilter) . '">
    <input type="hidden" name="etat_contrat" value="' . (is_array($etatContratFilter) ? implode(',', array_map('e', $etatContratFilter)) : e($etatContratFilter)) . '">
    <input type="hidden" name="vendeur" value="' . (int) $vendeurFilter . '">
    <input type="hidden" name="compagnie" value="' . e($compagnieFilter) . '">
    <input type="hidden" name="date_from" value="' . e($dateFrom) . '">
    <input type="hidden" name="date_to" value="' . e($dateTo) . '">
    <input type="hidden" name="send_email" value="1">
<button type="submit" class="btn btn-primary">Envoyer Excel par e-mail</button>
  </form>';
}
require __DIR__ . '/includes/header.php';
?>

<style>
  /* Colonnes "Etat du dossier" et "Etat du contrat" : le badge reste sur
     une seule ligne compacte, largeur limitée à 50 caractères, afin que
     le tableau reste aligné (pas de lignes étirées en hauteur). */
  table.data-table--wide thead th.col-etat,
  table.data-table--wide tbody td.col-etat {
    max-width: 320px;
    white-space: nowrap;
  }
  table.data-table--wide tbody td.col-etat .badge {
    max-width: 320px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: middle;
  }

  /* Filtres : même traitement que la zone de filtres de l'historique RH
     (hauteur uniforme 42px + liste déroulante confortable avec défilement). */
  .toolbar select,
  .toolbar input[type=search],
  .toolbar input[type=date] {
    height: 42px;
    box-sizing: border-box;
  }
  .toolbar .form-group.toolbar-action .btn {
    height: 42px;
    display: inline-flex;
    align-items: center;
    white-space: nowrap;
    justify-content: center;
  }
  .toolbar select option {
    padding: 6px 8px;
  }
  .toolbar select {
    min-width: 150px;
  }
</style>

<div class="card">
  <form class="toolbar" method="get" action="">
    <div class="form-group">
      <label for="q">Recherche</label>
      <input type="search" id="q" name="q" placeholder="Tous les champs : nom, vendeur, compagnie, mail, produit…" value="<?= e($search) ?>">
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
      <div class="multiselect-dropdown" data-multiselect id="etat_contrat"
           data-name="etat_contrat[]"
           data-selected='<?= json_encode($etatContratSelected) ?>'
           data-options='<?= json_encode(array_map(function ($e) { return ['value' => $e, 'label' => $e]; }, etats_contrat_valides())) ?>'>
      </div>
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
    <div class="form-group" style="border-left:1px solid var(--color-line);">
      <label for="date_from_injection">Date injection</label>
      <input type="date" id="date_from_injection" name="date_from_injection" value="<?= e($dateFromInjection) ?>">
    </div>
    <div class="form-group toolbar-action">
      <button type="submit" class="btn btn-primary btn-sm" title="Appliquer les filtres">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/></svg>
      </button>
    </div>
    <?php if ($search || $etatFilter || $etatContratFilter || $vendeurFilter || $compagnieFilter || $dateFrom || $dateTo || $dateFromInjection): ?>
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
    <table class="data-table data-table--wide">
      <thead>
        <tr>
          <?php if ($isAdmin): ?><th class="selection-cell"><input type="checkbox" data-select-all aria-label="Sélectionner tous les dossiers affichés"></th><?php endif; ?>
          <th>Vendeur</th><th>Origine</th><th>Prod</th>
          <th><?= sort_link('date_vente', 'Date vente', $sort, $dir) ?></th><th>Civilité</th><th><?= sort_link('nom', 'Nom', $sort, $dir) ?></th><th>Prénom</th>
          <th>Date d'effet</th>
          <?php if (!$hideSupervisorColumns): ?><th>Produit</th><th><?= sort_link('compagnie', 'Compagnie', $sort, $dir) ?></th><?php endif; ?>
          <th class="col-etat">Etat du dossier</th><th>Courrier</th><th>Commentaire dossier</th><th class="col-etat">Etat du contrat</th><th>Contrôle qualité</th><th>Date d'injection</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($dossiers as $d): ?>
        <tr class="<?= row_class_etat($d['etat_dossier']) ?>">
          <?php if ($isAdmin): ?><td class="selection-cell"><input type="checkbox" name="dossier_ids[]" value="<?= (int) $d['id'] ?>" data-dossier-select aria-label="Sélectionner le dossier <?= (int) $d['id'] ?>"></td><?php endif; ?>
          <td title="<?= e($d['vendeur_nom']) ?>"><?= e(truncate_chars($d['vendeur_nom'])) ?: '<span class="muted">—</span>' ?></td>
          <td title="<?= e($d['ta_origine']) ?>"><?= e(truncate_chars($d['ta_origine'])) ?: '<span class="muted">—</span>' ?></td>
          <td title="<?= e($d['p_prod']) ?>"><?= e(truncate_chars($d['p_prod'])) ?: '<span class="muted">—</span>' ?></td>
          <td class="nowrap"><?= format_date($d['date_vente']) ?></td>
          <td><?= e(truncate_chars($d['civilite'])) ?></td>
          <td><a href="<?= e(APP_URL) ?>/dossier_view.php?id=<?= (int) $d['id'] ?>" title="<?= e($d['nom']) ?>"><?= e(truncate_chars($d['nom'])) ?></a></td>
          <td title="<?= e($d['prenom']) ?>"><?= e(truncate_chars($d['prenom'])) ?></td>
          <td><?= format_date($d['date_effet']) ?></td>
          <?php if (!$hideSupervisorColumns): ?>
          <td title="<?= e($d['produit']) ?>"><?= e(truncate_chars($d['produit'])) ?: '<span class="muted">—</span>' ?></td>
          <td title="<?= e($d['compagnie']) ?>"><?= e(truncate_chars($d['compagnie'])) ?: '<span class="muted">—</span>' ?></td>
          <?php endif; ?>
          <td class="col-etat"><?= badge_etat(truncate_chars($d['etat_dossier'])) ?></td>
          <td title="<?= e(implode(', ', courrier_values($d['courrier'] ?? ''))) ?>"><?= e(truncate_chars(implode(', ', courrier_values($d['courrier'] ?? '')))) ?: '<span class="muted">—</span>' ?></td>
          <td title="<?= e($d['commentaire']) ?>"><?= e(truncate_chars($d['commentaire'])) ?: '<span class="muted">—</span>' ?></td>
          <td class="col-etat"><?= badge_etat_contrat(truncate_chars($d['etat_contrat'])) ?></td>
          <td title="<?= e($d['controle_qualite'] ?? '') ?>"><?= e(truncate_chars($d['controle_qualite'] ?? '')) ?: '<span class="muted">—</span>' ?></td>
          <td class="nowrap"><?= format_date(($d['date_injection'] ?? null)) ?: '<span class="muted">—</span>' ?></td>
          <td class="nowrap">
            <span class="row-actions">
            <a href="<?= e(APP_URL) ?>/dossier_view.php?id=<?= (int) $d['id'] ?>" class="btn btn-outline btn-sm btn-icon-action" title="Voir" aria-label="Voir"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg></a>
            <?php if ($isAdmin): ?>
            <a href="<?= e(APP_URL) ?>/dossier_form.php?id=<?= (int) $d['id'] ?>" class="btn btn-outline btn-sm btn-icon-action" title="Modifier" aria-label="Modifier"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg></a>
            <?php endif; ?>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php if ($isAdmin && $dossiers): ?>
  <div class="bulk-actions" data-bulk-actions>
    <span class="bulk-count" data-selection-count>0 dossier sélectionné</span>
    <div class="flex gap-8">
      <button type="button" class="btn btn-outline btn-sm" data-bulk-edit disabled>Modifier la sélection</button>
      <button type="button" class="btn btn-danger btn-sm" data-bulk-delete disabled>Supprimer la sélection</button>
    </div>
  </div>
  <form action="<?= e(APP_URL) ?>/actions/dossier_bulk_delete.php" method="post" data-bulk-delete-form>
    <?= csrf_field() ?>
  </form>
  <?php endif; ?>

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

  <?php if (!$hideSupervisorColumns): ?>
  <div class="dossiers-totals">
    <div><strong>Total CA annuel :</strong> <?= format_montant($sumCa) ?></div>
    <div><strong>Total chiffre d'affaire (dossier complet) :</strong> <?= format_montant($sumCaComplete) ?></div>
  </div>
  <?php endif; ?>
</div>

<script>
(function() {
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-multiselect]').forEach(function (el) {
      var optionsRaw = el.getAttribute('data-options');
      var selectedRaw = el.getAttribute('data-selected');
      new MultiSelectDropdown(el, {
        options: optionsRaw ? JSON.parse(optionsRaw) : [],
        selected: selectedRaw ? JSON.parse(selectedRaw) : [],
        name: el.getAttribute('data-name') || 'etat_contrat[]',
        onchange: function (values) {
          var select = el.querySelector('select');
          if (select) {
            select.querySelectorAll('option').forEach(function (opt) { opt.remove(); });
            values.forEach(function (v) {
              var opt = document.createElement('option');
              opt.value = v;
              opt.selected = true;
              select.appendChild(opt);
            });
          }
        }
      });
    });
  });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

<?php if ($isAdmin): ?>
<script>
(function() {
  var userName = <?= json_encode($userName) ?>;
  var userEmail = <?= json_encode($userEmail) ?>;
  var pageUrl = window.location.href;
  var csrfToken = <?= json_encode(csrf_token()) ?>;

  function reportSecurityEvent(type, description, details) {
    fetch('actions/security_alert.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify({
        type: type,
        description: description,
        details: details || '',
        user: userName,
        email: userEmail,
        page: pageUrl,
        timestamp: new Date().toISOString()
      })
    }).catch(function() {});
  }

  // La protection anti-copie (user-select:none, blocage copier/couper, clic droit,
  // sélection, glisser-déposer) a été supprimée : l'administrateur peut copier.
  // Seule la protection impression/source/F12 est conservée.

  document.addEventListener('keydown', function(e) {
    if (e.key === 'PrintScreen') {
      reportSecurityEvent('print_screen', 'PrintScreen key pressed');
    }
    if (e.ctrlKey || e.metaKey) {
      switch(e.key.toLowerCase()) {
        case 'p':
          e.preventDefault();
          reportSecurityEvent('print_attempt', 'Ctrl+P (Print) blocked');
          break;
        case 'u':
          e.preventDefault();
          reportSecurityEvent('view_source_attempt', 'Ctrl+U (View Source) blocked');
          break;
      }
    }
    if (e.key === 'F12') {
      e.preventDefault();
      reportSecurityEvent('dev_tools_attempt', 'F12 (Dev Tools) blocked');
    }
  });
})();
</script>
<?php endif; ?>
