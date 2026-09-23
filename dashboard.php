<?php
require_once __DIR__ . '/includes/init.php';
require_login();

$user = current_user();
$isAdmin = is_admin();
$canAccessAll = can_access_dossiers();
// Rôle strict « vendeur » (même si can_supervise=1) : ses tableaux de
// performance n'afficheront QUE sa propre ligne.
$isVendeurSession = is_vendeur_user();
$currentYear = (int) date('Y');

// ---------------------------------------------------------------------
// Filtres « Performance par vendeur » — EXACTEMENT identiques au bloc
// « Filtres du tableau de bord » : vendeur + jour / mois / année en
// listes déroulantes alimentées par les dates réellement enregistrées
// dans les dossiers (colonne date_vente). Utilise ses propres paramètres
// GET (perf_*) pour ne pas affecter les autres cartes du tableau de bord.
// ---------------------------------------------------------------------
$perfVendeurFilter = (int) (filter_var($_GET['perf_vendeur'] ?? '', FILTER_VALIDATE_INT) ?: 0);
// Par défaut : la date du jour. Si le paramètre est présent mais vide (« Tous »),
// aucun filtre n'est appliqué sur ce champ.
$perfJourRaw = array_key_exists('perf_jour', $_GET) ? trim((string) $_GET['perf_jour']) : (string) date('j');
$perfMoisRaw = array_key_exists('perf_mois', $_GET) ? trim((string) $_GET['perf_mois']) : (string) date('n');
$perfAnneeRaw = array_key_exists('perf_annee', $_GET) ? trim((string) $_GET['perf_annee']) : (string) $currentYear;
$perfJourFilter = filter_var($perfJourRaw, FILTER_VALIDATE_INT);
$perfJourFilter = ($perfJourFilter !== false && $perfJourFilter >= 1 && $perfJourFilter <= 31) ? (int) $perfJourFilter : null;
$perfMoisFilter = filter_var($perfMoisRaw, FILTER_VALIDATE_INT);
$perfMoisFilter = ($perfMoisFilter !== false && $perfMoisFilter >= 1 && $perfMoisFilter <= 12) ? (int) $perfMoisFilter : null;
$perfAnneeFilter = filter_var($perfAnneeRaw !== '' ? $perfAnneeRaw : (string) $currentYear, FILTER_VALIDATE_INT);
$perfAnneeFilter = ($perfAnneeFilter !== false && $perfAnneeFilter >= 1900 && $perfAnneeFilter <= 2100) ? (int) $perfAnneeFilter : $currentYear;
$perfHasDateFilter = $perfJourRaw !== '' || $perfMoisRaw !== '' || $perfAnneeRaw !== '';

// Filtres « Performance vendeur par chiffre d'affaire » — indépendants des
// filtres ci-dessus, avec par défaut la date du jour (jour / mois / année).
$caVendeurFilter = (int) (filter_var($_GET['ca_vendeur'] ?? '', FILTER_VALIDATE_INT) ?: 0);
$caJourRaw = array_key_exists('ca_jour', $_GET) ? trim((string) $_GET['ca_jour']) : (string) date('j');
$caMoisRaw = array_key_exists('ca_mois', $_GET) ? trim((string) $_GET['ca_mois']) : (string) date('n');
$caAnneeRaw = array_key_exists('ca_annee', $_GET) ? trim((string) $_GET['ca_annee']) : (string) $currentYear;
$caJourFilter = filter_var($caJourRaw, FILTER_VALIDATE_INT);
$caJourFilter = ($caJourFilter !== false && $caJourFilter >= 1 && $caJourFilter <= 31) ? (int) $caJourFilter : null;
$caMoisFilter = filter_var($caMoisRaw, FILTER_VALIDATE_INT);
$caMoisFilter = ($caMoisFilter !== false && $caMoisFilter >= 1 && $caMoisFilter <= 12) ? (int) $caMoisFilter : null;
$caAnneeFilter = filter_var($caAnneeRaw !== '' ? $caAnneeRaw : (string) $currentYear, FILTER_VALIDATE_INT);
$caAnneeFilter = ($caAnneeFilter !== false && $caAnneeFilter >= 1900 && $caAnneeFilter <= 2100) ? (int) $caAnneeFilter : $currentYear;

// Session au rôle « vendeur » : le filtre « Vendeur » est sans objet et les
// tableaux de performance ne contiennent que la propre ligne du vendeur.
if ($isVendeurSession) {
    $perfVendeurFilter = 0;
    $caVendeurFilter = 0;
}

