<?php
$file = 'c:\xampp\htdocs\Aplicaiones\fieles\admin\usuarios.php';
$content = file_get_contents($file);

// Replace page title
$content = str_replace('Gestión de Líderes y Referidos Promovidos', 'Gestión Global de Usuarios (Jefe Supremo)', $content);
$content = str_replace('<title>Gestión de Líderes - Proyecto Político Social</title>', '<title>Gestión de Usuarios - Proyecto Político Social</title>', $content);

// Replace actions
$content = str_replace("$_POST['accion'] === 'crear_lider'", "$_POST['accion'] === 'crear_usuario'", $content);
$content = str_replace("$_POST['accion'] === 'editar_lider'", "$_POST['accion'] === 'editar_usuario'", $content);

// In crear_lider block, add role_id from POST
$crearLiderOld = "\$password = \$_POST['password'] ?? '';\n\n        if (empty(\$nombre) || empty(\$cedula) || empty(\$telefono) || empty(\$username) || empty(\$password)) {";
$crearLiderNew = "\$password = \$_POST['password'] ?? '';\n        \$role_id = (int)(\$_POST['role_id'] ?? 2);\n\n        if (empty(\$nombre) || empty(\$cedula) || empty(\$username) || empty(\$password) || empty(\$role_id)) {";
$content = str_replace($crearLiderOld, $crearLiderNew, $content);

$insertOld = "\$stmtIns = \$pdo->prepare(\"INSERT INTO usuarios (nombre_completo, cedula, telefono, username, password, role_id, codigo_referido) VALUES (?, ?, ?, ?, ?, 2, ?)\");\n                if (\$stmtIns->execute([\$nombre, \$cedula, \$telefono, \$username, \$hash, \$codigoRef])) {";
$insertNew = "\$stmtIns = \$pdo->prepare(\"INSERT INTO usuarios (nombre_completo, cedula, telefono, username, password, role_id, codigo_referido) VALUES (?, ?, ?, ?, ?, ?, ?)\");\n                if (\$stmtIns->execute([\$nombre, \$cedula, \$telefono, \$username, \$hash, \$role_id, \$codigoRef])) {";
$content = str_replace($insertOld, $insertNew, $content);

$msgLiderCreated = "\$msg = \"¡Líder creado exitosamente! Código de referido: \" . \$codigoRef;";
$msgUserCreated = "\$msg = \"¡Usuario creado exitosamente!\";";
$content = str_replace($msgLiderCreated, $msgUserCreated, $content);

// In editar_lider block
$editarLiderOld = "\$liderId = (int)(\$_POST['lider_id'] ?? 0);\n        \$nombre = sanitizeInput(\$_POST['nombre_completo'] ?? '');";
$editarLiderNew = "\$liderId = (int)(\$_POST['usuario_id'] ?? 0);\n        \$nombre = sanitizeInput(\$_POST['nombre_completo'] ?? '');\n        \$role_id = (int)(\$_POST['role_id'] ?? 2);";
$content = str_replace($editarLiderOld, $editarLiderNew, $content);

$checkEditOld = "\$stmtCheck = \$pdo->prepare(\"SELECT COUNT(*) FROM usuarios WHERE (username = ? OR cedula = ?) AND id != ?\");";
$content = str_replace($checkEditOld, $checkEditOld, $content); // No change needed

$updateHashOld = "\$stmtUpd = \$pdo->prepare(\"UPDATE usuarios SET nombre_completo = ?, cedula = ?, telefono = ?, username = ?, password = ? WHERE id = ? AND role_id = 2\");";
$updateHashNew = "\$stmtUpd = \$pdo->prepare(\"UPDATE usuarios SET nombre_completo = ?, cedula = ?, telefono = ?, username = ?, password = ?, role_id = ? WHERE id = ?\");";
$content = str_replace($updateHashOld, $updateHashNew, $content);

$updateHashExecOld = "\$stmtUpd->execute([\$nombre, \$cedula, \$telefono, \$username, \$hash, \$liderId]);";
$updateHashExecNew = "\$stmtUpd->execute([\$nombre, \$cedula, \$telefono, \$username, \$hash, \$role_id, \$liderId]);";
$content = str_replace($updateHashExecOld, $updateHashExecNew, $content);

