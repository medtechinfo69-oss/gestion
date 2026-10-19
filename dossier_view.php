<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/security_integration.php';
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

sec_log('view', 'dossier', (string) $id, 'View dossier details: ' . ($dossier['nom'] ?? ''));

$attachStmt = $db->prepare('SELECT a.*, u.nom_complet AS uploader
                             FROM dossier_attachments a JOIN users u ON u.id = a.uploaded_by
                             WHERE dossier_id = :id ORDER BY a.created_at DESC');
$attachStmt->execute(['id' => $id]);
$attachments = $attachStmt->fetchAll();

// --- Historique : filtres (jour / mois / année) + pagination ---
$histDay   = isset($_GET['hday']) && $_GET['hday'] !== '' ? (int) $_GET['hday'] : 0;
$histMonth = isset($_GET['hmonth']) && $_GET['hmonth'] !== '' ? (int) $_GET['hmonth'] : 0;
$histYear  = isset($_GET['hyear']) && $_GET['hyear'] !== '' ? (int) $_GET['hyear'] : 0;
$histPage  = max(1, (int) ($_GET['hpage'] ?? 1));
$histPerPage = 15;

$histWhere = 'WHERE h.dossier_id = :id';
$histArgs = ['id' => $id];
if ($histYear > 0)  { $histWhere .= ' AND YEAR(h.created_at) = :hy';  $histArgs['hy'] = $histYear; }
if ($histMonth > 0) { $histWhere .= ' AND MONTH(h.created_at) = :hm'; $histArgs['hm'] = $histMonth; }
if ($histDay > 0)   { $histWhere .= ' AND DAY(h.created_at) = :hd';   $histArgs['hd'] = $histDay; }

// Total pour la pagination
$histCountStmt = $db->prepare("SELECT COUNT(*) c FROM dossier_historique h $histWhere");
$histCountStmt->execute($histArgs);
$histTotal = (int) $histCountStmt->fetch()['c'];
$histPages = max(1, (int) ceil($histTotal / $histPerPage));
if ($histPage > $histPages) { $histPage = $histPages; }
$histOffset = ($histPage - 1) * $histPerPage;

$histStmt = $db->prepare("SELECT h.*, u.nom_complet AS auteur
                          FROM dossier_historique h LEFT JOIN users u ON u.id = h.user_id
                          $histWhere ORDER BY h.created_at DESC LIMIT $histPerPage OFFSET $histOffset");
$histStmt->execute($histArgs);
$historique = $histStmt->fetchAll();

$histQueryBase = 'id=' . (int) $id;

$months = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin',
    7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
];

$champLabels = [
    'vendeur_id' => 'Vendeur', 'ta_origine' => 'TA / Origine', 'p_prod' => 'P.PROD', 'date_vente' => 'Date vente',
    'civilite' => 'Civilité', 'nom' => 'Nom', 'prenom' => 'Prénom', 'mail' => 'Mail', 'telfix' => 'Tél. fixe',
    'portable' => 'Portable', 'nombre_personnes' => 'Nombre de personnes', 'adresse' => 'Adresse', 'cp' => 'CP',
    'ville' => 'Ville', 'type_signature' => 'Type de signature', 'ca_mois' => 'CA mensuel', 'ca_annuel' => 'CA annuel',
    'date_effet' => "Date d'effet", 'produit' => 'Produit', 'compagnie' => 'Compagnie', 'etat_dossier' => 'État du dossier',
    'courrier' => 'Courrier', 'commentaire' => 'Commentaire dossier', 'etat_contrat' => 'État du contrat', 'controle_qualite' => 'Contrôle qualité', 'motif_annulation' => "Motif d'annulation",
    'mutuelle_a_resilier' => 'Mutuelle à résilier', 'date_resiliation' => 'Date de résiliation',
    'type_resiliation' => 'Type de résiliation',
    'date_dossier_complet' => 'Date validation', 'date_contrat_non_actif' => "Date d'annulation",
];

$pageTitle = $dossier['nom'] . ' ' . $dossier['prenom'];
$pageSubtitle = 'Dossier #' . $dossier['id'] . ' — ' . $dossier['produit'];
$activePage = 'dossiers';
// Le bouton « Modifier » est réservé à l'admin et au rôle superviseur strict.
// Les vendeurs (même avec can_supervise = 1) sont en lecture seule : pas de
// bouton Modifier, et l'accès direct à dossier_form.php?id=… est bloqué
// côté serveur (voir dossier_form.php + actions/dossier_save.php).
$canEditDossier = $isAdmin || (($user['role'] ?? '') === 'superviseur');
$topbarActions = $canEditDossier
    ? '<a href="' . e(APP_URL) . '/dossier_form.php?id=' . (int) $dossier['id'] . '" class="btn btn-primary btn-icon-action" title="Modifier" aria-label="Modifier"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg></a>'
    : '';
// Un dossier est « sous supervision verrouillée » dès qu'une des dates de
// supervision est renseignée, ou que son contrat n'est plus « Actif » (ce qui
// bloque de toute façon le superviseur) : c'est ce qui ouvre le droit à la
// réactivation.
$supervisionActive = ($dossier['date_dossier_complet'] ?? null) !== null
    || ($dossier['date_contrat_non_actif'] ?? null) !== null
    || ($dossier['date_courrier_supervision'] ?? null) !== null
    || ($dossier['date_etat_contrat_supervision'] ?? null) !== null
    || ($dossier['date_controle_qualite_supervision'] ?? null) !== null
    || ($dossier['etat_contrat'] ?? 'Actif') !== 'Actif';

// Le bouton « Réactiver supervision » est affiché une seule fois, dans la carte
// « Supervision » plus bas (voir $supervisionActive), pour éviter un doublon.
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
      <div class="detail-item"><div class="k">Mail</div><div class="v"><?php if (!empty($dossier['mail']) && filter_var($dossier['mail'], FILTER_VALIDATE_EMAIL)): ?><a href="#" class="client-mail-link" data-client-mail-open title="Envoyer un e-mail au client"><?= e($dossier['mail']) ?></a><?php else: ?>—<?php endif; ?></div></div>
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
      <div class="detail-item"><div class="k">Mutuelle à résilier</div><div class="v"><?= e($dossier['mutuelle_a_resilier'] ?? '') ?: '—' ?></div></div>
      <div class="detail-item"><div class="k">Date de résiliation</div><div class="v"><?= !empty($dossier['date_resiliation']) ? format_date($dossier['date_resiliation']) : '—' ?></div></div>
      <div class="detail-item"><div class="k">Type de résiliation</div><div class="v"><?= e($dossier['type_resiliation'] ?? '') ?: '—' ?></div></div>
      <?php $dlResiliation = dl_resiliation($dossier['date_resiliation'] ?? null, $dossier['type_resiliation'] ?? null, $dossier['dl_resiliation'] ?? null); ?>
      <div class="detail-item"><div class="k">DL résiliation</div><div class="v"><?= $dlResiliation !== null ? e($dlResiliation) : '—' ?></div></div>
      <div class="detail-item"><div class="k">Commentaire</div><div class="v"><?= $dossier['commentaire'] ? nl2br(e($dossier['commentaire'])) : '—' ?></div></div>
            <?php if ($dossier['motif_annulation']): ?>
            <div class="detail-item detail-item--wide"><div class="k">Motif d'annulation</div><div class="v"><?= e($dossier['motif_annulation']) ?></div></div>
            <?php endif; ?>
          </div>
       </div>
      </div>

      <?php if ($isAdmin && $supervisionActive): ?>
      <div class="card mb-16">
       <div class="card-header"><h2>Supervision</h2></div>
       <div class="card-body">
          <div>
            <div>Ce dossier est actuellement <strong>verrouillé</strong> par la supervision.</div>
            <div class="muted" style="font-size:0.82rem;">Réactivez uniquement la section souhaitée : les dates de validation / annulation correspondantes seront effacées.</div>
          </div>
          <div class="flex gap-8" style="flex-wrap:wrap;margin-top:14px;">
            <form method="post" action="<?= e(APP_URL) ?>/actions/dossier_reactivate.php" style="display:inline;" data-confirm="Réactiver uniquement la section Courrier ?">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $dossier['id'] ?>">
              <input type="hidden" name="scope" value="courrier">
              <button type="submit" class="btn btn-primary btn-sm">Réactiver Courrier</button>
            </form>
            <form method="post" action="<?= e(APP_URL) ?>/actions/dossier_reactivate.php" style="display:inline;" data-confirm="Réactiver uniquement la section État du contrat ?">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $dossier['id'] ?>">
              <input type="hidden" name="scope" value="etat_contrat">
              <button type="submit" class="btn btn-primary btn-sm">Réactiver État du contrat</button>
            </form>
            <form method="post" action="<?= e(APP_URL) ?>/actions/dossier_reactivate.php" style="display:inline;" data-confirm="Réactiver uniquement la section Contrôle qualité ?">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $dossier['id'] ?>">
              <input type="hidden" name="scope" value="controle_qualite">
              <button type="submit" class="btn btn-primary btn-sm">Réactiver Contrôle qualité</button>
            </form>
            <form method="post" action="<?= e(APP_URL) ?>/actions/dossier_reactivate.php" style="display:inline;" data-confirm="Réactiver TOUTES les sections de supervision ?">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $dossier['id'] ?>">
              <input type="hidden" name="scope" value="all">
              <button type="submit" class="btn btn-outline btn-sm">Réactiver tout</button>
            </form>
          </div>
       </div>
      </div>
      <?php endif; ?>

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
            <span class="flex gap-8 row-actions">
              <?php
              // Aperçu dans une modale (PDF, images, audio). Le type d'affichage
              // est calculé côté serveur : la modale se contente de l'utiliser.
              $previewKind = attachment_preview_kind($a['type_mime']);
              $previewUrl = e(APP_URL) . '/actions/attachment_download.php?id=' . (int) $a['id'] . '&preview=1';
              ?>
              <button type="button"
                      class="btn btn-outline btn-sm btn-icon-action"
                      title="Aperçu"
                      aria-label="Aperçu de la pièce jointe"
                      data-attachment-preview
                      data-attachment-name="<?= e($a['nom_original']) ?>"
                      data-attachment-kind="<?= e($previewKind) ?>"
                      data-attachment-type="<?= e(attachment_mime_label($a['type_mime'])) ?>"
                      data-attachment-url="<?= $previewUrl ?>"
                      data-attachment-download="<?= e(APP_URL) ?>/actions/attachment_download.php?id=<?= (int) $a['id'] ?>"
                      data-attachment-can-download="<?= $isSuperviseur ? '0' : '1' ?>">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
              <?php if (!$isSuperviseur): ?>
              <a href="<?= e(APP_URL) ?>/actions/attachment_download.php?id=<?= (int) $a['id'] ?>" class="btn btn-outline btn-sm btn-icon-action" title="Télécharger" aria-label="Télécharger"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg></a>
              <?php endif; ?>
              <?php if ($isAdmin): ?>
              <form action="<?= e(APP_URL) ?>/actions/attachment_delete.php" method="post" style="margin:0;" data-confirm="Supprimer cette pièce jointe ?">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <input type="hidden" name="dossier_id" value="<?= (int) $dossier['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm btn-icon-action" title="Supprimer" aria-label="Supprimer"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg></button>
              </form>
              <?php endif; ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="muted">Aucune pièce jointe pour ce dossier.</p>
    <?php endif; ?>

    <?php if ($isAdmin || $isSuperviseur): ?><form action="<?= e(APP_URL) ?>/actions/attachment_upload.php" method="post" enctype="multipart/form-data" data-upload-max-bytes="<?= max_import_size_bytes($db) ?>" style="margin-top:14px;">
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

<div class="card" id="historique">
 <div class="card-header flex-between">
    <h2>Historique</h2>
    <span class="muted"><?= $histTotal ?> événement(s)</span>
 </div>
 <div class="card-body">
    <form class="filter-card" method="get" style="border:1px solid var(--color-line);border-radius:8px;padding:12px;margin-bottom:16px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
      <input type="hidden" name="id" value="<?= (int) $id ?>">
      <div style="display:flex;flex-direction:column;gap:4px;">
        <label class="form-label" style="margin:0;font-size:0.78rem;">Jour</label>
        <input type="number" class="form-control" name="hday" min="1" max="31" value="<?= $histDay > 0 ? $histDay : '' ?>" placeholder="—" style="width:80px;">
      </div>
      <div style="display:flex;flex-direction:column;gap:4px;">
        <label class="form-label" style="margin:0;font-size:0.78rem;">Mois</label>
        <select name="hmonth" class="form-select" style="width:auto;">
          <option value="">—</option>
          <?php for ($mi = 1; $mi <= 12; $mi++): ?>
            <option value="<?= $mi ?>" <?= $histMonth === $mi ? 'selected' : '' ?>><?= e($months[$mi] ?? $mi) ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div style="display:flex;flex-direction:column;gap:4px;">
        <label class="form-label" style="margin:0;font-size:0.78rem;">Année</label>
        <input type="number" class="form-control" name="hyear" min="2000" max="2100" value="<?= $histYear > 0 ? $histYear : '' ?>" placeholder="—" style="width:100px;">
      </div>
      <button type="submit" class="btn btn-light">Filtrer</button>
      <a class="btn btn-outline btn-sm btn-reset" href="dossier_view.php?id=<?= (int) $id ?>#historique" title="Réinitialiser les filtres" aria-label="Réinitialiser les filtres"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/></svg></a>
    </form>

    <?php if (!$historique): ?>
      <p class="muted">Aucun historique enregistré pour ces critères.</p>
    <?php else: ?>
      <ul class="timeline">
        <?php foreach ($historique as $h): ?>
          <li>
            <div class="ts"><?= format_datetime_tn($h['created_at'], 'd/m/Y à H:i') ?> &middot; <?= e($h['auteur'] ?? 'Système') ?></div>
            <?php if ($h['action'] === 'creation'): ?>
              Création du dossier.
            <?php elseif (in_array($h['action'], ['ajout_piece_jointe', 'suppression_piece_jointe'], true)): ?>
              <?= $h['action'] === 'ajout_piece_jointe' ? 'Ajout d’une pièce jointe' : 'Suppression d’une pièce jointe' ?>
              <?php $attachmentName = $h['action'] === 'ajout_piece_jointe' ? $h['nouvelle_valeur'] : $h['ancienne_valeur']; ?>
              <?php if ($attachmentName !== null && $attachmentName !== ''): ?> « <?= e(mb_strimwidth((string) $attachmentName, 0, 100, '…')) ?> »<?php endif; ?>.
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

      <?php if ($histPages > 1): ?>
      <nav class="pagination" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:16px;">
        <?php if ($histPage > 1): ?>
          <a class="btn btn-outline btn-sm" href="dossier_view.php?<?= e($histQueryBase) ?>&hpage=<?= $histPage - 1 ?><?= $histDay ? '&hday='.$histDay : '' ?><?= $histMonth ? '&hmonth='.$histMonth : '' ?><?= $histYear ? '&hyear='.$histYear : '' ?>#historique">&laquo; Précédent</a>
        <?php endif; ?>
        <?php for ($hp = 1; $hp <= $histPages; $hp++): ?>
          <?php if ($hp === $histPage): ?>
            <span class="btn btn-primary btn-sm" style="cursor:default;"><?= $hp ?></span>
          <?php else: ?>
            <a class="btn btn-outline btn-sm" href="dossier_view.php?<?= e($histQueryBase) ?>&hpage=<?= $hp ?><?= $histDay ? '&hday='.$histDay : '' ?><?= $histMonth ? '&hmonth='.$histMonth : '' ?><?= $histYear ? '&hyear='.$histYear : '' ?>#historique"><?= $hp ?></a>
          <?php endif; ?>
        <?php endfor; ?>
        <?php if ($histPage < $histPages): ?>
          <a class="btn btn-outline btn-sm" href="dossier_view.php?<?= e($histQueryBase) ?>&hpage=<?= $histPage + 1 ?><?= $histDay ? '&hday='.$histDay : '' ?><?= $histMonth ? '&hmonth='.$histMonth : '' ?><?= $histYear ? '&hyear='.$histYear : '' ?>#historique">Suivant &raquo;</a>
        <?php endif; ?>
      </nav>
      <?php endif; ?>
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
      <button type="submit" class="btn btn-danger btn-icon-action" title="Supprimer le dossier" aria-label="Supprimer le dossier"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg></button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php
// Popup « Envoyer un e-mail au client » : objet + message pré-remplis avec
// les SEULS courriers non cochés (ex. 2 pièces manquantes => seules ces 2
// pièces listées). Envoi réel via la carte SMTP (actions/dossier_mail_client.php).
$clientMailOk = !empty($dossier['mail']) && filter_var($dossier['mail'], FILTER_VALIDATE_EMAIL);
$clientManquants = courrier_manquants($dossier['courrier'] ?? '');
$clientMailTo = $clientMailOk ? (string) $dossier['mail'] : '';
$clientMailSubject = client_mail_subject_missing($clientManquants);
$clientMailBody = client_mail_body_missing($dossier, $clientManquants);
$smtpOk = function_exists('app_mail_configured') ? app_mail_configured() : false;
?>
<?php if ($clientMailOk): ?>
<div id="client-mail-modal" class="client-mail-modal" style="display:none;" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="client-mail-title">
  <div class="client-mail-dialog">
    <div class="client-mail-head">
      <h2 id="client-mail-title">E-mail au client</h2>
      <button type="button" class="client-mail-close" data-client-mail-close aria-label="Fermer">&times;</button>
    </div>
    <div class="client-mail-summary">
      <span class="client-mail-to"><?= e($clientMailTo) ?></span>
      <?php if ($clientManquants): ?>
        <span class="client-mail-count"><?= count($clientManquants) ?> pièce(s) manquante(s)</span>
      <?php else: ?>
        <span class="client-mail-count ok">Dossier complet ✓</span>
      <?php endif; ?>
    </div>
    <?php if ($clientManquants): ?>
    <ul class="client-mail-missing">
      <?php foreach ($clientManquants as $piece): ?><li><?= e($piece) ?></li><?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if (!$smtpOk): ?>
    <p class="client-mail-warn">⚠️ Envoi SMTP non configuré : demandez à l'administrateur de renseigner la carte E-mail (SMTP) des Paramètres.</p>
    <?php endif; ?>
    <form action="<?= e(APP_URL) ?>/actions/dossier_mail_client.php" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="dossier_id" value="<?= (int) $dossier['id'] ?>">
      <input type="hidden" name="to" value="<?= e($clientMailTo) ?>">
      <div class="form-group">
        <label for="client-mail-subject">Objet</label>
        <input type="text" id="client-mail-subject" name="subject" value="<?= e($clientMailSubject) ?>" maxlength="200" required>
      </div>
      <div class="form-group">
        <label for="client-mail-body">Message</label>
        <textarea id="client-mail-body" name="body" rows="12" maxlength="10000" required><?= e($clientMailBody) ?></textarea>
      </div>
      <div class="client-mail-actions">
        <button type="button" class="btn btn-outline" data-client-mail-close>Annuler</button>
        <button type="submit" class="btn btn-primary">Envoyer</button>
      </div>
    </form>
  </div>
</div>
<script>
(function () {
  var modal = document.getElementById('client-mail-modal');
  if (!modal) return;
  function open(e) { if (e) e.preventDefault(); modal.style.display = 'flex'; modal.setAttribute('aria-hidden', 'false'); }
  function close() { modal.style.display = 'none'; modal.setAttribute('aria-hidden', 'true'); }
  document.querySelectorAll('[data-client-mail-open]').forEach(function (a) { a.addEventListener('click', open); });
  modal.querySelectorAll('[data-client-mail-close]').forEach(function (b) { b.addEventListener('click', close); });
  modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.style.display !== 'none') close(); });
})();
</script>
<?php endif; ?>

