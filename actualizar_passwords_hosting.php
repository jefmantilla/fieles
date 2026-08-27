<?php
require_once __DIR__ . '/config/db.php';
$pdo = getDB();

$passAdmin = 'AdminPass2026!';
$passAdminEnc = 'AdminEnc2026!';
$passLider = 'LiderPass2026!';
$passEncuestadora = 'Encuestadora2026!';

$hashAdmin = password_hash($passAdmin, PASSWORD_BCRYPT, ['cost' => 12]);
$hashAdminEnc = password_hash($passAdminEnc, PASSWORD_BCRYPT, ['cost' => 12]);
$hashLider = password_hash($passLider, PASSWORD_BCRYPT, ['cost' => 12]);
$hashEncuestadora = password_hash($passEncuestadora, PASSWORD_BCRYPT, ['cost' => 12]);

$pdo->prepare("UPDATE usuarios SET password = ? WHERE username = 'admin'")->execute([$hashAdmin]);
$pdo->prepare("UPDATE usuarios SET password = ? WHERE username = 'adminencuestas'")->execute([$hashAdminEnc]);
$pdo->prepare("UPDATE usuarios SET password = ? WHERE role_id = 2")->execute([$hashLider]);
$pdo->prepare("UPDATE usuarios SET password = ? WHERE role_id = 4")->execute([$hashEncuestadora]);

echo "✅ Contraseñas actualizadas con éxito en la base de datos de producción (Hostinger).";