$updateOld = "\$stmtUpd = \$pdo->prepare(\"UPDATE usuarios SET nombre_completo = ?, cedula = ?, telefono = ?, username = ? WHERE id = ? AND role_id = 2\");";
$updateNew = "\$stmtUpd = \$pdo->prepare(\"UPDATE usuarios SET nombre_completo = ?, cedula = ?, telefono = ?, username = ?, role_id = ? WHERE id = ?\");";
$content = str_replace($updateOld, $updateNew, $content);

$updateExecOld = "\$stmtUpd->execute([\$nombre, \$cedula, \$telefono, \$username, \$liderId]);";
$updateExecNew = "\$stmtUpd->execute([\$nombre, \$cedula, \$telefono, \$username, \$role_id, \$liderId]);";
$content = str_replace($updateExecOld, $updateExecNew, $content);

$msgLiderUpdated = "\$msg = \"¡Datos y credenciales del líder actualizados correctamente!\";";
$msgUserUpdated = "\$msg = \"¡Datos del usuario actualizados correctamente!\";";
$content = str_replace($msgLiderUpdated, $msgUserUpdated, $content);

// Add Delete action logic just before Configuración de Filtro
$deleteLogic = <<<PHP
// 4. Eliminar Usuario (Solo Admin)
if (\$_SERVER['REQUEST_METHOD'] === 'POST' && isset(\$_POST['accion']) && \$_POST['accion'] === 'eliminar_usuario') {
    \$token = \$_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken(\$token)) {
        \$error = "Token de seguridad no válido.";
    } else {
        \$delUserId = (int)(\$_POST['usuario_id'] ?? 0);
        if (\$delUserId == \$admin['id']) {
            \$error = "No puedes eliminarte a ti mismo.";
        } else {
            \$pdo->prepare("DELETE FROM usuarios WHERE id = ?")->execute([\$delUserId]);
            \$msg = "Usuario eliminado exitosamente.";
        }
    }
}
PHP;
$content = str_replace("// Configuración de Filtro y Paginación para la Tabla de Líderes", $deleteLogic . "\n\n// Configuración de Filtro y Paginación para la Tabla de Usuarios", $content);

// Query for all users
$whereClausesOld = "\$whereClauses = [\"u.role_id = 2\"];";
$whereClausesNew = "\$whereClauses = [\"1=1\"];";
$content = str_replace($whereClausesOld, $whereClausesNew, $content);

$sqlLideresOld = "SELECT u.*, \n           (SELECT COUNT(*) FROM referidos WHERE lider_raiz_id = u.id) as total_red";
$sqlLideresNew = "SELECT u.*, r.nombre as role_name, \n           (SELECT COUNT(*) FROM referidos WHERE lider_raiz_id = u.id) as total_red";
$content = str_replace($sqlLideresOld, $sqlLideresNew, $content);

$fromUsuariosOld = "FROM usuarios u \n    \" . \$whereSql . \"";
$fromUsuariosNew = "FROM usuarios u \n    JOIN roles r ON u.role_id = r.id\n    \" . \$whereSql . \"";
$content = str_replace($fromUsuariosOld, $fromUsuariosNew, $content);

// Add "Gestionar Usuarios" to Navbar
$navbarOld = "<li class=\"nav-item\">\n                    <a class=\"nav-link text-white-50 fw-bold\" href=\"lideres.php\"><i class=\"fas fa-users-cog me-1 text-warning\"></i> Gestionar Líderes</a>\n                </li>";
$navbarNew = "<li class=\"nav-item\">\n                    <a class=\"nav-link text-white-50 fw-bold\" href=\"lideres.php\"><i class=\"fas fa-users-cog me-1 text-warning\"></i> Gestionar Líderes</a>\n                </li>\n                <li class=\"nav-item\">\n                    <a class=\"nav-link text-white fw-bold\" href=\"usuarios.php\"><i class=\"fas fa-user-shield me-1 text-danger\"></i> Gestionar Usuarios</a>\n                </li>";
$content = str_replace($navbarOld, $navbarNew, $content);

file_put_contents($file, $content);
echo "Done replacing logic";
