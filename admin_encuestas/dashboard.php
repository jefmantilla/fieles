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

// Obtener Pregunta Principal, Guión de Llamadas y Meta Config
$stmtConfigPreg = $pdo->query("SELECT pregunta_candidato, guion_llamada, meta_wa_phone_number_id, meta_wa_access_token, meta_wa_verify_token FROM configuracion_encuestas WHERE id = 1");
$configRow = $stmtConfigPreg->fetch() ?: [];
$preguntaCandidato = !empty($configRow['pregunta_candidato']) ? $configRow['pregunta_candidato'] : '¿Por cuál candidato planea votar o cuál fue el resultado de la llamada?';
$guionLlamada = !empty($configRow['guion_llamada']) ? $configRow['guion_llamada'] : "Hola, muy buenas tardes. Mi nombre es Andrea de la firma de opinión Estelar. Nos comunicamos muy brevemente para realizarle un par de preguntas rápidas sobre el desarrollo y futuro de nuestro municipio como parte de un estudio local. ¿Nos concede solo 1 minuto de su tiempo?";
$metaWaPhoneId = $configRow['meta_wa_phone_number_id'] ?? '';
$metaWaAccessToken = $configRow['meta_wa_access_token'] ?? '';
$metaWaVerifyToken = !empty($configRow['meta_wa_verify_token']) ? $configRow['meta_wa_verify_token'] : 'fieles_wa_token_123';

// Lista de Preguntas Configuradas
$stmtPreguntasList = $pdo->query("SELECT * FROM preguntas_encuestas ORDER BY id ASC");
$listaPreguntas = $stmtPreguntasList->fetchAll();

