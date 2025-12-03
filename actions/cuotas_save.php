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

sincronizarConceptosPrestamo($pdo);

$accion = $_POST['accion'] ?? 'crear';
$idCuota = isset($_POST['id_cuota']) ? (int) $_POST['id_cuota'] : 0;
$tipoPago = ($_POST['tipo_pago'] ?? 'capital') === 'interes' ? 'interes' : 'capital';

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
$medio = $_POST['medio_consignacion'];

$fechaPagoObj = DateTime::createFromFormat('Y-m-d', $fechaPago);
if (!$fechaPagoObj) {
    $_SESSION['error'] = 'La fecha de pago no es válida.';
    header('Location: ../public/prestamos.php');
    exit;
}

$stmtPrestamo = $pdo->prepare('SELECT * FROM prestamos WHERE id_prestamo = :id');
$stmtPrestamo->execute([':id' => $idPrestamo]);
$prestamo = $stmtPrestamo->fetch();

if (!$prestamo) {
    $_SESSION['error'] = 'No se encontró el préstamo seleccionado.';
    header('Location: ../public/prestamos.php');
    exit;
}

$stmtUltimaCuota = $pdo->prepare('SELECT MAX(fecha_pago) FROM cuotas_prestamo WHERE id_prestamo = :id AND fecha_pago IS NOT NULL');
$stmtUltimaCuota->execute([':id' => $idPrestamo]);
$ultimaFechaPago = $stmtUltimaCuota->fetchColumn();

$fechaReferencia = $ultimaFechaPago ?: $prestamo['fecha_prestamo'];
$fechaRefObj = DateTime::createFromFormat('Y-m-d', $fechaReferencia) ?: clone $fechaPagoObj;
$fechaInicioRef = clone $fechaRefObj;
$fechaInicioRef->modify('first day of this month');

$fechaInicioPago = clone $fechaPagoObj;
$fechaInicioPago->modify('first day of this month');
$diffMeses = ((int) $fechaInicioPago->format('Y') - (int) $fechaInicioRef->format('Y')) * 12
    + ((int) $fechaInicioPago->format('n') - (int) $fechaInicioRef->format('n'));
$mesesPendientes = $ultimaFechaPago ? max(0, $diffMeses) : max(1, $diffMeses + 1);
$interesCausado = round($prestamo['saldo_capital_actual'] * ($prestamo['tasa_interes'] / 100) * $mesesPendientes, 2);

$actividadInteresCausado = obtenerConceptoPorBandera($pdo, 'es_interes_causado');
$actividadCapital = obtenerConceptoPorBandera($pdo, 'es_pago_prestamo');
$actividadInteres = obtenerConceptoPorBandera($pdo, 'es_pago_interes');

if (!$actividadCapital || !$actividadInteres || !$actividadInteresCausado) {
    $_SESSION['error'] = 'No se encontraron conceptos de pago configurados para préstamos.';
    header('Location: ../public/prestamos.php');
    exit;
}

$pendienteInteres = $prestamo['saldo_intereses_actual'] + $interesCausado;
$pendienteTotal = $prestamo['saldo_capital_actual'] + $pendienteInteres;
$intPagado = max(0, $intPagado);
if ($tipoPago === 'interes') {
    $capPagado = 0.0;
    if ($intPagado <= 0) {
        $_SESSION['error'] = 'El pago de intereses debe ser mayor a cero.';
        header('Location: ../public/prestamos.php');
        exit;
    }
} else {
    if ($capPagado <= 0) {
        $_SESSION['error'] = 'El abono a capital debe ser mayor a cero.';
        header('Location: ../public/prestamos.php');
        exit;
    }
}

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
if ($capPagado - $prestamo['saldo_capital_actual'] > 0.01) {
    $_SESSION['error'] = 'El abono a capital no puede superar el saldo pendiente de capital.';
    header('Location: ../public/prestamos.php');
    exit;
}
if ($intPagado - $pendienteInteres > 0.01) {
    $_SESSION['error'] = 'El pago de intereses no puede ser mayor a lo causado y pendiente.';
    header('Location: ../public/prestamos.php');
    exit;
}

$socioMovimiento = $prestamo['es_particular'] ? null : $prestamo['id_socio'];
$nombreAval = null;
if (!empty($prestamo['id_socio_aval'])) {
    $stmtAval = $pdo->prepare('SELECT nombre_completo FROM socios WHERE id_socio = :id');
    $stmtAval->execute([':id' => $prestamo['id_socio_aval']]);
    $nombreAval = $stmtAval->fetchColumn();
}

$observacionBase = $prestamo['es_particular']
    ? sprintf('Pago préstamo a tercero %s (aval: %s)', $prestamo['nombre_deudor'], $nombreAval ?: 'sin aval registrado')
    : 'Pago cuota';

function extraerPeriodos(string $fecha): array {
    $dt = DateTime::createFromFormat('Y-m-d', $fecha);
    return [
        (int) $dt->format('Y'),
        (int) $dt->format('n'),
        (int) ($dt->format('j') <= 15 ? 1 : 2),
    ];
}

[$anioPago, $mesPago, $quincenaPago] = extraerPeriodos($fechaPago);

