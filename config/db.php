<?php
/**
 * Configuración de Conexión a la Base de Datos mediante PDO
 * Con medidas de alta seguridad para entorno local y producción (Hostinger)
 */

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Lax');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', 1);
    }
    session_start();
}

$isLocal = (in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']) || strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false);

if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: ($isLocal ? 'root' : 'u654004036_fieles_db'));
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: ($isLocal ? '' : 'l@9rw-]7P5:H'));
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: ($isLocal ? 'fieles_db' : 'u654004036_fieles_db'));
if (!defined('DB_PORT')) define('DB_PORT', getenv('DB_PORT') ?: '3306');

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            // Intentar conexión directa con DB_NAME especificada
            try {
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";port=" . DB_PORT . ";charset=utf8mb4";
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $eDirect) {
                // Si la BD no existe localmente, intentar crearla
                $dsnRaw = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
                $pdoRaw = new PDO($dsnRaw, DB_USER, DB_PASS, $options);
                $pdoRaw->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdoRaw->exec("USE `" . DB_NAME . "`");
                $pdo = $pdoRaw;
            }

            // Auto-instalar tablas si no existen
            initTables($pdo);

        } catch (PDOException $e) {
            error_log("Error de conexión a BD: " . $e->getMessage());
            die("Error crítico de conexión a la base de datos. Por favor intente más tarde.");
        }
    }
    return $pdo;
}

function initTables($pdo) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'usuarios'");
        if ($stmt->rowCount() === 0) {
            $schemaFile = __DIR__ . '/../sql/schema.sql';
            if (file_exists($schemaFile)) {
                $sql = file_get_contents($schemaFile);
                
                // Eliminar comentarios SQL de una línea y de bloque
                $sql = preg_replace('/--.*$/m', '', $sql);
                $sql = preg_replace('/\/\*!?[^*]*\*+\//m', '', $sql);
                
                // Dividir en sentencias individuales por punto y coma
                $statements = array_filter(array_map('trim', explode(';', $sql)));
                foreach ($statements as $stmtSql) {
                    if (!empty($stmtSql)) {
                        try {
                            $pdo->exec($stmtSql);
                        } catch (Exception $eStmt) {
                            error_log("Aviso init SQL: " . $eStmt->getMessage());
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error al verificar/inicializar tablas: " . $e->getMessage());
    }
}
