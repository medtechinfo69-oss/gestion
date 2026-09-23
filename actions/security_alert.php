<?php
require_once __DIR__ . '/../includes/init.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// SECURITE : endpoint réservé aux utilisateurs connectés (l'alerte est
// rattachée à leur compte) et protégé par jeton CSRF. Sans ces contrôles,
// n'importe qui pouvait insérer des alertes arbitraires (pollution des
// journaux, usurpation d'identité dans les traces de sécurité).
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$allowedTypes = [
    'print_screen', 'right_click', 'drag_attempt', 'screenshot_attempt',
    'selection_attempt', 'copy_attempt', 'cut_attempt', 'paste_attempt',
    'print_attempt', 'save_attempt', 'view_source_attempt', 'dev_tools_attempt',
    'dev_tools', 'tab_hidden', 'tab_visible', 'idle_warning', 'copy_html_attempt'
];

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$sentToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? ''));
$storedToken = (string) ($_SESSION['csrf_token'] ?? '');
if ($sentToken === '' || $storedToken === '' || !hash_equals($storedToken, $sentToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$type = trim((string) ($input['type'] ?? ''));
if (!in_array($type, $allowedTypes, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown alert type']);
    exit;
}

$db = $GLOBALS['db'];

// Identité : toujours celle de la session (jamais celle fournie par le client,
// qui permettrait d'usurper un autre utilisateur). Longueurs bornées pour
// éviter le remplissage massif des journaux de sécurité.
$user = current_user();
$userName = mb_substr((string) ($user['nom_complet'] ?? $user['username'] ?? ''), 0, 150);
$userEmail = mb_substr((string) ($user['email'] ?? ''), 0, 190);
$description = mb_substr(trim((string) ($input['description'] ?? '')), 0, 500);
$details = mb_substr(trim((string) ($input['details'] ?? '')), 0, 500);
$page = mb_substr(trim((string) ($input['page'] ?? '')), 0, 500);
$timestamp = mb_substr(trim((string) ($input['timestamp'] ?? '')), 0, 40);

$fullDescription = $description;
if ($details) {
    $fullDescription = $description . ' - ' . $details;
}

try {
$clientIp = 'unknown';
if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    $clientIp = trim($ips[0]);
} elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
    $clientIp = $_SERVER['HTTP_X_REAL_IP'];
} elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
    $clientIp = $_SERVER['HTTP_CLIENT_IP'];
} else {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}
if ($clientIp === '::1') {
    $clientIp = 'localhost (IPv6)';
} elseif ($clientIp === '127.0.0.1') {
    $clientIp = 'localhost (IPv4)';
}

$ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
if (strlen($ua) > 500) $ua = substr($ua, 0, 500);

$stmt = $db->prepare('INSERT INTO security_alerts (user_id, user_name, user_email, alert_type, description, page_url, ip_address, user_agent, created_at) VALUES (:uid, :uname, :email, :type, :desc, :page, :ip, :ua, NOW())');
$stmt->execute([
    'uid' => (int) ($user['id'] ?? 0),
    'uname' => $userName,
    'email' => $userEmail,
    'type' => $type,
    'desc' => $fullDescription,
    'page' => $page,
    'ip' => $clientIp,
    'ua' => $ua
]);

$alertId = $db->lastInsertId();

$subject = '[ALERTE SECURITE] ' . ucfirst(str_replace('_', ' ', $type));
$body = "ALERTE DE SECURITE - ACTIVITE DETECTEE\n";
$body .= "==========================================\n";
$body .= "Type: " . ucfirst(str_replace('_', ' ', $type)) . "\n";
$body .= "Description: $fullDescription\n\n";
$body .= "=== UTILISATEUR ===\n";
$body .= "Nom: $userName\n";
$body .= "Email: $userEmail\n";
$body .= "Page: $page\n";
$body .= "Date/Heure: $timestamp\n";
$body .= "IP: $clientIp\n\n";
$body .= "---\n";
$body .= "ID Alerte: $alertId\n";
$body .= "Genere: " . date('d/m/Y H:i:s') . "\n";
    
    $recipients = [];
    
    // Aucun envoi d'e-mail n'est demandé : seule la trace DB est conservée.
    $emailSent = false;
    
    echo json_encode(['success' => true, 'logged' => true, 'alert_id' => $alertId, 'email_sent' => $emailSent, 'recipients' => $recipients]);
    
} catch (Throwable $e) {
    error_log('Security alert error: ' . $e->getMessage());
    echo json_encode(['error' => 'Failed to log alert: ' . $e->getMessage()]);
}
