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

    // CACHE_VERSION reste utile pour « forcer » un invalidation globally (il
    // suffit de changer sa valeur), mais il ne doit plus être utilisé seul :
    // sans le filemtime, deux deploiements successifs produisaient la meme URL
    // (?v=20260924) et les navigateurs continuaient de servir l'ancien
    // app.js / style.css depuis leur cache. On combine donc les deux.
    $absolute = __DIR__ . '/../assets/' . ltrim($relativePath, '/');
    $mtime = @filemtime($absolute);
    $stamp = defined('CACHE_VERSION')
        ? (string) CACHE_VERSION
        : ($mtime !== false ? (string) $mtime : '1');

    // Suffixe d'empreinte : change a chaque modification du fichier, donc
    // l'URL change au deploiement sans avoir a toucher a la configuration.
    $fingerprint = $mtime !== false ? substr(md5($absolute . '|' . $mtime), 0, 8) : '0';

    return $cache[$relativePath] = $stamp . '-' . $fingerprint;
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

/**
 * Prénoms reconnus pour découper un nom complet dont le prénom est collé au nom.
 * Liste volontairement courte : elle ne sert qu'à réparer les cas où le
 * séparateur a disparu (« EL KHOUDINARIMENE »), jamais à décider du découpage
 * d'un nom correctement espacé.
 */
function person_first_names(): array
{
    static $prenoms = null;
    if ($prenoms !== null) {
        return $prenoms;
    }
    $prenoms = [
        // Prénoms rencontrés dans les rapports de pointage de l'entreprise.
        'RIMENE', 'AFRA', 'WIDED', 'SAAD', 'IMEN', 'AYMEN', 'IKRAM', 'OLFA', 'KHAOULA',
        'NOUR', 'MERIAM', 'YATHREB', 'SALOUA', 'WIEM', 'ZEINAB', 'AMIRA', 'AMAL',
        'NAJEH', 'ANISSA', 'AHLEM', 'HENDA', 'AIDA', 'WAFA', 'RYM', 'NAIM', 'IBTISSEM',
        // Prénoms tunisiens courants (évite les découpages hasardeux).
        'MED', 'MOHAMED', 'AHMED', 'ALI', 'HASSEN', 'HOUCINE', 'YOUSSEF', 'MEHDI',
        'ANIS', 'AMINE', 'BILEL', 'HAMZA', 'HATEM', 'HICHEM', 'KARIM', 'KHALED',
        'MAHER', 'MAROUANE', 'NIZAR', 'RAMZI', 'SAMI', 'SKANDER', 'SOFIENE', 'TAREK',
        'WAJDI', 'ZIED', 'WALID', 'MOUADH', 'FATMA', 'FATIMA', 'KHADIJA', 'MARIEM',
        'MARYEM', 'SALMA', 'SANA', 'SONIA', 'LEILA', 'LILIA', 'NAJET', 'NAJWA',
        'RANIA', 'RANDA', 'RIM', 'SARRA', 'SARAH', 'SIRINE', 'SOUMAYA', 'YOSRA',
        'EMNA', 'ASMA', 'HOUDA', 'HAYET', 'HANEN', 'INES', 'ISRAA', 'JIHENE',
        'MARWA', 'MOUNA', 'NADIA', 'NESRINE', 'ONS', 'RAJA', 'RAOUIA', 'SABRINE',
        'SALIMA', 'SAMIA', 'SOUAD', 'CHIRAZ', 'DORSAF', 'FERIEL', 'GHADA', 'HAIFA',
        'KHAWLA', 'LOBNA', 'MAHA', 'MANEL', 'MERIEM', 'NADHIRA', 'NAWEL', 'NOURHENE',
        'RIHEM', 'SAFA', 'SOUHA', 'THOURAYA', 'ZOHRA', 'AZIZ', 'FADWA', 'FAICAL',
        'HEDI', 'JALEL', 'MONCEF', 'NABIL', 'RIDHA',
    ];
    return $prenoms;
}

/** Vrai si $mot est un prénom connu (comparaison insensible à la casse). */
function is_known_first_name(string $mot): bool
{
    $mot = mb_strtoupper(trim($mot), 'UTF-8');
    return $mot !== '' && in_array($mot, person_first_names(), true);
}

/** Normalise les espaces d'un nom (insécables, tabulations, sauts de ligne). */
function normaliser_nom(string $value): string
{
    $value = str_replace(["\xC2\xA0", "\xE2\x80\xAF", "\t", "\r", "\n"], ' ', $value);
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
}

/**
 * Découpe un nom complet en « nom » et « prénom ».
 *
 * Les rapports de pointage importés (.xlsx) mélangent deux présentations :
 *  - deux colonnes distinctes « Nom » et « Prénom » ;
 *  - une seule colonne « Nom » contenant le nom ET le prénom.
 *
 * Cette fonction ramène les deux cas à un couple (nom, prénom) exploitable,
 * pour que les deux informations ne soient plus écrasées dans une seule
 * colonne. Elle est volontairement prudente : quand elle ne sait pas, elle
 * laisse « prénom » vide plutôt que d'inventer un découpage — c'est l'appelant
 * qui affiche alors « Non renseigné ».
 *
 * Règles, dans l'ordre :
 *  1. séparateur explicite (virgule, point-virgule, barre oblique, tabulation,
 *     saut de ligne, double espace) : « NOM, Prénom » ;
 *  2. plusieurs mots : le dernier mot est le prénom, tout le reste est le nom
 *     (« BEN ALI OLFA » → nom « BEN ALI », prénom « OLFA »). C'est déjà la
 *     convention des fiches correctement saisies ;
 *  3. prénom collé : si le dernier mot se termine par un prénom connu, on
 *     découpe au bon endroit (« EL KHOUDINARIMENE » → nom « EL KHOUDINA »,
 *     prénom « RIMENE »).
 *
 * @param array $prenomsConnus Prénoms supplémentaires (ex. lus en base).
 * @return array{nom:string,prenom:string}
 */
