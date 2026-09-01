<?php
require_once __DIR__ . '/../includes/init.php';
require_dossier_access();
csrf_require();

$user = current_user();
$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
$isEdit = (bool) $id;

$existing = null;
if ($isEdit) {
    $stmt = $db->prepare('SELECT * FROM dossiers WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        set_flash('error', 'Dossier introuvable.');
        redirect('dossiers.php');
    }
}

if (is_superviseur()) {
    if (!$isEdit) {
        set_flash('error', 'Un superviseur ne peut pas créer de dossier.');
        redirect('dossiers.php');
    }

    $courrierValues = $_POST['courrier'] ?? [];
    $courrierValues = is_array($courrierValues) ? array_values(array_intersect(options_courrier(), array_map('clean_str', $courrierValues))) : [];
    $courrier = json_encode(array_values(array_unique($courrierValues)), JSON_UNESCAPED_UNICODE);
    $etat = etat_dossier_from_courrier($courrierValues);
    $etatContrat = clean_str($_POST['etat_contrat'] ?? 'Actif');
    $commentaire = clean_str($_POST['commentaire'] ?? '') ?: null;
    $dateDossierComplet = $etat === 'Dossier complet' ? ($existing['date_dossier_complet'] ?? date('Y-m-d')) : null;
    $dateContratNonActif = $etatContrat !== 'Actif' ? ($existing['date_contrat_non_actif'] ?? date('Y-m-d')) : null;

    $stmt = $db->prepare('UPDATE dossiers
                          SET courrier = :courrier, etat_dossier = :etat, date_dossier_complet = :date_dossier_complet,
                              etat_contrat = :etat_contrat, date_contrat_non_actif = :date_contrat_non_actif,
                              commentaire = :commentaire, updated_by = :updated_by
                          WHERE id = :id');
    $stmt->execute([
        'courrier' => $courrier,
        'etat' => $etat,
        'date_dossier_complet' => $dateDossierComplet,
        'etat_contrat' => $etatContrat,
        'date_contrat_non_actif' => $dateContratNonActif,
        'commentaire' => $commentaire,
        'updated_by' => $user['id'],
        'id' => $id,
    ]);

    foreach (['courrier' => $courrier, 'etat_dossier' => $etat, 'etat_contrat' => $etatContrat, 'commentaire' => $commentaire] as $champ => $nouvelle) {
        if ((string) $nouvelle !== (string) $existing[$champ]) {
            log_dossier_history($db, $id, $user['id'], 'modification', $champ, (string) $existing[$champ], (string) $nouvelle);
        }
    }

    set_flash('success', 'Dossier mis à jour avec succès.');
    redirect('dossier_view.php?id=' . $id);
}

$result = validate_dossier_input($_POST, $db, $isEdit ? $id : null);

if ($result['errors']) {
    $_SESSION['form_data'] = $result['data'];
    $_SESSION['form_errors'] = $result['errors'];
    set_flash('error', 'Veuillez corriger les erreurs signalées dans le formulaire.');
    redirect('dossier_form.php' . ($isEdit ? ('?id=' . $id) : ''));
}

$data = $result['data'];

try {
    $db->beginTransaction();

    if ($isEdit) {
        $sql = 'UPDATE dossiers SET
                    vendeur_id = :vendeur_id, ta_origine = :ta_origine, p_prod = :p_prod, date_vente = :date_vente,
                    civilite = :civilite, nom = :nom, prenom = :prenom, mail = :mail, telfix = :telfix,
                    portable = :portable, nombre_personnes = :nombre_personnes, date_naissance_assure = :date_naissance_assure,
                    age_assure_principal = :age_assure_principal, adresse = :adresse, cp = :cp, ville = :ville,
                    type_signature = :type_signature, ca_mois = :ca_mois, ca_annuel = :ca_annuel, date_effet = :date_effet,
                    produit = :produit, compagnie = :compagnie, courrier = :courrier, etat_dossier = :etat_dossier,
                    date_dossier_complet = :date_dossier_complet, etat_contrat = :etat_contrat,
                    date_contrat_non_actif = :date_contrat_non_actif, commentaire = :commentaire,
                    motif_annulation = :motif_annulation, updated_by = :updated_by
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
        foreach ($data as $champ => $nouvelle) {
            if (!array_key_exists($champ, $existing)) continue;
            $ancienne = (string) $existing[$champ];
            if ((string) $nouvelle !== $ancienne) {
                log_dossier_history($db, $id, $user['id'], 'modification', $champ, $ancienne, (string) $nouvelle);
            }
        }

        set_flash('success', 'Dossier mis à jour avec succès.');
    } else {
        $sql = 'INSERT INTO dossiers
                    (vendeur_id, ta_origine, p_prod, date_vente, civilite, nom, prenom, mail, telfix, portable,
                     nombre_personnes, date_naissance_assure, age_assure_principal, adresse, cp, ville,
                     type_signature, ca_mois, ca_annuel, date_effet, produit, compagnie, courrier, etat_dossier,
                     date_dossier_complet, etat_contrat, date_contrat_non_actif, commentaire, motif_annulation, created_by)
                VALUES
                    (:vendeur_id, :ta_origine, :p_prod, :date_vente, :civilite, :nom, :prenom, :mail, :telfix, :portable,
                     :nombre_personnes, :date_naissance_assure, :age_assure_principal, :adresse, :cp, :ville,
                     :type_signature, :ca_mois, :ca_annuel, :date_effet, :produit, :compagnie, :courrier, :etat_dossier,
                     :date_dossier_complet, :etat_contrat, :date_contrat_non_actif, :commentaire, :motif_annulation, :created_by)';
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
} catch (PDOException $e) {
    $db->rollBack();
    if ((int) $e->getCode() === 23000) {
        set_flash('error', 'Ce numéro de portable existe déjà pour un autre dossier.');
    } else {
        error_log('dossier_save error: ' . $e->getMessage());
        set_flash('error', 'Une erreur est survenue lors de l’enregistrement du dossier.');
    }
    $_SESSION['form_data'] = $data;
    redirect('dossier_form.php' . ($isEdit ? ('?id=' . $id) : ''));
}

redirect('dossier_view.php?id=' . $id);
