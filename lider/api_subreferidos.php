<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/auth.php';

requireRole('Lider');

header('Content-Type: application/json');

$user = getCurrentUser();
$pdo = getDB();

$referidoId = (int)($_GET['id'] ?? 0);

if (empty($referidoId)) {
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
    exit();
}

// Verificar que el referido padre pertenezca a la red raíz de este líder
$stmtCheck = $pdo->prepare("SELECT id, CONCAT(nombres, ' ', apellidos) as nombre_completo FROM referidos WHERE id = ? AND lider_raiz_id = ?");
$stmtCheck->execute([$referidoId, $user['id']]);
$padre = $stmtCheck->fetch();

if (!$padre) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

// Obtener la lista de personas invitadas directamente por este referido
$stmtHijos = $pdo->prepare("SELECT cedula, nombres, apellidos, celular, correo, comuna, votante_yopal, DATE_FORMAT(creado_en, '%d/%m/%Y %H:%i') as fecha_formateada FROM referidos WHERE referido_por_tipo = 'referido' AND referido_por_id = ? ORDER BY creado_en DESC");
$stmtHijos->execute([$referidoId]);
$hijos = $stmtHijos->fetchAll();

// Conteo por estados
$totalSi = 0;
$totalInscribir = 0;
$totalNo = 0;

foreach ($hijos as $h) {
    if ($h['votante_yopal'] === 'Si') {
        $totalSi++;
    } else if ($h['votante_yopal'] === 'Quiero inscribir') {
        $totalInscribir++;
    } else {
        $totalNo++;
    }
}

echo json_encode([
    'success' => true,
    'padre' => $padre['nombre_completo'],
    'total' => count($hijos),
    'total_si' => $totalSi,
    'total_inscribir' => $totalInscribir,
    'total_no' => $totalNo,
    'hijos' => $hijos
]);
