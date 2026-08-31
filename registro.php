<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/security.php';

setSecurityHeaders();

$refCode = sanitizeInput($_GET['ref'] ?? $_POST['ref_code'] ?? '');
$invitadorNombre = '';
$invitadorTipo = '';
$invitadorId = 0;
$liderRaizId = 0;
$esValidoRef = false;

$pdo = getDB();

if (!empty($refCode)) {
    // 1. Buscar en la tabla de usuarios (Líderes o Admin)
    $stmtU = $pdo->prepare("SELECT id, nombre_completo FROM usuarios WHERE codigo_referido = ?");
    $stmtU->execute([$refCode]);
    $userRef = $stmtU->fetch();

    if ($userRef) {
        $invitadorNombre = $userRef['nombre_completo'];
        $invitadorTipo = 'usuario';
        $invitadorId = $userRef['id'];
        $liderRaizId = $userRef['id'];
        $esValidoRef = true;
    } else {
        // 2. Buscar en la tabla de referidos (Multinivel)
        $stmtR = $pdo->prepare("SELECT id, nombres, apellidos, lider_raiz_id FROM referidos WHERE codigo_referido = ?");
        $stmtR->execute([$refCode]);
        $refRef = $stmtR->fetch();

        if ($refRef) {
            $invitadorNombre = $refRef['nombres'] . ' ' . $refRef['apellidos'];
            $invitadorTipo = 'referido';
            $invitadorId = $refRef['id'];
            $liderRaizId = $refRef['lider_raiz_id'];
            $esValidoRef = true;
        }
    }
}

