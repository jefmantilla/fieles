<?php
require 'c:\xampp\htdocs\Aplicaiones\fieles\config\db.php';
$pdo = getDB();
$stmt = $pdo->query("UPDATE referidos SET puesto_votacion = NULL WHERE puesto_votacion = '[EN PROCESO]'");
echo "Updated: " . $stmt->rowCount() . " rows\n";
