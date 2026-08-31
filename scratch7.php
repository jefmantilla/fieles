<?php
require 'c:\xampp\htdocs\Aplicaiones\fieles\config\db.php';
$pdo = getDB();
$hash = password_hash('123', PASSWORD_DEFAULT);
$stmt = $pdo->prepare("INSERT INTO usuarios (nombre_completo, username, password, role_id) VALUES (?, ?, ?, ?)");
$stmt->execute(['Jefe Supremo', 'jefe', $hash, 1]);
echo "Usuario jefe creado con id: " . $pdo->lastInsertId();
