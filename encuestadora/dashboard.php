<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/auth.php';

requireRole('Encuestadora');

$encuestadora = getCurrentUser();
$pdo = getDB();
$csrfToken = generateCSRFToken();

// Leer y limpiar mensajes de sesión (Patrón PRG)
$msg = $_SESSION['toast_msg'] ?? '';
$error = $_SESSION['toast_error'] ?? '';
unset($_SESSION['toast_msg'], $_SESSION['toast_error']);

$modoCola = sanitizeInput($_GET['modo'] ?? 'normal');

// Obtener Configuración de URL Externa Dinámica y Guión de Llamada
$stmtConfigUrl = $pdo->query("SELECT url_consulta_externa, pregunta_candidato, guion_llamada FROM configuracion_encuestas WHERE id = 1");
$configData = $stmtConfigUrl->fetch() ?: [];
$urlExternaActual = $configData['url_consulta_externa'] ?? '';
$preguntaCandidato = !empty($configData['pregunta_candidato']) ? $configData['pregunta_candidato'] : '¿Si las votaciones fueran hoy, por cuál candidato o grupo político votarías?';
$guionLlamada = !empty($configData['guion_llamada']) ? $configData['guion_llamada'] : "Hola, muy buenas tardes. Mi nombre es Andrea de la firma de opinión Estelar. Nos comunicamos muy brevemente para realizarle un par de preguntas rápidas sobre el desarrollo y futuro de nuestro municipio como parte de un estudio local. ¿Nos concede solo 1 minuto de su tiempo?";

if (empty($urlExternaActual)) {
    $codigoRefEncuestadora = !empty($encuestadora['codigo_referido']) ? $encuestadora['codigo_referido'] : 'LID-40FEA8AA';
    $urlExternaActual = '../registro.php?ref=' . urlencode($codigoRefEncuestadora);
}

// Obtener Información de la Ronda Activa lanzada por AdminEncuestas
$stmtRondaActiva = $pdo->query("SELECT * FROM rondas_encuestas ORDER BY id DESC LIMIT 1");
$rondaActiva = $stmtRondaActiva->fetch();
if (!$rondaActiva) {
    $rondaActiva = ['id' => 1, 'numero_ronda' => 1, 'nombre_ronda' => 'Ronda Inicial #1', 'creado_en' => date('Y-m-d H:i:s')];
}

// Obtener Lista de Candidatos Activos Creados por el AdminEncuestas con su Grupo Político
$stmtCandActivos = $pdo->query("SELECT * FROM candidatos_encuestas WHERE activo = 1 ORDER BY id ASC");
$candidatos = $stmtCandActivos->fetchAll();

// Obtener Lista de Preguntas Adicionales Activas
$stmtPregActivas = $pdo->query("SELECT * FROM preguntas_encuestas WHERE activo = 1 ORDER BY id ASC");
$preguntasAdicionales = $stmtPregActivas->fetchAll();

