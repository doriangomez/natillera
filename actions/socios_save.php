<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();

$id = $_POST['id_socio'] ?? null;
$accion = $_POST['accion'] ?? 'guardar';

if ($accion === 'inactivar' && $id) {
    $stmt = $pdo->prepare('UPDATE socios SET activo = 0 WHERE id_socio = :id');
    $stmt->execute([':id' => $id]);
    header('Location: ../public/socios.php');
    exit;
}

$data = [
    ':nombre_completo' => $_POST['nombre_completo'],
    ':telefono' => $_POST['telefono'] ?? '',
    ':numero_polla' => $_POST['numero_polla'] ?? '',
    ':periodicidad_pago' => $_POST['periodicidad_pago'] ?? 'mensual',
    ':valor_presupuestado' => $_POST['valor_presupuestado'] ?? 0,
];

if ($id) {
    $data[':id'] = $id;
    $stmt = $pdo->prepare('UPDATE socios SET nombre_completo=:nombre_completo, telefono=:telefono, numero_polla=:numero_polla, periodicidad_pago=:periodicidad_pago, valor_presupuestado=:valor_presupuestado WHERE id_socio=:id');
    $stmt->execute($data);
} else {
    $stmt = $pdo->prepare('INSERT INTO socios (nombre_completo, telefono, numero_polla, periodicidad_pago, valor_presupuestado) VALUES (:nombre_completo, :telefono, :numero_polla, :periodicidad_pago, :valor_presupuestado)');
    $stmt->execute($data);
}
header('Location: ../public/socios.php');
?>
