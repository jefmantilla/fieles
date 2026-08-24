<?php
/**
 * Script Ejecutable de Instalación/Importación de Base de Datos para Producción
 */
require_once __DIR__ . '/config/db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h2>🔧 Ejecutando Instalador de Base de Datos para Producción</h2>";

try {
    $pdo = getDB();
    echo "<p style='color:green;'>✅ Conexión a MySQL exitosa (" . DB_NAME . ")</p>";

    $schemaFile = __DIR__ . '/sql/schema.sql';
    if (!file_exists($schemaFile)) {
        die("<p style='color:red;'>❌ No se encontró el archivo sql/schema.sql</p>");
    }

    $sql = file_get_contents($schemaFile);
    
    // Limpiar comentarios
    $sql = preg_replace('/^--.*$/m', '', $sql);
    $sql = preg_replace('/^\/\*!.*$/m', '', $sql);

    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    $ejecutados = 0;
    $errores = 0;

    foreach ($statements as $stmtSql) {
        if (!empty($stmtSql)) {
            try {
                $pdo->exec($stmtSql);
                $ejecutados++;
            } catch (Exception $e) {
                $errores++;
            }
        }
    }

    echo "<p style='color:blue;'>📊 Sentencias procesadas: $ejecutados exitosas, $errores avisos/omitidas.</p>";

    // Verificar Tablas Instaladas
    $stmtTables = $pdo->query("SHOW TABLES");
    $tablas = $stmtTables->fetchAll(PDO::FETCH_COLUMN);

    echo "<h3>📋 Tablas en " . DB_NAME . ":</h3><ul>";
    foreach ($tablas as $t) {
        $c = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "<li><strong>$t</strong>: $c registros</li>";
    }
    echo "</ul>";

    echo "<h3 style='color:green;'>🎉 ¡Base de Datos Instalada y Lista para Usar!</h3>";
    echo "<p><a href='index.php' style='font-weight:bold; font-size:1.2rem;'>➡️ Ir al Inicio de Sesión (index.php)</a></p>";

} catch (Exception $e) {
    echo "<p style='color:red;'>❌ Error crítico durante la instalación: " . htmlspecialchars($e->getMessage()) . "</p>";
}
