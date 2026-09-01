<?php
require_once 'config/db.php';
$pdo = getDB();

try {
    if (!file_exists('sync_bot_data.sql')) {
        die("No se encontró el archivo de datos.");
    }

    $sql = file_get_contents('sync_bot_data.sql');
    $statements = array_filter(array_map('trim', explode(";\n", $sql)));
    
    $count = 0;
    $pdo->beginTransaction();
    foreach ($statements as $stmtSql) {
        if (!empty($stmtSql)) {
            $pdo->exec($stmtSql);
            $count++;
        }
    }
    $pdo->commit();
    
    echo "<h3>¡Éxito! Se sincronizaron $count registros del bot en la base de datos de producción.</h3>";
} catch(Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error: " . $e->getMessage();
}

@unlink('sync_bot_data.sql');
@unlink(__FILE__);
