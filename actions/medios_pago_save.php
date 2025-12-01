<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAdmin();

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$nombre = trim($_POST['nombre'] ?? '');
$descripcion = trim($_POST['descripcion'] ?? '');
$activo = isset($_POST['activo']) ? (int) $_POST['activo'] : 1;

if ($id) {
    $stmt = $pdo->prepare('UPDATE medios_pago SET nombre=:n, descripcion=:d, activo=:a WHERE id=:id');
    $stmt->execute([':n'=>$nombre, ':d'=>$descripcion, ':a'=>$activo, ':id'=>$id]);
} else {
    $stmt = $pdo->prepare('INSERT INTO medios_pago (nombre, descripcion, activo) VALUES (:n,:d,:a)');
    $stmt->execute([':n'=>$nombre, ':d'=>$descripcion, ':a'=>$activo]);
}

$redirect = $_POST['redirect'] ?? '../public/configuracion.php';
header('Location: ' . $redirect . '?guardado=1');
exit;
?>
