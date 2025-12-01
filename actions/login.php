<?php
require_once __DIR__ . '/../config/db.php';
session_start();

$usuario = $_POST['usuario'] ?? '';
$pass = $_POST['password'] ?? '';

$stmt = $pdo->prepare('SELECT * FROM usuarios WHERE usuario = :u');
$stmt->execute([':u' => $usuario]);
$user = $stmt->fetch();

if ($user && password_verify($pass, $user['contraseña_hash'])) {
    $_SESSION['usuario'] = $user['usuario'];
    $_SESSION['rol'] = $user['rol'];
    header('Location: ../public/index.php');
} else {
    header('Location: ../public/login.php?error=1');
}
?>
