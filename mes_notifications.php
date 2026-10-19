<?php
require_once __DIR__ . '/includes/init.php';
require_login();
$isSup = is_role_superviseur();
$isVend = is_vendeur_user();
if (!$isSup && !$isVend) {
    set_flash('error', 'Accès réservé aux superviseurs et vendeurs.');
    redirect('dashboard.php');
}
$pageTitle = $isSup ? 'Activité des vendeurs' : 'Mes notifications';
$pageSubtitle = $isSup ? 'Actions des vendeurs : créations et pièces jointes' : 'Modifications de vos dossiers par la supervision';
$activePage = 'mes_notifications';
$allowedActions = ['create', 'update', 'upload', 'delete', 'restore'];
$filterAction = clean_str($_GET['action'] ?? '');
if ($filterAction !== '' && !in_array($filterAction, $allowedActions, true)) {
    $filterAction = '';
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$history = role_notifications_history($db, $page, 25, $filterAction);
$unread = role_notifications_unread_count($db);
$topbarActions = '';
if ($unread > 0) {
    $topbarActions = '<form action="' . e(APP_URL) . '/actions/role_notification_read.php" method="post" style="display:inline;">' . csrf_field() . '<input type="hidden" name="action" value="read_all">' . '<button type="submit" class="btn btn-secondary">Tout marquer comme lu (' . (int) $unread . ')</button></form>';
}
require __DIR__ . '/includes/header.php';
?>
<div class="card notif-page">
  <div class="notif-page-head">
    <div>
      <h2><?= e($pageTitle) ?></h2>
      <div class="muted"><?= e($pageSubtitle) ?></div>
    </div>
    <span class="muted"><?= (int) $history['total'] ?> notification(s)</span>
  </div>
  <form method="get" class="filter-bar">
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
      <a href="mes_notifications.php" class="btn btn-secondary">Réinitialiser</a>
    </div>
  </form>
  <div class="table-card"><div class="table-wrap"><table class="data-table">
    <thead><tr><th>Date/Heure</th><th>Acteur</th><th>Action</th><th>Détail</th><th>État</th></tr></thead>
    <tbody>
      <?php foreach ($history['items'] as $n): ?>
      <?php $notifDate = format_datetime_tn($n['created_at'] ?? null, 'd/m/Y H:i:s'); ?>
      <tr class="data-row<?= empty($n['is_read']) ? ' notif-row-unread' : '' ?>">
        <td class="nowrap"><?= e($notifDate) ?></td>
        <td><strong><?= e((string) ($n['actor_username'] ?? '')) ?></strong></td>
        <td><span class="action-pill action-pill--<?= e((string) ($n['action'] ?? '')) ?>"><?= admin_notification_icon((string) ($n['action'] ?? '')) ?> <?= e(admin_notification_action_label((string) ($n['action'] ?? ''))) ?></span></td>
        <td>
          <div><?= e((string) ($n['title'] ?? '')) ?></div>
          <?php if (trim((string) ($n['detail'] ?? '')) !== ''): ?>
            <div class="notif-detail"><span class="muted" style="font-size:0.78rem;"><?= e((string) $n['detail']) ?></span></div>
          <?php endif; ?>
          <?php $linkDid = (int) ($n['dossier_id'] ?? 0); if ($linkDid <= 0 && ($n['entity'] ?? '') === 'dossier') { $linkDid = (int) ($n['entity_id'] ?? 0); } ?>
          <?php if ($linkDid > 0): ?>
            <div style="margin-top:4px;"><a class="btn btn-outline btn-sm" href="<?= e(APP_URL) ?>/dossier_view.php?id=<?= $linkDid ?>">Voir le dossier #<?= $linkDid ?></a></div>
          <?php endif; ?>
        </td>
        <td>
          <?php if (empty($n['is_read'])): ?>
            <form action="<?= e(APP_URL) ?>/actions/role_notification_read.php" method="post" style="display:inline;margin:0;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="read_one">
              <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
              <button type="submit" class="btn btn-secondary btn-sm">Marquer comme lue</button>
            </form>
          <?php else: ?>
            <span class="badge badge-success">Lue</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$history['items']): ?>
        <tr><td colspan="5"><div class="empty-state"><div class="empty-icon">&#128276;</div><?= $isSup ? 'Aucune activité de vendeur pour le moment.' : 'Aucune notification pour le moment.' ?></div></td></tr>
      <?php endif; ?>
    </tbody>
  </table></div></div>
  <?php if ($history['pages'] > 1): ?>
    <div class="pagination">
      <?php for ($i = 1; $i <= $history['pages']; $i++): ?>
        <?php if ($i === $history['page']): ?><span class="current"><?= $i ?></span>
        <?php else: ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a><?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