$monthLabels = [
  1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin',
  7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
];

// ---------------------------------------------------------------------
// Filtres du tableau de bord (vendeur + jour / mois / année en saisie texte)
//  - "Vendeur" : liste des vendeurs (admin / superviseur uniquement)
//  - "Jour", "Mois", "Année" : champs texte (saisie libre, sans liste déroulante)
//    Jour et Mois vides = pas de filtre sur ce champ. Année vide = année en cours.
// ---------------------------------------------------------------------
// Par défaut (premier chargement, sans paramètre GET) : la date du jour.
// Si le paramètre est présent mais vide (« Tous »), aucun filtre n'est appliqué.
$jourRaw = array_key_exists('jour', $_GET) ? trim((string) $_GET['jour']) : (string) date('j');
$moisRaw = array_key_exists('mois', $_GET) ? trim((string) $_GET['mois']) : (string) date('n');
$anneeRaw = trim((string) ($_GET['annee'] ?? ''));
$vendeurFilter = (int) (filter_var($_GET['vendeur'] ?? '', FILTER_VALIDATE_INT) ?: 0);
$jourFilter = filter_var($jourRaw, FILTER_VALIDATE_INT);
$jourFilter = ($jourFilter !== false && $jourFilter >= 1 && $jourFilter <= 31) ? (int) $jourFilter : null;
$moisFilter = filter_var($moisRaw, FILTER_VALIDATE_INT);
$moisFilter = ($moisFilter !== false && $moisFilter >= 1 && $moisFilter <= 12) ? (int) $moisFilter : null;
$anneeFilter = filter_var($anneeRaw !== '' ? $anneeRaw : (string) $currentYear, FILTER_VALIDATE_INT);
$anneeFilter = ($anneeFilter !== false && $anneeFilter >= 1900 && $anneeFilter <= 2100) ? (int) $anneeFilter : $currentYear;

