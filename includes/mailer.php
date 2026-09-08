<?php
if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Accès direct interdit.');
}

function app_mail_configured(): bool
{
    return defined('MAIL_HOST') && MAIL_HOST !== ''
        && defined('MAIL_USERNAME') && MAIL_USERNAME !== ''
        && defined('MAIL_PASSWORD') && MAIL_PASSWORD !== ''
        && defined('MAIL_FROM') && filter_var(MAIL_FROM, FILTER_VALIDATE_EMAIL);
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

function send_app_email(string $to, string $subject, string $body, ?string $attachmentPath = null, ?string $attachmentName = null): bool
{
    if (!app_mail_configured() || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('Email skipped: SMTP is not configured or recipient is invalid.');
        return false;
    }

    $host = (string) MAIL_HOST;
    $port = (int) (defined('MAIL_PORT') ? MAIL_PORT : 2525);
    $timeout = 15;
    $socket = @fsockopen($host, $port, $errno, $error, $timeout);
    if (!$socket) {
        error_log('SMTP connection failed: ' . $error . ' (' . $errno . ')');
        return false;
    }

    try {
        smtp_expect($socket, [220]);
        smtp_command($socket, 'EHLO ' . (string) (defined('MAIL_HELO') ? MAIL_HELO : 'localhost'), [250]);
        if (defined('MAIL_ENCRYPTION') && strtolower((string) MAIL_ENCRYPTION) === 'tls') {
            smtp_command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP TLS negotiation failed.');
            }
            smtp_command($socket, 'EHLO ' . (string) (defined('MAIL_HELO') ? MAIL_HELO : 'localhost'), [250]);
        }
        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode((string) MAIL_USERNAME), [334]);
        smtp_command($socket, base64_encode((string) MAIL_PASSWORD), [235]);
        smtp_command($socket, 'MAIL FROM:<' . MAIL_FROM . '>', [250]);
        smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);

        $boundary = '=_app_' . bin2hex(random_bytes(12));
        $headers = [
            'From: Gestion des Dossiers <' . MAIL_FROM . '>',
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
            $content = chunk_split(base64_encode((string) file_get_contents($attachmentPath)));
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
        return false;
    }
}

function notify_admins(PDO $db, string $subject, string $body, ?string $attachmentPath = null, ?string $attachmentName = null): bool
{
    try {
        $stmt = $db->query("SELECT email FROM users WHERE role = 'admin' AND is_active = 1 AND email IS NOT NULL AND email <> ''");
        $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (defined('MAIL_ALERT_TO') && filter_var(MAIL_ALERT_TO, FILTER_VALIDATE_EMAIL)) {
            $recipients[] = MAIL_ALERT_TO;
        }
        $sent = false;
        foreach (array_unique($recipients) as $recipient) {
            $sent = send_app_email((string) $recipient, $subject, $body, $attachmentPath, $attachmentName) || $sent;
        }
        return $sent;
    } catch (Throwable $e) {
        error_log('Admin notification failed: ' . $e->getMessage());
        return false;
    }
}
