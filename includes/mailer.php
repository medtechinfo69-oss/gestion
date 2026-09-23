<?php
if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Accès direct interdit.');
}

function app_mail_configured(): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $host = (string) (defined('MAIL_HOST') ? MAIL_HOST : (getenv('MAIL_HOST') ?: ''));
    $user = (string) (defined('MAIL_USERNAME') ? MAIL_USERNAME : (getenv('MAIL_USERNAME') ?: ''));
    $pass = (string) (defined('MAIL_PASSWORD') ? MAIL_PASSWORD : (getenv('MAIL_PASSWORD') ?: ''));
    $from = (string) (defined('MAIL_FROM') ? MAIL_FROM : (getenv('MAIL_FROM') ?: ''));
    $cached = (bool) ($host !== '' && $user !== '' && $pass !== '' && filter_var($from, FILTER_VALIDATE_EMAIL));
    return $cached;
}

function smtp_expect($socket, array $codes): void
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $codes, true)) {
        throw new RuntimeException('SMTP response ' . $code . ': ' . trim($response));
    }
}

function smtp_command($socket, string $command, array $codes): void
{
    fwrite($socket, $command . "\r\n");
    smtp_expect($socket, $codes);
}

/**
 * Envoi unique (sans réessai). Voir send_app_email() pour l'enveloppe
 * publique qui réessaie les erreurs SMTP transitoires.
 */
function send_app_email_once(string $to, string $subject, string $body, ?string $attachmentPath = null, ?string $attachmentName = null): bool
{
    $host = (string) (defined('MAIL_HOST') ? MAIL_HOST : (getenv('MAIL_HOST') ?: ''));
    $port = (int) (defined('MAIL_PORT') ? MAIL_PORT : (getenv('MAIL_PORT') ?: 587));
    $user = (string) (defined('MAIL_USERNAME') ? MAIL_USERNAME : (getenv('MAIL_USERNAME') ?: ''));
    $pass = (string) (defined('MAIL_PASSWORD') ? MAIL_PASSWORD : (getenv('MAIL_PASSWORD') ?: ''));
    $from = (string) (defined('MAIL_FROM') ? MAIL_FROM : (getenv('MAIL_FROM') ?: ''));
    $encryption = strtolower((string) (defined('MAIL_ENCRYPTION') ? MAIL_ENCRYPTION : (getenv('MAIL_ENCRYPTION') ?: 'tls')));
    $timeout = (int) (defined('MAIL_TIMEOUT') ? MAIL_TIMEOUT : (getenv('MAIL_TIMEOUT') ?: 15));

    $socket = @fsockopen($host, $port, $errno, $error, $timeout);
    if (!$socket) {
        throw new RuntimeException('connection failed: ' . $error . ' (' . $errno . ')');
    }
    try {
        smtp_expect($socket, [220]);
        smtp_command($socket, 'EHLO ' . (string) (defined('MAIL_HELO') ? MAIL_HELO : 'localhost'), [250]);
        if ($encryption === 'starttls' || $encryption === 'tls') {
            smtp_command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP TLS negotiation failed.');
            }
            smtp_command($socket, 'EHLO ' . (string) (defined('MAIL_HELO') ? MAIL_HELO : 'localhost'), [250]);
        }
        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode($user), [334]);
        smtp_command($socket, base64_encode($pass), [235]);
        smtp_command($socket, 'MAIL FROM:<' . $from . '>', [250]);
        smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);

        $boundary = '=_app_' . bin2hex(random_bytes(12));
        $headers = [
            'From: Gestion des Dossiers <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8'),
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
        ];
        $message = implode("\r\n", $headers) . "\r\n\r\n";
        $message .= '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= $body . "\r\n";

        if ($attachmentPath !== null && is_file($attachmentPath)) {
            $name = $attachmentName ?: basename($attachmentPath);
            $content = chunk_split(base64_encode(file_get_contents($attachmentPath)));
            $message .= '--' . $boundary . "\r\n";
            $message .= 'Content-Type: application/octet-stream; name="' . addcslashes($name, "\\\"") . '"' . "\r\n";
            $message .= 'Content-Disposition: attachment; filename="' . addcslashes($name, "\\\"") . '"' . "\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n\r\n" . $content . "\r\n";
        }
        $message .= '--' . $boundary . "--\r\n";

        smtp_command($socket, 'DATA', [354]);
        fwrite($socket, preg_replace('/(?m)^\./', '..', $message) . "\r\n.\r\n");
        smtp_expect($socket, [250]);
        smtp_command($socket, 'QUIT', [221, 250]);
        fclose($socket);
        return true;
    } catch (Throwable $e) {
        fclose($socket);
        error_log('SMTP error: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Envoie un e-mail via SMTP, avec réessais automatiques sur les erreurs
 * transitoires (421 « Server busy », connexion impossible). Gmail renvoie
 * fréquemment un 421 quand plusieurs envois rapprochés ont lieu ; un court
 * délai permet de repasser. Le dernier échec renvoie false.
 */
function send_app_email(string $to, string $subject, string $body, ?string $attachmentPath = null, ?string $attachmentName = null): bool
{
    if (!app_mail_configured()) {
        error_log('Email skipped: SMTP is not configured.');
        return false;
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('Email skipped: recipient is invalid.');
        return false;
    }

    $maxAttempts = (int) (defined('MAIL_MAX_ATTEMPTS') ? MAIL_MAX_ATTEMPTS : 3);
    $maxAttempts = max(1, min(5, $maxAttempts));

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            return send_app_email_once($to, $subject, $body, $attachmentPath, $attachmentName);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $transient = (strpos($message, '421') !== false)
                || stripos($message, 'server busy') !== false
                || stripos($message, 'try again later') !== false
                || stripos($message, 'connection failed') !== false
                || stripos($message, 'timed out') !== false;

            if (!$transient || $attempt >= $maxAttempts) {
                error_log('SMTP send failed after ' . $attempt . ' attempt(s): ' . $message);
                return false;
            }
            // Attente croissante : 2s, 4s, ...
            sleep(2 * $attempt);
        }
    }
    return false;
}

function notify_admins(PDO $db, string $subject, string $body, ?string $attachmentPath = null, ?string $attachmentName = null): bool
{
    // Désactivé : aucun envoi d'e-mail n'est demandé.
    // Retourne false immédiatement pour que tous les appels
    // (dossier_save, attachment_upload, secure_export, RH, etc.)
    // soient sans effet.
    $msg = 'notify_admins disabled: no email sent for subject "'
        . $subject . '"';
    error_log($msg);
    return false;
}
