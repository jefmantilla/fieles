<?php
require_once 'config/db.php';
$pdo = getDB();

try {
    $stmt = $pdo->prepare("INSERT IGNORE INTO usuarios (nombre_completo, cedula, telefono, username, password, role_id, codigo_referido) VALUES ('Jefe Supremo', '00000000', '0000000000', 'jefe', '$2y$10$YLtfhrOKgA.u.SU.wngavuLxo/JP829pcU9kSR7hOWqwxQ.8t0b2m', 1, 'JEFE-001')");
    $stmt->execute();
    echo "User jefe created!";
} catch(Exception $e) {
    echo "Error: " . $e->getMessage();
}

unlink(__FILE__);
