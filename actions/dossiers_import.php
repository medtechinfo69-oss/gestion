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

function import_cell(array $row, array $indexes, string $header): string
{
    $index = $indexes[import_normalize_header($header)] ?? null;
    return $index === null ? '' : clean_str((string) ($row[$index] ?? ''));
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

function import_insert_row(PDO $db, array $data, int $userId): void
{
    $sql = 'INSERT INTO dossiers
        (vendeur_id, ta_origine, p_prod, date_vente, civilite, nom, prenom, mail, telfix, portable,
         nombre_personnes, date_naissance_assure, age_assure_principal, adresse, cp, ville, type_signature,
         ca_mois, ca_annuel, date_effet, produit, compagnie, courrier, etat_dossier, etat_contrat,
         commentaire, motif_annulation, created_by)
        VALUES
        (:vendeur_id, :ta_origine, :p_prod, :date_vente, :civilite, :nom, :prenom, :mail, :telfix, :portable,
         :nombre_personnes, :date_naissance_assure, :age_assure_principal, :adresse, :cp, :ville, :type_signature,
         :ca_mois, :ca_annuel, :date_effet, :produit, :compagnie, :courrier, :etat_dossier, :etat_contrat,
         :commentaire, :motif_annulation, :created_by)';
    $data['created_by'] = $userId;
    $db->prepare($sql)->execute($data);
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
    $requiredHeaders = ['Vendeur', 'Date vente', 'Civilité', 'Nom', 'Prénom', 'Téléphone 2', 'Adresse', 'CP', 'Ville', 'Type de signature', 'CA-mois', "Date d'effet", 'Produit', 'Compagnie'];
    foreach ($requiredHeaders as $required) {
        if (!isset($indexes[import_normalize_header($required)])) {
            throw new RuntimeException('En-tête obligatoire manquante : ' . $required . '.');
        }
    }

    $vendeurs = $db->query("SELECT id, nom_complet FROM users WHERE role = 'vendeur'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $vendeursByName = [];
    foreach ($vendeurs as $id => $name) {
        $vendeursByName[import_normalize_header($name)] = (int) $id;
    }
    $prepared = [];
    $errors = [];
    $db->beginTransaction();
    foreach (array_slice($rows, 1) as $offset => $row) {
        $line = $offset + 2;
        $sellerName = import_cell($row, $indexes, 'Vendeur');
        $sellerId = import_vendeur_id($db, $sellerName, $vendeursByName);
        $courrierText = import_cell($row, $indexes, 'Courrier');
        $courrier = array_values(array_filter(array_map('trim', preg_split('/[,;|]+/', $courrierText) ?: [])));
        $post = [
            'vendeur_id' => $sellerId, 'ta_origine' => import_cell($row, $indexes, 'Origine'), 'p_prod' => import_cell($row, $indexes, 'Prod'),
            'date_vente' => import_date_value(import_cell($row, $indexes, 'Date vente')), 'civilite' => import_cell($row, $indexes, 'Civilité'),
            'nom' => import_cell($row, $indexes, 'Nom'), 'prenom' => import_cell($row, $indexes, 'Prénom'), 'mail' => import_cell($row, $indexes, 'Mail'),
            'telfix' => import_cell($row, $indexes, 'Téléphone 1'), 'portable' => import_cell($row, $indexes, 'Téléphone 2'),
            'nombre_personnes' => import_cell($row, $indexes, "NB d'assurés") ?: 1, 'date_naissance_assure' => import_cell($row, $indexes, 'Date naissance assuré'),
            'age_assure_principal' => import_cell($row, $indexes, 'Age assuré principal'), 'adresse' => import_cell($row, $indexes, 'Adresse'),
            'cp' => import_cell($row, $indexes, 'CP'), 'ville' => import_cell($row, $indexes, 'Ville'), 'type_signature' => import_cell($row, $indexes, 'Type de signature'),
            'ca_mois' => import_cell($row, $indexes, 'CA-mois'), 'ca_annuel' => import_cell($row, $indexes, 'CA-annuel'),
            'date_effet' => import_date_value(import_cell($row, $indexes, "Date d'effet")), 'produit' => import_cell($row, $indexes, 'Produit'),
            'compagnie' => import_cell($row, $indexes, 'Compagnie'), 'courrier' => $courrier, 'etat_contrat' => import_cell($row, $indexes, 'Etat du contrat') ?: 'Actif',
            'commentaire' => import_cell($row, $indexes, 'Commentaire dossier'),
        ];
        $result = validate_dossier_input($post, $db, null, true);
        if ($result['errors']) {
            $errors[] = 'Ligne ' . $line . ' : ' . implode(' ', array_values($result['errors']));
        } else {
            $prepared[] = $result['data'];
        }
    }
    if ($errors) {
        throw new RuntimeException(implode(' ', array_slice($errors, 0, 8)) . (count($errors) > 8 ? ' D’autres erreurs existent.' : ''));
    }

    foreach ($prepared as $data) {
        import_insert_row($db, $data, (int) current_user()['id']);
    }
    $db->commit();
    set_flash('success', count($prepared) . ' dossier(s) importé(s) avec succès.');
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('dossiers_import error: ' . $e->getMessage());
    set_flash('error', 'Import refusé : ' . $e->getMessage());
}

redirect('dossiers.php');