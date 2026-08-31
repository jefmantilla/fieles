<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/security.php';

$pdo = getDB();
$token = sanitizeInput($_GET['t'] ?? $_GET['token'] ?? '');
$msgExito = false;
$errorMsg = '';

// Validar Token
$tokenData = null;
if (!empty($token)) {
    $stmtToken = $pdo->prepare("
        SELECT t.*, r.nombres, r.apellidos, r.cedula, r.celular 
        FROM encuestas_tokens_whatsapp t
        JOIN referidos r ON t.referido_id = r.id
        WHERE t.token = ?
    ");
    $stmtToken->execute([$token]);
    $tokenData = $stmtToken->fetch();
}

// Obtener Configuración de Pregunta Principal y Candidatos
$stmtConfigPreg = $pdo->query("SELECT pregunta_candidato FROM configuracion_encuestas WHERE id = 1");
$preguntaCandidato = $stmtConfigPreg->fetchColumn() ?: '¿Si las votaciones fueran hoy, por cuál candidato o grupo político votarías?';

$stmtCand = $pdo->query("SELECT * FROM candidatos_encuestas WHERE activo = 1 ORDER BY id ASC");
$candidatos = $stmtCand->fetchAll();

$stmtPreg = $pdo->query("SELECT * FROM preguntas_encuestas WHERE activo = 1 ORDER BY id ASC");
$preguntasAdicionales = $stmtPreg->fetchAll();

// Procesar Respuestas Enviadas por el Ciudadano
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'responder_encuesta_publica') {
    $candidatoElegido = sanitizeInput($_POST['candidato'] ?? '');
    $respuestasAdicionales = $_POST['preguntas_respuestas'] ?? [];
    
    if (empty($tokenData)) {
        $errorMsg = "El enlace de encuesta no es válido o ha expirado.";
    } elseif ($tokenData['estado'] === 'completada') {
        $msgExito = true;
    } elseif (empty($candidatoElegido)) {
        $errorMsg = "Por favor seleccione su opción de preferencia para continuar.";
    } else {
        // Formatear respuestas adicionales para observaciones
        $obsPartes = [];
        if (!empty($respuestasAdicionales) && is_array($respuestasAdicionales)) {
            foreach ($respuestasAdicionales as $pId => $respVal) {
                $pIdClean = (int)$pId;
                $respClean = sanitizeInput(is_array($respVal) ? implode(', ', $respVal) : $respVal);
                if (!empty($respClean)) {
                    $stmtInfoP = $pdo->prepare("SELECT pregunta FROM preguntas_encuestas WHERE id = ?");
                    $stmtInfoP->execute([$pIdClean]);
                    $pregText = $stmtInfoP->fetchColumn() ?: "Pregunta #$pIdClean";
                    $obsPartes[] = "[$pregText: $respClean]";
                }
            }
        }
        $observacionesFinales = "Respondió vía WhatsApp. " . implode(' ', $obsPartes);

        $pdo->beginTransaction();
        try {
            // 1. Guardar la respuesta en respuestas_encuestas
            $stmtIns = $pdo->prepare("
                INSERT INTO respuestas_encuestas 
                (referido_id, ronda_id, encuestadora_id, candidato_elegido, votante_yopal_respuesta, observaciones, origen_respuesta) 
                VALUES (?, ?, ?, ?, 'Si', ?, 'whatsapp_autoliquidada')
            ");
            $stmtIns->execute([
                $tokenData['referido_id'],
                $tokenData['ronda_id'],
                $tokenData['encuestadora_id'],
                $candidatoElegido,
                $observacionesFinales
            ]);

            // 2. Marcar token como completado
            $stmtUpdToken = $pdo->prepare("
                UPDATE encuestas_tokens_whatsapp 
                SET estado = 'completada', respondido_en = NOW() 
                WHERE token = ?
            ");
            $stmtUpdToken->execute([$token]);

            $pdo->commit();
            $msgExito = true;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errorMsg = "Error al registrar sus respuestas: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta Ciudadana - Firma de Opinión Estelar</title>
    <!-- MDB / Bootstrap 5 & FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/6.4.0/mdb.min.css">
    <style>
        body {
            background-color: #f4f6f9;
            font-family: 'Roboto', sans-serif;
        }
        .public-card {
            border-radius: 16px;
            border: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }
        .option-box {
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            padding: 14px 18px;
            cursor: pointer;
            transition: all 0.2s ease;
            background-color: #ffffff;
        }
        .option-box:hover {
            border-color: #0d6efd;
            background-color: #f8f9fa;
        }
        .btn-check:checked + .option-box {
            border-color: #0d6efd !important;
            background-color: #e7f1ff !important;
            box-shadow: 0 4px 12px rgba(13,110,253,0.2) !important;
        }
        .header-gradient {
            background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 100%);
            border-radius: 16px 16px 0 0;
        }
    </style>
</head>
<body>

<div class="container py-4" style="max-width: 650px;">

    <?php if ($msgExito || (!empty($tokenData) && $tokenData['estado'] === 'completada')): ?>
        <!-- PANTALLA DE ÉXITO Y AGRADECIMIENTO -->
        <div class="card public-card bg-white text-center p-5 mt-4">
            <div class="mb-3 text-success">
                <i class="fas fa-check-circle fa-5x animate__animated animate__bounceIn"></i>
            </div>
            <h2 class="fw-bold text-dark mb-2">¡Muchas Gracias!</h2>
            <p class="text-muted fs-5 mb-4">Tus respuestas se han registrado exitosamente en nuestra consulta de opinión pública local.</p>
            <div class="p-3 bg-light rounded-3 d-inline-block mx-auto border">
                <i class="fas fa-shield-alt text-primary me-2"></i><strong>Firma de Opinión Estelar</strong>
            </div>
        </div>

    <?php elseif (empty($tokenData)): ?>
        <!-- TOKEN NO VÁLIDO O INEXISTENTE -->
        <div class="card public-card bg-white text-center p-5 mt-4">
            <div class="mb-3 text-warning">
                <i class="fas fa-exclamation-circle fa-4x"></i>
            </div>
            <h3 class="fw-bold text-dark mb-2">Enlace no disponible</h3>
            <p class="text-muted">El enlace de la encuesta no es válido o ha expirado. Por favor solicita uno nuevo a tu encuestadora.</p>
        </div>

    <?php else: ?>
        <!-- FORMULARIO PÚBLICO DE ENCUESTA -->
        <div class="card public-card bg-white">
            <div class="header-gradient text-white p-4 text-center">
                <div class="mb-2">
                    <span class="badge bg-warning text-dark px-3 py-2 fw-bold text-uppercase fs-6">
                        <i class="fas fa-poll me-1"></i> Consulta de Opinión Pública
                    </span>
                </div>
                <h3 class="fw-bold mb-1">Firma de Opinión Estelar</h3>
                <p class="mb-0 text-white-50 small">Participación Ciudadana Local</p>
            </div>

            <div class="card-body p-4 p-md-5">

                <?php if (!empty($errorMsg)): ?>
                    <div class="alert alert-danger mb-4">
                        <i class="fas fa-exclamation-triangle me-2"></i><?= e($errorMsg) ?>
                    </div>
                <?php endif; ?>

                <div class="alert alert-info py-2 px-3 mb-4 border-0" style="background-color: #e3f2fd; color: #0d47a1;">
                    <i class="fas fa-user me-2"></i>Hola <strong><?= e($tokenData['nombres']) ?></strong>, agradecemos tu participación voluntaria en esta breve encuesta de 1 minuto:
                </div>

                <form action="encuesta_publica.php?t=<?= urlencode($token) ?>" method="POST">
                    <input type="hidden" name="accion" value="responder_encuesta_publica">

                    <!-- Pregunta Principal de Intención de Voto / Candidato -->
                    <div class="mb-4">
                        <label class="form-label fw-bold text-dark fs-5 mb-3">
                            <i class="fas fa-question-circle text-primary me-2"></i><?= e($preguntaCandidato) ?> *
                        </label>

                        <div class="row g-3">
                            <?php foreach ($candidatos as $c): ?>
                                <div class="col-12">
                                    <input type="radio" class="btn-check" name="candidato" id="c_<?= $c['id'] ?>" value="<?= e($c['nombre']) ?>" required>
                                    <label class="option-box d-flex justify-content-between align-items-center w-100" for="c_<?= $c['id'] ?>">
                                        <div>
                                            <div class="fw-bold text-dark fs-6"><?= e($c['nombre']) ?></div>
                                            <?php if (!empty($c['grupo'])): ?>
                                                <div class="text-muted small"><i class="fas fa-flag text-warning me-1"></i><?= e($c['grupo']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <i class="fas fa-check-circle text-primary opacity-50"></i>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Preguntas Adicionales Configuradas -->
                    <?php if (!empty($preguntasAdicionales)): ?>
                        <hr class="my-4">
                        <?php foreach ($preguntasAdicionales as $p): ?>
                            <div class="mb-4">
                                <label class="form-label fw-bold text-dark fs-6 mb-2">
                                    <i class="fas fa-dot-circle text-info me-2"></i><?= e($p['pregunta']) ?>
                                </label>

                                <?php if ($p['tipo'] === 'opcion_multiple' && !empty($p['opciones'])): ?>
                                    <?php $opts = explode(',', $p['opciones']); ?>
                                    <div class="row g-2">
                                        <?php foreach ($opts as $oIdx => $oVal): 
                                            $oClean = trim($oVal);
                                            $opId = "preg_" . $p['id'] . "_" . $oIdx;
                                        ?>
                                            <div class="col-12">
                                                <input type="radio" class="btn-check" name="preguntas_respuestas[<?= $p['id'] ?>]" id="<?= $opId ?>" value="<?= e($oClean) ?>">
                                                <label class="option-box py-2 px-3 d-block" for="<?= $opId ?>">
                                                    <span class="fw-bold text-dark small"><?= e($oClean) ?></span>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <textarea name="preguntas_respuestas[<?= $p['id'] ?>]" class="form-control" rows="2" placeholder="Escriba su respuesta aquí..."></textarea>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="mt-4 pt-2 text-center">
                        <button type="submit" class="btn btn-primary btn-lg w-100 py-3 fw-bold shadow-0 fs-6">
                            <i class="fas fa-paper-plane me-2"></i>Enviar Mi Respuesta
                        </button>
                    </div>

                </form>

            </div>
        </div>
    <?php endif; ?>

</div>

</body>
</html>