if (empty($listaPreguntas)) {
    $pdo->exec("INSERT INTO preguntas_encuestas (pregunta, tipo, opciones, activo) VALUES 
        ('¿Por cuál candidato o propuesta política planea votar en las próximas elecciones?', 'opcion_multiple', 'Candidato Oficial, Candidato Opositor, Voto en Blanco, Indeciso', 1),
        ('¿Cuáles son las necesidades o temas prioritarios en su comuna o sector?', 'texto_libre', '', 1),
        ('¿Confirmaría su apoyo y participación activa el día de la jornada electoral?', 'opcion_multiple', 'Sí Confirmado, Tal vez / En duda, No asistirá', 1)
    ");
    $stmtPreguntasList = $pdo->query("SELECT * FROM preguntas_encuestas ORDER BY id ASC");
    $listaPreguntas = $stmtPreguntasList->fetchAll();
}

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
               ref.puesto_votacion as persona_puesto,
               ref.mesa_votacion as persona_mesa,
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
               ref.puesto_votacion as persona_puesto,
               ref.mesa_votacion as persona_mesa,
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
    <style>
        .card-interactive {
            cursor: pointer;
            transition: all 0.25s ease-in-out;
        }
        .card-interactive:hover {
            transform: translateY(-4px) scale(1.02);
            box-shadow: 0 10px 24px rgba(0,0,0,0.15) !important;
        }
    </style>
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

    <!-- Tarjetas de Resumen Global Interactivas (Hacer clic para ver lista) -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="card card-custom card-interactive bg-white border-start border-4 border-primary p-3 shadow-sm h-100"
                 onclick="abrirTarjetaEmergenteCategory('todas_encuestas', 'Total Encuestas Registradas')">
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
            <div class="card card-custom card-interactive bg-white border-start border-4 border-warning p-3 shadow-sm h-100"
                 onclick="abrirTarjetaEmergenteCategory('base_afiliados', 'Base Afiliados a Encuestar')">
                <div class="card-body p-1 d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase text-muted fw-bold small mb-1">Base Total Afiliados</h6>
                        <h3 class="fw-bold text-dark mb-0"><?= $totalBaseAfiliados ?></h3>
                    </div>
                    <div class="text-warning p-3 rounded-circle" style="background-color: #fff8e1;">
                        <i class="fas fa-users fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card card-custom card-interactive bg-white border-start border-4 border-success p-3 shadow-sm h-100"
                 onclick="abrirTarjetaEmergenteCategory('fieles_todos', 'Votantes Fieles (100% Leal)')">
                <div class="card-body p-1 d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase text-muted fw-bold small mb-1">Análisis de Fidelidad</h6>
                        <h3 class="fw-bold text-success mb-0">Ver Fieles</h3>
                    </div>
                    <div class="text-success p-3 rounded-circle" style="background-color: #e8f5e9;">
                        <i class="fas fa-user-shield fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card card-custom card-interactive bg-white border-start border-4 border-info p-3 shadow-sm h-100"
                 onclick="abrirTarjetaEmergenteCategory('encuestadoras', 'Equipo de Encuestadoras Registradas')">
                <div class="card-body p-1 d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase text-muted fw-bold small mb-1">Encuestadoras Registradas</h6>
                        <h3 class="fw-bold text-dark mb-0"><?= $totalEncuestadoras ?></h3>
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
                <div class="col-md-3 border-end py-1 card-interactive" onclick="abrirTarjetaEmergenteCategory('base_afiliados', 'Base Afiliados a Encuestar')">
                    <span class="text-muted small fw-bold text-uppercase">Base Afiliados a Encuestar</span>
                    <h4 class="fw-bold text-dark mb-0"><?= $totalBaseAfiliados ?></h4>
                </div>
                <div class="col-md-3 border-end py-1 card-interactive" onclick="abrirTarjetaEmergenteCategory('todas_encuestas', 'Llamadas Realizadas')">
                    <span class="text-success small fw-bold text-uppercase"><i class="fas fa-check-circle me-1"></i>Llamadas Realizadas</span>
                    <h4 class="fw-bold text-success mb-0"><?= $totalEncuestasRonda ?></h4>
                </div>
                <div class="col-md-3 border-end py-1 card-interactive" onclick="abrirTarjetaEmergenteCategory('no_contesto_equivocado', 'No Contestaron / Equivocados')">
                    <span class="text-warning small fw-bold text-uppercase"><i class="fas fa-phone-slash me-1"></i>No Contestaron / Equivocados</span>
                    <h4 class="fw-bold text-warning text-dark mb-0"><?= ($countNoContesto + $countNumEquiv) ?></h4>
                </div>
                <div class="col-md-3 py-1 card-interactive" onclick="abrirTarjetaEmergenteCategory('no_contesto', 'No Contestaron / Sin Respuesta')">
                    <span class="text-danger small fw-bold text-uppercase"><i class="fas fa-user-clock me-1"></i>No Contestaron</span>
                    <h4 class="fw-bold text-danger mb-0"><?= $countNoContesto ?></h4>
                </div>
            </div>
        </div>
    </div>

    <!-- SECCIÓN 1: RESULTADOS POR RONDA Y NAVEGADOR DE RONDAS -->
    <div class="row g-4 mb-4">
        
        <!-- Tarjeta: NAVEGADOR DE RONDAS Y RESULTADOS ESTADÍSTICOS (FULL WIDTH) -->
        <div class="col-12">
            <div class="card card-custom shadow-sm border">
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
                                        <tr class="<?= $esNoContesto ? 'table-warning text-dark' : '' ?>" style="cursor: pointer;" onclick="abrirTarjetaEmergenteCategory('candidato_<?= urlencode($c['candidato_elegido']) ?>', 'Afiliados que votaron por: <?= e($c['candidato_elegido']) ?>')">
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
                            <th>Puesto Votación</th>
                            <th>Mesa</th>
                            <th>Resultado / Candidato</th>
                            <th>Estado Votación</th>
                            <th>Encuestadora</th>
                            <th>Observaciones / Comentarios Adicionales</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($ultimasRespuestas)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-4 text-muted">No se han registrado observaciones de llamadas en <?= e($tituloRondaConsulta) ?> aún.</td>
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
                                    <td class="small">
                                        <?php if (!empty($resp['persona_puesto'])): ?>
                                            <i class="fas fa-building me-1 text-primary"></i><?= e($resp['persona_puesto']) ?>
                                        <?php else: ?>
                                            <span class="text-muted">Sin registrar</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if (!empty($resp['persona_mesa'])): ?>
                                            <span class="badge bg-info text-dark"><?= e($resp['persona_mesa']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
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

<!-- Modal Tarjeta Emergente Categoria -->
<div class="modal fade" id="modalTarjetaEmergenteCat" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold" id="tituloModalEmergenteCat"><i class="fas fa-users me-2 text-warning"></i>Lista de Afiliados</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                    <span class="badge bg-dark fs-6" id="totalModalEmergenteCat">0 Afiliados Encontrados</span>
                    <div style="max-width: 380px;" class="w-100">
                        <input type="text" id="filtroModalEmergenteCat" class="form-control form-control-sm" placeholder="🔍 Buscar (nombre, cédula, celular, comuna)..." onkeyup="filtrarListaModalCat()">
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Cédula</th>
                                <th>Nombres y Apellidos</th>
                                <th>Celular</th>
                                <th>Comuna / Sector</th>
                                <th>Puesto / Mesa</th>
                                <th>Respuesta</th>
                                <th>Observaciones</th>
                                <th>Fecha</th>
                            </tr>
                        </thead>
                        <tbody id="contenidoModalEmergenteCat">
                            <!-- Carga dinámicamente -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    let modalEmergenteCatInstancia;
    let datosModalCatActuales = [];

    document.addEventListener('DOMContentLoaded', function() {
        modalEmergenteCatInstancia = new bootstrap.Modal(document.getElementById('modalTarjetaEmergenteCat'));
    });

    function abrirTarjetaEmergenteCategory(catKey, tituloNombre) {
        document.getElementById('tituloModalEmergenteCat').innerHTML = '<i class="fas fa-list-alt me-2 text-warning"></i>' + tituloNombre;
        const contenedor = document.getElementById('contenidoModalEmergenteCat');
        const badgeTotal = document.getElementById('totalModalEmergenteCat');
        document.getElementById('filtroModalEmergenteCat').value = '';
        
        contenedor.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2 text-primary fa-lg"></i>Cargando lista de afiliados...</td></tr>';
        badgeTotal.textContent = 'Cargando...';
        
        modalEmergenteCatInstancia.show();

        fetch('api_lista_categoria.php?categoria=' + encodeURIComponent(catKey))
            .then(res => res.json())
            .then(data => {
                if (data.success && data.lista && data.lista.length > 0) {
                    datosModalCatActuales = data.lista;
                    renderizarListaModalCat(data.lista);
                    badgeTotal.textContent = data.lista.length + ' Afiliados Encontrados';
                } else if (data.success) {
                    datosModalCatActuales = [];
                    contenedor.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-muted">No se encontraron afiliados en esta categoría.</td></tr>';
                    badgeTotal.textContent = '0 Afiliados';
                } else {
                    contenedor.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-danger"><i class="fas fa-exclamation-triangle me-2"></i>' + (data.message || 'Error al consultar la información.') + '</td></tr>';
                    badgeTotal.textContent = 'Error';
                }
            })
            .catch(err => {
                console.error("Error AJAX:", err);
                contenedor.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Error al consultar la información.</td></tr>';
                badgeTotal.textContent = 'Error';
            });
    }

    function renderizarListaModalCat(lista) {
        const contenedor = document.getElementById('contenidoModalEmergenteCat');
        if (lista.length === 0) {
            contenedor.innerHTML = '<tr><td colspan="9" class="text-center py-3 text-muted">No coinciden resultados con la búsqueda.</td></tr>';
            return;
        }

        let html = '';
        lista.forEach((item, index) => {
            let badgeResp = '<span class="badge bg-primary">' + item.voto_ultimo + '</span>';
            if (item.voto_ultimo.includes('No Contestó')) {
                badgeResp = '<span class="badge bg-warning text-dark"><i class="fas fa-phone-slash me-1"></i>No Contestó</span>';
            } else if (item.voto_ultimo.includes('Cédula Falsa')) {
                badgeResp = '<span class="badge bg-dark"><i class="fas fa-user-times me-1"></i>Cédula Falsa</span>';
            } else if (item.voto_ultimo.includes('Equivocado')) {
                badgeResp = '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle me-1"></i>Equivocado</span>';
            } else if (item.voto_ultimo.includes('Rechazó')) {
                badgeResp = '<span class="badge bg-secondary"><i class="fas fa-ban me-1"></i>Rechazó</span>';
            }

            html += `
                <tr>
                    <td class="small text-muted">${index + 1}</td>
                    <td class="fw-bold"><span class="badge bg-light text-dark border">${item.cedula}</span></td>
                    <td class="fw-bold text-dark">${item.nombre_completo}</td>
                    <td>
                        ${item.celular ? `<a href="tel:${item.celular}" class="btn btn-outline-success btn-sm py-0 px-2 fw-bold"><i class="fas fa-phone-alt me-1"></i>${item.celular}</a>` : '<span class="text-muted">Sin celular</span>'}
                    </td>
                    <td><span class="badge bg-light text-dark border">${item.comuna}</span></td>
                    <td class="small text-muted">${item.puesto_votacion || 'Sin puesto'} / M: ${item.mesa_votacion || 'N/A'}</td>
                    <td>${badgeResp}</td>
                    <td class="small text-muted">${item.observaciones || 'Sin observaciones'}</td>
                    <td class="small text-muted">${item.ultima_fecha}</td>
                </tr>
            `;
        });
        contenedor.innerHTML = html;
    }

    function filtrarListaModalCat() {
        const term = document.getElementById('filtroModalEmergenteCat').value.toLowerCase().trim();
        if (!term) {
            renderizarListaModalCat(datosModalCatActuales);
            return;
        }
        const filtrados = datosModalCatActuales.filter(item => 
            item.nombre_completo.toLowerCase().includes(term) ||
            item.cedula.toLowerCase().includes(term) ||
            (item.celular && item.celular.toLowerCase().includes(term)) ||
            (item.comuna && item.comuna.toLowerCase().includes(term)) ||
            (item.voto_ultimo && item.voto_ultimo.toLowerCase().includes(term)) ||
            (item.observaciones && item.observaciones.toLowerCase().includes(term))
        );
        renderizarListaModalCat(filtrados);
    }
</script>
</body>
</html>
