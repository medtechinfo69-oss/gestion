<?php
require_once __DIR__ . '/../includes/init.php';
require_admin_or_superviseur();
// L'import est réservé à l'admin et au superviseur strict : un vendeur
// (même can_supervise = 1) ne dispose pas de l'import, il consulte sa liste.
if (!is_admin() && !is_role_superviseur()) {
    csrf_require();
    set_flash('error', 'Import réservé aux administrateurs et superviseurs.');
    redirect('dossiers.php');
}

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

/**
 * Convertit les numéros de série Excel présents dans un texte libre en dates
 * lisibles, sans perdre le reste du contenu. Utile pour « Date(s) naissance »,
 * qui accepte une ou deux dates séparées par du texte (ex. couple).
 * Ex. « 21044 » -> « 21/04/1944 » ; « 21044 et 23000 » -> les deux converties.
 */
function import_dates_text(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return $value;
    }

    // Un seul nombre pour tout le champ : c'est le cas courant d'une cellule
    // Excel de type date (ex. « 21044 »). On ne convertit que s'il s'agit d'un
    // numéro de série Excel plausible ET qu'il n'est PAS une année à 4 chiffres
    // saisie à la main (1900-2100), pour ne jamais transformer « 1960 ».
    if (preg_match('/^\d+(\.\d+)?$/', $trimmed)) {
        $number = (float) $trimmed;
        $isPlainYear = $number >= 1900 && $number <= 2100 && (float) (int) $number === $number;
        if (!$isPlainYear && $number >= 1 && $number <= 73050) {
            $timestamp = (int) round(($number - 25569) * 86400);
            return gmdate('d/m/Y', $timestamp);
        }
        return $value;
    }

    // Plusieurs valeurs dans le champ (ex. « 21044 et 23000 » pour un couple) :
    // on convertit chaque nombre isolé, mais UNIQUEMENT ceux qui ne peuvent pas
    // être une année et qui ne font pas partie d'une date déjà formatée
    // (jj/mm/aaaa) — sinon « 12/04/1960 » serait corrompu.
    $converted = preg_replace_callback('/(?<![\d\/.-])(\d{5,})(?![\d\/.-])/', function (array $m): string {
        $number = (float) $m[1];
        if ($number >= 1 && $number <= 73050) {
            $timestamp = (int) round(($number - 25569) * 86400);
            return gmdate('d/m/Y', $timestamp);
        }
        return $m[0];
    }, $value);

    return (string) $converted;
}

/**
 * Normalise une valeur de courrier pour comparaison : minuscules, sans
 * accents, sans espaces ni ponctuation. Ex. « Résiliation », « RESILIATION »
 * et « resiliation » produisent tous « resiliation ».
 */
function import_courrier_key(string $value): string
{
    $value = str_replace(["\xA0", "\xC2\xA0", "\xE2\x80\x8B"], ' ', $value);
    $value = strtolower(trim($value));
    $value = strtr($value, [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'õ' => 'o',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
        'ç' => 'c',
    ]);
    return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
}

/**
 * Convertit le texte brut de la colonne « Courrier » du fichier importé en
 * liste de valeurs CANONIQUES (parmi options_courrier()). Tolère les
 * séparateurs variés (virgule, point-virgule, barre, « / »), les espaces
 * multiples, les différences de casse/accents et un éventuel préfixe/suffixe
 * explicatif. Repli : si aucune valeur ne correspond, on conserve le texte
 * original découpé (jamais perdu).
 */
