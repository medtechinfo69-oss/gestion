<?php
/**
 * Fonctions utilitaires génériques : échappement de sortie, messages
 * flash, formatage, validation des données métier.
 */

if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Accès direct interdit.');
}

/** Échappement systématique pour tout affichage HTML (anti-XSS). */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Version d'un asset statique (cache-busting via ?v=…), mémorisée par requête.
 *
 * En production, CACHE_VERSION fixe la version : aucune statistique de
 * fichier n'est nécessaire, les assets restent donc servis depuis le cache
 * navigateur (1 mois) sans invalidation inutile.
 *
 * En développement, on retombe sur filemtime() pour que toute modification
 * soit immédiatement visible sans vider le cache du navigateur. Le résultat
 * est mis en cache dans un tableau statique : filemtime() est un appel
 * système (stat), et sans mémorisation il était exécuté plusieurs fois par
 * requête et par asset (une fois pour le <link> preload, une fois pour le
 * <link> stylesheet).
 */
function asset_version(string $relativePath): string
{
    static $cache = [];

    if (array_key_exists($relativePath, $cache)) {
        return $cache[$relativePath];
    }

    if (defined('CACHE_VERSION')) {
        return $cache[$relativePath] = (string) CACHE_VERSION;
    }

    $absolute = __DIR__ . '/../assets/' . ltrim($relativePath, '/');
    $mtime = @filemtime($absolute);

    return $cache[$relativePath] = (string) ($mtime !== false ? $mtime : '1');
}

/**
 * Tronque un texte à $limit caractères (50 par défaut) pour l'affichage
 * dans les tableaux. Ajoute une ellipse « … » si le texte est coupe.
 * La coupe se fait proprement sur les caracteres UTF-8 (multibyte).
 */
function truncate_chars(?string $value, int $limit = 50): string
{
    $value = (string) ($value ?? '');
    if ($limit <= 0 || mb_strlen($value, 'UTF-8') <= $limit) {
        return $value;
    }
    return rtrim(mb_substr($value, 0, $limit, 'UTF-8')) . '…';
}

/** Enregistre un message flash affiché une seule fois à la page suivante. */
function set_flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Récupère et vide les messages flash en attente. */
function get_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

/** Redirection sécurisée en interne uniquement (empêche l'open redirect). */
function redirect(string $path): void
{
    header('Location: ' . APP_URL . '/' . ltrim($path, '/'));
    exit;
}

/** Formate un montant en euros (ex : 1 234,56 €). */
function format_montant(?float $value): string
{
    if ($value === null) {
        return '—';
    }
    return number_format($value, 2, ',', ' ') . ' €';
}

/** Formate un montant RH en dinars tunisiens. */
function format_montant_tnd(?float $value): string
{
    if ($value === null) {
        return '—';
    }
    return number_format($value, 2, ',', ' ') . ' TND';
}

/** Formate un nombre sans devise (ex : 1 234,56). */
function format_nombre(?float $value): string
{
    if ($value === null) {
        return '—';
    }
    return number_format($value, 2, ',', ' ');
}

function app_setting(PDO $db, string $key, string $default = ''): string
{
    try {
        $stmt = $db->prepare('SELECT setting_value FROM settings WHERE setting_key = :key LIMIT 1');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string) $value;
    } catch (Throwable $e) {
        return $default;
    }
}

function save_app_setting(PDO $db, string $key, string $value): void
{
    $stmt = $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->execute(['key' => $key, 'value' => $value]);
}

function max_import_size_bytes(PDO $db): int
{
    $megabytes = (int) app_setting($db, 'max_upload_mb', (string) (IMPORT_MAX_SIZE / 1024 / 1024));
    return max(1, min(100, $megabytes)) * 1024 * 1024;
}

/** Formate une date SQL (Y-m-d) au format français (d/m/Y). */
function format_date(?string $sqlDate): string
{
    if (!$sqlDate || $sqlDate === '0000-00-00') {
        return '—';
    }
    $ts = strtotime($sqlDate);
    return $ts ? date('d/m/Y', $ts) : '—';
}

