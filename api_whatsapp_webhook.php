<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/security.php';

$pdo = getDB();

// Obtener Configuración de Meta WhatsApp API
$stmtConfig = $pdo->query("SELECT meta_wa_phone_number_id, meta_wa_access_token, meta_wa_verify_token FROM configuracion_encuestas WHERE id = 1");
$configMeta = $stmtConfig->fetch() ?: [];
$verifyToken = !empty($configMeta['meta_wa_verify_token']) ? $configMeta['meta_wa_verify_token'] : 'fieles_wa_token_123';

// 1. Verificación del Webhook por Meta (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';

    if ($mode === 'subscribe' && $token === $verifyToken) {
        http_response_code(200);
        echo $challenge;
        exit;
    }

    // Modo de Simulación de Prueba Local
    if (isset($_GET['simular_respuesta']) && !empty($_GET['token']) && !empty($_GET['candidato'])) {
        $simToken = sanitizeInput($_GET['token']);
        $simCandidato = sanitizeInput($_GET['candidato']);

        $stmtTok = $pdo->prepare("SELECT * FROM encuestas_tokens_whatsapp WHERE token = ? AND estado = 'enviada'");
        $stmtTok->execute([$simToken]);
        $tokData = $stmtTok->fetch();

        if ($tokData) {
            $pdo->beginTransaction();
            try {
                $stmtIns = $pdo->prepare("
                    INSERT INTO respuestas_encuestas 
                    (referido_id, ronda_id, encuestadora_id, candidato_elegido, votante_yopal_respuesta, observaciones, origen_respuesta) 
                    VALUES (?, ?, ?, ?, 'Si', 'Respondió tocando Botón Interactivo de WhatsApp (Meta API)', 'whatsapp_botones_nativos')
                ");
                $stmtIns->execute([
                    $tokData['referido_id'],
                    $tokData['ronda_id'],
                    $tokData['encuestadora_id'],
                    $simCandidato
                ]);

                $stmtUpdTok = $pdo->prepare("UPDATE encuestas_tokens_whatsapp SET estado = 'completada', respondido_en = NOW() WHERE id = ?");
                $stmtUpdTok->execute([$tokData['id']]);

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Simulación de botón WhatsApp procesada correctamente']);
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Token no encontrado o ya respondido']);
            exit;
        }
    }

    http_response_code(403);
    echo "Fallo de autenticación de Webhook";
    exit;
}

// 2. Recepción de Eventos de Botones Interactivos de WhatsApp (POST de Meta)
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!empty($data['entry'][0]['changes'][0]['value']['messages'][0])) {
    $msgObj = $data['entry'][0]['changes'][0]['value']['messages'][0];
    $fromPhone = preg_replace('/[^0-9]/', '', $msgObj['from'] ?? '');

    // Verificar si es respuesta a Botón Interactivo o Mensaje de Texto
    $candidatoSeleccionado = '';
    if (isset($msgObj['type']) && $msgObj['type'] === 'interactive') {
        if (isset($msgObj['interactive']['button_reply']['title'])) {
            $candidatoSeleccionado = sanitizeInput($msgObj['interactive']['button_reply']['title']);
        } elseif (isset($msgObj['interactive']['list_reply']['title'])) {
            $candidatoSeleccionado = sanitizeInput($msgObj['interactive']['list_reply']['title']);
        }
    } elseif (isset($msgObj['type']) && $msgObj['type'] === 'text') {
        $candidatoSeleccionado = sanitizeInput($msgObj['text']['body'] ?? '');
    }

    if (!empty($fromPhone) && !empty($candidatoSeleccionado)) {
        // Buscar referido por celular
        $celularCorto = substr($fromPhone, -10);
        $stmtRef = $pdo->prepare("SELECT id FROM referidos WHERE celular LIKE ? ORDER BY id DESC LIMIT 1");
        $stmtRef->execute(['%' . $celularCorto]);
        $refId = $stmtRef->fetchColumn();

        if ($refId) {
            // Buscar token activo
            $stmtTok = $pdo->prepare("SELECT * FROM encuestas_tokens_whatsapp WHERE referido_id = ? AND estado = 'enviada' ORDER BY id DESC LIMIT 1");
            $stmtTok->execute([$refId]);
            $tokData = $stmtTok->fetch();

            $rondaId = $tokData['ronda_id'] ?? 1;
            $encuestadoraId = $tokData['encuestadora_id'] ?? 1;

            $pdo->beginTransaction();
            try {
                $stmtIns = $pdo->prepare("
                    INSERT INTO respuestas_encuestas 
                    (referido_id, ronda_id, encuestadora_id, candidato_elegido, votante_yopal_respuesta, observaciones, origen_respuesta) 
                    VALUES (?, ?, ?, ?, 'Si', 'Respuesta recibida por WhatsApp Meta API (Botón/Texto)', 'whatsapp_botones_nativos')
                ");
                $stmtIns->execute([$refId, $rondaId, $encuestadoraId, $candidatoSeleccionado]);

                if ($tokData) {
                    $stmtUpdTok = $pdo->prepare("UPDATE encuestas_tokens_whatsapp SET estado = 'completada', respondido_en = NOW() WHERE id = ?");
                    $stmtUpdTok->execute([$tokData['id']]);
                }

                $pdo->commit();

                // Responder mensaje de agradecimiento si AccessToken está configurado
                if (!empty($configMeta['meta_wa_phone_number_id']) && !empty($configMeta['meta_wa_access_token'])) {
                    $endpointUrl = "https://graph.facebook.com/v18.0/" . $configMeta['meta_wa_phone_number_id'] . "/messages";
                    $replyPayload = [
                        'messaging_product' => 'whatsapp',
                        'to' => $fromPhone,
                        'type' => 'text',
                        'text' => ['body' => '¡Muchas gracias por confirmar tu voto! Tu participación se ha registrado correctamente.']
                    ];
                    
                    $ch = curl_init($endpointUrl);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Authorization: Bearer ' . $configMeta['meta_wa_access_token'],
                        'Content-Type: application/json'
                    ]);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($replyPayload));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_exec($ch);
                    curl_close($ch);
                }

            } catch (Exception $e) {
                $pdo->rollBack();
            }
        }
    }
}

http_response_code(200);
echo "EVENT_RECEIVED";
