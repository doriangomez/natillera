<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAdmin();

$usuarioId = isset($_POST['usuario_id']) ? (int) $_POST['usuario_id'] : 0;
$nuevoPassword = trim($_POST['nuevo_password'] ?? '');
$confirmacion = trim($_POST['confirmar_password'] ?? '');

if (!$usuarioId) {
    $_SESSION['error'] = 'No se recibió un usuario válido para actualizar la contraseña.';
    header('Location: ../public/configuracion.php#usuarios');
    exit;
}

if ($nuevoPassword === '' || $confirmacion === '') {
    $_SESSION['error'] = 'Debes ingresar y confirmar la nueva contraseña.';
    header('Location: ../public/configuracion.php#usuarios');
    exit;
}

if ($nuevoPassword !== $confirmacion) {
    $_SESSION['error'] = 'La confirmación no coincide con la nueva contraseña.';
    header('Location: ../public/configuracion.php#usuarios');
    exit;
}

if (strlen($nuevoPassword) < 8) {
    $_SESSION['error'] = 'La contraseña debe tener al menos 8 caracteres.';
    header('Location: ../public/configuracion.php#usuarios');
    exit;
}

$usuario = getUsuarioPorId($pdo, $usuarioId);

if (!$usuario) {
    $_SESSION['error'] = 'El usuario seleccionado no existe.';
    header('Location: ../public/configuracion.php#usuarios');
    exit;
}

try {
    actualizarPasswordUsuario($pdo, $usuarioId, $nuevoPassword);
    $_SESSION['success'] = 'Contraseña actualizada correctamente para el usuario ' . clean($usuario['usuario']) . '.';
} catch (Exception $e) {
    $_SESSION['error'] = 'No se pudo actualizar la contraseña. Intenta de nuevo.';
}

header('Location: ../public/configuracion.php#usuarios');
exit;
