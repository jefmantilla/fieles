<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/auth.php';

requireRole('Encuestadora');

$encuestadora = getCurrentUser();
$pdo = getDB();
$csrfToken = generateCSRFToken();

// Leer y limpiar mensajes de sesión (Patrón PRG: Post-Redirect-Get)
$msg = $_SESSION['toast_msg'] ?? '';
$error = $_SESSION['toast_error'] ?? '';
unset($_SESSION['toast_msg'], $_SESSION['toast_error']);

// Obtener Configuración de URL Externa Dinámica
$stmtConfigUrl = $pdo->query("SELECT url_consulta_externa FROM configuracion_encuestas WHERE id = 1");
$urlExternaActual = $stmtConfigUrl->fetchColumn();

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
        $observaciones = sanitizeInput($_POST['observaciones'] ?? '');

        if (empty($referidoId) || empty($candidato) || empty($votanteRespuesta)) {
            $_SESSION['toast_error'] = "Por favor seleccione la opción de candidato / resultado de llamada y confirme el lugar de votación.";
        } else {
            $pdo->beginTransaction();
            try {
                // 1. Guardar NUEVA respuesta vinculada a la Ronda Activa
                $stmtIns = $pdo->prepare("
                    INSERT INTO respuestas_encuestas (referido_id, ronda_id, encuestadora_id, candidato_elegido, votante_yopal_respuesta, observaciones) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmtIns->execute([$referidoId, $rondaActiva['id'], $encuestadora['id'], $candidato, $votanteRespuesta, $observaciones]);

                // 2. ACTUALIZAR SIEMPRE LA CASILLA votante_yopal EN LA TABLA referidos
                $stmtUpdRef = $pdo->prepare("UPDATE referidos SET votante_yopal = ? WHERE id = ?");
                $stmtUpdRef->execute([$votanteRespuesta, $referidoId]);

                $pdo->commit();
                $_SESSION['toast_msg'] = "¡Registro de llamada guardado exitosamente!";
            } catch (Exception $e) {
                $pdo->rollBack();
                $_SESSION['toast_error'] = "Error al guardar registro: " . $e->getMessage();
            }
        }
    }
    // Redirección PRG para evitar que F5 vuelva a enviar el formulario o repetir la alerta
    header("Location: dashboard.php");
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

// 3. Obtener el SIGUIENTE referido pendiente por encuestar EN LA RONDA ACTIVA
$sqlSiguiente = "
    SELECT r.*, 
           CONCAT(r.nombres, ' ', r.apellidos) as nombre_completo,
           (SELECT MAX(creado_en) FROM respuestas_encuestas WHERE referido_id = r.id) as ultima_encuesta,
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

