<?php
require_once __DIR__ . '/includes/init.php';
require_admin();

$pageTitle = 'Notifications de supervision';
$pageSubtitle = 'Journal anti-manipulation — chaque action d’un superviseur';
$activePage = 'notifications';

// Les superviseurs ne doivent ni lire ni modifier ce journal.
if (!is_admin()) {
    http_response_code(403);
    set_flash('error', 'Accès réservé aux administrateurs.');
    redirect('dashboard.php');
}

$filterActor = clean_str($_GET['actor'] ?? '');
$filterAction = clean_str($_GET['action'] ?? '');
$allowedActions = ['create', 'update', 'delete', 'import', 'upload', 'salary', 'employee', 'restore', 'reactivate'];
if ($filterAction !== '' && !in_array($filterAction, $allowedActions, true)) {
    $filterAction = '';
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$history = admin_notifications_history($db, $page, $perPage, $filterActor, $filterAction);

// Nombre de notifications non lues. On passe par la fonction du module
// (meme source que la cloche de l'en-tete) : l'ancienne version relanait ici
// un COUNT(*) identique a celui du header, soit une requete en double.
$unread = admin_notifications_unread_count($db);
try {
  $notificationsTotal = (int) $db->query('SELECT COUNT(*) FROM admin_notifications')->fetchColumn();
} catch (Throwable $e) {
  $notificationsTotal = 0;
}

$topbarActions = '';
if ($unread > 0) {
    $topbarActions = '<form action="' . e(APP_URL) . '/actions/notification_read.php" method="post" style="display:inline;">'
        . csrf_field()
        . '<input type="hidden" name="action" value="read_all">'
        . '<button type="submit" class="btn btn-secondary">Tout marquer comme lu (' . (int) $unread . ')</button>'
        . '</form>';
}

/** Rend le détail d'une notification : transforme « Champs modifiés : a, b » en pastilles. */
function notif_render_detail(?string $detail): string
{
    $detail = trim((string) $detail);
    if ($detail === '') {
        return '';
    }
    // Retire le préfixe redondant « Modification (section supervision) du dossier #N. »
    // (déjà porté par le titre de la notification).
    $detail = preg_replace('/^Modification \([^)]*\) du dossier #\d+\.\s*/u', '', $detail);
    $detail = trim((string) $detail);
    if ($detail === '') {
        return '';
    }
    if (preg_match('/^(.*?)Champs modifiés : (.+?)\\.$/us', $detail, $m)) {
        $head = trim((string) $m[1]);
        $fields = array_values(array_filter(
            array_map('trim', explode(',', (string) $m[2])),
            static fn (string $f): bool => $f !== ''
        ));
        $chips = '';
        foreach ($fields as $f) {
            $chips .= '<span class="detail-chip">' . e($f) . '</span>';
        }
        $html = '';
        if ($head !== '') {
            $html .= '<span class="muted" style="font-size:0.78rem;">' . e($head) . '</span>';
        }
        return $html . '<span class="detail-chips">' . $chips . '</span>';
    }
    return '<span class="muted" style="font-size:0.78rem;white-space:pre-wrap;">' . e($detail) . '</span>';
}

/** Détail « nettoyé » en texte brut pour la popup Voir (sans le préfixe redondant). */
function notif_clean_detail(?string $detail): string
{
    $detail = trim((string) $detail);
    if ($detail === '') {
        return '';
    }
    $detail = preg_replace('/^Modification \([^)]*\) du dossier #\d+\.\s*/u', '', $detail);
    return trim((string) $detail);
}

require __DIR__ . '/includes/header.php';
?>

<div class="card notif-page">
  <div class="notif-page-head">
    <div>
      <h2>Activité des superviseurs</h2>
      <div class="muted">Journal des actions des superviseurs. Sélectionnez une ou plusieurs notifications pour les supprimer, ou videz tout le journal.</div>
    </div>
    <span class="muted"><?= (int) $history['total'] ?> notification(s)</span>
  </div>

  <form method="get" class="filter-bar">
    <div class="filter-field">
      <label>Superviseur</label>
      <input type="text" name="actor" class="form-control" value="<?= e($filterActor) ?>" placeholder="Nom d’utilisateur">
    </div>
    <div class="filter-field filter-field--action">
      <label>Action</label>
      <select name="action" class="form-control">
        <option value="">Toutes</option>
        <?php foreach ($allowedActions as $a): ?>
          <option value="<?= e($a) ?>" <?= $filterAction === $a ? 'selected' : '' ?>><?= e(admin_notification_action_label($a)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-actions">
      <button type="submit" class="btn btn-primary">Filtrer</button>
      <a href="notifications.php" class="btn btn-secondary btn-reset" title="Réinitialiser les filtres" aria-label="Réinitialiser les filtres"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/></svg></a>
    </div>
  </form>

  <?php if ($history['items'] || $notificationsTotal > 0): ?>
  <div class="bulk-actions notif-bulk-actions" data-notif-bulk-actions>
    <?php if ($history['items']): ?>
    <span class="bulk-count" data-notif-selection-count>0 notification sélectionnée</span>
    <?php endif; ?>
    <div class="notif-bulk-buttons">
      <?php if ($history['items']): ?>
      <form id="notif-bulk-delete-form" action="<?= e(APP_URL) ?>/actions/notification_bulk_delete.php" method="post" data-confirm="Supprimer définitivement les notifications sélectionnées ?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="selected">
        <button type="submit" class="btn btn-danger btn-sm" data-notif-delete-selected disabled>Supprimer la sélection</button>
      </form>
      <?php endif; ?>
    <?php if ($notificationsTotal > 0): ?>
      <form action="<?= e(APP_URL) ?>/actions/notification_bulk_delete.php" method="post" data-confirm="Supprimer définitivement toutes les notifications du journal ? Cette action concerne aussi les autres pages et filtres.">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="all">
        <button type="submit" class="btn btn-danger btn-sm">Tout supprimer</button>
      </form>
    <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="table-card">
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <?php if ($history['items']): ?><th class="selection-cell"><input type="checkbox" data-notif-select-all aria-label="Sélectionner toutes les notifications de cette page"></th><?php endif; ?>
            <th>Date/Heure</th>
            <th>Superviseur</th>
            <th>Action</th>
            <th>Détail</th>
            <th>IP</th>
            <th>État</th>
            <th class="text-center">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history['items'] as $n): ?>
          <?php /* Horodatage en heure de Tunis : la session MySQL enregistre
                     déjà en Africa/Tunis (voir config/database.php), on ne
                     fait donc qu'un formatage, sans conversion. */ ?>
          <?php $notifDate = format_datetime_tn($n['created_at'] ?? null, 'd/m/Y H:i:s'); ?>
          <tr class="data-row<?= empty($n['is_read']) ? ' notif-row-unread' : '' ?>">
              <td class="selection-cell" data-label="Sélection"><input type="checkbox" name="notification_ids[]" value="<?= (int) $n['id'] ?>" form="notif-bulk-delete-form" data-notif-select aria-label="Sélectionner la notification <?= (int) $n['id'] ?>"></td>
              <td class="nowrap" data-label="Date / heure"><?= e($notifDate) ?></td>
              <td data-label="Superviseur"><strong><?= e((string) ($n['actor_username'] ?? '')) ?></strong></td>
              <td>
                <span class="action-pill action-pill--<?= e((string) ($n['action'] ?? '')) ?>"><?= admin_notification_icon((string) ($n['action'] ?? '')) ?> <?= e(admin_notification_action_label((string) ($n['action'] ?? ''))) ?></span>
              </td>
              <td>
                <div><?= e((string) ($n['title'] ?? '')) ?></div>
                <?php $notifDetailHtml = notif_render_detail((string) ($n['detail'] ?? '')); ?>
                <?php if ($notifDetailHtml !== ''): ?>
                  <div class="notif-detail"><?= $notifDetailHtml ?></div>
                <?php endif; ?>
              </td>
              <td class="mono" data-label="Adresse IP"><?= e((string) ($n['ip_address'] ?? '-')) ?></td>
              <td>
                <?php if (empty($n['is_read'])): ?>
                  <form class="notif-read-form" action="<?= e(APP_URL) ?>/actions/notification_read.php" method="post" style="display:inline-flex;margin:0;vertical-align:middle;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="read_one">
                    <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                    <button type="submit" class="btn btn-secondary btn-sm">Marquer comme lue</button>
                  </form>
                <?php else: ?>
                  <span class="badge badge-success">Lue</span>
                <?php endif; ?>
              </td>
              <td class="text-center notif-action-cell">
                <span class="notif-actions">
                <button type="button" class="btn btn-outline btn-sm notif-icon-btn" title="Voir le détail" aria-label="Voir le détail"
                  data-notif-view
                  data-date="<?= e($notifDate) ?>"
                  data-actor="<?= e((string) ($n['actor_username'] ?? '')) ?>"
                  data-action="<?= e(admin_notification_action_label((string) ($n['action'] ?? ''))) ?>"
                  data-entity="<?= e((string) ($n['entity'] ?? '')) ?><?= !empty($n['entity_id']) ? ' #' . e((string) $n['entity_id']) : '' ?>"
                  data-title="<?= e((string) ($n['title'] ?? '')) ?>"
                  data-detail="<?= e(notif_clean_detail((string) ($n['detail'] ?? ''))) ?>"
                  data-ip="<?= e((string) ($n['ip_address'] ?? '-')) ?>"
                  data-state="<?= empty($n['is_read']) ? 'Non lue' : 'Lue' ?>">
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
                <form action="<?= e(APP_URL) ?>/actions/notification_delete.php" method="post" data-confirm="Supprimer définitivement cette notification du journal ?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                  <button type="submit" class="btn btn-danger btn-sm notif-icon-btn" title="Supprimer cette notification" aria-label="Supprimer cette notification">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                  </button>
                </form>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$history['items']): ?>
            <tr>
              <td colspan="<?= $history['items'] ? 8 : 7 ?>">
                <div class="empty-state">
                  <div class="empty-icon">&#128276;</div>
                  Aucune activité de superviseur enregistrée pour le moment.
                </div>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($history['pages'] > 1): ?>
    <div class="pagination">
      <?php for ($i = 1; $i <= $history['pages']; $i++): ?>
        <?php if ($i === $history['page']): ?>
          <span class="current"><?= $i ?></span>
        <?php else: ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
        <?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Popup de détail d'une notification -->
