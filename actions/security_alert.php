<?php
require_once __DIR__ . '/../includes/init.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['type'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$db = $GLOBALS['db'];
$user = current_user();

$type = trim($input['type'] ?? '');
$description = trim($input['description'] ?? '');
$details = trim($input['details'] ?? '');
$userName = trim($input['user'] ?? '');
$userEmail = trim($input['email'] ?? '');
$page = trim($input['page'] ?? '');
$timestamp = trim($input['timestamp'] ?? '');

$allowedTypes = [
    'print_screen', 'right_click', 'drag_attempt', 'screenshot_attempt', 
    'selection_attempt', 'copy_attempt', 'cut_attempt', 'paste_attempt',
    'print_attempt', 'save_attempt', 'view_source_attempt', 'dev_tools_attempt',
    'dev_tools', 'tab_hidden', 'tab_visible', 'idle_warning', 'copy_html_attempt'
];

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
    
    if (defined('MAIL_ALERT_TO') && MAIL_ALERT_TO && filter_var(MAIL_ALERT_TO, FILTER_VALIDATE_EMAIL)) {
        $recipients[] = MAIL_ALERT_TO;
    }
    
    $admins = $db->query("SELECT email FROM users WHERE role = 'admin' AND is_active = 1 AND email IS NOT NULL AND email <> ''")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($admins as $adminEmail) {
        if ($adminEmail && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $recipients[] = $adminEmail;
        }
    }
    
    $recipients = array_unique($recipients);
    $emailSent = false;
    
    foreach ($recipients as $recipient) {
        $sent = send_app_email($recipient, $subject, $body);
        if ($sent) $emailSent = true;
    }
    
    echo json_encode(['success' => true, 'logged' => true, 'alert_id' => $alertId, 'email_sent' => $emailSent, 'recipients' => $recipients]);
    
} catch (Throwable $e) {
    error_log('Security alert error: ' . $e->getMessage());
    echo json_encode(['error' => 'Failed to log alert: ' . $e->getMessage()]);
}
