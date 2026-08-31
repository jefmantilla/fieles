<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/auth.php';

requireRole('AdminEncuestas');

$admin = getCurrentUser();
$pdo = getDB();

// Configuración de Paginación para Rendimiento (Máximo 16 por página)
$paginaActual = max(1, (int)($_GET['page'] ?? 1));
$registrosPorPagina = 16;
$offset = ($paginaActual - 1) * $registrosPorPagina;

// Contar Total Encuestadoras
$stmtTotalEnc = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE role_id = 4");
$totalEncuestadoras = $stmtTotalEnc->fetchColumn();
$totalPaginas = max(1, ceil($totalEncuestadoras / $registrosPorPagina));

if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
    $offset = ($paginaActual - 1) * $registrosPorPagina;
}

$rangoVista = 2;
$inicioPag = max(1, $paginaActual - $rangoVista);
$finPag = min($totalPaginas, $paginaActual + $rangoVista);

// Obtener Lista Completa de Rondas Creadas
$stmtHistorialRondas = $pdo->query("SELECT * FROM rondas_encuestas ORDER BY numero_ronda DESC");
$listaRondas = $stmtHistorialRondas->fetchAll();

// Determinar Ronda Activa
$rondaActiva = $listaRondas[0] ?? ['id' => 1, 'numero_ronda' => 1, 'nombre_ronda' => 'Ronda Inicial #1', 'creado_en' => date('Y-m-d H:i:s')];

// Determinar Ronda Seleccionada
$rondaSeleccionadaParam = $_GET['ronda'] ?? '';
if ($rondaSeleccionadaParam === 'todas') {
    $rondaConsulta = 'todas';
    $tituloRondaConsulta = "Consolidado de Todas las Rondas";
} elseif (!empty($rondaSeleccionadaParam)) {
    $rondaConsulta = (int)$rondaSeleccionadaParam;
    $stmtInfoR = $pdo->prepare("SELECT numero_ronda FROM rondas_encuestas WHERE id = ?");
    $stmtInfoR->execute([$rondaConsulta]);
    $numR = $stmtInfoR->fetchColumn();
    $tituloRondaConsulta = "Ronda #" . ($numR ?: $rondaConsulta);
} else {
    $stmtRondaConResp = $pdo->query("SELECT ronda_id FROM respuestas_encuestas ORDER BY creado_en DESC LIMIT 1");
    $rondaConsulta = (int)($stmtRondaConResp->fetchColumn() ?: $rondaActiva['id']);
    $stmtInfoR = $pdo->prepare("SELECT numero_ronda FROM rondas_encuestas WHERE id = ?");
    $stmtInfoR->execute([$rondaConsulta]);
    $numR = $stmtInfoR->fetchColumn();
    $tituloRondaConsulta = "Ronda #" . ($numR ?: $rondaConsulta);
}

