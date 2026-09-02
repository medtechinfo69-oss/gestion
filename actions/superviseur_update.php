<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
$nomComplet = clean_str($_POST['nom_complet'] ?? '');
$username = clean_str($_POST['username'] ?? '');
$email = clean_str($_POST['email'] ?? '');
$isActive = (int) ($_POST['is_active'] ?? 1);
$password = trim((string) ($_POST['password'] ?? ''));

if (!$id) {
    set_flash('error', 'Superviseur introuvable.');
    redirect('superviseurs.php');
}

if ($nomComplet === '' || $username === '' || mb_strlen($nomComplet) > 150 || mb_strlen($username) > 60) {
    set_flash('error', 'Données invalides. Les champs nom et identifiant sont obligatoires.');
    redirect('superviseurs.php');
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    set_flash('error', 'L\'e-mail doit être valide si vous le renseignez.');
    redirect('superviseurs.php');
}

if ($password !== '' && !password_is_strong($password)) {
    set_flash('error', 'Le mot de passe doit contenir au moins 10 caractères, une majuscule, une minuscule et un chiffre.');
    redirect('superviseurs.php');
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

    $sql .= ' WHERE id = :id AND role = \'superviseur\'';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    set_flash('success', $password !== ''
        ? 'Superviseur mis à jour avec succès. Le mot de passe a été modifié.'
        : 'Superviseur mis à jour avec succès.');
} catch (Throwable $e) {
    error_log('superviseur_update error: ' . $e->getMessage());
    set_flash('error', 'Impossible de mettre à jour ce superviseur. L\'identifiant existe peut-être déjà.');
}

redirect('superviseurs.php');
