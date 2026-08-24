<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/auth.php';

requireRole('AdminEncuestas');

$admin = getCurrentUser();
$pdo = getDB();
$csrfToken = generateCSRFToken();
$msg = '';
$error = '';

// 1. Guardar URL de Consulta Externa / Registro Personalizado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'guardar_url_externa') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = "Token de seguridad expirado.";
    } else {
        $urlExt = sanitizeInput($_POST['url_consulta_externa'] ?? '');
        $stmtUpdUrl = $pdo->prepare("UPDATE configuracion_encuestas SET url_consulta_externa = ? WHERE id = 1");
        if ($stmtUpdUrl->execute([$urlExt])) {
            $msg = "¡Enlace del recuadro de apoyo para las encuestadoras guardado exitosamente!";
        } else {
            $error = "Error al guardar el enlace.";
        }
    }
}

// 2. Procesar Lanzamiento de Nueva Ronda de Re-Encuesta Manual
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'lanzar_nueva_ronda') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = "Token de seguridad expirado.";
    } else {
        $stmtMaxRonda = $pdo->query("SELECT MAX(numero_ronda) FROM rondas_encuestas");
        $maxRonda = (int)($stmtMaxRonda->fetchColumn() ?: 0);
        $nuevaRondaNum = $maxRonda + 1;
        $nombreRonda = "Re-Encuesta Ronda #" . $nuevaRondaNum;

        $stmtInsRonda = $pdo->prepare("INSERT INTO rondas_encuestas (numero_ronda, nombre_ronda, creado_por, creado_en) VALUES (?, ?, ?, NOW())");
        if ($stmtInsRonda->execute([$nuevaRondaNum, $nombreRonda, $admin['id']])) {
            $msg = "¡Se ha iniciado exitosamente la " . e($nombreRonda) . " el día " . date('d/m/Y H:i') . "! Las encuestadoras ya pueden comenzar a llamar para el nuevo ciclo.";
        } else {
            $error = "Error al iniciar la nueva ronda de re-encuesta.";
        }
    }
}

// Obtener Configuración de URL Externa Actual
$stmtConfigUrl = $pdo->query("SELECT url_consulta_externa FROM configuracion_encuestas WHERE id = 1");
$urlExternaActual = $stmtConfigUrl->fetchColumn() ?: '../registro.php?ref=LID-40FEA8AA';

// Obtener Lista Completa de Rondas Creadas
$stmtHistorialRondas = $pdo->query("SELECT r.*, u.nombre_completo as creador_nombre FROM rondas_encuestas r JOIN usuarios u ON r.creado_por = u.id ORDER BY r.numero_ronda DESC");
$listaRondas = $stmtHistorialRondas->fetchAll();

// Determinar la Ronda Activa (la última creada)
$rondaActiva = $listaRondas[0] ?? ['id' => 1, 'numero_ronda' => 1, 'nombre_ronda' => 'Ronda Inicial #1', 'creado_en' => date('Y-m-d H:i:s')];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración de Rondas y Enlaces - Proyecto Político Social</title>
    <!-- Font Awesome & MDB / Bootstrap 5 CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/6.4.0/mdb.min.css">
    <link rel="stylesheet" href="../assets/css/custom.css">
</head>
<body class="bg-light">

<!-- Navbar Compartido AdminEncuestas -->
<?php $activeTab = 'configuracion'; include __DIR__ . '/navbar.php'; ?>

