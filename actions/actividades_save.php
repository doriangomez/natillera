<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();

$id = $_POST['id_actividad'] ?? null;
$data = [
    ':nombre_actividad' => $_POST['nombre_actividad'],
    ':descripcion' => $_POST['descripcion'] ?? '',
    ':afecta_saldo_socio' => $_POST['afecta_saldo_socio'] ?? 'neutral',
    ':afecta_saldo_natillera' => $_POST['afecta_saldo_natillera'] ?? 'neutral',
    ':es_prestamo' => isset($_POST['es_prestamo']) ? 1 : 0,
    ':es_pago_prestamo' => isset($_POST['es_pago_prestamo']) ? 1 : 0,
    ':es_polla' => isset($_POST['es_polla']) ? 1 : 0,
    ':es_gasto_general' => isset($_POST['es_gasto_general']) ? 1 : 0,
];

if ($id) {
    $data[':id'] = $id;
    $sql = 'UPDATE actividades_maestro SET nombre_actividad=:nombre_actividad, descripcion=:descripcion, afecta_saldo_socio=:afecta_saldo_socio, afecta_saldo_natillera=:afecta_saldo_natillera, es_prestamo=:es_prestamo, es_pago_prestamo=:es_pago_prestamo, es_polla=:es_polla, es_gasto_general=:es_gasto_general WHERE id_actividad=:id';
} else {
    $sql = 'INSERT INTO actividades_maestro (nombre_actividad, descripcion, afecta_saldo_socio, afecta_saldo_natillera, es_prestamo, es_pago_prestamo, es_polla, es_gasto_general) VALUES (:nombre_actividad, :descripcion, :afecta_saldo_socio, :afecta_saldo_natillera, :es_prestamo, :es_pago_prestamo, :es_polla, :es_gasto_general)';
}
$stmt = $pdo->prepare($sql);
$stmt->execute($data);
header('Location: /public/actividades.php');
?>
