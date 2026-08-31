<?php
$file = 'c:\xampp\htdocs\Aplicaiones\fieles\admin\usuarios.php';
$content = file_get_contents($file);

$content = str_replace('usuario_raiz_id', 'lider_raiz_id', $content);

file_put_contents($file, $content);
echo "ok";
