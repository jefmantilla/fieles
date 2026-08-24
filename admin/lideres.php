<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/auth.php';

requireRole('Admin');

$admin = getCurrentUser();
$pdo = getDB();
$csrfToken = generateCSRFToken();
$error = '';
$msg = '';

// 1. Procesar Creación Directa de Líder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'crear_lider') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = "Token de seguridad no válido.";
    } else {
        $nombre = sanitizeInput($_POST['nombre_completo'] ?? '');
        $cedula = sanitizeInput($_POST['cedula'] ?? '');
        $telefono = sanitizeInput($_POST['telefono'] ?? '');
        $username = sanitizeInput($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($nombre) || empty($cedula) || empty($telefono) || empty($username) || empty($password)) {
            $error = "Todos los campos son obligatorios.";
        } else {
            // Verificar unicidad de username y cedula
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE username = ? OR cedula = ?");
            $stmtCheck->execute([$username, $cedula]);
            if ($stmtCheck->fetchColumn() > 0) {
                $error = "El Usuario o Cédula ya se encuentra registrado en el sistema.";
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $codigoRef = generateUniqueCode('LID');

                $stmtIns = $pdo->prepare("INSERT INTO usuarios (nombre_completo, cedula, telefono, username, password, role_id, codigo_referido) VALUES (?, ?, ?, ?, ?, 2, ?)");
                if ($stmtIns->execute([$nombre, $cedula, $telefono, $username, $hash, $codigoRef])) {
                    $msg = "¡Líder creado exitosamente! Código de referido: " . $codigoRef;
                } else {
                    $error = "Ocurrió un error al registrar el nuevo líder.";
                }
            }
        }
    }
}

// 2. Procesar Conversión / Promoción de Referido a Líder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'promover_referido') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = "Token de seguridad no válido.";
    } else {
        $referidoId = (int)($_POST['referido_id'] ?? 0);
        $username = sanitizeInput($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($referidoId) || empty($username) || empty($password)) {
            $error = "Debe seleccionar un referido e ingresar un usuario y contraseña válidos.";
        } else {
            $stmtRef = $pdo->prepare("SELECT * FROM referidos WHERE id = ?");
            $stmtRef->execute([$referidoId]);
            $ref = $stmtRef->fetch();

            if (!$ref) {
                $error = "El referido seleccionado no existe.";
            } else if ($ref['usuario_id'] !== null) {
                $error = "Este referido ya ha sido promovido a Líder anteriormente.";
            } else {
                $stmtCheckUser = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE username = ? OR cedula = ?");
                $stmtCheckUser->execute([$username, $ref['cedula']]);
                if ($stmtCheckUser->fetchColumn() > 0) {
                    $error = "El nombre de usuario o la Cédula ya existe en la lista de usuarios del sistema.";
                } else {
                    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                    $nombreCompleto = $ref['nombres'] . ' ' . $ref['apellidos'];
                    $codigoRef = $ref['codigo_referido'];

                    $pdo->beginTransaction();
                    try {
                        $stmtInsUser = $pdo->prepare("INSERT INTO usuarios (nombre_completo, cedula, telefono, username, password, role_id, codigo_referido) VALUES (?, ?, ?, ?, ?, 2, ?)");
                        $stmtInsUser->execute([$nombreCompleto, $ref['cedula'], $ref['celular'], $username, $hash, $codigoRef]);
                        $newUserId = $pdo->lastInsertId();

                        $stmtUpdRef = $pdo->prepare("UPDATE referidos SET usuario_id = ? WHERE id = ?");
                        $stmtUpdRef->execute([$newUserId, $referidoId]);

                        $pdo->commit();
                        $msg = "¡El referido " . e($nombreCompleto) . " ha sido convertido en Líder exitosamente!";
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $error = "Error al promover referido a líder: " . $e->getMessage();
                    }
                }
            }
        }
    }
}

