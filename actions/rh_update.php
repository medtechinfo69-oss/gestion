<?php
/**
 * Modification d'un compte RH (ADMINISTRATEUR uniquement).
 * Même sécurité et mêmes règles que actions/superviseur_update.php.
 */
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
$nomComplet = clean_str($_POST['nom_complet'] ?? '');
$username = clean_str($_POST['username'] ?? '');
$email = clean_email_input($_POST['email'] ?? '');
$isActive = (int) ($_POST['is_active'] ?? 1);
$password = trim((string) ($_POST['password'] ?? ''));

if (!$id) {
    set_flash('error', 'Compte RH introuvable.');
    redirect('rh_comptes.php');
}

if ($nomComplet === '' || $username === '' || mb_strlen($nomComplet) > 150 || mb_strlen($username) > 60) {
    set_flash('error', 'Données invalides. Les champs nom et identifiant sont obligatoires.');
    redirect('rh_comptes.php');
}

// E-mail : ne bloque PAS la mise à jour ; inexploitable -> ignoré + avertissement.
$emailIgnore = false;
if ($email !== '' && !email_plausible($email)) {
    $email = '';
    $emailIgnore = true;
}

if ($password !== '' && !password_is_strong($password)) {
    set_flash('error', 'Le mot de passe doit contenir au moins 12 caractères, une majuscule, une minuscule, un chiffre et un caractère spécial.');
    redirect('rh_comptes.php');
}

try {
    $params = [
        'nom' => $nomComplet,
        'u' => $username,
        'e' => $email !== '' ? $email : null,
        'a' => $isActive,
        'id' => $id,
    ];
    $sql = 'UPDATE users SET nom_complet = :nom, username = :u, email = :e, is_active = :a';

    if ($password !== '') {
        $sql .= ', password_hash = :p, must_change_password = 1';
        $params['p'] = password_hash($password, PASSWORD_DEFAULT);
    }

    // Le filtre `role = 'rh'` empêche de détourner cette action sur un autre rôle.
    $sql .= ' WHERE id = :id AND role = \'rh\'';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    set_flash('success', ($password !== ''
        ? 'Compte RH mis à jour avec succès. Le mot de passe a été modifié.'
        : 'Compte RH mis à jour avec succès.')
        . ($emailIgnore ? ' Attention : l\'adresse e-mail saisie a été ignorée (format non reconnu).' : ''));
} catch (Throwable $e) {
    error_log('rh_update error: ' . $e->getMessage());
    set_flash('error', 'Impossible de mettre à jour ce compte RH. L\'identifiant existe peut-être déjà.');
}

redirect('rh_comptes.php');