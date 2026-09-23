<?php
/**
 * Téléchargement du fichier XLSX contenant les doublons détectés lors de l'import
 */
require_once __DIR__ . '/../includes/init.php';
require_login();

// Récupérer le fichier temporaire de la session
$filePath = $_SESSION['dupli_file_path'] ?? null;
$fileTime = $_SESSION['dupli_file_time'] ?? null;

// Vérifier que le fichier existe et n'est pas trop ancien (< 1 heure)
if (!$filePath || !file_exists($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    exit('Fichier non trouvé ou expiré.');
}

if (!$fileTime || (time() - $fileTime) > 3600) {
    unlink($filePath);
    unset($_SESSION['dupli_file_path'], $_SESSION['dupli_file_time']);
    http_response_code(404);
    exit('Fichier expiré. Veuillez relancer l\'import.');
}

// Servir le fichier
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="doublons_' . date('Y-m-d_H-i') . '.xlsx"');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);

// Nettoyer après téléchargement
unlink($filePath);
unset($_SESSION['dupli_file_path'], $_SESSION['dupli_file_time']);

sec_log('export', 'dossier', null, 'Downloaded duplicates file', true);
exit;
