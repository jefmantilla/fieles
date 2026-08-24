<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/auth.php';

requireRole('Lider');

$user = getCurrentUser();
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit();
}

$token = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($token)) {
    header("Location: dashboard.php?error=" . urlencode("Sesión expirada o token no válido."));
    exit();
}

$referidoId = (int)($_POST['referido_id'] ?? 0);
$nombres = sanitizeInput($_POST['nombres'] ?? '');
$apellidos = sanitizeInput($_POST['apellidos'] ?? '');
$celular = sanitizeInput($_POST['celular'] ?? '');
$comuna = sanitizeInput($_POST['comuna'] ?? '');

if (empty($referidoId) || empty($nombres) || empty($apellidos) || empty($celular) || empty($comuna)) {
    header("Location: dashboard.php?error=" . urlencode("Todos los campos requeridos deben ser diligenciados."));
    exit();
}

if (!preg_match('/^[0-9]{10}$/', $celular)) {
    header("Location: dashboard.php?error=" . urlencode("El número de celular debe ser exactamente de 10 dígitos numéricos."));
    exit();
}

// 1. Verificar pertenencia del referido a la red raíz del líder autenticado
$stmtCheck = $pdo->prepare("SELECT * FROM referidos WHERE id = ? AND lider_raiz_id = ?");
$stmtCheck->execute([$referidoId, $user['id']]);
$referido = $stmtCheck->fetch();

if (!$referido) {
    header("Location: dashboard.php?error=" . urlencode("No tiene permisos para modificar este registro."));
    exit();
}

// 2. Verificar que el celular no pertenezca a otra persona
$stmtCel = $pdo->prepare("SELECT COUNT(*) FROM referidos WHERE celular = ? AND id != ?");
$stmtCel->execute([$celular, $referidoId]);
if ($stmtCel->fetchColumn() > 0) {
    header("Location: dashboard.php?error=" . urlencode("El número de celular ya pertenece a otro registro en la plataforma."));
    exit();
}

// 3. Actualizar únicamente Nombres, Apellidos, Celular y Comuna/Sector
// (Cédula y Estado de Votante no son modificables por el líder)
$stmtUpd = $pdo->prepare("UPDATE referidos SET nombres = ?, apellidos = ?, celular = ?, comuna = ? WHERE id = ? AND lider_raiz_id = ?");
$res = $stmtUpd->execute([$nombres, $apellidos, $celular, $comuna, $referidoId, $user['id']]);

if ($res) {
    header("Location: dashboard.php?msg=edit_exito");
    exit();
} else {
    header("Location: dashboard.php?error=" . urlencode("Error al guardar los cambios."));
    exit();
}