function split_person_name(string $value, array $prenomsConnus = []): array
{
    $nom = normaliser_nom($value);
    if ($nom === '') {
        return ['nom' => '', 'prenom' => ''];
    }

    if ($prenomsConnus) {
        $connus = static function (string $mot) use ($prenomsConnus): bool {
            $mot = mb_strtoupper($mot, 'UTF-8');
            foreach ($prenomsConnus as $p) {
                if ($mot === mb_strtoupper(trim((string) $p), 'UTF-8')) {
                    return true;
                }
            }
            return false;
        };
    } else {
        $connus = static fn(string $mot): bool => is_known_first_name($mot);
    }

    // 1) Séparateur explicite : « NOM, Prénom », « NOM ; Prénom »… Les espaces
    //    (y compris doubles, tabulations et sauts de ligne) ont déjà été
    //    ramenés à un espace unique par normaliser_nom() : ils sont donc traités
    //    par la règle 2/3 ci-dessous, pas ici.
    foreach ([',', ';', '|', '/'] as $sep) {
        if (strpos($nom, $sep) !== false) {
            $parties = array_values(array_filter(
                array_map(static fn($p) => trim($p), explode($sep, $nom)),
                static fn($p) => $p !== ''
            ));
            if (count($parties) >= 2) {
                return ['nom' => $parties[0], 'prenom' => $parties[count($parties) - 1]];
            }
        }
    }

    $mots = preg_split('/\s+/u', $nom, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    // 2) Un seul mot : tenter de réparer un prénom collé.
    if (count($mots) === 1) {
        return decouper_prenom_colle($mots[0], $connus) ?? ['nom' => $mots[0], 'prenom' => ''];
    }

    // 3) Plusieurs mots : le dernier est le prénom, sauf s'il est collé.
    $dernier = $mots[count($mots) - 1];
    $tete = implode(' ', array_slice($mots, 0, -1));

    if (!$connus($dernier)) {
        $colle = decouper_prenom_colle($dernier, $connus);
        if ($colle !== null) {
            return ['nom' => $tete . ' ' . $colle['nom'], 'prenom' => $colle['prenom']];
        }
    }

    return ['nom' => $tete, 'prenom' => $dernier];
}

/**
 * Réessaie de découper « KHOUDINARIMENE » en (nom, prénom) grâce à la liste de
 * prénoms connus. Renvoie null si aucun découpage fiable n'est trouvé.
 *
 * Exigences volontairement strictes pour ne jamais abîmer un nom correct :
 * prénom d'au moins 4 lettres et nom restant d'au moins 3 lettres.
 */
function decouper_prenom_colle(string $mot, callable $estPrenom): ?array
{
    $mot = trim($mot);
    $longueur = mb_strlen($mot, 'UTF-8');
    if ($longueur < 7) {
        return null;
    }
    $maj = mb_strtoupper($mot, 'UTF-8');
    // Du plus long au plus court : « RIMENE » avant « IMENE ».
    for ($taille = $longueur - 3; $taille >= 4; $taille--) {
        $debut = $longueur - $taille;
        $suffixe = mb_substr($maj, $debut, null, 'UTF-8');
        if (!$estPrenom($suffixe)) {
            continue;
        }
        if ($debut < 3) {
            continue;
        }
        return ['nom' => mb_substr($mot, 0, $debut, 'UTF-8'), 'prenom' => mb_substr($mot, $debut, null, 'UTF-8')];
    }
    return null;
}

/** Affiche une partie de nom : « Non renseigné » si elle est vide. */
function nom_ou_non_renseigne(?string $value): string
{
    $value = trim((string) $value);
    return $value === '' ? 'Non renseigné' : $value;
}

/**
 * Ajoute les colonnes « nom » et « prenom » à la table `employees` si elles
 * manquent, puis découpe une seule fois les noms déjà enregistrés.
 *
 * Historiquement la table ne contenait que `full_name` : le nom ET le prénom
 * étaient donc forcés de cohabiter dans une seule colonne, et un rapport de
 * pointage livrant « NOM Prénom » dans une unique colonne ne pouvait pas être
 * séparé (d'où des fiches comme « EL KHOUDINARIMENE »).
 *
 * La migration est idempotente et non destructive :
 *  - les colonnes ne sont ajoutées que si elles sont absentes ;
 *  - un nom déjà renseigné à la main n'est jamais écrasé ;
 *  - `full_name` n'est réécrit que si le découpage a réussi et a changé
 *    quelque chose (noms collés), sans perdre un seul caractère ;
 *  - elle ne s'exécute qu'une fois, grâce à un drapeau dans `settings`.
 *
 * @return bool true si les colonnes sont disponibles et utilisables.
 */
function rh_ensure_name_columns(?PDO $db = null): bool
{
    static $resultat = null;
    if ($resultat !== null) {
        return $resultat;
    }
    if (!$db instanceof PDO) {
        return $resultat = false;
    }

    $drapeau = app_setting($db, 'rh_name_columns', '');
    if ($drapeau === '1') {
        return $resultat = true;
    }
    if ($drapeau === '0') {
        // Tentative déjà faite et refusée par l'hébergement : on n'insiste pas
        // à chaque page, l'application reste en mode `full_name` seul.
        return $resultat = false;
    }

    try {
        $colonnes = [];
        foreach ($db->query('SHOW COLUMNS FROM `employees`') as $colonne) {
            $colonnes[strtolower((string) $colonne['Field'])] = true;
        }
        if (!isset($colonnes['nom'])) {
            $db->exec("ALTER TABLE `employees` ADD COLUMN `nom` VARCHAR(150) NOT NULL DEFAULT '' AFTER `full_name`");
        }
        if (!isset($colonnes['prenom'])) {
            $db->exec("ALTER TABLE `employees` ADD COLUMN `prenom` VARCHAR(150) NOT NULL DEFAULT '' AFTER `nom`");
        }

        // Remplissage initial : on découpe les noms déjà en base.
        $lignes = $db->query('SELECT id, full_name, nom, prenom FROM employees')->fetchAll(PDO::FETCH_ASSOC);
        $maj = $db->prepare('UPDATE employees SET nom = :nom, prenom = :prenom, full_name = :full WHERE id = :id');
        foreach ($lignes as $ligne) {
            $full = normaliser_nom((string) $ligne['full_name']);
            // Une valeur déjà saisie (ou déjà migrée) est respectée.
            if (trim((string) $ligne['nom']) !== '' || trim((string) $ligne['prenom']) !== '') {
                continue;
            }
            $decoupe = split_person_name($full);
            $nom = $decoupe['nom'] !== '' ? $decoupe['nom'] : ($full !== '' ? $full : 'Non renseigné');
            $prenom = $decoupe['prenom'] !== '' ? $decoupe['prenom'] : 'Non renseigné';
            $nouveauFull = normaliser_nom($nom . ' ' . $prenom);
            $maj->execute([
                'nom' => $nom,
                'prenom' => $prenom,
                'full' => $nouveauFull !== '' ? $nouveauFull : $full,
                'id' => (int) $ligne['id'],
            ]);
        }

        save_app_setting($db, 'rh_name_columns', '1');
        $resultat = true;
    } catch (Throwable $e) {
        // Un hébergement sans droit ALTER TABLE ne doit jamais casser la page :
        // on mémorise l'échec et l'application continue avec `full_name` seul.
        error_log('rh_ensure_name_columns: ' . $e->getMessage());
        try {
            save_app_setting($db, 'rh_name_columns', '0');
        } catch (Throwable $ignored) {
            // Rien de plus à faire : le mode dégradé reste celui par défaut.
        }
        $resultat = false;
    }
    return $resultat;
}

/**
 * Recalcule les bulletins de salaire d'un employé après la modification de sa
 * fiche dans rh_employees.php (« Régime horaire », poste).
 *
 * Le régime horaire de la fiche est recopié dans `hourly_rate_used` de chaque
 * bulletin, puis le montant est recalculé avec la règle UNIQUE de
 * l'application : poste « Responsable » => jours payés × taux, sinon heures
 * payées × taux (c'est exactement le calcul de l'import du rapport de pointage
 * et de la saisie manuelle). Sans cet appel, modifier le régime horaire
 * laissait les bulletins déjà enregistrés bloqués sur l'ANCIEN taux : le
 * salaire affiché restait faux jusqu'à un nouveau réimport du fichier.
 *
 * Précautions :
 *  - seuls les bulletins de CET employé sont concernés (`employee_id`) ;
 *  - seules les lignes dont le taux ou le montant changent réellement sont
 *    écrites : les autres gardent leur `updated_at` intact ;
 *  - `total_hours` et toutes les colonnes de pointage (jours/heures payés,
 *    absences, retards, heures supplémentaires, départs) ne sont JAMAIS
 *    modifiées : elles ne dépendent pas du régime horaire ;
 *  - aucune donnée utilisateur n'entre dans la requête : la colonne de base est
 *    choisie dans une liste fermée (noir/blanc) selon le poste.
 *
 * L'atomicité avec la modification de la fiche est laissée à l'appelant : si
 * une transaction est déjà ouverte, la mise à jour y participe (c'est le cas de
 * rh_employee_action.php).
 *
 * @param float|null  $rate     Nouveau régime horaire ; null => celui de la fiche.
 * @param string|null $position Poste de l'employé ; null => celui de la fiche.
 *
 * @return int Nombre de bulletins réellement recalculés (0 si rien ne change).
 */
function rh_recalculer_salaires_employe(PDO $db, int $employeeId, ?float $rate = null, ?string $position = null): int
{
    if ($employeeId <= 0) {
        return 0;
    }

    // Le taux et le poste sont relus seulement si l'appelant ne les fournit pas :
    // la fiche vient d'être enregistrée, donc la fiche en base fait foi.
    if ($rate === null || $position === null) {
        $stmt = $db->prepare('SELECT hourly_rate, position FROM employees WHERE id=:id');
        $stmt->execute(['id' => $employeeId]);
        $employe = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$employe) {
            return 0;
        }
        if ($rate === null) {
            $rate = (float) $employe['hourly_rate'];
        }
        if ($position === null) {
            $position = (string) $employe['position'];
        }
    }

    $taux = round((float) $rate, 2);
    // Liste fermée : le nom de colonne ne peut pas être passé en paramètre, il
    // est donc choisi ici et jamais construit à partir d'une saisie.
    $colonneBase = ($position === 'Responsable') ? 'paid_days' : 'paid_hours';

    // Requêtes préparées natives côté MySQL (ATTR_EMULATE_PREPARES = false) :
    // un même placeholder ne peut pas être réutilisé => quatre paramètres
    // distincts pour la même valeur de taux.
    $sql = 'UPDATE salary_records
               SET hourly_rate_used = :taux_ecrit,
                   calculated_salary = ROUND(' . $colonneBase . ' * :taux_calcul, 2)
             WHERE employee_id = :employe
               AND (hourly_rate_used <> :taux_avant
                    OR calculated_salary <> ROUND(' . $colonneBase . ' * :taux_calcul_avant, 2))';
    $stmt = $db->prepare($sql);
    $stmt->execute([
        'taux_ecrit' => $taux,
        'taux_calcul' => $taux,
        'employe' => $employeeId,
        'taux_avant' => $taux,
        'taux_calcul_avant' => $taux,
    ]);

    // rowCount() = lignes réellement modifiées (MYSQL_ATTR_FOUND_ROWS n'est pas
    // activé) : les bulletins déjà justes ne sont pas comptés.
    return $stmt->rowCount();
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

/**
 * Formate un nombre d'heures décimal en HH:MM (ex : 3.5 -> 03:30).
 * Déplacée ici depuis rh_salaries.php afin que rh_salaries.php ET
 * rh_history.php affichent exactement la même valeur pour une même donnée.
 */
function format_heure($decimalHours): string
{
    $hours = (int) $decimalHours;
    $minutes = (int) round(((float) $decimalHours - $hours) * 60);
    if ($minutes === 60) {
        $hours++;
        $minutes = 0;
    }
    return str_pad((string) $hours, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) $minutes, 2, '0', STR_PAD_LEFT);
}