/** Convertit une date française (d/m/Y, Y-m-d, d-m-Y...) en date SQL (Y-m-d), ou null si invalide. */
function parse_date_fr(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    // YYYY-MM-DD or YYYY/MM/DD
    if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $value, $m)) {
        if (checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
    }
    // DD/MM/YYYY or DD-MM-YYYY
    if (preg_match('#^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$#', $value, $m)) {
        if (checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
    }
    // DD/MM/YY or DD-MM-YY
    if (preg_match('#^(\d{1,2})[-\/](\d{1,2})[-\/](\d{2})$#', $value, $m)) {
        $year = (int) $m[3] + 2000;
        if (checkdate((int) $m[2], (int) $m[1], $year)) {
            return sprintf('%04d-%02d-%02d', $year, (int) $m[2], (int) $m[1]);
        }
    }
    $ts = strtotime($value);
    if ($ts !== false && $ts > 0) {
        return date('Y-m-d', $ts);
    }
    return null;
}

/** Nettoie une chaîne (trim + suppression des caractères de contrôle). */
function clean_str(?string $value): string
{
    $value = trim((string) $value);
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
}

/**
 * Nettoie une adresse e-mail saisie, en tolérant les défauts fréquents :
 * espaces invisibles (insécable, zero-width, BOM) issus d'un copier-coller,
 * espaces à l'intérieur ("nom @gmail.com"), guillemets/chevrons et
 * ponctuation collés au début/fin ("<x@gmail.com>", "x@gmail.com.").
 * Retourne une chaîne vide si rien d'exploitable.
 */
function clean_email_input(?string $value): string
{
    $value = trim((string) $value);
    // Espaces "invisibles" : insécable, fines, zero-width, BOM...
    $value = preg_replace('/[\x{00A0}\x{1680}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}\x{FEFF}]/u', '', $value) ?? '';
    // Tous les autres espaces/tabulations à l'intérieur
    $value = preg_replace('/\s+/u', '', $value) ?? '';
    // Guillemets / chevrons / ponctuation en début ou fin (copier-coller depuis un mail)
    $value = trim($value, '"“”«»<>\'');
    $value = rtrim($value, '.,;:');
    return $value;
}

/**
 * Validation SOUPLE d'une adresse e-mail :
 * accepte aussi les adresses correctes mais refusées par FILTER_VALIDATE_EMAIL
 * (caractères accentués, domaines internationaux) dès lors qu'elles ont la
 * forme plausible nom@domaine.tld. Une adresse vide renvoie false (champ optionnel).
 */
function email_plausible(string $email): bool
{
    if ($email === '') {
        return false;
    }
    if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
        return true;
    }
    return preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]{2,}$/u', $email) === 1;
}

/** Badge HTML coloré selon l'état du dossier (reprend le code couleur du classeur d'origine). */
function badge_etat(string $etat): string
{
    $map = [
        'Dossier complet' => 'badge-complet',
        'Dossier incomplet' => 'badge-noncomplet',
    ];
    $class = $map[$etat] ?? 'badge-default';
    return '<span class="badge ' . $class . '">' . e($etat) . '</span>';
}

/** Classe CSS de ligne de tableau selon l'état (mise en couleur des lignes). */
function row_class_etat(string $etat): string
{
    $map = [
        'Dossier complet' => 'row-complet',
        'Dossier incomplet' => 'row-noncomplet',
    ];
    return $map[$etat] ?? '';
}

/** Génère un identifiant lisible et unique pour un fichier uploadé. */
function generate_safe_filename(string $originalName): string
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext);
    return bin2hex(random_bytes(20)) . '.' . $ext;
}

/** Liste blanche des valeurs autorisées pour "Etat du dossier" (règle métier). */
function etats_dossier_valides(): array
{
    return ['Dossier complet', 'Dossier incomplet'];
}

