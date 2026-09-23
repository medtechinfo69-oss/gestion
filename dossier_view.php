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

      <?php if ($isAdmin && $supervisionActive): ?>
      <div class="card mb-16">
       <div class="card-header"><h2>Supervision</h2></div>
       <div class="card-body flex-between" style="flex-wrap:wrap;gap:12px;">
          <div>
            <div>Ce dossier est actuellement <strong>verrouillé</strong> par la supervision.</div>
            <div class="muted" style="font-size:0.82rem;">La réactivation efface les dates de validation et d'annulation pour permettre de modifier à nouveau le dossier.</div>
          </div>
          <form method="post" action="<?= e(APP_URL) ?>/actions/dossier_reactivate.php" data-confirm="Réactiver la supervision pour ce dossier ? Ses dates de validation et d'annulation seront effacées.">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $dossier['id'] ?>">
            <button type="submit" class="btn btn-primary">Réactiver supervision</button>
          </form>
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
      <a class="btn btn-outline btn-sm" href="dossier_view.php?id=<?= (int) $id ?>#historique">Réinitialiser</a>
    </form>

    <?php if (!$historique): ?>
      <p class="muted">Aucun historique enregistré pour ces critères.</p>
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
