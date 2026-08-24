<?php
require_once __DIR__ . '/includes/init.php';
require_dossier_access();

$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
$dossier = null;
$isEdit = false;
$isSuperviseur = is_superviseur();

if ($isSuperviseur && !$id) {
  set_flash('error', 'Un superviseur peut uniquement modifier un dossier existant.');
  redirect('dossiers.php');
}

if ($id) {
    $stmt = $db->prepare('SELECT * FROM dossiers WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $dossier = $stmt->fetch();
    if (!$dossier) {
        set_flash('error', 'Dossier introuvable.');
        redirect('dossiers.php');
    }
    $isEdit = true;
}

// Ré-affichage après erreur de validation (données conservées en session)
$formData = $_SESSION['form_data'] ?? null;
$formErrors = $_SESSION['form_errors'] ?? [];
unset($_SESSION['form_data'], $_SESSION['form_errors']);

$v = function (string $key, $default = '') use ($formData, $dossier) {
    if ($formData !== null && array_key_exists($key, $formData)) {
        return $formData[$key];
    }
    if ($dossier !== null && array_key_exists($key, $dossier)) {
        return $dossier[$key];
    }
    return $default;
};

$vendeurs = $db->query("SELECT id, nom_complet FROM users WHERE role = 'vendeur' ORDER BY nom_complet")->fetchAll();

$pageTitle = $isEdit ? 'Modifier le dossier' : 'Nouveau dossier';
$pageSubtitle = $isEdit ? ('Dossier de ' . $dossier['nom'] . ' ' . $dossier['prenom']) : 'Créer un nouveau dossier';
$activePage = 'dossier_form';
require __DIR__ . '/includes/header.php';
?>

<?php if ($isSuperviseur): ?>
<div class="card dossier-form-card">
  <div class="card-body">
    <p class="help-text">Vous pouvez uniquement modifier le courrier et le commentaire. L’état du dossier est calculé automatiquement.</p>
    <form action="<?= e(APP_URL) ?>/actions/dossier_save.php" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $dossier['id'] ?>">
      <div class="form-grid mb-16">
        <div class="form-group span-full">
          <label>Courrier</label>
          <div class="flex gap-12" style="flex-wrap:wrap;">
            <?php foreach (options_courrier() as $option): ?>
              <label><input type="checkbox" name="courrier[]" value="<?= e($option) ?>" <?= in_array($option, courrier_values($dossier['courrier'] ?? ''), true) ? 'checked' : '' ?>> <?= e($option) ?></label>
            <?php endforeach; ?>
          </div>
          <div class="help-text">Les quatre cases cochées donnent automatiquement « Dossier complet ».</div>
        </div>
        <div class="form-group span-full">
          <label for="commentaire">Commentaire dossier</label>
          <textarea id="commentaire" name="commentaire"><?= e($dossier['commentaire']) ?></textarea>
        </div>
      </div>
      <div class="form-actions dossier-form-actions">
        <button type="submit" class="btn btn-primary">Enregistrer</button>
        <a href="<?= e(APP_URL) ?>/dossier_view.php?id=<?= (int) $dossier['id'] ?>" class="btn btn-outline">Annuler</a>
      </div>
    </form>
  </div>
</div>
<?php else: ?>
<div class="card dossier-form-card">
  <div class="card-body">
    <form action="<?= e(APP_URL) ?>/actions/dossier_save.php" method="post" novalidate>
      <?= csrf_field() ?>
      <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $dossier['id'] ?>"><?php endif; ?>

      <h3>Vendeur</h3>
      <div class="form-grid mb-16">
        <div class="form-group">
          <label for="vendeur_id">Vendeur <span class="req">*</span></label>
          <select id="vendeur_id" name="vendeur_id" class="<?= isset($formErrors['vendeur_id']) ? 'input-error' : '' ?>">
            <option value="">— Sélectionner —</option>
            <?php foreach ($vendeurs as $vd): ?>
              <option value="<?= (int) $vd['id'] ?>" <?= (string) $v('vendeur_id') === (string) $vd['id'] ? 'selected' : '' ?>><?= e($vd['nom_complet']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($formErrors['vendeur_id'])): ?><div class="field-error"><?= e($formErrors['vendeur_id']) ?></div><?php endif; ?>
          <div class="help-text">
            Vendeur absent de la liste ? <a href="<?= e(APP_URL) ?>/vendeurs.php" id="new-vendeur-link">Ajouter un vendeur</a>.
          </div>
        </div>
        <div class="form-group">
          <label for="ta_origine">Origine</label>
          <select id="ta_origine" name="ta_origine" class="<?= isset($formErrors['ta_origine']) ? 'input-error' : '' ?>">
            <option value="">— Sélectionner —</option>
            <?php foreach (origines_valides() as $origine): ?>
              <option value="<?= e($origine) ?>" <?= $v('ta_origine') === $origine ? 'selected' : '' ?>><?= e($origine) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($formErrors['ta_origine'])): ?><div class="field-error"><?= e($formErrors['ta_origine']) ?></div><?php endif; ?>
        </div>
        <div class="form-group">
          <label for="p_prod">PROD</label>
          <input type="text" id="p_prod" name="p_prod" value="<?= e($v('p_prod')) ?>" maxlength="100">
        </div>
        <div class="form-group">
          <label for="date_vente">Date de vente <span class="req">*</span></label>
          <input type="date" id="date_vente" name="date_vente" value="<?= e($v('date_vente')) ?>" class="<?= isset($formErrors['date_vente']) ? 'input-error' : '' ?>">
          <?php if (isset($formErrors['date_vente'])): ?><div class="field-error"><?= e($formErrors['date_vente']) ?></div><?php endif; ?>
        </div>
      </div>

      <h3>Assuré</h3>
      <div class="form-grid mb-16">
        <div class="form-group">
          <label for="civilite">Civilité <span class="req">*</span></label>
          <select id="civilite" name="civilite" class="<?= isset($formErrors['civilite']) ? 'input-error' : '' ?>">
            <?php foreach (civilites_valides() as $c): ?>
              <option value="<?= e($c) ?>" <?= $v('civilite', 'MR') === $c ? 'selected' : '' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="nom">Nom <span class="req">*</span></label>
          <input type="text" id="nom" name="nom" value="<?= e($v('nom')) ?>" maxlength="150" class="<?= isset($formErrors['nom']) ? 'input-error' : '' ?>">
          <?php if (isset($formErrors['nom'])): ?><div class="field-error"><?= e($formErrors['nom']) ?></div><?php endif; ?>
        </div>
        <div class="form-group">
          <label for="prenom">Prénom <span class="req">*</span></label>
          <input type="text" id="prenom" name="prenom" value="<?= e($v('prenom')) ?>" maxlength="150" class="<?= isset($formErrors['prenom']) ? 'input-error' : '' ?>">
          <?php if (isset($formErrors['prenom'])): ?><div class="field-error"><?= e($formErrors['prenom']) ?></div><?php endif; ?>
        </div>
        <div class="form-group">
          <label for="mail">Mail</label>
          <input type="email" id="mail" name="mail" value="<?= e($v('mail')) ?>" maxlength="190" class="<?= isset($formErrors['mail']) ? 'input-error' : '' ?>">
          <?php if (isset($formErrors['mail'])): ?><div class="field-error"><?= e($formErrors['mail']) ?></div><?php endif; ?>
        </div>
        <div class="form-group">
          <label for="telfix">Téléphone 1</label>
          <input type="tel" id="telfix" name="telfix" value="<?= e($v('telfix')) ?>" maxlength="30">
        </div>
        <div class="form-group">
          <label for="portable">Téléphone 2 <span class="req">*</span></label>
          <input type="tel" id="portable" name="portable" value="<?= e($v('portable')) ?>" maxlength="30" class="<?= isset($formErrors['portable']) ? 'input-error' : '' ?>">
          <?php if (isset($formErrors['portable'])): ?><div class="field-error"><?= e($formErrors['portable']) ?></div><?php endif; ?>
          <div class="help-text">Doit être unique (règle reprise du classeur d'origine).</div>
        </div>
        <div class="form-group">
          <label for="nombre_personnes">Nombre d'assurés</label>
          <input type="number" id="nombre_personnes" name="nombre_personnes" min="1" max="10" value="<?= e((string) $v('nombre_personnes', 1)) ?>">
        </div>
        <div class="form-group">
          <label for="date_naissance_assure">Date(s) naissance assuré(s)</label>
          <input type="text" id="date_naissance_assure" name="date_naissance_assure" value="<?= e($v('date_naissance_assure')) ?>" placeholder="ex : 12/04/1960 ou deux dates si couple">
        </div>
        <div class="form-group">
          <label for="age_assure_principal">Âge assuré principal</label>
          <input type="text" id="age_assure_principal" name="age_assure_principal" value="<?= e($v('age_assure_principal')) ?>">
        </div>
      </div>

      <h3>Coordonnées</h3>
      <div class="form-grid mb-16">
        <div class="form-group span-2">
          <label for="adresse">Adresse <span class="req">*</span></label>
          <input type="text" id="adresse" name="adresse" value="<?= e($v('adresse')) ?>" maxlength="255" class="<?= isset($formErrors['adresse']) ? 'input-error' : '' ?>">
          <?php if (isset($formErrors['adresse'])): ?><div class="field-error"><?= e($formErrors['adresse']) ?></div><?php endif; ?>
        </div>
        <div class="form-group">
          <label for="cp">Code postal <span class="req">*</span></label>
          <input type="text" id="cp" name="cp" value="<?= e($v('cp')) ?>" maxlength="10" class="<?= isset($formErrors['cp']) ? 'input-error' : '' ?>">
          <?php if (isset($formErrors['cp'])): ?><div class="field-error"><?= e($formErrors['cp']) ?></div><?php endif; ?>
        </div>
        <div class="form-group">
          <label for="ville">Ville <span class="req">*</span></label>
          <input type="text" id="ville" name="ville" value="<?= e($v('ville')) ?>" maxlength="150" class="<?= isset($formErrors['ville']) ? 'input-error' : '' ?>">
          <?php if (isset($formErrors['ville'])): ?><div class="field-error"><?= e($formErrors['ville']) ?></div><?php endif; ?>
        </div>
        <div class="form-group">
          <label for="type_signature">Type de signature <span class="req">*</span></label>
          <input type="text" id="type_signature" name="type_signature" list="types-signature" value="<?= e($v('type_signature')) ?>" class="<?= isset($formErrors['type_signature']) ? 'input-error' : '' ?>">
          <datalist id="types-signature">
            <?php foreach (types_signature_suggeres() as $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?>
          </datalist>
          <?php if (isset($formErrors['type_signature'])): ?><div class="field-error"><?= e($formErrors['type_signature']) ?></div><?php endif; ?>
        </div>
      </div>

      <h3>Contrat</h3>
      <div class="form-grid mb-16">
        <div class="form-group">
          <label for="ca_mois">CA mensuel (€) <span class="req">*</span></label>
          <input type="number" step="0.01" min="0" id="ca_mois" name="ca_mois" value="<?= e((string) $v('ca_mois')) ?>" class="<?= isset($formErrors['ca_mois']) ? 'input-error' : '' ?>">
          <?php if (isset($formErrors['ca_mois'])): ?><div class="field-error"><?= e($formErrors['ca_mois']) ?></div><?php endif; ?>
        </div>
        <div class="form-group">
          <label for="ca_annuel">CA annuel (€)</label>
          <input type="number" step="0.01" min="0" id="ca_annuel" name="ca_annuel" data-auto="true" value="<?= e((string) $v('ca_annuel')) ?>">
         
        </div>
        <div class="form-group">
          <label for="date_effet">Date d'effet <span class="req">*</span></label>
          <input type="date" id="date_effet" name="date_effet" value="<?= e($v('date_effet')) ?>" class="<?= isset($formErrors['date_effet']) ? 'input-error' : '' ?>">
          <?php if (isset($formErrors['date_effet'])): ?><div class="field-error"><?= e($formErrors['date_effet']) ?></div><?php endif; ?>
        </div>
        <div class="form-group">
          <label for="produit">Produit <span class="req">*</span></label>
          <input type="text" id="produit" name="produit" value="<?= e($v('produit')) ?>" maxlength="150" class="<?= isset($formErrors['produit']) ? 'input-error' : '' ?>">
          <?php if (isset($formErrors['produit'])): ?><div class="field-error"><?= e($formErrors['produit']) ?></div><?php endif; ?>
        </div>
        <div class="form-group">
          <label for="compagnie">Compagnie <span class="req">*</span></label>
          <input type="text" id="compagnie" name="compagnie" list="compagnies" value="<?= e($v('compagnie')) ?>" class="<?= isset($formErrors['compagnie']) ? 'input-error' : '' ?>">
          <datalist id="compagnies">
            <?php foreach (compagnies_suggerees() as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?>
          </datalist>
          <?php if (isset($formErrors['compagnie'])): ?><div class="field-error"><?= e($formErrors['compagnie']) ?></div><?php endif; ?>
        </div>
        <div class="form-group">
          <label>Courrier</label>
          <div class="flex gap-12" style="flex-wrap:wrap;">
            <?php foreach (options_courrier() as $option): ?>
              <label><input type="checkbox" name="courrier[]" value="<?= e($option) ?>" <?= in_array($option, courrier_values($v('courrier')), true) ? 'checked' : '' ?>> <?= e($option) ?></label>
            <?php endforeach; ?>
          </div>
          <div class="help-text">Les quatre cases cochées donnent automatiquement « Dossier complet ».</div>
        </div>
        <div class="form-group">
          <label for="etat_contrat">État du contrat <span class="req">*</span></label>
          <select id="etat_contrat" name="etat_contrat" required>
            <?php foreach (etats_contrat_valides() as $etat): ?>
              <option value="<?= e($etat) ?>" <?= $v('etat_contrat', 'Actif') === $etat ? 'selected' : '' ?>><?= e($etat) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        </div>
        <div class="form-group" id="motif-annulation-group" style="display:none;">
          <label for="motif_annulation">Motif d'annulation</label>
          <input type="text" id="motif_annulation" name="motif_annulation" value="<?= e($v('motif_annulation')) ?>" maxlength="255" placeholder="ex : Radiation pour non paiement">
        </div>
        <div class="form-group span-full">
          <label for="commentaire">Commentaire dossier</label>
          <textarea id="commentaire" name="commentaire"><?= e($v('commentaire')) ?></textarea>
        </div>
      </div>

      <div class="form-actions dossier-form-actions">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Enregistrer les modifications' : 'Créer le dossier' ?></button>
        <a href="<?= e(APP_URL) ?>/dossiers.php" class="btn btn-outline">Annuler</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php $footerInContent = true; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