function options_courrier(): array
{
    return ['Résiliation', 'RIB', 'Devoir de conseil', 'Consentement'];
}

function origines_valides(PDO $db = null, bool $refresh = false): array
{
    static $cached = null;
    if ($refresh || $cached === null) {
        $default = ['Lead', 'Fiche perso', 'MMC 12', 'MMC 25'];
        if (!$db) {
            $cached = $default;
            return $cached;
        }
        try {
            $stmt = $db->query('SELECT nom FROM origines ORDER BY nom');
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $cached = $rows ?: $default;
        } catch (Throwable $e) {
            $cached = $default;
        }
    }
    return $cached;
}

function etats_contrat_valides(): array
{
    return ['Actif', 'Renonciation', 'Résiliation infra-annuelle', 'Résiliation à échéance', 'Radié pour non-paiement', 'Sans effet qualite', 'CSS'];
}

function controles_qualite_valides(): array
{
    return ['OK', 'Moyen', 'KO'];
}

function courrier_values(?string $json): array
{
    $values = json_decode((string) $json, true);
    if (!is_array($values)) {
        return [];
    }
    return array_values(array_intersect(options_courrier(), $values));
}

function etat_dossier_from_courrier(array $courrier): string
{
    return count(array_unique($courrier)) === count(options_courrier())
        ? 'Dossier complet'
        : 'Dossier incomplet';
}

function badge_etat_contrat(string $etat): string
{
    $class = $etat === 'Actif' ? 'badge-complet' : 'badge-annule';
    return '<span class="badge ' . $class . '">' . e($etat) . '</span>';
}

/** Liste blanche pour "Civilité". */
function civilites_valides(): array
{
    return ['MR', 'MME', 'MLLE'];
}

/** Suggestions courantes pour "Type de signature" (champ libre avec suggestions). */
function types_signature_suggeres(): array
{
    return ['SMS', 'MAIL', 'COURRIER', 'MAIL+SMS', 'EMAIL'];
}

/** Suggestions courantes pour "Compagnie". */
function compagnies_suggerees(): array
{
    return ['KIASSURE', 'FMA', 'NEOLIANE'];
}

/**
 * Valide et normalise le formulaire de dossier.
 * Retourne ['errors' => [...], 'data' => [...]].
 * `$currentPortable` permet d'exclure le dossier courant du contrôle
 * d'unicité lors d'une modification.
 */
/** Libellé français lisible d'un champ de dossier (pour l'historique et les notifications). */
function dossier_field_label(string $field): string
{
    $labels = [
        'vendeur_id' => 'Vendeur', 'ta_origine' => 'Origine', 'p_prod' => 'Prod',
        'date_vente' => 'Date vente', 'civilite' => 'Civilité', 'nom' => 'Nom', 'prenom' => 'Prénom',
        'mail' => 'Mail', 'telfix' => 'Téléphone 1', 'portable' => 'Téléphone 2',
        'nombre_personnes' => "NB d'assurés", 'date_naissance_assure' => 'Date naissance assuré',
        'age_assure_principal' => 'Age assuré principal', 'adresse' => 'Adresse', 'cp' => 'CP', 'ville' => 'Ville',
        'type_signature' => 'Type de signature', 'ca_mois' => 'CA-mois', 'ca_annuel' => 'CA-annuel',
        'date_effet' => "Date d'effet", 'date_injection' => "Date d'injection",
        'produit' => 'Produit', 'compagnie' => 'Compagnie', 'etat_dossier' => 'Etat du dossier',
        'date_dossier_complet' => 'Date validation', 'courrier' => 'Courrier', 'commentaire' => 'Commentaire',
        'etat_contrat' => 'Etat du contrat', 'date_contrat_non_actif' => "Date d'annulation",
        'controle_qualite' => 'Contrôle qualité', 'motif_annulation' => "Motif d'annulation",
        'date_courrier_supervision' => 'Date courrier (superv.)',
        'date_etat_contrat_supervision' => 'Date état contrat (superv.)',
        'date_controle_qualite_supervision' => 'Date contrôle qualité (superv.)',
    ];
    return $labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
}

