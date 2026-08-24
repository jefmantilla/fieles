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

// Paginación para el Historial de Llamadas con Observaciones (Máximo 16 por página)
$obsPage = max(1, (int)($_GET['obs_page'] ?? 1));
$obsLimit = 16;
$obsOffset = ($obsPage - 1) * $obsLimit;

// 1. Crear Nuevo Candidato con Grupo Político
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'crear_candidato') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = "Token de seguridad expirado.";
    } else {
        $nombreCand = sanitizeInput($_POST['nombre_candidato'] ?? '');
        $grupoCand = sanitizeInput($_POST['grupo_candidato'] ?? '');

        if (empty($nombreCand)) {
            $error = "El nombre del candidato es obligatorio.";
        } else {
            $stmtIns = $pdo->prepare("INSERT INTO candidatos_encuestas (nombre, grupo, activo) VALUES (?, ?, 1)");
            if ($stmtIns->execute([$nombreCand, $grupoCand])) {
                $msg = "¡Candidato '" . e($nombreCand) . "' guardado exitosamente!";
            } else {
                $error = "Error al guardar candidato.";
            }
        }
    }
}

// 2. Editar Candidato
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'editar_candidato') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = "Token de seguridad expirado.";
    } else {
        $candId = (int)($_POST['candidato_id'] ?? 0);
        $nombreCand = sanitizeInput($_POST['nombre_candidato'] ?? '');
        $grupoCand = sanitizeInput($_POST['grupo_candidato'] ?? '');

        if (empty($candId) || empty($nombreCand)) {
            $error = "El nombre del candidato es obligatorio.";
        } else {
            $stmtUpd = $pdo->prepare("UPDATE candidatos_encuestas SET nombre = ?, grupo = ? WHERE id = ?");
            $stmtUpd->execute([$nombreCand, $grupoCand, $candId]);
            $msg = "¡Candidato modificado exitosamente!";
        }
    }
}

// 3. Eliminar Candidato
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'eliminar_candidato') {
    $token = $_POST['csrf_token'] ?? '';
    if (verifyCSRFToken($token)) {
        $candId = (int)($_POST['candidato_id'] ?? 0);
        $stmtDel = $pdo->prepare("DELETE FROM candidatos_encuestas WHERE id = ?");
        $stmtDel->execute([$candId]);
        $msg = "¡Candidato eliminado correctamente!";
    }
}

// 4. Toggle Estado Candidato
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'toggle_candidato') {
    $token = $_POST['csrf_token'] ?? '';
    if (verifyCSRFToken($token)) {
        $candId = (int)($_POST['candidato_id'] ?? 0);
        $nuevoEstado = (int)($_POST['nuevo_estado'] ?? 1);
        $stmtUpd = $pdo->prepare("UPDATE candidatos_encuestas SET activo = ? WHERE id = ?");
        $stmtUpd->execute([$nuevoEstado, $candId]);
        $msg = "Estado del candidato actualizado.";
    }
}

// Obtener Lista Completa de Rondas Creadas
$stmtHistorialRondas = $pdo->query("SELECT * FROM rondas_encuestas ORDER BY numero_ronda DESC");
$listaRondas = $stmtHistorialRondas->fetchAll();

// Determinar la Ronda Activa (la última creada)
$rondaActiva = $listaRondas[0] ?? ['id' => 1, 'numero_ronda' => 1, 'nombre_ronda' => 'Ronda Inicial #1', 'creado_en' => date('Y-m-d H:i:s')];

// Determinar qué Ronda se está Consultando en el Navegador de Rondas
$rondaSeleccionadaParam = $_GET['ronda'] ?? '';
if ($rondaSeleccionadaParam === 'todas') {
    $rondaConsulta = 'todas';
} elseif (!empty($rondaSeleccionadaParam)) {
    $rondaConsulta = (int)$rondaSeleccionadaParam;
} else {
    $stmtRondaConResp = $pdo->query("SELECT ronda_id FROM respuestas_encuestas ORDER BY creado_en DESC LIMIT 1");
    $rondaConsulta = (int)($stmtRondaConResp->fetchColumn() ?: $rondaActiva['id']);
}

