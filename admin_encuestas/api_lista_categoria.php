<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || !hasRole('AdminEncuestas')) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$pdo = getDB();
$categoria = sanitizeInput($_GET['categoria'] ?? '');
$rondaId = sanitizeInput($_GET['ronda_id'] ?? '');

if (empty($categoria)) {
    echo json_encode(['success' => false, 'message' => 'Categoría no especificada']);
    exit;
}

$sqlBase = "
    SELECT r.id as referido_id, r.cedula, CONCAT(r.nombres, ' ', r.apellidos) as nombre_completo,
           r.celular, r.comuna, r.votante_yopal, r.puesto_votacion, r.mesa_votacion,
           COUNT(re.id) as total_rondas,
           COUNT(DISTINCT re.candidato_elegido) as candidatos_distintos,
           SUBSTRING_INDEX(GROUP_CONCAT(re.candidato_elegido ORDER BY re.creado_en DESC), ',', 1) as voto_ultimo,
           SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(re.observaciones, '') ORDER BY re.creado_en DESC), ',', 1) as ultima_observacion,
           MAX(re.creado_en) as ultima_fecha
    FROM referidos r
    JOIN respuestas_encuestas re ON re.referido_id = r.id
    " . ($rondaId !== '' ? "WHERE re.ronda_id = " . intval($rondaId) . " " : "") . "
";

$havingClauses = [];
$params = [];
$titulo = "Lista de Afiliados";

if ($categoria === 'no_contesto') {
    $titulo = "Afiliados que No Contestaron / Sin Respuesta";
    $havingClauses[] = "voto_ultimo LIKE '%No Contestó%'";
} elseif ($categoria === 'no_contesto_equivocado') {
    $titulo = "Afiliados que No Contestaron o con Número Equivocado";
    $havingClauses[] = "(voto_ultimo LIKE '%No Contestó%' OR voto_ultimo LIKE '%Equivocado%')";
} elseif ($categoria === 'cedula_falsa') {
    $titulo = "Afiliados marcados con Cédula Falsa / Inexistente";
    $havingClauses[] = "voto_ultimo LIKE '%Cédula Falsa%'";
} elseif ($categoria === 'equivocado') {
    $titulo = "Afiliados con Número Equivocado / Inaccesible";
    $havingClauses[] = "voto_ultimo LIKE '%Equivocado%'";
} elseif ($categoria === 'rechazo') {
    $titulo = "Afiliados que Rechazaron la Encuesta";
    $havingClauses[] = "voto_ultimo LIKE '%Rechazó%'";
} elseif ($categoria === 'fieles_todos') {
    $titulo = "Todos los Votantes Fieles (100% Leal)";
    $havingClauses[] = $rondaId !== '' ? "voto_ultimo NOT LIKE '%Indeciso%' AND voto_ultimo NOT LIKE '%No Contestó%' AND voto_ultimo NOT LIKE '%Cédula Falsa%' AND voto_ultimo NOT LIKE '%Equivocado%' AND voto_ultimo NOT LIKE '%Rechazó%'" : "total_rondas > 1 AND candidatos_distintos = 1 AND voto_ultimo NOT LIKE '%Indeciso%' AND voto_ultimo NOT LIKE '%No Contestó%' AND voto_ultimo NOT LIKE '%Cédula Falsa%' AND voto_ultimo NOT LIKE '%Equivocado%' AND voto_ultimo NOT LIKE '%Rechazó%'";
} elseif (strpos($categoria, 'fiel_') === 0) {
    $cand = substr($categoria, 5);
    $titulo = "Votantes 100% Fieles a: " . $cand;
    $havingClauses[] = $rondaId !== '' ? "voto_ultimo = ?" : "total_rondas > 1 AND candidatos_distintos = 1 AND voto_ultimo = ?";
    $params[] = $cand;
} elseif (strpos($categoria, 'candidato_') === 0) {
    $cand = substr($categoria, 10);
    $titulo = "Afiliados que Votaron por: " . $cand;
    $havingClauses[] = "voto_ultimo = ?";
    $params[] = $cand;
}

if ($categoria === 'todas_encuestas') {
    $titulo = "Historial Completo de Encuestas Registradas";
    $sqlFinal = "
        SELECT re.id as referido_id, r.cedula, CONCAT(r.nombres, ' ', r.apellidos) as nombre_completo,
               r.celular, r.comuna, r.votante_yopal, r.puesto_votacion, r.mesa_votacion,
               re.candidato_elegido as voto_ultimo,
               re.observaciones as ultima_observacion,
               re.creado_en as ultima_fecha
        FROM respuestas_encuestas re
        JOIN referidos r ON re.referido_id = r.id
        ORDER BY re.creado_en DESC LIMIT 500
    ";
    $stmt = $pdo->prepare($sqlFinal);
    $stmt->execute();
    $rows = $stmt->fetchAll();
} elseif ($categoria === 'encuestadoras') {
    $titulo = "Equipo de Encuestadoras Registradas";
    $sqlFinal = "
        SELECT u.id as referido_id, u.cedula, u.nombre_completo,
               u.telefono as celular, 'Operativo' as comuna, 'N/A' as votante_yopal,
               '' as puesto_votacion, '' as mesa_votacion,
               'Encuestadora' as voto_ultimo,
               CONCAT('Activa desde ', DATE_FORMAT(u.creado_en, '%d/%m/%Y')) as ultima_observacion,
               u.creado_en as ultima_fecha
        FROM usuarios u
        WHERE u.role_id = 4
        ORDER BY u.id ASC
    ";
    $stmt = $pdo->prepare($sqlFinal);
    $stmt->execute();
    $rows = $stmt->fetchAll();
} elseif ($categoria === 'base_afiliados') {
    $titulo = "Base General de Afiliados Registrados";
    $sqlFinal = "
        SELECT r.id as referido_id, r.cedula, CONCAT(r.nombres, ' ', r.apellidos) as nombre_completo,
               r.celular, r.comuna, r.votante_yopal, r.puesto_votacion, r.mesa_votacion,
               'Sin encuestar' as voto_ultimo,
               '' as ultima_observacion,
               r.creado_en as ultima_fecha
        FROM referidos r
        ORDER BY r.creado_en DESC LIMIT 500
    ";
    $stmt = $pdo->prepare($sqlFinal);
    $stmt->execute();
    $rows = $stmt->fetchAll();
} else {
    $sqlFinal = $sqlBase . " GROUP BY r.id " . (!empty($havingClauses) ? " HAVING " . implode(" AND ", $havingClauses) : "") . " ORDER BY ultima_fecha DESC LIMIT 500";
    $stmt = $pdo->prepare($sqlFinal);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

$lista = [];
foreach ($rows as $row) {
    $lista[] = [
        'referido_id' => $row['referido_id'],
        'nombre_completo' => $row['nombre_completo'],
        'cedula' => $row['cedula'],
        'celular' => $row['celular'],
        'comuna' => $row['comuna'],
        'votante_yopal' => $row['votante_yopal'],
        'puesto_votacion' => $row['puesto_votacion'] ?: 'Sin registrar',
        'mesa_votacion' => $row['mesa_votacion'] ?: 'N/A',
        'voto_ultimo' => $row['voto_ultimo'],
        'observaciones' => $row['ultima_observacion'] ?: 'Sin observaciones',
        'ultima_fecha' => date('d/m/Y H:i', strtotime($row['ultima_fecha']))
    ];
}

echo json_encode([
    'success' => true,
    'titulo' => $titulo,
    'total' => count($lista),
    'lista' => $lista
]);
