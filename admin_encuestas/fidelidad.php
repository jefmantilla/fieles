<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/auth.php';

requireRole('AdminEncuestas');

$admin = getCurrentUser();
$pdo = getDB();

// Configuración de Paginación
$paginaActual = max(1, (int)($_GET['page'] ?? 1));
$registrosPorPagina = 16;
$offset = ($paginaActual - 1) * $registrosPorPagina;

$filtroFidelidad = sanitizeInput($_GET['fidelidad'] ?? '');
$buscarText = sanitizeInput($_GET['buscar'] ?? '');
$rondaIdFiltro = sanitizeInput($_GET['ronda_id'] ?? '');

// Consulta para analizar la fidelidad del votante según su historial completo de encuestas
$sqlFidelidad = "
    SELECT r.id as referido_id, r.cedula, CONCAT(r.nombres, ' ', r.apellidos) as nombre_completo,
           r.celular, r.comuna, r.votante_yopal,
           COUNT(re.id) as total_rondas,
           COUNT(DISTINCT re.candidato_elegido) as candidatos_distintos,
           SUBSTRING_INDEX(GROUP_CONCAT(re.candidato_elegido ORDER BY re.creado_en ASC), ',', 1) as voto_inicial,
           SUBSTRING_INDEX(GROUP_CONCAT(re.candidato_elegido ORDER BY re.creado_en DESC), ',', 1) as voto_ultimo,
           MAX(re.creado_en) as ultima_fecha
    FROM referidos r
    JOIN respuestas_encuestas re ON re.referido_id = r.id
    " . ($rondaIdFiltro !== '' ? "WHERE re.ronda_id = " . intval($rondaIdFiltro) . " " : "") . "
    GROUP BY r.id
";

$stmtTodosFidelidad = $pdo->query($sqlFidelidad);
$todosVotantes = $stmtTodosFidelidad->fetchAll();

// Conteo por candidato para votantes 100% Fieles y Estados de Llamada
$fielesPorCandidato = [];

// Obtener lista de candidatos activos
$stmtCandList = $pdo->query("SELECT nombre, grupo FROM candidatos_encuestas WHERE activo = 1 ORDER BY id ASC");
$candidatosActivos = $stmtCandList->fetchAll();

$stmtRondas = $pdo->query("SELECT id, nombre_ronda FROM rondas_encuestas ORDER BY id ASC");
$todasRondas = $stmtRondas->fetchAll();

foreach ($candidatosActivos as $c) {
    $fielesPorCandidato[$c['nombre']] = 0;
}

$countTotalFieles = 0;
$countCambiantes = 0;
$countIndecisos = 0;
$countUnaSola = 0;
$countNoContesto = 0;
$countCedulaFalsa = 0;
$countNumEquivocado = 0;
$countRechazo = 0;

foreach ($todosVotantes as $v) {
    $ultimo = $v['voto_ultimo'];
    
    if (strpos($ultimo, 'No Contestó') !== false) {
        $countNoContesto++;
    } elseif (strpos($ultimo, 'Cédula Falsa') !== false) {
        $countCedulaFalsa++;
    } elseif (strpos($ultimo, 'Equivocado') !== false) {
        $countNumEquivocado++;
    } elseif (strpos($ultimo, 'Rechazó') !== false) {
        $countRechazo++;
    } elseif (strpos(strtolower($ultimo), 'indeciso') !== false) {
        $countIndecisos++;
    } elseif ($rondaIdFiltro === '' && $v['total_rondas'] == 1) {
        $countUnaSola++;
    } elseif ($rondaIdFiltro !== '' || $v['candidatos_distintos'] == 1) {
        $countTotalFieles++;
        if (isset($fielesPorCandidato[$ultimo])) {
            $fielesPorCandidato[$ultimo]++;
        } else {
            $fielesPorCandidato[$ultimo] = 1;
        }
    } elseif ($v['candidatos_distintos'] > 1) {
        $countCambiantes++;
    }
}