// 4. Conteo de Pendientes en la Ronda Activa
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
$totalPendientesRonda = $stmtPendientes->fetchColumn();
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
                        <h6 class="text-uppercase text-muted fw-bold small mb-1">Pendientes Ronda</h6>
                        <h3 class="fw-bold text-warning text-dark mb-0"><?= $totalPendientesRonda ?></h3>
                    </div>
                    <div class="text-warning p-2 rounded-circle" style="background-color: #fff8e1;">
                        <i class="fas fa-user-clock fa-lg"></i>
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

    <!-- TARJETA ÚNICA DE ENCUESTA -->
    <?php if ($personaActual): ?>
        <div class="card card-custom shadow-lg border mb-4">
            <div class="card-header bg-dark text-white p-3 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0">
                    <i class="fas fa-user-clock me-2 text-warning"></i>
                    Encuesta Ronda #<?= $rondaActiva['numero_ronda'] ?> <?= $personaActual['total_rondas_previas'] > 0 ? '(Seguimiento #' . ($personaActual['total_rondas_previas'] + 1) . ')' : '(Inicial)' ?>
                </h5>
                <span class="badge bg-warning text-dark fs-6"><i class="fas fa-id-card me-1"></i>CC: <?= e($personaActual['cedula']) ?></span>
            </div>
            
            <div class="card-body p-4">
                
                <!-- Datos de la Persona a Llamar -->
                <div class="bg-light p-3 rounded mb-4 border">
                    <div class="row align-items-center">
                        <div class="col-md-7">
                            <h4 class="fw-bold text-primary mb-1"><?= e($personaActual['nombre_completo']) ?></h4>
                            <div class="text-muted small">
                                <i class="fas fa-map-marker-alt me-1 text-danger"></i><strong>Sector:</strong> <?= e($personaActual['comuna']) ?>
                            </div>
                            <?php if ($personaActual['ultima_encuesta']): ?>
                                <div class="small text-muted mt-1">
                                    <i class="far fa-clock me-1 text-info"></i>Última respuesta: <strong><?= date('d/m/Y', strtotime($personaActual['ultima_encuesta'])) ?></strong>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-5 text-md-end mt-2 mt-md-0">
                            <a href="tel:<?= e($personaActual['celular']) ?>" class="btn btn-success btn-lg fw-bold shadow-0 w-100 py-2">
                                <i class="fas fa-phone-alt me-2"></i><?= e($personaActual['celular']) ?>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Formulario Principal -->
                <form action="dashboard.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="accion" value="guardar_encuesta">
                    <input type="hidden" name="referido_id" value="<?= $personaActual['id'] ?>">

                    <!-- SELECCIÓN DE CANDIDATO O ESTADO DE LA LLAMADA (INTEGRADO CON SELECCIÓN VISUAL) -->
                    <div class="mb-4">
                        <label class="form-label fw-bold text-dark fs-6 mb-2">
                            <i class="fas fa-question-circle me-2 text-primary"></i>¿Si las votaciones fueran hoy, por cuál candidato o grupo político votarías? (o seleccione estado de llamada) *
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

                        <!-- ESTADOS DE LLAMADA SIN VOTO / NO CONTESTÓ -->
                        <div class="row g-2 border-top pt-2">
                            <div class="col-12"><span class="fw-bold text-muted small text-uppercase mb-1 d-block"><i class="fas fa-phone-slash me-1 text-warning"></i>Opciones de Estado de Llamada (No Contestó / Sin Voto):</span></div>

                            <div class="col-md-4 col-12">
                                <input type="radio" class="btn-check" name="candidato" id="opt_no_contesto" value="No Contestó / Sin Respuesta" required>
                                <label class="cand-option-card card-no-contesto border-warning w-100 d-block" for="opt_no_contesto">
                                    <div class="fw-bold text-dark small"><i class="fas fa-phone-slash text-warning me-1"></i>No Contestó</div>
                                    <div class="text-muted" style="font-size: 0.75rem;">Sin respuesta / Ocupado</div>
                                </label>
                            </div>

                            <div class="col-md-4 col-12">
                                <input type="radio" class="btn-check" name="candidato" id="opt_equivocado" value="Número Equivocado / Inaccesible" required>
                                <label class="cand-option-card card-equivocado border-danger w-100 d-block" for="opt_equivocado">
                                    <div class="fw-bold text-dark small"><i class="fas fa-exclamation-triangle text-danger me-1"></i>Núm. Equivocado</div>
                                    <div class="text-muted" style="font-size: 0.75rem;">Fuera de servicio</div>
                                </label>
                            </div>

                            <div class="col-md-4 col-12">
                                <input type="radio" class="btn-check" name="candidato" id="opt_rechazo" value="No Desea Responder / Rechazó" required>
                                <label class="cand-option-card card-rechazo border-secondary w-100 d-block" for="opt_rechazo">
                                    <div class="fw-bold text-dark small"><i class="fas fa-ban text-secondary me-1"></i>Rechazó Encuesta</div>
                                    <div class="text-muted" style="font-size: 0.75rem;">No desea responder</div>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Preguntas Adicionales Configuradas por AdminEncuestas (si existen) -->
                    <?php if (!empty($preguntasAdicionales)): ?>
                        <?php foreach ($preguntasAdicionales as $numP => $preg): ?>
                            <div class="mb-4 p-3 border rounded bg-light">
                                <label class="form-label fw-bold text-dark small mb-2">
                                    <i class="fas fa-question-circle me-1 text-info"></i><?= ($numP + 2) ?>. <?= e($preg['pregunta']) ?>
                                </label>
                                <?php if (!empty($preg['opciones'])): ?>
                                    <?php $opts = explode(',', $preg['opciones']); foreach ($opts as $oKey => $optVal): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="pregunta_extra_<?= $preg['id'] ?>" id="opt_<?= $preg['id'] ?>_<?= $oKey ?>" value="<?= e(trim($optVal)) ?>">
                                            <label class="form-check-label" for="opt_<?= $preg['id'] ?>_<?= $oKey ?>"><?= e(trim($optVal)) ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <input type="text" name="pregunta_extra_<?= $preg['id'] ?>" class="form-control form-control-sm" placeholder="Respuesta del usuario...">
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- Pregunta Estado de Votación en Yopal (SIEMPRE SE SELECCIONA Y ACTUALIZA LA BASE DE DATOS) -->
                    <div class="mb-4 p-3 border rounded bg-white">
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

                    <!-- Observaciones o Comentarios Adicionales -->
                    <div class="mb-4">
                        <label for="observaciones" class="form-label fw-bold text-dark small"><i class="fas fa-comment-alt me-1 text-primary"></i>Observaciones o Comentarios adicionales:</label>
                        <textarea name="observaciones" id="observaciones" class="form-control" rows="3" placeholder="Escriba aquí cualquier detalle o comentario de la llamada..."></textarea>
                    </div>

                    <!-- Botón Único de Guardar Registro -->
                    <button type="submit" class="btn btn-success btn-lg btn-block fw-bold shadow-0 py-3 mb-3">
                        <i class="fas fa-save me-2"></i> Guardar Registro de Llamada y Siguiente Persona
                    </button>
                </form>

            </div>
        </div>
    <?php else: ?>
        <!-- Mensaje de Fin de Lista de Encuestas para la Ronda Activa -->
        <div class="card card-custom shadow-sm border text-center p-5 mb-4">
            <div class="card-body d-flex flex-column align-items-center justify-content-center">
                <i class="fas fa-check-double fa-4x text-success mb-3"></i>
                <h3 class="fw-bold text-dark">¡Todas las Encuestas de la Ronda #<?= $rondaActiva['numero_ronda'] ?> han sido Procesadas!</h3>
                <p class="text-muted">No hay más afiliados pendientes en la ronda actual. En cuanto el Administrador inicie la siguiente ronda de re-encuestas, aparecerán aquí automáticamente.</p>
                <a href="dashboard.php" class="btn btn-primary"><i class="fas fa-sync-alt me-1"></i> Verificar Actualizaciones</a>
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

<!-- Notificación Emergente CENTRADA con SweetAlert2 -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
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
</script>
</body>
</html>
