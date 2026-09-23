<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

/** Convertit un index de colonne (1 -> A, 27 -> AA) pour les références de cellules Excel. */
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

/**
 * Garde-fou : la table secure_downloads doit conserver sa clé primaire
 * AUTO_INCREMENT sur `id`. Un ancien schéma cassé (id sans PK) insère des
 * lignes avec id = 0 / des doublons, rendant les exports introuvables.
 */
function secure_downloads_schema_ensure(PDO $db): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    try {
        foreach ($db->query('DESCRIBE secure_downloads')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (($c['Field'] ?? '') === 'id') {
                if (($c['Key'] ?? '') !== 'PRI' || stripos((string) ($c['Extra'] ?? ''), 'auto_increment') === false) {
                    try { $db->exec('ALTER TABLE secure_downloads DROP PRIMARY KEY'); } catch (Throwable $e) { /* pas de PK */ }
                    $type = (string) ($c['Type'] ?? 'bigint unsigned');
                    $db->exec("ALTER TABLE secure_downloads MODIFY COLUMN id $type NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)");
                }
                break;
            }
        }
    } catch (Throwable $e) {
        error_log('secure_downloads_schema_ensure: ' . $e->getMessage());
    }
}

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

if (is_array($etatContratFilter) && !empty($etatContratFilter)) {
    $filtered = array_filter($etatContratFilter, function ($v) { return in_array($v, etats_contrat_valides(), true); });
    if (!empty($filtered)) {
        $in = '';
        foreach ($filtered as $i => $val) {
            $in .= ($i > 0 ? ',' : '') . ':ec' . $i;
            $params['ec' . $i] = $val;
        }
        $conditions[] = "d.etat_contrat IN ($in)";
    }
} elseif ($etatContratFilter !== '') {
    $values = explode(',', $etatContratFilter);
    $filtered = array_filter($values, function ($v) { return in_array(trim($v), etats_contrat_valides(), true); });
    if (!empty($filtered)) {
        $in = '';
        foreach ($filtered as $i => $val) {
            $in .= ($i > 0 ? ',' : '') . ':ec' . $i;
            $params['ec' . $i] = trim($val);
        }
        $conditions[] = "d.etat_contrat IN ($in)";
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

$whereSql = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

$sql = "SELECT d.*, u.nom_complet AS vendeur_nom
        FROM dossiers d
        LEFT JOIN users u ON u.id = d.vendeur_id
        $whereSql
        ORDER BY d.date_vente DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$dossiers = $stmt->fetchAll();

$rows = [[
    'Vendeur', 'Origine', 'Prod', 'Date vente', 'Civilité', 'Nom', 'Prénom',
    'Mail', 'Téléphone 1', 'Téléphone 2', "NB d'assurés", 'Date naissance assuré',
    'Age assuré principal', 'Adresse', 'CP', 'Ville', 'Type de signature',
    'CA-mois', 'CA-annuel', "Date d'effet", 'Produit', 'Compagnie',
    'Etat du dossier', 'Date validation', 'Courrier', 'Commentaire dossier',
    'Etat du contrat', "Date d'annulation", 'Contrôle Qualité', "Date d'injection",
]];

foreach ($dossiers as $d) {
    $rows[] = [
        $d['vendeur_nom'] ?? '',
        $d['ta_origine'] ?? '',
        $d['p_prod'] ?? '',
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
        $d['etat_dossier'] ?? '',
        format_date((string) ($d['date_dossier_complet'] ?? '')),
        $d['courrier'] ?? '',
        $d['commentaire'] ?? '',
        $d['etat_contrat'] ?? '',
        format_date((string) ($d['date_contrat_non_actif'] ?? '')),
        $d['controle_qualite'] ?? '',
        format_date((string) ($d['date_injection'] ?? '')),
    ];
}

$filename = 'dossiers_export_' . date('Y-m-d_His') . '.xlsx';

if (!class_exists('ZipArchive')) {
    set_flash('error', "L'extension ZipArchive est requise pour l'export.");
    header('Location: ' . APP_URL . '/dossiers.php');
    exit;
}

// Fichier temporaire : app_temp_file() évite /home/tmp (hors open_basedir sur
// les hébergements mutualisés type InfinityFree) et privilégie uploads/tmp.
// Sans cela, tempnam() échoue puis ZipArchive::open() lève une ValueError => 500.
$tmp = app_temp_file('xlsx');
if ($tmp === '') {
    set_flash('error', "Export impossible : aucun dossier temporaire accessible sur le serveur.");
    header('Location: ' . APP_URL . '/dossiers.php');
    exit;
}

$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    set_flash('error', "Export impossible : création du fichier Excel temporaire refusée.");
    header('Location: ' . APP_URL . '/dossiers.php');
    exit;
}

try {

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
        $cell = export_column($ci + 1) . $r;
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
$tmpExportFile = $tmp; // conservé pour pièce jointe e-mail éventuelle
$token = bin2hex(random_bytes(32));
$code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$codeHash = hash('sha256', $code);
$expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));
$createdBy = (int) ($user['id'] ?? 0);

