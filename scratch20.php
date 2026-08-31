<?php
$file = 'c:\xampp\htdocs\Aplicaiones\fieles\encuestadora\dashboard.php';
$content = file_get_contents($file);

$blockToHide = '<div class="card card-custom mb-4 shadow-sm border">';
$hiddenBlock = '<div class="card card-custom mb-4 shadow-sm border d-none">';

$content = str_replace(
    '<!-- Selector de Modo de Cola: Iniciales vs Reintento de No Contestaron -->
    <div class="card card-custom mb-4 shadow-sm border">',
    '<!-- Selector de Modo de Cola: Iniciales vs Reintento de No Contestaron -->
    <div class="card card-custom mb-4 shadow-sm border d-none">',
    $content
);

file_put_contents($file, $content);
echo "ok";
