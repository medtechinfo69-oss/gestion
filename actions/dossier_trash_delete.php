<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
if (!$id) redirect('corbeille.php');

$stmt = $db->prepare('DELETE FROM dossier_trash WHERE id = :id');
$stmt->execute(['id' => $id]);
set_flash($stmt->rowCount() ? 'success' : 'error', $stmt->rowCount() ? 'Dossier supprimé définitivement.' : 'Élément introuvable dans la corbeille.');
redirect('corbeille.php');