function validate_dossier_input(array $post, PDO $db, ?int $excludeId = null, bool $allowImportCompatibility = false): array
{
    $errors = [];
    $data = [];

    $data['vendeur_id'] = filter_var($post['vendeur_id'] ?? '', FILTER_VALIDATE_INT);
    if (!$data['vendeur_id']) {
        if ($allowImportCompatibility) {
            $data['vendeur_id'] = (int) (current_user()['id'] ?? 1);
        } else {
            $errors['vendeur_id'] = 'Veuillez sélectionner un vendeur.';
        }
    } else {
        $sellerSql = $allowImportCompatibility
            ? 'SELECT id FROM users WHERE id = :id'
            : 'SELECT id FROM users WHERE id = :id AND role = "vendeur"';
        $chk = $db->prepare($sellerSql);
        $chk->execute(['id' => $data['vendeur_id']]);
        if (!$chk->fetch()) {
            if ($allowImportCompatibility) {
                $data['vendeur_id'] = (int) (current_user()['id'] ?? 1);
            } else {
                $errors['vendeur_id'] = 'Vendeur invalide.';
            }
        }
    }

    $data['ta_origine'] = clean_str($post['ta_origine'] ?? '');
    if ($data['ta_origine'] === '') {
        $data['ta_origine'] = 'LEAD';
    }
    $legacyOrigins = ['5ASSUR', '5 ASSUR', 'TRANSFERT', 'PARRAINAGE', 'FICHE PERSO'];
    $originIsValid = $allowImportCompatibility
        ? mb_strlen($data['ta_origine']) <= 255
        : in_array($data['ta_origine'], origines_valides($db), true);
    if (!$originIsValid && !($allowImportCompatibility && in_array($data['ta_origine'], $legacyOrigins, true))) {
        try {
            $db->prepare('INSERT IGNORE INTO origines (nom) VALUES (:nom)')->execute(['nom' => $data['ta_origine']]);
            origines_valides($db, true);
            $originIsValid = true;
        } catch (Throwable $e) {
            $errors['ta_origine'] = 'Origine invalide.';
        }
    }
    $data['p_prod'] = clean_str($post['p_prod'] ?? '') ?: ($allowImportCompatibility ? '5 ASSUR' : '');

    $parsedDateVente = parse_date_fr($post['date_vente'] ?? '');
    if (!$parsedDateVente) {
        if ($allowImportCompatibility) {
            $parsedDateVente = date('Y-m-d');
        } else {
            $errors['date_vente'] = 'Date de vente invalide.';
        }
    }
    $data['date_vente'] = $parsedDateVente ?: date('Y-m-d');

    $data['civilite'] = strtoupper(clean_str($post['civilite'] ?? ''));
    if (!in_array($data['civilite'], civilites_valides(), true)) {
        if ($allowImportCompatibility) {
            $data['civilite'] = 'MR';
        } else {
            $errors['civilite'] = 'Civilité invalide.';
        }
    }

    $data['nom'] = clean_str($post['nom'] ?? '');
    if ($data['nom'] === '' || mb_strlen($data['nom']) > 150) {
        if ($allowImportCompatibility) {
            $data['nom'] = $data['nom'] !== '' ? mb_substr($data['nom'], 0, 150) : 'Non renseigné';
        } else {
            $errors['nom'] = 'Le nom est obligatoire (150 caractères maximum).';
        }
    }

    $data['prenom'] = clean_str($post['prenom'] ?? '');
    if ($data['prenom'] === '' || mb_strlen($data['prenom']) > 150) {
        if ($allowImportCompatibility) {
            $data['prenom'] = $data['prenom'] !== '' ? mb_substr($data['prenom'], 0, 150) : 'Non renseigné';
        } else {
            $errors['prenom'] = 'Le prénom est obligatoire (150 caractères maximum).';
        }
    }

    $data['mail'] = clean_str($post['mail'] ?? '');
    if ($data['mail'] === '') {
        $data['mail'] = null;
    }

    $data['telfix'] = preg_replace('/[^0-9+\s.]/', '', $post['telfix'] ?? '') ?: null;

    $data['portable'] = preg_replace('/[^0-9+\s.]/', '', $post['portable'] ?? '');
    if ($data['portable'] === '') {
        if ($allowImportCompatibility) {
            $data['portable'] = $data['telfix'] ?? '0000000000';
        } else {
            $errors['portable'] = 'Le numéro de portable est obligatoire.';
        }
    }
    if ($data['portable'] !== '' && !$allowImportCompatibility) {
        // Règle métier reprise du classeur : le numéro de portable doit être unique
        $sql = 'SELECT id FROM dossiers WHERE portable = :p';
        $params = ['p' => $data['portable']];
        if ($excludeId) {
            $sql .= ' AND id != :id';
            $params['id'] = $excludeId;
        }
        $chk = $db->prepare($sql);
        $chk->execute($params);
        if ($chk->fetch()) {
            $errors['portable'] = 'Ce numéro de portable est déjà utilisé par un autre dossier.';
        }
    }

    $data['nombre_personnes'] = filter_var($post['nombre_personnes'] ?? 1, FILTER_VALIDATE_INT, [
        'options' => ['default' => 1, 'min_range' => 1, 'max_range' => 10],
    ]) ?: 1;

    $data['date_naissance_assure'] = clean_str($post['date_naissance_assure'] ?? '') ?: null;
    $data['age_assure_principal'] = clean_str($post['age_assure_principal'] ?? '') ?: null;

    $data['adresse'] = clean_str($post['adresse'] ?? '');
    if ($data['adresse'] === '') {
        if ($allowImportCompatibility) {
            $data['adresse'] = 'Non renseignée';
        } else {
            $errors['adresse'] = 'L’adresse est obligatoire.';
        }
    }

    $data['cp'] = clean_str($post['cp'] ?? '');
    if ($data['cp'] === '' || !preg_match('/^[0-9A-Za-z\- ]{2,10}$/', $data['cp'])) {
        if ($allowImportCompatibility) {
            $data['cp'] = $data['cp'] !== '' ? mb_substr($data['cp'], 0, 10) : '00000';
        } else {
            $errors['cp'] = 'Code postal invalide.';
        }
    }

    $data['ville'] = clean_str($post['ville'] ?? '');
    if ($data['ville'] === '') {
        if ($allowImportCompatibility) {
            $data['ville'] = 'Non renseignée';
        } else {
            $errors['ville'] = 'La ville est obligatoire.';
        }
    }

    $data['type_signature'] = clean_str($post['type_signature'] ?? '');
    if ($data['type_signature'] === '') {
        if ($allowImportCompatibility) {
            $data['type_signature'] = 'SMS';
        } else {
            $errors['type_signature'] = 'Le type de signature est obligatoire.';
        }
    }

    // Parsing robuste du CA mensuel : on retire les separateurs de milliers
    // (espaces, espaces insecables) et les symboles monnaie, puis on convertit
    // la virgule decimale en point. Ex. "1 200,50 €" -> 1200.50.
    $caMoisRaw = (string) ($post['ca_mois'] ?? '');
    $caMoisRaw = str_replace(["\xC2\xA0", ' ', '€'], '', $caMoisRaw);
    $caMoisRaw = str_replace(',', '.', $caMoisRaw);
    $caMoisVal = filter_var($caMoisRaw, FILTER_VALIDATE_FLOAT);
    if ($caMoisVal === false || $caMoisVal < 0) {
        if (!$allowImportCompatibility) {
            $errors['ca_mois'] = 'CA mensuel invalide.';
        }
        $data['ca_mois'] = 0.00;
    } else {
        $data['ca_mois'] = (float) $caMoisVal;
    }

    $data['ca_annuel'] = round($data['ca_mois'] * 12 * 0.846, 2);

    $parsedDateEffet = parse_date_fr($post['date_effet'] ?? '');
    if (!$parsedDateEffet) {
        if ($allowImportCompatibility) {
            $parsedDateEffet = $data['date_vente'] ?: date('Y-m-d');
        } else {
            $errors['date_effet'] = 'Date d’effet invalide.';
        }
    }
    $data['date_effet'] = $parsedDateEffet ?: date('Y-m-d');
    $parsedDateInjection = parse_date_fr($post['date_injection'] ?? '');
    $data['date_injection'] = $parsedDateInjection ?: null;
    $data['produit'] = clean_str($post['produit'] ?? '');
    if ($data['produit'] === '') {
        if ($allowImportCompatibility) {
            $data['produit'] = 'Non spécifié';
        } else {
            $errors['produit'] = 'Le produit est obligatoire.';
        }
    }

    $data['compagnie'] = clean_str($post['compagnie'] ?? '');
    if ($data['compagnie'] === '') {
        if ($allowImportCompatibility) {
            $data['compagnie'] = 'Autre';
        } else {
            $errors['compagnie'] = 'La compagnie est obligatoire.';
        }
    }

    $courrier = $post['courrier'] ?? [];
    $courrier = is_array($courrier) ? array_map('clean_str', $courrier) : [];
    if (!$allowImportCompatibility) {
        $courrier = array_values(array_intersect(options_courrier(), $courrier));
    }
    $courrier = array_values(array_filter(array_unique($courrier), static fn (string $value): bool => $value !== ''));
    $data['courrier'] = json_encode(array_values(array_unique($courrier)), JSON_UNESCAPED_UNICODE);
    $data['etat_dossier'] = etat_dossier_from_courrier($courrier);

    $data['etat_contrat'] = clean_str($post['etat_contrat'] ?? 'Actif');
    if (!in_array($data['etat_contrat'], etats_contrat_valides(), true)) {
        if ($allowImportCompatibility) {
            $data['etat_contrat'] = 'Actif';
        } else {
            $errors['etat_contrat'] = 'État du contrat invalide.';
        }
    }

    $data['controle_qualite'] = clean_str($post['controle_qualite'] ?? '');
    if ($data['controle_qualite'] !== '' && !in_array($data['controle_qualite'], controles_qualite_valides(), true)) {
        if ($allowImportCompatibility) {
            $data['controle_qualite'] = null;
        } else {
            $errors['controle_qualite'] = 'Contrôle qualité invalide.';
        }
    }
    if ($data['controle_qualite'] === '') {
        $data['controle_qualite'] = null;
    }

    $data['commentaire'] = clean_str($post['commentaire'] ?? '') ?: null;

    // Le motif d'annulation n'a de sens que lorsque le contrat n'est plus
    // « Actif » : c'est l'état du CONTRAT qui porte l'annulation, pas l'état du
    // dossier (qui ne vaut que « Dossier complet / incomplet »). L'ancienne
    // condition « etat_dossier === 'Annuler' » n'était jamais vraie, si bien que
    // le motif saisi au formulaire n'était jamais enregistré.
    $data['motif_annulation'] = null;
    if (($data['etat_contrat'] ?? 'Actif') !== 'Actif') {
        $data['motif_annulation'] = clean_str($post['motif_annulation'] ?? '') ?: null;
    }

    return ['errors' => $errors, 'data' => $data];
}

