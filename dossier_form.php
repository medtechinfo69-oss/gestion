<?php
require_once __DIR__ . '/includes/init.php';
require_dossier_create_access();

// Seuls l'admin et le rôle « superviseur » strict peuvent MODIFIER un dossier.
// Les vendeurs (même avec can_supervise = 1) restent en lecture seule :
// - pas de bouton « Modifier » (voir dossiers.php + dossier_view.php) ;
// - l'accès direct à dossier_form.php?id=… est refusé ici ;
// - la sauvegarde d'une modification est refusée dans actions/dossier_save.php.
if (!empty($_GET['id']) && !is_admin() && !is_role_superviseur()) {
    http_response_code(403);
    set_flash('error', 'Modification réservée aux administrateurs et superviseurs.');
    redirect('dossiers.php');
}

// CRÉATION : le vendeur dispose désormais de la même capacité que le
// superviseur (can_create_dossier()) ; la MODIFICATION, elle, reste
// interdite (garde ci-dessus). require_dossier_create_access() a déjà exclu
// les rôles non habilités (cloisonnement du rôle « rh » compris).
if (empty($_GET['id']) && !can_create_dossier()) {
    http_response_code(403);
    set_flash('error', 'Création de dossier réservée aux administrateurs et superviseurs.');
    redirect('dossiers.php');
}

$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
$dossier = null;
$isEdit = false;
$isAdmin = is_admin();
$isSuperviseur = is_role_superviseur();
$isVendeur = is_vendeur_user();

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

$isRestricted = $isEdit && ($dossier['date_dossier_complet'] !== null
  || $dossier['date_contrat_non_actif'] !== null
  || ($dossier['date_courrier_supervision'] ?? null) !== null
  || ($dossier['date_etat_contrat_supervision'] ?? null) !== null
  || ($dossier['date_controle_qualite_supervision'] ?? null) !== null);
$courrierValues = $isEdit ? courrier_values($dossier['courrier'] ?? '') : [];
$courrierComplete = count($courrierValues) === count(options_courrier());
$contratRouge = $isEdit && $dossier['etat_contrat'] !== 'Actif';
$dossierCompletLocked = $isEdit && (($dossier['date_dossier_complet'] ?? null) !== null || ($dossier['etat_dossier'] ?? '') === 'Dossier complet');
$courrierLocked = $isEdit
  && ($dossierCompletLocked || ($dossier['date_courrier_supervision'] ?? null) !== null)
  && $courrierComplete;
// L'état du contrat reste modifiable tant qu'il vaut « Actif », même si la
// section a déjà été enregistrée (date_etat_contrat_supervision renseignée) :
// sinon le superviseur ne pouvait plus jamais annuler un contrat validé. Le
// verrou n'apparaît qu'une fois le contrat réellement sorti de l'état
// « Actif » ; l'administrateur peut alors le débloquer via « Réactiver
// supervision » (voir actions/dossier_reactivate.php).
$etatContratLocked = $isEdit && (($dossier['etat_contrat'] ?? 'Actif') !== 'Actif');
$controleQualiteLocked = $isEdit && (($dossier['date_controle_qualite_supervision'] ?? null) !== null);

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

// Vendeur connecté : la saisie se fait toujours pour elle-même. Le champ
// « Vendeur » n'est donc plus une liste déroulante mais un champ figé (lecture
// seule) recopié dans un champ caché : elle ne peut plus choisir ni
// sélectionner un autre vendeur. Sur une fiche existante, la valeur figée est
// celle portée par le dossier (elle n'est jamais réattribuée à la volée au
// vendeur connecté). Les administrateurs et superviseurs conservent la liste
// complète et restent libres de choisir n'importe quel vendeur.
$currentUserId = (int) (current_user()['id'] ?? 0);
$lockedVendeur = $isVendeur;
$lockedVendeurId = $lockedVendeur ? ($isEdit ? (int) $dossier['vendeur_id'] : $currentUserId) : 0;
$lockedVendeurNom = '';
if ($lockedVendeur) {
    $stmtVendeurNom = $db->prepare('SELECT nom_complet FROM users WHERE id = :id');
    $stmtVendeurNom->execute(['id' => $lockedVendeurId]);
    $lockedVendeurNom = (string) ($stmtVendeurNom->fetchColumn() ?: '');
    if ($lockedVendeurNom === '') {
        // Compte introuvable (supprimé) : on retombe sur le nom de session.
        $lockedVendeurNom = (string) (current_user()['nom_complet'] ?? current_user()['username'] ?? '');
    }
}
$selectedVendeurId = (string) $v('vendeur_id', $lockedVendeurId ? (string) $lockedVendeurId : '');

