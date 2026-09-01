<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/auth.php';

requireRole('Admin');

$admin = getCurrentUser();
$pdo = getDB();

// Parámetros de Filtros
$liderFiltro = (int)($_GET['lider_id'] ?? 0);
$votanteFiltro = sanitizeInput($_GET['votante_yopal'] ?? '');
$comunaFiltro = sanitizeInput($_GET['comuna'] ?? '');
$tipoRefFiltro = sanitizeInput($_GET['tipo_ref'] ?? '');
$buscarFiltro = sanitizeInput($_GET['buscar'] ?? '');

$paginaActual = max(1, (int)($_GET['page'] ?? 1));
$registrosPorPagina = 15;
$offset = ($paginaActual - 1) * $registrosPorPagina;

// Obtener lista de todos los líderes para el filtro
$stmtLideresList = $pdo->query("SELECT id, nombre_completo, username FROM usuarios WHERE role_id = 2 ORDER BY nombre_completo ASC");
$listaLideres = $stmtLideresList->fetchAll();

// Construcción dinámica de la cláusula WHERE para filtros
$whereClauses = [];
$params = [];

if ($liderFiltro > 0) {
    $whereClauses[] = "r.lider_raiz_id = ?";
    $params[] = $liderFiltro;
}

if (!empty($votanteFiltro)) {
    $whereClauses[] = "r.votante_yopal = ?";
    $params[] = $votanteFiltro;
}

if (!empty($comunaFiltro)) {
    $whereClauses[] = "r.comuna = ?";
    $params[] = $comunaFiltro;
}

if (!empty($tipoRefFiltro)) {
    $whereClauses[] = "r.referido_por_tipo = ?";
    $params[] = $tipoRefFiltro;
}

if (!empty($buscarFiltro)) {
    $term = "%" . $buscarFiltro . "%";
    $words = explode(' ', preg_replace('/\s+/', ' ', trim($buscarFiltro)));
    
    $subConditions = [
        "CONCAT(r.nombres, ' ', r.apellidos) LIKE ?",
        "r.cedula LIKE ?",
        "r.celular LIKE ?",
        "r.correo LIKE ?",
        "r.comuna LIKE ?"
    ];
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;

    if (count($words) > 1) {
        $wordConditions = [];
        foreach ($words as $w) {
            $wordConditions[] = "CONCAT(r.nombres, ' ', r.apellidos, ' ', r.cedula, ' ', r.celular) LIKE ?";
            $params[] = "%" . $w . "%";
        }
        $subConditions[] = "(" . implode(" AND ", $wordConditions) . ")";
    }

    $whereClauses[] = "(" . implode(" OR ", $subConditions) . ")";
}

$whereSql = !empty($whereClauses) ? " WHERE " . implode(" AND ", $whereClauses) : "";

// Métricas Globales o Filtradas
$stmtTotalRef = $pdo->prepare("SELECT COUNT(*) FROM referidos r" . $whereSql);
$stmtTotalRef->execute($params);
$totalReferidos = $stmtTotalRef->fetchColumn();

// Conteo por Estados de Votación
$whereSi = !empty($whereClauses) ? $whereSql . " AND r.votante_yopal = 'Si'" : " WHERE r.votante_yopal = 'Si'";
$stmtSi = $pdo->prepare("SELECT COUNT(*) FROM referidos r" . $whereSi);
$stmtSi->execute($params);
$totalSi = $stmtSi->fetchColumn();

$whereInscribir = !empty($whereClauses) ? $whereSql . " AND r.votante_yopal = 'Quiero inscribir'" : " WHERE r.votante_yopal = 'Quiero inscribir'";
$stmtInscribir = $pdo->prepare("SELECT COUNT(*) FROM referidos r" . $whereInscribir);
$stmtInscribir->execute($params);
$totalInscribir = $stmtInscribir->fetchColumn();

$whereNo = !empty($whereClauses) ? $whereSql . " AND r.votante_yopal = 'No'" : " WHERE r.votante_yopal = 'No'";
$stmtNo = $pdo->prepare("SELECT COUNT(*) FROM referidos r" . $whereNo);
$stmtNo->execute($params);
$totalNo = $stmtNo->fetchColumn();

