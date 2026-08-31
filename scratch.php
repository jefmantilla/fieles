<?php
require 'c:\xampp\htdocs\Aplicaiones\fieles\config\db.php';
$pdo = getDB();
print_r($pdo->query('SELECT * FROM rondas_encuestas')->fetchAll(PDO::FETCH_ASSOC));
