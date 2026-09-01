<?php
require_once 'config/db.php';
$pdo = getDB();

try {
    $sql = file_get_contents('missing_tables.sql');
    
    // Remove DROP TABLE IF EXISTS safely
    $sql = preg_replace('/DROP TABLE IF EXISTS `[^`]+`;/', '', $sql);
    
    // Change CREATE TABLE to CREATE TABLE IF NOT EXISTS
    $sql = str_replace('CREATE TABLE `', 'CREATE TABLE IF NOT EXISTS `', $sql);
    
    // Remove lock/unlock
    $sql = preg_replace('/LOCK TABLES `[^`]+` WRITE;/', '', $sql);
    $sql = preg_replace('/UNLOCK TABLES;/', '', $sql);

    // Split and execute
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    $success = true;
    foreach ($statements as $stmtSql) {
        if (!empty($stmtSql) && !str_starts_with($stmtSql, '/*') && !str_starts_with($stmtSql, '--')) {
            try {
                $pdo->exec($stmtSql);
            } catch (Exception $eStmt) {
                echo "Notice during SQL init: " . htmlspecialchars($eStmt->getMessage()) . "<br>\n";
                $success = false;
            }
        }
    }
    
    if ($success) {
        echo "2. Missing tables (Encuestas, Rondas, etc) synced to production DB successfully!<br>\n";
        echo "<h3>¡LISTO! Ahora SI están las tablas creadas. Puedes iniciar sesión y todo funcionará.</h3>";
        @unlink('missing_tables.sql');
        @unlink(__FILE__);
    } else {
        echo "<h3>Hubo errores al crear las tablas.</h3>";
    }
} catch(Exception $e) {
    echo "Error: " . $e->getMessage();
}