function app_setting(PDO $db, string $key, string $default = ''): string
{
    // Cette lecture est faite sur CHAQUE requête (notamment via csrf.php pour
    // la taille maximale d'upload). Elle est donc servie 60 s depuis le cache
    // disque, ce qui évite une requête MySQL à chaque affichage. La valeur est
    // invalidée explicitement par save_app_setting() et par le vidage manuel.
    return (string) app_cache_get(
        'setting:' . $key,
        static function () use ($db, $key, $default): string {
            try {
                $stmt = $db->prepare('SELECT setting_value FROM settings WHERE setting_key = :key LIMIT 1');
                $stmt->execute(['key' => $key]);
                $value = $stmt->fetchColumn();
                return $value === false ? $default : (string) $value;
            } catch (Throwable $e) {
                return $default;
            }
        },
        60
    );
}

function save_app_setting(PDO $db, string $key, string $value): void
{
    $stmt = $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->execute(['key' => $key, 'value' => $value]);
    // Le cache doit refléter immédiatement la nouvelle valeur.
    app_cache_forget('setting:' . $key);
}

function max_import_size_bytes(PDO $db): int
{
    // Mémorisé pour la requête courante : la fonction est appelée plusieurs
    // fois par page (csrf.php, formulaires d'import et de pièce jointe).
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }

    // Le réglage admin « Taille maximale des fichiers » est la source de vérité.
    // La constante reste uniquement le fallback lors d'une installation sans
    // table settings ou avant sa création.
    $defaultMb = defined('IMPORT_MAX_SIZE')
        ? max(1, (int) ceil(IMPORT_MAX_SIZE / 1024 / 1024))
        : 30;
    $megabytes = (int) app_setting($db, 'max_upload_mb', (string) $defaultMb);
    $megabytes = max(1, min(100, $megabytes));

    // L'hébergement peut imposer une limite plus basse que le réglage admin.
    // On ne peut pas la augmenter depuis PHP : onReturned donc la limite
    // réellement disponible afin d'éviter une erreur HTTP 413.
    $phpLimits = [];
    foreach (['upload_max_filesize', 'post_max_size'] as $iniName) {
        $iniValue = trim((string) ini_get($iniName));
        if ($iniValue !== '' && (int) $iniValue > 0) {
            $multiplier = strtoupper(substr($iniValue, -1)) === 'G' ? 1024 * 1024 * 1024
                : (strtoupper(substr($iniValue, -1)) === 'M' ? 1024 * 1024 : 1);
            $phpLimits[] = (int) $iniValue * $multiplier;
        }
    }
    $serverLimitBytes = $phpLimits ? min($phpLimits) : null;
    return $memo = ($serverLimitBytes === null
        ? $megabytes * 1024 * 1024
        : min($megabytes * 1024 * 1024, $serverLimitBytes));
}

