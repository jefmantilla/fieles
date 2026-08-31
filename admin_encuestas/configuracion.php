<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/auth.php';

requireRole('AdminEncuestas');

$admin = getCurrentUser();
$pdo = getDB();
$csrfToken = generateCSRFToken();
$msg = '';
$error = '';

// 1. Crear Nuevo Candidato con Grupo Político
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'crear_candidato') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = "Token de seguridad expirado.";
    } else {
        $nombreCand = sanitizeInput($_POST['nombre_candidato'] ?? '');
        $grupoCand = sanitizeInput($_POST['grupo_candidato'] ?? '');

        if (empty($nombreCand)) {
            $error = "El nombre del candidato es obligatorio.";
        } else {
            $stmtIns = $pdo->prepare("INSERT INTO candidatos_encuestas (nombre, grupo, activo) VALUES (?, ?, 1)");
            if ($stmtIns->execute([$nombreCand, $grupoCand])) {
                $msg = "¡Candidato '" . e($nombreCand) . "' guardado exitosamente!";
            } else {
                $error = "Error al guardar candidato.";
            }
        }
    }
}

// 2. Editar Candidato
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'editar_candidato') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = "Token de seguridad expirado.";
    } else {
        $candId = (int)($_POST['candidato_id'] ?? 0);
        $nombreCand = sanitizeInput($_POST['nombre_candidato'] ?? '');
        $grupoCand = sanitizeInput($_POST['grupo_candidato'] ?? '');

        if (empty($candId) || empty($nombreCand)) {
            $error = "Nombre de candidato obligatorio.";
        } else {
            $stmtUpd = $pdo->prepare("UPDATE candidatos_encuestas SET nombre = ?, grupo = ? WHERE id = ?");
            if ($stmtUpd->execute([$nombreCand, $grupoCand, $candId])) {
                $msg = "¡Candidato modificado correctamente!";
            } else {
                $error = "Error al actualizar candidato.";
            }
        }
    }
}

// 3. Eliminar Candidato
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'eliminar_candidato') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = "Token de seguridad expirado.";
    } else {
        $candId = (int)($_POST['candidato_id'] ?? 0);
        $stmtDel = $pdo->prepare("DELETE FROM candidatos_encuestas WHERE id = ?");
        if ($stmtDel->execute([$candId])) {
            $msg = "¡Candidato eliminado exitosamente!";
        } else {
            $error = "Error al eliminar candidato.";
        }
    }
}

// 4. Cambiar Estado Candidato (Activar/Desactivar)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'toggle_candidato') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = "Token de seguridad expirado.";
    } else {
        $candId = (int)($_POST['candidato_id'] ?? 0);
        $nuevoEstado = (int)($_POST['nuevo_estado'] ?? 1);
        $stmtTog = $pdo->prepare("UPDATE candidatos_encuestas SET activo = ? WHERE id = ?");
        if ($stmtTog->execute([$nuevoEstado, $candId])) {
            $msg = "¡Estado de candidato actualizado!";
        } else {
            $error = "Error al cambiar estado de candidato.";
        }
    }
}

// 5. Guardar Pregunta de la Opción Candidatos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'guardar_pregunta_candidato') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = "Token de seguridad expirado.";
    } else {
        $nuevaPregunta = sanitizeInput($_POST['pregunta_candidato'] ?? '');
        $stmtUpdPreg = $pdo->prepare("UPDATE configuracion_encuestas SET pregunta_candidato = ? WHERE id = 1");
        if ($stmtUpdPreg->execute([$nuevaPregunta])) {
            $msg = "¡Pregunta del bloque de candidatos guardada exitosamente!";
        } else {
            $error = "Error al guardar la pregunta de candidato.";
        }
    }
}

// 6. Guardar Guión / Palabras Oficiales para Llamadas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'guardar_guion_llamada') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = "Token de seguridad expirado.";
    } else {
        $nuevoGuion = sanitizeInput($_POST['guion_llamada'] ?? '');
        $stmtUpdGuion = $pdo->prepare("UPDATE configuracion_encuestas SET guion_llamada = ? WHERE id = 1");
        if ($stmtUpdGuion->execute([$nuevoGuion])) {
            $msg = "¡Guión / Palabras para las llamadas guardadas exitosamente!";
        } else {
            $error = "Error al guardar el guión de llamada.";
        }
    }
}