$pageTitle = $isEdit ? 'Modifier le dossier' : 'Nouveau dossier';
$pageSubtitle = $isEdit ? ('Dossier de ' . $dossier['nom'] . ' ' . $dossier['prenom']) : 'Créer un nouveau dossier';
$activePage = 'dossier_form';
require __DIR__ . '/includes/header.php';
?>

<?php if ($isSuperviseur && $isEdit): ?>
<div class="card dossier-form-card">
  <div class="card-body">
    <p class="help-text">Vous pouvez uniquement modifier le commentaire du dossier. Les sections Courrier, État du contrat et Contrôle qualité sont gérées par l'administrateur et ne sont pas modifiables ici.</p>
    <form action="<?= e(APP_URL) ?>/actions/dossier_save.php" method="post" data-supervision-form>
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $dossier['id'] ?>">
      <input type="hidden" name="save_section" value="commentaire">
      <div class="form-grid mb-16">
        <div class="form-group span-full">
          <label>Courrier</label>
          <div class="courrier-grid">
            <?php foreach (options_courrier() as $option): ?>
              <?php $checked = in_array($option, $courrierValues, true); ?>
              <label class="courrier-option">
                <input type="checkbox" class="courrier-checkbox" name="courrier[]" value="<?= e($option) ?>" <?= $checked ? 'checked' : '' ?> <?= $courrierLocked ? 'disabled' : '' ?>>
                <span><?= e($option) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <?php if ($courrierLocked): ?>
            <div id="courrier-message" class="alert alert-error" style="margin-top:10px;">Merci de contacter l'administrateur.</div>
          <?php endif; ?>
          <div class="help-text">Cochez les quatre cases pour obtenir automatiquement « Dossier complet ».</div>
          <button type="submit" name="save_section" value="courrier" class="btn btn-primary btn-sm section-save" <?= $courrierLocked || $dossierCompletLocked ? 'disabled' : '' ?>>Enregistrer Courrier</button>
        </div>
        <div class="form-group span-full">
          <label for="etat_contrat">État du contrat</label>
          <select id="etat_contrat" name="etat_contrat" <?= $etatContratLocked ? 'disabled' : '' ?> aria-disabled="<?= $etatContratLocked ? 'true' : 'false' ?>">
            <?php foreach (etats_contrat_valides() as $etat): ?>
              <option value="<?= e($etat) ?>" <?= $dossier['etat_contrat'] === $etat ? 'selected' : '' ?>><?= e($etat) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="hidden" id="etat_contrat_locked" name="etat_contrat_locked" value="">
          <?php if ($etatContratLocked): ?>
            <div class="alert alert-error" style="margin-top:10px;">Merci de contacter l'administrateur.</div>
          <?php endif; ?>
          <button type="submit" name="save_section" value="etat_contrat" class="btn btn-primary btn-sm section-save" <?= $etatContratLocked ? 'disabled' : '' ?>>Enregistrer État du contrat</button>
        </div>
        <div class="form-group span-full">
          <label for="controle_qualite">Contrôle qualité</label>
          <select id="controle_qualite" name="controle_qualite" <?= $controleQualiteLocked ? 'disabled' : '' ?> aria-disabled="<?= $controleQualiteLocked ? 'true' : 'false' ?>">
            <option value="">— Sélectionner —</option>
            <?php foreach (controles_qualite_valides() as $controle): ?>
              <option value="<?= e($controle) ?>" <?= ($dossier['controle_qualite'] ?? '') === $controle ? 'selected' : '' ?>><?= e($controle) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="hidden" id="controle_qualite_locked" name="controle_qualite_locked" value="">
          <?php if ($controleQualiteLocked): ?>
            <div class="alert alert-error" style="margin-top:10px;">Merci de contacter l'administrateur.</div>
          <?php endif; ?>
          <button type="submit" name="save_section" value="controle_qualite" class="btn btn-primary btn-sm section-save" <?= $controleQualiteLocked ? 'disabled' : '' ?>>Enregistrer Contrôle qualité</button>
        </div>
        <div class="form-group span-full">
          <label for="commentaire">Commentaire dossier</label>
          <textarea id="commentaire" name="commentaire"><?= e($dossier['commentaire']) ?></textarea>
          <button type="submit" name="save_section" value="commentaire" class="btn btn-primary btn-sm section-save">Enregistrer Commentaire</button>
        </div>
      </div>
      <div class="form-actions dossier-form-actions">
        <a href="<?= e(APP_URL) ?>/dossier_view.php?id=<?= (int) $dossier['id'] ?>" class="btn btn-outline">Annuler</a>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  if (!document.querySelector('.courrier-checkbox')) return;
  var checkboxes = document.querySelectorAll('.courrier-checkbox');
  var etatContrat = document.getElementById('etat_contrat');
  var controleQualite = document.getElementById('controle_qualite');
  var messageEl = document.getElementById('courrier-message');
  var form = checkboxes[0].form;
  var courrierLocked = <?= $courrierLocked ? 'true' : 'false' ?>;
  var etatContratLocked = <?= $etatContratLocked ? 'true' : 'false' ?>;

  function updateCourrierState() {
    checkboxes.forEach(function(cb) { cb.disabled = courrierLocked; });
    var allChecked = Array.from(checkboxes).every(function(cb) { return cb.checked; });
    if (messageEl) { messageEl.style.display = allChecked && courrierLocked ? 'block' : 'none'; }
  }

  checkboxes.forEach(function(cb) {
    cb.addEventListener('change', updateCourrierState);
  });

  // Verrouille l'état du contrat dès qu'il quitte « Actif » : la valeur est
  // recopiée dans le champ caché « etat_contrat_locked » pour être enregistrée
  // (un <select disabled> n'est pas soumis par le navigateur). Tant que l'état
  // vaut « Actif », le champ reste actif et aucune valeur cachée n'est posée.
  function lockEtatContrat(valeur) {
    var lockedContract = document.getElementById('etat_contrat_locked');
    if (lockedContract) { lockedContract.value = valeur; }
    etatContrat.disabled = true;
  }

  if (etatContrat) {
    etatContrat.addEventListener('change', function() {
      if (etatContrat.value !== 'Actif') {
        lockEtatContrat(etatContrat.value);
      }
    });
  }

  form.addEventListener('submit', function(event) {
    var section = event.submitter ? event.submitter.value : '';
    if (section === 'courrier') {
      var allCourrierChecked = Array.from(checkboxes).every(function(cb) { return cb.checked; });
      checkboxes.forEach(function(cb) {
        if (allCourrierChecked && cb.checked) {
        var locked = document.createElement('input');
        locked.type = 'hidden';
        locked.name = 'courrier_locked[]';
        locked.value = cb.value;
        form.appendChild(locked);
        }
      });
      if (allCourrierChecked) {
        checkboxes.forEach(function(cb) { cb.disabled = true; });
      }
    }
    if (section === 'etat_contrat' && etatContrat) {
      lockEtatContrat(etatContrat.value);
    }
    if (section === 'controle_qualite' && controleQualite) {
      var lockedQuality = document.getElementById('controle_qualite_locked');
      if (lockedQuality) { lockedQuality.value = controleQualite.value; }
      controleQualite.disabled = true;
    }
  });

  if (etatContrat) { etatContrat.disabled = etatContratLocked; }

  updateCourrierState();
})();
</script>
<?php else: ?>
<div class="card dossier-form-card">
  <div class="card-body">
    <form action="<?= e(APP_URL) ?>/actions/dossier_save.php" method="post" novalidate>
      <?= csrf_field() ?>
      <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $dossier['id'] ?>"><?php endif; ?>

      <h3>Vendeur</h3>
      <div class="form-grid mb-16">
        <div class="form-group">
          <label for="<?= $lockedVendeur ? 'vendeur_id_display' : 'vendeur_id' ?>">Vendeur <span class="req">*</span></label>
          <?php if ($lockedVendeur): ?>
            <?php // Vendeur connecté : champ figé, aucune sélection possible. ?>
            <input type="text" id="vendeur_id_display" class="form-readonly" value="<?= e($lockedVendeurNom) ?>" readonly aria-readonly="true" tabindex="-1">
            <input type="hidden" name="vendeur_id" value="<?= (int) $lockedVendeurId ?>">
            <div class="help-text">Le dossier est automatiquement enregistré à votre nom.</div>
          <?php else: ?>
            <select id="vendeur_id" name="vendeur_id" class="<?= isset($formErrors['vendeur_id']) ? 'input-error' : '' ?>">
              <option value="">— Sélectionner —</option>
              <?php foreach ($vendeurs as $vd): ?>
                <option value="<?= (int) $vd['id'] ?>" <?= $selectedVendeurId === (string) $vd['id'] ? 'selected' : '' ?>><?= e($vd['nom_complet']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($formErrors['vendeur_id'])): ?><div class="field-error"><?= e($formErrors['vendeur_id']) ?></div><?php endif; ?>
            <div class="help-text">
              Vendeur absent de la liste ? <a href="<?= e(APP_URL) ?>/vendeurs.php" id="new-vendeur-link">Ajouter un vendeur</a>.
            </div>
          <?php endif; ?>
        </div>
        <div class="form-group">
          <label for="ta_origine">Origine</label>
          <select id="ta_origine" name="ta_origine" class="<?= isset($formErrors['ta_origine']) ? 'input-error' : '' ?>"<?= $lockedVendeur ? ' autofocus' : '' ?>>
            <option value="">— Sélectionner —</option>
            <?php
              $currentOrigine = (string) $v('ta_origine', '');
              $validOrigines = origines_valides($db);
              if ($currentOrigine !== '' && !in_array($currentOrigine, $validOrigines, true)) {
                  $validOrigines[] = $currentOrigine;
              }
              foreach ($validOrigines as $origine):
            ?>
              <option value="<?= e($origine) ?>" <?= $currentOrigine === $origine ? 'selected' : '' ?>><?= e($origine) ?></option>
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
          <input type="date" id="date_vente" name="date_vente" value="<?= e($v('date_vente', $isEdit ? '' : date('Y-m-d'))) ?>" class="<?= isset($formErrors['date_vente']) ? 'input-error' : '' ?>">
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
          <input type="text" id="mail" name="mail" value="<?= e($v('mail')) ?>" maxlength="190" class="<?= isset($formErrors['mail']) ? 'input-error' : '' ?>">
          <?php if (isset($formErrors['mail'])): ?><div class="field-error"><?= e($formErrors['mail']) ?></div><?php endif; ?>
        </div>
        <div class="form-group">
          <label for="telfix">Téléphone 1 <span class="req">*</span></label>
          <input type="tel" id="telfix" name="telfix" value="<?= e($v('telfix')) ?>" maxlength="30" class="<?= isset($formErrors['telfix']) ? 'input-error' : '' ?>">
          <?php if (isset($formErrors['telfix'])): ?><div class="field-error"><?= e($formErrors['telfix']) ?></div><?php endif; ?>
        </div>
        <div class="form-group">
          <label for="portable">Téléphone 2</label>
          <input type="tel" id="portable" name="portable" value="<?= e($v('portable')) ?>" maxlength="30" class="<?= isset($formErrors['portable']) ? 'input-error' : '' ?>">
          <?php if (isset($formErrors['portable'])): ?><div class="field-error"><?= e($formErrors['portable']) ?></div><?php endif; ?>
          <?php /* Champ facultatif : seule la precision est ajoutee, le design reste inchange. */ ?>
          <div class="help-text">Facultatif. S'il est renseigné, ce numéro doit être unique.</div>
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
          <input type="date" id="date_effet" name="date_effet" value="<?= e($v('date_effet', $isEdit ? '' : date('Y-m-d'))) ?>" class="<?= isset($formErrors['date_effet']) ? 'input-error' : '' ?>">
          <?php if (isset($formErrors['date_effet'])): ?><div class="field-error"><?= e($formErrors['date_effet']) ?></div><?php endif; ?>
        </div>
        <div class="form-group">
          <label for="date_injection">Date d'injection</label>
          <input type="date" id="date_injection" name="date_injection" value="<?= e($v('date_injection')) ?>" class="<?= isset($formErrors['date_injection']) ? 'input-error' : '' ?>">
          <?php if (isset($formErrors['date_injection'])): ?><div class="field-error"><?= e($formErrors['date_injection']) ?></div><?php endif; ?>
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
        <?php // Ni le superviseur ni le vendeur ne renseignent ces champs à la
              // création (Courrier, État du contrat, Contrôle qualité et motif
              // d'annulation) : leur formulaire est identique. Ces champs sont
              // gérés par l'administrateur ; actions/dossier_save.php applique
              // alors les valeurs par défaut (dossier incomplet, contrat
              // « Actif », pas de contrôle qualité). ?>
        <?php if (!$isSuperviseur && !$isVendeur): ?>
        <div class="form-group">
          <label>Courrier</label>
          <div class="courrier-grid">
            <?php $courrierPost = courrier_values($v('courrier')); ?>
            <?php foreach (options_courrier() as $option): ?>
              <label class="courrier-option">
                <input type="checkbox" name="courrier[]" value="<?= e($option) ?>" <?= in_array($option, $courrierPost, true) ? 'checked' : '' ?>>
                <span><?= e($option) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="help-text">Cochez les quatre cases pour obtenir automatiquement « Dossier complet ».</div>
        </div>
        <div class="form-group">
          <label for="etat_contrat">État du contrat <span class="req">*</span></label>
          <select id="etat_contrat" name="etat_contrat" required>
            <?php foreach (etats_contrat_valides() as $etat): ?>
              <option value="<?= e($etat) ?>" <?= $v('etat_contrat', 'Actif') === $etat ? 'selected' : '' ?>><?= e($etat) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="controle_qualite">Contrôle qualité</label>
          <select id="controle_qualite" name="controle_qualite">
            <option value="">— Sélectionner —</option>
            <?php foreach (controles_qualite_valides() as $controle): ?>
              <option value="<?= e($controle) ?>" <?= $v('controle_qualite', '') === $controle ? 'selected' : '' ?>><?= e($controle) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php $motifVisible = (string) $v('etat_contrat', 'Actif') !== 'Actif'; ?>
        <div class="form-group span-full" id="motif-annulation-group" style="<?= $motifVisible ? '' : 'display:none;' ?>">
          <label for="motif_annulation">Motif d'annulation</label>
          <input type="text" id="motif_annulation" name="motif_annulation" value="<?= e($v('motif_annulation')) ?>" maxlength="255" placeholder="ex : Radiation pour non paiement">
          <div class="help-text">Renseigné automatiquement dès que l'état du contrat n'est plus « Actif ».</div>
        </div>
        <?php
        // -----------------------------------------------------------------
        // Champs de résiliation de la mutuelle — visibles pour TOUS les
        // rôles (admin, superviseur, vendeur) à la création comme en
        // modification. Courrier / État du contrat / Contrôle qualité /
        // Motif d'annulation restent, eux, réservés à l'admin (bloc
        // fermé ci-dessus) : actions/dossier_save.php applique pour le
        // superviseur et le vendeur les valeurs par défaut (dossier
        // incomplet, contrat « Actif », pas de contrôle qualité).
        // ----------------------------------------------------------------- ?>
        <?php endif; ?>
        <div class="form-group span-full">
          <label for="mutuelle_a_resilier">Mutuelle à résilier</label>
          <input type="text" id="mutuelle_a_resilier" name="mutuelle_a_resilier" value="<?= e($v('mutuelle_a_resilier')) ?>" maxlength="255" placeholder="ex : Harmonie Mutuelle, Alan…" class="<?= isset($formErrors['mutuelle_a_resilier']) ? 'input-error' : '' ?>">
          <?php if (isset($formErrors['mutuelle_a_resilier'])): ?><div class="field-error"><?= e($formErrors['mutuelle_a_resilier']) ?></div><?php endif; ?>
        </div>
        <div class="form-group">
          <label for="date_resiliation">Date de résiliation</label>
          <input type="date" id="date_resiliation" name="date_resiliation" value="<?= e($v('date_resiliation')) ?>" class="<?= isset($formErrors['date_resiliation']) ? 'input-error' : '' ?>">
          <?php if (isset($formErrors['date_resiliation'])): ?><div class="field-error"><?= e($formErrors['date_resiliation']) ?></div><?php endif; ?>
        </div>
        <div class="form-group">
          <label for="type_resiliation">Type de résiliation</label>
          <select id="type_resiliation" name="type_resiliation" class="<?= isset($formErrors['type_resiliation']) ? 'input-error' : '' ?>">
            <option value="">— Sélectionner —</option>
            <?php foreach (types_resiliation_valides() as $typeResiliation): ?>
              <option value="<?= e($typeResiliation) ?>" <?= $v('type_resiliation') === $typeResiliation ? 'selected' : '' ?>><?= e($typeResiliation) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($formErrors['type_resiliation'])): ?><div class="field-error"><?= e($formErrors['type_resiliation']) ?></div><?php endif; ?>
        </div>
        <?php
        // -----------------------------------------------------------------
        // « DL résiliation » :
        //   * Infra-annuelle / À échéance : champ calculé en lecture seule
        //     (déduit de la « Date de résiliation », voir le script) ;
        //   * Hausse tarifaire / Autres   : champ DATE saisissable
        //     manuellement par l'utilisateur (colonne dl_resiliation).
        // -----------------------------------------------------------------
        $dlManuelTypes = ['Hausse tarifaire', 'Autres'];
        $dlIsManual = in_array((string) $v('type_resiliation'), $dlManuelTypes, true);
        $dlStored = (string) $v('dl_resiliation');
        // Valeur affichée : manuelle (Y-m-d) si type manuel, sinon calcul.
        $dlDisplay = $dlStored;
        if (!$dlIsManual) {
            $dlCalc = dl_resiliation($v('date_resiliation') ?: null, $v('type_resiliation') ?: null);
            if ($dlCalc !== null) {
                $p = explode('/', $dlCalc);
                $dlDisplay = (count($p) === 3) ? ($p[2] . '-' . $p[1] . '-' . $p[0]) : '';
            } else {
                $dlDisplay = '';
            }
        }
        ?>
        <div class="form-group">
          <label for="dl_resiliation">DL résiliation<?= $dlIsManual ? '' : ' (auto)' ?></label>
          <input type="date" id="dl_resiliation" name="dl_resiliation" value="<?= e(substr($dlDisplay, 0, 10)) ?>" <?= $dlIsManual ? '' : 'readonly aria-readonly="true" class="form-readonly"' ?> <?= $dlIsManual ? '' : 'tabindex="-1"' ?> placeholder="<?= $dlIsManual ? 'JJ/MM/AAAA' : 'Calculé automatiquement' ?>">
          <?php if (isset($formErrors['dl_resiliation'])): ?><div class="field-error"><?= e($formErrors['dl_resiliation']) ?></div><?php endif; ?>
          <div class="help-text" data-dl-help><?= $dlIsManual ? 'Saisissez la date limite manuellement.' : 'Calculée automatiquement (modifiable en choisissant « Hausse tarifaire » ou « Autres »).' ?></div>
        </div>
        </div>
        <div class="form-group span-full">
          <label for="commentaire">Commentaire dossier</label>
          <textarea id="commentaire" name="commentaire"><?= e($v('commentaire')) ?></textarea>
        </div>
      </div>

      <div class="form-actions dossier-form-actions">
        <?php // Le bouton « Réactiver supervision » se trouve sur la fiche du dossier
              // (dossier_view.php). Ici il était imbriqué dans le <form> principal
              // (HTML interdit : form dans form) et n'était jamais fonctionnel. ?>
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Enregistrer les modifications' : 'Créer le dossier' ?></button>
        <a href="<?= e(APP_URL) ?>/dossiers.php" class="btn btn-outline">Annuler</a>
      </div>
        </form>
     </div>
    </div>
    <script>
    (function(){
      var etatContrat = document.getElementById('etat_contrat');
      var motifGroup = document.getElementById('motif-annulation-group');
      if (etatContrat && motifGroup) {
        var toggleMotif = function() {
          motifGroup.style.display = etatContrat.value !== 'Actif' ? '' : 'none';
        };
        etatContrat.addEventListener('change', toggleMotif);
        toggleMotif();
      }

      // « DL résiliation » :
      //   * Infra-annuelle / À échéance : calculé en lecture seule
      //     (date de résiliation moins 1 mois / 2 mois et 5 jours) ;
      //   * Hausse tarifaire / Autres   : saisie MANUELLE (champ date
      //     modifiable, valeur conservée telle quelle).
      var dateResiliation = document.getElementById('date_resiliation');
      var typeResiliation = document.getElementById('type_resiliation');
      var dlResiliation = document.getElementById('dl_resiliation');
      var dlHelp = document.querySelector('[data-dl-help]');
      var dlLabel = dlResiliation ? dlResiliation.closest('.form-group').querySelector('label') : null;
      if (dateResiliation && typeResiliation && dlResiliation) {
        var offsetParType = {
          'Infra-annuelle': { months: 1, days: 5 },
          'À échéance': { months: 2, days: 5 }
        };
        var manualTypes = ['Hausse tarifaire', 'Autres'];
        var pad = function(n) { return (n < 10 ? '0' : '') + n; };
        var toInputValue = function(d) {
          return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
        };
        var isManual = function() { return manualTypes.indexOf(typeResiliation.value) !== -1; };
        var updateDlResiliation = function() {
          var manual = isManual();
          if (manual) {
            // Saisie libre : on ne touche jamais à la valeur de l'utilisateur.
            dlResiliation.removeAttribute('readonly');
            dlResiliation.removeAttribute('aria-readonly');
            dlResiliation.removeAttribute('tabindex');
            dlResiliation.classList.remove('form-readonly');
            dlResiliation.setAttribute('placeholder', 'JJ/MM/AAAA');
            if (dlHelp) { dlHelp.textContent = 'Saisissez la date limite manuellement.'; }
            if (dlLabel) { dlLabel.textContent = 'DL résiliation'; }
            return;
          }
          var offset = offsetParType[typeResiliation.value];
          if (!dateResiliation.value || !offset) {
            dlResiliation.value = '';
          } else {
            var parts = dateResiliation.value.split('-');
            var base = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
            base.setMonth(base.getMonth() - offset.months);
            base.setDate(base.getDate() - offset.days);
            dlResiliation.value = toInputValue(base);
          }
          dlResiliation.setAttribute('readonly', 'readonly');
          dlResiliation.setAttribute('aria-readonly', 'true');
          dlResiliation.setAttribute('tabindex', '-1');
          dlResiliation.classList.add('form-readonly');
          dlResiliation.setAttribute('placeholder', 'Calculé automatiquement');
          if (dlHelp) { dlHelp.textContent = 'Calculée automatiquement (modifiable en choisissant « Hausse tarifaire » ou « Autres »).'; }
          if (dlLabel) { dlLabel.textContent = 'DL résiliation (auto)'; }
        };
        dateResiliation.addEventListener('change', updateDlResiliation);
        dateResiliation.addEventListener('input', updateDlResiliation);
        typeResiliation.addEventListener('change', updateDlResiliation);
        updateDlResiliation();
      }
    })();
    </script>
    <?php endif; ?>

    <?php $footerInContent = true; ?>
    <?php require __DIR__ . '/includes/footer.php'; ?>