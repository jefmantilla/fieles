<?php
require_once 'config/db.php';
$pdo = getDB();

$sql = file_get_contents('update_usuarios.sql');
$sql = preg_replace('/^--.*$/m', '', $sql);
$sql = preg_replace('/^\/\*!.*$/m', '', $sql);

$statements = array_filter(array_map('trim', explode(';', $sql)));
foreach ($statements as $stmtSql) {
    if (!empty($stmtSql)) {
        try {
            $pdo->exec($stmtSql);
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "<br>";
        }
    }
}
echo "Database updated successfully (Users replaced)!";
unlink('update_usuarios.sql');
unlink(__FILE__);
