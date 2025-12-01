<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();

$fecha = $_POST['fecha_prestamo'];
$idSocio = $_POST['id_socio'] ?: null;
$nombreDeudor = $_POST['nombre_deudor'] ?: null;
$monto = (float) $_POST['monto_prestamo'];
$tasa = (float) $_POST['tasa_interes'];
$cuotas = (int) $_POST['numero_cuotas'];

$stmt = $pdo->prepare('INSERT INTO prestamos (id_socio, nombre_deudor, fecha_prestamo, monto_prestamo, tasa_interes, numero_cuotas, saldo_capital_actual, saldo_intereses_actual, estado) VALUES (:id_socio, :nombre_deudor, :fecha, :monto, :tasa, :cuotas, :saldo_capital_actual, :saldo_intereses_actual, :estado)');
$stmt->execute([
    ':id_socio' => $idSocio,
    ':nombre_deudor' => $nombreDeudor,
    ':fecha' => $fecha,
    ':monto' => $monto,
    ':tasa' => $tasa,
    ':cuotas' => $cuotas,
    ':saldo_capital_actual' => $monto,
    ':saldo_intereses_actual' => 0,
    ':estado' => 'vigente'
]);
$idPrestamo = $pdo->lastInsertId();

$valorCuota = $cuotas > 0 ? $monto / $cuotas : $monto;
for ($i=1; $i<=$cuotas; $i++) {
    $fechaProg = date('Y-m-d', strtotime("+$i month", strtotime($fecha)));
    $stmtCuota = $pdo->prepare('INSERT INTO cuotas_prestamo (id_prestamo, numero_cuota, fecha_programada, valor_cuota, valor_capital_pagado, valor_interes_pagado, saldo_capital_despues, saldo_intereses_despues) VALUES (:id_prestamo, :num, :fecha_prog, :valor_cuota, 0, 0, :saldo_capital, 0)');
    $stmtCuota->execute([
        ':id_prestamo' => $idPrestamo,
        ':num' => $i,
        ':fecha_prog' => $fechaProg,
        ':valor_cuota' => $valorCuota,
        ':saldo_capital' => $monto - ($valorCuota*$i)
    ]);
}

// Registrar movimiento de desembolso si existe actividad marcada como préstamo
$actividadPrestamo = $pdo->query("SELECT id_actividad, afecta_saldo_socio, afecta_saldo_natillera FROM actividades_maestro WHERE es_prestamo=1 LIMIT 1")->fetch();
if ($actividadPrestamo) {
    $stmtMov = $pdo->prepare('INSERT INTO movimientos (fecha, id_socio, id_actividad, motivo, valor, medio_consignacion, es_ingreso, es_egreso, observaciones, usuario_registro, fecha_registro) VALUES (:fecha, :id_socio, :id_actividad, :motivo, :valor, :medio, 0, 1, :obs, :usuario, NOW())');
    $stmtMov->execute([
        ':fecha' => $fecha,
        ':id_socio' => $idSocio,
        ':id_actividad' => $actividadPrestamo['id_actividad'],
        ':motivo' => 'Desembolso préstamo',
        ':valor' => -abs($monto),
        ':medio' => 'Efectivo',
        ':obs' => 'Desembolso inicial',
        ':usuario' => $_SESSION['usuario'] ?? null,
    ]);
    actualizarSaldoSocio($pdo, $idSocio, -abs($monto), $actividadPrestamo['afecta_saldo_socio']);
    actualizarSaldoNatillera($pdo, -abs($monto), $actividadPrestamo['afecta_saldo_natillera']);
}

header('Location: /public/prestamos.php');
?>
