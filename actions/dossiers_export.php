<?php
require_once __DIR__ . '/../includes/init.php';
require_dossier_access();

function export_xml(string $value): string
{
    $value = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value) ?? '';
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function export_column(int $number): string
{
    $column = '';
    while ($number > 0) {
        $remainder = ($number - 1) % 26;
        $column = chr(65 + $remainder) . $column;
        $number = (int) (($number - $remainder - 1) / 26);
    }
    return $column;
}

function export_cell(string $reference, string $value, bool $header = false): string
{
    return '<c r="' . $reference . '" t="inlineStr"><is><t xml:space="preserve">' . export_xml($value) . '</t></is></c>';
}

$user = current_user();
$canAccessAll = can_access_dossiers();
$hideSupervisorColumns = is_superviseur()
    && in_array(mb_strtolower((string) ($user['username'] ?? '')), ['emma', 'rabia'], true);
$search = trim($_GET['q'] ?? '');
$etatFilter = $_GET['etat'] ?? '';
$etatContratFilter = $_GET['etat_contrat'] ?? '';
$vendeurFilter = filter_var($_GET['vendeur'] ?? '', FILTER_VALIDATE_INT) ?: 0;
$compagnieFilter = trim($_GET['compagnie'] ?? '');
$dateFrom = parse_date_fr($_GET['date_from'] ?? '') ?: '';
$dateTo = parse_date_fr($_GET['date_to'] ?? '') ?: '';

$conditions = [];
$params = [];
if (!$canAccessAll) {
    $conditions[] = 'd.vendeur_id = :own_vendeur';
    $params['own_vendeur'] = $user['id'];
}
if ($search !== '') {
    $conditions[] = '(d.nom LIKE :q1 OR d.prenom LIKE :q2 OR d.mail LIKE :q3 OR d.portable LIKE :q4 OR d.ville LIKE :q5 OR d.produit LIKE :q6)';
    $like = '%' . $search . '%';
    foreach (['q1', 'q2', 'q3', 'q4', 'q5', 'q6'] as $key) {
        $params[$key] = $like;
    }
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

$whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$sort = $_GET['sort'] ?? 'date_vente';
$sortableColumns = [
    'date_vente' => 'd.date_vente', 'nom' => 'd.nom', 'ca_mois' => 'd.ca_mois',
    'ca_annuel' => 'd.ca_annuel', 'etat_dossier' => 'd.etat_dossier',
    'compagnie' => 'd.compagnie', 'created_at' => 'd.created_at',
];
$sortSql = $sortableColumns[$sort] ?? $sortableColumns['date_vente'];
$dirSql = strtolower($_GET['dir'] ?? '') === 'asc' ? 'ASC' : 'DESC';
$stmt = $db->prepare("SELECT d.*, u.nom_complet AS vendeur_nom FROM dossiers d JOIN users u ON u.id = d.vendeur_id $whereSql ORDER BY $sortSql $dirSql");
$stmt->execute($params);
$dossiers = $stmt->fetchAll();

$columns = [
    'vendeur_nom' => 'Vendeur', 'ta_origine' => 'Origine', 'p_prod' => 'Prod', 'date_vente' => 'Date vente',
    'civilite' => 'Civilité', 'nom' => 'Nom', 'prenom' => 'Prénom', 'mail' => 'Mail', 'telfix' => 'Téléphone 1',
    'portable' => 'Téléphone 2', 'nombre_personnes' => "NB d'assurés", 'date_naissance_assure' => 'Date naissance assuré',
    'age_assure_principal' => 'Age assuré principal', 'adresse' => 'Adresse', 'cp' => 'CP', 'ville' => 'Ville',
    'type_signature' => 'Type de signature', 'ca_mois' => 'CA-mois', 'ca_annuel' => 'CA-annuel', 'date_effet' => "Date d'effet",
    'produit' => 'Produit', 'compagnie' => 'Compagnie', 'etat_dossier' => 'Etat du dossier', 'date_dossier_complet' => 'Date validation', 'courrier' => 'Courrier',
    'commentaire' => 'Commentaire dossier', 'etat_contrat' => 'Etat du contrat', 'controle_qualite' => 'Contrôle qualité', 'date_contrat_non_actif' => "Date d'annulation",
];
if ($hideSupervisorColumns) {
    foreach (['mail', 'telfix', 'portable', 'nombre_personnes', 'date_naissance_assure', 'age_assure_principal', 'adresse', 'cp', 'ville'] as $hiddenColumn) {
        unset($columns[$hiddenColumn]);
    }
}

$sheetRows = [];
$headerCells = '';
foreach (array_values($columns) as $index => $label) {
    $headerCells .= export_cell(export_column($index + 1) . '1', $label, true);
}
$sheetRows[] = '<row r="1">' . $headerCells . '</row>';
foreach ($dossiers as $rowNumber => $dossier) {
    $excelRow = $rowNumber + 2;
    $cells = '';
    foreach (array_keys($columns) as $index => $key) {
        $value = $dossier[$key] ?? '';
        if (in_array($key, ['date_vente', 'date_effet', 'date_dossier_complet', 'date_contrat_non_actif'], true)) {
            $value = format_date((string) $value);
        } elseif ($key === 'courrier') {
            $value = implode(', ', courrier_values((string) $value));
        } elseif ($key === 'ca_mois' || $key === 'ca_annuel') {
            $value = number_format((float) $value, 2, ',', ' ');
        }
        $cells .= export_cell(export_column($index + 1) . $excelRow, (string) $value);
    }
    $sheetRows[] = '<row r="' . $excelRow . '">' . $cells . '</row>';
}

$lastColumn = export_column(count($columns));
$lastRow = max(1, count($dossiers) + 1);
$sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<dimension ref="A1:' . $lastColumn . $lastRow . '"/><sheetData>' . implode('', $sheetRows) . '</sheetData></worksheet>';
$workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Dossiers" sheetId="1" r:id="rId1"/></sheets></workbook>';
$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>';
$rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
$workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>';

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('Export .xlsx impossible : activez extension=zip dans C:\\xampp\\php\\php.ini puis redémarrez Apache.');
}
$temporaryFile = tempnam(sys_get_temp_dir(), 'dossiers_export_');
$zip = new ZipArchive();
if (!$temporaryFile || $zip->open($temporaryFile, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Export Excel impossible.');
}
$zip->addFromString('[Content_Types].xml', $contentTypes);
$zip->addFromString('_rels/.rels', $rootRels);
$zip->addFromString('xl/workbook.xml', $workbookXml);
$zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
$zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
$zip->close();

$filename = 'dossiers_' . date('Y-m-d_H-i') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
clearstatcache(true, $temporaryFile);
header('Content-Length: ' . filesize($temporaryFile));
readfile($temporaryFile);
unlink($temporaryFile);