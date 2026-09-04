<?php
require_once __DIR__ . '/includes/init.php';
require_login();

$user = current_user();
$isAdmin = is_admin();
$isSuperviseur = is_superviseur();

$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
if (!$id) {
    redirect('dossiers.php');
}

$stmt = $db->prepare('SELECT d.*, u.nom_complet AS vendeur_nom, u.id AS vendeur_id_ref
                       FROM dossiers d JOIN users u ON u.id = d.vendeur_id WHERE d.id = :id');
$stmt->execute(['id' => $id]);
$dossier = $stmt->fetch();

if (!$dossier) {
    set_flash('error', 'Dossier introuvable.');
    redirect('dossiers.php');
}

// Un vendeur ne peut consulter que ses propres dossiers
if (!$isAdmin && !$isSuperviseur && (int) $dossier['vendeur_id'] !== (int) $user['id']) {
    http_response_code(403);
    set_flash('error', 'Vous n’avez pas accès à ce dossier.');
    redirect('dossiers.php');
}

$attachStmt = $db->prepare('SELECT a.*, u.nom_complet AS uploader
                             FROM dossier_attachments a JOIN users u ON u.id = a.uploaded_by
                             WHERE dossier_id = :id ORDER BY a.created_at DESC');
$attachStmt->execute(['id' => $id]);
$attachments = $attachStmt->fetchAll();

$histStmt = $db->prepare('SELECT h.*, u.nom_complet AS auteur
                           FROM dossier_historique h LEFT JOIN users u ON u.id = h.user_id
                           WHERE dossier_id = :id ORDER BY h.created_at DESC LIMIT 30');
$histStmt->execute(['id' => $id]);
$historique = $histStmt->fetchAll();

$champLabels = [
    'vendeur_id' => 'Vendeur', 'ta_origine' => 'TA / Origine', 'p_prod' => 'P.PROD', 'date_vente' => 'Date vente',
    'civilite' => 'Civilité', 'nom' => 'Nom', 'prenom' => 'Prénom', 'mail' => 'Mail', 'telfix' => 'Tél. fixe',
    'portable' => 'Portable', 'nombre_personnes' => 'Nombre de personnes', 'adresse' => 'Adresse', 'cp' => 'CP',
    'ville' => 'Ville', 'type_signature' => 'Type de signature', 'ca_mois' => 'CA mensuel', 'ca_annuel' => 'CA annuel',
    'date_effet' => "Date d'effet", 'produit' => 'Produit', 'compagnie' => 'Compagnie', 'etat_dossier' => 'État du dossier',
    'courrier' => 'Courrier', 'commentaire' => 'Commentaire dossier', 'etat_contrat' => 'État du contrat', 'controle_qualite' => 'Contrôle qualité', 'motif_annulation' => "Motif d'annulation",
    'date_dossier_complet' => 'Date validation', 'date_contrat_non_actif' => "Date d'annulation",
];

$pageTitle = $dossier['nom'] . ' ' . $dossier['prenom'];
$pageSubtitle = 'Dossier #' . $dossier['id'] . ' — ' . $dossier['produit'];
$activePage = 'dossiers';
$topbarActions = ($isAdmin || $isSuperviseur)
    ? '<a href="' . e(APP_URL) . '/dossier_form.php?id=' . (int) $dossier['id'] . '" class="btn btn-primary">Modifier</a>'
    : '';
if ($isAdmin && ($dossier['date_dossier_complet'] !== null
  || $dossier['date_contrat_non_actif'] !== null
  || ($dossier['date_courrier_supervision'] ?? null) !== null
  || ($dossier['date_etat_contrat_supervision'] ?? null) !== null
  || ($dossier['date_controle_qualite_supervision'] ?? null) !== null)) {
    $topbarActions .= '<form method="post" action="' . e(APP_URL) . '/actions/dossier_reactivate.php" style="display:inline;">'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . (int) $dossier['id'] . '">'
        . '<button type="submit" class="btn btn-outline">Réactiver supervision</button>'
        . '</form>';
}
require __DIR__ . '/includes/header.php';
?>

<div class="flex gap-12 mb-16" style="align-items:center;">
  <?= badge_etat($dossier['etat_dossier']) ?>
  <span class="muted">Créé le <?= format_date(substr($dossier['created_at'], 0, 10)) ?></span>
  <?php if ($dossier['updated_at'] !== $dossier['created_at']): ?>
    <span class="muted">&middot; modifié le <?= format_date(substr($dossier['updated_at'], 0, 10)) ?></span>
  <?php endif; ?>
</div>

<div class="card mb-16">
  <div class="card-header"><h2>Informations générales</h2></div>
  <div class="card-body">
    <div class="detail-grid">
      <div class="detail-item"><div class="k">Vendeur</div><div class="v"><?= e($dossier['vendeur_nom']) ?></div></div>
      <div class="detail-item"><div class="k">TA / Origine</div><div class="v"><?= e($dossier['ta_origine']) ?: '—' ?></div></div>
      <div class="detail-item"><div class="k">P.PROD</div><div class="v"><?= e($dossier['p_prod']) ?: '—' ?></div></div>
      <div class="detail-item"><div class="k">Date de vente</div><div class="v"><?= format_date($dossier['date_vente']) ?></div></div>
      <div class="detail-item"><div class="k">Date d'effet</div><div class="v"><?= format_date($dossier['date_effet']) ?></div></div>
      <div class="detail-item"><div class="k">Type de signature</div><div class="v"><?= e($dossier['type_signature']) ?></div></div>
    </div>
  </div>
</div>

<div class="card mb-16">
  <div class="card-header"><h2>Assuré</h2></div>
  <div class="card-body">
    <div class="detail-grid">
      <div class="detail-item"><div class="k">Civilité</div><div class="v"><?= e($dossier['civilite']) ?></div></div>
      <div class="detail-item"><div class="k">Nom</div><div class="v"><?= e($dossier['nom']) ?></div></div>
      <div class="detail-item"><div class="k">Prénom</div><div class="v"><?= e($dossier['prenom']) ?></div></div>
      <div class="detail-item"><div class="k">Mail</div><div class="v"><?= $dossier['mail'] ? '<a href="mailto:' . e($dossier['mail']) . '">' . e($dossier['mail']) . '</a>' : '—' ?></div></div>
      <div class="detail-item"><div class="k">Tél. fixe</div><div class="v"><?= e($dossier['telfix']) ?: '—' ?></div></div>
      <div class="detail-item"><div class="k">Portable</div><div class="v"><?= e($dossier['portable']) ?></div></div>
      <div class="detail-item"><div class="k">Nombre de personnes</div><div class="v"><?= (int) $dossier['nombre_personnes'] ?></div></div>
      <div class="detail-item"><div class="k">Date(s) naissance</div><div class="v"><?= e($dossier['date_naissance_assure']) ?: '—' ?></div></div>
      <div class="detail-item"><div class="k">Âge assuré principal</div><div class="v"><?= e($dossier['age_assure_principal']) ?: '—' ?></div></div>
      <div class="detail-item"><div class="k">Adresse</div><div class="v"><?= e($dossier['adresse']) ?><br><?= e($dossier['cp']) ?> <?= e($dossier['ville']) ?></div></div>
    </div>
  </div>
</div>

<div class="card mb-16">
  <div class="card-header"><h2>Contrat</h2></div>
  <div class="card-body">
    <div class="detail-grid detail-grid--contract">
      <div class="detail-item"><div class="k">Produit</div><div class="v"><?= e($dossier['produit']) ?></div></div>
      <div class="detail-item"><div class="k">Compagnie</div><div class="v"><?= e($dossier['compagnie']) ?></div></div>
      <div class="detail-item"><div class="k">CA mensuel</div><div class="v"><?= format_montant((float) $dossier['ca_mois']) ?></div></div>
      <div class="detail-item detail-item--wide"><div class="k">CA annuel</div><div class="v"><strong><?= format_montant((float) $dossier['ca_annuel']) ?></strong></div></div>

      <div class="detail-item"><div class="k">Courrier</div><div class="v"><?= e(implode(', ', courrier_values($dossier['courrier'] ?? ''))) ?: '—' ?></div></div>
      <div class="detail-item"><div class="k">État du dossier</div><div class="v"><?= badge_etat($dossier['etat_dossier']) ?></div></div>
      <div class="detail-item"><div class="k">Date validation</div><div class="v"><?= !empty($dossier['date_dossier_complet']) ? format_date($dossier['date_dossier_complet']) : '—' ?></div></div>

      <div class="detail-item"><div class="k">État du contrat</div><div class="v"><?= badge_etat_contrat($dossier['etat_contrat']) ?></div></div>
      <div class="detail-item"><div class="k">Date d'annulation</div><div class="v"><?= !empty($dossier['date_contrat_non_actif']) ? format_date($dossier['date_contrat_non_actif']) : '—' ?></div></div>

      <div class="detail-item"><div class="k">Contrôle qualité</div><div class="v"><?= e($dossier['controle_qualite'] ?? '') ?: '—' ?></div></div>
      <div class="detail-item"><div class="k">Commentaire</div><div class="v"><?= $dossier['commentaire'] ? nl2br(e($dossier['commentaire'])) : '—' ?></div></div>
      <?php if ($dossier['motif_annulation']): ?>
      <div class="detail-item detail-item--wide"><div class="k">Motif d'annulation</div><div class="v"><?= e($dossier['motif_annulation']) ?></div></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card mb-16">
  <div class="card-header">
    <h2>Pièces jointes</h2>
  </div>
  <div class="card-body">
    <?php if ($attachments): ?>
      <ul class="attachment-list">
        <?php foreach ($attachments as $a): ?>
          <li>
            <span>
              <?php $isAudio = strpos((string) $a['type_mime'], 'audio/') === 0; ?>
              <?= $isAudio ? '&#127925;' : '&#128206;' ?> <?= e($a['nom_original']) ?>
              <span class="muted">(<?= round($a['taille'] / 1024) ?> Ko &middot; ajouté par <?= e($a['uploader']) ?> le <?= format_date(substr($a['created_at'],0,10)) ?>)</span>
              <?php if ($isAudio): ?>
                <audio class="attachment-player" controls controlsList="nodownload" preload="metadata" src="<?= e(APP_URL) ?>/actions/attachment_download.php?id=<?= (int) $a['id'] ?>&inline=1">
                  Votre navigateur ne peut pas lire cet enregistrement.
                </audio>
              <?php endif; ?>
            </span>
            <span class="flex gap-8">
              <?php if (!$isSuperviseur): ?>
              <a href="<?= e(APP_URL) ?>/actions/attachment_download.php?id=<?= (int) $a['id'] ?>" class="btn btn-outline btn-sm">Télécharger</a>
              <?php endif; ?>
              <?php if ($isAdmin): ?>
              <form action="<?= e(APP_URL) ?>/actions/attachment_delete.php" method="post" style="margin:0;" data-confirm="Supprimer cette pièce jointe ?">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <input type="hidden" name="dossier_id" value="<?= (int) $dossier['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">Supprimer</button>
              </form>
              <?php endif; ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="muted">Aucune pièce jointe pour ce dossier.</p>
    <?php endif; ?>

    <?php if ($isAdmin || $isSuperviseur): ?><form action="<?= e(APP_URL) ?>/actions/attachment_upload.php" method="post" enctype="multipart/form-data" style="margin-top:14px;">
      <?= csrf_field() ?>
      <input type="hidden" name="dossier_id" value="<?= (int) $dossier['id'] ?>">
      <div class="flex gap-8" style="align-items:center;flex-wrap:wrap;">
        <input type="file" name="fichier" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.mp3,.wav,.ogg,.m4a,.aac,.flac,.webm,.opus,.wma">
        <button type="submit" class="btn btn-outline btn-sm">Ajouter un document ou un audio</button>
      </div>
      <div class="help-text" style="margin-top:6px;">Formats acceptés : PDF, JPG, PNG, DOC, DOCX, MP3, WAV, OGG, M4A, AAC, FLAC, WEBM, OPUS, WMA — <?= (int) (max_import_size_bytes($db) / 1024 / 1024) ?> Mo maximum.</div>
    </form><?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-header"><h2>Historique</h2></div>
  <div class="card-body">
    <?php if (!$historique): ?>
      <p class="muted">Aucun historique enregistré.</p>
    <?php else: ?>
      <ul class="timeline">
        <?php foreach ($historique as $h): ?>
          <li>
            <div class="ts"><?= date('d/m/Y à H:i', strtotime($h['created_at'])) ?> &middot; <?= e($h['auteur'] ?? 'Système') ?></div>
            <?php if ($h['action'] === 'creation'): ?>
              Création du dossier.
            <?php elseif ($h['champ']): ?>
              Modification de « <?= e($champLabels[$h['champ']] ?? $h['champ']) ?> » :
              <span class="muted"><?= e(mb_strimwidth((string) $h['ancienne_valeur'], 0, 60, '…')) ?: '(vide)' ?></span>
              &rarr; <?= e(mb_strimwidth((string) $h['nouvelle_valeur'], 0, 60, '…')) ?>
            <?php else: ?>
              <?= e($h['action']) ?>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>

<?php if ($isAdmin): ?>
<div class="card mb-16" style="margin-top:16px;border-color:var(--color-annule-line);">
  <div class="card-body flex-between">
    <div>
      <strong>Supprimer ce dossier</strong>
      <div class="muted" style="font-size:0.82rem;">Action définitive et irréversible.</div>
    </div>
    <form action="<?= e(APP_URL) ?>/actions/dossier_delete.php" method="post" data-confirm="Supprimer définitivement ce dossier et ses pièces jointes ?">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $dossier['id'] ?>">
      <button type="submit" class="btn btn-danger">Supprimer le dossier</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