<!-- Aperçu d'une pièce jointe : modale affichée par assets/js/app.js
     (initAttachmentPreview). Disponible pour le vendeur, le superviseur
     et l'administrateur ; le contenu est construit à partir des
     attributs data-* du bouton « Aperçu » de chaque fichier. -->
<div id="attachment-preview-modal" class="attachment-preview-modal" style="display:none;" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="attachment-preview-title">
  <div class="attachment-preview-dialog">
    <div class="attachment-preview-head">
      <h2 id="attachment-preview-title">Aperçu</h2>
      <button type="button" class="attachment-preview-close" data-attachment-preview-close aria-label="Fermer l'aperçu">&times;</button>
    </div>
    <div class="attachment-preview-meta">
      <span class="attachment-preview-name" data-attachment-preview-name></span>
      <span class="muted" data-attachment-preview-type></span>
    </div>
    <div class="attachment-preview-body" data-attachment-preview-body></div>
    <div class="attachment-preview-actions">
      <a class="btn btn-outline btn-sm" data-attachment-preview-download hidden>
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
        Télécharger
      </a>
      <button type="button" class="btn btn-primary btn-sm" data-attachment-preview-close>Fermer</button>
    </div>
  </div>
</div>

<?php if ($isAdmin): ?>
<script>
(function() {
  var userName = <?= json_encode($user['nom_complet'] ?? 'Unknown') ?>;
  var userEmail = <?= json_encode($user['email'] ?? '') ?>;
  var pageUrl = window.location.href;
  var csrfToken = <?= json_encode(csrf_token()) ?>;

  var style = document.createElement('style');
  style.textContent = 'body { user-select: none !important; } *:not(input):not(textarea) { user-select: none !important; -webkit-user-select: none !important; }';
  document.head.appendChild(style);

  function reportSecurityEvent(type, description) {
    fetch('actions/security_alert.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify({
        type: type,
        description: description,
        user: userName,
        email: userEmail,
        page: pageUrl,
        timestamp: new Date().toISOString()
      })
    }).catch(function() {});
  }

  document.addEventListener('keydown', function(e) {
    if (e.key === 'PrintScreen') {
      reportSecurityEvent('print_screen', 'PrintScreen key pressed');
    }
    if (e.ctrlKey || e.metaKey) {
      switch(e.key.toLowerCase()) {
        case 'p': e.preventDefault(); reportSecurityEvent('print_attempt', 'Ctrl+P blocked'); break;
        case 's': e.preventDefault(); reportSecurityEvent('save_attempt', 'Ctrl+S blocked'); break;
        case 'u': e.preventDefault(); reportSecurityEvent('view_source_attempt', 'Ctrl+U blocked'); break;
      }
    }
    if (e.key === 'F12') { e.preventDefault(); reportSecurityEvent('dev_tools', 'F12 blocked'); }
  });

  document.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    reportSecurityEvent('right_click', 'Right-click blocked');
  });

  document.addEventListener('dragstart', function(e) {
    e.preventDefault();
    reportSecurityEvent('drag_attempt', 'Drag start blocked');
  });

  document.addEventListener('selectstart', function(e) {
    if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA') {
      e.preventDefault();
    }
  });

  document.addEventListener('copy', function(e) {
    if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA') {
      e.preventDefault();
      reportSecurityEvent('copy_attempt', 'Copy blocked');
    }
  });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
