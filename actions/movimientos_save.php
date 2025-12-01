<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();

$fecha = $_POST['fecha'];
$idSocio = $_POST['id_socio'] ?: null;
$idActividad = (int) $_POST['id_actividad'];
$valor = (float) $_POST['valor'];
$tipo = $_POST['tipo_mov'];
$esIngreso = $tipo === 'ingreso' ? 1 : 0;
$esEgreso = $tipo === 'egreso' ? 1 : 0;
if ($esEgreso) { $valor = -abs($valor); }
$motivo = $_POST['motivo'];
$medio = $_POST['medio_consignacion'] ?? '';
$idMedio = isset($_POST['id_medio_pago']) ? (int) $_POST['id_medio_pago'] : null;
$obs = $_POST['observaciones'] ?? '';

if (!$medio && $idMedio) {
    $medioInfo = getMedioPago($pdo, $idMedio);
    $medio = $medioInfo['nombre'] ?? '';
}

$actividad = getActividad($pdo, $idActividad);

$stmt = $pdo->prepare('INSERT INTO movimientos (fecha, id_socio, id_actividad, motivo, valor, medio_consignacion, id_medio_pago, es_ingreso, es_egreso, observaciones, usuario_registro, fecha_registro) VALUES (:fecha, :id_socio, :id_actividad, :motivo, :valor, :medio, :medio_id, :ingreso, :egreso, :obs, :usuario, NOW())');
$stmt->execute([
    ':fecha' => $fecha,
    ':id_socio' => $idSocio,
    ':id_actividad' => $idActividad,
    ':motivo' => $motivo,
    ':valor' => $valor,
    ':medio' => $medio,
    ':medio_id' => $idMedio,
    ':ingreso' => $esIngreso,
    ':egreso' => $esEgreso,
    ':obs' => $obs,
    ':usuario' => $_SESSION['usuario'] ?? null,
]);

actualizarSaldoSocio($pdo, $idSocio, $valor, $actividad['afecta_saldo_socio']);
actualizarSaldoNatillera($pdo, $valor, $actividad['afecta_saldo_natillera']);

header('Location: ../public/movimientos.php');
?>