function import_courrier_values(string $text): array
{
    $options = options_courrier();
    $keyToCanonical = [];
    foreach ($options as $option) {
        $keyToCanonical[import_courrier_key($option)] = $option;
    }

    $tokens = preg_split('/[,;|\/\n\r\t]+/', $text) ?: [];
    $result = [];
    $unmatched = [];
    foreach ($tokens as $token) {
        $token = trim($token);
        if ($token === '') {
            continue;
        }
        // 1) Correspondance exacte (accents/casse/espaces ignorés).
        $key = import_courrier_key($token);
        if (isset($keyToCanonical[$key])) {
            $result[] = $keyToCanonical[$key];
            continue;
        }
        // 2) Le texte peut contenir plusieurs options collées ou un libellé
        //    enrichi (« Résiliation (faite) ») : on cherche chaque option
        //    canonique en sous-chaîne pour l'extraire sans perte.
        $matched = false;
        foreach ($keyToCanonical as $optKey => $canonical) {
            if ($optKey !== '' && str_contains($key, $optKey)) {
                $result[] = $canonical;
                $matched = true;
            }
        }
        if (!$matched) {
            $unmatched[] = $token;
        }
    }

    // Aucune valeur reconnue du tout : on garde le contenu brut (découpé)
    // pour ne jamais perdre l'information du fichier.
    if (!$result && $unmatched) {
        return array_values(array_unique($unmatched));
    }

    return array_values(array_unique($result));
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
    // Les dates de validation / d'annulation et le motif d'annulation sont
    // fournis par l'appelant (colonnes « Date validation », « Date d'annulation »
    // et « Motif d'annulation » du fichier importé) ; on ne les écrase plus ici,
    // sinon un export suivi d'un réimport perdait ces informations.
    $data['date_dossier_complet'] = $data['date_dossier_complet'] ?? null;
    $data['date_contrat_non_actif'] = $data['date_contrat_non_actif'] ?? null;
    $sql = 'INSERT INTO dossiers
        (vendeur_id, ta_origine, p_prod, date_vente, civilite, nom, prenom, mail, telfix, portable,
         nombre_personnes, date_naissance_assure, age_assure_principal, adresse, cp, ville, type_signature,
         ca_mois, ca_annuel, date_effet, date_injection, produit, compagnie, courrier, etat_dossier, date_dossier_complet,
         etat_contrat, controle_qualite, date_contrat_non_actif, commentaire, motif_annulation, created_by)
        VALUES
        (:vendeur_id, :ta_origine, :p_prod, :date_vente, :civilite, :nom, :prenom, :mail, :telfix, :portable,
         :nombre_personnes, :date_naissance_assure, :age_assure_principal, :adresse, :cp, :ville, :type_signature,
         :ca_mois, :ca_annuel, :date_effet, :date_injection, :produit, :compagnie, :courrier, :etat_dossier, :date_dossier_complet,
         :etat_contrat, :controle_qualite, :date_contrat_non_actif, :commentaire, :motif_annulation, :created_by)';
    $data['created_by'] = $userId;
    $db->prepare($sql)->execute($data);
    // Keep creation history logging even for imports
    log_dossier_history($db, (int) $db->lastInsertId(), $userId, 'creation');
}

$file = $_FILES['fichier'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > max_import_size_bytes($db)) {
    $maxUploadMb = (int) (max_import_size_bytes($db) / 1024 / 1024);
    set_flash('error', 'Veuillez sélectionner un fichier Excel valide de ' . $maxUploadMb . ' Mo maximum.');
    redirect('dossiers_import.php');
}

$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($extension, ['xlsx', 'csv'], true)) {
    set_flash('error', 'Format refusé. Utilisez un fichier .xlsx ou .csv.');
    redirect('dossiers_import.php');
}

