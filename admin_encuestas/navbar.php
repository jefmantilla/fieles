<?php
// Shared Navbar for AdminEncuestas Module
// Usage: $activeTab = 'dashboard' | 'fidelidad' | 'rendimiento' | 'configuracion';
if (!isset($activeTab)) {
    $activeTab = 'dashboard';
}
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-0 py-2">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="dashboard.php">
            <i class="fas fa-poll text-warning me-2"></i>Módulo de Encuestas
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navEncuestadoras" aria-controls="navEncuestadoras" aria-expanded="false" aria-label="Toggle navigation">
            <i class="fas fa-bars text-white"></i>
        </button>

        <div class="collapse navbar-collapse" id="navEncuestadoras">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab === 'dashboard' ? 'active text-warning' : 'text-white' ?> fw-bold" href="dashboard.php">
                        <i class="fas fa-chart-pie me-1"></i> Dashboard General
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab === 'fidelidad' ? 'active text-warning' : 'text-white' ?> fw-bold" href="fidelidad.php">
                        <i class="fas fa-user-check me-1 text-success"></i> Fidelidad de Voto
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab === 'rendimiento' ? 'active text-warning' : 'text-white' ?> fw-bold" href="rendimiento.php">
                        <i class="fas fa-chart-line me-1 text-warning"></i> Rendimiento Comparativo
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab === 'configuracion' ? 'active text-warning' : 'text-white' ?> fw-bold" href="configuracion.php">
                        <i class="fas fa-cog me-1 text-info"></i> Configuración de Rondas
                    </a>
                </li>
            </ul>
            <div class="d-flex align-items-center">
                <span class="text-white me-3"><i class="fas fa-user-shield text-warning me-1"></i><?= e($admin['nombre_completo']) ?></span>
                <a href="../logout.php" class="btn btn-outline-light btn-sm"><i class="fas fa-sign-out-alt me-1"></i> Salir</a>
            </div>
        </div>
    </div>
</nav>
