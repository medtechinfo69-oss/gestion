<?php
if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Accès direct interdit.');
}

function smtp_crypto_key(): string
{
    $secret = defined('APP_SECRET_KEY') ? (string) APP_SECRET_KEY : 'gestion-dossiers-smtp';
    // Dérive une clé 32 octets stable pour AES-256.
    return hash('sha256', $secret, true);
}

function smtp_password_encrypt(string $plain): string
{
    if ($plain === '') {
        return '';
    }
    if (function_exists('openssl_encrypt')) {
        $iv = random_bytes(16);
        $ct = openssl_encrypt($plain, 'AES-256-CBC', smtp_crypto_key(), OPENSSL_RAW_DATA, $iv);
        if ($ct !== false) {
            return 'enc:' . base64_encode($iv . $ct);
        }
    }
    return 'b64:' . base64_encode($plain);
}

function smtp_password_decrypt(string $stored): string
{
    if ($stored === '') {
        return '';
    }
    if (strpos($stored, 'enc:') === 0 && function_exists('openssl_decrypt')) {
        $raw = base64_decode(substr($stored, 4), true);
        if ($raw !== false && strlen($raw) > 16) {
            $iv = substr($raw, 0, 16);
            $ct = substr($raw, 16);
            $plain = openssl_decrypt($ct, 'AES-256-CBC', smtp_crypto_key(), OPENSSL_RAW_DATA, $iv);
            if ($plain !== false) {
                return $plain;
            }
        }
        return '';
    }
    if (strpos($stored, 'b64:') === 0) {
        $plain = base64_decode(substr($stored, 4), true);
        return $plain === false ? '' : $plain;
    }
    // Ancienne valeur stockée en clair (compatibilité ascendante).
    return $stored;
}

/**
 * Configuration SMTP — SOURCE UNIQUE : la carte « E-mail (SMTP) » de
 * profile.php (table `settings`, clés smtp_*). Les fichiers config.*.php ne
 * contiennent plus aucune option SMTP : pour changer de compte d'envoi, il
 * suffit de modifier la carte, sans toucher aux fichiers ni redéployer.
 * Un repli temporaire vers d'éventuelles anciennes constantes MAIL_* sur le
 * serveur évite toute coupure tant que la carte n'a jamais été enregistrée.
 *
 * @return array{host:string,port:int,username:string,password:string,encryption:string,from:string,alert_to:string,timeout:int}
 */
function app_smtp_config(bool $refresh = false): array
{
    static $cached = null;
    if ($cached !== null && !$refresh) {
        return $cached;
    }

    $cfg = [
        'host'       => '',
        'port'       => 587,
        'username'   => '',
        'password'   => '',
        'encryption' => 'tls',
        'from'       => '',
        'alert_to'   => '',
        'timeout'    => 15,
    ];

    // Lecture DB (connexion déjà disponible via includes/init.php).
    try {
        $db = $GLOBALS['db'] ?? null;
        if ($db instanceof PDO && function_exists('app_setting')) {
            $cfg['host'] = trim(app_setting($db, 'smtp_host', ''));
            $cfg['port'] = (int) app_setting($db, 'smtp_port', '587');
            $cfg['password'] = smtp_password_decrypt(app_setting($db, 'smtp_password', ''));
            $cfg['username'] = trim(app_setting($db, 'smtp_username', ''));
            $enc = strtolower(trim(app_setting($db, 'smtp_encryption', 'tls')));
            if (in_array($enc, ['tls', 'starttls', 'ssl', 'none'], true)) {
                $cfg['encryption'] = ($enc === 'starttls') ? 'tls' : $enc;
            }
            $cfg['from'] = trim(app_setting($db, 'smtp_from', ''));
            $cfg['alert_to'] = trim(app_setting($db, 'smtp_alert_to', ''));
        }
    } catch (Throwable $e) {
        // Table settings absente : envoi impossible (badge « À configurer »).
    }

    // Repli héritage : si la DB est vide (ex. production pas encore migrée)
    // ET que d'anciennes constantes MAIL_* existent encore sur le serveur,
    // on les utilise pour ne jamais casser l'envoi existant. Dès que la carte
    // est enregistrée une fois, la DB prend le dessus définitivement.
    if ($cfg['host'] === '' && $cfg['username'] === '' && $cfg['password'] === '' && $cfg['from'] === '') {
        $legacy = static function (string $name) {
            if (defined($name)) {
                $v = constant($name);
                if (is_string($v) || is_int($v)) {
                    return trim((string) $v);
                }
            }
            $env = getenv($name);
            return $env === false ? '' : trim((string) $env);
        };
        $lh = $legacy('MAIL_HOST');
        if ($lh !== '') {
            $cfg['host'] = $lh;
            $lp = (int) $legacy('MAIL_PORT');
            if ($lp >= 1 && $lp <= 65535) {
                $cfg['port'] = $lp;
            }
            $cfg['username'] = $legacy('MAIL_USERNAME');
            $cfg['password'] = $legacy('MAIL_PASSWORD');
            $le = strtolower($legacy('MAIL_ENCRYPTION') ?: 'tls');
            if (in_array($le, ['tls', 'starttls', 'ssl', 'none'], true)) {
                $cfg['encryption'] = ($le === 'starttls') ? 'tls' : $le;
            }
            $cfg['from'] = $legacy('MAIL_FROM');
            $cfg['alert_to'] = $legacy('MAIL_ALERT_TO');
        }
    }

    if (!in_array($cfg['encryption'], ['tls', 'starttls', 'ssl', 'none'], true)) {
        $cfg['encryption'] = 'tls';
    }
    $cfg['port'] = max(1, min(65535, $cfg['port']));
    $cfg['timeout'] = max(5, min(60, $cfg['timeout']));

    return $cached = $cfg;
}

