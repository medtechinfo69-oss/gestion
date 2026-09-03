<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
$password = (string) ($_POST['password'] ?? '');

// Provide a fallback password strength checker if the helper is missing on the host
if (!function_exists('password_is_strong')) {
    function password_is_strong(string $p): bool {
        if ($p === '') return false;
        if (mb_strlen($p) < 10) return false;
        if (!preg_match('/[A-Z]/', $p)) return false;
        if (!preg_match('/[a-z]/', $p)) return false;
        if (!preg_match('/[0-9]/', $p)) return false;
        return true;
    }
}

if (!$id || $password === '' || !password_is_strong($password)) {
    $reasons = function_exists('password_policy_errors') ? implode(', ', password_policy_errors($password)) : 'au moins 12 caractères, une majuscule, une minuscule, un chiffre et un caractère spécial';
    set_flash('error', 'Mot de passe invalide. ' . $reasons . '.');
    redirect('superviseurs.php');
}

try {
    $stmt = $db->prepare('SELECT id, username, role FROM users WHERE id = :id AND role = \'superviseur\'');
    $stmt->execute(['id' => $id]);
    $superviseur = $stmt->fetch();

    if (!$superviseur) {
        set_flash('error', 'Superviseur introuvable.');
        redirect('superviseurs.php');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $upd = $db->prepare('UPDATE users SET password_hash = :p, must_change_password = 1, last_password_change = NOW() WHERE id = :id');
    $upd->execute(['p' => $hash, 'id' => $id]);

    // Historique des mots de passe (anti-réutilisation)
    if (function_exists('record_password_change')) {
        record_password_change($db, (int) $id, $hash);
    }

    log_security_event('PASSWORD_CHANGE', "Mot de passe défini par l'administrateur pour {$superviseur['username']}");

    if (function_exists('log_dossier_history') && function_exists('current_user')) {
        try {
            log_dossier_history($db, $id, current_user()['id'], 'modification', 'password_hash', '', '[changed by admin]');
        } catch (Throwable $e) {
            error_log('log_dossier_history failed: ' . $e->getMessage());
        }
    }

    set_flash('success', 'Mot de passe du superviseur mis à jour. Il sera demandé de changer le mot de passe à la prochaine connexion.');
} catch (Throwable $e) {
    error_log('superviseur_password error: ' . $e->getMessage());
    set_flash('error', 'Une erreur est survenue lors de la mise à jour du mot de passe.');
}

redirect('superviseurs.php');
