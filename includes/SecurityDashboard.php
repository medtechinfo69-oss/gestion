<?php
/**
 * Security Dashboard - Complete Security Management System
 * Provides comprehensive security monitoring, access control, and audit logging
 */

if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Accès direct interdit.');
}

class SecurityDashboard
{
    private PDO $db;
    private ?array $currentUser;

    /**
     * Cache des paramètres de sécurité (par requête).
     * Invalide par clearSettingsCache() après chaque mise à jour.
     */
    private static ?array $settingsCache = null;

    public function __construct(PDO $db, ?array $currentUser = null)
    {
        $this->db = $db;
        $this->currentUser = $currentUser;
    }

    // ==================== ACCESS CONTROL ====================

    /**
     * Check if user has specific permission
     */
    public function hasPermission(string $permission): bool
    {
        if (!$this->currentUser) return false;
        
        // Admin has all permissions
        if ($this->currentUser['role'] === 'admin') {
            return true;
        }

        // Check specific permission
        $stmt = $this->db->prepare('SELECT permission_value FROM user_permissions WHERE user_id = :uid AND permission_key = :key');
        $stmt->execute(['uid' => $this->currentUser['id'], 'key' => $permission]);
        $permission = $stmt->fetch();
        
        if (!$permission) {
            // Default permissions by role
            return $this->getDefaultPermission($permission);
        }

        return true;
    }

    /**
     * Get default permissions by role
     */
    private function getDefaultPermission(string $permission): bool
    {
        if (!$this->currentUser) return false;

        $role = $this->currentUser['role'];
        
        $permissions = [
            'admin' => [
                'view_all_dossiers', 'edit_all_dossiers', 'delete_dossiers', 'manage_users',
                'view_salaries', 'edit_salaries', 'export_data', 'import_data',
                'view_reports', 'manage_settings', 'view_security_logs', 'manage_security',
                'full_access'
            ],
            'superviseur' => [
                'view_assigned_dossiers', 'edit_assigned_dossiers',
                'view_assigned_employees', 'view_assigned_salaries',
                'export_own_data', 'view_own_reports'
            ],
            'vendeur' => [
                'view_own_dossiers'
            ]
        ];

        return in_array($permission, $permissions[$role] ?? [], true);
    }

    /**
     * Check if user can access specific dossier
     */
    public function canAccessDossier(int $dossierId): bool
    {
        if (!$this->currentUser) return false;

        // Admin can access all
        if ($this->currentUser['role'] === 'admin') {
            return true;
        }

        // Superviseur can access dossiers assigned to their team
        if ($this->currentUser['role'] === 'superviseur') {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM dossiers WHERE id = :id AND vendeur_id IN (SELECT id FROM users WHERE superviseur_id = :sid)');
            $stmt->execute(['id' => $dossierId, 'sid' => $this->currentUser['id']]);
            return $stmt->fetchColumn() > 0;
        }

        // Vendeur can only access own dossiers
        if ($this->currentUser['role'] === 'vendeur') {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM dossiers WHERE id = :id AND vendeur_id = :uid');
            $stmt->execute(['id' => $dossierId, 'uid' => $this->currentUser['id']]);
            return $stmt->fetchColumn() > 0;
        }

        return false;
    }

    /**
     * Check if user can access specific employee
     */
    public function canAccessEmployee(int $employeeId): bool
    {
        if (!$this->currentUser) return false;

        // Admin can access all
        if ($this->currentUser['role'] === 'admin') {
            return true;
        }

        // Superviseur can access employees in their team
        if ($this->currentUser['role'] === 'superviseur') {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM employees WHERE id = :eid AND EXISTS (SELECT 1 FROM dossiers d JOIN users u ON u.id = d.vendeur_id WHERE d.employee_id = :eid AND u.superviseur_id = :sid)');
            $stmt->execute(['eid' => $employeeId, 'sid' => $this->currentUser['id']]);
            return $stmt->fetchColumn() > 0;
        }

        return false;
    }

