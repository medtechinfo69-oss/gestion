<?php
/**
 * Envoi de l'e-mail « Courriers manquants » au client depuis la popup de
 * dossier_view.php. Utilise la carte SMTP (profile.php) comme source unique.
 */
require_once __DIR__ . '/../includes/init.php';
require_login();
csrf_require();

$dossierId = (int) ($_POST['dossier_id'] ?? 0);
$to = trim((string) ($_POST['to'] ?? ''));
$subject = trim((string) ($_POST['subject'] ?? ''));
$body = (string) ($_POST['body'] ?? '');

if ($dossierId <= 0) {
    set_flash('error', "Dossier invalide : envoi annulé.");
    redirect('dossiers.php');
}

if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    set_flash('error', "Adresse e-mail du client invalide : envoi annulé.");
    redirect('dossier_view.php?id=' . $dossierId);
}

if ($subject === '' || trim($body) === '') {
    set_flash('error', "L'objet et le message sont obligatoires.");
    redirect('dossier_view.php?id=' . $dossierId);
}

if (mb_strlen($subject, 'UTF-8') > 200 || mb_strlen($body, 'UTF-8') > 10000) {
    set_flash('error', "Objet (200 car. max) ou message (10000 car. max) trop long.");
    redirect('dossier_view.php?id=' . $dossierId);
}

$user = current_user();

// Le vendeur ne peut écrire qu'aux clients de SES dossiers.
$stmt = $db->prepare('SELECT d.*, u.nom_complet AS vendeur_nom FROM dossiers d JOIN users u ON u.id = d.vendeur_id WHERE d.id = :id');
$stmt->execute(['id' => $dossierId]);
$dossier = $stmt->fetch();

if (!$dossier) {
    set_flash('error', 'Dossier introuvable.');
    redirect('dossiers.php');
}

if (!is_admin() && !is_superviseur() && (int) $dossier['vendeur_id'] !== (int) ($user['id'] ?? 0)) {
    http_response_code(403);
    set_flash('error', "Vous n'avez pas accès à ce dossier.");
    redirect('dossiers.php');
}

// Sécurité : le destinataire doit être le mail enregistré du dossier
// (empêche de détourner le SMTP pour spammer une adresse arbitraire).
$dossierMail = trim((string) ($dossier['mail'] ?? ''));
if ($dossierMail === '' || strcasecmp($dossierMail, $to) !== 0) {
    set_flash('error', "Le destinataire doit être l'adresse e-mail enregistrée du dossier.");
    redirect('dossier_view.php?id=' . $dossierId);
}

$ok = send_app_email($to, $subject, $body);

try {
    $h = $db->prepare("INSERT INTO dossier_historique (dossier_id, user_id, action, champ, ancienne_valeur, nouvelle_valeur) VALUES (:d, :u, 'envoi_email_client', 'mail', '', :objet)");
    $h->execute(['d' => $dossierId, 'u' => (int) ($user['id'] ?? 0), 'objet' => mb_substr($subject, 0, 190)]);
} catch (Throwable $ignored) {
}

try {
    log_security_event('EMAIL_CLIENT', 'E-mail client dossier #' . $dossierId . ' vers ' . $to . ' : ' . ($ok ? 'envoyé' : 'ÉCHEC'));
} catch (Throwable $ignored) {
}

if ($ok) {
    set_flash('success', 'Envoyé avec succès.');
} else {
    set_flash('error', "L'envoi a échoué. Vérifiez la carte E-mail (SMTP) des Paramètres (compte, mot de passe d'application).");
}
redirect('dossier_view.php?id=' . $dossierId);
