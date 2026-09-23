<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

$action = $_POST['action'] ?? '';
admin_notifications_ensure($db);

if ($action === 'read_all') {
    $db->prepare('UPDATE admin_notifications SET is_read = 1, read_by = :uid, read_at = NOW() WHERE is_read = 0')
        ->execute(['uid' => (int) current_user()['id']]);
    set_flash('success', 'Toutes les notifications ont été marquées comme lues.');
} elseif ($action === 'read_one') {
    $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
    if ($id) {
        $db->prepare('UPDATE admin_notifications SET is_read = 1, read_by = :uid, read_at = NOW() WHERE id = :id')
            ->execute(['uid' => (int) current_user()['id'], 'id' => $id]);
    }
    // Pas de message flash pour la lecture unitaire (action discrète depuis la liste).
}

redirect('notifications.php');
