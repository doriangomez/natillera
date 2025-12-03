<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();

try {
    $existeModulo = $pdo->query("SHOW COLUMNS FROM movimientos LIKE 'modulo'");
    if ($existeModulo && $existeModulo->rowCount() === 0) {
        $pdo->exec("ALTER TABLE movimientos ADD COLUMN modulo VARCHAR(100) DEFAULT NULL");
    }
} catch (Exception $e) {
    // continuar
}

$accion = $_POST['accion'] ?? 'crear';
$idCuota = isset($_POST['id_cuota']) ? (int) $_POST['id_cuota'] : 0;

if ($accion === 'eliminar') {
    try {
        $pdo->beginTransaction();

        $stmtCuota = $pdo->prepare('SELECT * FROM cuotas_prestamo WHERE id_cuota = :id');
        $stmtCuota->execute([':id' => $idCuota]);
        $cuota = $stmtCuota->fetch();

        if (!$cuota) {
            throw new RuntimeException('Abono no encontrado.');
        }

        $stmtPrestamo = $pdo->prepare('SELECT * FROM prestamos WHERE id_prestamo = :id');
        $stmtPrestamo->execute([':id' => $cuota['id_prestamo']]);
        $prestamo = $stmtPrestamo->fetch();

        $valorTotal = (float) $cuota['valor_capital_pagado'] + (float) $cuota['valor_interes_pagado'];
        if (!empty($cuota['fecha_pago'])) {
            $motivo = 'Pago cuota préstamo #' . $cuota['id_prestamo'];
            $sqlDelMov = 'DELETE FROM movimientos WHERE motivo = :motivo AND fecha = :fecha AND ABS(valor - :valor) < 0.01';
            $paramsMov = [
                ':motivo' => $motivo,
                ':fecha' => $cuota['fecha_pago'],
                ':valor' => $valorTotal,
            ];
            if (!empty($prestamo['id_socio'])) {
                $sqlDelMov .= ' AND id_socio = :id_socio';
                $paramsMov[':id_socio'] = $prestamo['id_socio'];
            } else {
                $sqlDelMov .= ' AND id_socio IS NULL';
            }
            $sqlDelMov .= ' LIMIT 1';
            $pdo->prepare($sqlDelMov)->execute($paramsMov);
        }

        $pdo->prepare('DELETE FROM cuotas_prestamo WHERE id_cuota = :id')->execute([':id' => $idCuota]);
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = 'No se pudo eliminar el abono: ' . $e->getMessage();
    }

    header('Location: ../public/prestamos.php');
    exit;
}

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

$pendienteTotal = ($prestamo['saldo_capital_actual'] + $prestamo['saldo_intereses_actual']);
$pagoPropuesto = $capPagado + $intPagado;
if ($pagoPropuesto <= 0) {
    $_SESSION['error'] = 'El pago de préstamo debe ser mayor a cero.';
    header('Location: ../public/prestamos.php');
    exit;
}
if ($pagoPropuesto - $pendienteTotal > 0.01) {
    $_SESSION['error'] = 'No es posible registrar un pago mayor al saldo pendiente del préstamo.';
    header('Location: ../public/prestamos.php');
    exit;
}

$saldoCapital = max(0, $prestamo['saldo_capital_actual'] - $capPagado);
$saldoInteres = max(0, $prestamo['saldo_intereses_actual'] - $intPagado);

$pdo->prepare('UPDATE prestamos SET saldo_capital_actual=:cap, saldo_intereses_actual=:int WHERE id_prestamo=:id')->execute([
    ':cap' => $saldoCapital,
    ':int' => $saldoInteres,
    ':id' => $idPrestamo
]);

$actividad = getActividad($pdo, $idActividad);
$valorTotal = $capPagado + $intPagado;
$reglaSocio = normalizarReglaAfectacion($actividad['afecta_saldo_socio'] ?? 'neutral');
$reglaNatillera = normalizarReglaAfectacion($actividad['afecta_saldo_natillera'] ?? 'neutral');

$stmtMov = $pdo->prepare('INSERT INTO movimientos (fecha, id_socio, id_actividad, motivo, valor, medio_consignacion, es_ingreso, es_egreso, observaciones, usuario_registro, fecha_registro, modulo) VALUES (:fecha, :id_socio, :id_actividad, :motivo, :valor, :medio, 1, 0, :obs, :usuario, NOW(), :modulo)');
$stmtMov->execute([
    ':fecha' => $fechaPago,
    ':id_socio' => $prestamo['es_particular'] ? $prestamo['id_socio_aval'] : $prestamo['id_socio'],
    ':id_actividad' => $idActividad,
    ':motivo' => 'Pago cuota préstamo #'.$idPrestamo,
    ':valor' => $valorTotal,
    ':medio' => $medio,
    ':obs' => 'Pago cuota',
    ':usuario' => $_SESSION['usuario'] ?? null,
    ':modulo' => 'cuotas',
]);

actualizarSaldoSocio($pdo, $prestamo['es_particular'] ? $prestamo['id_socio_aval'] : $prestamo['id_socio'], $valorTotal, $reglaSocio);
actualizarSaldoNatillera($pdo, $valorTotal, $reglaNatillera);

$nuevoEstado = $saldoCapital > 0 ? 'Vigente' : 'Cancelado';
$pdo->prepare('UPDATE prestamos SET estado = :estado WHERE id_prestamo = :id')->execute([
    ':estado' => $nuevoEstado,
    ':id' => $idPrestamo,
]);

header('Location: ../public/prestamos.php');
?>
