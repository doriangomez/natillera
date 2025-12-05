<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();

try {
    asegurarTablaPeriodosPrestamo($pdo);
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
$anioPago = isset($_POST['anio']) ? (int) $_POST['anio'] : 0;
$mesPago = isset($_POST['mes']) ? (int) $_POST['mes'] : 0;
$capPagado = (float) $_POST['valor_capital_pagado'];
$intPagado = (float) $_POST['valor_interes_pagado'];
$medio = $_POST['medio_consignacion'];

$fechaPagoObj = DateTime::createFromFormat('Y-m-d', $fechaPago);
if (!$fechaPagoObj) {
    $_SESSION['error'] = 'La fecha de pago no es válida.';
    header('Location: ../public/prestamos.php');
    exit;
}
$anioFecha = (int) $fechaPagoObj->format('Y');
$mesFecha = (int) $fechaPagoObj->format('n');
if ($anioPago <= 0 || $mesPago <= 0) {
    $_SESSION['error'] = 'Debes seleccionar el año y el mes del periodo a pagar.';
    header('Location: ../public/prestamos.php');
    exit;
}
if ($anioFecha !== $anioPago || $mesFecha !== $mesPago) {
    $_SESSION['error'] = 'El año y el mes seleccionados deben coincidir con la fecha de pago.';
    header('Location: ../public/prestamos.php');
    exit;
}

$stmtPeriodoValido = $pdo->prepare('SELECT COUNT(*) FROM periodos_configuracion WHERE anio = :anio AND mes = :mes AND activo = 1');
$stmtPeriodoValido->execute([':anio' => $anioPago, ':mes' => $mesPago]);
if ((int) $stmtPeriodoValido->fetchColumn() === 0) {
    $_SESSION['error'] = 'El periodo seleccionado no está habilitado en la configuración.';
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

// Generar matriz mensual hasta el mes del pago
$periodoRecienteStmt = $pdo->prepare('SELECT * FROM periodos_prestamo WHERE id_prestamo = :id ORDER BY anio DESC, mes DESC LIMIT 1');
$periodoRecienteStmt->execute([':id' => $idPrestamo]);
$periodoReciente = $periodoRecienteStmt->fetch();

$capitalReferencia = $periodoReciente ? (float) $periodoReciente['capital_final'] : (float) $prestamo['monto_prestamo'];
$fechaReferenciaPeriodo = $periodoReciente
    ? sprintf('%04d-%02d-01', $periodoReciente['anio'], $periodoReciente['mes'])
    : $prestamo['fecha_prestamo'];
$cursor = DateTime::createFromFormat('Y-m-d', $fechaReferenciaPeriodo) ?: clone $fechaInicioPago;
$cursor->modify('first day of this month');
if ($periodoReciente) {
    $cursor->modify('+1 month');
}

$interesCausadoMeses = 0.0;
$stmtPeriodoExiste = $pdo->prepare('SELECT id_periodo FROM periodos_prestamo WHERE id_prestamo = :id AND anio = :anio AND mes = :mes');
$stmtInsertPeriodo = $pdo->prepare('INSERT INTO periodos_prestamo (id_prestamo, anio, mes, capital_inicio, interes_causado, interes_pagado, abono_capital, capital_final, estado) VALUES (:id_prestamo, :anio, :mes, :capital_inicio, :interes_causado, 0, 0, :capital_final, :estado)');

while ($cursor <= $fechaInicioPago) {
    $anioIteracion = (int) $cursor->format('Y');
    $mesIteracion = (int) $cursor->format('n');
    $stmtPeriodoExiste->execute([
        ':id' => $idPrestamo,
        ':anio' => $anioIteracion,
        ':mes' => $mesIteracion,
    ]);

    if ($stmtPeriodoExiste->rowCount() === 0) {
        $interesMes = round($capitalReferencia * ($prestamo['tasa_interes'] / 100), 2);
        $interesCausadoMeses += $interesMes;
        $stmtInsertPeriodo->execute([
            ':id_prestamo' => $idPrestamo,
            ':anio' => $anioIteracion,
            ':mes' => $mesIteracion,
            ':capital_inicio' => $capitalReferencia,
            ':interes_causado' => $interesMes,
            ':capital_final' => $capitalReferencia,
            ':estado' => 'Mora',
        ]);
    }
    $cursor->modify('+1 month');
}

$stmtPeriodos = $pdo->prepare('SELECT * FROM periodos_prestamo WHERE id_prestamo = :id ORDER BY anio, mes');
$stmtPeriodos->execute([':id' => $idPrestamo]);
$periodosPrestamo = $stmtPeriodos->fetchAll();

$pendienteInteres = 0.0;
foreach ($periodosPrestamo as $periodo) {
    $pendienteInteres += max(0, (float) $periodo['interes_causado'] - (float) $periodo['interes_pagado']);
}

$actividadInteresCausado = obtenerConceptoPorBandera($pdo, 'es_interes_causado');
$actividadCapital = obtenerConceptoPorBandera($pdo, 'es_pago_prestamo');
$actividadInteres = obtenerConceptoPorBandera($pdo, 'es_pago_interes');

if (!$actividadCapital || !$actividadInteres || !$actividadInteresCausado) {
    $_SESSION['error'] = 'No se encontraron conceptos de pago configurados para préstamos.';
    header('Location: ../public/prestamos.php');
    exit;
}

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
if ($pendienteInteres <= 0 && $intPagado > 0.01) {
    $_SESSION['error'] = 'No puedes pagar intereses porque no hay intereses causados pendientes.';
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
    ? sprintf('Pago préstamo a particular %s (aval: %s)', $prestamo['nombre_deudor'], $nombreAval ?: 'sin aval registrado')
    : 'Pago cuota';

$quincenaPago = (int) ($fechaPagoObj->format('j') <= 15 ? 1 : 2);

    $registrarMovimiento = function(array $actividad, float $valor, string $motivo, string $observacion, string $fecha, int $anio, int $mes, int $quincena) use ($pdo, $medio, $socioMovimiento, $idPrestamo) {
    if ($valor <= 0) {
        return;
    }

    $reglaSocio = normalizarReglaAfectacion($actividad['afecta_saldo_socio'] ?? 'neutral');
    $reglaNatillera = normalizarReglaAfectacion($actividad['afecta_saldo_natillera'] ?? 'neutral');
    $esIngreso = (int) ($actividad['es_ingreso'] ?? 0);
    $esEgreso = $esIngreso ? 0 : ($reglaNatillera === 'resta' ? 1 : 0);

    $stmtMov = $pdo->prepare('INSERT INTO movimientos (fecha, anio, mes, quincena, id_socio, id_prestamo, id_actividad, motivo, valor, medio_consignacion, es_ingreso, es_egreso, observaciones, usuario_registro, fecha_registro, modulo) VALUES (:fecha, :anio, :mes, :quincena, :id_socio, :id_prestamo, :id_actividad, :motivo, :valor, :medio, :es_ingreso, :es_egreso, :obs, :usuario, NOW(), :modulo)');
    $stmtMov->execute([
        ':fecha' => $fecha,
        ':anio' => $anio,
        ':mes' => $mes,
        ':quincena' => $quincena,
        ':id_socio' => $socioMovimiento,
        ':id_prestamo' => $idPrestamo,
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

    if ($interesCausadoMeses > 0) {
        $registrarMovimiento(
            $actividadInteresCausado,
            $interesCausadoMeses,
            'Interés causado préstamo #'.$idPrestamo,
            $observacionBase . ($mesesPendientes > 0 ? ' | Causado por ' . $mesesPendientes . ' mes(es)' : ''),
            $fechaPagoObj->format('Y-m-d'),
            $anioPago,
            $mesPago,
            $quincenaPago
        );
    }

    $restanteInteres = $intPagado;
    $periodosActualizados = [];
    foreach ($periodosPrestamo as $periodo) {
        $faltante = max(0, (float) $periodo['interes_causado'] - (float) $periodo['interes_pagado']);
        $aplicar = min($faltante, $restanteInteres);
        if ($aplicar > 0) {
            $periodo['interes_pagado'] += $aplicar;
            $restanteInteres -= $aplicar;
        }

        if ((int) $periodo['anio'] === $anioPago && (int) $periodo['mes'] === $mesPago) {
            $periodo['abono_capital'] = (float) $periodo['abono_capital'] + $capPagado;
            $periodo['capital_final'] = $saldoCapital;
        }

        $periodo['estado'] = ($periodo['interes_pagado'] + 0.01 >= $periodo['interes_causado']) ? 'OK' : 'Mora';
        if ($periodo['capital_final'] <= 0 && $periodo['estado'] === 'OK') {
            $periodo['estado'] = 'Finalizado';
        }

        $periodosActualizados[] = $periodo;
    }

    $stmtActualizarPeriodo = $pdo->prepare('UPDATE periodos_prestamo SET interes_pagado = :interes_pagado, abono_capital = :abono_capital, capital_final = :capital_final, estado = :estado WHERE id_periodo = :id');
    foreach ($periodosActualizados as $periodo) {
        $stmtActualizarPeriodo->execute([
            ':interes_pagado' => $periodo['interes_pagado'],
            ':abono_capital' => $periodo['abono_capital'],
            ':capital_final' => $periodo['capital_final'],
            ':estado' => $periodo['estado'],
            ':id' => $periodo['id_periodo'],
        ]);
    }

    $periodosEnMora = 0;
    $saldoInteresPendiente = 0.0;
    foreach ($periodosActualizados as $periodo) {
        $saldoInteresPendiente += max(0, (float) $periodo['interes_causado'] - (float) $periodo['interes_pagado']);
        if ($periodo['estado'] === 'Mora') {
            $periodosEnMora++;
        }
    }

    $stmtNext = $pdo->prepare('SELECT COALESCE(MAX(numero_cuota),0)+1 AS prox FROM cuotas_prestamo WHERE id_prestamo=:id AND fecha_pago IS NOT NULL');
    $stmtNext->execute([':id'=>$idPrestamo]);
    $numCuota = (int) $stmtNext->fetchColumn();

    $saldoCapital = max(0, $prestamo['saldo_capital_actual'] - $capPagado);
    $saldoInteres = max(0, $saldoInteresPendiente);

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

    $estadoPrestamo = 'Activo';
    if ($saldoCapital <= 0.01) {
        $estadoPrestamo = 'Finalizado';
    } elseif ($periodosEnMora > 0) {
        $estadoPrestamo = 'En mora';
    }

    $pdo->prepare('UPDATE prestamos SET estado = :estado, saldo_intereses_actual = :saldo_int WHERE id_prestamo = :id')->execute([
        ':estado' => $estadoPrestamo,
        ':saldo_int' => $saldoInteresPendiente,
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