// 7. Guardar Credenciales Meta WhatsApp API
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'guardar_config_meta') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = "Token de seguridad expirado.";
    } else {
        $metaPhoneId = sanitizeInput($_POST['meta_wa_phone_number_id'] ?? '');
        $metaAccessToken = sanitizeInput($_POST['meta_wa_access_token'] ?? '');
        $metaVerifyToken = sanitizeInput($_POST['meta_wa_verify_token'] ?? '');

        $stmtUpdMeta = $pdo->prepare("
            UPDATE configuracion_encuestas 
            SET meta_wa_phone_number_id = ?, 
                meta_wa_access_token = ?, 
                meta_wa_verify_token = ? 
            WHERE id = 1
        ");
        if ($stmtUpdMeta->execute([$metaPhoneId, $metaAccessToken, $metaVerifyToken])) {
            $msg = "¡Credenciales de Meta WhatsApp Cloud API guardadas exitosamente!";
        } else {
            $error = "Error al guardar credenciales de Meta.";
        }
    }
}

// 8. Crear Nueva Pregunta Adicional
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'crear_pregunta') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = "Token de seguridad expirado.";
    } else {
        $preguntaText = sanitizeInput($_POST['pregunta'] ?? '');
        $tipo = sanitizeInput($_POST['tipo'] ?? 'opcion_multiple');
        $opciones = sanitizeInput($_POST['opciones'] ?? '');

        if (empty($preguntaText)) {
            $error = "El texto de la pregunta es obligatorio.";
        } else {
            $stmtInsP = $pdo->prepare("INSERT INTO preguntas_encuestas (pregunta, tipo, opciones, activo) VALUES (?, ?, ?, 1)");
            if ($stmtInsP->execute([$preguntaText, $tipo, $opciones])) {
                $msg = "¡Pregunta '" . e($preguntaText) . "' guardada exitosamente!";
            } else {
                $error = "Error al guardar pregunta.";
            }
        }
    }
}

// 9. Editar Pregunta
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'editar_pregunta') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = "Token de seguridad expirado.";
    } else {
        $pId = (int)($_POST['pregunta_id'] ?? 0);
        $preguntaText = sanitizeInput($_POST['pregunta'] ?? '');
        $tipo = sanitizeInput($_POST['tipo'] ?? 'opcion_multiple');
        $opciones = sanitizeInput($_POST['opciones'] ?? '');

        if (empty($pId) || empty($preguntaText)) {
            $error = "El texto de la pregunta es obligatorio.";
        } else {
            $stmtUpdP = $pdo->prepare("UPDATE preguntas_encuestas SET pregunta = ?, tipo = ?, opciones = ? WHERE id = ?");
            if ($stmtUpdP->execute([$preguntaText, $tipo, $opciones, $pId])) {
                $msg = "¡Pregunta modificada exitosamente!";
            } else {
                $error = "Error al modificar la pregunta.";
            }
        }
    }
}

// 10. Eliminar Pregunta
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'eliminar_pregunta') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = "Token de seguridad expirado.";
    } else {
        $pId = (int)($_POST['pregunta_id'] ?? 0);
        $stmtDelP = $pdo->prepare("DELETE FROM preguntas_encuestas WHERE id = ?");
        if ($stmtDelP->execute([$pId])) {
            $msg = "¡Pregunta eliminada exitosamente!";
        } else {
            $error = "Error al eliminar la pregunta.";
        }
    }
}

// 11. Cambiar Estado Pregunta (Activar/Desactivar)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'toggle_pregunta') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = "Token de seguridad expirado.";
    } else {
        $pId = (int)($_POST['pregunta_id'] ?? 0);
        $nuevoEstado = (int)($_POST['nuevo_estado'] ?? 1);
        $stmtTogP = $pdo->prepare("UPDATE preguntas_encuestas SET activo = ? WHERE id = ?");
        if ($stmtTogP->execute([$nuevoEstado, $pId])) {
            $msg = "¡Estado de la pregunta actualizado!";
        } else {
            $error = "Error al actualizar estado.";
        }
    }
}

