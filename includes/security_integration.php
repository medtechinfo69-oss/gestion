<?php
/**
 * Security Integration Helper
 * Adds security audit logging to common operations
 */

if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Accès direct interdit.');
}

/**
 * Get the real client IP address from server variables.
 * Prefers a public address, then a private/LAN address, and only
 * falls back to loopback when the request is genuinely local.
 */
function get_client_ip(): string
{
    $headers = [
        'HTTP_X_FORWARDED_FOR',
        'HTTP_CLIENT_IP',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR'
    ];

    $loopback = null;
    $private = null;
    foreach ($headers as $header) {
        if (empty($_SERVER[$header])) {
            continue;
        }
        foreach (explode(',', (string) $_SERVER[$header]) as $candidate) {
            $ip = trim($candidate);
            if ($ip === '::1') {
                $ip = '127.0.0.1';
            }
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip; // Public address: the real client.
            }
            if (in_array($ip, ['127.0.0.1', '0.0.0.0'], true)) {
                $loopback = $loopback ?? $ip;
            } else {
                $private = $private ?? $ip; // LAN address of the PC that opened the page.
            }
        }
    }

    return $private ?? $loopback ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/**
 * Log security event if enabled
 */
function sec_log(string $actionType, string $resourceType, ?string $resourceId = null, ?string $description = null, bool $accessGranted = true, ?string $denialReason = null): void
{
    static $security = null;
    static $enabled = null;

    if ($enabled === null) {
        $enabled = true;
        try {
            if (class_exists('SecurityDashboard')) {
                $security = new SecurityDashboard($db ?? null, current_user());
            }
        } catch (Throwable $e) {
            $enabled = false;
        }
    }

    if (!$enabled || !$security) {
        return;
    }

    try {
        $security->logSecurityEvent($actionType, $resourceType, $resourceId, $description, $accessGranted, $denialReason);
    } catch (Throwable $e) {
        error_log('Security log error: ' . $e->getMessage());
    }
}

/**
 * Log data access for sensitive fields
 */
function sec_data_access(string $table, ?string $recordId, array $fields, ?string $reason = null): void
{
    static $security = null;
    static $enabled = null;

    if ($enabled === null) {
        $enabled = true;
        try {
            if (class_exists('SecurityDashboard')) {
                $security = new SecurityDashboard($db ?? null, current_user());
            }
        } catch (Throwable $e) {
            $enabled = false;
        }
    }

    if (!$enabled || !$security) {
        return;
    }

    try {
        $security->logDataAccess($table, $recordId, $fields, $reason);
    } catch (Throwable $e) {
        error_log('Security data access log error: ' . $e->getMessage());
    }
}