// Métricas Globales
$stmtTotalEncuestas = $pdo->query("SELECT COUNT(*) FROM respuestas_encuestas");
$totalEncuestas = $stmtTotalEncuestas->fetchColumn();

// Total Referidos Afiliados
$stmtTotalBase = $pdo->query("SELECT COUNT(*) FROM referidos");
$totalBaseAfiliados = $stmtTotalBase->fetchColumn();

// Total Encuestadoras
$stmtTotalEnc = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE role_id = 4");
$totalEncuestadoras = $stmtTotalEnc->fetchColumn();

// Lista de Candidatos Configurados
$stmtCandidatosList = $pdo->query("SELECT * FROM candidatos_encuestas ORDER BY id ASC");
$listaCandidatos = $stmtCandidatosList->fetchAll();

// Conteo Estadístico por Candidato de la RONDA SELECCIONADA o TODAS
if ($rondaConsulta === 'todas') {
    $stmtResultadosCand = $pdo->query("
        SELECT r.candidato_elegido, c.grupo, COUNT(*) as total 
        FROM respuestas_encuestas r
        LEFT JOIN candidatos_encuestas c ON c.nombre = r.candidato_elegido
        GROUP BY r.candidato_elegido, c.grupo 
        ORDER BY total DESC
    ");
    $resultadosCand = $stmtResultadosCand->fetchAll();
    $totalEncuestasRonda = $totalEncuestas;
    $tituloRondaConsulta = "Consolidado de Todas las Rondas";

    // Conteo de Llamadas Sin Respuesta / No Alcanzados
    $stmtNoContesto = $pdo->query("SELECT COUNT(*) FROM respuestas_encuestas WHERE candidato_elegido LIKE '%No Contestó%'");
    $countNoContesto = $stmtNoContesto->fetchColumn();

    $stmtNumEquiv = $pdo->query("SELECT COUNT(*) FROM respuestas_encuestas WHERE candidato_elegido LIKE '%Equivocado%'");
    $countNumEquiv = $stmtNumEquiv->fetchColumn();

    $countPendientesLlamar = 0;
} else {
    $stmtResultadosCand = $pdo->prepare("
        SELECT r.candidato_elegido, c.grupo, COUNT(*) as total 
        FROM respuestas_encuestas r
        LEFT JOIN candidatos_encuestas c ON c.nombre = r.candidato_elegido
        WHERE r.ronda_id = ?
        GROUP BY r.candidato_elegido, c.grupo 
        ORDER BY total DESC
    ");
    $stmtResultadosCand->execute([$rondaConsulta]);
    $resultadosCand = $stmtResultadosCand->fetchAll();

    $stmtTotalRonda = $pdo->prepare("SELECT COUNT(*) FROM respuestas_encuestas WHERE ronda_id = ?");
    $stmtTotalRonda->execute([$rondaConsulta]);
    $totalEncuestasRonda = $stmtTotalRonda->fetchColumn();

    $stmtInfoR = $pdo->prepare("SELECT numero_ronda FROM rondas_encuestas WHERE id = ?");
    $stmtInfoR->execute([$rondaConsulta]);
    $numR = $stmtInfoR->fetchColumn();
    $tituloRondaConsulta = "Ronda #" . ($numR ?: $rondaConsulta);

    // Conteo de Llamadas Sin Respuesta / No Alcanzados en Ronda
    $stmtNoContesto = $pdo->prepare("SELECT COUNT(*) FROM respuestas_encuestas WHERE ronda_id = ? AND candidato_elegido LIKE '%No Contestó%'");
    $stmtNoContesto->execute([$rondaConsulta]);
    $countNoContesto = $stmtNoContesto->fetchColumn();

    $stmtNumEquiv = $pdo->prepare("SELECT COUNT(*) FROM respuestas_encuestas WHERE ronda_id = ? AND candidato_elegido LIKE '%Equivocado%'");
    $stmtNumEquiv->execute([$rondaConsulta]);
    $countNumEquiv = $stmtNumEquiv->fetchColumn();

    $stmtPend = $pdo->prepare("SELECT COUNT(*) FROM referidos WHERE id NOT IN (SELECT referido_id FROM respuestas_encuestas WHERE ronda_id = ?)");
    $stmtPend->execute([$rondaConsulta]);
    $countPendientesLlamar = $stmtPend->fetchColumn();
}

// Historial de Llamadas con Observaciones FILTRADO SEGÚN LA RONDA SELECCIONADA (Paginado máx 16)
if ($rondaConsulta === 'todas') {
    $stmtTotalObs = $pdo->query("SELECT COUNT(*) FROM respuestas_encuestas");
    $totalObsCount = $stmtTotalObs->fetchColumn();

    $stmtUltimasResp = $pdo->query("
        SELECT r.*, 
               CONCAT(ref.nombres, ' ', ref.apellidos) as persona_nombre,
               ref.celular as persona_celular,
               u.nombre_completo as encuestadora_nombre,
               ro.numero_ronda
        FROM respuestas_encuestas r
        JOIN referidos ref ON r.referido_id = ref.id
        JOIN usuarios u ON r.encuestadora_id = u.id
        LEFT JOIN rondas_encuestas ro ON r.ronda_id = ro.id
        ORDER BY r.creado_en DESC
        LIMIT " . $obsLimit . " OFFSET " . $obsOffset
    );
} else {
    $stmtTotalObs = $pdo->prepare("SELECT COUNT(*) FROM respuestas_encuestas WHERE ronda_id = ?");
    $stmtTotalObs->execute([$rondaConsulta]);
    $totalObsCount = $stmtTotalObs->fetchColumn();

    $stmtUltimasResp = $pdo->prepare("
        SELECT r.*, 
               CONCAT(ref.nombres, ' ', ref.apellidos) as persona_nombre,
               ref.celular as persona_celular,
               u.nombre_completo as encuestadora_nombre,
               ro.numero_ronda
        FROM respuestas_encuestas r
        JOIN referidos ref ON r.referido_id = ref.id
        JOIN usuarios u ON r.encuestadora_id = u.id
        LEFT JOIN rondas_encuestas ro ON r.ronda_id = ro.id
        WHERE r.ronda_id = ?
        ORDER BY r.creado_en DESC
        LIMIT " . $obsLimit . " OFFSET " . $obsOffset
    );
    $stmtUltimasResp->execute([$rondaConsulta]);
}
$ultimasRespuestas = $stmtUltimasResp->fetchAll();
$totalObsPaginas = max(1, ceil($totalObsCount / $obsLimit));

if ($obsPage > $totalObsPaginas) {
    $obsPage = $totalObsPaginas;
}

$rangoVista = 2;
$inicioObsPag = max(1, $obsPage - $rangoVista);
$finObsPag = min($totalObsPaginas, $obsPage + $rangoVista);
$urlObsPrefix = '?ronda=' . urlencode($rondaConsulta) . '&obs_page=';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Administrador de Encuestas - Proyecto Político Social</title>
    <!-- Font Awesome & MDB / Bootstrap 5 CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/6.4.0/mdb.min.css">
    <link rel="stylesheet" href="../assets/css/custom.css">
</head>
<body class="bg-light">

<!-- Navbar Compartido AdminEncuestas -->
<?php $activeTab = 'dashboard'; include __DIR__ . '/navbar.php'; ?>

<div class="container-fluid px-4 py-4">

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

    <!-- TIRA INFORMATIVA DE RONDA ACTIVA -->
    <div class="card card-custom mb-4 border-info shadow-sm">
        <div class="card-body p-3 bg-white rounded d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-success fs-6"><i class="fas fa-play-circle me-1"></i>Ronda #<?= $rondaActiva['numero_ronda'] ?> Activa</span>
                <span class="text-muted small">Lanzada el <strong><?= date('d/m/Y H:i', strtotime($rondaActiva['creado_en'])) ?></strong></span>
            </div>
            <a href="configuracion.php" class="btn btn-outline-info btn-sm fw-bold shadow-0">
                <i class="fas fa-cog me-1"></i> Ir a Configuración de Rondas y Enlaces <i class="fas fa-arrow-right ms-1"></i>
            </a>
        </div>
    </div>

    <!-- Tarjetas de Resumen Global -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="card card-custom bg-white border-start border-4 border-primary p-3 shadow-sm h-100">
                <div class="card-body p-1 d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase text-muted fw-bold small mb-1">Total Encuestas Registradas</h6>
                        <h3 class="fw-bold text-dark mb-0"><?= $totalEncuestas ?></h3>
                    </div>
                    <div class="text-primary p-3 rounded-circle" style="background-color: #e3f2fd;">
                        <i class="fas fa-clipboard-list fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card card-custom bg-white border-start border-4 border-warning p-3 shadow-sm h-100">
                <div class="card-body p-1 d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase text-muted fw-bold small mb-1">Candidatos / Grupos</h6>
                        <h3 class="fw-bold text-dark mb-0"><?= count($listaCandidatos) ?></h3>
                    </div>
                    <div class="text-warning p-3 rounded-circle" style="background-color: #fff8e1;">
                        <i class="fas fa-vote-yea fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card card-custom bg-white border-start border-4 border-success p-3 shadow-sm h-100">
                <div class="card-body p-1 d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase text-muted fw-bold small mb-1">Análisis de Fidelidad</h6>
                        <a href="fidelidad.php" class="btn btn-outline-success btn-sm mt-1 fw-bold"><i class="fas fa-user-check me-1"></i> Ver Matriz Fieles</a>
                    </div>
                    <div class="text-success p-3 rounded-circle" style="background-color: #e8f5e9;">
                        <i class="fas fa-user-shield fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card card-custom bg-white border-start border-4 border-info p-3 shadow-sm h-100">
                <div class="card-body p-1 d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase text-muted fw-bold small mb-1">Encuestadoras Registradas</h6>
                        <h3 class="fw-bold text-dark mb-0"><?= $totalEncuestadoras ?></h3>
                        <a href="rendimiento.php" class="btn btn-outline-info btn-sm mt-1 fw-bold"><i class="fas fa-chart-line me-1"></i> Ver Rendimiento</a>
                    </div>
                    <div class="text-info p-3 rounded-circle" style="background-color: #e0f7fa;">
                        <i class="fas fa-headset fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- TARJETA INFORMATIVA: ALCANCE DE LA RONDA CONSULTADA (COMPLETADAS / NO CONTESTARON / PENDIENTES) -->
    <div class="card card-custom mb-4 shadow-sm border">
        <div class="card-body p-3 bg-white">
            <div class="row text-center align-items-center">
                <div class="col-md-3 border-end">
                    <span class="text-muted small fw-bold text-uppercase">Base Afiliados a Encuestar</span>
                    <h4 class="fw-bold text-dark mb-0"><?= $totalBaseAfiliados ?></h4>
                </div>
                <div class="col-md-3 border-end">
                    <span class="text-success small fw-bold text-uppercase"><i class="fas fa-check-circle me-1"></i>Llamadas Realizadas</span>
                    <h4 class="fw-bold text-success mb-0"><?= $totalEncuestasRonda ?></h4>
                </div>
                <div class="col-md-3 border-end">
                    <span class="text-warning small fw-bold text-uppercase"><i class="fas fa-phone-slash me-1"></i>No Contestaron / Equivocados</span>
                    <h4 class="fw-bold text-warning text-dark mb-0"><?= ($countNoContesto + $countNumEquiv) ?></h4>
                </div>
                <div class="col-md-3">
                    <span class="text-danger small fw-bold text-uppercase"><i class="fas fa-user-clock me-1"></i>Pendientes por Llamar</span>
                    <h4 class="fw-bold text-danger mb-0"><?= $countPendientesLlamar ?></h4>
                </div>
            </div>
        </div>
    </div>

    <!-- SECCIÓN 1: GESTIÓN DE CANDIDATOS Y RESULTADOS CON NAVEGADOR DE RONDAS -->
    <div class="row g-4 mb-4">
        
        <!-- Tarjeta 1: Crear y Gestor de Candidatos + Modificar/Eliminar -->
        <div class="col-lg-6">
            <div class="card card-custom shadow-sm border h-100">
                <div class="card-body p-4">
                    <h5 class="fw-bold text-warning mb-3"><i class="fas fa-users me-2"></i>Gestión de Candidatos y Grupo Político</h5>
                    
                    <form action="dashboard.php" method="POST" class="row g-2 mb-4">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="accion" value="crear_candidato">

                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Nombre del Candidato *</label>
                            <input type="text" name="nombre_candidato" class="form-control" placeholder="Ej. Dr. Juan Pérez" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Grupo / Partido Político *</label>
                            <input type="text" name="grupo_candidato" class="form-control" placeholder="Ej. Movimiento Yopal Avanza" required>
                        </div>
                        <div class="col-12 mt-2 text-end">
                            <button type="submit" class="btn btn-warning fw-bold text-dark shadow-0">
                                <i class="fas fa-plus me-1"></i> Agregar Candidato
                            </button>
                        </div>
                    </form>

                    <h6 class="fw-bold text-muted small text-uppercase mb-2">Lista de Candidatos (Editar / Eliminar / Estado):</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Candidato</th>
                                    <th>Grupo / Partido</th>
                                    <th>Estado</th>
                                    <th class="text-end">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($listaCandidatos as $cand): ?>
                                    <tr>
                                        <td class="fw-bold"><?= e($cand['nombre']) ?></td>
                                        <td>
                                            <span class="badge bg-light text-dark border">
                                                <i class="fas fa-flag text-primary me-1"></i><?= e($cand['grupo'] ?: 'Independiente') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($cand['activo'] == 1): ?>
                                                <span class="badge bg-success">Activo</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactivo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1">
                                                <button class="btn btn-outline-warning btn-sm p-1 px-2 text-dark" onclick='abrirModalEditarCandidato(<?= json_encode($cand) ?>)' title="Modificar candidato">
                                                    <i class="fas fa-edit"></i>
                                                </button>

                                                <form action="dashboard.php" method="POST" class="d-inline" onsubmit="return confirm('¿Está seguro de eliminar este candidato?');">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                    <input type="hidden" name="accion" value="eliminar_candidato">
                                                    <input type="hidden" name="candidato_id" value="<?= $cand['id'] ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm p-1 px-2" title="Eliminar candidato">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>

                                                <form action="dashboard.php" method="POST" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                    <input type="hidden" name="accion" value="toggle_candidato">
                                                    <input type="hidden" name="candidato_id" value="<?= $cand['id'] ?>">
                                                    <input type="hidden" name="nuevo_estado" value="<?= $cand['activo'] == 1 ? 0 : 1 ?>">
                                                    <button type="submit" class="btn btn-outline-secondary btn-sm p-1 px-2">
                                                        <?= $cand['activo'] == 1 ? 'Off' : 'On' ?>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                </div>
            </div>
        </div>

        <!-- Tarjeta 2: NAVEGADOR DE RONDAS Y RESULTADOS ESTADÍSTICOS -->
        <div class="col-lg-6">
            <div class="card card-custom shadow-sm border h-100">
                <div class="card-body p-4">
                    
                    <!-- NAVEGADOR / SELECTOR DE RONDAS -->
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom">
                        <div>
                            <h5 class="fw-bold text-primary mb-0"><i class="fas fa-chart-bar me-2"></i>Resultados por Ronda</h5>
                            <span class="badge bg-primary text-white mt-1"><?= e($tituloRondaConsulta) ?> (<?= $totalEncuestasRonda ?> encuestados)</span>
                        </div>

                        <!-- Selector de Ronda -->
                        <form action="dashboard.php" method="GET" class="d-inline-block">
                            <select name="ronda" class="form-select form-select-sm fw-bold border-primary shadow-0" onchange="this.form.submit()">
                                <option value="todas" <?= $rondaConsulta === 'todas' ? 'selected' : '' ?>>📊 Consolidado (Todas las Rondas)</option>
                                <?php foreach ($listaRondas as $r): ?>
                                    <option value="<?= $r['id'] ?>" <?= $rondaConsulta == $r['id'] ? 'selected' : '' ?>>
                                        <?= $r['id'] == $rondaActiva['id'] ? '⭐ Ronda #' . $r['numero_ronda'] . ' (Ronda Activa)' : 'Ronda #' . $r['numero_ronda'] ?> (<?= date('d/m/Y', strtotime($r['creado_en'])) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>

                    <?php if (empty($resultadosCand)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-phone-slash fa-3x mb-3 text-secondary"></i>
                            <h6>No hay respuestas registradas en <?= e($tituloRondaConsulta) ?> aún.</h6>
                            <p class="small">Las encuestadoras están realizando las llamadas para este ciclo.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Candidato / Estado Llamada</th>
                                        <th>Grupo / Partido</th>
                                        <th>Respuestas</th>
                                        <th>Porcentaje</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($resultadosCand as $c): 
                                        $porc = $totalEncuestasRonda > 0 ? round(($c['total'] / $totalEncuestasRonda) * 100, 1) : 0;
                                        $esNoContesto = strpos($c['candidato_elegido'], 'No Contestó') !== false || strpos($c['candidato_elegido'], 'Equivocado') !== false || strpos($c['candidato_elegido'], 'Rechazó') !== false;
                                    ?>
                                        <tr class="<?= $esNoContesto ? 'table-warning text-dark' : '' ?>">
                                            <td class="fw-bold">
                                                <?php if ($esNoContesto): ?>
                                                    <i class="fas fa-phone-slash text-danger me-1"></i><?= e($c['candidato_elegido']) ?>
                                                <?php else: ?>
                                                    <?= e($c['candidato_elegido']) ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark border">
                                                    <i class="fas fa-flag text-primary me-1"></i><?= e($c['grupo'] ?: 'Control Llamadas') ?>
                                                </span>
                                            </td>
                                            <td><span class="badge <?= $esNoContesto ? 'bg-warning text-dark' : 'bg-primary' ?> fs-6"><?= $c['total'] ?> resp.</span></td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="progress flex-grow-1" style="height: 10px;">
                                                        <div class="progress-bar <?= $esNoContesto ? 'bg-warning' : 'bg-success' ?>" role="progressbar" style="width: <?= $porc ?>%"></div>
                                                    </div>
                                                    <span class="fw-bold small"><?= $porc ?>%</span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>

    </div>

    <!-- SECCIÓN 2: HISTORIAL EN TIEMPO REAL CON OBSERVACIONES Y RONDA DE LAS LLAMADAS (PAGINADO MÁX 16) -->
    <div class="card card-custom mb-4 shadow-sm border">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold text-primary mb-0"><i class="fas fa-comments me-2"></i>Historial de Llamadas con Observaciones y Comentarios</h5>
                <span class="badge bg-dark fs-6">Mostrando <?= count($ultimasRespuestas) ?> de <?= $totalObsCount ?> en <?= e($tituloRondaConsulta) ?> (Pág <?= $obsPage ?> de <?= $totalObsPaginas ?>)</span>
            </div>
            
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Ronda #</th>
                            <th>Fecha y Hora</th>
                            <th>Persona Encuestada</th>
                            <th>Celular</th>
                            <th>Resultado / Candidato</th>
                            <th>Estado Votación</th>
                            <th>Encuestadora</th>
                            <th>Observaciones / Comentarios Adicionales</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($ultimasRespuestas)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">No se han registrado observaciones de llamadas en <?= e($tituloRondaConsulta) ?> aún.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($ultimasRespuestas as $resp): 
                                $esEspecial = strpos($resp['candidato_elegido'], 'No Contestó') !== false || strpos($resp['candidato_elegido'], 'Equivocado') !== false || strpos($resp['candidato_elegido'], 'Rechazó') !== false;
                            ?>
                                <tr>
                                    <td><span class="badge bg-dark"><i class="fas fa-sync-alt text-warning me-1"></i>Ronda #<?= $resp['numero_ronda'] ?: 1 ?></span></td>
                                    <td class="small text-muted"><i class="far fa-clock me-1"></i><?= date('d/m/Y H:i', strtotime($resp['creado_en'])) ?></td>
                                    <td class="fw-bold"><?= e($resp['persona_nombre']) ?></td>
                                    <td><i class="fas fa-phone-alt me-1 text-muted small"></i><?= e($resp['persona_celular']) ?></td>
                                    <td>
                                        <span class="badge <?= $esEspecial ? 'bg-warning text-dark' : 'bg-primary' ?>">
                                            <?= e($resp['candidato_elegido']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($resp['votante_yopal_respuesta'] === 'Si'): ?>
                                            <span class="badge bg-success">Sí en Yopal</span>
                                        <?php elseif ($resp['votante_yopal_respuesta'] === 'Quiero inscribir'): ?>
                                            <span class="badge bg-warning text-dark">Inscribirá</span>
                                        <?php elseif ($resp['votante_yopal_respuesta'] === 'Sin Confirmar'): ?>
                                            <span class="badge bg-secondary">Sin Confirmar</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Otro</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted"><i class="fas fa-headset me-1 text-success"></i><?= e($resp['encuestadora_nombre']) ?></td>
                                    <td class="small text-dark italic">
                                        <?= !empty($resp['observaciones']) ? e($resp['observaciones']) : '<span class="text-muted font-normal">Sin comentarios</span>' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Navegación de Paginación para Observaciones de Llamadas (Máx 16 por página) -->
            <?php if ($totalObsPaginas > 1): ?>
                <nav aria-label="Navegación de observaciones" class="mt-4">
                    <ul class="pagination justify-content-center flex-wrap">
                        <li class="page-item <?= ($obsPage <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= $urlObsPrefix . ($obsPage - 1) ?>">
                                <i class="fas fa-chevron-left me-1"></i> Anterior
                            </a>
                        </li>

                        <?php if ($inicioObsPag > 1): ?>
                            <li class="page-item"><a class="page-link" href="<?= $urlObsPrefix ?>1">1</a></li>
                            <?php if ($inicioObsPag > 2): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($p = $inicioObsPag; $p <= $finObsPag; $p++): ?>
                            <li class="page-item <?= ($p === $obsPage) ? 'active' : '' ?>">
                                <a class="page-link" href="<?= $urlObsPrefix . $p ?>"><?= $p ?></a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($finObsPag < $totalObsPaginas): ?>
                            <?php if ($finObsPag < $totalObsPaginas - 1): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                            <li class="page-item"><a class="page-link" href="<?= $urlObsPrefix . $totalObsPaginas ?>"><?= $totalObsPaginas ?></a></li>
                        <?php endif; ?>

                        <li class="page-item <?= ($obsPage >= $totalObsPaginas) ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= $urlObsPrefix . ($obsPage + 1) ?>">
                                Siguiente <i class="fas fa-chevron-right ms-1"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>

        </div>
    </div>

</div>

<!-- Modal Modificar Candidato -->
<div class="modal fade" id="modalEditarCandidato" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="dashboard.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="accion" value="editar_candidato">
                <input type="hidden" name="candidato_id" id="edit_cand_id">

                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="fas fa-edit text-warning me-2"></i>Modificar Candidato</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nombre del Candidato *</label>
                        <input type="text" class="form-control" id="edit_cand_nombre" name="nombre_candidato" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Grupo / Partido Político *</label>
                        <input type="text" class="form-control" id="edit_cand_grupo" name="grupo_candidato" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning"><i class="fas fa-save me-1"></i> Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    let editCandModal;
    document.addEventListener('DOMContentLoaded', function() {
        editCandModal = new bootstrap.Modal(document.getElementById('modalEditarCandidato'));
    });

    function abrirModalEditarCandidato(candidato) {
        document.getElementById('edit_cand_id').value = candidato.id;
        document.getElementById('edit_cand_nombre').value = candidato.nombre;
        document.getElementById('edit_cand_grupo').value = candidato.grupo || '';
        editCandModal.show();
    }
</script>
</body>
</html>
