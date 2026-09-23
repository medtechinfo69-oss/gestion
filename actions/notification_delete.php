<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

admin_notifications_ensure($db);

$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);

if (!$id) {
    set_flash('error', 'Notification introuvable.');
    redirect('notifications.php');
}

try {
    $stmt = $db->prepare('DELETE FROM admin_notifications WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);

    if ($stmt->rowCount() > 0) {
        set_flash('success', 'Notification supprimée du journal.');
    } else {
        set_flash('error', 'Notification introuvable ou déjà supprimée.');
    }
} catch (Throwable $e) {
    error_log('notification_delete error: ' . $e->getMessage());
    set_flash('error', 'Impossible de supprimer cette notification.');
}

redirect('notifications.php');