// 12. Guardar URL Externa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'guardar_url_externa') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = "Token de seguridad expirado.";
    } else {
        $urlExt = sanitizeInput($_POST['url_consulta_externa'] ?? '');
        $stmtUpdUrl = $pdo->prepare("UPDATE configuracion_encuestas SET url_consulta_externa = ? WHERE id = 1");
        if ($stmtUpdUrl->execute([$urlExt])) {
            $msg = "¡Enlace del recuadro de apoyo guardado exitosamente!";
        } else {
            $error = "Error al guardar el enlace.";
        }
    }
}

// 13. Lanzar Nueva Ronda
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'lanzar_nueva_ronda') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = "Token de seguridad expirado.";
    } else {
        $stmtMaxRonda = $pdo->query("SELECT MAX(numero_ronda) FROM rondas_encuestas");
        $maxRonda = (int)($stmtMaxRonda->fetchColumn() ?: 0);
        $nuevaRondaNum = $maxRonda + 1;
        $nombreRonda = "Re-Encuesta Ronda #" . $nuevaRondaNum;

        $stmtInsRonda = $pdo->prepare("INSERT INTO rondas_encuestas (numero_ronda, nombre_ronda, creado_por, creado_en) VALUES (?, ?, ?, NOW())");
        if ($stmtInsRonda->execute([$nuevaRondaNum, $nombreRonda, $admin['id']])) {
            $msg = "¡Se ha iniciado exitosamente la " . e($nombreRonda) . " el día " . date('d/m/Y H:i') . "! Las encuestadoras ya pueden comenzar a llamar para el nuevo ciclo.";
        } else {
            $error = "Error al iniciar la nueva ronda de re-encuesta.";
        }
    }
}

// Cargar Consultas de Configuración
$stmtConfigAll = $pdo->query("SELECT * FROM configuracion_encuestas WHERE id = 1");
$configRow = $stmtConfigAll->fetch() ?: [];
$urlExternaActual = !empty($configRow['url_consulta_externa']) ? $configRow['url_consulta_externa'] : '../registro.php?ref=LID-40FEA8AA';
$preguntaCandidato = !empty($configRow['pregunta_candidato']) ? $configRow['pregunta_candidato'] : '¿Por cuál candidato planea votar o cuál fue el resultado de la llamada?';
$guionLlamada = !empty($configRow['guion_llamada']) ? $configRow['guion_llamada'] : "Hola, muy buenas tardes. Mi nombre es Andrea de la firma de opinión Estelar. Nos comunicamos muy brevemente para realizarle un par de preguntas rápidas sobre el desarrollo y futuro de nuestro municipio como parte de un estudio local. ¿Nos concede solo 1 minuto de su tiempo?";
$metaWaPhoneId = $configRow['meta_wa_phone_number_id'] ?? '';
$metaWaAccessToken = $configRow['meta_wa_access_token'] ?? '';
$metaWaVerifyToken = !empty($configRow['meta_wa_verify_token']) ? $configRow['meta_wa_verify_token'] : 'fieles_wa_token_123';

// Lista de Candidatos
$stmtCandidatosList = $pdo->query("SELECT * FROM candidatos_encuestas ORDER BY id ASC");
$listaCandidatos = $stmtCandidatosList->fetchAll();

// Lista de Preguntas
$stmtPreguntasList = $pdo->query("SELECT * FROM preguntas_encuestas ORDER BY id ASC");
$listaPreguntas = $stmtPreguntasList->fetchAll();

// Historial de Rondas
$stmtHistorialRondas = $pdo->query("SELECT r.*, u.nombre_completo as creador_nombre FROM rondas_encuestas r JOIN usuarios u ON r.creado_por = u.id ORDER BY r.numero_ronda DESC");
$listaRondas = $stmtHistorialRondas->fetchAll();
$rondaActiva = $listaRondas[0] ?? ['id' => 1, 'numero_ronda' => 1, 'nombre_ronda' => 'Ronda Inicial #1', 'creado_en' => date('Y-m-d H:i:s')];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración General de Encuestas - Proyecto Político Social</title>
    <!-- Font Awesome & MDB / Bootstrap 5 CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/6.4.0/mdb.min.css">
    <link rel="stylesheet" href="../assets/css/custom.css">
