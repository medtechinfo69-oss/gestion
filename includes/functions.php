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

/** Formate une date SQL (Y-m-d) au format français (d/m/Y). */
function format_date(?string $sqlDate): string
{
    if (!$sqlDate || $sqlDate === '0000-00-00') {
        return '—';
    }
    $ts = strtotime($sqlDate);
    return $ts ? date('d/m/Y', $ts) : '—';
}

/** Convertit une date française (d/m/Y) en date SQL (Y-m-d), ou null si invalide. */
function parse_date_fr(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    // Accepte d/m/Y ou Y-m-d (champ HTML <input type="date">)
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        if (checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $value;
        }
        return null;
    }
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $value, $m)) {
        if (checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
    }
    return null;
}

/** Nettoie une chaîne (trim + suppression des caractères de contrôle). */
function clean_str(?string $value): string
{
    $value = trim((string) $value);
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
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

function origines_valides(): array
{
    return ['Lead', 'Fiche perso', 'MMC 12', 'MMC 25', 'FID+2ans'];
}

function etats_contrat_valides(): array
{
    return ['Actif', 'Renonciation', 'Résiliation infra-annuelle', 'Résiliation à échéance', 'Radié pour non-paiement', 'CSS'];
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
function validate_dossier_input(array $post, PDO $db, ?int $excludeId = null, bool $allowImportCompatibility = false): array
{
    $errors = [];
    $data = [];

    $data['vendeur_id'] = filter_var($post['vendeur_id'] ?? '', FILTER_VALIDATE_INT);
    if (!$data['vendeur_id']) {
        $errors['vendeur_id'] = 'Veuillez sélectionner un vendeur.';
    } else {
        $sellerSql = $allowImportCompatibility
            ? 'SELECT id FROM users WHERE id = :id'
            : 'SELECT id FROM users WHERE id = :id AND role = "vendeur"';
        $chk = $db->prepare($sellerSql);
        $chk->execute(['id' => $data['vendeur_id']]);
        if (!$chk->fetch()) {
            $errors['vendeur_id'] = 'Vendeur invalide.';
        }
    }

    $data['ta_origine'] = clean_str($post['ta_origine'] ?? '');
    $legacyOrigins = ['5ASSUR', '5 ASSUR', 'TRANSFERT', 'PARRAINAGE', 'FICHE PERSO'];
    $originIsValid = $allowImportCompatibility
        ? $data['ta_origine'] !== '' && mb_strlen($data['ta_origine']) <= 255
        : in_array($data['ta_origine'], origines_valides(), true);
    if (!$originIsValid && !($allowImportCompatibility && in_array($data['ta_origine'], $legacyOrigins, true))) {
        $errors['ta_origine'] = 'Origine invalide.';
    }
    $data['p_prod'] = clean_str($post['p_prod'] ?? '');

    $data['date_vente'] = parse_date_fr($post['date_vente'] ?? '');
    if (!$data['date_vente']) {
        $errors['date_vente'] = 'Date de vente invalide.';
    }

    $data['civilite'] = strtoupper(clean_str($post['civilite'] ?? ''));
    if (!in_array($data['civilite'], civilites_valides(), true)) {
        $errors['civilite'] = 'Civilité invalide.';
    }

    $data['nom'] = clean_str($post['nom'] ?? '');
    if ($data['nom'] === '' || mb_strlen($data['nom']) > 150) {
        $errors['nom'] = 'Le nom est obligatoire (150 caractères maximum).';
    }

    $data['prenom'] = clean_str($post['prenom'] ?? '');
    if ($data['prenom'] === '' || mb_strlen($data['prenom']) > 150) {
        $errors['prenom'] = 'Le prénom est obligatoire (150 caractères maximum).';
    }

    $data['mail'] = clean_str($post['mail'] ?? '');
    if ($data['mail'] !== '' && !filter_var($data['mail'], FILTER_VALIDATE_EMAIL)) {
        $errors['mail'] = 'Adresse e-mail invalide.';
    }

    $data['telfix'] = preg_replace('/[^0-9+\s.]/', '', $post['telfix'] ?? '') ?: null;

    $data['portable'] = preg_replace('/[^0-9+\s.]/', '', $post['portable'] ?? '');
    if ($data['portable'] === '') {
        $errors['portable'] = 'Le numéro de portable est obligatoire.';
    } else {
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
    ]);

    $data['date_naissance_assure'] = clean_str($post['date_naissance_assure'] ?? '') ?: null;
    $data['age_assure_principal'] = clean_str($post['age_assure_principal'] ?? '') ?: null;

    $data['adresse'] = clean_str($post['adresse'] ?? '');
    if ($data['adresse'] === '') {
        $errors['adresse'] = 'L’adresse est obligatoire.';
    }

    $data['cp'] = clean_str($post['cp'] ?? '');
    if ($data['cp'] === '' || !preg_match('/^[0-9A-Za-z\- ]{2,10}$/', $data['cp'])) {
        $errors['cp'] = 'Code postal invalide.';
    }

    $data['ville'] = clean_str($post['ville'] ?? '');
    if ($data['ville'] === '') {
        $errors['ville'] = 'La ville est obligatoire.';
    }

    $data['type_signature'] = clean_str($post['type_signature'] ?? '');
    if ($data['type_signature'] === '') {
        $errors['type_signature'] = 'Le type de signature est obligatoire.';
    }

    $data['ca_mois'] = filter_var(str_replace(',', '.', $post['ca_mois'] ?? ''), FILTER_VALIDATE_FLOAT);
    if ($data['ca_mois'] === false || $data['ca_mois'] < 0) {
        $errors['ca_mois'] = 'CA mensuel invalide.';
        $data['ca_mois'] = 0;
    }

    $coefficient = $data['ta_origine'] === 'FID+2ans' ? 0.846 * 0.5 : 0.846;
    $data['ca_annuel'] = round($data['ca_mois'] * 12 * $coefficient, 2);

    $data['date_effet'] = parse_date_fr($post['date_effet'] ?? '');
    if (!$data['date_effet']) {
        $errors['date_effet'] = 'Date d’effet invalide.';
    }

    $data['produit'] = clean_str($post['produit'] ?? '');
    if ($data['produit'] === '') {
        $errors['produit'] = 'Le produit est obligatoire.';
    }

    $data['compagnie'] = clean_str($post['compagnie'] ?? '');
    if ($data['compagnie'] === '') {
        $errors['compagnie'] = 'La compagnie est obligatoire.';
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
        $errors['etat_contrat'] = 'État du contrat invalide.';
    }

    $data['commentaire'] = clean_str($post['commentaire'] ?? '') ?: null;

    $data['motif_annulation'] = null;
    if ($data['etat_dossier'] === 'Annuler') {
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
    if (mb_strlen($password) < 10) {
        return false;
    }
    $hasLower = preg_match('/[a-z]/', $password);
    $hasUpper = preg_match('/[A-Z]/', $password);
    $hasDigit = preg_match('/[0-9]/', $password);
    return (bool) ($hasLower && $hasUpper && $hasDigit);
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
