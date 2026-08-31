<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || !hasRole('Lider')) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$user = getCurrentUser();
$pdo = getDB();
$filtro = sanitizeInput($_GET['filtro'] ?? '');

if (empty($filtro)) {
    echo json_encode(['success' => false, 'message' => 'Filtro no especificado']);
    exit;
}

$whereClauses = ["r.lider_raiz_id = ?"];
$params = [$user['id']];
$titulo = "Listado de Integrantes de la Red";

if ($filtro === 'directos') {
    $titulo = "Integrantes Directos Míos";
    $whereClauses[] = "r.referido_por_tipo = 'usuario'";
} elseif ($filtro === 'indirectos') {
    $titulo = "Red Indirecta (Multinivel)";
    $whereClauses[] = "r.referido_por_tipo = 'referido'";
} elseif ($filtro === 'si') {
    $titulo = "Integrantes que Votan en Yopal";
    $whereClauses[] = "r.votante_yopal = 'Si'";
} elseif ($filtro === 'inscribir') {
    $titulo = "Integrantes que Quieren Inscribir Cédula";
    $whereClauses[] = "r.votante_yopal = 'Quiero inscribir'";
} elseif ($filtro === 'no') {
    $titulo = "Integrantes No Votantes / Otro Municipio / Sin definir";
    $whereClauses[] = "(r.votante_yopal = 'No' OR r.votante_yopal = 'Sin consultar' OR r.votante_yopal IS NULL)";
} elseif ($filtro === 'total') {
    $titulo = "Total Registrados en mi Red";
}

$sql = "
    SELECT r.*,
           CONCAT(r.nombres, ' ', r.apellidos) as nombre_completo,
           (SELECT COUNT(*) FROM referidos WHERE referido_por_tipo = 'referido' AND referido_por_id = r.id) as sub_referidos_count,
           CASE 
             WHEN r.referido_por_tipo = 'usuario' THEN u.nombre_completo 
             ELSE CONCAT(p.nombres, ' ', p.apellidos) 
           END as invitador_nombre
    FROM referidos r
    LEFT JOIN usuarios u ON (r.referido_por_tipo = 'usuario' AND r.referido_por_id = u.id)
    LEFT JOIN referidos p ON (r.referido_por_tipo = 'referido' AND r.referido_por_id = p.id)
    WHERE " . implode(" AND ", $whereClauses) . "
    ORDER BY r.creado_en DESC
    LIMIT 500
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$lista = [];
foreach ($rows as $row) {
    $lista[] = [
        'id' => $row['id'],
        'cedula' => $row['cedula'],
        'nombre_completo' => $row['nombre_completo'],
        'celular' => $row['celular'],
        'comuna' => $row['comuna'],
        'votante_yopal' => $row['votante_yopal'] ?: 'Sin consultar',
        'verificado' => (!empty($row['puesto_votacion']) && strpos($row['puesto_votacion'], 'PROCESO') === false),
        'sub_referidos_count' => (int)$row['sub_referidos_count'],
        'invitador_nombre' => $row['invitador_nombre'] ?: 'Directo',
        'fecha' => date('d/m/Y H:i', strtotime($row['creado_en']))
    ];
}

echo json_encode([
    'success' => true,
    'titulo' => $titulo,
    'total' => count($lista),
    'lista' => $lista
]);
