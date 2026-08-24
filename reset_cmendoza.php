<?php
require_once __DIR__ . '/config/db.php';
$pdo = getDB();
$hash = password_hash('Lider12345!', PASSWORD_BCRYPT, ['cost' => 12]);
$stmt = $pdo->prepare("UPDATE usuarios SET password = ? WHERE username = 'cmendoza'");
$stmt->execute([$hash]);
echo "✅ Password de cmendoza actualizado a Lider12345! en la base de datos de producción.";
