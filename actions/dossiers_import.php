<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

function import_normalize_header(string $value): string
{
    $value = str_replace(["\xEF\xBB\xBF", "\xC2\xA0", "\xE2\x80\x8B"], ' ', $value);
    $value = trim(mb_strtolower($value));
    $value = strtr($value, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c']);
    return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
}

function import_cell(array $row, array $indexes, array|string $headers): string
{
    if (!is_array($headers)) {
        $headers = [$headers];
    }
    foreach ($headers as $header) {
        $norm = import_normalize_header($header);
        if (isset($indexes[$norm])) {
            $val = clean_str((string) ($row[$indexes[$norm]] ?? ''));
            if ($val !== '') {
                return $val;
            }
        }
    }
    return '';
}

function import_date_value(string $value): string
{
    if (is_numeric($value) && (float) $value > 0) {
        $timestamp = (int) round(((float) $value - 25569) * 86400);
        return gmdate('d/m/Y', $timestamp);
    }
    return $value;
}

function import_vendeur_id(PDO $db, string $sellerName, array &$vendeursByName): int
{
    $key = import_normalize_header($sellerName);
    if ($key === '') {
        return 0;
    }
    if (isset($vendeursByName[$key])) {
        return $vendeursByName[$key];
    }

    $baseUsername = strtolower(preg_replace('/[^a-z0-9]+/i', '.', iconv('UTF-8', 'ASCII//TRANSLIT', $sellerName)) ?: 'vendeur');
    $baseUsername = substr(trim($baseUsername, '.') ?: 'vendeur', 0, 50);
    $username = $baseUsername;
    $suffix = 2;
    while (true) {
        $check = $db->prepare('SELECT id FROM users WHERE username = :username');
        $check->execute(['username' => $username]);
        if (!$check->fetchColumn()) {
            break;
        }
        $username = $baseUsername . $suffix++;
    }

    $stmt = $db->prepare(
        'INSERT INTO users (username, password_hash, role, nom_complet, email, is_active, must_change_password)
         VALUES (:username, :password_hash, "vendeur", :nom_complet, NULL, 0, 0)'
    );
    $stmt->execute([
        'username' => $username,
        'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
        'nom_complet' => $sellerName,
    ]);
    $vendeursByName[$key] = (int) $db->lastInsertId();
    return $vendeursByName[$key];
}

function import_xlsx_rows(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('La lecture .xlsx nécessite l’extension PHP ZipArchive. Activez extension=zip dans C:\\xampp\\php\\php.ini puis redémarrez Apache.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Fichier Excel illisible.');
    }

    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $xml = simplexml_load_string($sharedXml, 'SimpleXMLElement', LIBXML_NONET);
        if ($xml) {
            foreach ($xml->xpath('//*[local-name()="si"]') ?: [] as $item) {
                $text = '';
                foreach ($item->xpath('.//*[local-name()="t"]') ?: [] as $part) {
                    $text .= (string) $part;
                }
                $shared[] = $text;
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('La première feuille Excel est introuvable.');
    }
    $xml = simplexml_load_string($sheetXml, 'SimpleXMLElement', LIBXML_NONET);
    if (!$xml) {
        throw new RuntimeException('Structure Excel invalide.');
    }

    $rows = [];
    foreach ($xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $xmlRow) {
        $row = [];
        foreach ($xmlRow->xpath('./*[local-name()="c"]') ?: [] as $cell) {
            $ref = (string) $cell['r'];
            preg_match('/([A-Z]+)[0-9]+/', $ref, $match);
            $column = 0;
            foreach (str_split($match[1] ?? '') as $letter) {
                $column = $column * 26 + ord($letter) - 64;
            }
            $values = $cell->xpath('./*[local-name()="v"]');
            $value = isset($values[0]) ? (string) $values[0] : '';
            if ((string) $cell['t'] === 's') {
                $value = $shared[(int) $value] ?? '';
            } elseif ((string) $cell['t'] === 'inlineStr') {
                $value = '';
                foreach ($cell->xpath('.//*[local-name()="t"]') ?: [] as $text) {
                    $value .= (string) $text;
                }
            }
            $row[$column - 1] = $value;
        }
        if (array_filter($row, static fn ($value) => trim((string) $value) !== '')) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function import_csv_rows(string $path): array
{
    $handle = fopen($path, 'rb');
    if (!$handle) {
        throw new RuntimeException('Fichier CSV illisible.');
    }
    $rows = [];
    while (($row = fgetcsv($handle, 0, ';')) !== false) {
        if (count($row) === 1 && str_contains((string) $row[0], ',')) {
            $row = str_getcsv($row[0], ',');
        }
        if (array_filter($row, static fn ($value) => trim((string) $value) !== '')) {
            $rows[] = $row;
        }
    }
    fclose($handle);
    return $rows;
}

function import_insert_row(PDO $db, array $data, int $userId, bool $skipBusinessRules = false): void
{
    $data['date_dossier_complet'] = null;
    $data['date_contrat_non_actif'] = null;
    $sql = 'INSERT INTO dossiers
        (vendeur_id, ta_origine, p_prod, date_vente, civilite, nom, prenom, mail, telfix, portable,
         nombre_personnes, date_naissance_assure, age_assure_principal, adresse, cp, ville, type_signature,
         ca_mois, ca_annuel, date_effet, produit, compagnie, courrier, etat_dossier, date_dossier_complet,
         etat_contrat, date_contrat_non_actif, commentaire, motif_annulation, created_by)
        VALUES
        (:vendeur_id, :ta_origine, :p_prod, :date_vente, :civilite, :nom, :prenom, :mail, :telfix, :portable,
         :nombre_personnes, :date_naissance_assure, :age_assure_principal, :adresse, :cp, :ville, :type_signature,
         :ca_mois, :ca_annuel, :date_effet, :produit, :compagnie, :courrier, :etat_dossier, :date_dossier_complet,
         :etat_contrat, :date_contrat_non_actif, :commentaire, :motif_annulation, :created_by)';
    $data['created_by'] = $userId;
    $db->prepare($sql)->execute($data);
    // Keep creation history logging even for imports
    log_dossier_history($db, (int) $db->lastInsertId(), $userId, 'creation');
}

$file = $_FILES['fichier'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > IMPORT_MAX_SIZE) {
    set_flash('error', 'Veuillez sélectionner un fichier Excel valide de 10 Mo maximum.');
    redirect('dossiers_import.php');
}

$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($extension, ['xlsx', 'csv'], true)) {
    set_flash('error', 'Format refusé. Utilisez un fichier .xlsx ou .csv.');
    redirect('dossiers_import.php');
}

// Allow callers to request import that skips business rules (dates/annulation/auto-validation).
// Only trusted users should be able to use this flag; require_admin() is already enforced at top of this script.
$skipBusinessRules = false;
if (!empty($_POST['skipBusinessRules']) || !empty($_POST['skip_business_rules']) || (isset($_REQUEST['skipBusinessRules']) && $_REQUEST['skipBusinessRules'] === 'true')) {
    $skipBusinessRules = true;
}

try {
    $rows = $extension === 'xlsx' ? import_xlsx_rows($file['tmp_name']) : import_csv_rows($file['tmp_name']);
    if (count($rows) < 2) {
        throw new RuntimeException('Le fichier ne contient aucune ligne de dossier.');
    }

    $headers = array_map(static fn ($value) => trim((string) $value), $rows[0]);
    $indexes = [];
    foreach ($headers as $index => $header) {
        $indexes[import_normalize_header($header)] = $index;
    }

    $vendeurs = $db->query("SELECT id, nom_complet FROM users WHERE role = 'vendeur'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $vendeursByName = [];
    foreach ($vendeurs as $id => $name) {
        $vendeursByName[import_normalize_header($name)] = (int) $id;
    }

    $existingPhones = array_merge(
        $db->query("SELECT telfix FROM dossiers WHERE telfix IS NOT NULL AND telfix <> ''")->fetchAll(PDO::FETCH_COLUMN),
        $db->query("SELECT portable FROM dossiers WHERE portable IS NOT NULL AND portable <> ''")->fetchAll(PDO::FETCH_COLUMN)
    );
    $seenPhones = [];
    foreach ($existingPhones as $phone) {
        $norm = strtolower(preg_replace('/[^0-9]/', '', (string) $phone));
        if (strlen($norm) >= 6 && $norm !== '0000000000') {
            $seenPhones[$norm] = true;
        }
    }

    $newOrigines = [];
    $existingOrigines = array_fill_keys(array_map('mb_strtolower', origines_valides($db)), true);

    $prepared = [];
    $skippedCount = 0;
    $db->beginTransaction();
    foreach (array_slice($rows, 1) as $offset => $row) {
        $sellerName = import_cell($row, $indexes, ['Vendeur', 'Agent', 'Conseiller']);
        $sellerId = $sellerName !== '' ? import_vendeur_id($db, $sellerName, $vendeursByName) : (int) (current_user()['id'] ?? 1);

        $telfix = import_cell($row, $indexes, ['Téléphone 1', 'Tel 1', 'Telfix', 'Téléphone', 'Tel', 'Fixe']);
        $portable = import_cell($row, $indexes, ['Téléphone 2', 'Tel 2', 'Portable', 'Mobile', 'GSM']);

        $telfixNorm = strtolower(preg_replace('/[^0-9]/', '', (string) $telfix));
        $portableNorm = strtolower(preg_replace('/[^0-9]/', '', (string) $portable));

        if (strlen($telfixNorm) < 6 || $telfixNorm === '0000000000') {
            $telfixNorm = '';
        }
        if (strlen($portableNorm) < 6 || $portableNorm === '0000000000') {
            $portableNorm = '';
        }

        // Si le numéro de téléphone (Tel 1 ou Tel 2) existe déjà en base ou dans le fichier, on ne l'importe pas (doublon ignoré)
        if (($telfixNorm !== '' && isset($seenPhones[$telfixNorm])) || ($portableNorm !== '' && isset($seenPhones[$portableNorm]))) {
            $skippedCount++;
            continue;
        }

        if ($telfixNorm !== '') {
            $seenPhones[$telfixNorm] = true;
        }
        if ($portableNorm !== '') {
            $seenPhones[$portableNorm] = true;
        }

        $dateVenteRaw = import_cell($row, $indexes, ['Date vente', 'Date de vente', 'Vente']);
        $dateVenteVal = import_date_value($dateVenteRaw);

        $dateEffetRaw = import_cell($row, $indexes, ["Date d'effet", 'Date effet', 'Effet']);
        $dateEffetVal = import_date_value($dateEffetRaw);

        $courrierText = import_cell($row, $indexes, ['Courrier', 'Courriers']);
        $courrier = array_values(array_filter(array_map('trim', preg_split('/[,;|]+/', $courrierText) ?: [])));

        $origineRaw = trim(import_cell($row, $indexes, ['Origine', 'Source', 'Ta origine']));
        if ($origineRaw !== '' && !isset($existingOrigines[mb_strtolower($origineRaw)])) {
            $newOrigines[mb_strtolower($origineRaw)] = $origineRaw;
            $existingOrigines[mb_strtolower($origineRaw)] = true;
        }

        $postPortable = $portable;
        if ($postPortable === '') {
            $postPortable = $telfix !== '' ? $telfix : 'N/A-' . ($offset + 2) . '-' . time();
        }

        $post = [
            'vendeur_id' => $sellerId,
            'ta_origine' => $origineRaw,
            'p_prod' => import_cell($row, $indexes, ['Prod', 'P_prod']),
            'date_vente' => $dateVenteVal,
            'civilite' => import_cell($row, $indexes, ['Civilité', 'Civilite', 'Civ']),
            'nom' => import_cell($row, $indexes, ['Nom', 'Nom client']),
            'prenom' => import_cell($row, $indexes, ['Prénom', 'Prenom', 'Prénom client']),
            'mail' => import_cell($row, $indexes, ['Mail', 'Email', 'E-mail', 'Courriel']),
            'telfix' => $telfix,
            'portable' => $postPortable,
            'nombre_personnes' => import_cell($row, $indexes, ["NB d'assurés", 'Nombre de personnes', 'Assurés']) ?: 1,
            'date_naissance_assure' => import_cell($row, $indexes, ['Date naissance assuré', 'Date naissance', 'Date de naissance']),
            'age_assure_principal' => import_cell($row, $indexes, ['Age assuré principal', 'Âge', 'Age']),
            'adresse' => import_cell($row, $indexes, ['Adresse', 'Adresse postale']),
            'cp' => import_cell($row, $indexes, ['CP', 'Code postal']),
            'ville' => import_cell($row, $indexes, ['Ville', 'Commune']),
            'type_signature' => import_cell($row, $indexes, ['Type de signature', 'Type signature', 'Signature']),
            'ca_mois' => import_cell($row, $indexes, ['CA-mois', 'CA mois', 'CA Mensuel', 'Cotisation']),
            'ca_annuel' => import_cell($row, $indexes, ['CA-annuel', 'CA annuel', 'CA Annuel']),
            'date_effet' => $dateEffetVal,
            'produit' => import_cell($row, $indexes, ['Produit', 'Offre', 'Formule']),
            'compagnie' => import_cell($row, $indexes, ['Compagnie', 'Assureur']),
            'courrier' => $courrier,
            'etat_contrat' => import_cell($row, $indexes, ['Etat du contrat', 'État du contrat', 'Contrat']) ?: 'Actif',
            'commentaire' => import_cell($row, $indexes, ['Commentaire dossier', 'Commentaire', 'Notes']),
        ];

        $result = validate_dossier_input($post, $db, null, true);
        $data = $result['data'];

        if (isset($dateVenteRaw) && trim((string) $dateVenteRaw) === '') {
            $data['date_vente'] = null;
        }
        if (isset($dateEffetRaw) && trim((string) $dateEffetRaw) === '') {
            $data['date_effet'] = null;
        }

        $prepared[] = $data;
    }

    foreach ($newOrigines as $origine) {
        try {
            $stmt = $db->prepare('INSERT INTO origines (nom) VALUES (:nom) ON DUPLICATE KEY UPDATE nom = VALUES(nom)');
            $stmt->execute(['nom' => $origine]);
        } catch (Throwable $e) {
            // Table origines optionnelle
        }
    }
    origines_valides($db, true);

    foreach ($prepared as $data) {
        import_insert_row($db, $data, (int) (current_user()['id'] ?? 1), $skipBusinessRules);
    }
    $db->commit();

    $flashMsg = count($prepared) . ' dossier(s) importé(s) avec succès.';
    if ($skippedCount > 0) {
        $flashMsg .= ' ' . $skippedCount . ' doublon(s) de numéro de téléphone ignoré(s).';
    }
    set_flash('success', $flashMsg);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('dossiers_import error: ' . $e->getMessage());
    set_flash('error', 'Import refusé : ' . $e->getMessage());
}

redirect('dossiers.php');