<?php
require_once __DIR__ . '/../includes/init.php';
require_login();
csrf_require();

$user = current_user();
$dossierId = filter_var($_POST['dossier_id'] ?? '', FILTER_VALIDATE_INT);

if (!$dossierId) {
    set_flash('error', 'Dossier invalide.');
    redirect('dossiers.php');
}

$stmt = $db->prepare('SELECT * FROM dossiers WHERE id = :id');
$stmt->execute(['id' => $dossierId]);
$dossier = $stmt->fetch();

if (!$dossier) {
    set_flash('error', 'Dossier introuvable.');
    redirect('dossiers.php');
}

// Un vendeur ne peut ajouter une pièce jointe que sur ses propres dossiers
if (!is_admin() && !is_superviseur() && (int) $dossier['vendeur_id'] !== (int) $user['id']) {
    http_response_code(403);
    set_flash('error', 'Accès refusé.');
    redirect('dossiers.php');
}

if (empty($_FILES['fichier']) || $_FILES['fichier']['error'] === UPLOAD_ERR_NO_FILE) {
    set_flash('error', 'Veuillez sélectionner un fichier.');
    redirect('dossier_view.php?id=' . $dossierId);
}

$file = $_FILES['fichier'];

// ---------------------------------------------------------------------
// Contrôles de sécurité de l'upload
// ---------------------------------------------------------------------
if ($file['error'] !== UPLOAD_ERR_OK) {
    set_flash('error', 'Échec du téléversement (code erreur ' . (int) $file['error'] . ').');
    redirect('dossier_view.php?id=' . $dossierId);
}

if ($file['size'] <= 0 || $file['size'] > UPLOAD_MAX_SIZE) {
    set_flash('error', 'Le fichier dépasse la taille maximale autorisée (5 Mo).');
    redirect('dossier_view.php?id=' . $dossierId);
}

// Vérifie que le fichier a bien été téléversé via HTTP POST (anti path/manipulation)
if (!is_uploaded_file($file['tmp_name'])) {
    set_flash('error', 'Téléversement invalide.');
    redirect('dossier_view.php?id=' . $dossierId);
}

$originalName = clean_str($file['name']);
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

if (!in_array($ext, UPLOAD_ALLOWED_EXT, true)) {
    set_flash('error', 'Type de fichier non autorisé. Formats acceptés : ' . implode(', ', UPLOAD_ALLOWED_EXT) . '.');
    redirect('dossier_view.php?id=' . $dossierId);
}

// Vérifie le type MIME réel du contenu (pas seulement l'extension déclarée)
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$realMime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($realMime, UPLOAD_ALLOWED_MIME, true)) {
    set_flash('error', 'Le contenu du fichier ne correspond pas à un type autorisé.');
    redirect('dossier_view.php?id=' . $dossierId);
}

// Nom de fichier stocké : aléatoire, jamais dérivé du nom d'origine (anti path traversal / collision)
$storedName = generate_safe_filename($originalName);
$destination = UPLOAD_DIR . $storedName;

if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    set_flash('error', 'Impossible d’enregistrer le fichier sur le serveur.');
    redirect('dossier_view.php?id=' . $dossierId);
}

// Empêche toute exécution du fichier téléversé, quel que soit son contenu
@chmod($destination, 0644);

try {
    $ins = $db->prepare(
        'INSERT INTO dossier_attachments (dossier_id, nom_original, nom_fichier, type_mime, taille, uploaded_by)
         VALUES (:d, :orig, :stored, :mime, :taille, :u)'
    );
    $ins->execute([
        'd' => $dossierId, 'orig' => $originalName, 'stored' => $storedName,
        'mime' => $realMime, 'taille' => $file['size'], 'u' => $user['id'],
    ]);
    log_dossier_history($db, $dossierId, $user['id'], 'ajout_piece_jointe', null, null, $originalName);
} catch (PDOException $e) {
    @unlink($destination);
    error_log('attachment_upload error: ' . $e->getMessage());
    set_flash('error', 'Erreur lors de l’enregistrement de la pièce jointe.');
    redirect('dossier_view.php?id=' . $dossierId);
}

set_flash('success', 'Pièce jointe ajoutée avec succès.');
redirect('dossier_view.php?id=' . $dossierId);
