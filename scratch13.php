<?php
$file = 'c:\xampp\htdocs\Aplicaiones\fieles\encuestadora\dashboard.php';
$content = file_get_contents($file);

// Hide Pregunta Estado de Votación en Yopal
$content = str_replace('<div class="mb-4 p-3 border rounded bg-white" id="seccionEstadoVotacion">', '<div class="mb-4 p-3 border rounded bg-white d-none" id="seccionEstadoVotacion">', $content);

// Hide CASILLAS OBLIGATORIAS PARA PUESTO DE VOTACIÓN Y NÚMERO DE MESA
$content = str_replace('<div class="mb-4 p-3 border rounded bg-light" id="seccionPuestoMesa">', '<div class="mb-4 p-3 border rounded bg-light d-none" id="seccionPuestoMesa">', $content);

// Remove 'required' from those inputs so the form can submit when hidden
$content = preg_replace('/<input class="form-check-input" type="radio" name="votante_yopal_respuesta"([^>]+)required>/', '<input class="form-check-input" type="radio" name="votante_yopal_respuesta"$1>', $content);
$content = str_replace('id="puesto_votacion" class="form-control" placeholder="Nombre del puesto de votación (Colegio / Escuela)..." value="<?= e($personaActual[\'puesto_votacion\'] ?? \'\') ?>" required>', 'id="puesto_votacion" class="form-control" placeholder="Nombre del puesto de votación (Colegio / Escuela)..." value="<?= e($personaActual[\'puesto_votacion\'] ?? \'\') ?>">', $content);
$content = str_replace('id="mesa_votacion" class="form-control" placeholder="Número de Mesa" min="1" max="500" value="<?= e($personaActual[\'mesa_votacion\'] ?? \'\') ?>" required>', 'id="mesa_votacion" class="form-control" placeholder="Número de Mesa" min="1" max="500" value="<?= e($personaActual[\'mesa_votacion\'] ?? \'\') ?>">', $content);

file_put_contents($file, $content);
echo "ok";
