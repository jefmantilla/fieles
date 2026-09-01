<?php
require_once 'config/db.php';
$pdo = getDB();

try {
    // 1. Reset all passwords to '123'
    $hash123 = password_hash('123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE usuarios SET password = ?");
    $stmt->execute([$hash123]);
    echo "1. Passwords reset to 123 for ALL users.<br>\n";

    // 2. Load missing tables SQL
    $sql = file_get_contents('missing_tables.sql');
    
    // Remove DROP TABLE IF EXISTS safely
    $sql = preg_replace('/DROP TABLE IF EXISTS `[^`]+`;/', '', $sql);
    
    // Change CREATE TABLE to CREATE TABLE IF NOT EXISTS
    $sql = str_replace('CREATE TABLE `', 'CREATE TABLE IF NOT EXISTS `', $sql);
    
    // Split and execute
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $stmtSql) {
        if (!empty($stmtSql) && !str_starts_with($stmtSql, '/*') && !str_starts_with($stmtSql, '--')) {
            try {
                $pdo->exec($stmtSql);
            } catch (Exception $eStmt) {
                echo "Notice during SQL init: " . $eStmt->getMessage() . "<br>\n";
            }
        }
    }
    
    echo "2. Missing tables (Encuestas, Rondas, etc) synced to production DB.<br>\n";
    echo "<h3>¡LISTO! Ahora puedes iniciar sesión con cualquier usuario y la contraseña es 123.</h3>";
} catch(Exception $e) {
    echo "Error: " . $e->getMessage();
}

@unlink('missing_tables.sql');
@unlink(__FILE__);
