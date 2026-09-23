<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

// Les identifiants de session valent 0 si la table a perdu son AUTO_INCREMENT :
// on répare le schéma avant toute lecture pour que l'approbation aboutisse.
superviseur_tables_ensure($db);
superviseur_schema_repair($db);

$sessionId = (int) ($_POST['session_id'] ?? 0);
$action = $_POST['action'] ?? '';
$reason = clean_str($_POST['reason'] ?? '');
// Page de retour : vendeurs.php (section vendeurs) ou superviseurs.php.
$returnTo = ($_POST['return_to'] ?? '') === 'vendeurs.php' ? 'vendeurs.php' : 'superviseurs.php';

if ($sessionId <= 0 || !in_array($action, ['approve', 'deny'], true)) {
    set_flash('error', 'Demande invalide.');
    redirect($returnTo);
}

if ($action === 'approve') {
    $stmt = $db->prepare('SELECT user_id, ip_address, user_agent FROM superviseur_sessions WHERE id = :id AND status = \'pending\' LIMIT 1');
    $stmt->execute(['id' => $sessionId]);
    $session = $stmt->fetch();

    if (!$session) {
        set_flash('error', 'Session introuvable ou déjà traitée.');
        redirect($returnTo);
    }

    $db->prepare('UPDATE superviseur_sessions SET status = \'approved\', approved_by = :by, approved_at = NOW() WHERE id = :id')
      ->execute(['by' => (int) current_user()['id'], 'id' => $sessionId]);

    // Upsert : ré-approuver une IP déjà connue ne doit pas échouer sur la clé unique.
    $db->prepare('INSERT INTO superviseur_approved_ips (user_id, ip_address, user_agent, approved_by, approved_at) VALUES (:uid, :ip, :ua, :by, NOW()) ON DUPLICATE KEY UPDATE user_agent = VALUES(user_agent), approved_by = VALUES(approved_by), approved_at = NOW()')
      ->execute([
          'uid' => (int) $session['user_id'],
          'ip' => $session['ip_address'],
          'ua' => $session['user_agent'],
          'by' => (int) current_user()['id'],
      ]);

    set_flash('success', 'Session approuvée. L\'utilisateur peut maintenant se connecter.');
} else {
    $db->prepare('UPDATE superviseur_sessions SET status = \'denied\', approved_by = :by, denial_reason = :reason, approved_at = NOW() WHERE id = :id')
      ->execute(['by' => (int) current_user()['id'], 'reason' => $reason ?: 'Refusée par l\'administrateur', 'id' => $sessionId]);

    set_flash('info', 'Session refusée.');
}

redirect($returnTo);
