<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();

$idPrestamo = (int) $_POST['id_prestamo'];
$fechaPago = $_POST['fecha_pago'];
$capPagado = (float) $_POST['valor_capital_pagado'];
$intPagado = (float) $_POST['valor_interes_pagado'];
$idActividad = (int) $_POST['id_actividad'];
$medio = $_POST['medio_consignacion'];

$stmtNext = $pdo->prepare('SELECT COALESCE(MAX(numero_cuota),0)+1 AS prox FROM cuotas_prestamo WHERE id_prestamo=:id AND fecha_pago IS NOT NULL');
$stmtNext->execute([':id'=>$idPrestamo]);
$numCuota = (int) $stmtNext->fetchColumn();

$stmt = $pdo->prepare('INSERT INTO cuotas_prestamo (id_prestamo, numero_cuota, fecha_pago, valor_capital_pagado, valor_interes_pagado, saldo_capital_despues, saldo_intereses_despues, observaciones) VALUES (:id_prestamo, :num, :fecha_pago, :capital, :interes, 0, 0, :obs)');
$stmt->execute([
    ':id_prestamo' => $idPrestamo,
    ':num' => $numCuota,
    ':fecha_pago' => $fechaPago,
    ':capital' => $capPagado,
    ':interes' => $intPagado,
    ':obs' => 'Pago cuota manual'
]);

$stmtPrestamo = $pdo->prepare('SELECT * FROM prestamos WHERE id_prestamo = :id');
$stmtPrestamo->execute([':id' => $idPrestamo]);
$prestamo = $stmtPrestamo->fetch();

$saldoCapital = max(0, $prestamo['saldo_capital_actual'] - $capPagado);
$saldoInteres = max(0, $prestamo['saldo_intereses_actual'] - $intPagado);

$pdo->prepare('UPDATE prestamos SET saldo_capital_actual=:cap, saldo_intereses_actual=:int WHERE id_prestamo=:id')->execute([
    ':cap' => $saldoCapital,
    ':int' => $saldoInteres,
    ':id' => $idPrestamo
]);

$actividad = getActividad($pdo, $idActividad);
$valorTotal = $capPagado + $intPagado;

$stmtMov = $pdo->prepare('INSERT INTO movimientos (fecha, id_socio, id_actividad, motivo, valor, medio_consignacion, es_ingreso, es_egreso, observaciones, usuario_registro, fecha_registro) VALUES (:fecha, :id_socio, :id_actividad, :motivo, :valor, :medio, 1, 0, :obs, :usuario, NOW())');
$stmtMov->execute([
    ':fecha' => $fechaPago,
    ':id_socio' => $prestamo['id_socio'],
    ':id_actividad' => $idActividad,
    ':motivo' => 'Pago cuota préstamo #'.$idPrestamo,
    ':valor' => $valorTotal,
    ':medio' => $medio,
    ':obs' => 'Pago cuota',
    ':usuario' => $_SESSION['usuario'] ?? null,
]);

actualizarSaldoSocio($pdo, $prestamo['id_socio'], $valorTotal, $actividad['afecta_saldo_socio']);
actualizarSaldoNatillera($pdo, $valorTotal, $actividad['afecta_saldo_natillera']);

header('Location: ../public/prestamos.php');
?>
