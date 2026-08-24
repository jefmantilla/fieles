<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/auth.php';

requireRole('Lider');

$user = getCurrentUser();
$pdo = getDB();
$csrfToken = generateCSRFToken();

// Filtros
$votanteFiltro = sanitizeInput($_GET['votante_yopal'] ?? '');
$comunaFiltro = sanitizeInput($_GET['comuna'] ?? '');
$tipoRefFiltro = sanitizeInput($_GET['tipo_ref'] ?? '');
$buscarFiltro = sanitizeInput($_GET['buscar'] ?? '');

$paginaActual = max(1, (int)($_GET['page'] ?? 1));
$registrosPorPagina = 15;
$offset = ($paginaActual - 1) * $registrosPorPagina;

// Construir enlace de referido dinámico
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$referralUrl = $protocol . "://" . $host . "/Aplicaiones/fieles/registro.php?ref=" . urlencode($user['codigo_referido']);

// Estadísticas Desglosadas del Líder
// 1. Directos
$stmtDirectos = $pdo->prepare("SELECT COUNT(*) FROM referidos WHERE referido_por_tipo = 'usuario' AND referido_por_id = ?");
$stmtDirectos->execute([$user['id']]);
$totalDirectos = $stmtDirectos->fetchColumn();

// 2. Red Indirecta (Multinivel)
$stmtIndirectos = $pdo->prepare("SELECT COUNT(*) FROM referidos WHERE referido_por_tipo = 'referido' AND lider_raiz_id = ?");
$stmtIndirectos->execute([$user['id']]);
$totalIndirectos = $stmtIndirectos->fetchColumn();

// 3. Votan en Yopal
$stmtSi = $pdo->prepare("SELECT COUNT(*) FROM referidos WHERE lider_raiz_id = ? AND votante_yopal = 'Si'");
$stmtSi->execute([$user['id']]);
$totalSi = $stmtSi->fetchColumn();

// 4. Quieren Inscribir Cédula
$stmtInscribir = $pdo->prepare("SELECT COUNT(*) FROM referidos WHERE lider_raiz_id = ? AND votante_yopal = 'Quiero inscribir'");
$stmtInscribir->execute([$user['id']]);
$totalInscribir = $stmtInscribir->fetchColumn();

// 5. No Votantes / Otro municipio
$stmtNo = $pdo->prepare("SELECT COUNT(*) FROM referidos WHERE lider_raiz_id = ? AND votante_yopal = 'No'");
$stmtNo->execute([$user['id']]);
$totalNo = $stmtNo->fetchColumn();

$totalRedGeneral = $totalDirectos + $totalIndirectos;

// Cláusula WHERE dinámica para la red del líder
$whereClauses = ["r.lider_raiz_id = ?"];
$params = [$user['id']];

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

$whereSql = " WHERE " . implode(" AND ", $whereClauses);

// Conteo total de red filtrada
$stmtRedCount = $pdo->prepare("SELECT COUNT(*) FROM referidos r" . $whereSql);
$stmtRedCount->execute($params);
$totalRedFiltrada = $stmtRedCount->fetchColumn();
$totalPaginas = max(1, ceil($totalRedFiltrada / $registrosPorPagina));

// Obtener listado de miembros con desglose de referidos traídos (Verde, Naranja, Gris)
$sql = "
    SELECT r.*, 
           (SELECT COUNT(*) FROM referidos WHERE referido_por_tipo = 'referido' AND referido_por_id = r.id) as sub_referidos_count,
           (SELECT COUNT(*) FROM referidos WHERE referido_por_tipo = 'referido' AND referido_por_id = r.id AND votante_yopal = 'Si') as sub_si_count,
           (SELECT COUNT(*) FROM referidos WHERE referido_por_tipo = 'referido' AND referido_por_id = r.id AND votante_yopal = 'Quiero inscribir') as sub_inscribir_count,
           (SELECT COUNT(*) FROM referidos WHERE referido_por_tipo = 'referido' AND referido_por_id = r.id AND votante_yopal = 'No') as sub_no_count,
           CASE 
             WHEN r.referido_por_tipo = 'usuario' THEN u.nombre_completo 
             ELSE CONCAT(p.nombres, ' ', p.apellidos) 
           END as invitador_nombre
    FROM referidos r
    LEFT JOIN usuarios u ON (r.referido_por_tipo = 'usuario' AND r.referido_por_id = u.id)
    LEFT JOIN referidos p ON (r.referido_por_tipo = 'referido' AND r.referido_por_id = p.id)
    " . $whereSql . "
    ORDER BY r.creado_en DESC
    LIMIT " . $registrosPorPagina . " OFFSET " . $offset;