// 3. Procesar Edición Completa de Datos y Credenciales de Líder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'editar_lider') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = "Token de seguridad no válido.";
    } else {
        $liderId = (int)($_POST['lider_id'] ?? 0);
        $nombre = sanitizeInput($_POST['nombre_completo'] ?? '');
        $cedula = sanitizeInput($_POST['cedula'] ?? '');
        $telefono = sanitizeInput($_POST['telefono'] ?? '');
        $username = sanitizeInput($_POST['username'] ?? '');
        $nuevaPassword = $_POST['nueva_password'] ?? '';

        if (empty($liderId) || empty($nombre) || empty($cedula) || empty($telefono) || empty($username)) {
            $error = "Nombre, cédula, teléfono y usuario son campos obligatorios.";
        } else {
            // Verificar unicidad de username y cedula para otros usuarios
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE (username = ? OR cedula = ?) AND id != ?");
            $stmtCheck->execute([$username, $cedula, $liderId]);
            if ($stmtCheck->fetchColumn() > 0) {
                $error = "El Usuario o Cédula ingresado ya pertenece a otra persona en el sistema.";
            } else {
                if (!empty($nuevaPassword)) {
                    $hash = password_hash($nuevaPassword, PASSWORD_BCRYPT, ['cost' => 12]);
                    $stmtUpd = $pdo->prepare("UPDATE usuarios SET nombre_completo = ?, cedula = ?, telefono = ?, username = ?, password = ? WHERE id = ? AND role_id = 2");
                    $stmtUpd->execute([$nombre, $cedula, $telefono, $username, $hash, $liderId]);
                } else {
                    $stmtUpd = $pdo->prepare("UPDATE usuarios SET nombre_completo = ?, cedula = ?, telefono = ?, username = ? WHERE id = ? AND role_id = 2");
                    $stmtUpd->execute([$nombre, $cedula, $telefono, $username, $liderId]);
                }

                // Sincronizar en referidos si el líder provino de una promoción
                $stmtUpdRef = $pdo->prepare("UPDATE referidos SET nombres = ?, apellidos = '', celular = ? WHERE usuario_id = ?");
                $stmtUpdRef->execute([$nombre, $telefono, $liderId]);

                $msg = "¡Datos y credenciales del líder actualizados correctamente!";
            }
        }
    }
}

// Configuración de Filtro y Paginación para la Tabla de Líderes
$buscarLider = sanitizeInput($_GET['buscar_lider'] ?? '');
$paginaActual = max(1, (int)($_GET['page'] ?? 1));
$registrosPorPagina = 10;
$offset = ($paginaActual - 1) * $registrosPorPagina;

$whereClauses = ["u.role_id = 2"];
$params = [];

if (!empty($buscarLider)) {
    $whereClauses[] = "(u.nombre_completo LIKE ? OR u.cedula LIKE ? OR u.username LIKE ? OR u.telefono LIKE ? OR u.codigo_referido LIKE ?)";
    $term = "%" . $buscarLider . "%";
    $params = [$term, $term, $term, $term, $term];
}

$whereSql = " WHERE " . implode(" AND ", $whereClauses);

// Contar total de líderes filtrados
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM usuarios u" . $whereSql);
$stmtCount->execute($params);
$totalLideres = $stmtCount->fetchColumn();
$totalPaginas = max(1, ceil($totalLideres / $registrosPorPagina));

// Obtener Lista Paginada de Líderes con Desglose de Votantes por Color (Verde, Naranja, Gris)
$sqlLideres = "
    SELECT u.*, 
           (SELECT COUNT(*) FROM referidos WHERE lider_raiz_id = u.id) as total_red,
           (SELECT COUNT(*) FROM referidos WHERE lider_raiz_id = u.id AND votante_yopal = 'Si') as total_si,
           (SELECT COUNT(*) FROM referidos WHERE lider_raiz_id = u.id AND votante_yopal = 'Quiero inscribir') as total_inscribir,
           (SELECT COUNT(*) FROM referidos WHERE lider_raiz_id = u.id AND votante_yopal = 'No') as total_no
    FROM usuarios u 
    " . $whereSql . "
    ORDER BY u.creado_en DESC
    LIMIT " . $registrosPorPagina . " OFFSET " . $offset;

$stmtLideres = $pdo->prepare($sqlLideres);
$stmtLideres->execute($params);
$lideres = $stmtLideres->fetchAll();