/**
 * Fuseau horaire d'AFFICHAGE des horodatages (heure de Tunisie, UTC+1).
 *
 * Source unique, alignée sur APP_TIMEZONE et sur la session MySQL
 * (voir Database::appTimezone() et le SET SESSION time_zone de database.php).
 * Comme les trois fuseaux concordent, une valeur DATETIME stockée par
 * NOW()/CURRENT_TIMESTAMP s'affiche telle quelle, sans double conversion.
 */
function app_display_timezone(): DateTimeZone
{
    static $tz = null;
    if ($tz === null) {
        $tz = new DateTimeZone(defined('APP_TIMEZONE') ? (string) APP_TIMEZONE : 'Africa/Tunis');
    }
    return $tz;
}

/**
 * Formate un DATETIME SQL ('Y-m-d H:i:s') en heure de Tunis.
 *
 * La valeur est déjà écrite dans le fuseau applicatif (session MySQL réglée
 * sur Africa/Tunis) : on se contente donc de la lire dans ce fuseau et de la
 * formater. Aucune conversion n'est nécessaire — c'est ce qui évite les
 * doubles décalages observés auparavant.
 *
 * @param string|null $sqlDateTime Valeur MySQL
 * @param string      $format       Masque date() (défaut : d/m/Y H:i)
 */