// Métricas de Rendimiento Paginadas de Encuestadoras FILTRADAS POR RONDA
if ($rondaConsulta === 'todas') {
    $sqlRendimiento = "
        SELECT u.id, u.nombre_completo, u.username, u.telefono,
               COUNT(re.id) as total_encuestas,
               SUM(CASE WHEN DATE(re.creado_en) = CURDATE() THEN 1 ELSE 0 END) as encuestas_hoy,
               AVG(CHAR_LENGTH(re.observaciones)) as promedio_detalles,
               MAX(re.creado_en) as ultima_actividad
        FROM usuarios u
        LEFT JOIN respuestas_encuestas re ON re.encuestadora_id = u.id
        WHERE u.role_id = 4
        GROUP BY u.id, u.nombre_completo, u.username, u.telefono
        ORDER BY total_encuestas DESC, encuestas_hoy DESC
    ";
    $sqlRendimientoPaginado = $sqlRendimiento . " LIMIT " . $registrosPorPagina . " OFFSET " . $offset;
    $stmtRendimiento = $pdo->query($sqlRendimientoPaginado);

    $stmtTop10 = $pdo->query("
        SELECT u.nombre_completo, COUNT(re.id) as total
        FROM usuarios u
        LEFT JOIN respuestas_encuestas re ON re.encuestadora_id = u.id
        WHERE u.role_id = 4
        GROUP BY u.id, u.nombre_completo
        ORDER BY total DESC
        LIMIT 10
    ");
} else {
    $sqlRendimiento = "
        SELECT u.id, u.nombre_completo, u.username, u.telefono,
               COUNT(re.id) as total_encuestas,
               SUM(CASE WHEN DATE(re.creado_en) = CURDATE() THEN 1 ELSE 0 END) as encuestas_hoy,
               AVG(CHAR_LENGTH(re.observaciones)) as promedio_detalles,
               MAX(re.creado_en) as ultima_actividad
        FROM usuarios u
        LEFT JOIN respuestas_encuestas re ON re.encuestadora_id = u.id AND re.ronda_id = ?
        WHERE u.role_id = 4
        GROUP BY u.id, u.nombre_completo, u.username, u.telefono
        ORDER BY total_encuestas DESC, encuestas_hoy DESC
    ";
    $sqlRendimientoPaginado = $sqlRendimiento . " LIMIT " . $registrosPorPagina . " OFFSET " . $offset;
    $stmtRendimiento = $pdo->prepare($sqlRendimientoPaginado);
    $stmtRendimiento->execute([$rondaConsulta]);

    $stmtTop10 = $pdo->prepare("
        SELECT u.nombre_completo, COUNT(re.id) as total
        FROM usuarios u
        LEFT JOIN respuestas_encuestas re ON re.encuestadora_id = u.id AND re.ronda_id = ?
        WHERE u.role_id = 4
        GROUP BY u.id, u.nombre_completo
        ORDER BY total DESC
        LIMIT 10
    ");
    $stmtTop10->execute([$rondaConsulta]);
}

$listaRendimiento = $stmtRendimiento->fetchAll();
$top10 = $stmtTop10->fetchAll();

$chartNombres = [];
$chartTotales = [];
foreach ($top10 as $t) {
    $chartNombres[] = $t['nombre_completo'];
    $chartTotales[] = (int)$t['total'];
}

$urlPagePrefix = '?ronda=' . urlencode($rondaConsulta) . '&page=';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rendimiento Comparativo de Encuestadoras - Módulo de Encuestas</title>
    <!-- Font Awesome & MDB / Bootstrap 5 CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/6.4.0/mdb.min.css">
    <link rel="stylesheet" href="../assets/css/custom.css">
    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-light">

<!-- Navbar Compartido AdminEncuestas -->
<?php $activeTab = 'rendimiento'; include __DIR__ . '/navbar.php'; ?>

<div class="container-fluid px-4 py-4">

    <!-- BARRA Y SELECTOR DE RONDAS DE DESEMPEÑO -->
    <div class="card card-custom mb-4 shadow-sm border">
        <div class="card-body p-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h5 class="fw-bold text-primary mb-0"><i class="fas fa-chart-line me-2"></i>Rendimiento Comparativo por Ronda</h5>
                <span class="badge bg-primary text-white mt-1">Consultando: <?= e($tituloRondaConsulta) ?></span>
            </div>

            <!-- Selector de Ronda -->
            <form action="rendimiento.php" method="GET" class="d-inline-block">
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
    </div>

    <!-- Sección de Gráfica de Rendimiento Comparativo Top Encuestadoras -->
    <div class="card card-custom mb-4 shadow-sm border">
        <div class="card-body p-4">
            <h5 class="fw-bold text-primary mb-3"><i class="fas fa-chart-bar me-2"></i>Comparativa de Productividad en <?= e($tituloRondaConsulta) ?> (Top 10)</h5>
            <div style="height: 320px; width: 100%;">
                <canvas id="chartRendimiento"></canvas>
            </div>
        </div>
    </div>

    <!-- Tabla de Métricas de Eficiencia Paginada (Máximo 16) -->
    <div class="card card-custom shadow-sm border">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold text-primary mb-0"><i class="fas fa-award me-2"></i>Ranking de Eficiencia en <?= e($tituloRondaConsulta) ?></h5>
                <span class="badge bg-dark fs-6">Mostrando <?= count($listaRendimiento) ?> de <?= $totalEncuestadoras ?> Encuestadoras</span>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Posición</th>
                            <th>Nombre Completo</th>
                            <th>Usuario</th>
                            <th>Teléfono</th>
                            <th>Encuestas Hoy</th>
                            <th>Encuestas en Ronda</th>
                            <th>Detalle de Observaciones</th>
                            <th>Última Actividad</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($listaRendimiento)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">No hay datos de rendimiento disponibles en <?= e($tituloRondaConsulta) ?>.</td>
                            </tr>
                        <?php else: ?>
                            <?php $pos = $offset + 1; foreach ($listaRendimiento as $r): ?>
                                <tr>
                                    <td>
                                        <?php if ($pos === 1): ?>
                                            <span class="badge bg-warning text-dark fs-6"><i class="fas fa-trophy me-1"></i>#1 Top</span>
                                        <?php elseif ($pos === 2): ?>
                                            <span class="badge bg-secondary fs-6">#2</span>
                                        <?php elseif ($pos === 3): ?>
                                            <span class="badge bg-danger fs-6">#3</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark border">#<?= $pos ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-bold"><?= e($r['nombre_completo']) ?></td>
                                    <td><span class="badge bg-primary">@<?= e($r['username']) ?></span></td>
                                    <td><i class="fas fa-phone-alt me-1 text-muted small"></i><?= e($r['telefono']) ?></td>
                                    <td><span class="badge bg-success fs-6">+<?= $r['encuestas_hoy'] ?> hoy</span></td>
                                    <td><span class="badge bg-dark fs-6"><?= $r['total_encuestas'] ?> realizadas</span></td>
                                    <td class="small">
                                        <?php if ($r['promedio_detalles'] > 20): ?>
                                            <span class="badge bg-success">Alto Detalle (<?= round($r['promedio_detalles']) ?> chars)</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark border">Normal</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted">
                                        <?= $r['ultima_actividad'] ? date('d/m/Y H:i', strtotime($r['ultima_actividad'])) : 'Sin actividad' ?>
                                    </td>
                                </tr>
                            <?php $pos++; endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Navegación de Paginación (Máx 16) -->
            <?php if ($totalPaginas > 1): ?>
                <nav aria-label="Navegación de rendimiento" class="mt-4">
                    <ul class="pagination justify-content-center flex-wrap">
                        <li class="page-item <?= ($paginaActual <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= $urlPagePrefix . ($paginaActual - 1) ?>">
                                <i class="fas fa-chevron-left me-1"></i> Anterior
                            </a>
                        </li>

                        <?php if ($inicioPag > 1): ?>
                            <li class="page-item"><a class="page-link" href="<?= $urlPagePrefix ?>1">1</a></li>
                            <?php if ($inicioPag > 2): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($p = $inicioPag; $p <= $finPag; $p++): ?>
                            <li class="page-item <?= ($p === $paginaActual) ? 'active' : '' ?>">
                                <a class="page-link" href="<?= $urlPagePrefix . $p ?>"><?= $p ?></a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($finPag < $totalPaginas): ?>
                            <?php if ($finPag < $totalPaginas - 1): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                            <li class="page-item"><a class="page-link" href="<?= $urlPagePrefix . $totalPaginas ?>"><?= $totalPaginas ?></a></li>
                        <?php endif; ?>

                        <li class="page-item <?= ($paginaActual >= $totalPaginas) ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= $urlPagePrefix . ($paginaActual + 1) ?>">
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
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('chartRendimiento').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($chartNombres) ?>,
                datasets: [{
                    label: 'Total Encuestas en <?= e($tituloRondaConsulta) ?>',
                    data: <?= json_encode($chartTotales) ?>,
                    backgroundColor: 'rgba(13, 110, 253, 0.7)',
                    borderColor: 'rgba(13, 110, 253, 1)',
                    borderWidth: 1,
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });
    });
</script>
</body>
</html>
