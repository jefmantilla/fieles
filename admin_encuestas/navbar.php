<?php
// Shared Navbar for AdminEncuestas Module
// Usage: $activeTab = 'dashboard' | 'fidelidad' | 'rendimiento' | 'configuracion';
if (!isset($activeTab)) {
    $activeTab = 'dashboard';
}
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm py-2 border-bottom border-secondary">
    <div class="container-fluid px-3 px-lg-4">
        <a class="navbar-brand fw-bold fs-6 py-1 me-3 d-flex align-items-center" href="dashboard.php">
            <i class="fas fa-poll text-warning me-2 fs-5"></i>
            <span>Módulo de Encuestas</span>
        </a>
        
        <button class="navbar-toggler py-1 px-2 border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navEncuestadoras" aria-controls="navEncuestadoras" aria-expanded="false" aria-label="Toggle navigation">
            <i class="fas fa-bars text-white"></i>
        </button>

        <div class="collapse navbar-collapse" id="navEncuestadoras">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 gap-1 py-1">
                <li class="nav-item">
                    <a class="nav-link nav-link-pill <?= $activeTab === 'dashboard' ? 'active' : '' ?>" href="dashboard.php">
                        <i class="fas fa-chart-pie me-1 text-warning"></i> Dashboard General
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link nav-link-pill <?= $activeTab === 'fidelidad' ? 'active' : '' ?>" href="fidelidad.php">
                        <i class="fas fa-user-check me-1 text-success"></i> Fidelidad de Voto
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link nav-link-pill <?= $activeTab === 'rendimiento' ? 'active' : '' ?>" href="rendimiento.php">
                        <i class="fas fa-chart-line me-1 text-info"></i> Rendimiento Comparativo
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link nav-link-pill <?= $activeTab === 'configuracion' ? 'active' : '' ?>" href="configuracion.php">
                        <i class="fas fa-cog me-1 text-light"></i> Configuración de Rondas
                    </a>
                </li>
            </ul>
            
            <div class="d-flex align-items-center gap-2 py-1" style="font-size: 0.85rem;">
                <span class="text-white-50"><i class="fas fa-user-shield text-warning me-1"></i><strong class="text-white"><?= e($admin['nombre_completo']) ?></strong></span>
                <a href="../logout.php" class="btn btn-outline-light btn-sm py-1 px-2 border-0 rounded-pill ms-2" style="font-size: 0.8rem;"><i class="fas fa-sign-out-alt me-1"></i> Salir</a>
            </div>
        </div>
    </div>
</nav>
