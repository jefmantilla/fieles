<?php
$file = 'c:\xampp\htdocs\Aplicaiones\fieles\encuestadora\dashboard.php';
$content = file_get_contents($file);

// 1. Remove additional data below name
$pattern1 = '/<div class="text-muted small">\s*<i class="fas fa-map-marker-alt me-1 text-danger"><\/i><strong>Sector:<\/strong> <\?= e\(\$personaActual\[\'comuna\'\]\) \?>\s*<\/div>/s';
$content = preg_replace($pattern1, '', $content);

$pattern2 = '/<div class="text-muted small mt-1">\s*<i class="fas fa-building me-1 text-primary"><\/i><strong>Puesto actual:<\/strong>[^<]+<\?= !empty\(\$personaActual\[\'puesto_votacion\'\]\) \? e\(\$personaActual\[\'puesto_votacion\'\]\) : \'<span class="text-muted">Sin registrar<\/span>\' \?> \| \s*<i class="fas fa-sort-numeric-up me-1 text-primary"><\/i><strong>Mesa:<\/strong>[^<]+<\?= !empty\(\$personaActual\[\'mesa_votacion\'\]\) \? e\(\$personaActual\[\'mesa_votacion\'\]\) : \'<span class="text-muted">N\/A<\/span>\' \?>\s*<\/div>/s';
$content = preg_replace($pattern2, '', $content);


// 2. Move "Preguntas Adicionales" block before "SELECCIÓN DE CANDIDATO"
// Extract Preguntas Adicionales
$pregAdicionalesStart = '<!-- Preguntas Adicionales Configuradas por AdminEncuestas -->';
$pregAdicionalesEnd = '<?php endif; ?>';
$pregStartPos = strpos($content, $pregAdicionalesStart);

// We need to carefully extract the block.
// Let's use preg_match to extract it safely.
$regex = '/<!-- Preguntas Adicionales Configuradas por AdminEncuestas -->.*?<\?php endif; \?>/s';
if (preg_match($regex, $content, $matches)) {
    $preguntasBlock = $matches[0];
    
    // Remove it from its original location
    $content = str_replace($preguntasBlock, '', $content);
    
    // Insert it before SELECCIÓN DE CANDIDATO
    $targetPos = '<!-- SELECCIÓN DE CANDIDATO O ESTADO DE LA LLAMADA -->';
    
    // Also, change the label number from <?= ($numP + 2) ?> to <?= ($numP + 1) ?> because it's first now
    // Wait, the main question is "preguntaCandidato". If additional questions go first, they become 1.
    // Let's change <?= ($numP + 2) ?> to <?= ($numP + 1) ?>
    $preguntasBlock = str_replace('<?= ($numP + 2) ?>', '<?= ($numP + 1) ?>', $preguntasBlock);
    
    $content = str_replace($targetPos, $preguntasBlock . "\n\n                    " . $targetPos, $content);
    
    // Also, the main candidate question should now say the last number.
    // It currently has no number: <i class="fas fa-question-circle me-2 text-primary"></i><?= e($preguntaCandidato) ?> *
    // Actually the user said "esta pregunta 2. ... deberia ir arriba".
    // So the original candidate question didn't have a number, but the additional ones had `($numP + 2)`.
    // Let's just put the additional questions block before the candidates.
}

file_put_contents($file, $content);
echo "ok";
