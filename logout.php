<?php
require_once __DIR__ . '/includes/auth.php';

logoutUser();

$baseUrl = authBaseUrl();
header('Location: ' . $baseUrl . '/login.php');
exit;