$registrarMovimiento = function(array $actividad, float $valor, string $motivo, string $observacion, string $fecha, int $anio, int $mes, int $quincena) use ($pdo, $medio, $socioMovimiento) {
    if ($valor <= 0) {
        return;
    }

    $reglaSocio = normalizarReglaAfectacion($actividad['afecta_saldo_socio'] ?? 'neutral');
    $reglaNatillera = normalizarReglaAfectacion($actividad['afecta_saldo_natillera'] ?? 'neutral');
    $esIngreso = (int) ($actividad['es_ingreso'] ?? 0);
    $esEgreso = $esIngreso ? 0 : ($reglaNatillera === 'resta' ? 1 : 0);

    $stmtMov = $pdo->prepare('INSERT INTO movimientos (fecha, anio, mes, quincena, id_socio, id_actividad, motivo, valor, medio_consignacion, es_ingreso, es_egreso, observaciones, usuario_registro, fecha_registro, modulo) VALUES (:fecha, :anio, :mes, :quincena, :id_socio, :id_actividad, :motivo, :valor, :medio, :es_ingreso, :es_egreso, :obs, :usuario, NOW(), :modulo)');
    $stmtMov->execute([
        ':fecha' => $fecha,
        ':anio' => $anio,
        ':mes' => $mes,
        ':quincena' => $quincena,
        ':id_socio' => $socioMovimiento,
        ':id_actividad' => $actividad['id_actividad'],
        ':motivo' => $motivo,
        ':valor' => abs($valor),
        ':medio' => $medio,
        ':obs' => $observacion,
        ':usuario' => $_SESSION['usuario'] ?? null,
        ':modulo' => 'cuotas',
        ':es_ingreso' => $esIngreso,
        ':es_egreso' => $esEgreso,
    ]);

    $socioId = $socioMovimiento ?: null;
    actualizarSaldoSocio($pdo, $socioId, abs($valor), $reglaSocio);
    actualizarSaldoNatillera($pdo, abs($valor), $reglaNatillera);
};

try {
    $pdo->beginTransaction();

    if ($interesCausado > 0) {
        $registrarMovimiento(
            $actividadInteresCausado,
            $interesCausado,
            'Interés causado préstamo #'.$idPrestamo,
            $observacionBase . ($mesesPendientes > 0 ? ' | Causado por ' . $mesesPendientes . ' mes(es)' : ''),
            $fechaPagoObj->format('Y-m-d'),
            $anioPago,
            $mesPago,
            $quincenaPago
        );
    }

    $stmtNext = $pdo->prepare('SELECT COALESCE(MAX(numero_cuota),0)+1 AS prox FROM cuotas_prestamo WHERE id_prestamo=:id AND fecha_pago IS NOT NULL');
    $stmtNext->execute([':id'=>$idPrestamo]);
    $numCuota = (int) $stmtNext->fetchColumn();

    $saldoCapital = max(0, $prestamo['saldo_capital_actual'] - $capPagado);
    $saldoInteres = max(0, $pendienteInteres - $intPagado);

    $stmtCuota = $pdo->prepare('INSERT INTO cuotas_prestamo (id_prestamo, numero_cuota, fecha_pago, valor_capital_pagado, valor_interes_pagado, saldo_capital_despues, saldo_intereses_despues, observaciones) VALUES (:id_prestamo, :num, :fecha_pago, :capital, :interes, :saldo_cap, :saldo_int, :obs)');
    $stmtCuota->execute([
        ':id_prestamo' => $idPrestamo,
        ':num' => $numCuota,
        ':fecha_pago' => $fechaPago,
        ':capital' => $capPagado,
        ':interes' => $intPagado,
        ':saldo_cap' => $saldoCapital,
        ':saldo_int' => $saldoInteres,
        ':obs' => 'Pago cuota manual'
    ]);

    $pdo->prepare('UPDATE prestamos SET saldo_capital_actual=:cap, saldo_intereses_actual=:int WHERE id_prestamo=:id')->execute([
        ':cap' => $saldoCapital,
        ':int' => $saldoInteres,
        ':id' => $idPrestamo
    ]);

    $registrarMovimiento($actividadCapital, $capPagado, 'Pago capital préstamo #'.$idPrestamo, $observacionBase, $fechaPago, $anioPago, $mesPago, $quincenaPago);
    $registrarMovimiento($actividadInteres, $intPagado, 'Pago intereses préstamo #'.$idPrestamo, $observacionBase, $fechaPago, $anioPago, $mesPago, $quincenaPago);

    $nuevoEstado = $saldoCapital > 0 ? 'Vigente' : 'Cancelado';
    $pdo->prepare('UPDATE prestamos SET estado = :estado WHERE id_prestamo = :id')->execute([
        ':estado' => $nuevoEstado,
        ':id' => $idPrestamo,
    ]);

    $pdo->commit();
    recalcularSaldosDesdeMovimientos($pdo);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error'] = 'No se pudo registrar el pago: ' . $e->getMessage();
    header('Location: ../public/prestamos.php');
    exit;
}

header('Location: ../public/prestamos.php');
?>
