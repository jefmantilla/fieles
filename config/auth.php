<?php
/**
 * Módulo de Autenticación y Control de Acceso por Roles
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT u.*, r.nombre as role_name FROM usuarios u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

function requireLogin() {
    setSecurityHeaders();
    if (!isLoggedIn()) {
        header("Location: /Aplicaiones/fieles/index.php");
        exit();
    }
}

function requireRole($requiredRole) {
    requireLogin();
    $user = getCurrentUser();
    if (!$user || strtolower($user['role_name']) !== strtolower($requiredRole)) {
        header("Location: /Aplicaiones/fieles/index.php?error=acceso_denegado");
        exit();
    }
}

function logoutUser() {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}
