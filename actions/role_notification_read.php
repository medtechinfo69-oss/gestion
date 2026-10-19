<?php
require_once __DIR__ . '/../includes/init.php';
require_login();
csrf_require();
if (!is_role_superviseur() && !is_vendeur_user()) {
    set_flash('error', 'Accès réservé aux superviseurs et vendeurs.');
    redirect('dashboard.php');
}
$action = $_POST['action'] ?? '';
admin_notifications_ensure($db);
if ($action === 'read_all') {
    $n = role_notifications_mark_all_read($db);
    set_flash('success', $n > 0 ? ($n . ' notification(s) marquée(s) comme lue(s).') : 'Aucune notification à marquer.');
} elseif ($action === 'read_one') {
    $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
    if ($id) {
        role_notifications_mark_one_read($db, (int) $id);
    }
}
redirect('mes_notifications.php');
