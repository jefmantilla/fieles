<?php
$file = 'c:\xampp\htdocs\Aplicaiones\fieles\encuestadora\dashboard.php';
$content = file_get_contents($file);

$regex = '/\n                    <!-- Preguntas Adicionales Configuradas por AdminEncuestas -->.*?<\?php endif; \?>/s';
if (preg_match($regex, $content, $matches)) {
    $preguntasBlock = $matches[0];
    
    // Replace '($numP + 2)' with '($numP + 1)'
    $preguntasBlock = str_replace('<?= ($numP + 2) ?>', '<?= ($numP + 1) ?>', $preguntasBlock);

    // Remove block
    $content = str_replace($matches[0], '', $content);

    // Also we need to change the candidate question to be the last number.
    // However, the candidate question number is hardcoded in the question text or doesn't have a number.
    // The user didn't ask to change the candidate question numbering, just move this one up.
    
    $target = '<!-- SELECCIÓN DE CANDIDATO O ESTADO DE LA LLAMADA -->';
    
    // Add block before target
    $content = str_replace($target, ltrim($preguntasBlock) . "\n\n                    " . $target, $content);
    
    file_put_contents($file, $content);
    echo "ok";
} else {
    echo "not found";
}
