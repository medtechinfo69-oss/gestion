<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/security_integration.php';
require_dossier_create_access();
csrf_require();

$user = current_user();
$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
$isEdit = (bool) $id;

// Garde-fou serveur : un vendeur ne peut jamais modifier un dossier existant
// (même avec can_supervise = 1). Le bouton Modifier lui est déjà masqué.
if ($isEdit && !is_admin() && !is_role_superviseur()) {
    sec_log('permission_denied', 'dossier', (string) $id, 'Vendeur attempted to edit dossier', false, 'Edit reserved to admin/superviseur');
    http_response_code(403);
    set_flash('error', 'Modification réservée aux administrateurs et superviseurs.');
    redirect('dossiers.php');
}

// Un vendeur peut CRÉER un dossier (même capacité que le superviseur, voir
// can_create_dossier()) : le dossier créé lui est automatiquement attribué
// (valeur forcée plus bas). En revanche il ne peut toujours pas MODIFIER un
// dossier existant (garde précédente, inchangée), ni supprimer/valider quoi
// que ce soit sur les autres rôles.
if (!$isEdit && !can_create_dossier()) {
    sec_log('permission_denied', 'dossier', null, 'Vendeur attempted to create dossier', false, 'Creation reserved to admin/superviseur');
    http_response_code(403);
    set_flash('error', 'Création de dossier réservée aux administrateurs et superviseurs.');
    redirect('dossiers.php');
}

$existing = null;
if ($isEdit) {
    $stmt = $db->prepare('SELECT * FROM dossiers WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        set_flash('error', 'Dossier introuvable.');
        sec_log('permission_denied', 'dossier', (string) $id, 'Attempt to edit non-existent dossier', false, 'Dossier not found');
        redirect('dossiers.php');
    }
}

