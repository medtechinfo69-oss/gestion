<?php
/**
 * API AJAX du chat interne (JSON).
 *   GET  ?action=status                -> contacts + en ligne + non lus
 *   GET  ?action=messages&peer=N&after=N -> messages (déchiffrés) + état de lecture
 *   POST action=send                   -> {peer_id, message} + jeton CSRF
 *
 * La logique est dans includes/chat_api_handler.php : ce fichier n'est que le
 * endpoint classique ; le transport de secours passe par la page courante
 * (?chat_api=... géré dans includes/init.php) pour les hébergeurs qui
 * filtrent les requêtes AJAX vers le dossier actions/.
 */

require_once __DIR__ . '/../includes/init.php';
require_login();
require_once __DIR__ . '/../includes/chat_api_handler.php';

chat_api_handle($db, (string) ($_GET['action'] ?? $_POST['action'] ?? ''));

