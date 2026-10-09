<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

admin_notifications_ensure($db);
$action = (string) ($_POST['action'] ?? '');

try {
    if ($action === 'all') {
        $stmt = $db->prepare('DELETE FROM admin_notifications');
        $stmt->execute();
        $deleted = $stmt->rowCount();
        set_flash('success', $deleted . ' notification(s) supprimée(s) du journal.');
    } elseif ($action === 'selected') {
        $postedIds = $_POST['notification_ids'] ?? [];
        $ids = [];
        if (is_array($postedIds)) {
            foreach ($postedIds as $value) {
                $id = filter_var($value, FILTER_VALIDATE_INT);
                if ($id !== false && $id > 0) {
                    $ids[] = (int) $id;
                }
            }
        }
        $ids = array_values(array_unique($ids));

        if (!$ids) {
            set_flash('error', 'Sélectionnez au moins une notification.');
            redirect('notifications.php');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("DELETE FROM admin_notifications WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $deleted = $stmt->rowCount();
        set_flash('success', $deleted . ' notification(s) sélectionnée(s) supprimée(s).');
    } else {
        set_flash('error', 'Action de suppression invalide.');
    }
} catch (Throwable $e) {
    error_log('notification_bulk_delete error: ' . $e->getMessage());
    set_flash('error', 'Impossible de supprimer les notifications demandées.');
}

redirect('notifications.php');