secure_downloads_schema_ensure($db);

$stmt = $db->prepare('INSERT INTO secure_downloads (token, code_hash, filename, file_data, expires_at, max_attempts, created_by, created_at) VALUES (:token, :code_hash, :filename, :file_data, :expires, 3, :created_by, NOW())');
$stmt->execute([
    'token' => $token,
    'code_hash' => $codeHash,
    'filename' => $filename,
    'file_data' => $fileContents,
    'expires' => $expiresAt,
    'created_by' => $createdBy
]);
} catch (Throwable $e) {
    // Un échec de génération ne doit jamais produire une erreur 500 (page
    // blanche remplacée par la page d'erreur générique de l'hébergeur) :
    // on journalise et on revient à la liste avec un message clair.
    error_log('dossier_secure_export error: ' . $e->getMessage());
    @unlink($tmp);
    set_flash('error', "L'export sécurisé a échoué : " . $e->getMessage());
    header('Location: ' . APP_URL . '/dossiers.php');
    exit;
}

$downloadUrl = APP_URL . '/secure_download.php?token=' . $token;

$filterDesc = [];
if ($search) $filterDesc[] = "recherche: $search";
if ($etatFilter) $filterDesc[] = "état: $etatFilter";
if (is_array($etatContratFilter) && !empty($etatContratFilter)) {
        $filterDesc[] = 'contrat: ' . implode(', ', $etatContratFilter);
    } elseif ($etatContratFilter) {
        $filterDesc[] = "contrat: $etatContratFilter";
    }
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

// Envoi de l'e-mail via le SMTP configuré (bouton "Envoyer Excel par
// e-mail" : champ send_email=1). Le fichier Excel n'est PAS joint :
// l'e-mail contient uniquement le lien sécurisé + le code de vérification.
$sendEmail = !empty($_POST['send_email']);
if ($sendEmail) {
    $recipient = defined('MAIL_ALERT_TO') && MAIL_ALERT_TO ? MAIL_ALERT_TO : (defined('MAIL_FROM') ? MAIL_FROM : '');
    if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        $mailSubject = 'Export Excel des dossiers - ' . date('d/m/Y H:i');
        $ok = send_app_email($recipient, $mailSubject, $body);
        if ($ok) {
            set_flash('success', 'Lien de téléchargement envoyé par e-mail à ' . $recipient . ' (' . count($dossiers) . ' dossier(s)).');
        } else {
            set_flash('error', 'L\'export Excel a été généré mais l\'envoi par e-mail a échoué. Vérifiez la configuration SMTP.');
        }
    } else {
        set_flash('error', 'Adresse e-mail de destination invalide. L\'export Excel a été généré mais n\'a pas été envoyé.');
    }
    @unlink($tmpExportFile);
    header('Location: ' . APP_URL . '/dossiers.php');
    exit;
} else {

@unlink($tmpExportFile);

$_SESSION['secure_download_code'] = $code;
$_SESSION['secure_download_url'] = $downloadUrl;
$_SESSION['secure_download_filename'] = $filename;
$_SESSION['secure_download_token'] = $token;

$queryParts = [];
if ($search) $queryParts[] = 'q=' . urlencode($search);
if ($etatFilter) $queryParts[] = 'etat=' . urlencode($etatFilter);
if (is_array($etatContratFilter) && !empty($etatContratFilter)) {
        foreach ($etatContratFilter as $val) {
            $queryParts[] = 'etat_contrat[]=' . urlencode($val);
        }
    } elseif ($etatContratFilter) {
        $queryParts[] = 'etat_contrat=' . urlencode($etatContratFilter);
    }
if ($vendeurFilter) $queryParts[] = 'vendeur=' . $vendeurFilter;
if ($compagnieFilter) $queryParts[] = 'compagnie=' . urlencode($compagnieFilter);
if ($dateFrom) $queryParts[] = 'date_from=' . urlencode($dateFrom);
if ($dateTo) $queryParts[] = 'date_to=' . urlencode($dateTo);

header('Location: ' . APP_URL . '/dossiers.php' . ($queryParts ? '?' . implode('&', $queryParts) : ''));
exit;
}