// ---------------------------------------------------------------------
// Récupération des dates (jour / mois / année) réellement enregistrées
// dans les dossiers, directement depuis la même source que dossiers.php
// (colonne date_vente de la table dossiers). Les filtres du tableau de
// bord utilisent ainsi EXACTEMENT les mêmes informations de date que
// celles saisies dans dossiers.php.
// ---------------------------------------------------------------------
$availableJours = $db->query("SELECT DISTINCT DAY(date_vente) AS j
        FROM dossiers
        WHERE date_vente IS NOT NULL
        ORDER BY j")->fetchAll(PDO::FETCH_COLUMN);
$availableJours = array_map('intval', $availableJours);
// Le jour d'aujourd'hui doit toujours être sélectionnable (valeur par défaut du filtre).
if (!in_array((int) date('j'), $availableJours, true)) {
    $availableJours[] = (int) date('j');
}
sort($availableJours);

$availableMois = $db->query("SELECT DISTINCT MONTH(date_vente) AS m
        FROM dossiers
        WHERE date_vente IS NOT NULL
        ORDER BY m")->fetchAll(PDO::FETCH_COLUMN);
$availableMois = array_map('intval', $availableMois);
// Le mois d'aujourd'hui doit toujours être sélectionnable (valeur par défaut du filtre).
if (!in_array((int) date('n'), $availableMois, true)) {
    $availableMois[] = (int) date('n');
}
sort($availableMois);

$availableAnnees = $db->query("SELECT DISTINCT YEAR(date_vente) AS a
        FROM dossiers
        WHERE date_vente IS NOT NULL
        ORDER BY a DESC")->fetchAll(PDO::FETCH_COLUMN);
$availableAnnees = array_map('intval', $availableAnnees);
// L'année en cours reste sélectionnable même si aucun dossier n'existe.
if (!in_array($currentYear, $availableAnnees, true)) {
    $availableAnnees[] = $currentYear;
}
sort($availableAnnees);

$vendeurs = [];
if ($canAccessAll) {
  $vendeurs = $db->query("SELECT id, nom_complet FROM users WHERE role = 'vendeur' ORDER BY nom_complet")->fetchAll();
  if ($vendeurFilter) {
    $vendeurFound = false;
    foreach ($vendeurs as $v) {
      if ((int) $v['id'] === $vendeurFilter) {
        $vendeurFound = true;
        break;
      }
    }
    if (!$vendeurFound) {
      $vendeurFilter = 0;
    }
  }
}

$selectedVendeurNom = 'Tous les vendeurs';
if ($vendeurFilter) {
  foreach ($vendeurs as $v) {
    if ((int) $v['id'] === $vendeurFilter) {
      $selectedVendeurNom = $v['nom_complet'];
      break;
    }
  }
} elseif (!$canAccessAll) {
  $selectedVendeurNom = $user['nom_complet'] ?? 'Mes dossiers';
}

$anneeStart = sprintf('%04d-01-01', $anneeFilter);
$anneeEnd = sprintf('%04d-01-01', $anneeFilter + 1);

// Libellé de période affiché sous les cartes (ex : "en 2026", "en 03/2026", "le 15/03/2026").
$periodeLabel = 'en ' . sprintf('%04d', $anneeFilter);
if ($moisFilter !== null && $jourFilter !== null) {
    $periodeLabel = 'le ' . sprintf('%02d/%02d/%04d', $jourFilter, $moisFilter, $anneeFilter);
} elseif ($moisFilter !== null) {
    $periodeLabel = 'en ' . sprintf('%02d/%04d', $moisFilter, $anneeFilter);
} elseif ($jourFilter !== null) {
    $periodeLabel = 'le ' . sprintf('%02d', $jourFilter) . ' de chaque mois en ' . sprintf('%04d', $anneeFilter);
}
$hasDateFilter = $jourFilter !== null || $moisFilter !== null || $anneeFilter !== $currentYear
    || $jourRaw !== '' || $moisRaw !== '' || $anneeRaw !== '';

// ---------------------------------------------------------------------
// Statistiques globales (filtrées vendeur + jour / mois / année)
// Reprend exactement les règles du bloc "STATISTIQUES" du classeur :
//   TOTAL CA-ANNUEL (hors Annulé), Nb dossiers complets/non complets/annulés
// ---------------------------------------------------------------------
$statsConditions = [];
$statsParams = [];
if (!$canAccessAll) {
    $statsConditions[] = 'vendeur_id = :vid';
    $statsParams['vid'] = $user['id'];
} elseif ($vendeurFilter > 0) {
    $statsConditions[] = 'vendeur_id = :fvid';
    $statsParams['fvid'] = $vendeurFilter;
}
$statsConditions[] = 'date_vente >= :ystart AND date_vente < :yend';
$statsParams['ystart'] = $anneeStart;
$statsParams['yend'] = $anneeEnd;
if ($moisFilter !== null) {
    $statsConditions[] = 'MONTH(date_vente) = :fmois';
    $statsParams['fmois'] = $moisFilter;
}
if ($jourFilter !== null) {
    $statsConditions[] = 'DAY(date_vente) = :fjour';
    $statsParams['fjour'] = $jourFilter;
}
$statsWhere = 'WHERE ' . implode(' AND ', $statsConditions);

$stmt = $db->prepare("SELECT
        COALESCE(SUM(CASE WHEN etat_contrat = 'Actif' THEN ca_annuel ELSE 0 END), 0) AS total_ca_annuel,
        SUM(CASE WHEN etat_dossier = 'Dossier complet' THEN 1 ELSE 0 END) AS nb_complets,
        SUM(CASE WHEN etat_dossier = 'Dossier incomplet' THEN 1 ELSE 0 END) AS nb_non_complets,
        SUM(CASE WHEN etat_contrat <> 'Actif' THEN 1 ELSE 0 END) AS nb_annules,
        COUNT(*) AS nb_total
    FROM dossiers $statsWhere");
$stmt->execute($statsParams);
$stats = $stmt->fetch();

// Répartition par vendeur : mêmes tableaux pour l'admin et pour une session
// au rôle « vendeur » — un vendeur ne voit que SA propre ligne (son nom),
// même avec can_supervise=1 ; admin / superviseurs voient tous les vendeurs.
$parVendeur = [];
$parVendeurCa = [];
{
  // Mêmes règles de filtrage que « Filtres du tableau de bord » :
  // année (plage du 01/01 au 01/01), puis mois et jour si sélectionnés.
  $performanceParams = [];
  $performanceStart = sprintf('%04d-01-01', $perfAnneeFilter);
  $performanceEnd = sprintf('%04d-01-01', $perfAnneeFilter + 1);
  $performanceOn = 'd.vendeur_id = u.id
                AND d.date_vente >= :performance_start AND d.date_vente < :performance_end';
  $performanceParams['performance_start'] = $performanceStart;
  $performanceParams['performance_end'] = $performanceEnd;
  if ($perfMoisFilter !== null) {
    $performanceOn .= ' AND MONTH(d.date_vente) = :performance_mois';
    $performanceParams['performance_mois'] = $perfMoisFilter;
  }
  if ($perfJourFilter !== null) {
    $performanceOn .= ' AND DAY(d.date_vente) = :performance_jour';
    $performanceParams['performance_jour'] = $perfJourFilter;
  }
  $performanceVendeurWhere = $perfVendeurFilter > 0 ? ' AND u.id = :performance_vendeur' : '';
  if ($perfVendeurFilter > 0) {
    $performanceParams['performance_vendeur'] = $perfVendeurFilter;
  }
  if ($isVendeurSession) {
    $performanceVendeurWhere .= ' AND u.id = :performance_self';
    $performanceParams['performance_self'] = (int) $user['id'];
  }
  $stmt = $db->prepare("SELECT u.nom_complet,
            COALESCE(SUM(CASE WHEN d.etat_contrat = 'Actif' THEN d.ca_annuel ELSE 0 END), 0) AS total_ca,
            SUM(CASE WHEN d.etat_dossier = 'Dossier complet' THEN 1 ELSE 0 END) AS nb_complets,
            SUM(CASE WHEN d.etat_dossier = 'Dossier incomplet' THEN 1 ELSE 0 END) AS nb_non_complets,
            SUM(CASE WHEN d.etat_contrat <> 'Actif' THEN 1 ELSE 0 END) AS nb_annules,
            COUNT(d.id) AS nb_total
        FROM users u
        LEFT JOIN dossiers d ON $performanceOn
              WHERE u.role = 'vendeur'$performanceVendeurWhere
        GROUP BY u.id, u.nom_complet
        ORDER BY total_ca DESC");
    $stmt->execute($performanceParams);
    $parVendeur = $stmt->fetchAll();

    // Performance vendeur par chiffre d'affaire : CA réparti selon
    // l'état des dossiers (Complets / Non complets / Annulés / Total),
    // avec ses propres filtres (ca_*) — par défaut la date du jour.
    $caParams = [];
    $caStart = sprintf('%04d-01-01', $caAnneeFilter);
    $caEnd = sprintf('%04d-01-01', $caAnneeFilter + 1);
    $caOn = "d.vendeur_id = u.id
                AND d.date_vente >= :ca_start AND d.date_vente < :ca_end";
    $caParams['ca_start'] = $caStart;
    $caParams['ca_end'] = $caEnd;
    if ($caMoisFilter !== null) {
        $caOn .= ' AND MONTH(d.date_vente) = :ca_fmois';
        $caParams['ca_fmois'] = $caMoisFilter;
    }
    if ($caJourFilter !== null) {
        $caOn .= ' AND DAY(d.date_vente) = :ca_fjour';
        $caParams['ca_fjour'] = $caJourFilter;
    }
    $caVendeurWhere = $caVendeurFilter > 0 ? ' AND u.id = :ca_fvendeur' : '';
    if ($caVendeurFilter > 0) {
        $caParams['ca_fvendeur'] = $caVendeurFilter;
    }
    if ($isVendeurSession) {
        $caVendeurWhere .= ' AND u.id = :ca_self';
        $caParams['ca_self'] = (int) $user['id'];
    }
    $stmt = $db->prepare("SELECT u.nom_complet,
                COALESCE(SUM(CASE WHEN d.etat_contrat = 'Actif' AND d.etat_dossier = 'Dossier complet' THEN d.ca_annuel ELSE 0 END), 0) AS ca_complets,
                COALESCE(SUM(CASE WHEN d.etat_contrat = 'Actif' AND d.etat_dossier = 'Dossier incomplet' THEN d.ca_annuel ELSE 0 END), 0) AS ca_non_complets,
                COALESCE(SUM(CASE WHEN d.etat_contrat <> 'Actif' THEN d.ca_annuel ELSE 0 END), 0) AS ca_annules,
                COALESCE(SUM(d.ca_annuel), 0) AS ca_total
            FROM users u
            LEFT JOIN dossiers d ON $caOn
            WHERE u.role = 'vendeur'$caVendeurWhere
            GROUP BY u.id, u.nom_complet
            ORDER BY ca_total DESC");
    $stmt->execute($caParams);
    $parVendeurCa = $stmt->fetchAll();

    // Réponse AJAX : met à jour uniquement les tableaux, sans recharger la page
    // (donc sans remonter le scroll en haut). Deux cibles possibles :
    //   - ajax=performance      -> carte « Performance vendeur par contrat »
    //   - ajax=performance_ca   -> carte « Performance vendeur par chiffre d'affaire »
    // Accessible aussi en session vendeur (ses lignes sont déjà restreintes).
    if (isset($_GET['ajax'])) {
        $ajaxTarget = $_GET['ajax'];

        // Lignes de la carte « Performance vendeur par contrat ».
        $rowsHtml = '';
        foreach ($parVendeur as $v) {
            $rowsHtml .= '<tr>'
                . '<td>' . e($v['nom_complet']) . '</td>'
                . '<td class="text-center">' . (int) $v['nb_complets'] . '</td>'
                . '<td class="text-center">' . (int) $v['nb_non_complets'] . '</td>'
                . '<td class="text-center">' . (int) $v['nb_annules'] . '</td>'
                . '<td class="text-center">' . (int) $v['nb_total'] . '</td>'
                . '</tr>';
        }

        // Lignes de la carte « Performance vendeur par chiffre d'affaire ».
        $caRowsHtml = '';
        foreach ($parVendeurCa as $v) {
            $caRowsHtml .= '<tr>'
                . '<td>' . e($v['nom_complet']) . '</td>'
                . '<td class="text-center">' . format_montant((float) $v['ca_complets']) . '</td>'
                . '<td class="text-center">' . format_montant((float) $v['ca_non_complets']) . '</td>'
                . '<td class="text-center">' . format_montant((float) $v['ca_annules']) . '</td>'
                . '<td class="text-center"><strong>' . format_montant((float) $v['ca_total']) . '</strong></td>'
                . '</tr>';
        }

        // Sous-titre de la carte CA (année · mois · jour), reconstruit côté serveur.
        $caSubtitle = 'CA réparti par état de dossier — ' . (int) $caAnneeFilter
            . ($caMoisFilter !== null ? ' · ' . e($monthLabels[$caMoisFilter] ?? '') : '')
            . ($caJourFilter !== null ? ' · jour ' . sprintf('%02d', $caJourFilter) : '');

        if (in_array($ajaxTarget, ['performance', 'performance_ca'], true)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'rowsHtml' => $rowsHtml,
                'caRowsHtml' => $caRowsHtml,
                'caSubtitle' => $caSubtitle,
                'count' => count($parVendeur),
                'countCa' => count($parVendeurCa),
                'vendeur' => $perfVendeurFilter,
                'jour' => $perfJourFilter,
                'mois' => $perfMoisFilter,
                'annee' => $perfAnneeFilter,
            ]);
            exit;
        }
    }
}

// ---------------------------------------------------------------------
// CA annuel (hors annulés) par mois - graphique du tableau de bord
// (mêmes filtres vendeur + jour / mois / année que les statistiques globales)
// Quand un mois précis est saisi, le graphique se limite à ce mois.
// ---------------------------------------------------------------------
$monthlyConditions = ['d.date_vente >= :mstart AND d.date_vente < :mend'];
$monthlyParams = ['mstart' => $anneeStart, 'mend' => $anneeEnd];
if (!$canAccessAll) {
    $monthlyConditions[] = 'd.vendeur_id = :mvid';
    $monthlyParams['mvid'] = $user['id'];
} elseif ($vendeurFilter > 0) {
    $monthlyConditions[] = 'd.vendeur_id = :mfvid';
    $monthlyParams['mfvid'] = $vendeurFilter;
}
if ($moisFilter !== null) {
    $monthlyConditions[] = 'MONTH(d.date_vente) = :mmois';
    $monthlyParams['mmois'] = $moisFilter;
}
if ($jourFilter !== null) {
    $monthlyConditions[] = 'DAY(d.date_vente) = :mjour';
    $monthlyParams['mjour'] = $jourFilter;
}
$caParMois = array_fill(1, 12, 0);
$stmt = $db->prepare("SELECT MONTH(d.date_vente) AS mois,
        COALESCE(SUM(CASE WHEN d.etat_contrat = 'Actif' THEN d.ca_annuel ELSE 0 END), 0) AS total_ca
    FROM dossiers d
    WHERE " . implode(' AND ', $monthlyConditions) . "
    GROUP BY MONTH(d.date_vente)");
$stmt->execute($monthlyParams);
foreach ($stmt->fetchAll() as $r) {
    $caParMois[(int) $r['mois']] = (float) $r['total_ca'];
}
$caTotal = array_sum($caParMois);
$caMax = max(array_values($caParMois));
$caMaxMonth = (int) array_search($caMax, $caParMois, true);
$caMin = min(array_values($caParMois));
$caMinMonth = (int) array_search($caMin, $caParMois, true);

$pageTitle = 'Tableau de bord';
$pageSubtitle = $isAdmin ? 'Vue d’ensemble de tous les dossiers' : 'Vue d’ensemble de vos dossiers';
$activePage = 'dashboard';
require __DIR__ . '/includes/header.php';
?>

<div class="dashboard-layout">
<div class="card dashboard-filter-card">
  <div class="card-header">
    <h2>Filtres du tableau de bord</h2>
  <form class="dashboard-filters" method="get" action="">
    <?php if ($canAccessAll): ?>
    <div class="dashboard-filter-field">
      <label for="vendeur" class="muted">Vendeur</label>
      <select id="vendeur" name="vendeur">
        <option value="0">Tous</option>
        <?php foreach ($vendeurs as $v): ?>
          <option value="<?= (int) $v['id'] ?>" <?= $vendeurFilter === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['nom_complet']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="dashboard-filter-field">
      <label for="jour" class="muted">Jour</label>
      <select id="jour" name="jour" class="dashboard-scroll-select" size="1">
        <option value="" <?= $jourFilter === null ? 'selected' : '' ?>>Tous</option>
        <?php foreach ($availableJours as $j): ?>
          <option value="<?= $j ?>" <?= $jourFilter === $j ? 'selected' : '' ?>><?= sprintf('%02d', $j) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="dashboard-filter-field">
      <label for="mois" class="muted">Mois</label>
      <select id="mois" name="mois" class="dashboard-scroll-select" size="1">
        <option value="" <?= $moisFilter === null ? 'selected' : '' ?>>Tous</option>
        <?php foreach ($availableMois as $m): ?>
          <option value="<?= $m ?>" <?= $moisFilter === $m ? 'selected' : '' ?>><?= e($monthLabels[$m] ?? sprintf('%02d', $m)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="dashboard-filter-field">
      <label for="annee" class="muted">Année</label>
      <select id="annee" name="annee" class="dashboard-scroll-select" size="1">
        <?php foreach ($availableAnnees as $a): ?>
          <option value="<?= $a ?>" <?= $anneeFilter === $a ? 'selected' : '' ?>><?= $a ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="dashboard-filter-actions">
      <button type="submit" class="btn btn-primary btn-sm">Actualiser</button>
      <?php if ($vendeurFilter || $hasDateFilter): ?>
      <a href="<?= e(APP_URL) ?>/dashboard.php" class="btn btn-outline btn-sm">Réinitialiser</a>
      <?php endif; ?>
    </div>
  </form>
  </div>
</div>

<div class="stat-grid">
  <div class="stat-card tone-total stat-card--ca">
    <div class="label">Total CA annuel (hors annulés)</div>
    <div class="value"><?= format_montant((float) $stats['total_ca_annuel']) ?></div>
    <div class="hint"><?= (int) $stats['nb_total'] ?> dossier(s) <?= e($periodeLabel) ?></div>
  </div>
  <div class="stat-card tone-complet stat-card--complet">
    <div class="label">Dossiers complets</div>
    <div class="value"><?= (int) $stats['nb_complets'] ?></div>
    <div class="hint">Prêts à être transmis</div>
  </div>
  <div class="stat-card tone-noncomplet stat-card--noncomplet">
    <div class="label">Dossiers non complets</div>
    <div class="value"><?= (int) $stats['nb_non_complets'] ?></div>
    <div class="hint">Pièces manquantes</div>
  </div>
  <div class="stat-card tone-annule stat-card--annule">
    <div class="label">Dossiers annulés</div>
    <div class="value"><?= (int) $stats['nb_annules'] ?></div>
    <div class="hint">Hors calcul du CA</div>
  </div>
</div>

<section class="chart-card chart-card--primary">
  <div class="chart-card-header">
    <div class="chart-title-group">
      <h2 class="chart-title">CA annuel (hors annulés)</h2>
      <div class="chart-subtitle"><?= (int) $anneeFilter ?> · <?= e($selectedVendeurNom) ?> · <?= e($periodeLabel) ?> · par mois</div>
    </div>
    <span class="chart-badge"><?= (int) $anneeFilter ?></span>
  </div>
  <div class="chart-canvas-wrapper">
    <canvas id="caAnnuelChart" data-static-attendance data-chart-color="#012B5E" data-chart-fill="rgba(1,43,94,0.14)" data-values='<?= json_encode(array_values($caParMois)) ?>' data-labels='<?= json_encode(array_values($monthLabels)) ?>' role="img" aria-label="CA annuel (hors annulés) par mois en <?= (int) $anneeFilter ?>"><?= e(implode(', ', array_values($caParMois))) ?></canvas>
  </div>
  <div class="chart-metrics">
    <div class="metric"><span class="metric-label">Total annuel</span><strong class="metric-value"><?= format_montant((float) $caTotal) ?></strong></div>
    <div class="metric"><span class="metric-label">Moyenne / mois</span><strong class="metric-value"><?= format_montant((float) ($caTotal / 12)) ?></strong></div>
    <div class="metric"><span class="metric-label">Pic mensuel</span><strong class="metric-value"><?= format_montant((float) $caMax) ?></strong><span class="metric-sub"><?= e($monthLabels[$caMaxMonth]) ?></span></div>
    <div class="metric"><span class="metric-label">Mois minimum</span><strong class="metric-value"><?= format_montant((float) $caMin) ?></strong><span class="metric-sub"><?= e($monthLabels[$caMinMonth]) ?></span></div>
  </div>
</section>

<?php if ($parVendeurCa): ?>
<div class="card" id="performance-ca-card">
  <div class="card-header">
    <div>
      <h2>Performance vendeur par chiffre d'affaire</h2>
      <div class="muted" id="performance-ca-subtitle" style="font-size:0.8rem;margin-top:4px;">CA réparti par état de dossier — <?= (int) $caAnneeFilter ?><?= $caMoisFilter !== null ? ' · ' . e($monthLabels[$caMoisFilter] ?? '') : '' ?><?= $caJourFilter !== null ? ' · jour ' . sprintf('%02d', $caJourFilter) : '' ?></div>
    </div>
    <form method="get" class="dashboard-filters" id="performance-ca-form" data-performance-ca-form action="#performance-ca-card">
      <?php if ($canAccessAll && !$isVendeurSession): ?>
      <div class="dashboard-filter-field">
        <label for="ca_vendeur" class="muted">Vendeur</label>
        <select id="ca_vendeur" name="ca_vendeur">
          <option value="0">Tous</option>
          <?php foreach ($vendeurs as $v): ?>
            <option value="<?= (int) $v['id'] ?>" <?= $caVendeurFilter === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['nom_complet']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="dashboard-filter-field">
        <label for="ca_jour" class="muted">Jour</label>
        <select id="ca_jour" name="ca_jour" class="dashboard-scroll-select" size="1">
          <option value="" <?= $caJourFilter === null ? 'selected' : '' ?>>Tous</option>
          <?php foreach ($availableJours as $j): ?>
            <option value="<?= $j ?>" <?= $caJourFilter === $j ? 'selected' : '' ?>><?= sprintf('%02d', $j) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="dashboard-filter-field">
        <label for="ca_mois" class="muted">Mois</label>
        <select id="ca_mois" name="ca_mois" class="dashboard-scroll-select" size="1">
          <option value="" <?= $caMoisFilter === null ? 'selected' : '' ?>>Tous</option>
          <?php foreach ($availableMois as $m): ?>
            <option value="<?= $m ?>" <?= $caMoisFilter === $m ? 'selected' : '' ?>><?= e($monthLabels[$m] ?? sprintf('%02d', $m)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="dashboard-filter-field">
        <label for="ca_annee" class="muted">Année</label>
        <select id="ca_annee" name="ca_annee" class="dashboard-scroll-select" size="1">
          <?php foreach ($availableAnnees as $a): ?>
            <option value="<?= $a ?>" <?= $caAnneeFilter === $a ? 'selected' : '' ?>><?= $a ?></option>
          <?php endforeach; ?>
        </select>
      </div>
            <div class="dashboard-filter-actions">
              <button type="submit" class="btn btn-primary btn-sm" data-performance-ca-submit>Actualiser</button>
              <a href="<?= e(APP_URL) ?>/dashboard.php#performance-ca-card" class="btn btn-outline btn-sm">Réinitialiser</a>
              <span class="muted" data-performance-ca-status role="status" aria-live="polite" style="font-size:0.8rem;"></span>
            </div>
          </form>
       </div>
       <div class="table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th>Vendeur</th>
                <th class="text-center">CA Complets</th>
                <th class="text-center">CA Non complets</th>
                <th class="text-center">CA Annulés</th>
                <th class="text-center">CA Total</th>
              </tr>
            </thead>
            <tbody data-performance-ca-tbody>
              <?php foreach ($parVendeurCa as $v): ?>
              <tr>
                <td><?= e($v['nom_complet']) ?></td>
                <td class="text-center"><?= format_montant((float) $v['ca_complets']) ?></td>
                <td class="text-center"><?= format_montant((float) $v['ca_non_complets']) ?></td>
                <td class="text-center"><?= format_montant((float) $v['ca_annules']) ?></td>
                <td class="text-center"><strong><?= format_montant((float) $v['ca_total']) ?></strong></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
       </div>
      </div>
      <?php endif; ?>

<?php if ($parVendeur): ?>
<div class="card" id="performance-vendeurs-card">
  <div class="card-header">
    <h2>Performance vendeur par contrat</h2>
    <form method="get" class="dashboard-filters" id="performance-filter-form" data-performance-form action="">
      <?php if ($canAccessAll): ?>
      <input type="hidden" name="vendeur" value="<?= (int) $vendeurFilter ?>">
      <input type="hidden" name="jour" value="<?= e($jourRaw) ?>">
      <input type="hidden" name="mois" value="<?= e($moisRaw) ?>">
      <input type="hidden" name="annee" value="<?= e($anneeRaw !== '' ? $anneeRaw : (string) $anneeFilter) ?>">
      <?php endif; ?>
      <?php if ($canAccessAll && !$isVendeurSession): ?>
      <div class="dashboard-filter-field">
        <label for="perf_vendeur" class="muted">Vendeur</label>
        <select id="perf_vendeur" name="perf_vendeur">
          <option value="0">Tous</option>
          <?php foreach ($vendeurs as $v): ?>
            <option value="<?= (int) $v['id'] ?>" <?= $perfVendeurFilter === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['nom_complet']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="dashboard-filter-field">
        <label for="perf_jour" class="muted">Jour</label>
        <select id="perf_jour" name="perf_jour" class="dashboard-scroll-select" size="1">
          <option value="" <?= $perfJourFilter === null ? 'selected' : '' ?>>Tous</option>
          <?php foreach ($availableJours as $j): ?>
            <option value="<?= $j ?>" <?= $perfJourFilter === $j ? 'selected' : '' ?>><?= sprintf('%02d', $j) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="dashboard-filter-field">
        <label for="perf_mois" class="muted">Mois</label>
        <select id="perf_mois" name="perf_mois" class="dashboard-scroll-select" size="1">
          <option value="" <?= $perfMoisFilter === null ? 'selected' : '' ?>>Tous</option>
          <?php foreach ($availableMois as $m): ?>
            <option value="<?= $m ?>" <?= $perfMoisFilter === $m ? 'selected' : '' ?>><?= e($monthLabels[$m] ?? sprintf('%02d', $m)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="dashboard-filter-field">
        <label for="perf_annee" class="muted">Année</label>
        <select id="perf_annee" name="perf_annee" class="dashboard-scroll-select" size="1">
          <?php foreach ($availableAnnees as $a): ?>
            <option value="<?= $a ?>" <?= $perfAnneeFilter === $a ? 'selected' : '' ?>><?= $a ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="dashboard-filter-actions">
        <button type="submit" class="btn btn-primary btn-sm" data-performance-submit>Actualiser</button>
        <?php if ($perfVendeurFilter || $perfHasDateFilter): ?>
        <a href="<?= e(APP_URL) ?>/dashboard.php#performance-vendeurs-card" class="btn btn-outline btn-sm">Réinitialiser</a>
        <?php endif; ?>
        <span class="muted" data-performance-status role="status" aria-live="polite" style="font-size:0.8rem;"></span>
      </div>
    </form>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Vendeur</th>
          <th class="text-center">Complets</th>
          <th class="text-center">Non complets</th>
          <th class="text-center">Annulés</th>
          <th class="text-center">Total</th>
        </tr>
      </thead>
      <tbody data-performance-tbody>
        <?php foreach ($parVendeur as $v): ?>
        <tr>
          <td><?= e($v['nom_complet']) ?></td>
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

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
