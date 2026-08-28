<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/auth.php';

requireRole('AdminEncuestas');

$admin = getCurrentUser();
$pdo = getDB();

// Parámetros de filtros
$filtroPuesto = sanitizeInput($_GET['puesto'] ?? '');
$filtroMesa   = sanitizeInput($_GET['mesa'] ?? '');
$filtroCedula = sanitizeInput($_GET['cedula'] ?? '');
$filtroNombre = sanitizeInput($_GET['nombre'] ?? '');
$filtroComuna = sanitizeInput($_GET['comuna'] ?? '');
$filtroVotante = sanitizeInput($_GET['votante'] ?? '');

// Paginación
$pagina = max(1, (int)($_GET['page'] ?? 1));
$limite = 30;
$offset = ($pagina - 1) * $limite;

// Construir query con filtros dinámicos
$where = [];
$params = [];

if (!empty($filtroPuesto)) {
    $where[] = "r.puesto_votacion LIKE ?";
    $params[] = "%$filtroPuesto%";
}
if (!empty($filtroMesa)) {
    $where[] = "r.mesa_votacion = ?";
    $params[] = $filtroMesa;
}
if (!empty($filtroCedula)) {
    $where[] = "r.cedula LIKE ?";
    $params[] = "%$filtroCedula%";
}
if (!empty($filtroNombre)) {
    $where[] = "CONCAT(r.nombres, ' ', r.apellidos) LIKE ?";
    $params[] = "%$filtroNombre%";
}
if (!empty($filtroComuna)) {
    $where[] = "r.comuna LIKE ?";
    $params[] = "%$filtroComuna%";
}
if (!empty($filtroVotante)) {
    $where[] = "r.votante_yopal = ?";
    $params[] = $filtroVotante;
}

$whereSQL = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Conteo total con filtros
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM referidos r $whereSQL");
$stmtCount->execute($params);
$totalRegistros = $stmtCount->fetchColumn();
$totalPaginas = max(1, ceil($totalRegistros / $limite));

if ($pagina > $totalPaginas) $pagina = $totalPaginas;