<div id="notif-detail-modal" class="confirm-modal" style="display:none;" role="dialog" aria-modal="true" aria-hidden="true">
  <div class="confirm-dialog notif-detail-dialog">
    <div class="notif-detail-head">
      <h2 id="notif-detail-title">Détail de la notification</h2>
      <button type="button" class="notif-detail-close" id="notif-detail-close" aria-label="Fermer">&times;</button>
    </div>
    <div class="notif-detail-body" id="notif-detail-body">
      <div class="notif-detail-row">
        <span class="notif-detail-label">Date/Heure</span>
        <span class="notif-detail-value" id="nd-date">—</span>
      </div>
      <div class="notif-detail-row">
        <span class="notif-detail-label">Superviseur</span>
        <span class="notif-detail-value" id="nd-actor">—</span>
      </div>
      <div class="notif-detail-row">
        <span class="notif-detail-label">Action</span>
        <span class="notif-detail-value" id="nd-action">—</span>
      </div>
      <div class="notif-detail-row">
        <span class="notif-detail-label">Entité</span>
        <span class="notif-detail-value" id="nd-entity">—</span>
      </div>
      <div class="notif-detail-row">
        <span class="notif-detail-label">Titre</span>
        <span class="notif-detail-value" id="nd-title">—</span>
      </div>
      <div class="notif-detail-row notif-detail-row--detail">
        <span class="notif-detail-label">Détail</span>
        <span class="notif-detail-value notif-detail-text" id="nd-detail">—</span>
      </div>
      <div class="notif-detail-row">
        <span class="notif-detail-label">Adresse IP</span>
        <span class="notif-detail-value mono" id="nd-ip">—</span>
      </div>
      <div class="notif-detail-row">
        <span class="notif-detail-label">État</span>
        <span class="notif-detail-value" id="nd-state">—</span>
      </div>
    </div>
    <div class="confirm-actions">
      <button type="button" class="btn btn-primary" id="notif-detail-ok">Fermer</button>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
