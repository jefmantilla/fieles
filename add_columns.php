<?php
require_once 'config/db.php';
$pdo = getDB();

try {
    $columns = [
        "ALTER TABLE configuracion_encuestas ADD COLUMN pregunta_candidato VARCHAR(255) DEFAULT 'Si las votaciones fueran hoy. por que candidato Votaria?'",
        "ALTER TABLE configuracion_encuestas ADD COLUMN guion_llamada TEXT DEFAULT 'Hola, muy buenas tardes...'",
        "ALTER TABLE configuracion_encuestas ADD COLUMN meta_access_token VARCHAR(500) DEFAULT NULL",
        "ALTER TABLE configuracion_encuestas ADD COLUMN meta_phone_id VARCHAR(50) DEFAULT NULL",
        "ALTER TABLE configuracion_encuestas ADD COLUMN wa_webhook_verify_token VARCHAR(100) DEFAULT 'fieles_wa_token_123'",
        "ALTER TABLE referidos ADD COLUMN puesto_votacion VARCHAR(150) DEFAULT NULL",
        "ALTER TABLE referidos ADD COLUMN mesa_votacion VARCHAR(50) DEFAULT NULL"
    ];

    foreach ($columns as $sql) {
        try {
            $pdo->exec($sql);
            echo "Executed: $sql <br>";
        } catch (Exception $e) {
            echo "Notice (already exists?): " . $e->getMessage() . "<br>";
        }
    }
    echo "<h3>¡LISTO! Columnas añadidas.</h3>";
} catch(Exception $e) {
    echo "Error: " . $e->getMessage();
}

@unlink(__FILE__);