// Consultar datos paginados
$stmtDatos = $pdo->prepare("
    SELECT r.id, r.cedula, r.nombres, r.apellidos,
           CONCAT(r.nombres, ' ', r.apellidos) AS nombre_completo,
           r.celular, r.comuna, r.votante_yopal,
           r.departamento, r.municipio,
           r.puesto_votacion, r.direccion_votacion, r.mesa_votacion, r.creado_en
    FROM referidos r
    $whereSQL
    ORDER BY r.id ASC
    LIMIT $limite OFFSET $offset
");
$stmtDatos->execute($params);
$afiliados = $stmtDatos->fetchAll();

// Obtener lista única de puestos para el filtro select
$stmtPuestos = $pdo->query("SELECT DISTINCT puesto_votacion FROM referidos WHERE puesto_votacion IS NOT NULL AND puesto_votacion != '' ORDER BY puesto_votacion ASC");
$listaPuestos = $stmtPuestos->fetchAll(PDO::FETCH_COLUMN);

// Obtener lista única de mesas
$stmtMesas = $pdo->query("SELECT DISTINCT mesa_votacion FROM referidos WHERE mesa_votacion IS NOT NULL AND mesa_votacion != '' ORDER BY CAST(mesa_votacion AS UNSIGNED) ASC");
$listaMesas = $stmtMesas->fetchAll(PDO::FETCH_COLUMN);

// Contadores rápidos
$stmtConPuesto = $pdo->query("SELECT COUNT(*) FROM referidos WHERE puesto_votacion IS NOT NULL AND puesto_votacion != ''");
$totalConPuesto = $stmtConPuesto->fetchColumn();

$stmtSinPuesto = $pdo->query("SELECT COUNT(*) FROM referidos WHERE puesto_votacion IS NULL OR puesto_votacion = ''");
$totalSinPuesto = $stmtSinPuesto->fetchColumn();

// Construir URL base para paginación conservando filtros
function buildUrl($page, $params) {
    $query = $params;
    $query['page'] = $page;
    return '?' . http_build_query($query);
}
$filtrosActuales = array_filter([
    'puesto' => $filtroPuesto, 'mesa' => $filtroMesa,
    'cedula' => $filtroCedula, 'nombre' => $filtroNombre,
    'comuna' => $filtroComuna, 'votante' => $filtroVotante,
]);

$rangoVista = 2;
$inicioPag = max(1, $pagina - $rangoVista);
$finPag = min($totalPaginas, $pagina + $rangoVista);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Afiliados / Censo Electoral - Módulo de Encuestas</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/6.4.0/mdb.min.css">
    <link rel="stylesheet" href="../assets/css/custom.css">
</head>
<body class="bg-light">

<?php $activeTab = 'afiliados'; include __DIR__ . '/navbar.php'; ?>

<div class="container-fluid px-4 py-4">

    <!-- Contadores rápidos -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card card-custom bg-white border-start border-4 border-primary p-3 shadow-sm">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase text-muted fw-bold small mb-1">Total Afiliados</h6>
                        <h3 class="fw-bold text-primary mb-0"><?= $totalRegistros ?></h3>
                        <span class="small text-muted">Con filtros aplicados</span>
                    </div>
                    <div class="text-primary p-2 rounded-circle" style="background-color: #e3f2fd;">
                        <i class="fas fa-users fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-custom bg-white border-start border-4 border-success p-3 shadow-sm">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase text-muted fw-bold small mb-1">Con Puesto y Mesa</h6>
                        <h3 class="fw-bold text-success mb-0"><?= $totalConPuesto ?></h3>
                    </div>
                    <div class="text-success p-2 rounded-circle" style="background-color: #e8f5e9;">
                        <i class="fas fa-map-marker-alt fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-custom bg-white border-start border-4 border-warning p-3 shadow-sm">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase text-muted fw-bold small mb-1">Sin Puesto / Mesa</h6>
                        <h3 class="fw-bold text-warning mb-0"><?= $totalSinPuesto ?></h3>
                    </div>
                    <div class="text-warning p-2 rounded-circle" style="background-color: #fff8e1;">
                        <i class="fas fa-exclamation-triangle fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-custom bg-white border-start border-4 border-info p-3 shadow-sm">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase text-muted fw-bold small mb-1">Puestos Únicos</h6>
                        <h3 class="fw-bold text-info mb-0"><?= count($listaPuestos) ?></h3>
                    </div>
                    <div class="text-info p-2 rounded-circle" style="background-color: #e0f7fa;">
                        <i class="fas fa-building fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card card-custom shadow-sm border mb-4">
        <div class="card-body p-3">
            <form action="afiliados.php" method="GET" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1"><i class="fas fa-id-card me-1 text-primary"></i>Cédula</label>
                    <input type="text" name="cedula" class="form-control form-control-sm" placeholder="Buscar cédula..." value="<?= e($filtroCedula) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1"><i class="fas fa-user me-1 text-primary"></i>Nombre</label>
                    <input type="text" name="nombre" class="form-control form-control-sm" placeholder="Buscar nombre..." value="<?= e($filtroNombre) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1"><i class="fas fa-building me-1 text-success"></i>Puesto Votación</label>
                    <select name="puesto" class="form-select form-select-sm">
                        <option value="">-- Todos --</option>
                        <?php foreach ($listaPuestos as $p): ?>
                            <option value="<?= e($p) ?>" <?= $filtroPuesto === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label small fw-bold mb-1"><i class="fas fa-sort-numeric-up me-1 text-info"></i>Mesa</label>
                    <select name="mesa" class="form-select form-select-sm">
                        <option value="">-- Todas --</option>
                        <?php foreach ($listaMesas as $m): ?>
                            <option value="<?= e($m) ?>" <?= $filtroMesa === $m ? 'selected' : '' ?>><?= e($m) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1"><i class="fas fa-map-marker-alt me-1 text-danger"></i>Comuna</label>
                    <input type="text" name="comuna" class="form-control form-control-sm" placeholder="Buscar comuna..." value="<?= e($filtroComuna) ?>">
                </div>
                <div class="col-md-1">
                    <label class="form-label small fw-bold mb-1"><i class="fas fa-vote-yea me-1 text-warning"></i>Vota Yopal</label>
                    <select name="votante" class="form-select form-select-sm">
                        <option value="">-- Todos --</option>
                        <option value="Si" <?= $filtroVotante === 'Si' ? 'selected' : '' ?>>Sí</option>
                        <option value="No" <?= $filtroVotante === 'No' ? 'selected' : '' ?>>No</option>
                        <option value="Quiero inscribir" <?= $filtroVotante === 'Quiero inscribir' ? 'selected' : '' ?>>Inscribirá</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-1">
                    <button type="submit" class="btn btn-primary btn-sm fw-bold flex-grow-1">
                        <i class="fas fa-search me-1"></i> Filtrar
                    </button>
                    <a href="afiliados.php" class="btn btn-outline-secondary btn-sm fw-bold">
                        <i class="fas fa-times"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabla de Afiliados -->
    <div class="card card-custom shadow-sm border mb-4">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold text-primary mb-0">
                    <i class="fas fa-address-book me-2"></i>Listado de Afiliados / Censo Electoral
                </h5>
                <span class="badge bg-dark fs-6">
                    Mostrando <?= count($afiliados) ?> de <?= $totalRegistros ?> (Pág <?= $pagina ?> de <?= $totalPaginas ?>)
                </span>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>Cédula</th>
                            <th>Nombre Completo</th>
                            <th>Celular</th>
                            <th>Comuna</th>
                            <th>Vota Yopal</th>
                            <th>Departamento</th>
                            <th>Municipio</th>
                            <th>Puesto de Votación</th>
                            <th>Dirección</th>
                            <th>Mesa</th>
                            <th>Registrado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($afiliados)): ?>
                            <tr>
                                <td colspan="12" class="text-center py-4 text-muted">
                                    <i class="fas fa-search fa-2x mb-2 d-block text-secondary"></i>
                                    No se encontraron afiliados con los filtros aplicados.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($afiliados as $i => $af): ?>
                                <tr>
                                    <td class="text-muted small"><?= $offset + $i + 1 ?></td>
                                    <td class="fw-bold"><?= e($af['cedula']) ?></td>
                                    <td><?= e($af['nombre_completo']) ?></td>
                                    <td class="small"><i class="fas fa-phone-alt me-1 text-muted"></i><?= e($af['celular']) ?></td>
                                    <td class="small"><?= e($af['comuna']) ?></td>
                                    <td>
                                        <?php if ($af['votante_yopal'] === 'Si'): ?>
                                            <span class="badge bg-success">Sí</span>
                                        <?php elseif ($af['votante_yopal'] === 'Quiero inscribir'): ?>
                                            <span class="badge bg-warning text-dark">Inscribirá</span>
                                        <?php elseif ($af['votante_yopal'] === 'No'): ?>
                                            <span class="badge bg-danger">No</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><?= e($af['votante_yopal'] ?: 'N/A') ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted"><?= e($af['departamento'] ?? '—') ?></td>
                                    <td class="small">
                                        <?php if (!empty($af['municipio'])): ?>
                                            <span class="badge bg-light text-dark border"><i class="fas fa-city me-1 text-primary"></i><?= e($af['municipio']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small">
                                        <?php if (!empty($af['puesto_votacion'])): ?>
                                            <i class="fas fa-building me-1 text-primary"></i><?= e($af['puesto_votacion']) ?>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic">Sin registrar</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted"><?= e($af['direccion_votacion'] ?? '—') ?></td>
                                    <td class="text-center">
                                        <?php if (!empty($af['mesa_votacion'])): ?>
                                            <span class="badge bg-info text-dark fw-bold"><?= e($af['mesa_votacion']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted"><?= date('d/m/Y', strtotime($af['creado_en'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Paginación -->
            <?php if ($totalPaginas > 1): ?>
                <nav aria-label="Navegación de afiliados" class="mt-3">
                    <ul class="pagination justify-content-center flex-wrap">
                        <li class="page-item <?= ($pagina <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= buildUrl($pagina - 1, $filtrosActuales) ?>">
                                <i class="fas fa-chevron-left me-1"></i> Anterior
                            </a>
                        </li>

                        <?php if ($inicioPag > 1): ?>
                            <li class="page-item"><a class="page-link" href="<?= buildUrl(1, $filtrosActuales) ?>">1</a></li>
                            <?php if ($inicioPag > 2): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($p = $inicioPag; $p <= $finPag; $p++): ?>
                            <li class="page-item <?= ($p === $pagina) ? 'active' : '' ?>">
                                <a class="page-link" href="<?= buildUrl($p, $filtrosActuales) ?>"><?= $p ?></a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($finPag < $totalPaginas): ?>
                            <?php if ($finPag < $totalPaginas - 1): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                            <li class="page-item"><a class="page-link" href="<?= buildUrl($totalPaginas, $filtrosActuales) ?>"><?= $totalPaginas ?></a></li>
                        <?php endif; ?>

                        <li class="page-item <?= ($pagina >= $totalPaginas) ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= buildUrl($pagina + 1, $filtrosActuales) ?>">
                                Siguiente <i class="fas fa-chevron-right ms-1"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>

        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