function format_datetime_tn(?string $sqlDateTime, string $format = 'd/m/Y H:i'): string
{
    $value = trim((string) $sqlDateTime);
    if ($value === '' || str_starts_with($value, '0000-00-00')) {
        return '—';
    }

    try {
        $tz = app_display_timezone();
        $dt = new DateTimeImmutable($value, $tz);
        return $dt->format($format);
    } catch (Throwable $e) {
        $ts = strtotime($value);
        return $ts ? date($format, $ts) : '—';
    }
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

/**
 * Types de pièces jointes affichables directement par le navigateur.
 *
 * Renvoie true pour les contenus que l'on peut servir avec
 * « Content-Disposition: inline » sans risque et sans dépendance externe.
 * Les autres types (Word, Excel…) ne sont pas listés : le navigateur ne
 * sait pas les afficher, la page doit donc proposer un téléchargement.
 */
function attachment_is_previewable(?string $mime): bool
{
    return attachment_preview_kind($mime) !== 'unsupported';
}

/**
 * Détermine la façon d'afficher une pièce jointe dans la modale d'aperçu.
 *
 * @return string 'image' | 'pdf' | 'audio' | 'unsupported'
 */
function attachment_preview_kind(?string $mime): string
{
    $mime = strtolower(trim((string) $mime));

    if (str_starts_with($mime, 'image/')) {
        return 'image';
    }
    if ($mime === 'application/pdf') {
        return 'pdf';
    }
    if (str_starts_with($mime, 'audio/')) {
        return 'audio';
    }
    return 'unsupported';
}

/**
 * Libellé lisible du type MIME, utilisé dans l'aperçu.
 */
function attachment_mime_label(?string $mime): string
{
    $mime = strtolower(trim((string) $mime));
    $map = [
        'application/pdf' => 'Document PDF',
        'image/jpeg' => 'Image JPEG',
        'image/png' => 'Image PNG',
        'image/gif' => 'Image GIF',
        'image/webp' => 'Image WebP',
        'application/msword' => 'Document Word',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'Document Word',
        'application/vnd.ms-excel' => 'Tableur Excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'Tableur Excel',
        'audio/mpeg' => 'Audio MP3',
        'audio/wav' => 'Audio WAV',
        'audio/x-wav' => 'Audio WAV',
        'audio/ogg' => 'Audio OGG',
        'audio/mp4' => 'Audio M4A',
        'audio/aac' => 'Audio AAC',
        'audio/flac' => 'Audio FLAC',
        'audio/webm' => 'Audio WEBM',
        'audio/opus' => 'Audio OPUS',
        'audio/x-ms-wma' => 'Audio WMA',
    ];

    if (isset($map[$mime])) {
        return $map[$mime];
    }
    if ($mime === '') {
        return 'Type inconnu';
    }
    return $mime;
}

/** Liste blanche des valeurs autorisées pour "Etat du dossier" (règle métier). */
function etats_dossier_valides(): array
{
    return ['Dossier complet', 'Dossier incomplet'];
}

/**
 * Valeurs canoniques de la colonne « Courrier ».
 * Deux libellés ont été renommés : « Devoir de conseil » -> « Attestation de
 * droit » et « Consentement » -> « Devoir de conseil et consentement ».
 * Les anciens libellés sont conservés dans courrier_legacy_alias() pour que les
 * dossiers déjà enregistrés en base (et les anciens fichiers d'import) restent
 * lus correctement.
 */
function options_courrier(): array
{
    return ['Résiliation', 'RIB', 'Attestation de droit', 'Devoir de conseil et consentement'];
}

/**
 * Anciens libellés de courrier -> libellé canonique actuel.
 * Utilisé à la lecture (courrier_values) et à l'import pour migrer sans perte.
 *
 * ORDRE IMPORTANT : les remplacements s'appliquent par correspondance EXACTE
 * (voir courrier_canonical), jamais par recherche de sous-chaîne, car
 * « Devoir de conseil » est un préfixe de « Devoir de conseil et
 * consentement » : un remplacement naïf corromprait le libellé le plus long
 * en « Attestation de droit et consentement ».
 *
 * @return array<string,string>
 */
function courrier_legacy_alias(): array
{
    return [
        'Devoir de conseil' => 'Attestation de droit',
        'Consentement'      => 'Devoir de conseil et consentement',
    ];
}

/** Remplace un libellé de courrier obsolète par son équivalent canonique. */
function courrier_canonical(string $value): string
{
    return courrier_legacy_alias()[$value] ?? $value;
}

function origines_valides(PDO $db = null, bool $refresh = false): array
{
    static $cached = null;
    $cle = 'origines';

    if ($refresh) {
        // Écriture ou suppression d'une origine : on invalide le cache disque
        // pour que la nouvelle liste soit visible immédiatement.
        app_cache_forget($cle);
        $cached = null;
    }

    if ($cached === null) {
        $default = ['Lead', 'Fiche perso', 'MMC 12', 'MMC 25'];
        if (!$db) {
            return $cached = $default;
        }
        // Liste stable et non sensible : 5 minutes de cache suffisent et
        // évitent une requête sur chaque affichage du formulaire de dossier.
        $cached = (array) app_cache_get(
            $cle,
            static function () use ($db, $default): array {
                try {
                    $stmt = $db->query('SELECT nom FROM origines ORDER BY nom');
                    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    return $rows ?: $default;
                } catch (Throwable $e) {
                    return $default;
                }
            },
            300
        );
    }

    return $cached;
}

function etats_contrat_valides(): array
{
    return ['Actif', 'Renonciation', 'Résiliation infra-annuelle', 'Résiliation à échéance', 'Radié pour non-paiement', 'Sans effet qualite', 'Sans effet mutuelle', 'CSS'];
}

function controles_qualite_valides(): array
{
    return ['OK', 'Moyen', 'KO'];
}

/**
 * Types de résiliation (nouveau champ « Type de résiliation » du formulaire).
 * Valeurs imposées par le métier : toute autre valeur est rejetée à la
 * validation et ramenée à NULL.
 */
function types_resiliation_valides(): array
{
    return [
        'Résiliation infra-annuelle',
        'Résiliation à échéance',
        'Résiliation pour hausse tarifaire',
        'Autres',
    ];
}

function courrier_values(?string $json): array
{
    $values = json_decode((string) $json, true);
    if (!is_array($values)) {
        return [];
    }
    // Les libellés historiques sont traduits vers leur équivalent canonique :
    // sans cela, un dossier enregistré avant le renommage perdrait cette case
    // (elle ne ferait plus partie de la liste blanche) et repasserait
    // automatiquement à « Dossier incomplet ».
    $values = array_map(
        static fn($value) => courrier_canonical(clean_str((string) $value)),
        $values
    );
    return array_values(array_intersect(options_courrier(), $values));
}

function etat_dossier_from_courrier(array $courrier): string
{
    return count(array_unique($courrier)) === count(options_courrier())
        ? 'Dossier complet'
        : 'Dossier incomplet';
}

/**
 * Courriers MANQUANTS d'un dossier (options non cochées).
 * Ex. dossier avec Résiliation + RIB cochés => ['Attestation de droit',
 * 'Devoir de conseil et consentement']. Sert à préparer l'e-mail client
 * « Courriers manquants » (popup sur dossier_view.php).
 */
function courrier_manquants(?string $json): array
{
    $presents = courrier_values($json);
    return array_values(array_diff(options_courrier(), $presents));
}

/** Nom affichable d'un assuré : civilité + prénom + nom. */
function client_display_name(array $dossier): string
{
    $parts = array_filter([
        trim((string) ($dossier['civilite'] ?? '')),
        trim((string) ($dossier['prenom'] ?? '')),
        trim((string) ($dossier['nom'] ?? '')),
    ]);
    return $parts ? implode(' ', $parts) : 'Madame, Monsieur';
}

/**
 * Objet pré-rempli de l'e-mail « Courriers manquants ».
 * Ex. : « Votre dossier — pièces manquantes : RIB, Résiliation ».
 */
function client_mail_subject_missing(array $manquants): string
{
    if (!$manquants) {
        return 'Votre dossier est complet';
    }
    return 'Votre dossier — pièce(s) manquante(s) : ' . implode(', ', $manquants);
}

/**
 * Corps pré-rempli de l'e-mail « Courriers manquants » : salue le client,
 * liste UNIQUEMENT les pièces non cochées et explique que leur réception
 * rendra le dossier complet. S'il ne manque rien, message de confirmation.
 */
function client_mail_body_missing(array $dossier, array $manquants): string
{
    $nom = client_display_name($dossier);
    $lignes = [];
    $lignes[] = 'Bonjour ' . $nom . ',';
    $lignes[] = '';
    if (!$manquants) {
        $lignes[] = 'Bonne nouvelle : votre dossier est désormais complet.';
        $lignes[] = "Nous vous remercions pour l\u{2019}envoi de l\u{2019}ensemble des pièces demandées.";
        $lignes[] = "Aucune action supplémentaire n\u{2019}est requise de votre part.";
    } else {
        $lignes[] = 'Dans le cadre du traitement de votre dossier, il manque encore '
            . (count($manquants) > 1 ? 'les pièces suivantes' : 'la pièce suivante')
            . ' pour que votre dossier soit complet :';
        $lignes[] = '';
        foreach ($manquants as $piece) {
            $lignes[] = '• ' . $piece;
        }
        $lignes[] = '';
        $lignes[] = 'Merci de nous transmettre '
            . (count($manquants) > 1 ? 'ces documents' : 'ce document')
            . ' dès que possible afin de finaliser votre dossier.';
        $lignes[] = 'Vous pouvez simplement répondre à cet e-mail en joignant '
            . (count($manquants) > 1 ? 'les pièces demandées' : 'la pièce demandée') . '.';
    }
    $lignes[] = '';
    $lignes[] = 'Cordialement,';
    $vendeur = trim((string) ($dossier['vendeur_nom'] ?? ''));
    $lignes[] = $vendeur !== '' ? $vendeur : 'Le service dossiers';
    return implode("\n", $lignes);
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
        'mutuelle_a_resilier' => 'Mutuelle à résilier', 'date_resiliation' => 'Date de résiliation',
        'type_resiliation' => 'Type de résiliation',
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

    // Téléphone 1 (telfix) est le champ OBLIGATOIRE, Téléphone 2 (portable)
    // reste facultatif : il est stocké a NULL lorsqu'il est laisse vide.
    // Le nettoyage conserve les espaces internes (« 06 12 34 56 78 ») mais une
    // saisie sans aucun chiffre (vide, espaces, « +. ») est consideree vide.
    $telfix = preg_replace('/[^0-9+\s.]/', '', (string) ($post['telfix'] ?? ''));
    $telfix = trim((string) $telfix);
    if ($telfix === '' || !preg_match('/\d/', $telfix)) {
        $data['telfix'] = null;
        if (!$allowImportCompatibility) {
            $errors['telfix'] = 'Le téléphone 1 est obligatoire.';
        }
    } else {
        $data['telfix'] = $telfix;
    }

    // Téléphone 2 : FACULTATIF. Le nettoyage ci-dessous conserve les espaces
    // (ils font partie des formats « 06 12 34 56 78 »), ce qui posait un
    // problème : une saisie composée uniquement d'espaces («   », ou un
    // simple copier/coller d'un champ vide) était considérée comme REMPLIE.
    // stockée telle quelle, elle occupait la valeur ' ' sous la contrainte
    // UNIQUE uq_dossiers_portable : le dossier suivant recevait alors
    // SQLSTATE 23000 et l'utilisateur voyait « Ce numéro de téléphone 2
    // existe déjà pour un autre dossier » alors qu'il venait de le laisser vide.
    // On borne donc le contrôle à la présence de CHIFFRES : sans digit, le
    // champ est considéré vide et enregistré a NULL (MySQL autorise plusieurs
    // NULL sous une clé UNIQUE, donc aucun conflit possible).
    $portable = preg_replace('/[^0-9+\s.]/', '', (string) ($post['portable'] ?? ''));
    $portable = trim((string) $portable);
    // Un numero doit contenir au moins un CHIFFRE. Si la saisie n'en contient
    // aucun («   », tabulation, « +. »), le champ est considere vide et sera
    // stocke a NULL. Les espaces INTERNES sont conserves tels quels : ils font
    // partie des formats « 06 12 34 56 78 ».
    if (!preg_match('/\d/', $portable)) {
        $portable = '';
    }
    $data['portable'] = $portable === '' ? null : $portable;
    if ($portable !== '' && !$allowImportCompatibility) {
        // Règle métier reprise du classeur : le numéro de téléphone 2, quand il
        // est renseigné, doit être unique (contrainte uq_dossiers_portable).
        // Le contrôle ne porte QUE sur une valeur réellement remplie : une
        // valeur vide n'est jamais comparée aux dossiers existants.
        $sql = 'SELECT id FROM dossiers WHERE portable = :p';
        $params = ['p' => $data['portable']];
        if ($excludeId) {
            $sql .= ' AND id != :id';
            $params['id'] = $excludeId;
        }
        $chk = $db->prepare($sql);
        $chk->execute($params);
        if ($chk->fetch()) {
            $errors['portable'] = 'Ce numéro de téléphone 2 existe déjà pour un autre dossier.';
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

    $data['ca_annuel'] = round($data['ca_mois'] * 12, 2);

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
    // Les libellés historiques sont ramenés au libellé canonique avant filtrage :
    // un ancien fichier ou une valeur déjà en base ne doit pas être écarté.
    $courrier = array_map('courrier_canonical', $courrier);
    $courrier = array_values(array_unique($courrier));
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

    // -----------------------------------------------------------------
    // Nouveaux champs « Mutuelle à résilier », « Date de résiliation »
    // et « Type de résiliation ». Les trois sont FACULTATIFS (un dossier
    // actif n'a rien à renseigner ici).
    //   * mutuelle_a_resilier : texte libre, 255 caractères max ;
    //   * date_resiliation    : date SQL valide (parse_date_fr) ;
    //   * type_resiliation     : valeur exacte de types_resiliation_valides()
    //     (liste déroulante), sinon erreur affichée sous le champ.
    // -----------------------------------------------------------------
    $data['mutuelle_a_resilier'] = clean_str($post['mutuelle_a_resilier'] ?? '');
    if (mb_strlen($data['mutuelle_a_resilier']) > 255) {
        if ($allowImportCompatibility) {
            $data['mutuelle_a_resilier'] = mb_substr($data['mutuelle_a_resilier'], 0, 255);
        } else {
            $errors['mutuelle_a_resilier'] = 'Mutuelle à résilier : 255 caractères maximum.';
        }
    }
    $data['mutuelle_a_resilier'] = $data['mutuelle_a_resilier'] === '' ? null : $data['mutuelle_a_resilier'];

    $dateResiliationRaw = trim((string) ($post['date_resiliation'] ?? ''));
    $parsedDateResiliation = $dateResiliationRaw === '' ? null : parse_date_fr($dateResiliationRaw);
    if ($dateResiliationRaw !== '' && !$parsedDateResiliation) {
        if (!$allowImportCompatibility) {
            $errors['date_resiliation'] = 'Date de résiliation invalide.';
        }
    }
    $data['date_resiliation'] = $parsedDateResiliation;

    $data['type_resiliation'] = clean_str($post['type_resiliation'] ?? '');
    if ($data['type_resiliation'] !== '' && !in_array($data['type_resiliation'], types_resiliation_valides(), true)) {
        if ($allowImportCompatibility) {
            $data['type_resiliation'] = null;
        } else {
            $errors['type_resiliation'] = 'Type de résiliation invalide.';
        }
    }
    if ($data['type_resiliation'] === '') {
        $data['type_resiliation'] = null;
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

// ---------------------------------------------------------------------------
// CACHE APPLICATIF SUR DISQUE
// ---------------------------------------------------------------------------
// Certains hébergeurs mutualisés (byethost22, InfinityFree…) n'exposent pas
// opcache : PHP recompile alors chaque fichier à chaque requête et chaque
// réglage est relu en base. Ce petit cache sur disque évite ces lectures
// répétées et rend le bouton « Vider le cache » réellement utile.
//
// Volontairement « best effort » : si le dossier n'est pas inscriptible
// (quota, droits, open_basedir), chaque fonction retombe silencieusement sur
// son comportement habituel. Aucune donnée sensible n'y est écrite : uniquement
// des valeurs déjà exposées publiquement dans l'interface.

/** Dossier de cache, créé à la demande. '' si aucun emplacement n'est inscriptible. */
function app_cache_dir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }

    if (defined('CACHE_DIR') && is_string(CACHE_DIR) && CACHE_DIR !== '') {
        $dir = rtrim(CACHE_DIR, '/\\');
    } else {
        // Hors racine web : aucune exposition via HTTP, et nettoyage possible
        // par l'hébergeur. Le nom est unique par projet (empreinte du chemin).
        $jeton = substr(sha1(str_replace('\\', '/', dirname(__DIR__))), 0, 12);
        $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'gestion_dossiers_' . $jeton;
    }

    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        $dir = '';
    } elseif (!is_writable($dir)) {
        $dir = '';
    }

    return $dir;
}

/** Chemin du fichier correspondant à une clé (jamais null : '' si pas de cache). */
function app_cache_path(string $key): string
{
    $dir = app_cache_dir();
    if ($dir === '') {
        return '';
    }
    return $dir . DIRECTORY_SEPARATOR . substr(sha1($key), 0, 32) . '.cache';
}

/**
 * Lit une entrée du cache. Renvoie $default si absente, expirée ou illisible.
 *
 * $default est une fonction de rappel : elle n'est exécutée qu'en cas d'échec
 * (c'est elle qui porte la requête SQL). Grâce à cela une entrée absente coûte
 * une requête, puis plus rien tant que le TTL n'est pas écoulé.
 *
 * @param bool $remember Enregistre la valeur calculée pour les prochains
 *                       appels. Mettre false pour une donnée qu'on ne veut pas
 *                       conserver (valeur par défaut d'un réglage absent…).
 */
function app_cache_get(string $key, callable $default, int $ttl = 300, bool $remember = true)
{
    $path = app_cache_path($key);
    if ($path === '' || $ttl <= 0) {
        return $default();
    }

    $contenu = @file_get_contents($path);
    if ($contenu !== false && $contenu !== '') {
        $enveloppe = @unserialize($contenu, ['allowed_classes' => false]);
        if (is_array($enveloppe) && isset($enveloppe['expire'], $enveloppe['valeur'])) {
            $expire = (int) $enveloppe['expire'];
            if ($expire === 0 || $expire >= time()) {
                return $enveloppe['valeur'];
            }
        }
        // Entrée absente, corrompue ou périmée : on la remplace.
        @unlink($path);
    }

    $valeur = $default();
    if ($remember) {
        app_cache_set($key, $valeur, $ttl);
    }
    return $valeur;
}

/** Écrit une entrée (écriture atomique : fichier temporaire puis rename). */
function app_cache_set(string $key, $valeur, int $ttl = 300): bool
{
    $path = app_cache_path($key);
    if ($path === '' || $ttl <= 0) {
        return false;
    }
    $enveloppe = serialize([
        'expire' => $ttl > 0 ? time() + $ttl : 0,
        'valeur' => $valeur,
    ]);
    $tmp = $path . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $enveloppe, LOCK_EX) === false) {
        return false;
    }
    return @rename($tmp, $path);
}

