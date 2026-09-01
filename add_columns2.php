<?php
require_once 'config/db.php';
$pdo = getDB();

try {
    $columns = [
        "ALTER TABLE configuracion_encuestas DROP COLUMN meta_access_token",
        "ALTER TABLE configuracion_encuestas DROP COLUMN meta_phone_id",
        "ALTER TABLE configuracion_encuestas DROP COLUMN wa_webhook_verify_token",
        "ALTER TABLE configuracion_encuestas ADD COLUMN meta_wa_phone_number_id VARCHAR(100) DEFAULT NULL",
        "ALTER TABLE configuracion_encuestas ADD COLUMN meta_wa_access_token TEXT DEFAULT NULL",
        "ALTER TABLE configuracion_encuestas ADD COLUMN meta_wa_verify_token VARCHAR(100) DEFAULT 'fieles_wa_token_123'"
    ];

    foreach ($columns as $sql) {
        try {
            $pdo->exec($sql);
            echo "Executed: $sql <br>";
        } catch (Exception $e) {
            echo "Notice: " . htmlspecialchars($e->getMessage()) . "<br>";
        }
    }
    echo "<h3>¡LISTO! Columnas corregidas.</h3>";
} catch(Exception $e) {
    echo "Error: " . $e->getMessage();
}

@unlink(__FILE__);
