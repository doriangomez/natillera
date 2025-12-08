<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

$usuario = $_POST['usuario'] ?? '';
$pass = $_POST['password'] ?? '';

$stmt = $pdo->prepare('SELECT * FROM usuarios WHERE usuario = :u');
$stmt->execute([':u' => $usuario]);
$user = $stmt->fetch();

if ($user && password_verify($pass, $user['contraseña_hash'])) {
    session_regenerate_id(true);
    $_SESSION['usuario'] = $user['usuario'];
    $_SESSION['rol'] = $user['rol'];
    $_SESSION['last_activity'] = time();
    header('Location: ../public/index.php');
    exit;
} else {
    header('Location: ../public/login.php?error=1');
    exit;
}
?>