// Procesar Guardado de Encuesta o Registro de Llamada (Redireccionando con PRG)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'guardar_encuesta') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['toast_error'] = "Token de seguridad expirado.";
    } else {
        $referidoId = (int)($_POST['referido_id'] ?? 0);
        $candidato = sanitizeInput($_POST['candidato'] ?? '');
        $votanteRespuesta = sanitizeInput($_POST['votante_yopal_respuesta'] ?? '');
        $puestoVotacion = sanitizeInput($_POST['puesto_votacion'] ?? '');
        $mesaVotacion = sanitizeInput($_POST['mesa_votacion'] ?? '');
        $observaciones = sanitizeInput($_POST['observaciones'] ?? '');
        $modoOrigen = sanitizeInput($_POST['modo_origen'] ?? 'normal');

        // Exenciones de llenado obligatorio (Puesto y Mesa son gestionados automáticamente por el Bot)
        $esExentoCampos = true;

        if (empty($referidoId) || empty($candidato)) {
            $_SESSION['toast_error'] = "Por favor seleccione el resultado de la llamada o el candidato.";
        } elseif (!$esExentoCampos && (empty($votanteRespuesta) || empty($puestoVotacion) || empty($mesaVotacion))) {
            $_SESSION['toast_error'] = "Todos los campos de votación (Estado en Yopal, PUESTO y MESA) son obligatorios excepto si selecciona 'No Contestó' o 'Cédula Falsa'.";
        } else {
            $pdo->beginTransaction();
            try {
                // 1. Guardar NUEVA respuesta vinculada a la Ronda Activa
                $stmtIns = $pdo->prepare("
                    INSERT INTO respuestas_encuestas (referido_id, ronda_id, encuestadora_id, candidato_elegido, votante_yopal_respuesta, puesto_votacion, mesa_votacion, observaciones) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtIns->execute([$referidoId, $rondaActiva['id'], $encuestadora['id'], $candidato, $votanteRespuesta, $puestoVotacion, $mesaVotacion, $observaciones]);

                // 2. ACTUALIZAR LA CASILLA votante_yopal, puesto_votacion Y mesa_votacion EN LA TABLA referidos SI SE REGISTRARON
                if (!empty($votanteRespuesta) || !empty($puestoVotacion) || !empty($mesaVotacion)) {
                    $stmtUpdRef = $pdo->prepare("
                        UPDATE referidos 
                        SET votante_yopal = IF(? != '', ?, votante_yopal), 
                            puesto_votacion = IF(? != '', ?, puesto_votacion), 
                            mesa_votacion = IF(? != '', ?, mesa_votacion) 
                        WHERE id = ?
                    ");
                    $stmtUpdRef->execute([$votanteRespuesta, $votanteRespuesta, $puestoVotacion, $puestoVotacion, $mesaVotacion, $mesaVotacion, $referidoId]);
                }

                $pdo->commit();
                $_SESSION['toast_msg'] = "¡Registro de llamada guardado exitosamente!";
            } catch (Exception $e) {
                $pdo->rollBack();
                $_SESSION['toast_error'] = "Error al guardar registro: " . $e->getMessage();
            }
        }
    }
    header("Location: dashboard.php?modo=" . urlencode($modoOrigen));
    exit();
}

// 1. Conteo de Encuestas Realizadas Hoy por esta encuestadora
$stmtCountHoy = $pdo->prepare("
    SELECT COUNT(*) 
    FROM respuestas_encuestas 
    WHERE encuestadora_id = ? AND DATE(creado_en) = CURDATE()
");
$stmtCountHoy->execute([$encuestadora['id']]);
$encuestasHoy = $stmtCountHoy->fetchColumn();

// 2. Conteo Total de Encuestas Realizadas por esta encuestadora
$stmtCountTotal = $pdo->prepare("SELECT COUNT(*) FROM respuestas_encuestas WHERE encuestadora_id = ?");
$stmtCountTotal->execute([$encuestadora['id']]);
$encuestasTotal = $stmtCountTotal->fetchColumn();

// 3. Conteo de Pendientes Iniciales en la Ronda Activa
$stmtPendientes = $pdo->prepare("
    SELECT COUNT(*) 
    FROM referidos r
    WHERE r.id NOT IN (
        SELECT referido_id 
        FROM respuestas_encuestas 
        WHERE ronda_id = ?
    )
");
$stmtPendientes->execute([$rondaActiva['id']]);
$totalPendientesRonda = (int)$stmtPendientes->fetchColumn();

// Total en Ronda y Completadas en la Ronda Activa
$stmtTotalR = $pdo->query("SELECT COUNT(*) FROM referidos");
$totalRonda = (int)$stmtTotalR->fetchColumn();

$stmtCompR = $pdo->prepare("SELECT COUNT(DISTINCT referido_id) FROM respuestas_encuestas WHERE ronda_id = ?");
$stmtCompR->execute([$rondaActiva['id']]);
$totalCompletadasRonda = (int)$stmtCompR->fetchColumn();

$porcentajeAvance = $totalRonda > 0 ? round(($totalCompletadasRonda / $totalRonda) * 100, 1) : 0;

// 4. Conteo de Afiliados que NO CONTESTARON en su última llamada de esta Ronda Activa
$stmtCountReintentos = $pdo->prepare("
    SELECT COUNT(*) 
    FROM respuestas_encuestas re
    WHERE re.ronda_id = ? 
      AND re.candidato_elegido LIKE '%No Contestó%'
      AND re.id = (
          SELECT MAX(id) 
          FROM respuestas_encuestas 
          WHERE referido_id = re.referido_id AND ronda_id = ?
      )
");
$stmtCountReintentos->execute([$rondaActiva['id'], $rondaActiva['id']]);
$totalReintentosPendientes = $stmtCountReintentos->fetchColumn();

// 5. Obtener la Persona a Encuestar según la Cola Seleccionada (Normal vs Reintento No Contestaron)
if ($modoCola === 'reintento_nocontesto') {
    $sqlSiguiente = "
        SELECT r.*, 
               CONCAT(r.nombres, ' ', r.apellidos) as nombre_completo,
               re.creado_en as ultima_encuesta,
               re.observaciones as ultima_observacion,
               (SELECT COUNT(*) FROM respuestas_encuestas WHERE referido_id = r.id) as total_rondas_previas
        FROM referidos r
        JOIN respuestas_encuestas re ON re.referido_id = r.id
        WHERE re.ronda_id = ?
          AND re.candidato_elegido LIKE '%No Contestó%'
          AND re.id = (
              SELECT MAX(id) 
              FROM respuestas_encuestas 
              WHERE referido_id = r.id AND ronda_id = ?
          )
        ORDER BY re.creado_en ASC
        LIMIT 1
    ";
    $stmtSiguiente = $pdo->prepare($sqlSiguiente);
    $stmtSiguiente->execute([$rondaActiva['id'], $rondaActiva['id']]);
    $personaActual = $stmtSiguiente->fetch();
} else {
    $sqlSiguiente = "
        SELECT r.*, 
               CONCAT(r.nombres, ' ', r.apellidos) as nombre_completo,
               (SELECT MAX(creado_en) FROM respuestas_encuestas WHERE referido_id = r.id) as ultima_encuesta,
               (SELECT observaciones FROM respuestas_encuestas WHERE referido_id = r.id ORDER BY id DESC LIMIT 1) as ultima_observacion,
               (SELECT COUNT(*) FROM respuestas_encuestas WHERE referido_id = r.id) as total_rondas_previas
        FROM referidos r
        WHERE r.id NOT IN (
            SELECT referido_id 
            FROM respuestas_encuestas 
            WHERE ronda_id = ?
        )
        ORDER BY r.id ASC
        LIMIT 1
    ";
    $stmtSiguiente = $pdo->prepare($sqlSiguiente);
    $stmtSiguiente->execute([$rondaActiva['id']]);
    $personaActual = $stmtSiguiente->fetch();
}

// Generar o consultar Token Único de WhatsApp para la Persona Actual en la Ronda Activa
$tokenWaData = null;
$waUrl = '#';
if (!empty($personaActual['id'])) {
    $stmtTokSel = $pdo->prepare("SELECT * FROM encuestas_tokens_whatsapp WHERE referido_id = ? AND ronda_id = ?");
    $stmtTokSel->execute([$personaActual['id'], $rondaActiva['id']]);
    $tokenWaData = $stmtTokSel->fetch();

    if (!$tokenWaData) {
        $newToken = md5($personaActual['id'] . '_' . $rondaActiva['id'] . '_' . time() . '_' . rand(1000, 9999));
        $stmtTokIns = $pdo->prepare("INSERT INTO encuestas_tokens_whatsapp (referido_id, ronda_id, encuestadora_id, token, estado) VALUES (?, ?, ?, ?, 'enviada')");
        $stmtTokIns->execute([$personaActual['id'], $rondaActiva['id'], $encuestadora['id'], $newToken]);
        $tokenWaData = ['token' => $newToken, 'estado' => 'enviada'];
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $publicLink = $scheme . '://' . $host . '/Aplicaiones/fieles/encuesta_publica.php?t=' . ($tokenWaData['token'] ?? '');

    $speechLimpio = ltrim($guionLlamada);
    if (stripos($speechLimpio, 'Hola') === 0) {
        $speechLimpio = preg_replace('/^Hola,?\s*/i', '', $speechLimpio);
    }
    $nombreCiudadano = trim(($personaActual['nombres'] ?? '') . ' ' . ($personaActual['apellidos'] ?? ''));
    $msgWaText = "Hola " . $nombreCiudadano . ", " . lcfirst($speechLimpio) . "\n\nIngrese a este enlace seguro para responder en 1 minuto:\n\n" . $publicLink . "\n\n¡Muchas gracias por su opinión!";
    $celularLimpio = preg_replace('/[^0-9]/', '', $personaActual['celular'] ?? '');
    if (strlen($celularLimpio) === 10 && strpos($celularLimpio, '57') !== 0) {
        $celularLimpio = '57' . $celularLimpio;
    }
    $waUrl = "https://api.whatsapp.com/send?phone=" . $celularLimpio . "&text=" . urlencode($msgWaText);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Módulo de Encuestas - Proyecto Político Social</title>
    <!-- Font Awesome & MDB / Bootstrap 5 CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/6.4.0/mdb.min.css">
    <link rel="stylesheet" href="../assets/css/custom.css">
    <!-- SweetAlert2 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .cand-option-card {
            border: 1.5px solid #dee2e6;
            border-radius: 8px;
            background-color: #ffffff;
            cursor: pointer;
            transition: all 0.2s ease-in-out;
            padding: 10px 14px;
        }
        .cand-option-card:hover {
            border-color: #0d6efd;
            background-color: #f8f9fa;
        }
        .btn-check:checked + .cand-option-card {
            border-color: #0d6efd !important;
            background-color: #e7f1ff !important;
            box-shadow: 0 3px 8px rgba(13,110,253,0.25) !important;
        }
        .btn-check:checked + .cand-option-card.card-no-contesto {
            border-color: #ffc107 !important;
            background-color: #fff8e1 !important;
            box-shadow: 0 3px 8px rgba(255,193,7,0.3) !important;
        }
        .btn-check:checked + .cand-option-card.card-cedula-falsa {
            border-color: #212529 !important;
            background-color: #e9ecef !important;
            box-shadow: 0 3px 8px rgba(33,37,41,0.3) !important;
        }
        .btn-check:checked + .cand-option-card.card-equivocado {
            border-color: #dc3545 !important;
            background-color: #f8d7da !important;
            box-shadow: 0 3px 8px rgba(220,53,69,0.3) !important;
        }
        .btn-check:checked + .cand-option-card.card-rechazo {
            border-color: #6c757d !important;
            background-color: #e2e3e5 !important;
            box-shadow: 0 3px 8px rgba(108,117,125,0.3) !important;
        }
    </style>
</head>
<body class="bg-light">

<!-- Navbar Encuestadora -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-0 py-2">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="dashboard.php">
            <i class="fas fa-headset text-warning me-2"></i>Módulo de Encuestas Telefónicas
        </a>
        <div class="d-flex align-items-center ms-auto">
            <span class="text-white me-3"><i class="fas fa-user-check text-success me-1"></i><?= e($encuestadora['nombre_completo']) ?></span>
            <a href="../logout.php" class="btn btn-outline-light btn-sm"><i class="fas fa-sign-out-alt me-1"></i> Salir</a>
        </div>
    </div>
</nav>

<div class="container py-4" style="max-width: 850px;">

    <!-- Selector de Modo de Cola: Iniciales vs Reintento de No Contestaron -->
    <div class="card card-custom mb-4 shadow-sm border">
        <div class="card-body p-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <h6 class="fw-bold mb-0 text-dark"><i class="fas fa-layer-group text-primary me-2"></i>Modo de Trabajo en Ronda #<?= $rondaActiva['numero_ronda'] ?>:</h6>
                <span class="small text-muted">Seleccione la cola de llamadas que desea procesar</span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div class="btn-group" role="group" aria-label="Selector de cola">
                    <a href="dashboard.php?modo=normal" class="btn <?= $modoCola !== 'reintento_nocontesto' ? 'btn-primary active' : 'btn-outline-primary' ?> btn-sm fw-bold">
                        <i class="fas fa-users me-1"></i> Cola Inicial (<?= $totalPendientesRonda ?>)
                    </a>
                    <a href="dashboard.php?modo=reintento_nocontesto" class="btn <?= $modoCola === 'reintento_nocontesto' ? 'btn-warning text-dark active' : 'btn-outline-warning text-dark' ?> btn-sm fw-bold">
                        <i class="fas fa-redo me-1"></i> Reintentar No Contestaron (<?= $totalReintentosPendientes ?>)
                    </a>
                </div>
                <?php if ($personaActual): ?>
                    <a href="dashboard.php?modo=<?= e($modoCola) ?>&skip=<?= $personaActual['id'] ?>" class="btn btn-outline-secondary btn-sm fw-bold" title="Saltar contacto actual">
                        <i class="fas fa-forward me-1"></i>Saltar
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Tira de Contador Personal, Ronda Activa y Pendientes -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card card-custom bg-white border-start border-4 border-primary p-3 shadow-sm h-100">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase text-muted fw-bold small mb-1">Ronda Activa</h6>
                        <h5 class="fw-bold text-primary mb-0">Ronda #<?= $rondaActiva['numero_ronda'] ?></h5>
                    </div>
                    <div class="text-primary p-2 rounded-circle" style="background-color: #e3f2fd;">
                        <i class="fas fa-sync-alt fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card card-custom bg-white border-start border-4 border-warning p-3 shadow-sm h-100">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase text-muted fw-bold small mb-1">Reintentos No Contestó</h6>
                        <h3 class="fw-bold text-warning text-dark mb-0"><?= $totalReintentosPendientes ?></h3>
                    </div>
                    <div class="text-warning p-2 rounded-circle" style="background-color: #fff8e1;">
                        <i class="fas fa-phone-slash fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card card-custom bg-white border-start border-4 border-success p-3 shadow-sm h-100">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase text-muted fw-bold small mb-1">Encuestas Hoy</h6>
                        <h3 class="fw-bold text-success mb-0"><?= $encuestasHoy ?></h3>
                    </div>
                    <div class="text-success p-2 rounded-circle" style="background-color: #e8f5e9;">
                        <i class="fas fa-phone-volume fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card card-custom bg-white border-start border-4 border-info p-3 shadow-sm h-100">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase text-muted fw-bold small mb-1">Mis Encuestas Total</h6>
                        <h3 class="fw-bold text-info mb-0"><?= $encuestasTotal ?></h3>
                    </div>
                    <div class="text-info p-2 rounded-circle" style="background-color: #e0f7fa;">
                        <i class="fas fa-clipboard-check fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$personaActual): ?>
        <!-- Mensaje cuando la cola está vacía -->
        <div class="card shadow-sm border-0 text-center p-5">
            <div class="card-body">
                <div class="mb-3 text-success">
                    <i class="fas fa-check-circle fa-4x"></i>
                </div>
                <h3 class="fw-bold text-dark">
                    <?= $modoCola === 'reintento_nocontesto' ? '¡Sin reintentos pendientes!' : '¡Excelente trabajo! Has completado todas las llamadas asignadas.' ?>
                </h3>
                <p class="text-muted fs-6">
                    <?= $modoCola === 'reintento_nocontesto' 
                        ? 'No tienes contactos marcados como "No Contestó" pendientes por volver a llamar en esta ronda.' 
                        : 'No hay más contactos pendientes en la Ronda #' . $rondaActiva['numero_ronda'] . '. Puedes revisar el panel de reintentos.' ?>
                </p>
                <div class="mt-3">
                    <?php if ($modoCola === 'reintento_nocontesto'): ?>
                        <a href="dashboard.php?modo=normal" class="btn btn-primary fw-bold"><i class="fas fa-arrow-left me-1"></i>Volver a Cola Normal</a>
                    <?php else: ?>
                        <a href="dashboard.php?modo=reintento_nocontesto" class="btn btn-warning fw-bold text-dark"><i class="fas fa-redo-alt me-1"></i>Revisar Reintentos</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php else: ?>

        <!-- Tarjeta Principal del Contacto Actual -->
        <div class="card shadow-sm border-0 mb-4">
            <!-- <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center d-none"> 
                 (Header ocultado por solicitud del usuario) 
            </div> -->
            
            <div class="card-body p-4">
                
                <!-- Datos de la Persona a Llamar -->
                <div class="bg-light p-3 rounded mb-4 border">
                    <div class="row align-items-center">
                        <div class="col-md-7">
                            <h4 class="fw-bold text-primary mb-1"><?= e($personaActual['nombre_completo']) ?></h4>
                            <?php if ($modoCola === 'reintento_nocontesto' && !empty($personaActual['ultima_observacion'])): ?>
                                <div class="small text-warning text-dark fw-bold mt-1">
                                    <i class="fas fa-history me-1"></i>Nota del intento previo: <em><?= e($personaActual['ultima_observacion']) ?></em>
                                </div>
                            <?php elseif ($personaActual['ultima_encuesta']): ?>
                                <div class="small text-muted mt-1">
                                    <i class="far fa-clock me-1 text-info"></i>Último intento: <strong><?= date('d/m/Y H:i', strtotime($personaActual['ultima_encuesta'])) ?></strong>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-5 text-md-end mt-2 mt-md-0 d-flex flex-column gap-2">
                            <a href="tel:<?= e($personaActual['celular']) ?>" class="btn btn-success btn-md fw-bold shadow-0 py-2">
                                <i class="fas fa-phone-alt me-2"></i>Llamar a <?= e($personaActual['celular']) ?>
                            </a>
                            <a href="<?= e($waUrl) ?>" target="_blank" class="btn btn-outline-primary btn-md fw-bold shadow-0 py-2" onclick="document.getElementById('opt_wa_enviado').checked = true;" title="Enviar encuesta con enlace público">
                                <i class="fas fa-globe me-2 fa-lg text-primary"></i>Enviar Formulario Web (WhatsApp)
                            </a>
                            <?php if (!empty($tokenWaData['token'])): ?>
                                <button type="button" class="btn btn-outline-info btn-sm fw-bold shadow-0 py-1" onclick="simularBotonMetaWhatsApp('<?= $tokenWaData['token'] ?>')" title="Probar respuesta de botón interactivo Meta Cloud API">
                                    <i class="fas fa-robot me-1"></i>Probar Botones Interactivos Meta API
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <!-- GUIÓN / SPEECH OFICIAL PARA LA LLAMADA (CONFIGURADO DESDE EL ADMIN) -->
                <?php if (!empty($guionLlamada)): ?>
                    <div class="card border-primary mb-4 shadow-sm" style="background-color: #f0f7ff;">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-center mb-1">
                                <i class="fas fa-scroll text-primary me-2 fa-lg"></i>
                                <h6 class="fw-bold text-primary mb-0 text-uppercase" style="font-size: 0.85rem; letter-spacing: 0.5px;">Guión / Speech Oficial para la Llamada:</h6>
                            </div>
                            <p class="mb-0 text-dark italic fw-bold" style="font-size: 0.95rem; line-height: 1.5; white-space: pre-line;">
                                "<?= e($guionLlamada) ?>"
                            </p>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Formulario Principal -->
                <form action="dashboard.php" method="POST" id="formEncuesta">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="accion" value="guardar_encuesta">
                    <input type="hidden" name="referido_id" value="<?= $personaActual['id'] ?>">
                    <input type="hidden" name="modo_origen" value="<?= e($modoCola) ?>">

                    <!-- Preguntas Adicionales Configuradas por AdminEncuestas -->
                    <?php if (!empty($preguntasAdicionales)): ?>
                        <?php foreach ($preguntasAdicionales as $numP => $preg): ?>
                            <div class="mb-4 p-3 border rounded bg-light">
                                <label class="form-label fw-bold text-dark small mb-2">
                                    <i class="fas fa-question-circle me-1 text-info"></i><?= ($numP + 1) ?>. <?= e($preg['pregunta']) ?> *
                                </label>
                                <?php if (!empty($preg['opciones'])): ?>
                                    <?php $opts = explode(',', $preg['opciones']); foreach ($opts as $oKey => $optVal): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="pregunta_extra_<?= $preg['id'] ?>" id="opt_<?= $preg['id'] ?>_<?= $oKey ?>" value="<?= e(trim($optVal)) ?>" required>
                                            <label class="form-check-label" for="opt_<?= $preg['id'] ?>_<?= $oKey ?>"><?= e(trim($optVal)) ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <input type="text" name="pregunta_extra_<?= $preg['id'] ?>" class="form-control form-control-sm" placeholder="Respuesta del usuario..." required>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- SELECCIÓN DE CANDIDATO O ESTADO DE LA LLAMADA -->
                    <div class="mb-4">
                        <label class="form-label fw-bold text-dark fs-6 mb-2">
                            <i class="fas fa-question-circle me-2 text-primary"></i><?= e($preguntaCandidato) ?> *
                        </label>

                        <!-- CANDIDATOS POLÍTICOS -->
                        <div class="row g-2 mb-3">
                            <div class="col-12"><span class="fw-bold text-muted small text-uppercase mb-1 d-block"><i class="fas fa-users me-1 text-primary"></i>Candidatos Registrados:</span></div>
                            <?php foreach ($candidatos as $cand): ?>
                                <div class="col-md-6 col-12">
                                    <input type="radio" class="btn-check" name="candidato" id="cand_<?= $cand['id'] ?>" value="<?= e($cand['nombre']) ?>" required>
                                    <label class="cand-option-card w-100 d-block" for="cand_<?= $cand['id'] ?>">
                                        <div class="fw-bold text-dark small"><i class="fas fa-user-check text-primary me-1"></i><?= e($cand['nombre']) ?></div>
                                        <?php if (!empty($cand['grupo'])): ?>
                                            <div class="text-muted" style="font-size: 0.78rem;"><i class="fas fa-flag text-warning me-1"></i><?= e($cand['grupo']) ?></div>
                                        <?php endif; ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- ESTADOS DE LLAMADA SIN VOTO / NO CONTESTÓ / CÉDULA FALSA / WHATSAPP -->
                        <div class="row g-2 border-top pt-2">
                            <div class="col-12 d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-bold text-muted small text-uppercase"><i class="fas fa-phone-slash me-1 text-warning"></i>Opciones de Estado de Llamada / WhatsApp:</span>
                                <span id="badgeExencionCampos" class="badge bg-info text-dark d-none"><i class="fas fa-info-circle me-1"></i>Exento de llenar Puesto y Mesa</span>
                            </div>

                            <div class="col-md-3 col-6">
                                <input type="radio" class="btn-check" name="candidato" id="opt_wa_enviado" value="Enviado Enlace Web / WhatsApp (En Espera)" required>
                                <label class="cand-option-card border-success w-100 d-block" for="opt_wa_enviado">
                                    <div class="fw-bold text-success small"><i class="fab fa-whatsapp me-1"></i>Enlace Web WhatsApp</div>
                                    <div class="text-muted" style="font-size: 0.73rem;">En espera de respuesta</div>
                                </label>
                            </div>

                            <div class="col-md-3 col-6">
                                <input type="radio" class="btn-check" name="candidato" id="opt_wa_botones" value="Enviado Botones Meta API / WhatsApp (En Espera)" required>
                                <label class="cand-option-card border-primary w-100 d-block" for="opt_wa_botones">
                                    <div class="fw-bold text-primary small"><i class="fas fa-robot me-1"></i>Botones Meta API</div>
                                    <div class="text-muted" style="font-size: 0.73rem;">En espera de respuesta</div>
                                </label>
                            </div>

                            <div class="col-md-3 col-6">
                                <input type="radio" class="btn-check" name="candidato" id="opt_no_contesto" value="No Contestó / Sin Respuesta" required>
                                <label class="cand-option-card card-no-contesto border-warning w-100 d-block" for="opt_no_contesto">
                                    <div class="fw-bold text-dark small"><i class="fas fa-phone-slash text-warning me-1"></i>No Contestó</div>
                                    <div class="text-muted" style="font-size: 0.73rem;">Sin respuesta</div>
                                </label>
                            </div>

                            <div class="col-md-3 col-6 d-none">
                                <input type="radio" class="btn-check" name="candidato" id="opt_cedula_falsa" value="Cédula Falsa / Inexistente" required>
                                <label class="cand-option-card card-cedula-falsa border-dark w-100 d-block" for="opt_cedula_falsa">
                                    <div class="fw-bold text-dark small"><i class="fas fa-user-times text-danger me-1"></i>Cédula Falsa</div>
                                    <div class="text-muted" style="font-size: 0.73rem;">Errónea / Inexistente</div>
                                </label>
                            </div>

                            <div class="col-md-3 col-6">
                                <input type="radio" class="btn-check" name="candidato" id="opt_equivocado" value="Número Equivocado / Inaccesible" required>
                                <label class="cand-option-card card-equivocado border-danger w-100 d-block" for="opt_equivocado">
                                    <div class="fw-bold text-dark small"><i class="fas fa-exclamation-triangle text-danger me-1"></i>Núm. Equivocado</div>
                                    <div class="text-muted" style="font-size: 0.73rem;">Fuera de servicio</div>
                                </label>
                            </div>

                            <div class="col-md-3 col-6">
                                <input type="radio" class="btn-check" name="candidato" id="opt_rechazo" value="No Desea Responder / Rechazó" required>
                                <label class="cand-option-card card-rechazo border-secondary w-100 d-block" for="opt_rechazo">
                                    <div class="fw-bold text-dark small"><i class="fas fa-ban text-secondary me-1"></i>Rechazó Encuesta</div>
                                    <div class="text-muted" style="font-size: 0.73rem;">No desea responder</div>
                                </label>
                            </div>
                        </div>
                    </div>

                            <!-- Pregunta Estado de Votación en Yopal -->
                    <div class="mb-4 p-3 border rounded bg-white d-none" id="seccionEstadoVotacion">
                        <label class="form-label fw-bold text-dark fs-6 mb-2">
                            <i class="fas fa-vote-yea me-2 text-success"></i>Confirmar / Actualizar Estado de Votación en Yopal *
                        </label>
                        <p class="small text-muted mb-2">Respuesta actual en sistema: 
                            <span class="badge bg-secondary"><?= e($personaActual['votante_yopal']) ?></span>
                        </p>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="votante_yopal_respuesta" id="votaSi" value="Si" <?= $personaActual['votante_yopal'] === 'Si' ? 'checked' : '' ?> required>
                            <label class="form-check-label fw-bold text-success" for="votaSi">
                                <i class="fas fa-check-circle me-1"></i> Sí, vota en Yopal
                            </label>
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="votante_yopal_respuesta" id="votaInscribir" value="Quiero inscribir" <?= $personaActual['votante_yopal'] === 'Quiero inscribir' ? 'checked' : '' ?> required>
                            <label class="form-check-label fw-bold text-warning text-dark" for="votaInscribir">
                                <i class="fas fa-edit me-1"></i> Quiere inscribir su cédula en Yopal
                            </label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="votante_yopal_respuesta" id="votaNo" value="No" <?= $personaActual['votante_yopal'] === 'No' ? 'checked' : '' ?> required>
                            <label class="form-check-label fw-bold text-secondary" for="votaNo">
                                <i class="fas fa-times-circle me-1"></i> No, voto en otro municipio
                            </label>
                        </div>
                    </div>

                    <!-- CASILLAS OBLIGATORIAS PARA PUESTO DE VOTACIÓN Y NÚMERO DE MESA (Salvo No Contestó o Cédula Falsa) -->
                    <div class="mb-4 p-3 border rounded bg-light d-none" id="seccionPuestoMesa">
                        <h6 class="fw-bold text-primary mb-3">
                            <i class="fas fa-map-marked-alt me-2"></i>Lugar de Votación (Puesto y Mesa) *
                        </h6>

                        <?php if (!empty($personaActual['departamento']) || !empty($personaActual['municipio'])): ?>
                        <!-- Datos del Censo (Solo lectura, extraídos del bot) -->
                        <div class="row g-2 mb-3">
                            <?php if (!empty($personaActual['departamento'])): ?>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-muted mb-1"><i class="fas fa-map me-1"></i>Departamento:</label>
                                <div class="form-control form-control-sm bg-white text-muted border-0 border-bottom rounded-0 ps-0">
                                    <?= e($personaActual['departamento']) ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($personaActual['municipio'])): ?>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-muted mb-1"><i class="fas fa-city me-1"></i>Municipio:</label>
                                <div class="form-control form-control-sm bg-white text-muted border-0 border-bottom rounded-0 ps-0">
                                    <?= e($personaActual['municipio']) ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($personaActual['direccion_votacion'])): ?>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-muted mb-1"><i class="fas fa-road me-1"></i>Dirección:</label>
                                <div class="form-control form-control-sm bg-white text-muted border-0 border-bottom rounded-0 ps-0">
                                    <?= e($personaActual['direccion_votacion']) ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <div class="row g-3">
                            <div class="col-md-8 col-12">
                                <label for="puesto_votacion" class="form-label fw-bold text-dark small mb-1">
                                    <i class="fas fa-building me-1 text-secondary"></i>PUESTO: *
                                </label>
                                <input type="text" name="puesto_votacion" id="puesto_votacion" class="form-control" placeholder="Nombre del puesto de votación (Colegio / Escuela)..." value="<?= e($personaActual['puesto_votacion'] ?? '') ?>">
                            </div>
                            <div class="col-md-4 col-12">
                                <label for="mesa_votacion" class="form-label fw-bold text-dark small mb-1">
                                    <i class="fas fa-sort-numeric-up me-1 text-secondary"></i>MESA: *
                                </label>
                                <input type="number" name="mesa_votacion" id="mesa_votacion" class="form-control" placeholder="Número de Mesa" min="1" max="500" value="<?= e($personaActual['mesa_votacion'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Observaciones o Comentarios Adicionales (OPCIONAL - PRECARGADO Y EDITABLE) -->
                    <div class="mb-4">
                        <label for="observaciones" class="form-label fw-bold text-dark small">
                            <i class="fas fa-comment-alt me-1 text-primary"></i>Observaciones o Comentarios adicionales <span class="text-muted fw-normal">(Opcional - Modificable)</span>:
                        </label>
                        <textarea name="observaciones" id="observaciones" class="form-control" rows="3" placeholder="Escriba o modifique detalles adicionales del intento de llamada..."><?= e($personaActual['ultima_observacion'] ?? '') ?></textarea>
                    </div>

                    <!-- Botón Único de Guardar Registro -->
                    <button type="submit" class="btn btn-success btn-lg btn-block fw-bold shadow-0 py-3 mb-3">
                        <i class="fas fa-save me-2"></i> Guardar Registro y Siguiente Persona
                    </button>
                </form>

            </div>
        </div>
    <?php endif; ?>

    <!-- TARJETA INFERIOR: ENLACE DIRECTO DE APOYO / CONSULTA EXTERNA DE CÉDULAS -->
    <div class="card card-custom shadow-sm border border-primary">
        <div class="card-body p-3 text-center">
            <h6 class="fw-bold text-primary mb-1"><i class="fas fa-external-link-alt me-2"></i>Enlace / Consulta de Apoyo para Encuestadoras</h6>
            <p class="small text-muted mb-2">Haga clic en el botón para abrir la plataforma de inscripción o consulta de cédulas durante la llamada:</p>
            <a href="<?= e($urlExternaActual) ?>" target="_blank" class="btn btn-primary btn-block fw-bold shadow-0 py-2">
                <i class="fas fa-external-link-alt me-2"></i> Abrir Enlace de Apoyo (<?= e($urlExternaActual) ?>)
            </a>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Notificación Emergente CENTRADA con SweetAlert2 y Lógica de Campos Obligatorios Dinámicos -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Lógica Dinámica de Campos Obligatorios vs Exención (No Contestó o Cédula Falsa)
        const radioCandidatos = document.querySelectorAll('input[name="candidato"]');
        const radioVotanteYopal = document.querySelectorAll('input[name="votante_yopal_respuesta"]');
        const inputPuesto = document.getElementById('puesto_votacion');
        const inputMesa = document.getElementById('mesa_votacion');
        const badgeExencion = document.getElementById('badgeExencionCampos');

        function actualizarRequeridos() {
            const checkedCand = document.querySelector('input[name="candidato"]:checked');
            const val = checkedCand ? checkedCand.value : '';
            const esExento = (val === 'No Contestó / Sin Respuesta' || val === 'Cédula Falsa / Inexistente');

            if (esExento) {
                radioVotanteYopal.forEach(r => r.removeAttribute('required'));
                if (inputPuesto) inputPuesto.removeAttribute('required');
                if (inputMesa) inputMesa.removeAttribute('required');
                if (badgeExencion) badgeExencion.classList.remove('d-none');
            } else {
                // radioVotanteYopal.forEach(r => r.setAttribute('required', 'required'));
                // if (inputPuesto) inputPuesto.setAttribute('required', 'required');
                // if (inputMesa) inputMesa.setAttribute('required', 'required');
                if (badgeExencion) badgeExencion.classList.add('d-none');
            }
        }

        radioCandidatos.forEach(r => {
            r.addEventListener('change', actualizarRequeridos);
        });

        actualizarRequeridos();

        <?php if (!empty($msg)): ?>
            Swal.fire({
                title: '¡Registro de Encuesta Guardado!',
                text: 'La información de la llamada ha sido registrada exitosamente.',
                icon: 'success',
                position: 'center',
                showConfirmButton: false,
                timer: 2300,
                timerProgressBar: true,
                background: '#ffffff',
                iconColor: '#198754',
                customClass: {
                    popup: 'shadow-lg border border-success rounded-4 p-4'
                }
            });
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            Swal.fire({
                title: 'Atención',
                text: '<?= e($error) ?>',
                icon: 'error',
                position: 'center',
                confirmButtonColor: '#dc3545'
            });
        <?php endif; ?>
    });

    function simularBotonMetaWhatsApp(tok) {
        Swal.fire({
            title: '🤖 Prueba de Botones Meta WhatsApp API',
            text: 'Selecciona la respuesta que el usuario tocaría dentro de su chat de WhatsApp:',
            input: 'select',
            inputOptions: {
                <?php foreach ($candidatos as $c): ?>
                    '<?= e(addslashes($c['nombre'])) ?>': '<?= e(addslashes($c['nombre'])) ?>',
                <?php endforeach; ?>
            },
            showCancelButton: true,
            confirmButtonText: '⚡ Simular Clic de Botón',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed && result.value) {
                fetch('../api_whatsapp_webhook.php?simular_respuesta=1&token=' + encodeURIComponent(tok) + '&candidato=' + encodeURIComponent(result.value))
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('¡Respuesta Capturada!', 'El Webhook de Meta recibió e ingresó la respuesta automáticamente.', 'success')
                                .then(() => window.location.reload());
                        } else {
                            Swal.fire('Atención', data.message || 'No se pudo simular', 'error');
                        }
                    });
            }
        });
    }
</script>
</body>
</html>
