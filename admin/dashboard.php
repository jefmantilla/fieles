<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/auth.php';

requireRole('Admin');

$user = getCurrentUser();

if ($user && $user['username'] === 'jefe') {
    require_once __DIR__ . '/dashboard_jefe.php';
} else {
    require_once __DIR__ . '/dashboard_admin.php';
}
