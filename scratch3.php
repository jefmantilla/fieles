<?php
$file = 'c:\xampp\htdocs\Aplicaiones\fieles\admin_encuestas\api_lista_categoria.php';
$content = file_get_contents($file);

$sqlBaseOld = "    JOIN respuestas_encuestas re ON re.referido_id = r.id\n";
$sqlBaseNew = "    JOIN respuestas_encuestas re ON re.referido_id = r.id\n    \" . (\$rondaId !== '' ? \"WHERE re.ronda_id = \" . intval(\$rondaId) . \" \" : \"\") . \"\n";
$content = str_replace($sqlBaseOld, $sqlBaseNew, $content);

// Update Having for fiel_todos
$havingFielTodosOld = "\$havingClauses[] = \"total_rondas > 1 AND candidatos_distintos = 1 AND voto_ultimo NOT LIKE '%Indeciso%' AND voto_ultimo NOT LIKE '%No Contestó%' AND voto_ultimo NOT LIKE '%Cédula Falsa%' AND voto_ultimo NOT LIKE '%Equivocado%' AND voto_ultimo NOT LIKE '%Rechazó%'\";";
$havingFielTodosNew = "\$havingClauses[] = \$rondaId !== '' ? \"voto_ultimo NOT LIKE '%Indeciso%' AND voto_ultimo NOT LIKE '%No Contestó%' AND voto_ultimo NOT LIKE '%Cédula Falsa%' AND voto_ultimo NOT LIKE '%Equivocado%' AND voto_ultimo NOT LIKE '%Rechazó%'\" : \"total_rondas > 1 AND candidatos_distintos = 1 AND voto_ultimo NOT LIKE '%Indeciso%' AND voto_ultimo NOT LIKE '%No Contestó%' AND voto_ultimo NOT LIKE '%Cédula Falsa%' AND voto_ultimo NOT LIKE '%Equivocado%' AND voto_ultimo NOT LIKE '%Rechazó%'\";";
$content = str_replace($havingFielTodosOld, $havingFielTodosNew, $content);

// Update Having for fiel_X
$havingFielXOld = "\$havingClauses[] = \"total_rondas > 1 AND candidatos_distintos = 1 AND voto_ultimo = ?\";";
$havingFielXNew = "\$havingClauses[] = \$rondaId !== '' ? \"voto_ultimo = ?\" : \"total_rondas > 1 AND candidatos_distintos = 1 AND voto_ultimo = ?\";";
$content = str_replace($havingFielXOld, $havingFielXNew, $content);

file_put_contents($file, $content);
echo "Done";
