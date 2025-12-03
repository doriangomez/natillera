<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();

try {
    $existeModulo = $pdo->query("SHOW COLUMNS FROM movimientos LIKE 'modulo'");
    if ($existeModulo && $existeModulo->rowCount() === 0) {
        $pdo->exec("ALTER TABLE movimientos ADD COLUMN modulo VARCHAR(100) DEFAULT NULL");
    }
    $existeInteresMensual = $pdo->query("SHOW COLUMNS FROM prestamos LIKE 'interes_mensual'");
    if ($existeInteresMensual && $existeInteresMensual->rowCount() === 0) {
        $pdo->exec("ALTER TABLE prestamos ADD COLUMN interes_mensual DECIMAL(12,2) DEFAULT 0 AFTER tasa_interes");
    }
} catch (Exception $e) {
    // continuar
}

sincronizarConceptosPrestamo($pdo);

$accion = $_POST['accion'] ?? 'crear';
$idPrestamo = isset($_POST['id_prestamo']) ? (int) $_POST['id_prestamo'] : 0;

if ($accion === 'eliminar') {
    try {
        $pdo->beginTransaction();

        $stmtPrestamo = $pdo->prepare('SELECT * FROM prestamos WHERE id_prestamo = :id');
        $stmtPrestamo->execute([':id' => $idPrestamo]);
        $prestamo = $stmtPrestamo->fetch();

        if (!$prestamo) {
            throw new RuntimeException('Préstamo no encontrado.');
        }

        $stmtCuotas = $pdo->prepare('SELECT * FROM cuotas_prestamo WHERE id_prestamo = :id');
        $stmtCuotas->execute([':id' => $idPrestamo]);
        $cuotas = $stmtCuotas->fetchAll();

        $interesAnticipado = (float) ($prestamo['interes_mensual'] ?? 0);
        $montoDesembolso = max(0, ((float) $prestamo['monto_prestamo']) - $interesAnticipado);

        foreach ($cuotas as $cuota) {
            $valorTotal = (float) $cuota['valor_capital_pagado'] + (float) $cuota['valor_interes_pagado'];
            if (!empty($cuota['fecha_pago'])) {
                $motivo = 'Pago cuota préstamo #' . $idPrestamo;
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
        }

        $stmtDelCuotas = $pdo->prepare('DELETE FROM cuotas_prestamo WHERE id_prestamo = :id');
        $stmtDelCuotas->execute([':id' => $idPrestamo]);

        $actividadPrestamo = $pdo->query("SELECT id_actividad FROM actividades_maestro WHERE es_prestamo=1 LIMIT 1")->fetch();
        if ($actividadPrestamo) {
            $paramsDel = [
                ':id_act' => $actividadPrestamo['id_actividad'],
                ':fecha' => $prestamo['fecha_prestamo'],
                ':valor' => abs($montoDesembolso),
            ];
            $sqlDelDesembolso = 'DELETE FROM movimientos WHERE id_actividad = :id_act AND motivo = :motivo AND fecha = :fecha AND valor = :valor';
            $socioMovimiento = $prestamo['es_particular'] ? null : $prestamo['id_socio'];
            $sqlDelDesembolso .= $socioMovimiento ? ' AND id_socio = :id_socio' : ' AND id_socio IS NULL';
            $paramsDel[':motivo'] = 'Registro préstamo';
            if ($socioMovimiento) {
                $paramsDel[':id_socio'] = $socioMovimiento;
            }
            $sqlDelDesembolso .= ' LIMIT 1';
            $pdo->prepare($sqlDelDesembolso)->execute($paramsDel);

        }

        $actividadInteres = obtenerConceptoPorBandera($pdo, 'es_pago_interes');
        if ($actividadInteres && $interesAnticipado > 0) {
            $paramsInteres = [
                ':id_act' => $actividadInteres['id_actividad'],
                ':fecha' => $prestamo['fecha_prestamo'],
                ':valor' => abs($interesAnticipado),
                ':motivo' => 'Interés anticipado préstamo',
            ];

            $sqlDelInteres = 'DELETE FROM movimientos WHERE id_actividad = :id_act AND motivo = :motivo AND fecha = :fecha AND valor = :valor';
            $socioMovimientoInteres = $prestamo['es_particular'] ? null : $prestamo['id_socio'];
            $sqlDelInteres .= $socioMovimientoInteres ? ' AND id_socio = :id_socio' : ' AND id_socio IS NULL';
            if ($socioMovimientoInteres) {
                $paramsInteres[':id_socio'] = $socioMovimientoInteres;
            }
            $sqlDelInteres .= ' LIMIT 1';
            $pdo->prepare($sqlDelInteres)->execute($paramsInteres);
        }

        $stmtDelPrestamo = $pdo->prepare('DELETE FROM prestamos WHERE id_prestamo = :id');
        $stmtDelPrestamo->execute([':id' => $idPrestamo]);

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = 'No se pudo eliminar el préstamo: ' . $e->getMessage();
    }

    header('Location: ../public/prestamos.php');
    exit;
}

$fecha = $_POST['fecha_prestamo'];
$idSocio = $_POST['id_socio'] ?: null;
$esParticular = isset($_POST['es_particular']) ? (int) $_POST['es_particular'] : null;
$idAval = $_POST['id_socio_aval'] ?: null;
$nombreDeudor = trim($_POST['nombre_deudor'] ?? '');
$monto = (float) $_POST['monto_prestamo'];
$tasa = (float) $_POST['tasa_interes'];

$fechaObj = DateTime::createFromFormat('Y-m-d', $fecha);
if (!$fechaObj) {
    $_SESSION['error'] = 'La fecha del préstamo no es válida.';
    header('Location: ../public/prestamos.php');
    exit;
}

$anioFecha = (int) $fechaObj->format('Y');
$mesFecha = (int) $fechaObj->format('n');
$quincena = (int) $fechaObj->format('j') <= 15 ? 1 : 2;

$stmtPeriodo = $pdo->prepare('SELECT COUNT(*) FROM periodos_configuracion WHERE anio = :anio AND mes = :mes AND activo = 1');
$stmtPeriodo->execute([':anio' => $anioFecha, ':mes' => $mesFecha]);
if ((int) $stmtPeriodo->fetchColumn() === 0) {
    $_SESSION['error'] = 'El periodo correspondiente a la fecha del préstamo no está activo. Configúralo en el módulo de configuración para continuar.';
    header('Location: ../public/prestamos.php');
    exit;
}

if ($esParticular === null) {
    $_SESSION['error'] = 'Debes seleccionar si el deudor es socio o particular.';
    header('Location: ../public/prestamos.php');
    exit;
}

if (!$esParticular) {
    if (!$idSocio) {
        $_SESSION['error'] = 'Selecciona el socio beneficiario para el préstamo.';
        header('Location: ../public/prestamos.php');
        exit;
    }
    $idAval = $_POST['id_socio_aval'] ?: null;
    $nombreDeudor = null;
} else {
    if ($nombreDeudor === '') {
        $_SESSION['error'] = 'Ingresa el nombre del deudor particular.';
        header('Location: ../public/prestamos.php');
        exit;
    }
    if (!$idAval) {
        $_SESSION['error'] = 'Selecciona el socio avalador para el préstamo de un particular.';
        header('Location: ../public/prestamos.php');
        exit;
    }
    $idSocio = null;
}

$interesMensual = round($monto * ($tasa / 100), 2);
$saldoCapital = $monto;
$saldoInteres = 0;
$montoDesembolso = max(0, $monto - $interesMensual);

$stmt = $pdo->prepare('INSERT INTO prestamos (id_socio, es_particular, id_socio_aval, nombre_deudor, fecha_prestamo, monto_prestamo, tasa_interes, interes_mensual, saldo_capital_actual, saldo_intereses_actual, estado) VALUES (:id_socio, :es_particular, :id_aval, :nombre_deudor, :fecha, :monto, :tasa, :interes_mensual, :saldo_capital_actual, :saldo_intereses_actual, :estado)');
$stmt->execute([
    ':id_socio' => $idSocio,
    ':es_particular' => $esParticular,
    ':id_aval' => $idAval,
    ':nombre_deudor' => $nombreDeudor,
    ':fecha' => $fecha,
    ':monto' => $monto,
    ':tasa' => $tasa,
    ':interes_mensual' => $interesMensual,
    ':saldo_capital_actual' => $saldoCapital,
    ':saldo_intereses_actual' => $saldoInteres,
    ':estado' => 'Vigente'
]);
$idPrestamo = $pdo->lastInsertId();

// No se generan cuotas programadas: solo se registra el resumen del préstamo

// Registrar movimiento de desembolso si existe actividad marcada como préstamo
$actividadPrestamo = obtenerConceptoPorBandera($pdo, 'es_prestamo');
if (!$actividadPrestamo) {
    $_SESSION['error'] = 'No se encontró un concepto de desembolso configurado para préstamos.';
    header('Location: ../public/prestamos.php');
    exit;
}

$reglaSocio = normalizarReglaAfectacion($actividadPrestamo['afecta_saldo_socio']);
$reglaNatillera = normalizarReglaAfectacion($actividadPrestamo['afecta_saldo_natillera']);
$socioMovimiento = $esParticular ? null : $idSocio;
$esIngreso = (int) ($actividadPrestamo['es_ingreso'] ?? 0);
$esEgreso = $esIngreso ? 0 : ($reglaNatillera === 'resta' ? 1 : 0);

$nombreSocioMovimiento = null;
if ($socioMovimiento) {
    $stmtSocio = $pdo->prepare('SELECT nombre_completo FROM socios WHERE id_socio = :id');
    $stmtSocio->execute([':id' => $socioMovimiento]);
    $nombreSocioMovimiento = $stmtSocio->fetchColumn();
}

$nombreAval = null;
if ($idAval) {
    $stmtAval = $pdo->prepare('SELECT nombre_completo FROM socios WHERE id_socio = :id');
    $stmtAval->execute([':id' => $idAval]);
    $nombreAval = $stmtAval->fetchColumn();
}

$observacionMovimiento = $esParticular
    ? sprintf('Préstamo a particular %s (aval: %s)', $nombreDeudor, $nombreAval ?: 'sin aval registrado')
    : sprintf('Préstamo a socio %s', $nombreSocioMovimiento ?: $idSocio);

$stmtMov = $pdo->prepare('INSERT INTO movimientos (fecha, anio, mes, quincena, id_socio, id_actividad, motivo, valor, medio_consignacion, es_ingreso, es_egreso, observaciones, usuario_registro, fecha_registro, modulo) VALUES (:fecha, :anio, :mes, :quincena, :id_socio, :id_actividad, :motivo, :valor, :medio, :es_ingreso, :es_egreso, :obs, :usuario, NOW(), :modulo)');
$stmtMov->execute([
    ':fecha' => $fecha,
    ':anio' => $anioFecha,
    ':mes' => $mesFecha,
    ':quincena' => $quincena,
    ':id_socio' => $socioMovimiento,
    ':id_actividad' => $actividadPrestamo['id_actividad'],
    ':motivo' => 'Registro préstamo',
    ':valor' => abs($montoDesembolso),
    ':medio' => 'Efectivo',
    ':obs' => $observacionMovimiento,
    ':usuario' => $_SESSION['usuario'] ?? null,
    ':modulo' => 'prestamos',
    ':es_ingreso' => $esIngreso,
    ':es_egreso' => $esEgreso,
]);
actualizarSaldoSocio($pdo, $socioMovimiento, abs($montoDesembolso), $reglaSocio);
actualizarSaldoNatillera($pdo, abs($montoDesembolso), $reglaNatillera);

$actividadInteres = obtenerConceptoPorBandera($pdo, 'es_pago_interes');
if ($actividadInteres) {
    $reglaSocioInteres = normalizarReglaAfectacion($actividadInteres['afecta_saldo_socio']);
    $reglaNatilleraInteres = normalizarReglaAfectacion($actividadInteres['afecta_saldo_natillera']);
    $esIngresoInteres = (int) ($actividadInteres['es_ingreso'] ?? 0);
    $esEgresoInteres = $esIngresoInteres ? 0 : ($reglaNatilleraInteres === 'resta' ? 1 : 0);

    $stmtInteres = $pdo->prepare('INSERT INTO movimientos (fecha, anio, mes, quincena, id_socio, id_actividad, motivo, valor, medio_consignacion, es_ingreso, es_egreso, observaciones, usuario_registro, fecha_registro, modulo) VALUES (:fecha, :anio, :mes, :quincena, :id_socio, :id_actividad, :motivo, :valor, :medio, :es_ingreso, :es_egreso, :obs, :usuario, NOW(), :modulo)');
    $stmtInteres->execute([
        ':fecha' => $fecha,
        ':anio' => $anioFecha,
        ':mes' => $mesFecha,
        ':quincena' => $quincena,
        ':id_socio' => $socioMovimiento,
        ':id_actividad' => $actividadInteres['id_actividad'],
        ':motivo' => 'Interés anticipado préstamo',
        ':valor' => abs($interesMensual),
        ':medio' => 'Efectivo',
        ':obs' => $observacionMovimiento,
        ':usuario' => $_SESSION['usuario'] ?? null,
        ':modulo' => 'prestamos',
        ':es_ingreso' => $esIngresoInteres,
        ':es_egreso' => $esEgresoInteres,
    ]);
    actualizarSaldoSocio($pdo, $socioMovimiento, abs($interesMensual), $reglaSocioInteres);
    actualizarSaldoNatillera($pdo, abs($interesMensual), $reglaNatilleraInteres);
}

header('Location: ../public/prestamos.php');
?>