/** Invalide une clé précise (après une écriture en base, par exemple). */
function app_cache_forget(string $key): void
{
    $path = app_cache_path($key);
    if ($path !== '') {
        @unlink($path);
    }
}

/**
 * Vide tout le cache disque. Renvoie ['supprimes' => int, 'ko' => float,
 * 'dossier' => bool] ; 'dossier' indique si un cache existe réellement.
 */
function app_cache_clear(): array
{
    $dir = app_cache_dir();
    if ($dir === '') {
        return ['supprimes' => 0, 'ko' => 0.0, 'dossier' => false];
    }

    $supprimes = 0;
    $octets = 0;
    $fichiers = glob($dir . DIRECTORY_SEPARATOR . '*');
    if (is_array($fichiers)) {
        foreach ($fichiers as $fichier) {
            if (!is_file($fichier)) {
                continue;
            }
            $taille = (int) @filesize($fichier);
            if (@unlink($fichier)) {
                $supprimes++;
                $octets += $taille;
            }
        }
    }

    return ['supprimes' => $supprimes, 'ko' => round($octets / 1024, 1), 'dossier' => true];
}

/** État du cache disque, pour la carte « Performance » de Mon profil. */
function app_cache_state(): array
{
    $dir = app_cache_dir();
    $etat = ['actif' => $dir !== '', 'dossier' => $dir, 'entrees' => 0, 'ko' => 0.0, 'peremption' => false];
    if ($dir === '') {
        return $etat;
    }
    $fichiers = glob($dir . DIRECTORY_SEPARATOR . '*.cache');
    if (is_array($fichiers)) {
        foreach ($fichiers as $fichier) {
            if (!is_file($fichier)) {
                continue;
            }
            $contenu = @file_get_contents($fichier);
            $enveloppe = $contenu === false ? null : @unserialize($contenu, ['allowed_classes' => false]);
            if (is_array($enveloppe) && isset($enveloppe['expire'])) {
                $etat['entrees']++;
                $etat['peremption'] = $etat['peremption'] || ((int) $enveloppe['expire'] > 0 && (int) $enveloppe['expire'] < time());
            }
            $etat['ko'] += (int) @filesize($fichier);
        }
    }
    $etat['ko'] = round($etat['ko'] / 1024, 1);
    return $etat;
}