if (is_role_superviseur() && $isEdit) {
    // Section « supervision » : réservée au rôle superviseur strict.
    // (Un vendeur avec can_supervise = 1 est déjà rejeté plus haut.)
    $courrierValues = $_POST['courrier'] ?? [];
    $courrierLockedValues = $_POST['courrier_locked'] ?? [];
    if (!is_array($courrierValues)) {
        $courrierValues = [];
    }
    if (is_array($courrierLockedValues)) {
        $courrierValues = array_merge($courrierValues, $courrierLockedValues);
    }
    $saveSection = clean_str($_POST['save_section'] ?? 'all');
    $existingCourrier = courrier_values($existing['courrier'] ?? '');
    $dossierCompletLocked = ($existing['date_dossier_complet'] ?? null) !== null
        || ($existing['etat_dossier'] ?? '') === 'Dossier complet';
    $courrierLocked = $dossierCompletLocked || (($existing['date_courrier_supervision'] ?? null) !== null
        && count($existingCourrier) === count(options_courrier()));
    if ($dossierCompletLocked) {
        $courrierValues = $existingCourrier;
    }
    // Même règle que dans dossier_form.php : l'état du contrat n'est verrouillé
    // que s'il a réellement quitté « Actif ». Le fait d'avoir déjà enregistré la
    // section (date_etat_contrat_supervision renseignée) ne bloque plus rien.
    $etatContratLocked = ($existing['etat_contrat'] ?? 'Actif') !== 'Actif';
    $controleQualiteLocked = ($existing['date_controle_qualite_supervision'] ?? null) !== null;
    if ($saveSection !== 'courrier' && $saveSection !== 'all') {
        $courrierValues = courrier_values($existing['courrier'] ?? '');
    }
    if ($courrierLocked) {
        $courrierValues = courrier_values($existing['courrier'] ?? '');
    }
    $courrierValues = is_array($courrierValues) ? array_map('courrier_canonical', array_map('clean_str', $courrierValues)) : [];
    $courrierValues = array_values(array_intersect(options_courrier(), $courrierValues));
    $courrier = json_encode(array_values(array_unique($courrierValues)), JSON_UNESCAPED_UNICODE);
    $etat = etat_dossier_from_courrier($courrierValues);
    $postedEtatContrat = clean_str($_POST['etat_contrat'] ?? '');
    $lockedEtatContrat = clean_str($_POST['etat_contrat_locked'] ?? '');
    $etatContratChanged = $lockedEtatContrat !== '' || ($postedEtatContrat !== '' && $postedEtatContrat !== $existing['etat_contrat']);
    $etatContrat = $etatContratChanged
        ? ($postedEtatContrat !== '' ? $postedEtatContrat : $lockedEtatContrat)
        : $existing['etat_contrat'];
    if ($etatContratLocked) {
        $etatContrat = $existing['etat_contrat'];
    }
    if ($saveSection !== 'etat_contrat' && $saveSection !== 'all') {
        $etatContrat = $existing['etat_contrat'];
    }
    $controleQualite = clean_str($_POST['controle_qualite'] ?? ($_POST['controle_qualite_locked'] ?? ($existing['controle_qualite'] ?? '')));
    if ($controleQualite !== '' && !in_array($controleQualite, controles_qualite_valides(), true)) {
        $controleQualite = $existing['controle_qualite'] ?? null;
    }
    $controleQualite = $controleQualite !== '' ? $controleQualite : null;
    if ($saveSection !== 'controle_qualite' && $saveSection !== 'all') {
        $controleQualite = $existing['controle_qualite'] ?? null;
    }
    $courrierIsComplete = count(array_unique($courrierValues)) === count(options_courrier());
    $dateCourrierSupervision = !$dossierCompletLocked && $saveSection === 'courrier' && $courrierIsComplete
        ? ($existing['date_courrier_supervision'] ?? date('Y-m-d'))
        : ($saveSection === 'courrier' ? null : ($existing['date_courrier_supervision'] ?? null));
    $dateEtatContratSupervision = $saveSection === 'etat_contrat'
        ? ($existing['date_etat_contrat_supervision'] ?? date('Y-m-d'))
        : ($existing['date_etat_contrat_supervision'] ?? null);
    $dateControleQualiteSupervision = $saveSection === 'controle_qualite'
        ? ($existing['date_controle_qualite_supervision'] ?? date('Y-m-d'))
        : ($existing['date_controle_qualite_supervision'] ?? null);
    $commentaire = clean_str($_POST['commentaire'] ?? '') ?: null;
    $dateDossierComplet = $etat === 'Dossier complet' ? ($existing['date_dossier_complet'] ?? date('Y-m-d')) : null;
    $dateContratNonActif = $etatContrat !== 'Actif' ? ($existing['date_contrat_non_actif'] ?? date('Y-m-d')) : null;

    $stmt = $db->prepare('UPDATE dossiers
                          SET courrier = :courrier, etat_dossier = :etat, date_dossier_complet = :date_dossier_complet,
                              etat_contrat = :etat_contrat, date_etat_contrat_supervision = :date_etat_contrat_supervision,
                              controle_qualite = :controle_qualite, date_courrier_supervision = :date_courrier_supervision,
                              date_controle_qualite_supervision = :date_controle_qualite_supervision,
                              date_contrat_non_actif = :date_contrat_non_actif,
                              commentaire = :commentaire, updated_by = :updated_by
                          WHERE id = :id');
    $stmt->execute([
        'courrier' => $courrier,
        'etat' => $etat,
        'date_dossier_complet' => $dateDossierComplet,
        'etat_contrat' => $etatContrat,
        'date_etat_contrat_supervision' => $dateEtatContratSupervision,
        'controle_qualite' => $controleQualite,
        'date_courrier_supervision' => $dateCourrierSupervision,
        'date_controle_qualite_supervision' => $dateControleQualiteSupervision,
        'date_contrat_non_actif' => $dateContratNonActif,
        'commentaire' => $commentaire,
        'updated_by' => $user['id'],
        'id' => $id,
    ]);

    $changedLabels = [];
    $brief = static function (string $v): string {
        $v = trim($v);
        if ($v === '') { $v = '—'; }
        return mb_strlen($v) > 40 ? mb_substr($v, 0, 40) . '…' : $v;
    };
    foreach (['courrier' => $courrier, 'etat_dossier' => $etat, 'etat_contrat' => $etatContrat, 'controle_qualite' => $controleQualite, 'commentaire' => $commentaire] as $champ => $nouvelle) {
        if ((string) $nouvelle !== (string) $existing[$champ]) {
            log_dossier_history($db, $id, $user['id'], 'modification', $champ, (string) $existing[$champ], (string) $nouvelle);
            $changedLabels[] = dossier_field_label($champ) . ' : ' . $brief((string) $existing[$champ]) . ' → ' . $brief((string) $nouvelle);
        }
    }


    $notifDetail = 'Modification (section supervision) du dossier #' . $id . '.';
    if ($changedLabels) {
        $notifDetail .= ' Champs modifiés : ' . implode(', ', array_values(array_unique($changedLabels))) . '.';
    }
    $whoSup = (string) ($user['nom_complet'] ?? $user['username'] ?? 'Un superviseur');
    notify_superviseur_action($db, 'update', 'dossier', $id,
        $whoSup . ' a modifié le dossier #' . $id,
        $notifDetail);
    // Notifie le vendeur propriétaire (chemin « section supervision » : retour
    // anticipé, donc l'alerte vendeur doit être envoyée ICI aussi).
    $ownerIdSup = (int) ($existing['vendeur_id'] ?? 0);
    if ($ownerIdSup > 0 && $ownerIdSup !== (int) ($user['id'] ?? 0)) {
        notify_vendeur_owner($db, $ownerIdSup, 'update', 'dossier', $id,
            $whoSup . ' a modifié votre dossier #' . $id,
            $notifDetail, $id);
    }

    set_flash('success', 'Dossier mis à jour avec succès.');
    redirect('dossier_view.php?id=' . $id);
}

$result = validate_dossier_input($_POST, $db, $isEdit ? $id : null);

// Un vendeur connecté ne peut jamais choisir ni sélectionner un autre vendeur
// (voir dossier_form.php où le champ est figé en lecture seule). La valeur
// envoyée par la requête est donc purement ignorée : on force le vendeur porté
// par le dossier en modification, ou le vendeur connecté en création. Le
// contrôle a lieu APRÈS validation pour rester robuste : une valeur falsifiée
// (ou un dossier importé dont le vendeur n'a plus le rôle « vendeur ») ne peut
// ni s'enregistrer, ni bloquer l'enregistrement du dossier.
if (is_vendeur_user()) {
    $forcedVendeurId = $isEdit ? (int) $existing['vendeur_id'] : (int) ($user['id'] ?? 0);
    if ($forcedVendeurId > 0) {
        $result['data']['vendeur_id'] = $forcedVendeurId;
        unset($result['errors']['vendeur_id']);
    }
}

if ($result['errors']) {
    $_SESSION['form_data'] = $result['data'];
    $_SESSION['form_errors'] = $result['errors'];
    set_flash('error', 'Veuillez corriger les erreurs signalées dans le formulaire.');
    redirect('dossier_form.php' . ($isEdit ? ('?id=' . $id) : ''));
}

$data = $result['data'];

// Migration douce : la colonne dl_resiliation peut ne pas exister encore sur
// une base existante / hébergée (avant import de install.sql ou exécution de
// repair_schema.php). On l'ajoute à la volée pour ne jamais planter le save.
try {
    $hasDlCol = false;
    foreach ($db->query('SHOW COLUMNS FROM `dossiers`')->fetchAll(PDO::FETCH_ASSOC) as $colRow) {
        if (($colRow['Field'] ?? '') === 'dl_resiliation') { $hasDlCol = true; break; }
    }
    if (!$hasDlCol) {
        $db->exec('ALTER TABLE `dossiers` ADD COLUMN `dl_resiliation` DATE NULL');
        $hasDlCol = true;
    }
} catch (Throwable $e) {
    $hasDlCol = false;
}
if (!$hasDlCol) {
    unset($data['dl_resiliation']);
}

try {
    $db->beginTransaction();

    if ($isEdit) {
        $dlSet = $hasDlCol ? ', dl_resiliation = :dl_resiliation' : '';
        $sql = 'UPDATE dossiers SET
                    vendeur_id = :vendeur_id, ta_origine = :ta_origine, p_prod = :p_prod, date_vente = :date_vente,
                    civilite = :civilite, nom = :nom, prenom = :prenom, mail = :mail, telfix = :telfix,
                    portable = :portable, nombre_personnes = :nombre_personnes, date_naissance_assure = :date_naissance_assure,
                    age_assure_principal = :age_assure_principal, adresse = :adresse, cp = :cp, ville = :ville,
                    type_signature = :type_signature, ca_mois = :ca_mois, ca_annuel = :ca_annuel, date_effet = :date_effet,
                    date_injection = :date_injection, produit = :produit, compagnie = :compagnie, courrier = :courrier, etat_dossier = :etat_dossier,
                    date_dossier_complet = :date_dossier_complet, etat_contrat = :etat_contrat,
                    controle_qualite = :controle_qualite,
                    date_contrat_non_actif = :date_contrat_non_actif, commentaire = :commentaire,
                    motif_annulation = :motif_annulation,
                    mutuelle_a_resilier = :mutuelle_a_resilier, date_resiliation = :date_resiliation,
                    type_resiliation = :type_resiliation' . $dlSet . ', updated_by = :updated_by
                WHERE id = :id';
        $stmt = $db->prepare($sql);
        $data['updated_by'] = $user['id'];
        $data['date_dossier_complet'] = $data['etat_dossier'] === 'Dossier complet'
            ? ($existing['date_dossier_complet'] ?? date('Y-m-d'))
            : null;
        $data['date_contrat_non_actif'] = $data['etat_contrat'] !== 'Actif'
            ? ($existing['date_contrat_non_actif'] ?? date('Y-m-d'))
            : null;
        $data['id'] = $id;
        $stmt->execute($data);

        // Historique : uniquement les champs modifiés
        $generalChanged = [];
        $briefGen = static function (string $v): string {
            $v = trim($v);
            if ($v === '') { $v = '—'; }
            return mb_strlen($v) > 40 ? mb_substr($v, 0, 40) . '…' : $v;
        };
        foreach ($data as $champ => $nouvelle) {
            if (!array_key_exists($champ, $existing)) continue;
            $ancienne = (string) $existing[$champ];
            if ((string) $nouvelle !== $ancienne) {
                log_dossier_history($db, $id, $user['id'], 'modification', $champ, $ancienne, (string) $nouvelle);
                $generalChanged[] = dossier_field_label($champ) . ' : ' . $briefGen($ancienne) . ' → ' . $briefGen((string) $nouvelle);
            }
        }

        set_flash('success', 'Dossier mis à jour avec succès.');
    } else {
        $dlCols = $hasDlCol ? ', dl_resiliation' : '';
        $dlVals = $hasDlCol ? ', :dl_resiliation' : '';
        $sql = 'INSERT INTO dossiers
                    (vendeur_id, ta_origine, p_prod, date_vente, civilite, nom, prenom, mail, telfix, portable,
                     nombre_personnes, date_naissance_assure, age_assure_principal, adresse, cp, ville,
                     type_signature, ca_mois, ca_annuel, date_effet, date_injection, produit, compagnie, courrier, etat_dossier,
                     date_dossier_complet, etat_contrat, controle_qualite, date_contrat_non_actif, commentaire, motif_annulation,
                     mutuelle_a_resilier, date_resiliation, type_resiliation' . $dlCols . ', created_by)
                VALUES
                    (:vendeur_id, :ta_origine, :p_prod, :date_vente, :civilite, :nom, :prenom, :mail, :telfix, :portable,
                     :nombre_personnes, :date_naissance_assure, :age_assure_principal, :adresse, :cp, :ville,
                     :type_signature, :ca_mois, :ca_annuel, :date_effet, :date_injection, :produit, :compagnie, :courrier, :etat_dossier,
                     :date_dossier_complet, :etat_contrat, :controle_qualite, :date_contrat_non_actif, :commentaire, :motif_annulation,
                     :mutuelle_a_resilier, :date_resiliation, :type_resiliation' . $dlVals . ', :created_by)';
        $stmt = $db->prepare($sql);
        $data['date_dossier_complet'] = $data['etat_dossier'] === 'Dossier complet' ? date('Y-m-d') : null;
        $data['date_contrat_non_actif'] = $data['etat_contrat'] !== 'Actif' ? date('Y-m-d') : null;
        $data['created_by'] = $user['id'];
        $stmt->execute($data);
        $id = (int) $db->lastInsertId();

        log_dossier_history($db, $id, $user['id'], 'creation');
        set_flash('success', 'Dossier créé avec succès.');
    }

    $db->commit();

    // Notifications croisées vendeur <-> superviseur (en plus du journal admin) :
    //  - vendeur qui crée            -> superviseurs + admin ;
    //  - superviseur/admin qui crée  -> vendeur propriétaire + admin ;
    //  - superviseur/admin qui édite -> vendeur propriétaire + admin.
    $who = (string) ($user['nom_complet'] ?? $user['username'] ?? 'Un utilisateur');
    $label = trim((string) (($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? '')));
    $ownerId = $isEdit
        ? (int) ($existing['vendeur_id'] ?? ($data['vendeur_id'] ?? 0))
        : (int) ($data['vendeur_id'] ?? ($user['id'] ?? 0));
    if ($isEdit) {
        $updateDetail = 'Dossier #' . $id . ' modifié par ' . $who . '.';
        if (!empty($generalChanged)) {
            $updateDetail .= ' Champs modifiés : ' . implode(', ', array_values(array_unique($generalChanged))) . '.';
        }
        if (is_vendeur_user()) {
            notify_vendeur_action($db, 'update', 'dossier', $id,
                $who . ' (vendeur) a modifié le dossier #' . $id . ($label !== '' ? ' (' . $label . ')' : ''),
                $updateDetail, $id, $ownerId);
        } elseif (is_superviseur() || is_admin()) {
            notify_superviseur_action($db, 'update', 'dossier', $id,
                $who . ' a modifié le dossier #' . $id . ($label !== '' ? ' (' . $label . ')' : ''),
                $updateDetail);
            if ($ownerId > 0 && $ownerId !== (int) ($user['id'] ?? 0)) {
                notify_vendeur_owner($db, $ownerId, 'update', 'dossier', $id,
                    $who . ' a modifié votre dossier #' . $id . ($label !== '' ? ' (' . $label . ')' : ''),
                    $updateDetail, $id);
            }
        }
    } else {
        if (is_vendeur_user()) {
            notify_vendeur_action($db, 'create', 'dossier', $id,
                $who . ' (vendeur) a créé le dossier #' . $id . ($label !== '' ? ' (' . $label . ')' : ''),
                'Dossier #' . $id . ' créé par ' . $who . ' (vendeur).', $id, $ownerId);
        } elseif (is_superviseur() || is_admin()) {
            notify_superviseur_action($db, 'create', 'dossier', $id,
                $who . ' a créé le dossier #' . $id . ($label !== '' ? ' (' . $label . ')' : ''),
                'Dossier #' . $id . ' créé par ' . $who . '.');
            if ($ownerId > 0 && $ownerId !== (int) ($user['id'] ?? 0)) {
                notify_vendeur_owner($db, $ownerId, 'create', 'dossier', $id,
                    $who . ' a créé le dossier #' . $id . ($label !== '' ? ' (' . $label . ')' : '') . ' pour vous',
                    'Dossier #' . $id . ' créé par ' . $who . ' et rattaché à votre compte.', $id);
            }
        }
    }

    $action = $isEdit ? 'update' : 'create';
    sec_log($action, 'dossier', (string) $id, ($isEdit ? 'Updated' : 'Created') . ' dossier: ' . ($data['nom'] ?? ''));
} catch (PDOException $e) {
    $db->rollBack();
    // SQLSTATE 23000 = violation d'integrite. Le message montre n'est annonce
    // que si la cause reelle est bien le telephone 2 (contrainte
    // uq_dossiers_portable). Sinon on ne trompe pas l'utilisateur en lui
    // evoquant un numero en double alors qu'il vient de le laisser vide : un
    // telephone 2 vide est stocke a NULL et ne peut donc pas creer de doublon.
    $portableRenseigne = trim((string) ($data['portable'] ?? '')) !== '';
    $estDoublonPortable = $portableRenseigne
        && (str_contains($e->getMessage(), 'uq_dossiers_portable') || stripos($e->getMessage(), 'Duplicate entry') !== false);
    if ($estDoublonPortable) {
        set_flash('error', 'Ce numéro de téléphone 2 existe déjà pour un autre dossier.');
        sec_log('permission_denied', 'dossier', null, 'Duplicate phone number', false, 'Duplicate entry');
    } else {
        error_log('dossier_save error: ' . $e->getMessage());
        set_flash('error', 'Une erreur est survenue lors de l’enregistrement du dossier.');
        sec_log('permission_denied', 'dossier', null, 'Save failed: ' . $e->getMessage(), false, 'Database error');
    }
    $_SESSION['form_data'] = $data;
    redirect('dossier_form.php' . ($isEdit ? ('?id=' . $id) : ''));
}

redirect('dossier_view.php?id=' . $id);
