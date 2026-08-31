<?php
$file = 'c:\xampp\htdocs\Aplicaiones\fieles\admin\usuarios.php';
$content = file_get_contents($file);

$content = str_replace('Líder', 'Usuario', $content);
$content = str_replace('lider', 'usuario', $content);
$content = str_replace('LIDER', 'USUARIO', $content);
$content = str_replace('Líderes', 'Usuarios', $content);
$content = str_replace('lideres', 'usuarios', $content);

// Fix role_id selector in Edit and Create Modals
$roleSelectorHTML = <<<HTML
                        <div class="mb-3">
                            <label class="form-label fw-bold">Rol en el Sistema <span class="text-danger">*</span></label>
                            <select name="role_id" class="form-select" required>
                                <option value="1">Admin (Jefe Supremo)</option>
                                <option value="2" selected>Líder</option>
                                <option value="3">Admin Encuestas</option>
                                <option value="4">Encuestadora</option>
                            </select>
                        </div>
HTML;

$editRoleSelectorHTML = <<<HTML
                        <div class="mb-3">
                            <label class="form-label fw-bold">Rol en el Sistema <span class="text-danger">*</span></label>
                            <select name="role_id" id="edit_role_id" class="form-select" required>
                                <option value="1">Admin (Jefe Supremo)</option>
                                <option value="2">Líder</option>
                                <option value="3">Admin Encuestas</option>
                                <option value="4">Encuestadora</option>
                            </select>
                        </div>
HTML;

// Add it to modalCrearusuario before password
$content = str_replace('<div class="mb-3">
                            <label class="form-label fw-bold">Contraseña', $roleSelectorHTML . "\n" . '<div class="mb-3">
                            <label class="form-label fw-bold">Contraseña', $content);

$content = str_replace('<div class="mb-3">
                            <label class="form-label fw-bold text-danger">Nueva Contraseña', $editRoleSelectorHTML . "\n" . '<div class="mb-3">
                            <label class="form-label fw-bold text-danger">Nueva Contraseña', $content);

// Make sure edit modal sets role_id
$content = str_replace('document.getElementById(\'edit_usuario_id\').value = id;', "document.getElementById('edit_usuario_id').value = id;\n        document.getElementById('edit_role_id').value = btn.getAttribute('data-role');", $content);

// In the table output, add data-role
$content = str_replace('data-usuario="\' . e($u[\'username\']) . \'"', 'data-usuario="\' . e($u[\'username\']) . \'" data-role="\' . $u[\'role_id\'] . \'"', $content);

file_put_contents($file, $content);
echo "ok";
