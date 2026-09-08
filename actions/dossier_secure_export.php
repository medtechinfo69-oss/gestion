<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/dossiers.php');
    exit;
}

if (!csrf_verify()) {
    set_flash('error', 'Jeton de sécurité invalide.');
    redirect('dossiers.php');
}

$db = $GLOBALS['db'];

$conditions = [];
$params = [];

$search = trim($_POST['q'] ?? '');
$etatFilter = $_POST['etat'] ?? '';
$etatContratFilter = $_POST['etat_contrat'] ?? '';
$vendeurFilter = filter_var($_POST['vendeur'] ?? '', FILTER_VALIDATE_INT) ?: 0;
$compagnieFilter = trim($_POST['compagnie'] ?? '');
$dateFrom = $_POST['date_from'] ?? '';
$dateTo = $_POST['date_to'] ?? '';

$canAccessAll = can_access_dossiers();
$user = current_user();

if (!$canAccessAll) {
    $conditions[] = 'd.vendeur_id = :own_vendeur';
    $params['own_vendeur'] = $user['id'];
}

if ($search !== '') {
    $searchColumns = [
        'd.nom', 'd.prenom', 'd.civilite', 'd.mail', 'd.telfix', 'd.portable',
        'd.compagnie', 'd.etat_dossier', 'd.etat_contrat', 'd.produit'
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

$sql = "SELECT d.*, u.nom_complet AS vendeur_nom
        FROM dossiers d
        LEFT JOIN users u ON u.id = d.vendeur_id
        $whereSql
        ORDER BY d.date_vente DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$dossiers = $stmt->fetchAll();

$rows = [['Vendeur', 'Date Vente', 'Civilité', 'Nom', 'Prénom', 'Email', 'Téléphone Fixe', 'Téléphone Portable', 'NB Assurés', 'Date Naissance', 'Age Assuré Principal', 'Adresse', 'CP', 'Ville', 'Type Signature', 'CA Mois', 'CA Annuel', 'Date Effet', 'Produit', 'Compagnie', 'TA Origine', 'Etat Dossier', 'Courrier', 'Commentaire', 'Etat Contrat', 'Contrôle Qualité']];

foreach ($dossiers as $d) {
    $rows[] = [
        $d['vendeur_nom'] ?? '',
        $d['date_vente'] ?? '',
        $d['civilite'] ?? '',
        $d['nom'] ?? '',
        $d['prenom'] ?? '',
        $d['mail'] ?? '',
        $d['telfix'] ?? '',
        $d['portable'] ?? '',
        $d['nombre_personnes'] ?? '',
        $d['date_naissance_assure'] ?? '',
        $d['age_assure_principal'] ?? '',
        $d['adresse'] ?? '',
        $d['cp'] ?? '',
        $d['ville'] ?? '',
        $d['type_signature'] ?? '',
        $d['ca_mois'] ?? '',
        $d['ca_annuel'] ?? '',
        $d['date_effet'] ?? '',
        $d['produit'] ?? '',
        $d['compagnie'] ?? '',
        $d['ta_origine'] ?? '',
        $d['etat_dossier'] ?? '',
        $d['courrier'] ?? '',
        $d['commentaire'] ?? '',
        $d['etat_contrat'] ?? '',
        $d['controle_qualite'] ?? '',
    ];
}

$filename = 'dossiers_export_' . date('Y-m-d_His') . '.xlsx';

if (!class_exists('ZipArchive')) {
    set_flash('error', "L'extension ZipArchive est requise pour l'export.");
    header('Location: ' . APP_URL . '/dossiers.php');
    exit;
}

$tmp = tempnam(sys_get_temp_dir(), 'xlsx');
$zip = new ZipArchive();
$zip->open($tmp, ZipArchive::OVERWRITE);

$zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>');
$zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
$zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Dossiers" sheetId="1" r:id="rId1"/></sheets></workbook>');
$zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>');

$map = [];
$strings = [];
foreach ($rows as $row) {
    foreach ($row as $v) {
        $v = (string) $v;
        if (!isset($map[$v])) {
            $map[$v] = count($strings);
            $strings[] = $v;
        }
    }
}

$sheet = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
foreach ($rows as $ri => $row) {
    $r = $ri + 1;
    $sheet .= '<row r="' . $r . '">';
    foreach (array_values($row) as $ci => $v) {
        $cell = chr(65 + $ci) . $r;
        $sheet .= '<c r="' . $cell . '" t="s"><v>' . $map[(string) $v] . '</v></c>';
    }
    $sheet .= '</row>';
}
$sheet .= '</sheetData></worksheet>';
$zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?>' . $sheet);

$shared = '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">';
foreach ($strings as $str) {
    $shared .= '<si><t>' . htmlspecialchars($str, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></si>';
}
$shared .= '</sst>';
$zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?>' . $shared);

$zip->close();
$fileContents = file_get_contents($tmp);
@unlink($tmp);

$token = bin2hex(random_bytes(32));
$code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$codeHash = hash('sha256', $code);
$expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));
$createdBy = (int) ($user['id'] ?? 0);

$stmt = $db->prepare('INSERT INTO secure_downloads (token, code_hash, filename, file_data, expires_at, max_attempts, created_by, created_at) VALUES (:token, :code_hash, :filename, :file_data, :expires, 3, :created_by, NOW())');
$stmt->execute([
    'token' => $token,
    'code_hash' => $codeHash,
    'filename' => $filename,
    'file_data' => $fileContents,
    'expires' => $expiresAt,
    'created_by' => $createdBy
]);

$downloadUrl = APP_URL . '/secure_download.php?token=' . $token;

$filterDesc = [];
if ($search) $filterDesc[] = "recherche: $search";
if ($etatFilter) $filterDesc[] = "état: $etatFilter";
if ($etatContratFilter) $filterDesc[] = "contrat: $etatContratFilter";
if ($compagnieFilter) $filterDesc[] = "compagnie: $compagnieFilter";
if ($dateFrom || $dateTo) $filterDesc[] = "dates: $dateFrom - $dateTo";

$body = "Bonjour,\n\n";
$body .= "Votre fichier sécurisé est prêt.\n\n";
$body .= "Code de vérification: $code\n\n";
$body .= "Cliquez sur le lien ci-dessous et entrez ce code pour télécharger le fichier.\n\n";
$body .= "$downloadUrl\n\n";
$body .= "Ce lien expire dans 30 minutes.\n";
$body .= "Attention: Ce code est à usage unique.\n\n";
$body .= "---\n";
$body .= "Généré le " . date('d/m/Y à H:i') . " par " . ($user['nom_complet'] ?? 'un administrateur') . ".\n";
$body .= "Nombre de dossiers: " . count($dossiers) . "\n";
if ($filterDesc) {
    $body .= "Filtres: " . implode(', ', $filterDesc);
}

$emailSent = notify_admins($db, 'Export sécurisé des dossiers - ' . date('d/m/Y'), $body);

if ($emailSent) {
    set_flash('success', 'L\'email avec le code de vérification a été envoyé aux administrateurs.');
} else {
    set_flash('error', "L'envoi de l'email a échoué. Vérifiez la configuration SMTP.");
}

$queryParts = [];
if ($search) $queryParts[] = 'q=' . urlencode($search);
if ($etatFilter) $queryParts[] = 'etat=' . urlencode($etatFilter);
if ($etatContratFilter) $queryParts[] = 'etat_contrat=' . urlencode($etatContratFilter);
if ($vendeurFilter) $queryParts[] = 'vendeur=' . $vendeurFilter;
if ($compagnieFilter) $queryParts[] = 'compagnie=' . urlencode($compagnieFilter);
if ($dateFrom) $queryParts[] = 'date_from=' . urlencode($dateFrom);
if ($dateTo) $queryParts[] = 'date_to=' . urlencode($dateTo);

header('Location: ' . APP_URL . '/dossiers.php' . ($queryParts ? '?' . implode('&', $queryParts) : ''));
exit;