$stmtMiembros = $pdo->prepare($sql);
$stmtMiembros->execute($params);
$miembros = $stmtMiembros->fetchAll();

// Query string para mantener filtros en paginación
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
    <title>Panel de Líder - Proyecto Político Social</title>
    <!-- Font Awesome & MDB / Bootstrap 5 CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/6.4.0/mdb.min.css">
    <link rel="stylesheet" href="../assets/css/custom.css">
</head>
<body class="bg-light">

<!-- Navbar Líder Estándar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-0 py-2">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="dashboard.php">
            <i class="fas fa-handshake text-warning me-2"></i>Proyecto Político Social
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarLiderMenu" aria-controls="navbarLiderMenu" aria-expanded="false" aria-label="Toggle navigation">
            <i class="fas fa-bars text-white"></i>
        </button>

        <div class="collapse navbar-collapse" id="navbarLiderMenu">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link active fw-bold" href="dashboard.php"><i class="fas fa-bullhorn me-1"></i> Mi Panel de Red</a>
                </li>
            </ul>
            <div class="d-flex align-items-center">
                <span class="text-white me-3"><i class="fas fa-user-circle me-1 text-warning"></i><?= e($user['nombre_completo']) ?></span>
                <a href="../logout.php" class="btn btn-outline-light btn-sm"><i class="fas fa-sign-out-alt me-1"></i> Salir</a>
            </div>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 py-4">

    <!-- Mensajes de Notificación -->
    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'edit_exito'): ?>
        <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
            <i class="fas fa-check-circle me-2"></i>Datos del referido actualizados correctamente.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i><?= e($_GET['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Fila de QR y Tarjetas de Estadísticas Sutiles -->
    <div class="row g-3 mb-4">
        
        <!-- Columna QR -->
        <div class="col-xl-3 col-lg-4">
            <div class="card card-custom bg-white h-100 text-center p-3 shadow-sm border">
                <div class="card-body p-2">
                    <h6 class="fw-bold text-primary mb-2"><i class="fas fa-qrcode me-1"></i>Mi Código QR de Referidos</h6>
                    <div class="qr-container mb-2 p-2" id="qrcode"></div>
                    <div class="input-group input-group-sm">
                        <input type="text" id="linkReferido" class="form-control form-control-sm" value="<?= e($referralUrl) ?>" readonly>
                        <button class="btn btn-primary btn-sm" type="button" onclick="copiarEnlace()"><i class="fas fa-copy"></i></button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Columna 6 Tarjetas Sutiles -->
        <div class="col-xl-9 col-lg-8">
            <div class="row g-3">
                
                <!-- Card 1: Total Registrados en Red -->
                <div class="col-md-4 col-sm-6">
                    <div class="card card-custom bg-white border-start border-4 border-primary p-3 h-100 shadow-sm">
                        <div class="card-body p-1 d-flex align-items-center justify-content-between">
                            <div>
                                <h6 class="text-uppercase text-muted fw-bold small mb-1">Total Registrados en Red</h6>
                                <h3 class="fw-bold text-dark mb-0"><?= $totalRedGeneral ?></h3>
                            </div>
                            <div class="text-primary bg-primary-subtle p-3 rounded-circle" style="background-color: #e3f2fd;">
                                <i class="fas fa-users fa-lg"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Card 2: Directos Míos -->
                <div class="col-md-4 col-sm-6">
                    <div class="card card-custom bg-white border-start border-4 border-info p-3 h-100 shadow-sm">
                        <div class="card-body p-1 d-flex align-items-center justify-content-between">
                            <div>
                                <h6 class="text-uppercase text-muted fw-bold small mb-1">Directos Míos</h6>
                                <h3 class="fw-bold text-dark mb-0"><?= $totalDirectos ?></h3>
                            </div>
                            <div class="text-info bg-info-subtle p-3 rounded-circle" style="background-color: #e0f7fa;">
                                <i class="fas fa-user-check fa-lg"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Card 3: Red Indirecta (Multinivel) -->
                <div class="col-md-4 col-sm-6">
                    <div class="card card-custom bg-white border-start border-4 border-success p-3 h-100 shadow-sm">
                        <div class="card-body p-1 d-flex align-items-center justify-content-between">
                            <div>
                                <h6 class="text-uppercase text-muted fw-bold small mb-1">Red Indirecta (Multinivel)</h6>
                                <h3 class="fw-bold text-dark mb-0"><?= $totalIndirectos ?></h3>
                            </div>
                            <div class="text-success bg-success-subtle p-3 rounded-circle" style="background-color: #e8f5e9;">
                                <i class="fas fa-sitemap fa-lg"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Card 4: Votan en Yopal -->
                <div class="col-md-4 col-sm-6">
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

                <!-- Card 5: Quieren Inscribir Cédula -->
                <div class="col-md-4 col-sm-6">
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

                <!-- Card 6: No Votantes / Otro Municipio -->
                <div class="col-md-4 col-sm-6">
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
        </div>

    </div>

    <!-- Panel de Filtros para el Líder -->
    <div class="card card-custom mb-4 shadow-sm border">
        <div class="card-header bg-white py-3 border-0">
            <h5 class="fw-bold text-primary mb-0"><i class="fas fa-filter me-2"></i>Filtros de Búsqueda en Mi Red</h5>
        </div>
        <div class="card-body p-4 pt-0">
            <form action="dashboard.php" method="GET" class="row g-3">
                <div class="col-md-3">
                    <label for="comuna" class="form-label fw-bold small text-muted"><i class="fas fa-map-marker-alt me-1"></i>Comuna / Corregimiento:</label>
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
                <div class="col-md-3">
                    <label for="votante_yopal" class="form-label fw-bold small text-muted"><i class="fas fa-vote-yea me-1"></i>Estado de Votación:</label>
                    <select name="votante_yopal" id="votante_yopal" class="form-select">
                        <option value="">-- Todos los Estados --</option>
                        <option value="Si" <?= $votanteFiltro === 'Si' ? 'selected' : '' ?>>Sí, voto en Yopal</option>
                        <option value="Quiero inscribir" <?= $votanteFiltro === 'Quiero inscribir' ? 'selected' : '' ?>>Quiero inscribir cédula</option>
                        <option value="No" <?= $votanteFiltro === 'No' ? 'selected' : '' ?>>No, voto en otro municipio</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="tipo_ref" class="form-label fw-bold small text-muted"><i class="fas fa-sitemap me-1"></i>Origen / Nivel:</label>
                    <select name="tipo_ref" id="tipo_ref" class="form-select">
                        <option value="">-- Todos los Niveles --</option>
                        <option value="usuario" <?= $tipoRefFiltro === 'usuario' ? 'selected' : '' ?>>Directos míos</option>
                        <option value="referido" <?= $tipoRefFiltro === 'referido' ? 'selected' : '' ?>>Referidos de mi red (Multinivel)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="buscar" class="form-label fw-bold small text-muted"><i class="fas fa-search me-1"></i>Buscar Nombre, Cédula o Celular:</label>
                    <input type="text" name="buscar" id="buscar" class="form-control" placeholder="Ej. Nombre completo o Cédula..." value="<?= e($buscarFiltro) ?>">
                </div>
                <div class="col-12 d-flex justify-content-end gap-2 mt-3">
                    <a href="dashboard.php" class="btn btn-secondary btn-sm"><i class="fas fa-undo me-1"></i> Limpiar Filtros</a>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search me-1"></i> Aplicar Filtros</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabla de Estado de la Red -->
    <div class="card card-custom shadow-sm border">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold text-primary mb-0"><i class="fas fa-users me-2"></i>Integrantes de Mi Red</h5>
                <span class="badge bg-primary fs-6">Mostrando <?= count($miembros) ?> de <?= $totalRedFiltrada ?> Registrados</span>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Cédula</th>
                            <th>Nombres y Apellidos</th>
                            <th>Celular</th>
                            <th>Comuna / Sector</th>
                            <th>Votante Yopal</th>
                            <th>Personas Referidas (Total / Desglose)</th>
                            <th>Invitado Por</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($miembros)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">No se encontraron personas que coincidan con la búsqueda o filtro.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($miembros as $m): ?>
                                <tr>
                                    <td class="fw-bold"><?= e($m['cedula']) ?></td>
                                    <td><?= e($m['nombres'] . ' ' . $m['apellidos']) ?></td>
                                    <td><i class="fas fa-phone-alt me-1 text-muted small"></i><?= e($m['celular']) ?></td>
                                    <td><span class="badge bg-light text-dark border"><i class="fas fa-map-marker-alt text-primary me-1"></i><?= e($m['comuna']) ?></span></td>
                                    <td>
                                        <?php if ($m['votante_yopal'] === 'Si'): ?>
                                            <span class="badge bg-success badge-votante">Sí en Yopal</span>
                                        <?php elseif ($m['votante_yopal'] === 'Quiero inscribir'): ?>
                                            <span class="badge bg-warning text-dark badge-votante"><i class="fas fa-edit me-1"></i>Inscribirá Cédula</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary badge-votante">Otro</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- Personas Referidas con Desglose Verde / Naranja / Gris -->
                                    <td>
                                        <?php if ($m['sub_referidos_count'] > 0): ?>
                                            <div class="d-flex align-items-center gap-1 flex-wrap">
                                                <button type="button" class="btn btn-outline-primary btn-sm btn-ver-subreferidos fw-bold shadow-0 py-1 px-2" 
                                                        data-id="<?= $m['id'] ?>" 
                                                        data-nombre="<?= e($m['nombres'] . ' ' . $m['apellidos']) ?>">
                                                    <i class="fas fa-sitemap me-1"></i> <?= $m['sub_referidos_count'] ?> REFERIDOS
                                                </button>
                                                <div class="d-inline-flex gap-1 ms-1">
                                                    <?php if ($m['sub_si_count'] > 0): ?>
                                                        <span class="badge bg-success py-1 px-2" title="Votan en Yopal: <?= $m['sub_si_count'] ?>">
                                                            <i class="fas fa-check me-1"></i><?= $m['sub_si_count'] ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($m['sub_inscribir_count'] > 0): ?>
                                                        <span class="badge bg-warning text-dark py-1 px-2" title="Quieren inscribir cédula: <?= $m['sub_inscribir_count'] ?>">
                                                            <i class="fas fa-edit me-1"></i><?= $m['sub_inscribir_count'] ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($m['sub_no_count'] > 0): ?>
                                                        <span class="badge bg-secondary py-1 px-2" title="No votantes / Otro municipio: <?= $m['sub_no_count'] ?>">
                                                            <i class="fas fa-times me-1"></i><?= $m['sub_no_count'] ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border">0 Referidos</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="small text-muted"><?= e($m['invitador_nombre']) ?></td>
                                    <td class="text-center">
                                        <button class="btn btn-warning btn-sm shadow-0" onclick='abrirModalEditar(<?= json_encode($m) ?>)' title="Modificar Datos">
                                            <i class="fas fa-edit me-1"></i> Modificar
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Paginación -->
            <?php if ($totalPaginas > 1): ?>
                <nav aria-label="Navegación de mi red" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?= ($paginaActual <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= $pageUrlPrefix . ($paginaActual - 1) ?>"><i class="fas fa-chevron-left me-1"></i> Anterior</a>
                        </li>
                        <?php for ($p = 1; $p <= $totalPaginas; $p++): ?>
                            <li class="page-item <?= ($p === $paginaActual) ? 'active' : '' ?>">
                                <a class="page-link" href="<?= $pageUrlPrefix . $p ?>"><?= $p ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= ($paginaActual >= $totalPaginas) ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= $pageUrlPrefix . ($paginaActual + 1) ?>">Siguiente <i class="fas fa-chevron-right ms-1"></i></a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>

        </div>
    </div>
</div>

<!-- Modal Ver Personas Referidas por un Integrante -->
<div class="modal fade" id="modalSubreferidos" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-sitemap me-2"></i>Personas Referidas por <span id="nombrePadreModal" class="fw-bold text-warning"></span></h5>
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

<!-- Modal Modificar Referido -->
<div class="modal fade" id="modalEditarReferido" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="editar_referido.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="referido_id" id="edit_id">
                
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-user-edit me-2"></i>Modificar Datos del Referido</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="fas fa-shield-alt me-1"></i>Como Líder puede modificar Nombres, Apellidos, Teléfono y Comuna/Sector.
                    </div>

                    <div class="mb-3">
                        <label for="edit_cedula" class="form-label fw-bold text-muted">Cédula de Ciudadanía (Inmodificable)</label>
                        <input type="text" class="form-control bg-light" id="edit_cedula" readonly disabled>
                    </div>

                    <div class="mb-3">
                        <label for="edit_nombres" class="form-label fw-bold">Nombres *</label>
                        <input type="text" class="form-control" id="edit_nombres" name="nombres" required>
                    </div>

                    <div class="mb-3">
                        <label for="edit_apellidos" class="form-label fw-bold">Apellidos *</label>
                        <input type="text" class="form-control" id="edit_apellidos" name="apellidos" required>
                    </div>

                    <div class="mb-3">
                        <label for="edit_celular" class="form-label fw-bold">Teléfono / Celular (10 dígitos) *</label>
                        <input type="text" class="form-control" id="edit_celular" name="celular" required inputmode="numeric" maxlength="10" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                    </div>

                    <div class="mb-3">
                        <label for="edit_comuna" class="form-label fw-bold">Comuna o Corregimiento donde vive *</label>
                        <select class="form-select" id="edit_comuna" name="comuna" required>
                            <optgroup label="Comunas Urbanas">
                                <option value="Comuna 1">Comuna 1 - El Hobo</option>
                                <option value="Comuna 2">Comuna 2 - Viveros</option>
                                <option value="Comuna 3">Comuna 3 - Clelia Rivero</option>
                                <option value="Comuna 4">Comuna 4 - Campiña</option>
                                <option value="Comuna 5">Comuna 5 - Villa del Sol</option>
                                <option value="Comuna 6">Comuna 6 - Llano Lindo</option>
                            </optgroup>
                            <optgroup label="Corregimientos Rurales">
                                <option value="Corregimiento El Morro">Corregimiento El Morro</option>
                                <option value="Corregimiento La Chaparrera">Corregimiento La Chaparrera</option>
                                <option value="Corregimiento Tilodirán">Corregimiento Tilodirán</option>
                                <option value="Corregimiento Quebradaseca">Corregimiento Quebradaseca</option>
                                <option value="Corregimiento Punto Nuevo">Corregimiento Punto Nuevo</option>
                                <option value="Corregimiento El Taladro">Corregimiento El Taladro</option>
                                <option value="Corregimiento Tacarimena">Corregimiento Tacarimena</option>
                                <option value="Corregimiento La Niata">Corregimiento La Niata</option>
                                <option value="Corregimiento La Guafilla">Corregimiento La Guafilla</option>
                                <option value="Corregimiento Mata de Limón">Corregimiento Mata de Limón</option>
                                <option value="Corregimiento El Charte">Corregimiento El Charte</option>
                            </optgroup>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="edit_votante" class="form-label fw-bold text-muted">¿Votante / Inscripción en Yopal? (Inmodificable)</label>
                        <select class="form-select bg-light" id="edit_votante" disabled>
                            <option value="Si">Sí, voto en Yopal</option>
                            <option value="Quiero inscribir">Quiero inscribir mi cédula en Yopal</option>
                            <option value="No">No, voto en otro municipio</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- QR Code Generator & Bootstrap Bundle JS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    const qrUrl = <?= json_encode($referralUrl) ?>;
    new QRCode(document.getElementById("qrcode"), {
        text: qrUrl,
        width: 140,
        height: 140,
        colorDark : "#0d47a1",
        colorLight : "#ffffff",
        correctLevel : QRCode.CorrectLevel.H
    });

    function copiarEnlace() {
        const copyText = document.getElementById("linkReferido");
        copyText.select();
        copyText.setSelectionRange(0, 99999);
        navigator.clipboard.writeText(copyText.value);
        alert("¡Enlace de referido copiado al portapapeles!");
    }

    let editModal;
    let subreferidosModal;
    document.addEventListener('DOMContentLoaded', function() {
        editModal = new bootstrap.Modal(document.getElementById('modalEditarReferido'));
        subreferidosModal = new bootstrap.Modal(document.getElementById('modalSubreferidos'));
    });

    function abrirModalEditar(referido) {
        document.getElementById('edit_id').value = referido.id;
        document.getElementById('edit_cedula').value = referido.cedula;
        document.getElementById('edit_nombres').value = referido.nombres;
        document.getElementById('edit_apellidos').value = referido.apellidos;
        document.getElementById('edit_celular').value = referido.celular;
        document.getElementById('edit_comuna').value = referido.comuna;
        document.getElementById('edit_votante').value = referido.votante_yopal;
        editModal.show();
    }

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