// Allow callers to request import that skips business rules (dates/annulation/auto-validation).
// Only administrators can bypass import business rules.
$skipBusinessRules = false;
if (is_admin() && (!empty($_POST['skipBusinessRules']) || !empty($_POST['skip_business_rules']) || (isset($_REQUEST['skipBusinessRules']) && $_REQUEST['skipBusinessRules'] === 'true'))) {
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

    // Un dossier est un DOUBLON seulement si TOUTES les valeurs cles sont
    // identiques : telephone + CA-mois + compagnie.
    // Deux lignes avec le meme telephone mais un CA-mois OU une compagnie
    // differents NE sont PAS des doublons : elles sont importees.
    //
    // IMPORTANT : la cle doit etre construite sur les valeurs NORMALISEES
    // telles qu'elles sont (ou seront) STOCKEES en base, sinon une simple
    // reimportation du meme fichier ne serait jamais reconnue comme doublon
    // (le CA-mois est stocke en DECIMAL, pas comme la chaine brute du fichier :
    // "1 200,50" -> 1200.50).
    $dupKeyOf = function (
        string $telfix,
        string $portable,
        string $caMois,
        string $compagnie,
        string $nom = '',
        string $prenom = '',
        string $dateVente = ''
    ): string {
        $clean = static function (string $v): string {
            // Comparaison insensible a la casse et aux espaces multiples ;
            // les espaces insecables, zero-width et caracteres de controle sont
            // retires exactement comme lors du stockage (clean_str()).
            $v = str_replace(["\xC2\xA0", "\xE2\x80\x8B"], ' ', $v);
            $v = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v);
            return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $v)));
        };
        $phoneNorm = static function (string $v): string {
            $n = preg_replace('/[^0-9]/', '', $v);
            // Format international francais : « +33 6 51... » -> « 0651... ».
            // On retire l'indicatif 33 puis on retablit le zero national, afin
            // que les deux ecritures produisent la meme cle.
            if (strlen($n) === 11 && $n[0] === '3' && $n[1] === '3') {
                $n = '0' . substr($n, 2);
            }
            // « 0000 » et « 0000000000 » sont les valeurs de remplacement utilisees
            // lorsque aucun telephone n'est fourni (validation a l'import) : elles
            // ne constituent pas un numero exploitable. Cela permet a ces lignes de
            // retomber sur la cle d'identite « SANS-TEL » ci-dessous.
            return (strlen($n) >= 6 && $n !== '0000' && $n !== '0000000000') ? $n : '';
        };
        // Le CA-mois est ramene a un NOMBRE, puis formate avec un nombre de
        // decimales suffisant pour absorber les differences de precision entre
        // le fichier et la colonne DECIMAL de la base (ex. 1200.5 vs 1200.50).
        $caMoisNorm = static function (string $v): string {
            $v = str_replace(",", ".", trim($v));
            $v = preg_replace('/[^0-9.]/', '', $v) ?? '';
            $value = filter_var($v, FILTER_VALIDATE_FLOAT);
            return number_format((float) ($value === false ? 0 : $value), 2, '.', '');
        };
        $caMoisKey = $caMoisNorm($caMois);
        // Le CA-annuel n'est PAS inclus dans la cle : il est entierement derive
        // du CA-mois (ca_mois * 12 * 0.846). L'inclure n'apportait aucun
        // discriminant utile et provoquait des faux "non doublons" a cause du
        // recalcul/arrondi. Deux lignes avec meme telephone, meme CA-mois et
        // meme compagnie sont donc des doublons, point.
        // La validation remplace une compagnie vide par « Autre » lors d'un
        // import (validate_dossier_input). La cle doit donc porter la valeur
        // STOCKEE : sinon une ligne dont la colonne Compagnie est vide n'est
        // jamais reconnue comme doublon et se reimporte a chaque envoi du meme
        // fichier (1 dossier importe + N doublons a l'infini).
        $compagnieKey = $clean($compagnie !== '' ? $compagnie : 'Autre');
        // La colonne `portable` recoit un suffixe d'unicite (« ...-46-1 ») a
        // l'insertion : on le retire afin de comparer la valeur du fichier a
        // celle de la base.
        $portableBase = (string) preg_replace('/-\d+-\d+$/', '', trim($portable));
        // Le telephone de reference est Tel 1, sinon Tel 2.
        $phone = $phoneNorm($telfix);
        if ($phone === '') {
            $phone = $phoneNorm($portableBase);
        }
        if ($phone === '') {
            // Aucun numero exploitable : repli sur l'identite du dossier
            // (nom + prenom + date de vente), sinon la meme ligne serait
            // reimportee a chaque envoi du fichier.
            $dateKey = (string) (parse_date_fr(import_date_value($dateVente)) ?? '');
            return 'SANS-TEL|' . $clean($nom) . '|' . $clean($prenom) . '|' . $dateKey
                . '|' . $caMoisKey . '|' . $compagnieKey;
        }
        return $phone . '|' . $caMoisKey . '|' . $compagnieKey;
    };

    $existingRowsForDup = $db->query("SELECT telfix, portable, ca_mois, ca_annuel, compagnie, nom, prenom, date_vente FROM dossiers")->fetchAll(PDO::FETCH_ASSOC);
    $seenKeys = [];
    $duplicateRows = [];
    // La colonne `portable` est GLOBALE et UNIQUE (uq_dossiers_portable). On
    // mémorise donc toutes les valeurs déjà présentes en base afin de ne jamais
    // tenter d'insérer un doublon — sinon l'INSERT échoue avec l'erreur 1062
    // et tout l'import est annulé.
    $usedPortables = [];
    foreach ($existingRowsForDup as $existingRow) {
        $key = $dupKeyOf(
            (string) ($existingRow['telfix'] ?? ''),
            (string) ($existingRow['portable'] ?? ''),
            (string) ($existingRow['ca_mois'] ?? ''),
            (string) ($existingRow['compagnie'] ?? ''),
            (string) ($existingRow['nom'] ?? ''),
            (string) ($existingRow['prenom'] ?? ''),
            (string) ($existingRow['date_vente'] ?? '')
        );
        // La cle est toujours exploitable : le repli « SANS-TEL » couvre les
        // lignes sans telephone pour qu'elles ne soient pas reimportees.
        $seenKeys[$key] = true;
        $existingPortable = trim((string) ($existingRow['portable'] ?? ''));
        if ($existingPortable !== '') {
            $usedPortables[$existingPortable] = true;
        }
    }

    // Rend la valeur de `portable` unique au regard de la contrainte UNIQUE :
    // si elle est déjà utilisée (en base ou par une autre ligne déjà préparée),
    // on lui ajoute un suffixe discriminant au lieu de faire échouer l'import.
    $makePortableUnique = static function (string $portable, int $rowNumber) use (&$usedPortables): string {
        $portable = trim($portable);
        if ($portable !== '' && !isset($usedPortables[$portable])) {
            $usedPortables[$portable] = true;
            return $portable;
        }
        $base = $portable !== '' ? $portable : 'N/A';
        $suffix = 1;
        do {
            $candidate = $base . '-' . $rowNumber . '-' . $suffix;
            $suffix++;
        } while (isset($usedPortables[$candidate]));
        $usedPortables[$candidate] = true;
        return $candidate;
    };

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

        // Valeurs clés lues avant la détection de doublon.
        $nomVal = import_cell($row, $indexes, ['Nom', 'Nom client']);
        $prenomVal = import_cell($row, $indexes, ['Prénom', 'Prenom', 'Prénom client']);
        $caMoisVal = import_cell($row, $indexes, ['CA-mois', 'CA mois', 'CA Mensuel', 'Cotisation']);
        $compagnieVal = import_cell($row, $indexes, ['Compagnie', 'Assureur']);
        $dateVenteRaw = import_cell($row, $indexes, ['Date vente', 'Date de vente', 'Vente']);
        $dateVenteVal = import_date_value($dateVenteRaw);

        // La colonne « CA-annuel » est lue telle qu'elle figure dans le fichier
        // (l'export produit cet en-tête). La validation la recalcule de toute
        // façon à partir du CA mensuel (ca_mois * 12 * 0.846) : cette lecture
        // sert donc uniquement à conserver la compatibilité des fichiers
        // exportés puis réimportés.
        $caAnnuelVal = import_cell($row, $indexes, ['CA-annuel', 'CA annuel', 'CA Annuel']);

        // Doublon seulement si TOUTES les valeurs clés sont identiques
        // (téléphone + CA-mois + compagnie). Même téléphone mais CA-mois ou
        // compagnie différents => la ligne est importée (ce n'est pas un doublon).
        // La clé est construite sur les valeurs TELLES QU'ELLES SONT STOCKÉES
        // (compagnie vide => « Autre », portable sans suffixe d'unicité,
        // téléphone normalisé) : indispensable pour qu'un second import du même
        // fichier soit reconnu intégralement comme doublon.
        $dupKey = $dupKeyOf($telfix, $portable, $caMoisVal, $compagnieVal, $nomVal, $prenomVal, $dateVenteVal);
        if (isset($seenKeys[$dupKey])) {
            $skippedCount++;
            $duplicateRows[] = $row;
            continue;
        }

        $seenKeys[$dupKey] = true;

        $dateEffetRaw = import_cell($row, $indexes, ["Date d'effet", 'Date effet', 'Effet']);
        $dateEffetVal = import_date_value($dateEffetRaw);

        $dateInjectionRaw = import_cell($row, $indexes, ['Date injection', "Date d'injection"]);

        $courrierText = import_cell($row, $indexes, ['Courrier', 'Courriers']);
        // Normalisation vers les valeurs canoniques (case/accents/séparateurs) :
        // sans cela, un courrier importé avec une autre casse ou un autre
        // séparateur était stocké brut puis masqué à l'affichage (courrier_values
        // filtre sur options_courrier()) et l'état « Dossier complet » n'était
        // jamais recalculé correctement.
        $courrier = import_courrier_values($courrierText);

        $origineRaw = trim(import_cell($row, $indexes, ['Origine', 'Source', 'Ta origine']));
        if ($origineRaw !== '' && !isset($existingOrigines[mb_strtolower($origineRaw)])) {
            $newOrigines[mb_strtolower($origineRaw)] = $origineRaw;
            $existingOrigines[mb_strtolower($origineRaw)] = true;
        }

        // Le portable doit rester unique en base : un portable vide retombe
        // sur le tel fixe, puis sur un identifiant de repli.
        $basePortable = $portable !== '' ? $portable : ($telfix !== '' ? $telfix : '');
        $postPortable = $basePortable !== '' ? $basePortable : 'N/A';

        // Colonnes de supervision : dates de validation / d'annulation et motif.
        // Elles figurent dans l'export et doivent être relues telles quelles au
        // réimport (sinon elles sont perdues à chaque cycle export → import).
        $dateValidationRaw = import_cell($row, $indexes, ['Date validation', 'Date de validation', 'Date dossier complet']);
        $dateAnnulationRaw = import_cell($row, $indexes, ["Date d'annulation", 'Date annulation', 'Date contrat non actif']);
        $motifAnnulationRaw = import_cell($row, $indexes, ["Motif d'annulation", 'Motif annulation', 'Motif']);

        $post = [
            'vendeur_id' => $sellerId,
            'ta_origine' => $origineRaw,
            'p_prod' => import_cell($row, $indexes, ['Prod', 'P_prod']),
            'date_vente' => $dateVenteVal,
            'civilite' => import_cell($row, $indexes, ['Civilité', 'Civilite', 'Civ']),
            'nom' => $nomVal,
            'prenom' => $prenomVal,
            'mail' => import_cell($row, $indexes, ['Mail', 'Email', 'E-mail', 'Courriel']),
            'telfix' => $telfix,
            'portable' => $postPortable,
            'nombre_personnes' => import_cell($row, $indexes, ["NB d'assurés", 'Nombre de personnes', 'Assurés']) ?: 1,
            'date_naissance_assure' => import_dates_text(import_cell($row, $indexes, ['Date naissance assuré', 'Date naissance', 'Date de naissance'])),
            'age_assure_principal' => import_cell($row, $indexes, ['Age assuré principal', 'Âge', 'Age']),
            'adresse' => import_cell($row, $indexes, ['Adresse', 'Adresse postale']),
            'cp' => import_cell($row, $indexes, ['CP', 'Code postal']),
            'ville' => import_cell($row, $indexes, ['Ville', 'Commune']),
            'type_signature' => import_cell($row, $indexes, ['Type de signature', 'Type signature', 'Signature']),
            'ca_mois' => $caMoisVal,
            'ca_annuel' => $caAnnuelVal,
            'date_effet' => $dateEffetVal,
            'produit' => import_cell($row, $indexes, ['Produit', 'Offre', 'Formule']),
            'compagnie' => $compagnieVal,
            'courrier' => $courrier,
            'etat_contrat' => import_cell($row, $indexes, ['Etat du contrat', 'État du contrat', 'Contrat']) ?: 'Actif',
            'controle_qualite' => import_cell($row, $indexes, ['Controle qualite', 'Contrôle qualité', 'Controle qualité']),
            'commentaire' => import_cell($row, $indexes, ['Commentaire dossier', 'Commentaire', 'Notes']),
            'date_injection' => import_date_value($dateInjectionRaw),
            'date_dossier_complet' => $dateValidationRaw !== '' ? $dateValidationRaw : null,
            'date_contrat_non_actif' => $dateAnnulationRaw !== '' ? $dateAnnulationRaw : null,
            'motif_annulation' => $motifAnnulationRaw,
        ];

        $result = validate_dossier_input($post, $db, null, true);
        $data = $result['data'];

        // La validation ne gère pas ces colonnes de supervision : on les ajoute
        // après coup. La date vient EXCLUSIVEMENT du fichier (jamais la date du
        // jour) : une date de validation/annulation absente reste vide, sinon
        // un import écraserait la valeur réelle par « aujourd'hui ».
        $dateValidation = parse_date_fr(import_date_value($dateValidationRaw));
        $dateAnnulation = parse_date_fr(import_date_value($dateAnnulationRaw));
        $data['date_dossier_complet'] = $data['etat_dossier'] === 'Dossier complet'
            ? $dateValidation
            : null;
        $data['date_contrat_non_actif'] = $data['etat_contrat'] !== 'Actif'
            ? $dateAnnulation
            : null;
        $data['motif_annulation'] = $motifAnnulationRaw !== '' ? $motifAnnulationRaw : ($data['motif_annulation'] ?? null);

        // La validation d'import ne peut pas vérifier l'unicité du portable
        // (contrainte GLOBALE uq_dossiers_portable non connue à ce stade). On
        // la garantit ici, APRÈS validation, pour éviter toute erreur
        // SQLSTATE[23000] / 1062 qui annulerait tout l'import.
        $data['portable'] = $makePortableUnique((string) ($data['portable'] ?? ''), $offset + 2);

        // `date_vente` et `date_effet` sont NOT NULL en base : une cellule vide
        // ou invalide ne doit JAMAIS produire null (sinon l'INSERT échoue avec
        // une erreur SQL et tout l'import est annulé). On retombe sur la date
        // calculée par la validation (aujourd'hui pour la vente ; date de vente
        // pour l'effet), exactement comme à la création manuelle d'un dossier.
        $data['date_vente'] = $data['date_vente'] ?: date('Y-m-d');
        $data['date_effet'] = $data['date_effet'] ?: ($data['date_vente'] ?: date('Y-m-d'));

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

    // Message clair lorsque le fichier a déjà été importé : sans cela,
    // l'utilisateur voit « 0 dossier(s) importé(s) » et croit à un échec.
    if (count($prepared) > 0) {
        $flashMsg = count($prepared) . ' dossier(s) importé(s) avec succès.';
    } elseif ($skippedCount > 0) {
        $flashMsg = 'Aucun nouveau dossier : les ' . $skippedCount . ' ligne(s) de ce fichier ont déjà été importées.';
    } else {
        $flashMsg = 'Aucun dossier à importer : le fichier ne contient aucune ligne exploitable.';
    }
    
    if ($skippedCount > 0 && count($duplicateRows) > 0) {
        // Générer un fichier XLSX avec les doublons
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('La génération .xlsx nécessite l\'extension PHP ZipArchive.');
        }

        // app_temp_file() évite /home/tmp (hors open_basedir sur InfinityFree)
        // et privilégie uploads/tmp du projet.
        $duplicateFile = app_temp_file('doublons_');
        if ($duplicateFile === '') {
            throw new RuntimeException('Aucun dossier temporaire accessible sur le serveur.');
        }
        
        // Helper function to convert column index to Excel letter (1=A, 26=Z, 27=AA, etc)
        $indexToLetter = static function(int $col): string {
            $letter = '';
            while ($col > 0) {
                $col--;
                $letter = chr(65 + ($col % 26)) . $letter;
                $col = (int)($col / 26);
            }
            return $letter;
        };
        
        $zip = new ZipArchive();
        if ($zip->open($duplicateFile, ZipArchive::OVERWRITE) === true) {
            // Créer les lignes d'en-tête
            $headerRow = '<row r="1">';
            $colIndex = 1;
            foreach ($headers as $header) {
                $colLetter = $indexToLetter($colIndex);
                $headerRow .= '<c r="' . $colLetter . '1" t="inlineStr"><is><t xml:space="preserve">' . htmlspecialchars((string) $header, ENT_XML1) . '</t></is></c>';
                $colIndex++;
            }
            $headerRow .= '</row>';
            
            // Ajouter les lignes de doublons
            $sheetRows = [$headerRow];
            foreach ($duplicateRows as $rowNum => $row) {
                $excelRow = $rowNum + 2;
                $rowXml = '<row r="' . $excelRow . '">';
                $colIndex = 1;
                foreach ($row as $cellValue) {
                    $colLetter = $indexToLetter($colIndex);
                    $cellValue = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string) $cellValue) ?? '';
                    $rowXml .= '<c r="' . $colLetter . $excelRow . '" t="inlineStr"><is><t xml:space="preserve">' . htmlspecialchars($cellValue, ENT_XML1) . '</t></is></c>';
                    $colIndex++;
                }
                $rowXml .= '</row>';
                $sheetRows[] = $rowXml;
            }
            
            $lastCol = $indexToLetter(count($headers));
            $lastRow = count($duplicateRows) + 1;
            
            $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<dimension ref="A1:' . $lastCol . $lastRow . '"/><sheetData>' . implode('', $sheetRows) . '</sheetData></worksheet>';
            $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Doublons" sheetId="1" r:id="rId1"/></sheets></workbook>';
            $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>';
            $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
            $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>';
            
            $zip->addFromString('[Content_Types].xml', $contentTypes);
            $zip->addFromString('_rels/.rels', $rootRels);
            $zip->addFromString('xl/workbook.xml', $workbookXml);
            $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
            $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
            $zip->close();
            
            // Stocker le fichier temporaire dans la session pour téléchargement
            $_SESSION['dupli_file_path'] = $duplicateFile;
            $_SESSION['dupli_file_time'] = time();
            $_SESSION['show_duplicates_download'] = true;
        }
        
        if (count($prepared) > 0) {
            $flashMsg .= ' ' . $skippedCount . ' doublon(s) de numéro de téléphone trouvé(s).';
        }
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