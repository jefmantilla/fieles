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
$referidoId = (int)($_GET['id'] ?? 0);

if (!$referidoId) {
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT r.id, r.candidato_elegido, r.votante_yopal_respuesta, r.puesto_votacion, r.mesa_votacion, r.observaciones, r.creado_en,
           u.nombre_completo as encuestadora_nombre
    FROM respuestas_encuestas r
    JOIN usuarios u ON r.encuestadora_id = u.id
    WHERE r.referido_id = ?
    ORDER BY r.creado_en ASC
");
$stmt->execute([$referidoId]);
$rows = $stmt->fetchAll();

$historial = [];
foreach ($rows as $row) {
    $historial[] = [
        'id' => $row['id'],
        'candidato' => $row['candidato_elegido'],
        'votante_yopal' => $row['votante_yopal_respuesta'],
        'puesto_votacion' => $row['puesto_votacion'],
        'mesa_votacion' => $row['mesa_votacion'],
        'observaciones' => $row['observaciones'],
        'encuestadora' => $row['encuestadora_nombre'],
        'fecha' => date('d/m/Y H:i', strtotime($row['creado_en']))
    ];
}

echo json_encode(['success' => true, 'historial' => $historial]);
