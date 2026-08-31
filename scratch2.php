<?php
$file = 'c:\xampp\htdocs\Aplicaiones\fieles\admin_encuestas\fidelidad.php';
$content = file_get_contents($file);

// 1. Add $rondaIdFiltro
$content = str_replace(
    "\$buscarText = sanitizeInput(\$_GET['buscar'] ?? '');",
    "\$buscarText = sanitizeInput(\$_GET['buscar'] ?? '');\n\$rondaIdFiltro = sanitizeInput(\$_GET['ronda_id'] ?? '');",
    $content
);

// 2. Fetch rondas
$content = str_replace(
    "\$candidatosActivos = \$stmtCandList->fetchAll();",
    "\$candidatosActivos = \$stmtCandList->fetchAll();\n\n\$stmtRondas = \$pdo->query(\"SELECT id, nombre_ronda FROM rondas_encuestas ORDER BY id ASC\");\n\$todasRondas = \$stmtRondas->fetchAll();",
    $content
);

// 3. Update SQL
$sqlOld = "    FROM referidos r\n    JOIN respuestas_encuestas re ON re.referido_id = r.id\n    GROUP BY r.id";
$sqlNew = "    FROM referidos r\n    JOIN respuestas_encuestas re ON re.referido_id = r.id\n    \" . (\$rondaIdFiltro !== '' ? \"WHERE re.ronda_id = \" . intval(\$rondaIdFiltro) . \" \" : \"\") . \"\n    GROUP BY r.id";
$content = str_replace($sqlOld, $sqlNew, $content);

// 4. Update the counting loop
$loopOld = "} elseif (\$v['total_rondas'] == 1) {
        \$countUnaSola++;
    } elseif (\$v['candidatos_distintos'] == 1 && strpos(strtolower(\$ultimo), 'indeciso') === false) {
        \$countTotalFieles++;
        if (isset(\$fielesPorCandidato[\$ultimo])) {
            \$fielesPorCandidato[\$ultimo]++;
        } else {
            \$fielesPorCandidato[\$ultimo] = 1;
        }
    } elseif (\$v['candidatos_distintos'] > 1) {
        \$countCambiantes++;
    } else {
        \$countIndecisos++;
    }";

$loopNew = "} elseif (strpos(strtolower(\$ultimo), 'indeciso') !== false) {
        \$countIndecisos++;
    } elseif (\$rondaIdFiltro === '' && \$v['total_rondas'] == 1) {
        \$countUnaSola++;
    } elseif (\$rondaIdFiltro !== '' || \$v['candidatos_distintos'] == 1) {
        \$countTotalFieles++;
        if (isset(\$fielesPorCandidato[\$ultimo])) {
            \$fielesPorCandidato[\$ultimo]++;
        } else {
            \$fielesPorCandidato[\$ultimo] = 1;
        }
    } elseif (\$v['candidatos_distintos'] > 1) {
        \$countCambiantes++;
    }";
$content = str_replace($loopOld, $loopNew, $content);

// 5. Update HAVING clause for 'fiel'
$havingOld = "\$havingClauses[] = \"total_rondas > 1 AND candidatos_distintos = 1 AND voto_ultimo NOT LIKE '%Indeciso%' AND voto_ultimo NOT LIKE '%No Contestó%' AND voto_ultimo NOT LIKE '%Cédula Falsa%' AND voto_ultimo NOT LIKE '%Equivocado%' AND voto_ultimo NOT LIKE '%Rechazó%'\";";
$havingNew = "\$havingClauses[] = \$rondaIdFiltro !== '' ? \"voto_ultimo NOT LIKE '%Indeciso%' AND voto_ultimo NOT LIKE '%No Contestó%' AND voto_ultimo NOT LIKE '%Cédula Falsa%' AND voto_ultimo NOT LIKE '%Equivocado%' AND voto_ultimo NOT LIKE '%Rechazó%'\" : \"total_rondas > 1 AND candidatos_distintos = 1 AND voto_ultimo NOT LIKE '%Indeciso%' AND voto_ultimo NOT LIKE '%No Contestó%' AND voto_ultimo NOT LIKE '%Cédula Falsa%' AND voto_ultimo NOT LIKE '%Equivocado%' AND voto_ultimo NOT LIKE '%Rechazó%'\";";
$content = str_replace($havingOld, $havingNew, $content);

