<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

$user = current_user();
$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);

if (!$id) {
    redirect('dossiers.php');
}

$stmt = $db->prepare('SELECT a.*, d.vendeur_id FROM dossier_attachments a
                       JOIN dossiers d ON d.id = a.dossier_id WHERE a.id = :id');
$stmt->execute(['id' => $id]);
$attachment = $stmt->fetch();

if (!$attachment) {
    set_flash('error', 'Pièce jointe introuvable.');
    redirect('dossiers.php');
}

if (!is_admin() && !is_superviseur() && (int) $attachment['vendeur_id'] !== (int) $user['id']) {
    http_response_code(403);
    set_flash('error', 'Accès refusé.');
    redirect('dossiers.php');
}

// Le nom de fichier stocké est un identifiant aléatoire ; on reconstruit
// le chemin nous-mêmes (jamais de valeur venant directement de l'utilisateur).
$path = UPLOAD_DIR . basename($attachment['nom_fichier']);

if (!is_file($path)) {
    set_flash('error', 'Le fichier n’existe plus sur le serveur.');
    redirect('dossier_view.php?id=' . (int) $attachment['dossier_id']);
}

header('Content-Description: File Transfer');
header('Content-Type: ' . $attachment['type_mime']);
if (isset($_GET['inline']) && $_GET['inline'] === '1' && str_starts_with((string) $attachment['type_mime'], 'audio/')) {
    header('Content-Disposition: inline; filename="' . basename($attachment['nom_original']) . '"');
} else {
    header('Content-Disposition: attachment; filename="' . basename($attachment['nom_original']) . '"');
}
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');

readfile($path);
exit;