<div class="container-fluid px-4 py-4" style="max-width: 1000px;">

    <!-- Notificaciones -->
    <?php if (!empty($msg)): ?>
        <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
            <i class="fas fa-check-circle me-2"></i><?= e($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i><?= e($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- SECCIÓN 1: LANZADOR OFICIAL DE RONDAS DE RE-ENCUESTA -->
    <div class="card card-custom mb-4 border-success shadow-sm">
        <div class="card-header bg-dark text-white p-3">
            <h5 class="fw-bold mb-0"><i class="fas fa-sync-alt me-2 text-success"></i>Control de Rondas de Re-Encuesta Telefónica</h5>
        </div>
        <div class="card-body p-4 bg-white">
            <div class="row align-items-center">
                <div class="col-lg-7 mb-3 mb-lg-0">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="badge bg-success fs-6"><i class="fas fa-play-circle me-1"></i>Ronda #<?= $rondaActiva['numero_ronda'] ?> Activa</span>
                        <span class="text-muted small">Lanzada el <strong><?= date('d/m/Y H:i', strtotime($rondaActiva['creado_en'])) ?></strong></span>
                    </div>
                    <h5 class="fw-bold text-dark mb-1">Reiniciar Ciclo de Llamadas (Nueva Ronda)</h5>
                    <p class="text-muted small mb-0">Al presionar el botón de abajo, se creará oficialmente la <strong>Ronda #<?= $rondaActiva['numero_ronda'] + 1 ?></strong>. Toda la base de afiliados volverá a estar disponible para que las encuestadoras realicen el nuevo seguimiento sin borrar el historial anterior.</p>
                </div>
                <div class="col-lg-5 text-lg-end">
                    <form action="configuracion.php" method="POST" onsubmit="return confirm('⚠️ ¿Está completamente seguro de iniciar una NUEVA RONDA de re-encuesta (#<?= $rondaActiva['numero_ronda'] + 1 ?>)?\n\nEsto habilitará de nuevo a todos los afiliados para que las encuestadoras comiencen las llamadas del nuevo ciclo.');">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="accion" value="lanzar_nueva_ronda">
                        <button type="submit" class="btn btn-success btn-lg fw-bold shadow-0 w-100 py-3">
                            <i class="fas fa-redo-alt me-2"></i> Realizar Re-Encuesta Ahora (Ronda #<?= $rondaActiva['numero_ronda'] + 1 ?>)
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- SECCIÓN 2: CONFIGURACIÓN DE ENLACE DE APOYO EXTERNO PARA ENCUESTADORAS -->
    <div class="card card-custom mb-4 border-primary shadow-sm">
        <div class="card-header bg-dark text-white p-3">
            <h5 class="fw-bold mb-0"><i class="fas fa-link me-2 text-primary"></i>Configuración de Enlace de Consulta o Registro de Apoyo</h5>
        </div>
        <div class="card-body p-4 bg-white">
            <form action="configuracion.php" method="POST" class="row align-items-center g-3">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="accion" value="guardar_url_externa">

                <div class="col-12">
                    <label class="form-label fw-bold text-dark mb-1">URL de Consulta de Cédula o Registro Externe para Encuestadoras *</label>
                    <p class="text-muted small mb-2">Ingrese la dirección web completa que abrirán las encuestadoras al hacer clic en el botón de apoyo durante la llamada:</p>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-globe text-primary"></i></span>
                        <input type="url" name="url_consulta_externa" class="form-control" placeholder="http://localhost/Aplicaiones/fieles/registro.php?ref=LID-40FEA8AA" value="<?= e($urlExternaActual) ?>" required>
                        <button type="submit" class="btn btn-primary fw-bold shadow-0 px-4">
                            <i class="fas fa-save me-1"></i> Guardar Enlace
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- SECCIÓN 3: HISTORIAL DE RONDAS LANZADAS -->
    <div class="card card-custom shadow-sm border">
        <div class="card-header bg-dark text-white p-3">
            <h5 class="fw-bold mb-0"><i class="fas fa-history me-2 text-warning"></i>Historial de Rondas de Re-Encuesta Creadas</h5>
        </div>
        <div class="card-body p-4 bg-white">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Ronda #</th>
                            <th>Nombre de la Ronda</th>
                            <th>Fecha y Hora de Lanzamiento</th>
                            <th>Creado Por</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($listaRondas as $r): ?>
                            <tr>
                                <td class="fw-bold">
                                    <span class="badge bg-dark fs-6">Ronda #<?= $r['numero_ronda'] ?></span>
                                </td>
                                <td class="fw-bold text-primary"><?= e($r['nombre_ronda']) ?></td>
                                <td class="small text-muted"><i class="far fa-clock me-1"></i><?= date('d/m/Y H:i', strtotime($r['creado_en'])) ?></td>
                                <td class="small text-dark"><i class="fas fa-user-shield me-1 text-warning"></i><?= e($r['creador_nombre']) ?></td>
                                <td>
                                    <?php if ($r['id'] == $rondaActiva['id']): ?>
                                        <span class="badge bg-success fs-6"><i class="fas fa-check-circle me-1"></i>Ronda Activa en Curso</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><i class="fas fa-archive me-1"></i>Completada / Histórica</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