// 6. Update the dropdown form HTML to include ronda_id
$formHtmlOld = "<div class=\"col-md-5\">
                    <label class=\"form-label small fw-bold text-muted mb-1\"><i class=\"fas fa-filter me-1\"></i>Filtrar por Candidato o Estado de Llamada:</label>";
$formHtmlNew = "<div class=\"col-md-3\">
                    <label class=\"form-label small fw-bold text-muted mb-1\"><i class=\"fas fa-history me-1\"></i>Ronda de Encuesta:</label>
                    <select name=\"ronda_id\" class=\"form-select form-select-sm\">
                        <option value=\"\">-- Todas las Rondas (Matriz Histórica) --</option>
                        <?php foreach (\$todasRondas as \$ronda): ?>
                            <option value=\"<?= \$ronda['id'] ?>\" <?= \$rondaIdFiltro == \$ronda['id'] ? 'selected' : '' ?>><?= e(\$ronda['nombre_ronda']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class=\"col-md-4\">
                    <label class=\"form-label small fw-bold text-muted mb-1\"><i class=\"fas fa-filter me-1\"></i>Filtrar por Candidato o Estado:</label>";
$content = str_replace($formHtmlOld, $formHtmlNew, $content);

// Fix col sizes in the form
$content = str_replace("<div class=\"col-md-4\">\n                    <label class=\"form-label small fw-bold text-muted mb-1\"><i class=\"fas fa-search me-1\"></i>Buscar Afiliado:</label>", "<div class=\"col-md-3\">\n                    <label class=\"form-label small fw-bold text-muted mb-1\"><i class=\"fas fa-search me-1\"></i>Buscar Afiliado:</label>", $content);

$content = str_replace("<div class=\"col-md-3 d-flex align-items-end justify-content-end gap-2 pt-3\">", "<div class=\"col-md-2 d-flex align-items-end justify-content-end gap-2 pt-3\">", $content);

// 7. Update text on Cards dynamically
$cardTitleOld = "<h6 class=\"text-uppercase text-muted fw-bold small mb-1\">Fieles (100% Leal)</h6>";
$cardTitleNew = "<h6 class=\"text-uppercase text-muted fw-bold small mb-1\"><?= \$rondaIdFiltro !== '' ? 'Votos en esta Ronda' : 'Fieles (100% Leal)' ?></h6>";
$content = str_replace($cardTitleOld, $cardTitleNew, $content);

// 8. Update options in select for Fieles
$optionFielOld = "<option value=\"fiel\" <?= \$filtroFidelidad === 'fiel' ? 'selected' : '' ?>>🟩 Todos los Votantes Fieles (<?= \$countTotalFieles ?>)</option>";
$optionFielNew = "<option value=\"fiel\" <?= \$filtroFidelidad === 'fiel' ? 'selected' : '' ?>>🟩 <?= \$rondaIdFiltro !== '' ? 'Votos Válidos' : 'Todos los Votantes Fieles' ?> (<?= \$countTotalFieles ?>)</option>";
$content = str_replace($optionFielOld, $optionFielNew, $content);

$optionCandOld = "➔ Fieles a: <?= e(\$c['nombre']) ?> (<?= \$fielesPorCandidato[\$c['nombre']] ?? 0 ?>)";
$optionCandNew = "➔ <?= \$rondaIdFiltro !== '' ? 'Votos por' : 'Fieles a' ?>: <?= e(\$c['nombre']) ?> (<?= \$fielesPorCandidato[\$c['nombre']] ?? 0 ?>)";
$content = str_replace($optionCandOld, $optionCandNew, $content);


// 9. Change API link to include ronda_id
$apiLinkOld = "fetch('api_lista_categoria.php?categoria=' + encodeURIComponent(categoriaKey))";
$apiLinkNew = "fetch('api_lista_categoria.php?categoria=' + encodeURIComponent(categoriaKey) + '&ronda_id=<?= urlencode(\$rondaIdFiltro) ?>')";
$content = str_replace($apiLinkOld, $apiLinkNew, $content);


file_put_contents($file, $content);
echo "Done";