    // ==================== AUDIT LOGGING ====================

    /**
     * Log security event
     */
    public function logSecurityEvent(string $actionType, string $resourceType, ?string $resourceId = null, ?string $description = null, bool $accessGranted = true, ?string $denialReason = null): void
    {
        if (!$this->currentUser) return;

        $ipAddress = get_client_ip();
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 500);
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
        $requestUrl = $_SERVER['REQUEST_URI'] ?? '';
        $sessionId = session_id();

        $queryParams = '';
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $queryParams = json_encode($_GET);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $queryParams = json_encode($_POST);
        }

        $stmt = $this->db->prepare('INSERT INTO security_audit_log (user_id, username, user_role, action_type, resource_type, resource_id, resource_description, ip_address, user_agent, request_method, request_url, query_params, access_granted, denial_reason, session_id) VALUES (:uid, :uname, :urole, :action, :rtype, :rid, :rdesc, :ip, :ua, :method, :url, :params, :granted, :reason, :sid)');
        $stmt->execute([
            'uid' => $this->currentUser['id'],
            'uname' => $this->currentUser['username'],
            'urole' => $this->currentUser['role'],
            'action' => $actionType,
            'rtype' => $resourceType,
            'rid' => $resourceId,
            'rdesc' => $description,
            'ip' => $ipAddress,
            'ua' => $userAgent,
            'method' => $requestMethod,
            'url' => $requestUrl,
            'params' => $queryParams,
            'granted' => $accessGranted ? 1 : 0,
            'reason' => $denialReason,
            'sid' => $sessionId
        ]);
    }

    /**
     * Log data access for sensitive fields
     */
    public function logDataAccess(string $table, ?string $recordId, array $fields, ?string $reason = null): void
    {
        if (!$this->currentUser) return;

        $ipAddress = get_client_ip();
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 500);

        $stmt = $this->db->prepare('INSERT INTO data_access_log (user_id, username, accessed_table, accessed_record_id, fields_accessed, access_reason, ip_address) VALUES (:uid, :uname, :table, :rid, :fields, :reason, :ip)');
        $stmt->execute([
            'uid' => $this->currentUser['id'],
            'uname' => $this->currentUser['username'],
            'table' => $table,
            'rid' => $recordId,
            'fields' => json_encode($fields),
            'reason' => $reason,
            'ip' => $ipAddress
        ]);
    }

    // ==================== SECURITY MONITORING ====================

    /**
     * Check for suspicious activity
     */
    public function detectSuspiciousActivity(): array
    {
        $alerts = [];

        // 1. Multiple failed login attempts from same IP
        $stmt = $this->db->prepare('SELECT ip_address, COUNT(*) as attempts FROM login_attempts WHERE success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE) GROUP BY ip_address HAVING attempts >= 5');
        $stmt->execute();
        $failedLogins = $stmt->fetchAll();
        
        foreach ($failedLogins as $login) {
            $alerts[] = [
                'type' => 'multiple_failed_logins',
                'severity' => 'high',
                'description' => "Multiple failed login attempts from IP: {$login['ip_address']} ({$login['attempts']} attempts)"
            ];
        }

        // 2. Same user logging in from different IPs
        if ($this->currentUser) {
            $stmt = $this->db->prepare('SELECT DISTINCT ip_address FROM login_attempts WHERE username = :uname AND success = 1 AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
            $stmt->execute(['uname' => $this->currentUser['username']]);
            $ips = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (count($ips) > 1) {
                $alerts[] = [
                    'type' => 'multiple_ips',
                    'severity' => 'medium',
                    'description' => "User {$this->currentUser['username']} logged in from multiple IPs: " . implode(', ', $ips)
                ];
            }
        }

        // 3. Bulk exports
        $stmt = $this->db->prepare('SELECT COUNT(*) as cnt FROM security_audit_log WHERE action_type = "export" AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
        $stmt->execute();
        $exportCount = $stmt->fetchColumn();
        
        if ($exportCount > 3) {
            $alerts[] = [
                'type' => 'bulk_exports',
                'severity' => 'medium',
                'description' => "Multiple exports in last hour: $exportCount"
            ];
        }

        return $alerts;
    }

    /**
     * Check if IP is blocked
     */
    public function isBlocked(string $ip, string $userAgent = ''): bool
    {
        $stmt = $this->db->prepare('SELECT * FROM security_blocks WHERE block_type = "ip" AND block_value = :ip AND (is_permanent = 1 OR expires_at > NOW())');
        $stmt->execute(['ip' => $ip]);
        return $stmt->fetch() !== false;
    }

    /**
     * Check if user is blocked
     */
    public function isUserBlocked(int $userId): bool
    {
        $stmt = $this->db->prepare('SELECT * FROM security_blocks WHERE block_type = "user" AND block_value = :uid AND (is_permanent = 1 OR expires_at > NOW())');
        $stmt->execute(['uid' => (string) $userId]);
        return $stmt->fetch() !== false;
    }

    // ==================== SECURITY DASHBOARD ====================

    /**
     * Get security dashboard statistics
     */
    public function getDashboardStats(): array
    {
        $stats = [];

        // Login attempts last 24h
        $stmt = $this->db->prepare('SELECT COUNT(*) as total, SUM(success) as successful, COUNT(*) - SUM(success) as failed FROM login_attempts WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)');
        $stmt->execute();
        $loginStats = $stmt->fetch();
        $stats['logins_24h'] = $loginStats;

        // Security events last 24h
        $stmt = $this->db->prepare('SELECT COUNT(*) as total FROM security_audit_log WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)');
        $stmt->execute();
        $stats['security_events_24h'] = $stmt->fetchColumn();

        // Active sessions
        $stmt = $this->db->prepare('SELECT COUNT(DISTINCT session_id) as active_sessions FROM security_audit_log WHERE created_at > DATE_SUB(NOW(), INTERVAL 2 HOUR)');
        $stmt->execute();
        $stats['active_sessions'] = $stmt->fetchColumn();

        // Permission denials last 24h
        $stmt = $this->db->prepare('SELECT COUNT(*) as denials FROM security_audit_log WHERE access_granted = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)');
        $stmt->execute();
        $stats['permission_denials_24h'] = $stmt->fetchColumn();

        // Unresolved security events
        $stmt = $this->db->prepare('SELECT COUNT(*) as unresolved FROM security_events WHERE resolved = 0');
        $stmt->execute();
        $stats['unresolved_events'] = $stmt->fetchColumn();

        // Blocked users/IPs
        $stmt = $this->db->prepare('SELECT COUNT(*) as blocked FROM security_blocks WHERE (is_permanent = 1 OR expires_at > NOW())');
        $stmt->execute();
        $stats['blocked_entities'] = $stmt->fetchColumn();

        return $stats;
    }

    /**
     * Get recent security events
     */
    public function getRecentSecurityEvents(int $limit = 50): array
    {
        $stmt = $this->db->prepare('SELECT * FROM security_audit_log ORDER BY created_at DESC LIMIT :limit');
        $stmt->execute(['limit' => $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Get security events by user
     */
    public function getEventsByUser(int $userId, int $limit = 50): array
    {
        $stmt = $this->db->prepare('SELECT * FROM security_audit_log WHERE user_id = :uid ORDER BY created_at DESC LIMIT :limit');
        $stmt->execute(['uid' => $userId, 'limit' => $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Get login history for user
     */
    public function getLoginHistory(int $userId, int $limit = 20): array
    {
        $stmt = $this->db->prepare('SELECT * FROM login_attempts WHERE username = (SELECT username FROM users WHERE id = :uid) ORDER BY created_at DESC LIMIT :limit');
        $stmt->execute(['uid' => $userId, 'limit' => $limit]);
        return $stmt->fetchAll();
    }

    // ==================== RATE LIMITING ====================

    /**
     * Check rate limit for action
     */
    public function checkRateLimit(string $action, int $maxRequests = null, int $windowSeconds = null): bool
    {
        if (!$this->currentUser) return false;

        $maxRequests = $maxRequests ?? (int) $this->getSetting('rate_limit_max_requests', 100);
        $windowSeconds = $windowSeconds ?? (int) $this->getSetting('rate_limit_window', 900);

        $bucket = $this->currentUser['id'] . '_' . $action;
        
        $stmt = $this->db->prepare('SELECT attempts, window_start FROM rate_limits WHERE bucket = :bucket AND window_start > DATE_SUB(NOW(), INTERVAL :window SECOND)');
        $stmt->execute(['bucket' => $bucket, 'window' => $windowSeconds]);
        $rateLimit = $stmt->fetch();

        if ($rateLimit) {
            if ((int)$rateLimit['attempts'] >= $maxRequests) {
                // Log security event
                $this->logSecurityEvent('permission_denied', 'system', null, "Rate limit exceeded for $action", false, "Rate limit: $maxRequests requests per $windowSeconds seconds");
                return false;
            }
            // Increment
            $stmt = $this->db->prepare('UPDATE rate_limits SET attempts = attempts + 1 WHERE bucket = :bucket');
            $stmt->execute(['bucket' => $bucket]);
        } else {
            // Create new window
            $stmt = $this->db->prepare('INSERT INTO rate_limits (bucket, attempts, window_start) VALUES (:bucket, 1, NOW())');
            $stmt->execute(['bucket' => $bucket]);
        }

        return true;
    }

    // ==================== SETTINGS ====================

    /**
     * Get security setting
     */
    public function getSetting(string $key, $default = null)
    {
        if (self::$settingsCache === null) {
            $this->loadSettingsCache();
        }

        return self::$settingsCache[$key] ?? $default;
    }

    /**
     * Get all security settings
     */
    public function getSettings(): array
    {
        if (self::$settingsCache === null) {
            $this->loadSettingsCache();
        }

        return self::$settingsCache;
    }

    /**
     * Charge tous les paramètres depuis la base et décode leurs valeurs
     * selon le type déclaré (boolean, integer, json, string).
     */
    private function loadSettingsCache(): void
    {
        self::$settingsCache = [];
        $stmt = $this->db->query('SELECT setting_key, setting_value, setting_type FROM security_settings');
        while ($row = $stmt->fetch()) {
            $value = $row['setting_value'];
            switch ($row['setting_type']) {
                case 'boolean':
                    $value = in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
                    break;
                case 'integer':
                    $value = (int) $value;
                    break;
                case 'json':
                    $value = json_decode($value, true);
                    break;
            }
            self::$settingsCache[$row['setting_key']] = $value;
        }
    }

    /**
     * Invalide le cache des paramètres (appelé après chaque mise à jour).
     */
    public function clearSettingsCache(): void
    {
        self::$settingsCache = null;
    }

    /**
     * Update security setting
     */
    public function updateSetting(string $key, $value, ?int $updatedBy = null): bool
    {
        $type = 'string';
        if (is_bool($value)) {
            $type = 'boolean';
            $value = $value ? '1' : '0';
        } elseif (is_int($value)) {
            $type = 'integer';
            $value = (string) $value;
        } elseif (is_array($value)) {
            $type = 'json';
            $value = json_encode($value);
        }

        $stmt = $this->db->prepare('INSERT INTO security_settings (setting_key, setting_value, setting_type, updated_by) VALUES (:key, :value, :type, :uid) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_type = VALUES(setting_type), updated_by = VALUES(updated_by)');
        $ok = $stmt->execute(['key' => $key, 'value' => $value, 'type' => $type, 'uid' => $updatedBy]);

        // Invalide le cache pour que les lectures suivantes voient la nouvelle valeur
        if ($ok) {
            $this->clearSettingsCache();
        }

        return $ok;
    }

    // ==================== DATA MASKING ====================

    /**
     * Mask sensitive data based on user role
     */
    public function maskSensitiveData(array $data, string $role = 'superviseur'): array
    {
        if ($role === 'admin') {
            return $data; // Admin sees everything
        }

        $sensitiveFields = [
            'mail', 'email', 'telfix', 'portable', 'phone', 'telephone',
            'adresse', 'address', 'cp', 'code_postal', 'ville', 'city',
            'password_hash', 'password', 'secret', 'token', 'api_key',
            'numero_securite_sociale', 'nss', 'iban', 'rib', 'num_compte'
        ];

        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), $sensitiveFields, true)) {
                $data[$key] = $this->maskValue($value, $key);
            }
        }

        return $data;
    }

    /**
     * Mask a single value
     */
    private function maskValue($value, string $field): string
    {
        $value = (string) $value;
        $length = strlen($value);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        // Show first and last 2 characters
        return substr($value, 0, 2) . str_repeat('*', $length - 4) . substr($value, -2);
    }

    // ==================== EXPORT SECURITY ====================

    /**
     * Check if export is allowed
     */
    public function canExport(string $exportType = 'general'): bool
    {
        if (!$this->currentUser) return false;

        // Admin can always export
        if ($this->currentUser['role'] === 'admin') {
            return true;
        }

        // Superviseur export check
        if ($this->currentUser['role'] === 'superviseur') {
            return (bool) $this->getSetting('allow_supervisor_exports', false);
        }

        return false;
    }

    /**
     * Log export activity
     */
    public function logExport(string $exportType, string $fileName, int $recordCount, ?string $filters = null): void
    {
        if (!$this->currentUser) return;

        $this->logSecurityEvent('export', 'export', null, 
            "Export: $exportType - File: $fileName - Records: $recordCount" . ($filters ? " - Filters: $filters" : '')
        );

        // Send alert if bulk export
        if ($recordCount > 100 && $this->getSetting('alert_on_bulk_export', true)) {
            $this->sendSecurityAlert('bulk_export', "Bulk export detected: $recordCount records exported by {$this->currentUser['username']}");
        }
    }

    /**
     * Send security alert email
     */
    private function sendSecurityAlert(string $type, string $message): void
    {
        $subject = "[ALERTE SECURITE] $type - " . APP_NAME;
        $body = "ALERTE DE SECURITE\n";
        $body .= "==================\n\n";
        $body .= "Type: $type\n";
        $body .= "Message: $message\n";
        $body .= "User: " . ($this->currentUser['username'] ?? 'unknown') . "\n";
        $body .= "IP: " . get_client_ip() . "\n";
        $body .= "Date: " . date('d/m/Y H:i:s') . "\n\n";
        $body .= "Ce message a été généré automatiquement.\n";

        // Send to all admins
        $stmt = $this->db->query("SELECT email FROM users WHERE role = 'admin' AND is_active = 1 AND email IS NOT NULL AND email <> ''");
        $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($admins as $adminEmail) {
            send_app_email($adminEmail, $subject, $body);
        }

        // Log event
        $stmt = $this->db->prepare('INSERT INTO security_events (event_type, severity, user_id, username, description, ip_address, user_agent) VALUES (:type, :severity, :uid, :uname, :desc, :ip, :ua)');
        $stmt->execute([
            'type' => $type,
            'severity' => 'high',
            'uid' => $this->currentUser['id'] ?? 0,
            'uname' => $this->currentUser['username'] ?? 'unknown',
            'desc' => $message,
            'ip' => get_client_ip(),
            'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 500)
        ]);
    }
}
