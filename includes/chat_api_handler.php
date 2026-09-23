<?php
/**
 * Logique de l'API du chat, factorisée pour deux transports :
 *  1. actions/chat_api.php (endpoint AJAX classique) ;
 *  2. LA PAGE COURANTE via ?chat_api=... (transport de secours : certains
 *     hébergeurs gratuits filtrent les requêtes AJAX vers le dossier actions/,
 *     alors que les requêtes vers la page elle-même passent toujours).
 * Sécurité : tous les rôles connectés (admin / superviseur / vendeur),
 * CSRF sur l'envoi,
 * anti-spam côté serveur, validation de l'existence du correspondant.
 */
if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Accès direct interdit.');
}

function chat_api_handle(PDO $db, string $action): void
{
    // Garde-fou JSON : jette tout output parasite (BOM, warnings en dev)
    // et coupe l'affichage des erreurs pour garantir une réponse 100 % JSON.
    while (ob_get_level() > 0) { @ob_end_clean(); }
    @ini_set('display_errors', '0');
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }

    if (!is_admin() && !is_superviseur() && !is_vendeur_user()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Accès refusé.']);
        return;
    }

    $me = current_user();
    $meId = (int) $me['id'];
    chat_tables_ensure($db);
    users_schema_ensure($db); // répare un éventuel ancien schéma de la table users
    chat_touch_presence($db, $meId);
    chat_maybe_purge($db); // purge des messages > 2 ans (1 fois sur ~200 appels)

    switch ($action) {
        case 'status':
            $contacts = [];
            foreach (chat_get_contacts($db, $meId) as $c) {
                $contacts[] = [
                    'id' => (int) $c['id'],
                    'username' => $c['username'],
                    'name' => $c['nom_complet'] ?: $c['username'],
                    'role' => $c['role'],
                    'online' => (bool) $c['is_online'],
                    'unread' => (int) $c['unread'],
                ];
            }
            $payload = ['ok' => true, 'me' => $meId, 'contacts' => $contacts];
            if (is_admin()) {
                $payload['diag'] = chat_status_diag($db, $meId);
            }
            echo json_encode($payload);
            break;

        case 'messages':
            $peerId = (int) ($_GET['peer'] ?? 0);
            $afterId = (int) ($_GET['after'] ?? 0);
            if ($peerId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Correspondant invalide.']);
                break;
            }
            if (!chat_peer_allowed($db, $peerId)) {
                log_security_event('CHAT_ACCESS_DENIED', "user {$meId} -> peer {$peerId}");
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Correspondant introuvable.']);
                break;
            }
            chat_mark_read($db, $meId, $peerId);
            echo json_encode([
                'ok' => true,
                'messages' => chat_fetch_messages($db, $meId, $peerId, $afterId),
                // Double coche bleue : jusqu'à quel ID mes messages ont été lus
                'receipt' => chat_read_receipt($db, $meId, $peerId),
            ]);
            break;

        case 'send':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Jeton de sécurité invalide.']);
                break;
            }
            $peerId = (int) ($_POST['peer_id'] ?? 0);
            $text = trim((string) ($_POST['message'] ?? ''));
            if ($peerId <= 0 || $text === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Message vide ou correspondant invalide.']);
                break;
            }
            if (!chat_peer_allowed($db, $peerId)) {
                log_security_event('CHAT_ACCESS_DENIED', "send {$meId} -> {$peerId}");
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Correspondant introuvable.']);
                break;
            }
            // Vérifie / répare le schéma de la table chat_messages avant l'insertion.
            // Sur certains hébergeurs, CREATE TABLE IF NOT EXISTS ne corrige pas
            // une table existante avec un mauvais schéma (ex: colonne id sans
            // AUTO_INCREMENT), ce qui fait échouer l'INSERT → lastInsertId() = 0.
            chat_messages_schema_ensure($db);

            $id = chat_send_message($db, $meId, $peerId, $text);
            if ($id === -1) {
                http_response_code(429);
                echo json_encode(['ok' => false, 'error' => 'Trop rapide, patientez un instant.']);
                break;
            }
            if ($id === 0) {
                // L'insertion a échoué en base — on renvoie un message clair
                // au lieu du "Erreur d'envoi." générique côté client.
                echo json_encode([
                    'ok' => false,
                    'error' => "Impossible d'enregistrer le message. Veuillez réessayer. Si le problème persiste, contactez l'administrateur (vérifiez que la table MySQL « chat_messages » existe et possède une clé primaire AUTO_INCREMENT).",
                ]);
                break;
            }
            echo json_encode(['ok' => true, 'id' => $id]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Action inconnue.']);
    }
}
