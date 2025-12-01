<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAdmin();

$id = isset($_POST['id_actividad']) ? (int) $_POST['id_actividad'] : 0;
$estado = isset($_POST['estado']) ? (int) $_POST['estado'] : 1;
$redirect = $_POST['redirect'] ?? '../public/configuracion.php';

if ($id > 0) {
    $stmt = $pdo->prepare('UPDATE actividades_maestro SET activo = :estado WHERE id_actividad = :id');
    $stmt->execute([
        ':estado' => $estado,
        ':id' => $id,
    ]);
}

header('Location: ' . $redirect);
?>