$error = '';
$exito = false;
$csrfToken = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = "Sesión de formulario expirada. Por favor intente de nuevo.";
    } else if (!$esValidoRef) {
        $error = "Enlace de referido no válido.";
    } else {
        // Sanitizar campos
        $cedula = sanitizeInput($_POST['cedula'] ?? '');
        $nombres = sanitizeInput($_POST['nombres'] ?? '');
        $apellidos = sanitizeInput($_POST['apellidos'] ?? '');
        $correo = sanitizeInput($_POST['correo'] ?? '');
        $celular = sanitizeInput($_POST['celular'] ?? '');
        $comuna = sanitizeInput($_POST['comuna'] ?? '');
        $votanteYopal = sanitizeInput($_POST['votante_yopal'] ?? 'Si');
        $autorizacion = isset($_POST['autorizacion_datos']) ? 1 : 0;

        // Validaciones Estrictas en Backend
        if (empty($cedula) || empty($nombres) || empty($apellidos) || empty($correo) || empty($celular) || empty($comuna)) {
            $error = "Por favor diligencie todos los campos requeridos.";
        } else if (!preg_match('/^[0-9]{6,12}$/', $cedula)) {
            $error = "La Cédula de Ciudadanía debe contener únicamente números (mínimo 6 y máximo 12 dígitos).";
        } else if (!preg_match('/^[0-9]{10}$/', $celular)) {
            $error = "El número de Celular debe contener exactamente 10 dígitos numéricos.";
        } else if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $error = "El Correo Electrónico no es válido (debe contener '@' y un dominio correcto).";
        } else if ($autorizacion !== 1) {
            $error = "Debe autorizar el tratamiento de datos personales para continuar.";
        } else {
            // 1. Verificar si la Cédula ya está registrada (en referidos o en usuarios)
            $stmtCheckCedulaRef = $pdo->prepare("SELECT COUNT(*) FROM referidos WHERE cedula = ?");
            $stmtCheckCedulaRef->execute([$cedula]);

            $stmtCheckCedulaUser = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE cedula = ?");
            $stmtCheckCedulaUser->execute([$cedula]);

            if ($stmtCheckCedulaRef->fetchColumn() > 0 || $stmtCheckCedulaUser->fetchColumn() > 0) {
                $error = "La cédula ingresada ya se encuentra registrada en el sistema y figura como referida a otra persona.";
            } else {
                // 2. Verificar si el Celular ya está registrado
                $stmtCheckCelular = $pdo->prepare("SELECT COUNT(*) FROM referidos WHERE celular = ? AND celular != ''");
                $stmtCheckCelular->execute([$celular]);

                if ($stmtCheckCelular->fetchColumn() > 0) {
                    $error = "El número de celular ingresado ya se encuentra registrado y asignado a otra persona en nuestro sistema.";
                } else {
                    // Generar nuevo código único para el registrado
                    $nuevoCodigo = generateUniqueCode('REF');
                    $ipRegistro = $_SERVER['REMOTE_ADDR'] ?? '';
                    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                    $hashDispositivo = hash('sha256', $ipRegistro . $userAgent);

                    // Insertar en Base de Datos
                    $stmtInsert = $pdo->prepare("INSERT INTO referidos (cedula, nombres, apellidos, correo, celular, comuna, votante_yopal, referido_por_tipo, referido_por_id, lider_raiz_id, autorizacion_datos, ip_registro, hash_dispositivo, codigo_referido) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    
                    $result = $stmtInsert->execute([
                        $cedula, $nombres, $apellidos, $correo, $celular, $comuna, $votanteYopal,
                        $invitadorTipo, $invitadorId, $liderRaizId, $autorizacion,
                        $ipRegistro, $hashDispositivo, $nuevoCodigo
                    ]);

                    if ($result) {
                        $exito = true;
                    } else {
                        $error = "Ocurrió un problema al guardar el registro. Por favor intente más tarde.";
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formulario de Vinculación - Proyecto Político Social</title>
    <!-- Font Awesome & MDB / Bootstrap 5 CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/6.4.0/mdb.min.css">
    <link rel="stylesheet" href="assets/css/custom.css">
</head>
<body>

<div class="brand-header text-center">
    <div class="container">
        <h2 class="fw-bold"><i class="fas fa-handshake me-2"></i>Proyecto Político Social</h2>
        <p class="mb-0 fs-6">Unidos por el desarrollo y bienestar de Yopal</p>
    </div>
</div>

<div class="container mb-5" style="max-width: 600px;" id="contenedorFormulario">

    <?php if (!$esValidoRef): ?>
        <div class="card card-custom text-center p-4">
            <div class="card-body">
                <div class="text-danger mb-3">
                    <i class="fas fa-qrcode fa-5x"></i>
                </div>
                <h4 class="fw-bold text-danger">Código QR o Enlace Inválido</h4>
                <p class="text-muted">El código de referido escaneado no es válido o ha sido modificado. Escanee nuevamente el código QR proporcionado por su líder o referidor.</p>
            </div>
        </div>
    <?php elseif ($exito): ?>
        <div class="card card-custom text-center p-4">
            <div class="card-body">
                <div class="text-success mb-3">
                    <i class="fas fa-check-circle fa-5x"></i>
                </div>
                <h3 class="fw-bold text-success mb-3">¡Registro Completado Exitosamente!</h3>
                <p class="fs-5 text-dark fw-bold">Gracias por ser parte de este proyecto político social.</p>
                <p class="text-muted mb-4">El registro ha sido vinculado correctamente a la red de <strong><?= e($invitadorNombre) ?></strong>.</p>
                
                <div class="d-grid gap-2">
                    <a href="registro.php?ref=<?= urlencode($refCode) ?>" class="btn btn-primary btn-lg btn-primary-custom">
                        <i class="fas fa-user-plus me-2"></i> Registrar a Otra Persona
                    </a>
                </div>
            </div>
        </div>
        <script src="assets/js/autofill.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                limpiarBorradores();
            });
        </script>
    <?php else: ?>

        <div class="card card-custom">
            <div class="card-body p-4">
                <h4 class="card-title fw-bold text-center mb-1 text-primary">Formulario de Registro</h4>
                <p class="text-center text-muted small mb-4">Diligencie los datos de la persona a vincular</p>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?= e($error) ?>
                    </div>
                <?php endif; ?>

                <form action="registro.php?ref=<?= e($refCode) ?>" method="POST" id="formRegistroReferido">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="ref_code" value="<?= e($refCode) ?>">

                    <!-- Cédula (Solo Números, Máx 12) -->
                    <div class="mb-4">
                        <label class="form-label fw-bold small text-muted mb-1" for="cedula"><i class="fas fa-id-card me-2 text-primary"></i>Cédula de Ciudadanía *</label>
                        <input type="text" id="cedula" name="cedula" class="form-control form-control-lg" required 
                               inputmode="numeric" maxlength="12" pattern="[0-9]{6,12}" 
                               oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                               placeholder="Solo números (Ej. 1118123456)" />
                        <small class="text-muted">Ingrese únicamente números sin puntos ni comas.</small>
                    </div>

                    <!-- Nombres -->
                    <div class="mb-4">
                        <label class="form-label fw-bold small text-muted mb-1" for="nombres"><i class="fas fa-user me-2 text-primary"></i>Nombres *</label>
                        <input type="text" id="nombres" name="nombres" class="form-control form-control-lg" required placeholder="Ej. Juan Carlos" />
                    </div>

                    <!-- Apellidos -->
                    <div class="mb-4">
                        <label class="form-label fw-bold small text-muted mb-1" for="apellidos"><i class="fas fa-user me-2 text-primary"></i>Apellidos *</label>
                        <input type="text" id="apellidos" name="apellidos" class="form-control form-control-lg" required placeholder="Ej. Pérez Gómez" />
                    </div>

                    <!-- Correo Electrónico (Debe incluir @) -->
                    <div class="mb-4">
                        <label class="form-label fw-bold small text-muted mb-1" for="correo"><i class="fas fa-envelope me-2 text-primary"></i>Correo Electrónico *</label>
                        <input type="email" id="correo" name="correo" class="form-control form-control-lg" required placeholder="ejemplo@correo.com" />
                        <small class="text-muted">Debe incluir el símbolo '@' y un correo válido.</small>
                    </div>

                    <!-- Celular (Solo Números, Exacto 10 Dígitos) -->
                    <div class="mb-4">
                        <label class="form-label fw-bold small text-muted mb-1" for="celular"><i class="fas fa-mobile-alt me-2 text-primary"></i>Número de Celular *</label>
                        <input type="tel" id="celular" name="celular" class="form-control form-control-lg" required 
                               inputmode="numeric" maxlength="10" pattern="[0-9]{10}" 
                               oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                               placeholder="10 dígitos (Ej. 3101234567)" />
                        <small class="text-muted">Ingrese exactamente 10 dígitos numéricos.</small>
                    </div>

                    <!-- Comuna o Corregimiento donde vive -->
                    <div class="mb-4">
                        <label for="comuna" class="form-label fw-bold small text-muted mb-1"><i class="fas fa-home me-2 text-primary"></i>Comuna o Corregimiento donde vive *</label>
                        <select class="form-select form-select-lg" id="comuna" name="comuna" required>
                            <option value="" disabled selected>-- Seleccione la Comuna o Corregimiento donde vive --</option>
                            <optgroup label="Comunas Urbanas de Yopal">
                                <option value="Comuna 1">Comuna 1 - El Hobo</option>
                                <option value="Comuna 2">Comuna 2 - Viveros</option>
                                <option value="Comuna 3">Comuna 3 - Clelia Rivero de Bonilla</option>
                                <option value="Comuna 4">Comuna 4 - Campiña</option>
                                <option value="Comuna 5">Comuna 5 - Villa del Sol</option>
                                <option value="Comuna 6">Comuna 6 - Llano Lindo</option>
                            </optgroup>
                            <optgroup label="Corregimientos / Zona Rural de Yopal">
                                <option value="Corregimiento El Morro">Corregimiento El Morro</option>
                                <option value="Corregimiento La Chaparrera">Corregimiento La Chaparrera</option>
                                <option value="Corregimiento Tilodirán">Corregimiento Tilodirán</option>
                                <option value="Corregimiento Quebradaseca">Corregimiento Quebradaseca</option>
                                <option value="Corregimiento Punto Nuevo">Corregimiento Punto Nuevo</option>
                                <option value="Corregimiento El Taladro">Corregimiento El Taladro</option>
                                <option value="Corregimiento Tacarimena">Corregimiento Tacarimena</option>
                                <option value="Corregimiento La Niata">Corregimiento La Niata</option>
                                <option value="Corregimiento La Guafilla">Corregimiento La Guafilla</option>
                                <option value="Corregimiento Mata de Limón">Corregimiento Mata de Limón</option>
                                <option value="Corregimiento El Charte">Corregimiento El Charte</option>
                            </optgroup>
                        </select>
                    </div>

                    <!-- ¿Votante en Yopal? -->
                    <div class="mb-4">
                        <label for="votante_yopal" class="form-label fw-bold small text-muted mb-1"><i class="fas fa-vote-yea me-2 text-primary"></i>¿Es Votante en Yopal?</label>
                        <select class="form-select form-select-lg" id="votante_yopal" name="votante_yopal" required>
                            <option value="Si" selected>Sí, voto en Yopal</option>
                            <option value="Quiero inscribir">Quiero inscribir mi cédula en Yopal</option>
                            <option value="No">No, voto en otro municipio</option>
                        </select>
                    </div>

                    <!-- Persona que lo referencia (Invitador) -->
                    <div class="referral-box">
                        <span class="text-muted d-block small uppercase fw-bold">Persona que lo referencia:</span>
                        <span class="fs-5 fw-bold text-dark"><i class="fas fa-user-check text-primary me-2"></i><?= e($invitadorNombre) ?></span>
                    </div>

                    <!-- Autorización de Tratamiento de Datos -->
                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" value="1" id="autorizacion_datos" name="autorizacion_datos" required checked />
                        <label class="form-check-label small text-muted" for="autorizacion_datos">
                            Autorizo el tratamiento de mis datos personales de acuerdo con las leyes vigentes para fines exclusivos de este proyecto político social.
                        </label>
                    </div>

                    <!-- Botón Enviar -->
                    <button type="submit" class="btn btn-primary btn-block btn-lg btn-primary-custom shadow-0">
                        <i class="fas fa-paper-plane me-2"></i> Enviar Registro
                    </button>
                </form>
            </div>
        </div>

    <?php endif; ?>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/6.4.0/mdb.min.js"></script>
<script src="assets/js/autofill.js"></script>
</body>
</html>
