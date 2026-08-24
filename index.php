<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';

setSecurityHeaders();

// Si ya está autenticado, redirigir a su panel correspondiente según su rol
if (isLoggedIn()) {
    $user = getCurrentUser();
    if ($user) {
        $role = strtolower($user['role_name']);
        if ($role === 'admin') {
            header("Location: admin/dashboard.php");
            exit();
        } else if ($role === 'adminencuestas') {
            header("Location: admin_encuestas/dashboard.php");
            exit();
        } else if ($role === 'encuestadora') {
            header("Location: encuestadora/dashboard.php");
            exit();
        } else {
            header("Location: lider/dashboard.php");
            exit();
        }
    }
}

// Resetear contador de reintentos al recargar mediante GET para pruebas
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    resetRateLimit('login_attempts');
}

$error = '';
$csrfToken = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = "Petición no válida o expirada (CSRF Error).";
    } else {
        // Rate Limiting anti-fuerza bruta
        if (!checkRateLimit('login_attempts', 10, 180)) {
            $error = "Demasiados intentos fallidos. Por favor espere unos minutos antes de reintentar.";
        } else {
            $username = sanitizeInput($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';

            if (empty($username) || empty($password)) {
                $error = "Por favor ingrese usuario y contraseña.";
            } else {
                $pdo = getDB();
                $stmt = $pdo->prepare("SELECT u.*, r.nombre as role_name FROM usuarios u JOIN roles r ON u.role_id = r.id WHERE u.username = ?");
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {
                    // Resetear tasa de limite en login exitoso
                    resetRateLimit('login_attempts');
                    
                    // Regenerar sesión por seguridad
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role_name'] = $user['role_name'];

                    $role = strtolower($user['role_name']);
                    if ($role === 'admin') {
                        header("Location: admin/dashboard.php");
                    } else if ($role === 'adminencuestas') {
                        header("Location: admin_encuestas/dashboard.php");
                    } else if ($role === 'encuestadora') {
                        header("Location: encuestadora/dashboard.php");
                    } else {
                        header("Location: lider/dashboard.php");
                    }
                    exit();
                } else {
                    $error = "Credenciales incorrectas. Verifique usuario y contraseña.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Proyecto Político Social</title>
    <!-- Font Awesome & MDB / Bootstrap 5 CSS CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/6.4.0/mdb.min.css">
    <link rel="stylesheet" href="assets/css/custom.css">
</head>
<body class="bg-light d-flex align-items-center justify-content-center min-vh-100">

<div class="container" style="max-width: 440px;">
    <div class="card card-custom shadow-lg">
        <div class="card-body p-4 text-center">
            <div class="mb-4">
                <i class="fas fa-users-cog fa-4x text-primary mb-2"></i>
                <h4 class="fw-bold color-primary">Proyecto Político Social</h4>
                <p class="text-muted small">Acceso Unificado del Sistema (Líderes, Encuestas y Administración)</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i><?= e($error) ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['error']) && $_GET['error'] === 'acceso_denegado'): ?>
                <div class="alert alert-warning" role="alert">
                    <i class="fas fa-lock me-2"></i>Acceso no autorizado. Inicie sesión.
                </div>
            <?php endif; ?>

            <form action="index.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                
                <!-- Usuario Input -->
                <div class="mb-4 text-start">
                    <label class="form-label fw-bold small text-muted mb-1" for="username"><i class="fas fa-user me-2 text-primary"></i>Usuario</label>
                    <input type="text" id="username" name="username" class="form-control form-control-lg" placeholder="Ingrese su usuario" required autofocus />
                </div>

                <!-- Contraseña Input -->
                <div class="mb-4 text-start">
                    <label class="form-label fw-bold small text-muted mb-1" for="password"><i class="fas fa-lock me-2 text-primary"></i>Contraseña</label>
                    <input type="password" id="password" name="password" class="form-control form-control-lg" placeholder="Ingrese su contraseña" required />
                </div>

                <!-- Botón Login -->
                <button type="submit" class="btn btn-primary btn-block btn-lg btn-primary-custom shadow-0">
                    <i class="fas fa-sign-in-alt me-2"></i> Ingresar al Sistema
                </button>
            </form>
        </div>
        <div class="card-footer text-center py-3 bg-white border-0 rounded-bottom">
            <small class="text-muted"><i class="fas fa-shield-alt text-success me-1"></i> Conexión Segura e Encriptada</small>
        </div>
    </div>
</div>

<!-- MDB JS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/6.4.0/mdb.min.js"></script>
</body>
</html>
