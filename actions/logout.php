<?php
require_once __DIR__ . '/../includes/init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
}

logout_user();
header('Location: ' . APP_URL . '/login.php');
exit;
