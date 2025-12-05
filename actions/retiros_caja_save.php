<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();

$accion = $_POST['accion'] ?? 'guardar';
$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$redirect = $_POST['redirect'] ?? '../public/retiros_caja.php';

asegurarEsquemaRetirosCaja($pdo);

if ($accion === 'eliminar' && $id > 0) {
    $stmt = $pdo->prepare('DELETE FROM retiros_caja WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $_SESSION['success'] = 'Retiro eliminado correctamente.';
    header('Location: ' . $redirect);
    exit;
}

$fecha = $_POST['fecha'] ?? date('Y-m-d');
$valor = isset($_POST['valor']) ? (float) $_POST['valor'] : 0;
$medio = trim($_POST['medio'] ?? '');
$referencia = trim($_POST['referencia'] ?? '');
$observaciones = trim($_POST['observaciones'] ?? '');
$usuario = $_SESSION['usuario'] ?? null;

$fechaValida = DateTime::createFromFormat('Y-m-d', $fecha) !== false;
if (!$fechaValida || $valor <= 0) {
    $_SESSION['error'] = 'Verifica la fecha y el valor del retiro.';
    header('Location: ' . $redirect);
    exit;
}

registrarRetiroCaja($pdo, [
    'fecha' => $fecha,
    'valor' => $valor,
    'medio' => $medio ?: null,
    'referencia' => $referencia ?: null,
    'observaciones' => $observaciones ?: null,
    'usuario' => $usuario,
]);

$_SESSION['success'] = 'Retiro registrado correctamente.';
header('Location: ' . $redirect);
exit;
