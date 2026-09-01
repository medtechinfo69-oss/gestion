<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
csrf_require();

clear_app_cache($db);
set_flash('success', 'Le cache a été vidé avec succès.');
redirect('profile.php');