/**
 * État du cache d'opcode PHP, pour l'affichage dans « Mon profil ».
 * Renvoie un tableau normalisé, même si l'extension est absente.
 */
function app_cache_status(): array
{
    $status = [
        'available' => function_exists('opcache_get_status') && function_exists('opcache_get_configuration'),
        'enabled' => false,
        'memory_used' => 0,
        'memory_total' => 0,
        'memory_free' => 0,
        'cached_scripts' => 0,
        'max_cached' => 0,
        'hits' => 0,
        'misses' => 0,
        'hit_rate' => null,
    ];

    if (!$status['available']) {
        return $status;
    }

    $config = @opcache_get_configuration();
    $status['enabled'] = !empty($config['directives']['opcache.enable']);

    $stat = @opcache_get_status(false);
    if (!is_array($stat) || empty($stat['opcache_enabled'])) {
        return $status;
    }

    $status['memory_used'] = (int) ($stat['memory_usage']['used_memory'] ?? 0);
    $status['memory_total'] = (int) ($stat['memory_usage']['memory_limit'] ?? 0);
    $status['memory_free'] = (int) ($stat['memory_usage']['free_memory'] ?? 0);
    $status['cached_scripts'] = (int) ($stat['opcache_statistics']['num_cached_scripts'] ?? 0);
    $status['max_cached'] = (int) ($config['directives']['opcache.max_accelerated_files'] ?? 0);
    $status['hits'] = (int) ($stat['opcache_statistics']['hits'] ?? 0);
    $status['misses'] = (int) ($stat['opcache_statistics']['misses'] ?? 0);

    $total = $status['hits'] + $status['misses'];
    if ($total > 0) {
        $status['hit_rate'] = round($status['hits'] * 100 / $total, 1);
    }

    return $status;
}

