<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || !hasRole('Admin')) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$pdo = getDB();
$filtro = sanitizeInput($_GET['filtro'] ?? '');

if (empty($filtro)) {
    echo json_encode(['success' => false, 'message' => 'Filtro no especificado']);
    exit;
}

$titulo = "Listado General de la Plataforma";
$lista = [];

if ($filtro === 'lideres') {
    $titulo = "Listado de Líderes Activos";
    $sql = "
        SELECT u.id, u.cedula, u.nombre_completo, u.telefono as celular, 'Líder' as comuna,
               'N/A' as votante_yopal,
               (SELECT COUNT(*) FROM referidos WHERE lider_raiz_id = u.id) as sub_referidos_count,
               'Líder Principal' as invitador_nombre,
               u.creado_en
        FROM usuarios u
        WHERE u.role_id = 2
        ORDER BY u.creado_en DESC
        LIMIT 500
    ";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();
    foreach ($rows as $r) {
        $lista[] = [
            'id' => $r['id'],
            'cedula' => $r['cedula'],
            'nombre_completo' => $r['nombre_completo'],
            'celular' => $r['celular'],
            'comuna' => $r['comuna'],
            'votante_yopal' => $r['votante_yopal'],
            'sub_referidos_count' => (int)$r['sub_referidos_count'],
            'invitador_nombre' => $r['invitador_nombre'],
            'fecha' => date('d/m/Y H:i', strtotime($r['creado_en']))
        ];
    }
} else {
    $whereClauses = [];
    $params = [];

    if ($filtro === 'si') {
        $titulo = "Afiliados que Votan en Yopal";
        $whereClauses[] = "r.votante_yopal = 'Si'";
    } elseif ($filtro === 'inscribir') {
        $titulo = "Afiliados que Quieren Inscribir Cédula";
        $whereClauses[] = "r.votante_yopal = 'Quiero inscribir'";
    } elseif ($filtro === 'no') {
        $titulo = "Afiliados No Votantes / Otro Municipio / Sin consultar";
        $whereClauses[] = "(r.votante_yopal = 'No' OR r.votante_yopal = 'Sin consultar' OR r.votante_yopal IS NULL)";
    } elseif ($filtro === 'total') {
        $titulo = "Total de Referidos Registrados en el Sistema";
    }

    $whereSql = !empty($whereClauses) ? " WHERE " . implode(" AND ", $whereClauses) : "";

    $sql = "
        SELECT r.*,
               CONCAT(r.nombres, ' ', r.apellidos) as nombre_completo,
               l.nombre_completo as lider_raiz_nombre,
               (SELECT COUNT(*) FROM referidos WHERE referido_por_tipo = 'referido' AND referido_por_id = r.id) as sub_referidos_count
        FROM referidos r
        LEFT JOIN usuarios l ON r.lider_raiz_id = l.id
        " . $whereSql . "
        ORDER BY r.creado_en DESC
        LIMIT 500
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $lista[] = [
            'id' => $row['id'],
            'cedula' => $row['cedula'],
            'nombre_completo' => $row['nombre_completo'],
            'celular' => $row['celular'],
            'comuna' => $row['comuna'],
            'votante_yopal' => $row['votante_yopal'] ?: 'Sin consultar',
            'puesto_votacion' => $row['puesto_votacion'] ?: '',
            'mesa_votacion' => $row['mesa_votacion'] ?: '',
            'municipio' => $row['municipio'] ?: '',
            'departamento' => $row['departamento'] ?: '',
            'sub_referidos_count' => (int)$row['sub_referidos_count'],
            'invitador_nombre' => $row['lider_raiz_nombre'] ?: 'Sistema',
            'fecha' => date('d/m/Y H:i', strtotime($row['creado_en']))
        ];
    }
}

echo json_encode([
    'success' => true,
    'titulo' => $titulo,
    'total' => count($lista),
    'lista' => $lista
]);