/** Invalide les caches SMTP (à appeler après enregistrement en base). */
function app_smtp_reset_cache(): void
{
    app_smtp_config(true);
    $GLOBALS['app_mail_configured_cache'] = null;
}

function app_mail_configured(): bool
{
    if (array_key_exists('app_mail_configured_cache', $GLOBALS ?? []) && $GLOBALS['app_mail_configured_cache'] !== null) {
        return (bool) $GLOBALS['app_mail_configured_cache'];
    }
    $cfg = app_smtp_config();
    $ok = (bool) ($cfg['host'] !== '' && $cfg['username'] !== '' && $cfg['password'] !== '' && filter_var($cfg['from'], FILTER_VALIDATE_EMAIL));
    $GLOBALS['app_mail_configured_cache'] = $ok;
    return $ok;
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
    $cfg = app_smtp_config();
    $host = $cfg['host'];
    $port = $cfg['port'];
    $user = $cfg['username'];
    $pass = $cfg['password'];
    $from = $cfg['from'];
    $encryption = $cfg['encryption'];
    $timeout = $cfg['timeout'];
    // HELO/EHLO : jamais « localhost » (signal de spam). On utilise le domaine
    // de l'adresse From (ex. gmail.com), ou MAIL_HELO si défini explicitement.
    $helo = (string) (defined('MAIL_HELO') ? MAIL_HELO : (getenv('MAIL_HELO') ?: ''));
    $helo = trim($helo);
    if ($helo === '' && strpos($from, '@') !== false) {
        $helo = strtolower(trim(substr($from, strrpos($from, '@') + 1)));
    }
    if ($helo === '' && function_exists('gethostname')) {
        $helo = (string) gethostname();
    }
    $helo = preg_replace('/[^A-Za-z0-9.\-]/', '', $helo);
    if ($helo === '') {
        $helo = 'localhost';
    }

    // SSL direct (port 465, ex. Gmail/Outlook) : enveloppe TLS dès la connexion.
    $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host;
    $socket = @fsockopen($remote, $port, $errno, $error, $timeout);
    if (!$socket) {
        throw new RuntimeException('connection failed: ' . $error . ' (' . $errno . ')');
    }
    try {
        smtp_expect($socket, [220]);
        smtp_command($socket, 'EHLO ' . $helo, [250]);
        if ($encryption === 'starttls' || $encryption === 'tls') {
            smtp_command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP TLS negotiation failed.');
            }
            smtp_command($socket, 'EHLO ' . $helo, [250]);
        }
        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode($user), [334]);
        smtp_command($socket, base64_encode($pass), [235]);
        smtp_command($socket, 'MAIL FROM:<' . $from . '>', [250]);
        smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);

        // --- En-têtes anti-spam : chaque détail compte pour Gmail/Outlook ---
        // From aligné sur le compte authentifié (pas d'usurpation d'identité),
        // Reply-To = From (les réponses reviennent au bon compte), Date +
        // Message-ID uniques (sinon score spam), List-Unsubscribe (requis par
        // Gmail depuis 2024 pour tout envoi non strictement personnel),
        // X-Priority haute + Importance (priorité boîte de réception).
        $fromName = defined('APP_NAME') ? (string) APP_NAME : 'Gestion des Dossiers';
        $fromName = trim(preg_replace('/[\r\n<>"]+/', ' ', $fromName));
        if ($fromName === '') {
            $fromName = 'Gestion des Dossiers';
        }
        // Nom d'affichage encodé RFC 2047 (accents sûrs).
        $fromHeader = mb_encode_mimeheader($fromName, 'UTF-8', 'Q') . ' <' . $from . '>';
        // Message-ID ancré sur le domaine du From (jamais sur smtp.gmail.com) :
        // un Message-ID dont le domaine ne correspond pas à l'expéditeur est
        // un signal classique de spam pour Gmail.
        $fromDomain = (strpos($from, '@') !== false)
            ? strtolower(trim(substr($from, strrpos($from, '@') + 1)))
            : $host;
        if ($fromDomain === '') {
            $fromDomain = $host;
        }
        $msgId = '<' . bin2hex(random_bytes(16)) . '@' . $fromDomain . '>';
        $boundary = '=_app_' . bin2hex(random_bytes(12));
        $headers = [
            'From: ' . $fromHeader,
            'Reply-To: ' . $fromHeader,
            'To: <' . $to . '>',
            'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8'),
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: ' . $msgId,
            'MIME-Version: 1.0',
            'X-Mailer: ' . $fromName,
            // Priorité haute (1 = haute) + importance : remonte dans la boîte.
            'X-Priority: 1 (Highest)',
            'X-MSMail-Priority: High',
            'Importance: High',
            // Désinscription en un clic (mailto vers l'expéditeur) : exigé par
            // Gmail/Outlook, son absence pénalise fortement le score.
            'List-Unsubscribe: <mailto:' . $from . '?subject=unsubscribe>',
            'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
        ];
        // Version HTML en plus du texte brut : les filtres pénalisent les
        // messages « texte seul sans structure » venant d'un SMTP applicatif.
        $htmlBody = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>'
            . '<body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#1a1a1a;">'
            . nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'))
            . '</body></html>';
        $altBoundary = '=_alt_' . bin2hex(random_bytes(12));
        $message = implode("\r\n", $headers) . "\r\n\r\n";
        $message .= '--' . $boundary . "\r\nContent-Type: multipart/alternative; boundary=\"" . $altBoundary . "\"\r\n\r\n";
        $message .= '--' . $altBoundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= $body . "\r\n";
        $message .= '--' . $altBoundary . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= $htmlBody . "\r\n";
        $message .= '--' . $altBoundary . "--\r\n";

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