$totalYopal = $totalSi + $totalInscribir;
$porcentajeYopal = $totalReferidos > 0 ? round(($totalYopal / $totalReferidos) * 100, 1) : 0;
$totalPaginas = max(1, ceil($totalReferidos / $registrosPorPagina));

$stmtTotalLideres = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE role_id = 2");
$totalLideres = $stmtTotalLideres->fetchColumn();

// Consultar Referidos Filtrados y Paginados
$sql = "
    SELECT r.*, 
           l.nombre_completo as lider_raiz_nombre,
           (SELECT COUNT(*) FROM referidos WHERE referido_por_tipo = 'referido' AND referido_por_id = r.id) as sub_referidos_count,
           (SELECT COUNT(*) FROM referidos WHERE referido_por_tipo = 'referido' AND referido_por_id = r.id AND votante_yopal = 'Si') as sub_si_count,
           (SELECT COUNT(*) FROM referidos WHERE referido_por_tipo = 'referido' AND referido_por_id = r.id AND votante_yopal = 'Quiero inscribir') as sub_inscribir_count,
           (SELECT COUNT(*) FROM referidos WHERE referido_por_tipo = 'referido' AND referido_por_id = r.id AND votante_yopal = 'No') as sub_no_count,
           CASE 
             WHEN r.referido_por_tipo = 'usuario' THEN u.nombre_completo 
             ELSE CONCAT(p.nombres, ' ', p.apellidos) 
           END as invitador_directo
    FROM referidos r
    LEFT JOIN usuarios l ON r.lider_raiz_id = l.id
    LEFT JOIN usuarios u ON (r.referido_por_tipo = 'usuario' AND r.referido_por_id = u.id)
    LEFT JOIN referidos p ON (r.referido_por_tipo = 'referido' AND r.referido_por_id = p.id)
    " . $whereSql . "
    ORDER BY r.creado_en DESC LIMIT " . $registrosPorPagina . " OFFSET " . $offset;

$stmtMiembros = $pdo->prepare($sql);
$stmtMiembros->execute($params);
$referidos = $stmtMiembros->fetchAll();

// Construir query string para paginación
$queryParams = $_GET;
unset($queryParams['page']);
$queryString = http_build_query($queryParams);
$pageUrlPrefix = '?' . ($queryString ? $queryString . '&' : '') . 'page=';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Administrador - Proyecto Político Social</title>
    <!-- Font Awesome & MDB / Bootstrap 5 CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/6.4.0/mdb.min.css">
    <link rel="stylesheet" href="../assets/css/custom.css">
</head>
<body class="bg-light">