// Filtro HAVING para paginación
$havingClauses = [];
$params = [];

if ($filtroFidelidad === 'fiel') {
    $havingClauses[] = $rondaIdFiltro !== '' ? "voto_ultimo NOT LIKE '%Indeciso%' AND voto_ultimo NOT LIKE '%No Contestó%' AND voto_ultimo NOT LIKE '%Cédula Falsa%' AND voto_ultimo NOT LIKE '%Equivocado%' AND voto_ultimo NOT LIKE '%Rechazó%'" : "total_rondas > 1 AND candidatos_distintos = 1 AND voto_ultimo NOT LIKE '%Indeciso%' AND voto_ultimo NOT LIKE '%No Contestó%' AND voto_ultimo NOT LIKE '%Cédula Falsa%' AND voto_ultimo NOT LIKE '%Equivocado%' AND voto_ultimo NOT LIKE '%Rechazó%'";
} elseif ($filtroFidelidad === 'cambiante') {
    $havingClauses[] = "total_rondas > 1 AND candidatos_distintos > 1";
} elseif ($filtroFidelidad === 'indeciso') {
    $havingClauses[] = "voto_ultimo LIKE '%Indeciso%'";
} elseif ($filtroFidelidad === 'no_contesto') {
    $havingClauses[] = "voto_ultimo LIKE '%No Contestó%'";
} elseif ($filtroFidelidad === 'cedula_falsa') {
    $havingClauses[] = "voto_ultimo LIKE '%Cédula Falsa%'";
} elseif ($filtroFidelidad === 'equivocado') {
    $havingClauses[] = "voto_ultimo LIKE '%Equivocado%'";
} elseif ($filtroFidelidad === 'rechazo') {
    $havingClauses[] = "voto_ultimo LIKE '%Rechazó%'";
} elseif (strpos($filtroFidelidad, 'fiel_') === 0) {
    $candSel = substr($filtroFidelidad, 5);
    $havingClauses[] = "voto_ultimo = ?";
    $params[] = $candSel;
}

if (!empty($buscarText)) {
    $havingClauses[] = "(cedula LIKE ? OR nombre_completo LIKE ? OR celular LIKE ?)";
    $term = "%" . $buscarText . "%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$havingSql = !empty($havingClauses) ? " HAVING " . implode(" AND ", $havingClauses) : "";

// Conteo Paginado
$sqlCountPaginado = "SELECT COUNT(*) FROM (" . $sqlFidelidad . $havingSql . ") as sub";
$stmtCountPag = $pdo->prepare($sqlCountPaginado);
$stmtCountPag->execute($params);
$totalRegistrosFiltrados = $stmtCountPag->fetchColumn();
$totalPaginas = max(1, ceil($totalRegistrosFiltrados / $registrosPorPagina));

// Garantizar que la página actual no sobrepase el total de páginas
if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
    $offset = ($paginaActual - 1) * $registrosPorPagina;
}

// Consulta Paginada Final
$sqlFinal = $sqlFidelidad . $havingSql . " ORDER BY total_rondas DESC, ultima_fecha DESC LIMIT " . $registrosPorPagina . " OFFSET " . $offset;
$stmtFinal = $pdo->prepare($sqlFinal);
$stmtFinal->execute($params);
$listaVotantesFidelidad = $stmtFinal->fetchAll();

// Query String para mantener filtros
$queryParams = $_GET;
unset($queryParams['page']);
$queryString = http_build_query($queryParams);
$pageUrlPrefix = '?' . ($queryString ? $queryString . '&' : '') . 'page=';

// Rango inteligente de paginación
$rangoVista = 2;
$inicioPag = max(1, $paginaActual - $rangoVista);
$finPag = min($totalPaginas, $paginaActual + $rangoVista);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Matriz de Fidelidad y Lealtad de Voto - Proyecto Político Social</title>
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
<?php $activeTab = 'fidelidad'; include __DIR__ . '/navbar.php'; ?>

