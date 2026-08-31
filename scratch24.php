<?php
$file = 'c:\xampp\htdocs\Aplicaiones\fieles\admin\dashboard.php';

$newContent = <<<'PHP'
<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/auth.php';

requireRole('Admin');

$admin = getCurrentUser();
$pdo = getDB();

// 1. Progreso de Referidos (Total)
$stmtRef = $pdo->query("SELECT COUNT(*) FROM referidos");
$totalReferidos = $stmtRef->fetchColumn();

// 2. Progreso de Encuestas (Total Realizadas)
$stmtEnc = $pdo->query("SELECT COUNT(*) FROM respuestas_encuestas");
$totalEncuestas = $stmtEnc->fetchColumn();

// 3. Progreso de Referidos por Comuna
$stmtComunas = $pdo->query("SELECT comuna, COUNT(*) as total FROM referidos GROUP BY comuna ORDER BY total DESC");
$referidosPorComuna = $stmtComunas->fetchAll();

// 4. Resultados de Encuestas vs Contrincantes (Agrupado por candidato)
$stmtCandidatos = $pdo->query("
    SELECT candidato_elegido, COUNT(*) as votos 
    FROM respuestas_encuestas 
    WHERE candidato_elegido NOT LIKE '%No Contestó%' 
      AND candidato_elegido NOT LIKE '%Cédula Falsa%'
      AND candidato_elegido NOT LIKE '%Equivocado%'
      AND candidato_elegido NOT LIKE '%Rechazó%'
      AND candidato_elegido NOT LIKE '%WhatsApp%'
    GROUP BY candidato_elegido 
    ORDER BY votos DESC
");
$resultadosEncuestas = $stmtCandidatos->fetchAll();

$totalVotosValidos = 0;
foreach($resultadosEncuestas as $r) {
    $totalVotosValidos += $r['votos'];
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel del Jefe Supremo - Dashboard General</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/6.4.0/mdb.min.css">
    <link rel="stylesheet" href="../assets/css/custom.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-0 py-2">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="dashboard.php">
            <i class="fas fa-chess-king text-warning me-2"></i>Panel del Jefe
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarAdminMenu" aria-controls="navbarAdminMenu">
            <i class="fas fa-bars text-white"></i>
        </button>
        
        <div class="collapse navbar-collapse" id="navbarAdminMenu">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link active fw-bold" href="dashboard.php"><i class="fas fa-chart-pie me-1"></i> Resumen de Progreso</a>
                </li>
            </ul>
            <div class="d-flex align-items-center">
                <span class="text-white me-3"><i class="fas fa-user-shield me-1 text-warning"></i><?= e($admin['nombre_completo']) ?></span>
                <a href="../logout.php" class="btn btn-outline-light btn-sm"><i class="fas fa-sign-out-alt me-1"></i> Salir</a>
            </div>
        </div>
    </div>
</nav>

<div class="container py-4">
    <div class="row mb-4">
        <div class="col-12 text-center">
            <h3 class="fw-bold text-dark"><i class="fas fa-chart-line text-primary me-2"></i>Resumen General de Campaña</h3>
            <p class="text-muted">Monitoreo en tiempo real de Referidos y Encuestas</p>
        </div>
    </div>

    <!-- Top Metrics -->
    <div class="row g-4 mb-5">
        <div class="col-md-6">
            <div class="card shadow-sm border-start border-4 border-primary h-100">
                <div class="card-body p-4 d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase text-muted fw-bold mb-1">Total Referidos</h6>
                        <h2 class="fw-bold text-primary mb-0"><?= number_format($totalReferidos) ?></h2>
                    </div>
                    <div class="p-3 bg-primary bg-opacity-10 rounded-circle">
                        <i class="fas fa-users fa-2x text-primary"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow-sm border-start border-4 border-success h-100">
                <div class="card-body p-4 d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase text-muted fw-bold mb-1">Encuestas Realizadas</h6>
                        <h2 class="fw-bold text-success mb-0"><?= number_format($totalEncuestas) ?></h2>
                    </div>
                    <div class="p-3 bg-success bg-opacity-10 rounded-circle">
                        <i class="fas fa-phone-volume fa-2x text-success"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Resultados Encuestas -->
        <div class="col-md-7">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-poll text-warning me-2"></i>Resultados de Encuestas vs Contrincantes</h5>
                    <span class="small text-muted">Votos válidos registrados en encuestas</span>
                </div>
                <div class="card-body p-4">
                    <?php if(empty($resultadosEncuestas)): ?>
                        <p class="text-muted text-center py-4">Aún no hay suficientes datos de encuestas con candidatos seleccionados.</p>
                    <?php else: ?>
                        <?php foreach($resultadosEncuestas as $idx => $res): 
                            $porcentaje = $totalVotosValidos > 0 ? round(($res['votos'] / $totalVotosValidos) * 100, 1) : 0;
                            $barClass = $idx === 0 ? 'bg-success' : ($idx === 1 ? 'bg-info' : 'bg-secondary');
                        ?>
                        <div class="mb-4">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="fw-bold text-dark"><?= e($res['candidato_elegido']) ?></span>
                                <span class="fw-bold text-muted"><?= $res['votos'] ?> votos (<?= $porcentaje ?>%)</span>
                            </div>
                            <div class="progress" style="height: 12px; border-radius: 6px;">
                                <div class="progress-bar <?= $barClass ?>" role="progressbar" style="width: <?= $porcentaje ?>%" aria-valuenow="<?= $porcentaje ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Progreso de Referidos por Comuna -->
        <div class="col-md-5">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-map-marker-alt text-danger me-2"></i>Referidos por Sector</h5>
                </div>
                <div class="card-body p-4">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover table-borderless align-middle">
                            <thead class="border-bottom">
                                <tr>
                                    <th class="text-muted fw-bold">Sector / Comuna</th>
                                    <th class="text-muted fw-bold text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($referidosPorComuna as $com): ?>
                                <tr>
                                    <td class="fw-bold text-dark"><i class="fas fa-map-pin me-2 text-danger opacity-50"></i><?= e($com['comuna']) ?></td>
                                    <td class="text-end fw-bold text-primary"><?= number_format($com['total']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
PHP;

file_put_contents($file, $newContent);
echo "ok";

