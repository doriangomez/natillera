<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAdmin();

$nombreUsuario = trim($_POST['usuario'] ?? '');
$password = trim($_POST['password'] ?? '');
$confirmacion = trim($_POST['confirmar_password'] ?? '');
$rol = trim($_POST['rol'] ?? 'admin');

if ($nombreUsuario === '' || $password === '' || $confirmacion === '') {
    $_SESSION['error'] = 'Debes ingresar el usuario y la contraseña.';
    header('Location: ../public/configuracion.php#usuarios');
    exit;
}

if (strlen($nombreUsuario) < 3 || strlen($nombreUsuario) > 50) {
    $_SESSION['error'] = 'El usuario debe tener entre 3 y 50 caracteres.';
    header('Location: ../public/configuracion.php#usuarios');
    exit;
}

if ($password !== $confirmacion) {
    $_SESSION['error'] = 'La confirmación de contraseña no coincide.';
    header('Location: ../public/configuracion.php#usuarios');
    exit;
}

if (strlen($password) < 8) {
    $_SESSION['error'] = 'La contraseña debe tener al menos 8 caracteres.';
    header('Location: ../public/configuracion.php#usuarios');
    exit;
}

$rolesPermitidos = ['admin'];
if (!in_array($rol, $rolesPermitidos, true)) {
    $_SESSION['error'] = 'El rol seleccionado no es válido.';
    header('Location: ../public/configuracion.php#usuarios');
    exit;
}

$usuarioExistente = getUsuarioPorNombre($pdo, $nombreUsuario);
if ($usuarioExistente) {
    $_SESSION['error'] = 'Ya existe una cuenta con ese nombre de usuario.';
    header('Location: ../public/configuracion.php#usuarios');
    exit;
}

try {
    crearUsuario($pdo, $nombreUsuario, $password, $rol);
    $_SESSION['success'] = 'Usuario creado correctamente.';
} catch (Exception $e) {
    $_SESSION['error'] = 'No fue posible crear el usuario. Intenta nuevamente.';
}

header('Location: ../public/configuracion.php#usuarios');
exit;
