<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();

$fecha = $_POST['fecha'];
$idSocio = $_POST['id_socio'] ?: null;
$idActividad = (int) $_POST['id_actividad'];
$valor = (float) $_POST['valor'];
$motivo = $_POST['motivo'];
$medio = $_POST['medio_consignacion'] ?? '';
$idMedio = isset($_POST['id_medio_pago']) ? (int) $_POST['id_medio_pago'] : null;
$obs = $_POST['observaciones'] ?? '';

$camposObligatorios = [
    'fecha' => $fecha,
    'actividad' => $idActividad,
    'valor' => $valor,
];
foreach ($camposObligatorios as $campo => $dato) {
    if ($dato === '' || $dato === null) {
        $_SESSION['error'] = 'No se puede guardar el movimiento: falta ' . $campo . '.';
        header('Location: ../public/movimientos.php');
        exit;
    }
}
if ($valor <= 0) {
    $_SESSION['error'] = 'El valor del movimiento debe ser mayor a cero.';
    header('Location: ../public/movimientos.php');
    exit;
}

if (!$medio && $idMedio) {
    $medioInfo = getMedioPago($pdo, $idMedio);
    $medio = $medioInfo['nombre'] ?? '';
}
$medio = trim($medio);
if (!$medio) {
    $_SESSION['error'] = 'Debe seleccionar o registrar un medio de pago.';
    header('Location: ../public/movimientos.php');
    exit;
}

$actividad = getActividad($pdo, $idActividad);
$reglaNatillera = $actividad['afecta_saldo_natillera'] ?? 'neutral';
$esIngreso = $reglaNatillera === 'suma' ? 1 : 0;
$esEgreso = $reglaNatillera === 'resta' ? 1 : 0;

if ($actividad && !empty($actividad['es_polla']) && !$idSocio) {
    $_SESSION['error'] = 'Debe seleccionar un socio para registrar movimientos de polla.';
    header('Location: ../public/movimientos.php');
    exit;
}
if ($actividad && !empty($actividad['es_polla']) && (float) abs($valor) != 20000) {
    $_SESSION['error'] = 'Cada registro de polla debe ser exactamente de $20.000.';
    header('Location: ../public/movimientos.php');
    exit;
}

if ($esEgreso) {
    $valor = -abs($valor);
}

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
