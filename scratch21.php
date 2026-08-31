<?php
$file = 'c:\xampp\htdocs\Aplicaiones\fieles\encuestadora\dashboard.php';
$content = file_get_contents($file);

// 1. Re-enable mode selector
$content = str_replace(
    '<!-- Selector de Modo de Cola: Iniciales vs Reintento de No Contestaron -->
    <div class="card card-custom mb-4 shadow-sm border d-none">',
    '<!-- Selector de Modo de Cola: Iniciales vs Reintento de No Contestaron -->
    <div class="card card-custom mb-4 shadow-sm border">',
    $content
);

// 2. Hide "Cédula Falsa / Inexistente" option
// It looks like:
// <div class="col-md-3 col-6">
//     <input type="radio" class="btn-check" name="candidato" id="opt_cedula_falsa" value="Cédula Falsa / Inexistente" required>
//     <label class="cand-option-card card-cedula-falsa border-dark w-100 d-block" for="opt_cedula_falsa">
//         <div class="fw-bold text-dark small"><i class="fas fa-user-times text-danger me-1"></i>Cédula Falsa</div>
//         <div class="text-muted" style="font-size: 0.73rem;">Errónea / Inexistente</div>
//     </label>
// </div>

// We can safely add "d-none" to its wrapping div.
// Let's use preg_replace or str_replace to inject d-none.
// Wait, replacing the class of that specific div.
$cedulaHtml = <<<HTML
                            <div class="col-md-3 col-6">
                                <input type="radio" class="btn-check" name="candidato" id="opt_cedula_falsa" value="Cédula Falsa / Inexistente" required>
HTML;

$cedulaHtmlNew = <<<HTML
                            <div class="col-md-3 col-6 d-none">
                                <input type="radio" class="btn-check" name="candidato" id="opt_cedula_falsa" value="Cédula Falsa / Inexistente" required>
HTML;

$content = str_replace($cedulaHtml, $cedulaHtmlNew, $content);

file_put_contents($file, $content);
echo "ok";