</head>
<body class="bg-light">

<!-- Navbar Compartido AdminEncuestas -->
<?php $activeTab = 'configuracion'; include __DIR__ . '/navbar.php'; ?>

<div class="container-fluid px-4 py-4" style="max-width: 1100px;">

    <!-- Notificaciones -->
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

    <!-- SECCIÓN 1: LANZADOR OFICIAL DE RONDAS DE RE-ENCUESTA -->
    <div class="card card-custom mb-4 border-success shadow-sm">
        <div class="card-header bg-dark text-white p-3">
            <h5 class="fw-bold mb-0"><i class="fas fa-sync-alt me-2 text-success"></i>Control de Rondas de Re-Encuesta Telefónica</h5>
        </div>
        <div class="card-body p-4 bg-white">
            <div class="row align-items-center">
                <div class="col-lg-7 mb-3 mb-lg-0">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="badge bg-success fs-6"><i class="fas fa-play-circle me-1"></i>Ronda #<?= $rondaActiva['numero_ronda'] ?> Activa</span>
                        <span class="text-muted small">Lanzada el <strong><?= date('d/m/Y H:i', strtotime($rondaActiva['creado_en'])) ?></strong></span>
                    </div>
                    <h5 class="fw-bold text-dark mb-1">Reiniciar Ciclo de Llamadas (Nueva Ronda)</h5>
                    <p class="text-muted small mb-0">Al presionar el botón de abajo, se creará oficialmente la <strong>Ronda #<?= $rondaActiva['numero_ronda'] + 1 ?></strong>. Toda la base de afiliados volverá a estar disponible para que las encuestadoras realicen el nuevo seguimiento sin borrar el historial anterior.</p>
                </div>
                <div class="col-lg-5 text-lg-end">
                    <form action="configuracion.php" method="POST" onsubmit="return confirm('⚠️ ¿Está completamente seguro de iniciar una NUEVA RONDA de re-encuesta (#<?= $rondaActiva['numero_ronda'] + 1 ?>)?\n\nEsto habilitará de nuevo a todos los afiliados para que las encuestadoras comiencen las llamadas del nuevo ciclo.');">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="accion" value="lanzar_nueva_ronda">
                        <button type="submit" class="btn btn-success btn-lg fw-bold shadow-0 w-100 py-3">
                            <i class="fas fa-redo-alt me-2"></i> Realizar Re-Encuesta Ahora (Ronda #<?= $rondaActiva['numero_ronda'] + 1 ?>)
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- SECCIÓN 2: GESTIÓN DE CANDIDATOS Y GRUPO POLÍTICO -->
    <div class="card card-custom mb-4 shadow-sm border">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="fw-bold text-primary mb-0"><i class="fas fa-user-tie me-2 text-warning"></i>Gestión de Candidatos y Grupo Político</h5>
                    <p class="text-muted small mb-0">Administra la lista de opciones que verán las encuestadoras al registrar la intención de voto.</p>
                </div>
                <span class="badge bg-dark fs-6"><?= count($listaCandidatos) ?> Opciones Configurada(s)</span>
            </div>

            <!-- Formulario Editar Pregunta del Bloque Candidato -->
            <div class="bg-light p-3 rounded mb-4 border">
                <form action="configuracion.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="accion" value="guardar_pregunta_candidato">
                    <label for="pregunta_candidato" class="form-label fw-bold small text-dark"><i class="fas fa-edit me-1 text-primary"></i>Pregunta del Bloque de Candidatos (Visible en Encuestadoras y Web):</label>
                    <div class="input-group">
                        <input type="text" name="pregunta_candidato" id="pregunta_candidato" class="form-control" placeholder="Ej: ¿Si las votaciones fueran hoy, por cuál candidato votarías?" value="<?= e($preguntaCandidato) ?>" required>
                        <button type="submit" class="btn btn-primary fw-bold shadow-0">
                            <i class="fas fa-save me-1"></i> Guardar Pregunta
                        </button>
                    </div>
                </form>
            </div>

            <!-- Formulario Crear Nuevo Candidato -->
            <form action="configuracion.php" method="POST" class="row g-3 mb-4 bg-light p-3 rounded border">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="accion" value="crear_candidato">

                <div class="col-md-6">
                    <label for="nombre_candidato" class="form-label fw-bold small">Nombre del Candidato u Opción *</label>
                    <input type="text" name="nombre_candidato" id="nombre_candidato" class="form-control" placeholder="Ej: Dr. Juan Pérez" required>
                </div>

                <div class="col-md-4">
                    <label for="grupo_candidato" class="form-label fw-bold small">Grupo / Movimiento Político</label>
                    <input type="text" name="grupo_candidato" id="grupo_candidato" class="form-control" placeholder="Ej: Movimiento Yopal Avanza">
                </div>

                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-success fw-bold w-100 shadow-0">
                        <i class="fas fa-plus me-1"></i> Agregar
                    </button>
                </div>
            </form>

            <!-- Tabla de Candidatos -->
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Nombre del Candidato</th>
                            <th>Grupo / Movimiento</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($listaCandidatos as $idx => $c): ?>
                            <tr>
                                <td><?= $idx + 1 ?></td>
                                <td class="fw-bold text-dark"><?= e($c['nombre']) ?></td>
                                <td>
                                    <?php if (!empty($c['grupo'])): ?>
                                        <span class="badge bg-light text-dark border"><i class="fas fa-flag me-1 text-warning"></i><?= e($c['grupo']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted small">Sin grupo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($c['activo']): ?>
                                        <span class="badge bg-success">Activo</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <button type="button" class="btn btn-outline-primary btn-sm px-2 py-1" 
                                                onclick="prepararEditarCandidato(<?= $c['id'] ?>, '<?= e(addslashes($c['nombre'])) ?>', '<?= e(addslashes($c['grupo'])) ?>')">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form action="configuracion.php" method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="accion" value="toggle_candidato">
                                            <input type="hidden" name="candidato_id" value="<?= $c['id'] ?>">
                                            <input type="hidden" name="nuevo_estado" value="<?= $c['activo'] ? 0 : 1 ?>">
                                            <button type="submit" class="btn btn-outline-<?= $c['activo'] ? 'warning' : 'success' ?> btn-sm px-2 py-1" title="<?= $c['activo'] ? 'Desactivar' : 'Activar' ?>">
                                                <i class="fas fa-<?= $c['activo'] ? 'eye-slash' : 'eye' ?>"></i>
                                            </button>
                                        </form>
                                        <form action="configuracion.php" method="POST" class="d-inline" onsubmit="return confirm('¿Confirma eliminar este candidato?');">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="accion" value="eliminar_candidato">
                                            <input type="hidden" name="candidato_id" value="<?= $c['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm px-2 py-1">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- SECCIÓN 3: GUIÓN OFICIAL PARA ENCUESTADORAS -->
    <div class="card card-custom mb-4 shadow-sm border border-primary">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="fw-bold text-primary mb-0"><i class="fas fa-scroll me-2 text-primary"></i>Guión Oficial / Palabras para las Llamadas (Speech Encuestadoras)</h5>
                    <p class="text-muted small mb-0">Escribe las palabras exactas o el saludo de inicio que las encuestadoras deben leer al llamar a cada ciudadano.</p>
                </div>
                <span class="badge bg-primary fs-6"><i class="fas fa-headset me-1"></i>Visible en Panel Encuestadoras</span>
            </div>

            <form action="configuracion.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="accion" value="guardar_guion_llamada">

                <div class="mb-3">
                    <textarea name="guion_llamada" class="form-control fw-normal" rows="3" style="font-size: 1rem; line-height: 1.5;" placeholder="Escriba aquí el saludo y discurso oficial..." required><?= e($guionLlamada) ?></textarea>
                </div>

                <div class="text-end">
                    <button type="submit" class="btn btn-primary fw-bold px-4 shadow-0">
                        <i class="fas fa-save me-1"></i> Guardar Guión de Llamada
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- SECCIÓN 4: CONFIGURACIÓN META WHATSAPP CLOUD API -->
    <div class="card card-custom mb-4 shadow-sm border border-success">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="fw-bold text-success mb-0"><i class="fab fa-whatsapp me-2 fa-lg text-success"></i>Configuración Meta WhatsApp Cloud API (Exclusivo Administrador)</h5>
                    <p class="text-muted small mb-0">Configura tus llaves oficiales de Meta Developers para activar los botones interactivos nativos dentro del chat de WhatsApp.</p>
                </div>
                <span class="badge bg-success fs-6"><i class="fas fa-lock me-1"></i>Exclusivo AdminEncuestas</span>
            </div>

            <form action="configuracion.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="accion" value="guardar_config_meta">

                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="meta_wa_phone_number_id" class="form-label fw-bold small text-dark"><i class="fas fa-phone-alt me-1 text-success"></i>Phone Number ID (Meta):</label>
                        <input type="text" name="meta_wa_phone_number_id" id="meta_wa_phone_number_id" class="form-control" placeholder="Ej: 109876543210985" value="<?= e($metaWaPhoneId) ?>">
                    </div>

                    <div class="col-md-4">
                        <label for="meta_wa_verify_token" class="form-label fw-bold small text-dark"><i class="fas fa-shield-alt me-1 text-primary"></i>Verify Token (Webhook):</label>
                        <input type="text" name="meta_wa_verify_token" id="meta_wa_verify_token" class="form-control" placeholder="Ej: fieles_wa_token_123" value="<?= e($metaWaVerifyToken) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-bold small text-dark"><i class="fas fa-link me-1 text-info"></i>URL del Webhook (Para Meta Console):</label>
                        <input type="text" class="form-control bg-light" readonly value="<?= (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/Aplicaiones/fieles/api_whatsapp_webhook.php' ?>">
                    </div>

                    <div class="col-12">
                        <label for="meta_wa_access_token" class="form-label fw-bold small text-dark"><i class="fas fa-key me-1 text-warning"></i>Permanent / Temporary Access Token (Meta Graph API):</label>
                        <textarea name="meta_wa_access_token" id="meta_wa_access_token" class="form-control font-monospace" rows="2" placeholder="Pegue aquí el Bearer Access Token de Meta Cloud API..."><?= e($metaWaAccessToken) ?></textarea>
                    </div>
                </div>

                <div class="text-end mt-3">
                    <button type="submit" class="btn btn-success fw-bold px-4 shadow-0">
                        <i class="fas fa-save me-1"></i> Guardar Credenciales Meta API
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- SECCIÓN 5: GESTIÓN DE PREGUNTAS DE LA ENCUESTA TELEFÓNICA -->
    <div class="card card-custom mb-4 shadow-sm border">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="fw-bold text-primary mb-0"><i class="fas fa-question-circle me-2 text-warning"></i>Gestión de Preguntas de la Encuesta Telefónica</h5>
                    <p class="text-muted small mb-0">Configura y administra el cuestionario oficial que las encuestadoras aplicarán durante las llamadas.</p>
                </div>
                <span class="badge bg-dark fs-6"><?= count($listaPreguntas) ?> Pregunta(s) Configurada(s)</span>
            </div>

            <!-- Formulario Crear Pregunta -->
            <form action="configuracion.php" method="POST" class="row g-3 mb-4 bg-light p-3 rounded border">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="accion" value="crear_pregunta">

                <div class="col-md-6">
                    <label for="pregunta" class="form-label fw-bold small">Pregunta o Cuestionamiento *</label>
                    <input type="text" name="pregunta" id="pregunta" class="form-control" placeholder="Ej: ¿Cuáles son las prioridades en su sector?" required>
                </div>

                <div class="col-md-3">
                    <label for="tipo" class="form-label fw-bold small">Tipo de Respuesta *</label>
                    <select name="tipo" id="tipo" class="form-select" onchange="toggleOpcionesInput(this.value, 'opciones_create')">
                        <option value="opcion_multiple">Opción Múltiple</option>
                        <option value="texto_libre">Texto Libre</option>
                    </select>
                </div>

                <div class="col-md-3" id="opciones_create">
                    <label for="opciones" class="form-label fw-bold small">Opciones (Separadas por Coma)</label>
                    <input type="text" name="opciones" id="opciones" class="form-control" placeholder="Ej: Opción 1, Opción 2, Opción 3">
                </div>

                <div class="col-12 text-end">
                    <button type="submit" class="btn btn-success fw-bold px-4 shadow-0">
                        <i class="fas fa-plus me-1"></i> Crear Pregunta
                    </button>
                </div>
            </form>

            <!-- Tabla de Preguntas -->
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Pregunta</th>
                            <th>Tipo</th>
                            <th>Opciones / Configuración</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($listaPreguntas as $pIdx => $p): ?>
                            <tr>
                                <td><?= $pIdx + 1 ?></td>
                                <td class="fw-bold text-dark"><?= e($p['pregunta']) ?></td>
                                <td>
                                    <span class="badge <?= $p['tipo'] === 'opcion_multiple' ? 'bg-primary' : 'bg-info text-dark' ?>">
                                        <?= $p['tipo'] === 'opcion_multiple' ? 'Opción Múltiple' : 'Texto Libre' ?>
                                    </span>
                                </td>
                                <td class="small">
                                    <?php if ($p['tipo'] === 'opcion_multiple' && !empty($p['opciones'])): ?>
                                        <span class="text-muted"><?= e($p['opciones']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted italic">Respuesta abierta del encuestado</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($p['activo']): ?>
                                        <span class="badge bg-success">Activa</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactiva</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <button type="button" class="btn btn-outline-primary btn-sm px-2 py-1" 
                                                onclick="prepararEditarPregunta(<?= $p['id'] ?>, '<?= e(addslashes($p['pregunta'])) ?>', '<?= e($p['tipo']) ?>', '<?= e(addslashes($p['opciones'])) ?>')">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form action="configuracion.php" method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="accion" value="toggle_pregunta">
                                            <input type="hidden" name="pregunta_id" value="<?= $p['id'] ?>">
                                            <input type="hidden" name="nuevo_estado" value="<?= $p['activo'] ? 0 : 1 ?>">
                                            <button type="submit" class="btn btn-outline-<?= $p['activo'] ? 'warning' : 'success' ?> btn-sm px-2 py-1">
                                                <i class="fas fa-<?= $p['activo'] ? 'eye-slash' : 'eye' ?>"></i>
                                            </button>
                                        </form>
                                        <form action="configuracion.php" method="POST" class="d-inline" onsubmit="return confirm('¿Confirma eliminar esta pregunta?');">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="accion" value="eliminar_pregunta">
                                            <input type="hidden" name="pregunta_id" value="<?= $p['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm px-2 py-1">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- SECCIÓN 6: ENLACE DE CONSULTA O REGISTRO DE APOYO -->
    <div class="card card-custom mb-4 border-primary shadow-sm">
        <div class="card-header bg-dark text-white p-3">
            <h5 class="fw-bold mb-0"><i class="fas fa-link me-2 text-primary"></i>Configuración de Enlace de Consulta o Registro de Apoyo</h5>
        </div>
        <div class="card-body p-4 bg-white">
            <form action="configuracion.php" method="POST" class="row align-items-center g-3">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="accion" value="guardar_url_externa">

                <div class="col-12">
                    <label class="form-label fw-bold text-dark mb-1">URL de Consulta de Cédula o Registro Externo para Encuestadoras *</label>
                    <p class="text-muted small mb-2">Ingrese la dirección web completa que abrirán las encuestadoras al hacer clic en el botón de apoyo durante la llamada:</p>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-globe text-primary"></i></span>
                        <input type="url" name="url_consulta_externa" class="form-control" placeholder="http://localhost/Aplicaiones/fieles/registro.php?ref=LID-40FEA8AA" value="<?= e($urlExternaActual) ?>" required>
                        <button type="submit" class="btn btn-primary fw-bold shadow-0 px-4">
                            <i class="fas fa-save me-1"></i> Guardar Enlace
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- SECCIÓN 7: HISTORIAL DE RONDAS LANZADAS -->
    <div class="card card-custom shadow-sm border">
        <div class="card-header bg-dark text-white p-3">
            <h5 class="fw-bold mb-0"><i class="fas fa-history me-2 text-warning"></i>Historial de Rondas de Re-Encuesta Creadas</h5>
        </div>
        <div class="card-body p-4 bg-white">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Ronda #</th>
                            <th>Nombre de la Ronda</th>
                            <th>Fecha y Hora de Lanzamiento</th>
                            <th>Creado Por</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($listaRondas as $r): ?>
                            <tr>
                                <td class="fw-bold">
                                    <span class="badge bg-dark fs-6">Ronda #<?= $r['numero_ronda'] ?></span>
                                </td>
                                <td class="fw-bold text-primary"><?= e($r['nombre_ronda']) ?></td>
                                <td class="small text-muted"><i class="far fa-clock me-1"></i><?= date('d/m/Y H:i', strtotime($r['creado_en'])) ?></td>
                                <td class="small text-dark"><i class="fas fa-user-shield me-1 text-warning"></i><?= e($r['creador_nombre']) ?></td>
                                <td>
                                    <?php if ($r['id'] == $rondaActiva['id']): ?>
                                        <span class="badge bg-success fs-6"><i class="fas fa-check-circle me-1"></i>Ronda Activa en Curso</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><i class="fas fa-archive me-1"></i>Completada / Histórica</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<!-- Modal Editar Candidato -->
<div class="modal fade" id="modalEditarCandidato" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="configuracion.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="accion" value="editar_candidato">
                <input type="hidden" name="candidato_id" id="edit_candidato_id">
                
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i>Editar Candidato / Opción</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="edit_nombre_candidato" class="form-label fw-bold small">Nombre del Candidato *</label>
                        <input type="text" name="nombre_candidato" id="edit_nombre_candidato" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_grupo_candidato" class="form-label fw-bold small">Grupo / Movimiento Político</label>
                        <input type="text" name="grupo_candidato" id="edit_grupo_candidato" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary fw-bold">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Editar Pregunta -->
<div class="modal fade" id="modalEditarPregunta" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="configuracion.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="accion" value="editar_pregunta">
                <input type="hidden" name="pregunta_id" id="edit_pregunta_id">
                
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i>Editar Pregunta Telefónica</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="edit_pregunta" class="form-label fw-bold small">Texto de la Pregunta *</label>
                        <input type="text" name="pregunta" id="edit_pregunta" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_tipo" class="form-label fw-bold small">Tipo de Respuesta *</label>
                        <select name="tipo" id="edit_tipo" class="form-select" onchange="toggleOpcionesInput(this.value, 'edit_opciones_container')">
                            <option value="opcion_multiple">Opción Múltiple</option>
                            <option value="texto_libre">Texto Libre</option>
                        </select>
                    </div>
                    <div class="mb-3" id="edit_opciones_container">
                        <label for="edit_opciones" class="form-label fw-bold small">Opciones (Separadas por Coma)</label>
                        <input type="text" name="opciones" id="edit_opciones" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary fw-bold">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    let modalEditarCandidatoInstancia;
    let modalEditarPreguntaInstancia;

    document.addEventListener('DOMContentLoaded', function() {
        modalEditarCandidatoInstancia = new bootstrap.Modal(document.getElementById('modalEditarCandidato'));
        modalEditarPreguntaInstancia = new bootstrap.Modal(document.getElementById('modalEditarPregunta'));
    });

    function prepararEditarCandidato(id, nombre, grupo) {
        document.getElementById('edit_candidato_id').value = id;
        document.getElementById('edit_nombre_candidato').value = nombre;
        document.getElementById('edit_grupo_candidato').value = grupo;
        modalEditarCandidatoInstancia.show();
    }

    function prepararEditarPregunta(id, pregunta, tipo, opciones) {
        document.getElementById('edit_pregunta_id').value = id;
        document.getElementById('edit_pregunta').value = pregunta;
        document.getElementById('edit_tipo').value = tipo;
        document.getElementById('edit_opciones').value = opciones;
        toggleOpcionesInput(tipo, 'edit_opciones_container');
        modalEditarPreguntaInstancia.show();
    }

    function toggleOpcionesInput(tipoVal, elementId) {
        const elem = document.getElementById(elementId);
        if (elem) {
            elem.style.display = tipoVal === 'opcion_multiple' ? 'block' : 'none';
        }
    }
</script>
</body>
</html>
