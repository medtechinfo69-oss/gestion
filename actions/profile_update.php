<?php
require_once __DIR__ . '/../includes/init.php';
require_login();
csrf_require();

$user = current_user();

if (!empty($_POST['save_preferences'])) {
    $itemsPerPage = max(10, min(100, (int) ($_POST['items_per_page'] ?? ITEMS_PER_PAGE)));
    $sessionLifetime = max(900, min(21600, (int) ($_POST['session_lifetime'] ?? SESSION_LIFETIME)));

    set_user_preference('items_per_page', $itemsPerPage);
    set_user_preference('session_lifetime', $sessionLifetime);

    set_flash('success', 'Les préférences ont été enregistrées.');
    redirect('profile.php' . (!empty($user['must_change_password']) ? '?force=1' : ''));
}

if (!empty($_POST['save_smtp_settings']) || !empty($_POST['save_smtp_and_test'])) {
    if (!is_admin()) {
        set_flash('error', 'Seul un administrateur peut modifier la configuration e-mail (SMTP).');
        redirect('profile.php');
        exit;
    }

    $smtpHost = strtolower(trim((string) ($_POST['smtp_host'] ?? '')));
    $smtpHost = preg_replace('/[^a-z0-9.\-]/', '', $smtpHost);
    $smtpPort = (int) ($_POST['smtp_port'] ?? 587);
    $smtpUser = trim((string) ($_POST['smtp_username'] ?? ''));
    $smtpPass = (string) ($_POST['smtp_password'] ?? '');
    $smtpEnc = strtolower(trim((string) ($_POST['smtp_encryption'] ?? 'tls')));
    $smtpFrom = trim((string) ($_POST['smtp_from'] ?? ''));
    $smtpAlert = trim((string) ($_POST['smtp_alert_to'] ?? ''));
    $smtpTestTo = trim((string) ($_POST['smtp_test_to'] ?? ''));

    $errors = [];
    if ($smtpHost === '' || strlen($smtpHost) < 3 || strpos($smtpHost, '.') === false) {
        $errors[] = 'Serveur SMTP invalide (ex. smtp.gmail.com).';
    }
    if ($smtpPort < 1 || $smtpPort > 65535) {
        $errors[] = 'Port SMTP invalide (1-65535).';
    }
    if (!in_array($smtpEnc, ['tls', 'starttls', 'ssl', 'none'], true)) {
        $smtpEnc = 'tls';
    }
    // Normalise : tls/starttls identiques côté envoi.
    if ($smtpEnc === 'starttls') {
        $smtpEnc = 'tls';
    }
    if ($smtpUser === '' || strlen($smtpUser) > 190) {
        $errors[] = 'Identifiant SMTP obligatoire (adresse e-mail complète).';
    } elseif (!filter_var($smtpUser, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Identifiant SMTP invalide : saisissez une adresse e-mail valide.';
    }
    if ($smtpFrom === '' || !filter_var($smtpFrom, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Adresse d’expédition (From) invalide.';
    }
    if ($smtpAlert !== '' && !filter_var($smtpAlert, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Adresse de notification (Alert To) invalide.';
    }
    // Le mot de passe est obligatoire sauf s'il en existe déjà un (champ vide = conserver).
    $existingPassEnc = app_setting($db, 'smtp_password', '');
    $existingPass = $existingPassEnc !== '' ? smtp_password_decrypt($existingPassEnc) : '';
    if ($smtpPass === '' && $existingPass === '') {
        $errors[] = 'Mot de passe SMTP obligatoire (mot de passe d’application pour Gmail).';
    }
    if (strlen($smtpPass) > 500) {
        $errors[] = 'Mot de passe SMTP trop long.';
    }

    if ($errors) {
        set_flash('error', implode(' ', $errors));
        redirect('profile.php');
        exit;
    }

    // Suggestion de cohérence port/chiffrement (non bloquante, auto-corrigée côté envoi).
    save_app_setting($db, 'smtp_host', $smtpHost);
    save_app_setting($db, 'smtp_port', (string) $smtpPort);
    save_app_setting($db, 'smtp_username', $smtpUser);
    if ($smtpPass !== '') {
        save_app_setting($db, 'smtp_password', smtp_password_encrypt($smtpPass));
    }
    save_app_setting($db, 'smtp_encryption', $smtpEnc);
    save_app_setting($db, 'smtp_from', $smtpFrom);
    save_app_setting($db, 'smtp_alert_to', $smtpAlert);
    app_smtp_reset_cache();

    try {
        log_security_event('SMTP_UPDATE', 'Configuration SMTP mise à jour (hôte ' . $smtpHost . ':' . $smtpPort . ').');
    } catch (Throwable $ignored) {
    }

    // Envoi d'un e-mail de test si demandé (bouton « Enregistrer et tester »).
    if (!empty($_POST['save_smtp_and_test'])) {
        $testTo = $smtpTestTo !== '' ? $smtpTestTo : ($smtpAlert !== '' ? $smtpAlert : $smtpFrom);
        if (!filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
            set_flash('success', 'Configuration SMTP enregistrée. Adresse de test invalide : aucun e-mail envoyé.');
            redirect('profile.php');
            exit;
        }
        $ok = send_app_email(
            $testTo,
            'Test SMTP — ' . (defined('APP_NAME') ? APP_NAME : 'Gestion des Dossiers'),
            "Bonjour,\n\nCeci est un e-mail de test envoyé depuis la page Paramètres.\n"
            . 'Configuration active : ' . $smtpHost . ':' . $smtpPort . ' (' . strtoupper($smtpEnc) . '), compte ' . $smtpUser . ".\n"
            . 'Si vous recevez ce message, l’envoi d’e-mails est opérationnel.'
            . "\n\nEnvoyé le " . date('d/m/Y à H:i') . '.'
        );
        if ($ok) {
            set_flash('success', 'Configuration SMTP enregistrée. E-mail de test envoyé à ' . $testTo . '.');
        } else {
            set_flash('error', 'Configuration enregistrée, mais l’e-mail de test a échoué. Vérifiez l’hôte, le port, le chiffrement et le mot de passe d’application (consultez logs/php-error.log).');
        }
        redirect('profile.php');
        exit;
    }

    set_flash('success', 'La configuration e-mail (SMTP) a été enregistrée.');
    redirect('profile.php');
    exit;
}

if (!empty($_POST['save_app_settings'])) {
    if (!is_admin()) {
        set_flash('error', 'Seul un administrateur peut modifier la configuration générale.');
        redirect('profile.php');
    }

    $maxUploadMb = max(1, min(100, (int) ($_POST['max_upload_mb'] ?? 30)));
    save_app_setting($db, 'max_upload_mb', (string) $maxUploadMb);
    set_flash('success', 'La configuration générale a été enregistrée.');
    redirect('profile.php');
}

$current = (string) ($_POST['current_password'] ?? '');
$new = (string) ($_POST['new_password'] ?? '');
$confirm = (string) ($_POST['confirm_password'] ?? '');

$stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
$stmt->execute(['id' => $user['id']]);
$dbUser = $stmt->fetch();

if (!$dbUser || !password_verify($current, $dbUser['password_hash'])) {
    set_flash('error', 'Mot de passe actuel incorrect.');
    redirect('profile.php' . (!empty($user['must_change_password']) ? '?force=1' : ''));
}

if ($new !== $confirm) {
    set_flash('error', 'Les deux nouveaux mots de passe ne correspondent pas.');
    redirect('profile.php' . (!empty($user['must_change_password']) ? '?force=1' : ''));
}

if (!password_is_strong($new)) {
    $reasons = implode(', ', password_policy_errors($new));
    set_flash('error', 'Mot de passe trop faible : ' . $reasons . '.');
    redirect('profile.php' . (!empty($user['must_change_password']) ? '?force=1' : ''));
}

if (password_verify($new, $dbUser['password_hash'])) {
    set_flash('error', 'Le nouveau mot de passe doit être différent de l’ancien.');
    redirect('profile.php' . (!empty($user['must_change_password']) ? '?force=1' : ''));
}

// Anti-réutilisation des derniers mots de passe (5 derniers par défaut)
if (password_was_used_before($db, (int) $dbUser['id'], $new)) {
    set_flash('error', 'Ce mot de passe a déjà été utilisé récemment. Choisissez-en un nouveau.');
    redirect('profile.php' . (!empty($user['must_change_password']) ? '?force=1' : ''));
}

$hash = password_hash($new, PASSWORD_DEFAULT);
$upd = $db->prepare('UPDATE users SET password_hash = :p, must_change_password = 0, last_password_change = NOW() WHERE id = :id');
$upd->execute(['p' => $hash, 'id' => $user['id']]);

// Enregistre le nouveau mot de passe dans l'historique
record_password_change($db, (int) $dbUser['id'], $hash);

log_security_event('PASSWORD_CHANGE', "Mot de passe modifié pour {$dbUser['username']}");

$_SESSION['user']['must_change_password'] = false;

set_flash('success', 'Votre mot de passe a été mis à jour.');
redirect('dashboard.php');
