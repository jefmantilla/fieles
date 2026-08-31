<?php
require 'c:\xampp\htdocs\Aplicaiones\fieles\config\db.php';
$pdo = getDB();
print_r($pdo->query('SELECT id, username, role_id FROM usuarios')->fetchAll(PDO::FETCH_ASSOC));
