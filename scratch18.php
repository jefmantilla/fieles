<?php
$file = 'c:\xampp\htdocs\Aplicaiones\fieles\encuestadora\dashboard.php';
$content = file_get_contents($file);

$headerOld = <<<HTML
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0 text-dark">
                    <?php if (\$modoCola === 'reintento_nocontesto'): ?>
                        <i class="fas fa-redo-alt me-2 text-warning"></i> Reintento de Llamada - Ronda #<?= \$rondaActiva['numero_ronda'] ?>
                    <?php else: ?>
                        <i class="fas fa-user-clock me-2 text-warning"></i> Encuesta Ronda #<?= \$rondaActiva['numero_ronda'] ?> <?= \$personaActual['total_rondas_previas'] > 0 ? '(Seguimiento #' . (\$personaActual['total_rondas_previas'] + 1) . ')' : '(Inicial)' ?>
                    <?php endif; ?>
                </h5>
                <span class="badge <?= \$modoCola === 'reintento_nocontesto' ? 'bg-dark text-white' : 'bg-warning text-dark' ?> fs-6"><i class="fas fa-id-card me-1"></i>CC: <?= e(\$personaActual['cedula']) ?></span>
            </div>
HTML;

$headerNew = <<<HTML
            <!-- <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center d-none"> 
                 (Header ocultado por solicitud del usuario) 
            </div> -->
HTML;

$content = str_replace($headerOld, $headerNew, $content);

file_put_contents($file, $content);
echo "ok";
