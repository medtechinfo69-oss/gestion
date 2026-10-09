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

$mime = strtolower(trim((string) $attachment['type_mime']));

// Deux modes de lecture sont acceptés :
//   - inline=1  : utilisé depuis toujours par le lecteur audio intégré ;
//   - preview=1 : nouvel aperçu dans la modale de la page dossier.
// Dans les deux cas le fichier n'est servi qu'en mode « inline » s'il fait
// partie des types affichables par le navigateur (voir attachment_preview_kind).
$wantInline = (isset($_GET['inline']) && $_GET['inline'] === '1')
    || (isset($_GET['preview']) && $_GET['preview'] === '1');
$isInline = $wantInline && attachment_is_previewable($mime);

// Un superviseur ne peut pas télécharger de pièce jointe ; la consultation
// « en ligne » (aperçu / lecteur audio) reste autorisée, comme auparavant.
if (is_superviseur() && !$isInline) {
    http_response_code(403);
    exit('Téléchargement interdit pour les superviseurs.');
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
if ($isInline) {
    // Aucun en-tête CSP supplémentaire ici : includes/init.php en envoie déjà
    // un, et deux politiques sont intersectées par le navigateur — un
    // « script-src 'none » casserait le lecteur PDF (pdf.js sous Firefox).
    // La protection repose sur la liste blanche des types servis en inline
    // (attachment_preview_kind) et sur X-Content-Type-Options: nosniff.
    header('Content-Disposition: inline; filename="' . basename($attachment['nom_original']) . '"');
} else {
    header('Content-Disposition: attachment; filename="' . basename($attachment['nom_original']) . '"');
}
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');

readfile($path);
exit;