/**
 * Vide le cache applicatif et renvoie un récapitulatif de ce qui a été libéré.
 *
 * L'application n'utilisait jusqu'ici aucun cache disque ; c'est précisément
 * ce qui rend le bouton « Vider le cache » utile sur un hébergement sans
 * opcache. La fonction vide donc les deux niveaux : cache d'opcode (s'il
 * existe) et cache applicatif sur disque.
 *
 * @return array{opcache:bool,scripts:int,stat:bool,files:int,cache:array}
 */
function clear_app_cache(?PDO $db = null): array
{
    $result = ['opcache' => false, 'scripts' => 0, 'stat' => false, 'files' => 0, 'cache' => ['supprimes' => 0, 'ko' => 0.0, 'dossier' => false]];

    if (function_exists('opcache_invalidate')) {
        // Vider tout le cache d'opcode, pas seulement le script courant.
        $before = app_cache_status();
        $result['scripts'] = $before['cached_scripts'];
        if (function_exists('opcache_reset')) {
            $result['opcache'] = (bool) @opcache_reset();
        }
    }

    // Cache des chemins réels (realpath_cache) et des stat() : après un
    // déploiement, des fichiers ont pu être ajoutés ou remplacés.
    clearstatcache(true);
    $result['stat'] = true;

    // Dossier de cache éventuel (CACHE_DIR) : fichiers .cache / .tmp purgés.
    if (defined('CACHE_DIR') && is_string(CACHE_DIR) && CACHE_DIR !== '' && is_dir(CACHE_DIR)) {
        $pattern = rtrim(CACHE_DIR, '/\\') . DIRECTORY_SEPARATOR . '*';
        $fichiers = glob($pattern);
        if (is_array($fichiers)) {
            foreach ($fichiers as $fichier) {
                if (is_file($fichier) && @unlink($fichier)) {
                    $result['files']++;
                }
            }
        }
    }

    // Cache applicatif sur disque : c'est le cache réellement présent sur un
    // hébergement mutualisé sans opcache.
    $result['cache'] = app_cache_clear();

    return $result;
}
