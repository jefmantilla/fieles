<?php
$file = 'c:\xampp\htdocs\Aplicaiones\fieles\encuestadora\dashboard.php';
$content = file_get_contents($file);

// Comment out the javascript required logic
$content = preg_replace('/radioVotanteYopal\.forEach\(r => r\.setAttribute\(\'required\', \'required\'\)\);/', '// radioVotanteYopal.forEach(r => r.setAttribute(\'required\', \'required\'));', $content);
$content = preg_replace('/if \(inputPuesto\) inputPuesto\.setAttribute\(\'required\', \'required\'\);/', '// if (inputPuesto) inputPuesto.setAttribute(\'required\', \'required\');', $content);
$content = preg_replace('/if \(inputMesa\) inputMesa\.setAttribute\(\'required\', \'required\'\);/', '// if (inputMesa) inputMesa.setAttribute(\'required\', \'required\');', $content);

file_put_contents($file, $content);
echo "ok";