<!-- Navbar Administrador Estándar Completo -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-0 py-2">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="dashboard.php">
            <i class="fas fa-handshake text-warning me-2"></i>Proyecto Político Social
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarAdminMenu" aria-controls="navbarAdminMenu" aria-expanded="false" aria-label="Toggle navigation">
            <i class="fas fa-bars text-white"></i>
        </button>
        
        <div class="collapse navbar-collapse" id="navbarAdminMenu">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link active fw-bold" href="dashboard.php"><i class="fas fa-chart-pie me-1"></i> Dashboard General</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-white-50 fw-bold" href="lideres.php"><i class="fas fa-users-cog me-1 text-warning"></i> Gestionar Líderes</a>
                </li>
            </ul>
            <div class="d-flex align-items-center">
                <span class="text-white me-3"><i class="fas fa-user-shield me-1 text-warning"></i><?= e($admin['nombre_completo']) ?></span>
                <a href="../logout.php" class="btn btn-outline-light btn-sm"><i class="fas fa-sign-out-alt me-1"></i> Salir</a>
            </div>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 py-4">

    <!-- Tarjetas Métricas Sutiles y Elegantes -->
    <div class="row g-3 mb-4">
        <!-- Card 1: Total Líderes -->
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="card card-custom bg-white border-start border-4 border-primary p-3 h-100 shadow-sm">
                <div class="card-body p-1 d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase text-muted fw-bold small mb-1">Líderes Activos</h6>
                        <h3 class="fw-bold text-dark mb-0"><?= $totalLideres ?></h3>
                    </div>
                    <div class="text-primary bg-primary-subtle p-3 rounded-circle" style="background-color: #e3f2fd;">
                        <i class="fas fa-user-tie fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Card 2: Total Referidos -->
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="card card-custom bg-white border-start border-4 border-info p-3 h-100 shadow-sm">
                <div class="card-body p-1 d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase text-muted fw-bold small mb-1">Total Referidos</h6>
                        <h3 class="fw-bold text-dark mb-0"><?= $totalReferidos ?></h3>
                    </div>
                    <div class="text-info bg-info-subtle p-3 rounded-circle" style="background-color: #e0f7fa;">
                        <i class="fas fa-users fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Card 3: Votan en Yopal -->
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="card card-custom bg-white border-start border-4 border-success p-3 h-100 shadow-sm">
                <div class="card-body p-1 d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase text-muted fw-bold small mb-1">Votan en Yopal</h6>
                        <h3 class="fw-bold text-dark mb-0"><?= $totalSi ?></h3>
                    </div>
                    <div class="text-success bg-success-subtle p-3 rounded-circle" style="background-color: #e8f5e9;">
                        <i class="fas fa-vote-yea fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Card 4: Quieren Inscribir Cédula -->
        <div class="col-xl-3 col-md-6 col-sm-6">
            <div class="card card-custom bg-white border-start border-4 border-warning p-3 h-100 shadow-sm">
                <div class="card-body p-1 d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase text-muted fw-bold small mb-1">Quieren Inscribir Cédula</h6>
                        <h3 class="fw-bold text-dark mb-0"><?= $totalInscribir ?></h3>
                    </div>
                    <div class="text-warning bg-warning-subtle p-3 rounded-circle" style="background-color: #fff8e1;">
                        <i class="fas fa-edit fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Card 5: No Votantes / Otro -->
        <div class="col-xl-3 col-md-6 col-sm-6">
            <div class="card card-custom bg-white border-start border-4 border-secondary p-3 h-100 shadow-sm">
                <div class="card-body p-1 d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase text-muted fw-bold small mb-1">No Votantes / Otro</h6>
                        <h3 class="fw-bold text-dark mb-0"><?= $totalNo ?></h3>
                    </div>
                    <div class="text-secondary bg-secondary-subtle p-3 rounded-circle" style="background-color: #f5f5f5;">
                        <i class="fas fa-times-circle fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Fila Reorganizada: Gráfico Intuitivo + Panel de Filtros -->
    <div class="row g-4 mb-4">
        <!-- Gráfico Estado de Votantes en Yopal -->
        <div class="col-lg-4">
            <div class="card card-custom h-100 shadow-sm border">
                <div class="card-body p-4 text-center">
                    <h5 class="fw-bold text-primary mb-3"><i class="fas fa-chart-pie me-2"></i>Intención de Voto Yopal</h5>
                    <div style="height: 200px; position: relative;">
                        <canvas id="chartVotantes"></canvas>
                    </div>
                    <div class="mt-3 small text-start">
                        <div class="d-flex justify-content-between mb-1">
                            <span><i class="fas fa-circle text-success me-1"></i> Votan en Yopal:</span>
                            <strong class="text-success"><?= $totalSi ?> (<?= $totalReferidos > 0 ? round(($totalSi / $totalReferidos) * 100, 1) : 0 ?>%)</strong>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span><i class="fas fa-circle text-warning me-1"></i> Inscribirán Cédula:</span>
                            <strong class="text-warning"><?= $totalInscribir ?> (<?= $totalReferidos > 0 ? round(($totalInscribir / $totalReferidos) * 100, 1) : 0 ?>%)</strong>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span><i class="fas fa-circle text-secondary me-1"></i> No Votantes / Otro:</span>
                            <strong class="text-secondary"><?= $totalNo ?> (<?= $totalReferidos > 0 ? round(($totalNo / $totalReferidos) * 100, 1) : 0 ?>%)</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Panel de Filtros de Búsqueda Avanzados -->
        <div class="col-lg-8">
            <div class="card card-custom h-100 shadow-sm border">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="fw-bold text-primary mb-0"><i class="fas fa-search-location me-2"></i>Filtros Dinámicos de Búsqueda</h5>
                </div>
                <div class="card-body p-4 pt-0">
                    <form action="dashboard.php" method="GET" class="row g-3">
                        
                        <!-- Líder Raíz -->
                        <div class="col-md-6">
                            <label for="lider_id" class="form-label fw-bold small text-muted"><i class="fas fa-user-tie me-1"></i>Filtrar por Líder Raíz:</label>
                            <select name="lider_id" id="lider_id" class="form-select">
                                <option value="0">-- Todos los Líderes (Consolidado) --</option>
                                <?php foreach ($listaLideres as $lid): ?>
                                    <option value="<?= $lid['id'] ?>" <?= $liderFiltro === (int)$lid['id'] ? 'selected' : '' ?>>
                                        <?= e($lid['nombre_completo']) ?> (@<?= e($lid['username']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Comuna o Corregimiento -->
                        <div class="col-md-6">
                            <label for="comuna" class="form-label fw-bold small text-muted"><i class="fas fa-map-marker-alt me-1"></i>Comuna o Corregimiento:</label>
                            <select name="comuna" id="comuna" class="form-select">
                                <option value="">-- Todos los Sectores --</option>
                                <optgroup label="Comunas Urbanas">
                                    <option value="Comuna 1" <?= $comunaFiltro === 'Comuna 1' ? 'selected' : '' ?>>Comuna 1 - El Hobo</option>
                                    <option value="Comuna 2" <?= $comunaFiltro === 'Comuna 2' ? 'selected' : '' ?>>Comuna 2 - Viveros</option>
                                    <option value="Comuna 3" <?= $comunaFiltro === 'Comuna 3' ? 'selected' : '' ?>>Comuna 3 - Clelia Rivero</option>
                                    <option value="Comuna 4" <?= $comunaFiltro === 'Comuna 4' ? 'selected' : '' ?>>Comuna 4 - Campiña</option>
                                    <option value="Comuna 5" <?= $comunaFiltro === 'Comuna 5' ? 'selected' : '' ?>>Comuna 5 - Villa del Sol</option>
                                    <option value="Comuna 6" <?= $comunaFiltro === 'Comuna 6' ? 'selected' : '' ?>>Comuna 6 - Llano Lindo</option>
                                </optgroup>
                                <optgroup label="Corregimientos Rurales">
                                    <option value="Corregimiento El Morro" <?= $comunaFiltro === 'Corregimiento El Morro' ? 'selected' : '' ?>>Corregimiento El Morro</option>
                                    <option value="Corregimiento La Chaparrera" <?= $comunaFiltro === 'Corregimiento La Chaparrera' ? 'selected' : '' ?>>Corregimiento La Chaparrera</option>
                                    <option value="Corregimiento Tilodirán" <?= $comunaFiltro === 'Corregimiento Tilodirán' ? 'selected' : '' ?>>Corregimiento Tilodirán</option>
                                    <option value="Corregimiento Quebradaseca" <?= $comunaFiltro === 'Corregimiento Quebradaseca' ? 'selected' : '' ?>>Corregimiento Quebradaseca</option>
                                    <option value="Corregimiento Punto Nuevo" <?= $comunaFiltro === 'Corregimiento Punto Nuevo' ? 'selected' : '' ?>>Corregimiento Punto Nuevo</option>
                                    <option value="Corregimiento El Taladro" <?= $comunaFiltro === 'Corregimiento El Taladro' ? 'selected' : '' ?>>Corregimiento El Taladro</option>
                                    <option value="Corregimiento Tacarimena" <?= $comunaFiltro === 'Corregimiento Tacarimena' ? 'selected' : '' ?>>Corregimiento Tacarimena</option>
                                    <option value="Corregimiento La Niata" <?= $comunaFiltro === 'Corregimiento La Niata' ? 'selected' : '' ?>>Corregimiento La Niata</option>
                                    <option value="Corregimiento La Guafilla" <?= $comunaFiltro === 'Corregimiento La Guafilla' ? 'selected' : '' ?>>Corregimiento La Guafilla</option>
                                    <option value="Corregimiento Mata de Limón" <?= $comunaFiltro === 'Corregimiento Mata de Limón' ? 'selected' : '' ?>>Corregimiento Mata de Limón</option>
                                    <option value="Corregimiento El Charte" <?= $comunaFiltro === 'Corregimiento El Charte' ? 'selected' : '' ?>>Corregimiento El Charte</option>
                                </optgroup>
                            </select>
                        </div>

                        <!-- Estado Votante -->
                        <div class="col-md-6">
                            <label for="votante_yopal" class="form-label fw-bold small text-muted"><i class="fas fa-vote-yea me-1"></i>Estado de Votación:</label>
                            <select name="votante_yopal" id="votante_yopal" class="form-select">
                                <option value="">-- Todos los Estados --</option>
                                <option value="Si" <?= $votanteFiltro === 'Si' ? 'selected' : '' ?>>Sí, voto en Yopal</option>
                                <option value="Quiero inscribir" <?= $votanteFiltro === 'Quiero inscribir' ? 'selected' : '' ?>>Quiero inscribir cédula</option>
                                <option value="No" <?= $votanteFiltro === 'No' ? 'selected' : '' ?>>No, voto en otro municipio</option>
                            </select>
                        </div>

                        <!-- Nivel de Red -->
                        <div class="col-md-6">
                            <label for="tipo_ref" class="form-label fw-bold small text-muted"><i class="fas fa-sitemap me-1"></i>Nivel de Red:</label>
                            <select name="tipo_ref" id="tipo_ref" class="form-select">
                                <option value="">-- Todos los Niveles --</option>
                                <option value="usuario" <?= $tipoRefFiltro === 'usuario' ? 'selected' : '' ?>>Directos del Líder</option>
                                <option value="referido" <?= $tipoRefFiltro === 'referido' ? 'selected' : '' ?>>Referidos de la Red (Multinivel)</option>
                            </select>
                        </div>

                        <!-- Buscar Texto -->
                        <div class="col-md-8">
                            <label for="buscar" class="form-label fw-bold small text-muted"><i class="fas fa-search me-1"></i>Buscar Nombre Completo, Cédula o Celular:</label>
                            <input type="text" name="buscar" id="buscar" class="form-control" placeholder="Ej. Mateo Jhon Castillo Suárez o Cédula..." value="<?= e($buscarFiltro) ?>">
                        </div>

                        <!-- Botones de Acción -->
                        <div class="col-md-4 d-flex align-items-end justify-content-end gap-2">
                            <a href="dashboard.php" class="btn btn-secondary btn-block btn-sm"><i class="fas fa-undo me-1"></i> Limpiar</a>
                            <button type="submit" class="btn btn-primary btn-block btn-sm"><i class="fas fa-filter me-1"></i> Aplicar</button>
                        </div>

                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabla General de Referidos -->
    <div class="card card-custom shadow-sm border">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold text-primary mb-0"><i class="fas fa-table me-2"></i>Consolidado General de la Red</h5>
                <span class="badge bg-dark fs-6">Mostrando <?= count($referidos) ?> de <?= $totalReferidos ?> Registros</span>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>Cédula</th>
                            <th>Nombres y Apellidos</th>
                            <th>Celular</th>
                            <th>Comuna / Sector</th>
                            <th>Votante Yopal</th>
                            <th>Personas Referidas (Total / Desglose)</th>
                            <th>Invitado Por</th>
                            <th>Líder Raíz</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($referidos)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-4 text-muted">No se encontraron registros que coincidan con los filtros seleccionados.</td>
                            </tr>
                        <?php else: ?>
                            <?php $i = $offset + 1; foreach ($referidos as $ref): ?>
                                <tr>
                                    <td><?= $i++ ?></td>
                                    <td class="fw-bold"><?= e($ref['cedula']) ?></td>
                                    <td><?= e($ref['nombres'] . ' ' . $ref['apellidos']) ?></td>
                                    <td><i class="fas fa-phone-alt me-1 text-muted small"></i><?= e($ref['celular']) ?></td>
                                    <td><span class="badge bg-light text-dark border"><i class="fas fa-map-marker-alt text-primary me-1"></i><?= e($ref['comuna']) ?></span></td>
                                    <td>
                                        <?php if ($ref['votante_yopal'] === 'Si'): ?>
                                            <span class="badge bg-success badge-votante">Sí en Yopal</span>
                                        <?php elseif ($ref['votante_yopal'] === 'Quiero inscribir'): ?>
                                            <span class="badge bg-warning text-dark badge-votante"><i class="fas fa-edit me-1"></i>Inscribirá Cédula</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary badge-votante">No Vota / Otro</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- Personas Referidas con Desglose Verde / Naranja / Gris -->
                                    <td>
                                        <?php if ($ref['sub_referidos_count'] > 0): ?>
                                            <div class="d-flex align-items-center gap-1 flex-wrap">
                                                <button type="button" class="btn btn-outline-primary btn-sm btn-ver-subreferidos fw-bold shadow-0 py-1 px-2" 
                                                        data-id="<?= $ref['id'] ?>" 
                                                        data-nombre="<?= e($ref['nombres'] . ' ' . $ref['apellidos']) ?>">
                                                    <i class="fas fa-sitemap me-1"></i> <?= $ref['sub_referidos_count'] ?> REFERIDOS
                                                </button>
                                                <div class="d-inline-flex gap-1 ms-1">
                                                    <?php if ($ref['sub_si_count'] > 0): ?>
                                                        <span class="badge bg-success py-1 px-2" title="Votan en Yopal: <?= $ref['sub_si_count'] ?>">
                                                            <i class="fas fa-check me-1"></i><?= $ref['sub_si_count'] ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($ref['sub_inscribir_count'] > 0): ?>
                                                        <span class="badge bg-warning text-dark py-1 px-2" title="Quieren inscribir cédula: <?= $ref['sub_inscribir_count'] ?>">
                                                            <i class="fas fa-edit me-1"></i><?= $ref['sub_inscribir_count'] ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($ref['sub_no_count'] > 0): ?>
                                                        <span class="badge bg-secondary py-1 px-2" title="No votantes / Otro municipio: <?= $ref['sub_no_count'] ?>">
                                                            <i class="fas fa-times me-1"></i><?= $ref['sub_no_count'] ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border">0 Referidos</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="small text-primary fw-bold"><?= e($ref['invitador_directo']) ?></td>
                                    <td class="small text-dark fw-bold"><?= e($ref['lider_raiz_nombre']) ?></td>
                                    <td class="small text-muted"><?= date('d/m/Y H:i', strtotime($ref['creado_en'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Navegación de Paginación -->
            <?php if ($totalPaginas > 1): ?>
                <nav aria-label="Navegación de registros" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?= ($paginaActual <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= $pageUrlPrefix . ($paginaActual - 1) ?>">
                                <i class="fas fa-chevron-left me-1"></i> Anterior
                            </a>
                        </li>

                        <?php
                        $startPage = max(1, $paginaActual - 3);
                        $endPage = min($totalPaginas, $paginaActual + 3);
                        if ($startPage > 1): ?>
                            <li class="page-item"><a class="page-link" href="<?= $pageUrlPrefix . 1 ?>">1</a></li>
                            <?php if ($startPage > 2): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                            <li class="page-item <?= ($p === $paginaActual) ? 'active' : '' ?>">
                                <a class="page-link" href="<?= $pageUrlPrefix . $p ?>"><?= $p ?></a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($endPage < $totalPaginas): ?>
                            <?php if ($endPage < $totalPaginas - 1): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                            <li class="page-item"><a class="page-link" href="<?= $pageUrlPrefix . $totalPaginas ?>"><?= $totalPaginas ?></a></li>
                        <?php endif; ?>

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

<!-- Modal Ver Personas Referidas por un Integrante para Administrador -->
<div class="modal fade" id="modalSubreferidos" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="fas fa-sitemap me-2 text-warning"></i>Personas Referidas por <span id="nombrePadreModal" class="fw-bold text-warning"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                
                <!-- Resumen en 3 Cuadritos al inicio del Modal -->
                <div class="d-flex align-items-center justify-content-between bg-light p-3 rounded mb-3 border">
                    <span class="fw-bold text-muted small"><i class="fas fa-chart-pie me-1"></i>Resumen de Votación:</span>
                    <div class="d-flex gap-2">
                        <span id="chipSiModal" class="badge bg-success fs-6 py-2 px-3"><i class="fas fa-check-circle me-1"></i>Votan: 0</span>
                        <span id="chipInscribirModal" class="badge bg-warning text-dark fs-6 py-2 px-3"><i class="fas fa-edit me-1"></i>Inscribirán: 0</span>
                        <span id="chipNoModal" class="badge bg-secondary fs-6 py-2 px-3"><i class="fas fa-times-circle me-1"></i>Otro: 0</span>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th>
                                <th>Cédula</th>
                                <th>Nombres y Apellidos</th>
                                <th>Celular</th>
                                <th>Comuna / Sector</th>
                                <th>Votante Yopal</th>
                                <th>Fecha Registro</th>
                            </tr>
                        </thead>
                        <tbody id="contenidoSubreferidos">
                            <!-- Se carga dinámicamente -->
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

<!-- Chart.js CDN & Bootstrap Bundle JS -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Gráfico 1: Estado de Votantes en Yopal
    const ctxVotantes = document.getElementById('chartVotantes').getContext('2d');
    new Chart(ctxVotantes, {
        type: 'doughnut',
        data: {
            labels: ['Votan en Yopal', 'Quieren Inscribir Cédula', 'No Votantes / Otro'],
            datasets: [{
                data: [<?= $totalSi ?>, <?= $totalInscribir ?>, <?= $totalNo ?>],
                backgroundColor: ['#2e7d32', '#ffa000', '#757575'],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    });

    // Modal Subreferidos para Administrador
    let subreferidosModal;
    document.addEventListener('DOMContentLoaded', function() {
        subreferidosModal = new bootstrap.Modal(document.getElementById('modalSubreferidos'));
    });

    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-ver-subreferidos');
        if (btn) {
            const id = btn.getAttribute('data-id');
            const nombre = btn.getAttribute('data-nombre');
            verSubreferidos(id, nombre);
        }
    });

    function verSubreferidos(id, nombreCompleto) {
        document.getElementById('nombrePadreModal').textContent = nombreCompleto;
        const contenedor = document.getElementById('contenidoSubreferidos');
        contenedor.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2 text-primary"></i>Cargando personas referidas...</td></tr>';
        
        document.getElementById('chipSiModal').textContent = 'Votan: ...';
        document.getElementById('chipInscribirModal').textContent = 'Inscribirán: ...';
        document.getElementById('chipNoModal').textContent = 'Otro: ...';

        subreferidosModal.show();

        fetch('api_subreferidos.php?id=' + id)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.hijos.length > 0) {
                    document.getElementById('chipSiModal').innerHTML = '<i class="fas fa-check-circle me-1"></i>Votan: ' + data.total_si;
                    document.getElementById('chipInscribirModal').innerHTML = '<i class="fas fa-edit me-1"></i>Inscribirán: ' + data.total_inscribir;
                    document.getElementById('chipNoModal').innerHTML = '<i class="fas fa-times-circle me-1"></i>Otro: ' + data.total_no;

                    let html = '';
                    data.hijos.forEach((hijo, index) => {
                        let badgeVotante = '<span class="badge bg-secondary">Otro</span>';
                        if (hijo.votante_yopal === 'Si') {
                            badgeVotante = '<span class="badge bg-success">Sí en Yopal</span>';
                        } else if (hijo.votante_yopal === 'Quiero inscribir') {
                            badgeVotante = '<span class="badge bg-warning text-dark"><i class="fas fa-edit me-1"></i>Inscribirá Cédula</span>';
                        }

                        html += `
                            <tr>
                                <td>${index + 1}</td>
                                <td class="fw-bold">${hijo.cedula}</td>
                                <td>${hijo.nombres} ${hijo.apellidos}</td>
                                <td><i class="fas fa-phone-alt me-1 text-muted small"></i>${hijo.celular}</td>
                                <td><span class="badge bg-light text-dark border">${hijo.comuna}</span></td>
                                <td>${badgeVotante}</td>
                                <td class="small text-muted">${hijo.fecha_formateada || hijo.creado_en}</td>
                            </tr>
                        `;
                    });
                    contenedor.innerHTML = html;
                } else {
                    document.getElementById('chipSiModal').textContent = 'Votan: 0';
                    document.getElementById('chipInscribirModal').textContent = 'Inscribirán: 0';
                    document.getElementById('chipNoModal').textContent = 'Otro: 0';
                    contenedor.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted">No se encontraron personas referidas por este integrante.</td></tr>';
                }
            })
            .catch(err => {
                contenedor.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-danger"><i class="fas fa-exclamation-circle me-2"></i>Error al cargar los datos.</td></tr>';
            });
    }
</script>
</body>
</html>