<div class="container-fluid px-4 py-4">

    <!-- Tarjetas de Resumen Interactivas (Hacer clic para abrir la Tarjeta Emergente) -->
    <div class="row g-3 mb-4">
        <?php 
        $coloresBorde = ['border-success', 'border-primary', 'border-warning', 'border-info', 'border-dark'];
        $coloresBg = ['#e8f5e9', '#e3f2fd', '#fff8e1', '#e0f7fa', '#f5f5f5'];
        $coloresTexto = ['text-success', 'text-primary', 'text-warning', 'text-info', 'text-dark'];

        $indexCand = 0;
        foreach ($candidatosActivos as $cand): 
            $nomC = $cand['nombre'];
            $totalFielesCand = $fielesPorCandidato[$nomC] ?? 0;
            $porcFiel = $countTotalFieles > 0 ? round(($totalFielesCand / $countTotalFieles) * 100, 1) : 0;
            $borde = $coloresBorde[$indexCand % count($coloresBorde)];
            $bg = $coloresBg[$indexCand % count($coloresBg)];
            $txt = $coloresTexto[$indexCand % count($coloresTexto)];
        ?>
            <div class="col-xl-3 col-md-6">
                <div class="card card-custom card-interactive bg-white border-start border-4 <?= $borde ?> p-3 shadow-sm h-100" 
                     onclick="abrirTarjetaEmergente('fiel_<?= urlencode($nomC) ?>', 'Votantes Fieles a: <?= e($nomC) ?>')">
                    <div class="card-body p-1 d-flex align-items-center justify-content-between">
                        <div>
                            <h6 class="text-uppercase text-muted fw-bold small mb-1"><?= $rondaIdFiltro !== '' ? 'Votos en esta Ronda' : 'Fieles (100% Leal)' ?></h6>
                            <h5 class="fw-bold text-dark mb-0 text-truncate" style="max-width: 170px;"><?= e($nomC) ?></h5>
                            <div class="mt-1">
                                <span class="fw-bold <?= $txt ?> fs-5"><?= $totalFielesCand ?></span>
                                <span class="text-muted small fw-bold ms-1">(<?= $porcFiel ?>% del total fiel)</span>
                            </div>
                        </div>
                        <div class="<?= $txt ?> p-3 rounded-circle" style="background-color: <?= $bg ?>;">
                            <i class="fas fa-user-check fa-lg"></i>
                        </div>
                    </div>
                </div>
            </div>
        <?php $indexCand++; endforeach; ?>

        <!-- Tarjeta de Control 1: No Contestaron -->
        <div class="col-xl-3 col-md-6">
            <div class="card card-custom card-interactive bg-white border-start border-4 border-warning p-3 shadow-sm h-100"
                 onclick="abrirTarjetaEmergente('no_contesto', 'No Contestaron / Sin Respuesta')">
                <div class="card-body p-1 d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase text-muted fw-bold small mb-1">Estado de Llamada</h6>
                        <h5 class="fw-bold text-dark mb-0">No Contestaron</h5>
                        <div class="mt-1">
                            <span class="fw-bold text-warning text-dark fs-5"><?= $countNoContesto ?></span>
                            <span class="text-muted small fw-bold ms-1">afiliados</span>
                        </div>
                    </div>
                    <div class="text-warning p-3 rounded-circle" style="background-color: #fff8e1;">
                        <i class="fas fa-phone-slash fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tarjeta de Control 2: Cédulas Falsas -->
        <div class="col-xl-3 col-md-6">
            <div class="card card-custom card-interactive bg-white border-start border-4 border-dark p-3 shadow-sm h-100"
                 onclick="abrirTarjetaEmergente('cedula_falsa', 'Cédulas Falsas / Inexistentes')">
                <div class="card-body p-1 d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase text-muted fw-bold small mb-1">Estado de Registro</h6>
                        <h5 class="fw-bold text-dark mb-0">Cédulas Falsas</h5>
                        <div class="mt-1">
                            <span class="fw-bold text-dark fs-5"><?= $countCedulaFalsa ?></span>
                            <span class="text-muted small fw-bold ms-1">afiliados</span>
                        </div>
                    </div>
                    <div class="text-dark p-3 rounded-circle" style="background-color: #e9ecef;">
                        <i class="fas fa-user-times fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tarjeta de Control 3: Números Equivocados -->
        <div class="col-xl-3 col-md-6">
            <div class="card card-custom card-interactive bg-white border-start border-4 border-danger p-3 shadow-sm h-100"
                 onclick="abrirTarjetaEmergente('equivocado', 'Números Equivocados / Inaccesibles')">
                <div class="card-body p-1 d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase text-muted fw-bold small mb-1">Estado de Llamada</h6>
                        <h5 class="fw-bold text-dark mb-0">Núm. Equivocados</h5>
                        <div class="mt-1">
                            <span class="fw-bold text-danger fs-5"><?= $countNumEquivocado ?></span>
                            <span class="text-muted small fw-bold ms-1">afiliados</span>
                        </div>
                    </div>
                    <div class="text-danger p-3 rounded-circle" style="background-color: #f8d7da;">
                        <i class="fas fa-exclamation-triangle fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tarjeta de Control 4: Rechazaron Encuesta -->
        <div class="col-xl-3 col-md-6">
            <div class="card card-custom card-interactive bg-white border-start border-4 border-secondary p-3 shadow-sm h-100"
                 onclick="abrirTarjetaEmergente('rechazo', 'Rechazaron la Encuesta')">
                <div class="card-body p-1 d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase text-muted fw-bold small mb-1">Estado de Llamada</h6>
                        <h5 class="fw-bold text-dark mb-0">Rechazaron Encuesta</h5>
                        <div class="mt-1">
                            <span class="fw-bold text-secondary fs-5"><?= $countRechazo ?></span>
                            <span class="text-muted small fw-bold ms-1">afiliados</span>
                        </div>
                    </div>
                    <div class="text-secondary p-3 rounded-circle" style="background-color: #e2e3e5;">
                        <i class="fas fa-ban fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Panel de Filtros para la Matriz de Fidelidad -->
    <div class="card card-custom mb-4 shadow-sm border">
        <div class="card-body p-3">
            <form action="fidelidad.php" method="GET" class="row g-2 align-items-center">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted mb-1"><i class="fas fa-history me-1"></i>Ronda de Encuesta:</label>
                    <select name="ronda_id" class="form-select form-select-sm">
                        <option value="">-- Todas las Rondas (Matriz Histórica) --</option>
                        <?php foreach ($todasRondas as $ronda): ?>
                            <option value="<?= $ronda['id'] ?>" <?= $rondaIdFiltro == $ronda['id'] ? 'selected' : '' ?>><?= e($ronda['nombre_ronda']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted mb-1"><i class="fas fa-filter me-1"></i>Filtrar por Candidato o Estado:</label>
                    <select name="fidelidad" class="form-select form-select-sm">
                        <option value="">-- Todos los Votantes Encuestados --</option>
                        <option value="fiel" <?= $filtroFidelidad === 'fiel' ? 'selected' : '' ?>>🟩 <?= $rondaIdFiltro !== '' ? 'Votos Válidos' : 'Todos los Votantes Fieles' ?> (<?= $countTotalFieles ?>)</option>
                        <?php foreach ($candidatosActivos as $c): ?>
                            <option value="fiel_<?= e($c['nombre']) ?>" <?= $filtroFidelidad === 'fiel_' . $c['nombre'] ? 'selected' : '' ?>>
                                ➔ <?= $rondaIdFiltro !== '' ? 'Votos por' : 'Fieles a' ?>: <?= e($c['nombre']) ?> (<?= $fielesPorCandidato[$c['nombre']] ?? 0 ?>)
                            </option>
                        <?php endforeach; ?>
                        <option value="cambiante" <?= $filtroFidelidad === 'cambiante' ? 'selected' : '' ?>>🟧 Votantes Cambiantes / En Riesgo (<?= $countCambiantes ?>)</option>
                        <option value="indeciso" <?= $filtroFidelidad === 'indeciso' ? 'selected' : '' ?>>🟨 Indecisos (<?= $countIndecisos ?>)</option>
                        <option value="no_contesto" <?= $filtroFidelidad === 'no_contesto' ? 'selected' : '' ?>>📞 No Contestaron / Sin Respuesta (<?= $countNoContesto ?>)</option>
                        <option value="cedula_falsa" <?= $filtroFidelidad === 'cedula_falsa' ? 'selected' : '' ?>>💳 Cédulas Falsas / Inexistentes (<?= $countCedulaFalsa ?>)</option>
                        <option value="equivocado" <?= $filtroFidelidad === 'equivocado' ? 'selected' : '' ?>>⚠️ Números Equivocados / Inaccesibles (<?= $countNumEquivocado ?>)</option>
                        <option value="rechazo" <?= $filtroFidelidad === 'rechazo' ? 'selected' : '' ?>>🛑 Rechazaron la Encuesta (<?= $countRechazo ?>)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted mb-1"><i class="fas fa-search me-1"></i>Buscar Afiliado:</label>
                    <input type="text" name="buscar" class="form-control form-control-sm" placeholder="Nombre, Cédula o Celular..." value="<?= e($buscarText) ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end justify-content-end gap-2 pt-3">
                    <a href="fidelidad.php" class="btn btn-secondary btn-sm"><i class="fas fa-undo me-1"></i> Limpiar</a>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search me-1"></i> Filtrar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabla Matriz de Fidelidad del Votante -->
    <div class="card card-custom shadow-sm border">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold text-primary mb-0"><i class="fas fa-shield-alt me-2"></i>Matriz de Lealtad y Evolución del Voto</h5>
                <span class="badge bg-dark fs-6">Mostrando <?= count($listaVotantesFidelidad) ?> de <?= $totalRegistrosFiltrados ?> Afiliados (Página <?= $paginaActual ?> de <?= $totalPaginas ?>)</span>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Afiliado</th>
                            <th>Cédula</th>
                            <th>Celular</th>
                            <th>Sector</th>
                            <th>Rondas Tomadas</th>
                            <th>Primer Voto Registrado</th>
                            <th>Último Voto Registrado</th>
                            <th>Clasificación de Lealtad / Estado</th>
                            <th class="text-center">Historial Completo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($listaVotantesFidelidad)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-4 text-muted">No se encontraron afiliados con encuestas registradas.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($listaVotantesFidelidad as $vf): 
                                $esEspecial = strpos($vf['voto_ultimo'], 'No Contestó') !== false || strpos($vf['voto_ultimo'], 'Cédula Falsa') !== false || strpos($vf['voto_ultimo'], 'Equivocado') !== false || strpos($vf['voto_ultimo'], 'Rechazó') !== false;
                            ?>
                                <tr class="<?= $esEspecial ? 'table-warning text-dark' : '' ?>">
                                    <td class="fw-bold text-dark"><?= e($vf['nombre_completo']) ?></td>
                                    <td><?= e($vf['cedula']) ?></td>
                                    <td><i class="fas fa-phone-alt me-1 text-muted small"></i><?= e($vf['celular']) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= e($vf['comuna']) ?></span></td>
                                    
                                    <td>
                                        <span class="badge bg-dark fs-6"><i class="fas fa-sync-alt me-1 text-warning"></i><?= $vf['total_rondas'] ?> Rondas</span>
                                    </td>

                                    <td><span class="badge bg-light text-dark border"><?= e($vf['voto_inicial']) ?></span></td>
                                    <td>
                                        <span class="badge <?= $esEspecial ? 'bg-warning text-dark' : 'bg-primary' ?>">
                                            <?= e($vf['voto_ultimo']) ?>
                                        </span>
                                    </td>

                                    <!-- Clasificación de Lealtad y Estado -->
                                    <td>
                                        <?php if (strpos($vf['voto_ultimo'], 'No Contestó') !== false): ?>
                                            <span class="badge bg-warning text-dark py-1 px-2 fs-6"><i class="fas fa-phone-slash me-1"></i>No Contestó (Reintentar)</span>
                                        <?php elseif (strpos($vf['voto_ultimo'], 'Cédula Falsa') !== false): ?>
                                            <span class="badge bg-dark py-1 px-2 fs-6"><i class="fas fa-user-times me-1"></i>Cédula Falsa</span>
                                        <?php elseif (strpos($vf['voto_ultimo'], 'Equivocado') !== false): ?>
                                            <span class="badge bg-danger py-1 px-2 fs-6"><i class="fas fa-exclamation-triangle me-1"></i>Núm. Equivocado</span>
                                        <?php elseif (strpos($vf['voto_ultimo'], 'Rechazó') !== false): ?>
                                            <span class="badge bg-secondary py-1 px-2 fs-6"><i class="fas fa-ban me-1"></i>Rechazó Encuesta</span>
                                        <?php elseif ($vf['total_rondas'] == 1): ?>
                                            <span class="badge bg-info text-white py-1 px-2"><i class="fas fa-user-clock me-1"></i>1 Sola Toma (<?= e($vf['voto_ultimo']) ?>)</span>
                                        <?php elseif ($vf['candidatos_distintos'] == 1 && strpos(strtolower($vf['voto_ultimo']), 'indeciso') === false): ?>
                                            <span class="badge bg-success py-1 px-2 fs-6"><i class="fas fa-user-check me-1"></i>Votante Fiel (<?= e($vf['voto_ultimo']) ?>)</span>
                                        <?php elseif ($vf['candidatos_distintos'] > 1): ?>
                                            <span class="badge bg-warning text-dark py-1 px-2 fs-6"><i class="fas fa-exchange-alt me-1"></i>Cambiante (En Riesgo)</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary py-1 px-2"><i class="fas fa-question-circle me-1"></i>Indeciso</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-center">
                                        <button type="button" class="btn btn-outline-primary btn-sm shadow-0" onclick="verHistorialVotante(<?= $vf['referido_id'] ?>, '<?= e($vf['nombre_completo']) ?>')">
                                            <i class="fas fa-history me-1"></i> Ver Historial & Notas
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Navegación de Paginación Inteligente -->
            <?php if ($totalPaginas > 1): ?>
                <nav aria-label="Navegación de lealtad" class="mt-4">
                    <ul class="pagination justify-content-center flex-wrap">
                        <!-- Anterior -->
                        <li class="page-item <?= ($paginaActual <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= $pageUrlPrefix . ($paginaActual - 1) ?>">
                                <i class="fas fa-chevron-left me-1"></i> Anterior
                            </a>
                        </li>

                        <!-- Primera página siempre -->
                        <?php if ($inicioPag > 1): ?>
                            <li class="page-item"><a class="page-link" href="<?= $pageUrlPrefix ?>1">1</a></li>
                            <?php if ($inicioPag > 2): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                        <?php endif; ?>

                        <!-- Páginas del rango central -->
                        <?php for ($p = $inicioPag; $p <= $finPag; $p++): ?>
                            <li class="page-item <?= ($p === $paginaActual) ? 'active' : '' ?>">
                                <a class="page-link" href="<?= $pageUrlPrefix . $p ?>"><?= $p ?></a>
                            </li>
                        <?php endfor; ?>

                        <!-- Última página siempre -->
                        <?php if ($finPag < $totalPaginas): ?>
                            <?php if ($finPag < $totalPaginas - 1): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                            <li class="page-item"><a class="page-link" href="<?= $pageUrlPrefix . $totalPaginas ?>"><?= $totalPaginas ?></a></li>
                        <?php endif; ?>

                        <!-- Siguiente -->
                        <li class="page-item <?= ($paginaActual >= $totalPaginas) ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= $pageUrlPrefix . ($paginaActual + 1) ?>">
                                Siguiente <i class="fas fa-chevron-right ms-1"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>

        </div>
    </div>

</div>

<!-- MODAL TARJETA EMERGENTE (LISTA INTERACTIVA AL HACER CLIC EN LOS RECUADROS) -->
<div class="modal fade" id="modalTarjetaEmergente" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold" id="tituloModalEmergente"><i class="fas fa-users me-2 text-warning"></i>Lista de Afiliados</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                    <span class="badge bg-primary fs-6" id="totalModalEmergente">0 Afiliados Encontrados</span>
                    <div style="max-width: 380px;" class="w-100">
                        <input type="text" id="filtroModalEmergente" class="form-control form-control-sm" placeholder="🔍 Buscar en esta lista (nombre, cédula, celular)..." onkeyup="filtrarListaModal()">
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Afiliado</th>
                                <th>Cédula</th>
                                <th>Celular</th>
                                <th>Sector / Comuna</th>
                                <th>Puesto y Mesa</th>
                                <th>Último Resultado</th>
                                <th>Fecha & Nota</th>
                            </tr>
                        </thead>
                        <tbody id="contenidoModalEmergente">
                            <!-- Se carga dinámicamente mediante AJAX -->
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

<!-- Modal Ver Historial Completo y Observaciones del Votante -->
<div class="modal fade" id="modalHistorialVotante" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="fas fa-history me-2 text-warning"></i>Evolución de Respuestas y Observaciones: <span id="nombreVotanteModal" class="text-warning fw-bold"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th># Ronda</th>
                                <th>Fecha y Hora</th>
                                <th>Candidato / Estado</th>
                                <th>Estado Votación</th>
                                <th>Encuestadora</th>
                                <th>Observaciones / Comentarios Adicionales</th>
                            </tr>
                        </thead>
                        <tbody id="contenidoHistorialVotante">
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
    let historialModal;
    let modalEmergenteInstancia;
    let datosModalActuales = [];

    document.addEventListener('DOMContentLoaded', function() {
        historialModal = new bootstrap.Modal(document.getElementById('modalHistorialVotante'));
        modalEmergenteInstancia = new bootstrap.Modal(document.getElementById('modalTarjetaEmergente'));
    });

    function abrirTarjetaEmergente(categoriaKey, tituloNombre) {
        document.getElementById('tituloModalEmergente').innerHTML = '<i class="fas fa-list-alt me-2 text-warning"></i>' + tituloNombre;
        const contenedor = document.getElementById('contenidoModalEmergente');
        const badgeTotal = document.getElementById('totalModalEmergente');
        document.getElementById('filtroModalEmergente').value = '';
        
        contenedor.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2 text-primary fa-lg"></i>Cargando lista de afiliados...</td></tr>';
        badgeTotal.textContent = 'Cargando...';
        
        modalEmergenteInstancia.show();

        fetch('api_lista_categoria.php?categoria=' + encodeURIComponent(categoriaKey) + '&ronda_id=<?= urlencode($rondaIdFiltro) ?>')
            .then(res => res.json())
            .then(data => {
                if (data.success && data.lista.length > 0) {
                    datosModalActuales = data.lista;
                    renderizarListaModal(data.lista);
                    badgeTotal.textContent = data.lista.length + ' Afiliados Encontrados';
                } else {
                    datosModalActuales = [];
                    contenedor.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted">No se encontraron afiliados en esta categoría.</td></tr>';
                    badgeTotal.textContent = '0 Afiliados';
                }
            })
            .catch(err => {
                contenedor.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Error al consultar la información.</td></tr>';
                badgeTotal.textContent = 'Error';
            });
    }

    function renderizarListaModal(lista) {
        const contenedor = document.getElementById('contenidoModalEmergente');
        if (lista.length === 0) {
            contenedor.innerHTML = '<tr><td colspan="7" class="text-center py-3 text-muted">No coinciden resultados con la búsqueda.</td></tr>';
            return;
        }

        let html = '';
        lista.forEach(item => {
            const esEspecial = item.voto_ultimo.includes('No Contestó') || item.voto_ultimo.includes('Cédula Falsa') || item.voto_ultimo.includes('Equivocado') || item.voto_ultimo.includes('Rechazó');
            const badgeClass = esEspecial ? 'bg-warning text-dark' : 'bg-primary';

            html += `
                <tr>
                    <td class="fw-bold text-dark">${item.nombre_completo}</td>
                    <td><span class="badge bg-light text-dark border">${item.cedula}</span></td>
                    <td>
                        ${item.celular ? `<a href="tel:${item.celular}" class="btn btn-outline-success btn-sm py-0 px-2 fw-bold"><i class="fas fa-phone-alt me-1"></i>${item.celular}</a>` : '<span class="text-muted">Sin celular</span>'}
                    </td>
                    <td><span class="badge bg-light text-dark border">${item.comuna}</span></td>
                    <td class="small">
                        <div><i class="fas fa-building me-1 text-muted"></i>${item.puesto_votacion}</div>
                        <div class="text-muted"><i class="fas fa-sort-numeric-up me-1"></i>Mesa ${item.mesa_votacion}</div>
                    </td>
                    <td><span class="badge ${badgeClass}">${item.voto_ultimo}</span></td>
                    <td class="small">
                        <div class="text-muted"><i class="far fa-clock me-1"></i>${item.ultima_fecha}</div>
                        <div class="italic text-dark">${item.observaciones}</div>
                    </td>
                </tr>
            `;
        });
        contenedor.innerHTML = html;
    }

    function filtrarListaModal() {
        const term = document.getElementById('filtroModalEmergente').value.toLowerCase().trim();
        if (!term) {
            renderizarListaModal(datosModalActuales);
            return;
        }
        const filtrados = datosModalActuales.filter(item => 
            item.nombre_completo.toLowerCase().includes(term) ||
            item.cedula.toLowerCase().includes(term) ||
            item.celular.toLowerCase().includes(term) ||
            item.comuna.toLowerCase().includes(term)
        );
        renderizarListaModal(filtrados);
    }

    function verHistorialVotante(referidoId, nombreCompleto) {
        document.getElementById('nombreVotanteModal').textContent = nombreCompleto;
        const contenedor = document.getElementById('contenidoHistorialVotante');
        contenedor.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2 text-primary"></i>Cargando historial de encuestas...</td></tr>';
        
        historialModal.show();

        fetch('api_historial_votante.php?id=' + referidoId)
            .then(res => res.json())
            .then(data => {
                if (data.success && data.historial.length > 0) {
                    let html = '';
                    data.historial.forEach((item, index) => {
                        const esNoContesto = item.candidato.includes('No Contestó') || item.candidato.includes('Cédula Falsa') || item.candidato.includes('Equivocado') || item.candidato.includes('Rechazó');
                        const badgeClass = esNoContesto ? 'bg-warning text-dark' : 'bg-primary';
                        
                        html += `
                            <tr>
                                <td class="fw-bold">Ronda #${item.numero_ronda || (index + 1)}</td>
                                <td class="small text-muted"><i class="far fa-clock me-1"></i>${item.fecha}</td>
                                <td><span class="badge ${badgeClass}">${item.candidato}</span></td>
                                <td><span class="badge bg-light text-dark border">${item.votante_yopal}</span></td>
                                <td class="small text-muted"><i class="fas fa-headset me-1 text-success"></i>${item.encuestadora}</td>
                                <td class="small text-dark italic">${item.observaciones || '<span class="text-muted">Sin observaciones</span>'}</td>
                            </tr>
                        `;
                    });
                    contenedor.innerHTML = html;
                } else {
                    contenedor.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">No se encontró historial previo.</td></tr>';
                }
            })
            .catch(err => {
                contenedor.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-danger"><i class="fas fa-exclamation-circle me-2"></i>Error al consultar el historial.</td></tr>';
            });
    }
</script>
</body>
</html>