/** Génère un mot de passe temporaire lisible et suffisamment robuste. */
function generate_temp_password(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $pwd = '';
    for ($i = 0; $i < 10; $i++) {
        $pwd .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $pwd . '!' . random_int(10, 99);
}

/** Vérifie la robustesse minimale d'un mot de passe choisi par l'utilisateur. */
function password_is_strong(string $password): bool
{
    return password_policy_errors($password) === [];
}

/**
 * Politique de mot de passe renforcée.
 * Retourne la liste des messages d'erreur (vide si le mot de passe est valide).
 * Règles : 12+ caractères, majuscule, minuscule, chiffre, caractère spécial,
 * mot de passe non courant.
 */
function password_policy_errors(string $password): array
{
    $errors = [];

    if (mb_strlen($password) < 12) {
        $errors[] = 'au moins 12 caractères';
    }
    if (mb_strlen($password) > 128) {
        $errors[] = 'au plus 128 caractères';
    }
    if (preg_match('/[æøåàâäéèêëîïôöùûüÿçœ]/iu', $password)) {
        $errors[] = 'uniquement les caractères ASCII (évitez accents et lettres non latines)';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'une minuscule';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'une majuscule';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'un chiffre';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'un caractère spécial (!@#$%^&*...)';
    }

    // Mots de passe trop courants ou trop proches du nom de l'utilisateur
    $lower = strtolower($password);
    $common = [
        'password', 'password1', 'password123', '12345678', '123456789',
        '1234567890', 'qwerty123', 'letmein', 'admin123', 'welcome123',
        'changeme', 'changeme1', 'adminadmin', 'iloveyou', 'monkey123',
        'azerty123', 'assurialis', 'gestion123', 'motdepasse',
    ];
    if (in_array($lower, $common, true)) {
        $errors[] = 'un mot de passe moins courant';
    }

    return array_values(array_unique($errors));
}

/**
 * Vérifie si le mot de passe soumis figure dans l'historique des derniers
 * mots de passe (anti-réutilisation). La table password_history est créée
 * par database/upgrade_v2.sql. En cas d'absence de table, retourne false
 * (aucun blocage) pour ne jamais casser l'application.
 */
function password_was_used_before(PDO $db, int $userId, string $password, int $historyCount = 5): bool
{
    try {
        $stmt = $db->prepare(
            'SELECT password_hash
               FROM password_history
              WHERE user_id = :u
           ORDER BY changed_at DESC
              LIMIT ' . max(1, min(10, $historyCount))
        );
        $stmt->execute(['u' => $userId]);
        $hashes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return false;
    }

    foreach ($hashes as $hash) {
        if (password_verify($password, (string) $hash)) {
            return true;
        }
    }
    return false;
}

/**
 * Vérifie si le mot de passe d'un utilisateur doit être considéré comme
 * expiré (âge maximal réglable via la colonne last_password_change).
 * Retourne false si la colonne n'existe pas encore (compatibilité).
 */
function password_is_expired(PDO $db, int $userId, int $maxAgeDays = 90): bool
{
    try {
        $stmt = $db->prepare('SELECT last_password_change FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $lastChange = $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }

    if (!$lastChange) {
        return false;
    }
    $ts = strtotime((string) $lastChange);
    if ($ts === false) {
        return false;
    }
    return (time() - $ts) > $maxAgeDays * 86400;
}

/**
 * Enregistre le mot de passe courant dans l'historique et met à jour
 * la date de dernier changement. Ignore silencieusement les erreurs
 * (table d'historique absente) pour rester compatible avant mise à niveau.
 */
function record_password_change(PDO $db, int $userId, string $hash): void
{
    try {
        $stmt = $db->prepare(
            'INSERT INTO password_history (user_id, password_hash, changed_at) VALUES (:u, :h, NOW())'
        );
        $stmt->execute(['u' => $userId, 'h' => $hash]);

        $upd = $db->prepare('UPDATE users SET last_password_change = NOW() WHERE id = :id');
        $upd->execute(['id' => $userId]);
    } catch (Throwable $e) {
        // Non bloquant : l'historique est un bonus de sécurité.
    }
}

/** Enregistre une entrée d'historique pour un dossier. */
function log_dossier_history(PDO $db, int $dossierId, ?int $userId, string $action, ?string $champ = null, ?string $ancienne = null, ?string $nouvelle = null): void
{
    $stmt = $db->prepare(
        'INSERT INTO dossier_historique (dossier_id, user_id, action, champ, ancienne_valeur, nouvelle_valeur)
         VALUES (:d, :u, :a, :c, :old, :new)'
    );
    $stmt->execute([
        'd' => $dossierId, 'u' => $userId, 'a' => $action,
        'c' => $champ, 'old' => $ancienne, 'new' => $nouvelle,
    ]);
}

/** Archive un dossier et ses donnees liees avant sa suppression. */
function archive_dossier(PDO $db, int $dossierId, int $deletedBy): void
{
    $stmt = $db->prepare('SELECT * FROM dossiers WHERE id = :id');
    $stmt->execute(['id' => $dossierId]);
    $dossier = $stmt->fetch();
    if (!$dossier) {
        return;
    }

    $stmt = $db->prepare('SELECT * FROM dossier_attachments WHERE dossier_id = :id');
    $stmt->execute(['id' => $dossierId]);
    $attachments = $stmt->fetchAll();

    $stmt = $db->prepare('SELECT * FROM dossier_historique WHERE dossier_id = :id');
    $stmt->execute(['id' => $dossierId]);
    $historique = $stmt->fetchAll();

    $stmt = $db->prepare('INSERT INTO dossier_trash
        (original_dossier_id, dossier_data, attachments_data, historique_data, deleted_by)
        VALUES (:id, :dossier, :attachments, :historique, :deleted_by)');
    $stmt->execute([
        'id' => $dossierId,
        'dossier' => json_encode($dossier, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'attachments' => json_encode($attachments, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'historique' => json_encode($historique, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'deleted_by' => $deletedBy,
    ]);
}

/**
 * Dossier temporaire inscriptible pour les fichiers d'export (XLSX, CSV...).
 *
 * Pourquoi ne pas utiliser sys_get_temp_dir() seul : sur les hébergements
 * mutualisés (InfinityFree, etc.) il renvoie /home/tmp qui est HORS de la
 * directive open_basedir => tempnam() échoue et le script meurt en erreur 500.
 * On privilégie donc un dossier interne au projet (uploads/tmp), déjà non
 * servi par le web (uploads/.htaccess : Require all denied).
 *
 * @return string Chemin du dossier (sans slash final), ou '' si aucun n'est utilisable.
 */
function app_temp_dir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }

    $root = dirname(__DIR__);
    $candidates = [
        $root . '/uploads/tmp',     // dans le projet : toujours autorisé par open_basedir
        $root . '/tmp',
        sys_get_temp_dir(),         // /home/tmp sur certains hébergements : interdit
        '/tmp',                     // autorisé par open_basedir sur InfinityFree
    ];

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || $candidate === '') {
            continue;
        }
        if (!is_dir($candidate) && !@mkdir($candidate, 0775, true) && !is_dir($candidate)) {
            continue;
        }
        if (@is_writable($candidate)) {
            // Le dossier est dans le projet : on garantit qu'il n'est pas servi.
            if (strpos($candidate, $root) === 0) {
                $deny = $candidate . '/.htaccess';
                if (!is_file($deny)) {
                    @file_put_contents($deny, "Require all denied\n");
                }
            }
            return $dir = rtrim($candidate, "/\\");
        }
    }

    return $dir = '';
}

/**
 * Crée un fichier temporaire inscriptible (nom unique) et retourne son chemin.
 * Retourne '' si aucun dossier temporaire n'est disponible : l'appelant doit
 * alors afficher un message clair au lieu de laisser PHP lever une erreur 500.
 */
function app_temp_file(string $prefix = 'tmp'): string
{
    $dir = app_temp_dir();
    if ($dir === '') {
        return '';
    }

    $path = $dir . DIRECTORY_SEPARATOR . $prefix . '_' . bin2hex(random_bytes(8)) . '.tmp';

    // Écriture-test : détecte immédiatement un quota ou des droits insuffisants.
    return @file_put_contents($path, '') === 0 ? $path : '';
}
