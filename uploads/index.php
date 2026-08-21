<?php
// Empêche toute exécution ou listage direct de ce dossier.
http_response_code(403);
exit('Accès interdit.');