// Obtener Lista de Referidos Elegibles para Promoción
$stmtElegibles = $pdo->query("
    SELECT r.*, 
           CONCAT(r.nombres, ' ', r.apellidos) as nombre_completo
    FROM referidos r
    WHERE r.usuario_id IS NULL
    ORDER BY r.nombres ASC
");
$elegibles = $stmtElegibles->fetchAll();

// Paginación URL Prefix
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
    <title>Gestión de Líderes - Administrador</title>
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
                    <a class="nav-link text-white-50 fw-bold" href="dashboard.php"><i class="fas fa-chart-pie me-1"></i> Dashboard General</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active fw-bold" href="lideres.php"><i class="fas fa-users-cog me-1 text-warning"></i> Gestionar Líderes</a>
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

    <div class="row g-4 mb-4">
        <!-- Formulario 1: Crear Líder Nuevo -->
        <div class="col-lg-6">
            <div class="card card-custom h-100 shadow-sm border">
                <div class="card-body p-4">
                    <h5 class="fw-bold text-primary mb-3"><i class="fas fa-user-plus me-2"></i>Crear Nuevo Líder</h5>
                    <form action="lideres.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="accion" value="crear_lider">

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Nombre Completo *</label>
                            <input type="text" name="nombre_completo" class="form-control" required placeholder="Ej. Carlos Alberto Mendoza">
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label small fw-bold">Cédula *</label>
                                <input type="text" name="cedula" class="form-control" required placeholder="Ej. 1118123456" inputmode="numeric" maxlength="12" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label small fw-bold">Teléfono / Celular *</label>
                                <input type="text" name="telefono" class="form-control" required placeholder="Ej. 3109876543" inputmode="numeric" maxlength="10" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label small fw-bold">Usuario de Ingreso *</label>
                                <input type="text" name="username" class="form-control" required placeholder="Ej. cmendoza">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label small fw-bold">Contraseña *</label>
                                <input type="password" name="password" class="form-control" required placeholder="******">
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary btn-block btn-primary-custom">
                            <i class="fas fa-save me-1"></i> Registrar y Crear Líder
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Formulario 2: Promover Usuario de la Red a Líder -->
        <div class="col-lg-6">
            <div class="card card-custom h-100 border-warning shadow-sm">
                <div class="card-body p-4">
                    <h5 class="fw-bold text-warning mb-2"><i class="fas fa-user-shield me-2"></i>Convertir Referido en Líder (Solicitud Verbal)</h5>
                    <p class="small text-muted mb-3">Asigne usuario y contraseña a una persona de la red para habilitarle su propio código QR:</p>
                    
                    <form action="lideres.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="accion" value="promover_referido">

                        <!-- Buscador en tiempo real para el desplegable -->
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-dark"><i class="fas fa-search me-1 text-primary"></i>1. Buscar Persona por Cédula o Nombre:</label>
                            <input type="text" id="filtroReferidoElegible" class="form-control form-control-sm mb-2" placeholder="Escriba cédula o nombre aquí para buscar...">
                            
                            <select name="referido_id" id="selectReferidoElegible" class="form-select" required size="5">
                                <option value="" disabled>-- Seleccione haciendo clic en una persona de la lista (<?= count($elegibles) ?> disponibles) --</option>
                                <?php foreach ($elegibles as $el): ?>
                                    <option value="<?= $el['id'] ?>" data-search="<?= strtolower(e($el['nombre_completo'] . ' ' . $el['cedula'] . ' ' . $el['celular'])) ?>">
                                        <?= e($el['nombre_completo']) ?> - CC: <?= e($el['cedula']) ?> (Sector: <?= e($el['comuna']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Caja de Confirmación Visual Destacada -->
                        <div id="cajaPersonaSeleccionada" class="alert alert-success border-success d-none mb-3 shadow-sm py-2">
                            <div class="fw-bold text-success small text-uppercase">
                                <i class="fas fa-check-circle me-1"></i> Persona Seleccionada para ser Líder:
                            </div>
                            <div id="nombrePersonaSeleccionada" class="fs-6 fw-bold text-dark mt-1"></div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label small fw-bold">2. Asignar Usuario *</label>
                                <input type="text" name="username" class="form-control" required placeholder="Ej. nuevo_lider">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label small fw-bold">3. Asignar Contraseña *</label>
                                <input type="password" name="password" class="form-control" required placeholder="******">
                            </div>
                        </div>

                        <button type="submit" class="btn btn-warning btn-block fw-bold text-dark shadow-0">
                            <i class="fas fa-star me-1"></i> Convertir en Líder y Asignar Credenciales
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabla de Líderes Registrados -->
    <div class="card card-custom shadow-sm border">
        <div class="card-body p-4">
            
            <div class="row align-items-center mb-3">
                <div class="col-md-6">
                    <h5 class="fw-bold text-primary mb-0"><i class="fas fa-users-cog me-2"></i>Líderes Activos en el Sistema</h5>
                    <span class="badge bg-dark mt-1">Mostrando <?= count($lideres) ?> de <?= $totalLideres ?> Líderes</span>
                </div>
                <!-- Buscador para la Tabla de Líderes -->
                <div class="col-md-6 mt-2 mt-md-0">
                    <form action="lideres.php" method="GET" class="d-flex gap-2">
                        <input type="text" name="buscar_lider" class="form-control" placeholder="Buscar por nombre, cédula, usuario o QR..." value="<?= e($buscarLider) ?>">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
                        <?php if (!empty($buscarLider)): ?>
                            <a href="lideres.php" class="btn btn-secondary btn-sm"><i class="fas fa-undo"></i></a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Nombre Completo</th>
                            <th>Cédula</th>
                            <th>Usuario</th>
                            <th>Teléfono</th>
                            <th>Código QR</th>
                            <th>Total Red (Desglose Verde/Naranja/Gris)</th>
                            <th>Fecha Creación</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($lideres)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">No se encontraron líderes que coincidan con la búsqueda.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($lideres as $lid): ?>
                                <tr>
                                    <td class="fw-bold"><?= e($lid['nombre_completo']) ?></td>
                                    <td><?= e($lid['cedula']) ?></td>
                                    <td><span class="badge bg-primary">@<?= e($lid['username']) ?></span></td>
                                    <td><i class="fas fa-phone-alt me-1 text-muted small"></i><?= e($lid['telefono']) ?></td>
                                    <td><code class="fw-bold text-danger"><?= e($lid['codigo_referido']) ?></code></td>
                                    
                                    <!-- Total Red con Mini-cuadritos de Desglose Verde, Naranja y Gris -->
                                    <td>
                                        <div class="d-flex align-items-center gap-1 flex-wrap">
                                            <a href="dashboard.php?lider_id=<?= $lid['id'] ?>" class="btn btn-outline-primary btn-sm fw-bold shadow-0 py-1 px-2" title="Filtrar dashboard por este líder">
                                                <i class="fas fa-sitemap me-1"></i> <?= $lid['total_red'] ?> pers.
                                            </a>
                                            <div class="d-inline-flex gap-1 ms-1">
                                                <?php if ($lid['total_si'] > 0): ?>
                                                    <span class="badge bg-success py-1 px-2" title="Votan en Yopal: <?= $lid['total_si'] ?>">
                                                        <i class="fas fa-check me-1"></i><?= $lid['total_si'] ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($lid['total_inscribir'] > 0): ?>
                                                    <span class="badge bg-warning text-dark py-1 px-2" title="Quieren inscribir cédula: <?= $lid['total_inscribir'] ?>">
                                                        <i class="fas fa-edit me-1"></i><?= $lid['total_inscribir'] ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($lid['total_no'] > 0): ?>
                                                    <span class="badge bg-secondary py-1 px-2" title="No votantes / Otro municipio: <?= $lid['total_no'] ?>">
                                                        <i class="fas fa-times me-1"></i><?= $lid['total_no'] ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="small text-muted"><?= date('d/m/Y', strtotime($lid['creado_en'])) ?></td>
                                    <td class="text-center">
                                        <button class="btn btn-outline-warning btn-sm text-dark fw-bold shadow-0" onclick='abrirModalEditarCredenciales(<?= json_encode($lid) ?>)' title="Modificar datos, usuario o contraseña del líder">
                                            <i class="fas fa-edit me-1 text-primary"></i> Editar Líder
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Navegación de Paginación para Líderes -->
            <?php if ($totalPaginas > 1): ?>
                <nav aria-label="Navegación de líderes" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?= ($paginaActual <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= $pageUrlPrefix . ($paginaActual - 1) ?>">
                                <i class="fas fa-chevron-left me-1"></i> Anterior
                            </a>
                        </li>
                        <?php for ($p = 1; $p <= $totalPaginas; $p++): ?>
                            <li class="page-item <?= ($p === $paginaActual) ? 'active' : '' ?>">
                                <a class="page-link" href="<?= $pageUrlPrefix . $p ?>"><?= $p ?></a>
                            </li>
                        <?php endfor; ?>
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

<!-- Modal Editar Datos y Credenciales de Líder -->
<div class="modal fade" id="modalEditarCredencialesLider" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="lideres.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="accion" value="editar_lider">
                <input type="hidden" name="lider_id" id="edit_lider_id">

                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="fas fa-user-edit text-warning me-2"></i>Editar Datos y Credenciales de Líder</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="fas fa-info-circle me-1"></i>Modifique el Nombre, Cédula, Teléfono, Usuario de ingreso o restablezca la contraseña si es necesario.
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Nombre Completo *</label>
                        <input type="text" class="form-control" id="edit_lider_nombre" name="nombre_completo" required placeholder="Nombre completo del líder">
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Cédula *</label>
                            <input type="text" class="form-control" id="edit_lider_cedula" name="cedula" required inputmode="numeric" maxlength="12" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Teléfono / Celular *</label>
                            <input type="text" class="form-control" id="edit_lider_telefono" name="telefono" required inputmode="numeric" maxlength="10" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold text-primary"><i class="fas fa-user me-1"></i>Usuario de Ingreso *</label>
                        <input type="text" class="form-control" id="edit_lider_username" name="username" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold text-danger"><i class="fas fa-key me-1"></i>Nueva Contraseña (Opcional)</label>
                        <input type="password" class="form-control" name="nueva_password" placeholder="Dejar en blanco para conservar la contraseña actual">
                        <small class="text-muted">Si ingresa un texto aquí, la contraseña actual se actualizará.</small>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const selectElegible = document.getElementById('selectReferidoElegible');
    const cajaSeleccionada = document.getElementById('cajaPersonaSeleccionada');
    const nombreDiv = document.getElementById('nombrePersonaSeleccionada');

    selectElegible.addEventListener('change', function() {
        const selectedOption = selectElegible.options[selectElegible.selectedIndex];
        if (selectedOption && selectedOption.value) {
            nombreDiv.innerHTML = '<i class="fas fa-user-check text-success me-2"></i>' + selectedOption.textContent;
            cajaSeleccionada.classList.remove('d-none');
        } else {
            cajaSeleccionada.classList.add('d-none');
        }
    });

    document.getElementById('filtroReferidoElegible').addEventListener('keyup', function() {
        const value = this.value.toLowerCase().trim();
        const options = selectElegible.querySelectorAll('option');

        options.forEach(opt => {
            if (opt.value === "") return;
            const searchData = opt.getAttribute('data-search') || opt.textContent.toLowerCase();
            opt.style.display = searchData.indexOf(value) > -1 ? '' : 'none';
        });
    });

    let editLiderModal;
    document.addEventListener('DOMContentLoaded', function() {
        editLiderModal = new bootstrap.Modal(document.getElementById('modalEditarCredencialesLider'));
    });

    function abrirModalEditarCredenciales(lider) {
        document.getElementById('edit_lider_id').value = lider.id;
        document.getElementById('edit_lider_nombre').value = lider.nombre_completo;
        document.getElementById('edit_lider_cedula').value = lider.cedula;
        document.getElementById('edit_lider_telefono').value = lider.telefono;
        document.getElementById('edit_lider_username').value = lider.username;
        editLiderModal.show();
    }
</script>
</body>
</html>
