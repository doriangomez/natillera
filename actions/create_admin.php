<?php
require_once __DIR__ . '/../config/db.php';

$usuario = 'admin';
$passPlano = 'admin123';
$hash = password_hash($passPlano, PASSWORD_BCRYPT);

$pdo->exec("INSERT INTO usuarios (usuario, contraseña_hash, rol) VALUES ('$usuario', '$hash', 'admin') ON DUPLICATE KEY UPDATE rol='admin'");

echo "Usuario administrador creado: $usuario / $passPlano";
?>
