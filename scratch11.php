<?php
$file = 'c:\xampp\htdocs\Aplicaiones\fieles\admin\usuarios.php';
$content = file_get_contents($file);

$btnEliminar = <<<HTML
<form method="POST" class="d-inline" onsubmit="return confirm('¿Estás seguro de que deseas eliminar este usuario de forma permanente?');">
    <input type="hidden" name="csrf_token" value="<?= \$csrfToken ?>">
    <input type="hidden" name="accion" value="eliminar_usuario">
    <input type="hidden" name="usuario_id" value="<?= \$u['id'] ?>">
    <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar Usuario">
        <i class="fas fa-trash"></i>
    </button>
</form>
HTML;

// Find where the edit button is outputted and add the delete button next to it.
// The edit button in lideres.php looks like: <button class="btn btn-sm btn-outline-primary" onclick="..." title="Editar Líder"> <i class="fas fa-edit"></i> </button>
// Which I replaced to "Editar Usuario" 

$editBtnSearch = '<i class="fas fa-edit"></i>';
$editBtnFull = '                                                <button class="btn btn-sm btn-outline-primary" onclick="abrirModalEditar(this)" ';

// I'll just use str_replace on a known string in the table.
$content = preg_replace('/(<button class="btn btn-sm btn-outline-primary"[^>]*>.*?<\/button>)/s', "$1\n" . $btnEliminar, $content);

file_put_contents($file, $content);
echo "ok";
