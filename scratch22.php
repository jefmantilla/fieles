<?php
$file = 'c:\xampp\htdocs\Aplicaiones\fieles\admin\usuarios.php';
$content = file_get_contents($file);

$navbarOld = <<<HTML
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link text-white-50 fw-bold" href="dashboard.php"><i class="fas fa-chart-pie me-1"></i> Dashboard General</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active fw-bold" href="usuarioes.php"><i class="fas fa-users-cog me-1 text-warning"></i> Gestionar Usuarioes</a>
                </li>
            </ul>
HTML;

$navbarNew = <<<HTML
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link text-white-50 fw-bold" href="dashboard.php"><i class="fas fa-chart-pie me-1"></i> Dashboard General</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-white-50 fw-bold" href="lideres.php"><i class="fas fa-users-cog me-1 text-warning"></i> Gestionar Líderes</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active fw-bold" href="usuarios.php"><i class="fas fa-user-shield me-1 text-danger"></i> Gestionar Usuarios</a>
                </li>
            </ul>
HTML;

$content = str_replace($navbarOld, $navbarNew, $content);

file_put_contents($file, $content);
echo "ok";
