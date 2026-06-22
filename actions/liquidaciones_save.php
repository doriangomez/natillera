<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();
asegurarEsquemaLiquidaciones($pdo);

$accion = trim((string) ($_POST['accion'] ?? 'crear'));
$idLiquidacion = isset($_POST['id_liquidacion']) ? (int) $_POST['id_liquidacion'] : 0;
$redirect = '../public/liquidaciones.php';

function obtenerActividadValida(PDO $pdo, int $id): ?array {
    if ($id <= 0) {
        return null;
    }
    $actividad = getActividad($pdo, $id);
    return $actividad ?: null;
}

function borrarMovimientoSiExiste(PDO $pdo, ?int $idMovimiento): void {
    if (!$idMovimiento) {
        return;
    }
    $stmt = $pdo->prepare('DELETE FROM movimientos WHERE id_movimiento = :id');
    $stmt->execute([':id' => $idMovimiento]);
}

function extraerIdsMovimientosLiquidacion(array $liquidacion): array {
    $ids = [
        isset($liquidacion['id_movimiento_compensacion']) ? (int) $liquidacion['id_movimiento_compensacion'] : 0,
        isset($liquidacion['movimiento_liquidacion_id']) ? (int) $liquidacion['movimiento_liquidacion_id'] : 0,
        isset($liquidacion['movimiento_cuota_id']) ? (int) $liquidacion['movimiento_cuota_id'] : 0,
        isset($liquidacion['movimiento_fondo_id']) ? (int) $liquidacion['movimiento_fondo_id'] : 0,
    ];

    $movimientosGenerados = isset($liquidacion['movimientos_generados']) ? (string) $liquidacion['movimientos_generados'] : '';
    if ($movimientosGenerados !== '') {
        $detalle = json_decode($movimientosGenerados, true);
        if (is_array($detalle)) {
            foreach ($detalle as $mov) {
                if (is_array($mov) && isset($mov['id_movimiento'])) {
                    $ids[] = (int) $mov['id_movimiento'];
                }
            }
        }
    }

    $ids = array_values(array_filter(array_unique($ids), static function ($id) {
        return (int) $id > 0;
    }));

    return array_map('intval', $ids);
}

if ($accion === 'anular') {
    if ($idLiquidacion <= 0) {
        $_SESSION['error'] = 'Liquidación inválida para anular.';
        header('Location: ' . $redirect);
        exit;
    }

    $stmt = $pdo->prepare('SELECT * FROM liquidaciones WHERE id = :id');
    $stmt->execute([':id' => $idLiquidacion]);
    $liq = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$liq || ($liq['estado'] ?? '') !== 'activa') {
        $_SESSION['error'] = 'Solo se pueden anular liquidaciones activas.';
        header('Location: ' . $redirect);
        exit;
    }

    $pdo->beginTransaction();
    try {
        foreach (extraerIdsMovimientosLiquidacion($liq) as $idMovimiento) {
            borrarMovimientoSiExiste($pdo, $idMovimiento);
        }

        $pdo->prepare('UPDATE liquidaciones SET estado = "anulada" WHERE id = :id')->execute([':id' => $idLiquidacion]);
        recalcularSaldosDesdeMovimientos($pdo);
        $idsPrestamos = json_decode((string) ($liq['ids_prestamos_afectados'] ?? '[]'), true);
        if (is_array($idsPrestamos)) {
            foreach (array_unique(array_map('intval', $idsPrestamos)) as $idPrestamoAfectado) {
                if ($idPrestamoAfectado > 0) {
                    recalcularPrestamoDesdeMovimientos($pdo, $idPrestamoAfectado);
                }
            }
        }
        $pdo->commit();
        $_SESSION['exito'] = 'Liquidación anulada y movimientos revertidos correctamente.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = 'Error anulando liquidación: ' . $e->getMessage();
    }

    header('Location: ' . $redirect);
    exit;
}

if ($accion === 'crear' && (string) ($_POST['confirmar_liquidacion'] ?? '') !== '1') {
    $_SESSION['error'] = 'Debe confirmar explícitamente la liquidación antes de registrarla.';
    header('Location: ' . $redirect);
    exit;
}

$tipoLiquidacion = trim((string) ($_POST['tipo_liquidacion'] ?? 'anticipada'));
$tipos = obtenerTiposLiquidacion();
if (!isset($tipos[$tipoLiquidacion])) {
    $_SESSION['error'] = 'Tipo de liquidación inválido.';
    header('Location: ' . $redirect);
    exit;
}

$idSocio = isset($_POST['id_socio']) ? (int) $_POST['id_socio'] : 0;
$cuotaManejo = isset($_POST['cuota_manejo']) ? (float) $_POST['cuota_manejo'] : 0.0;
$idActividadLiquidacion = isset($_POST['id_actividad_liquidacion']) ? (int) $_POST['id_actividad_liquidacion'] : 0;
$idActividadRetencion = isset($_POST['id_actividad_retencion'])
    ? (int) $_POST['id_actividad_retencion']
    : (isset($_POST['id_actividad_cuota']) ? (int) $_POST['id_actividad_cuota'] : 0);
$observaciones = trim((string) ($_POST['observaciones'] ?? ''));

$liquidacionBase = null;
if ($accion === 'editar') {
    $stmtBase = $pdo->prepare('SELECT * FROM liquidaciones WHERE id = :id');
    $stmtBase->execute([':id' => $idLiquidacion]);
    $liquidacionBase = $stmtBase->fetch(PDO::FETCH_ASSOC);
    if (!$liquidacionBase || ($liquidacionBase['estado'] ?? '') !== 'activa') {
        $_SESSION['error'] = 'Solo se puede editar una liquidación activa.';
        header('Location: ' . $redirect);
        exit;
    }

    $idSocio = (int) $liquidacionBase['socio_id'];
}

if ($idSocio <= 0 || $idActividadLiquidacion <= 0) {
    $_SESSION['error'] = 'Faltan datos obligatorios para registrar la liquidación.';
    header('Location: ' . $redirect);
    exit;
}

$actividadLiquidacion = obtenerActividadValida($pdo, $idActividadLiquidacion);
$actividadRetencion = $idActividadRetencion > 0 ? obtenerActividadValida($pdo, $idActividadRetencion) : null;

if (!$actividadLiquidacion) {
    $_SESSION['error'] = 'La actividad principal de liquidación no es válida.';
    header('Location: ' . $redirect);
    exit;
}

$reglaSocioPrincipal = normalizarReglaAfectacion($actividadLiquidacion['afecta_saldo_socio'] ?? 'neutral');
$reglaNatilleraPrincipal = normalizarReglaAfectacion($actividadLiquidacion['afecta_saldo_natillera'] ?? 'neutral');
if ($reglaSocioPrincipal !== 'resta' || $reglaNatilleraPrincipal !== 'resta') {
    $_SESSION['error'] = 'La actividad principal debe restar saldo socio y saldo natillera.';
    header('Location: ' . $redirect);
    exit;
}

$idsPrestamosPrevios = [];
if ($liquidacionBase) {
    $idsPrevios = json_decode((string) ($liquidacionBase['ids_prestamos_afectados'] ?? '[]'), true);
    if (is_array($idsPrevios)) {
        $idsPrestamosPrevios = array_values(array_unique(array_filter(array_map('intval', $idsPrevios), static fn($id) => $id > 0)));
    }
}

$fecha = date('Y-m-d');
$anio = (int) date('Y');
$mes = (int) date('n');
$quincena = (int) ((int) date('j') <= 15 ? 1 : 2);
$usuario = $_SESSION['usuario'] ?? null;

$insertMov = $pdo->prepare('INSERT INTO movimientos (fecha, anio, mes, quincena, id_socio, id_prestamo, id_actividad, motivo, valor, medio_consignacion, es_ingreso, es_egreso, observaciones, usuario_registro, fecha_registro, modulo)
VALUES (:fecha, :anio, :mes, :quincena, :id_socio, :id_prestamo, :id_actividad, :motivo, :valor, :medio, :ingreso, :egreso, :obs, :usuario, NOW(), :modulo)');

$insertLiq = $pdo->prepare('INSERT INTO liquidaciones (socio_id, tipo_liquidacion, saldo_base, valor_pollas, valor_prestamos, valor_cuota_manejo, valor_aplicado_deuda, deficit, intereses_cubiertos, capital_cubierto, valor_bruto, valor_neto, actividad_liquidacion_id, actividad_cuota_id, actividad_fondo_id, movimiento_liquidacion_id, movimiento_cuota_id, movimiento_fondo_id, id_movimiento_compensacion, ids_prestamos_afectados, movimientos_generados, detalle_preliquidacion, fecha_preliquidacion, observaciones, fecha, usuario_id, estado)
VALUES (:socio, :tipo, :saldo_base, :pollas, :prestamos, :cuota, :aplicado_deuda, :deficit, :intereses_cubiertos, :capital_cubierto, :bruto, :neto, :act_liq, :act_cuota, :act_fondo, :mov_liq, :mov_cuota, :mov_fondo, :mov_comp, :ids_prestamos, :movs_json, :detalle_preliquidacion, :fecha_preliquidacion, :obs, :fecha, :usuario, :estado)');

$pdo->beginTransaction();
try {
    if ($accion === 'editar' && $liquidacionBase) {
        foreach (extraerIdsMovimientosLiquidacion($liquidacionBase) as $idMovimiento) {
            borrarMovimientoSiExiste($pdo, $idMovimiento);
        }
        $pdo->prepare('UPDATE liquidaciones SET estado = "editada" WHERE id = :id')->execute([':id' => $idLiquidacion]);
        recalcularSaldosDesdeMovimientos($pdo);
        foreach ($idsPrestamosPrevios as $idPrestamoPrevio) {
            recalcularPrestamoDesdeMovimientos($pdo, $idPrestamoPrevio);
        }
    }

    $calculo = calcularLiquidacionSocio($pdo, $idSocio, $cuotaManejo);
    if (!$calculo) {
        throw new InvalidArgumentException('No se encontró el socio para liquidar.');
    }

    if ((float) $calculo['valor_neto'] < 0 || ((float) $calculo['valor_bruto'] <= 0 && (float) $calculo['valor_aplicado_deuda'] <= 0)) {
        throw new InvalidArgumentException('El cálculo de liquidación no es válido (sin valor para pagar o aplicar a deuda, o neto negativo).');
    }

    if ($cuotaManejo > 0 && (float) $calculo['deficit'] <= 0) {
        if ($idActividadRetencion <= 0) {
            throw new InvalidArgumentException('Para cuota de administración debe seleccionar la actividad de retención.');
        }
        if (!$actividadRetencion) {
            throw new InvalidArgumentException('La actividad de retención de administración no es válida.');
        }
        $reglaSocioRetencion = normalizarReglaAfectacion($actividadRetencion['afecta_saldo_socio'] ?? 'neutral');
        $reglaNatilleraRetencion = normalizarReglaAfectacion($actividadRetencion['afecta_saldo_natillera'] ?? 'neutral');
        if ($reglaSocioRetencion !== 'resta' || $reglaNatilleraRetencion !== 'resta') {
            throw new InvalidArgumentException('Actividad retención inválida: debe restar saldo socio y saldo natillera.');
        }
    }

    $movPrincipalId = null;
    $movCompensacionId = null;
    $idsPrestamosAfectados = [];
    $interesesCubiertos = 0.0;
    $capitalCubierto = 0.0;
    $movimientosGenerados = [];

    if ((float) $calculo['valor_aplicado_deuda'] > 0) {
        $actividadesLiquidacionPrestamo = sincronizarConceptosLiquidacionPrestamo($pdo);
        $actividadPagoInteresLiquidacion = $actividadesLiquidacionPrestamo['pago_interes_liquidacion'] ?? null;
        $actividadPagoCapitalLiquidacion = $actividadesLiquidacionPrestamo['pago_capital_liquidacion'] ?? null;
        if (!$actividadPagoInteresLiquidacion || !$actividadPagoCapitalLiquidacion) {
            throw new RuntimeException('No se pudieron preparar las actividades de pago por liquidación.');
        }

        $saldoDisponibleDeuda = (float) $calculo['valor_aplicado_deuda'];
        foreach ($calculo['prestamos_descontados'] as $prestamo) {
            if ($saldoDisponibleDeuda <= 0.01) {
                break;
            }
            $interesPendiente = (float) $prestamo['intereses_pendientes'];
            $capitalPendiente = (float) $prestamo['capital_pendiente'];
            $interesCubierto = min($saldoDisponibleDeuda, $interesPendiente);
            $saldoDisponibleDeuda -= $interesCubierto;
            $capitalCubiertoPrestamo = min($saldoDisponibleDeuda, $capitalPendiente);
            $saldoDisponibleDeuda -= $capitalCubiertoPrestamo;
            $totalCubiertoPrestamo = $interesCubierto + $capitalCubiertoPrestamo;
            if ($totalCubiertoPrestamo <= 0) {
                continue;
            }

            $nuevoInteres = max(0, $interesPendiente - $interesCubierto);
            $nuevoCapital = max(0, $capitalPendiente - $capitalCubiertoPrestamo);
            $nuevoEstado = $nuevoCapital <= 0.01 && $nuevoInteres <= 0.01 ? 'Finalizado' : ($nuevoInteres > 0.01 ? 'En mora' : 'Activo');
            $idPrestamo = (int) $prestamo['id_prestamo'];

            if ($interesCubierto > 0.01) {
                $insertMov->execute([
                    ':fecha' => $fecha, ':anio' => $anio, ':mes' => $mes, ':quincena' => $quincena,
                    ':id_socio' => $idSocio, ':id_prestamo' => $idPrestamo, ':id_actividad' => (int) $actividadPagoInteresLiquidacion['id_actividad'],
                    ':motivo' => 'Pago de intereses por liquidación préstamo #' . $idPrestamo . ' - ' . $calculo['socio']['nombre_completo'],
                    ':valor' => abs($interesCubierto), ':medio' => 'Liquidaciones', ':ingreso' => 0, ':egreso' => 1,
                    ':obs' => 'Compensación automática de liquidación aplicada a intereses.',
                    ':usuario' => $usuario, ':modulo' => 'liquidaciones',
                ]);
                $idMovInteres = (int) $pdo->lastInsertId();
                $movCompensacionId = $movCompensacionId ?: $idMovInteres;
                $movimientosGenerados[] = [
                    'tipo' => 'pago_interes_liquidacion',
                    'id_movimiento' => $idMovInteres,
                    'id_prestamo' => $idPrestamo,
                    'id_actividad' => (int) $actividadPagoInteresLiquidacion['id_actividad'],
                    'valor' => $interesCubierto,
                ];
            }

            if ($capitalCubiertoPrestamo > 0.01) {
                $insertMov->execute([
                    ':fecha' => $fecha, ':anio' => $anio, ':mes' => $mes, ':quincena' => $quincena,
                    ':id_socio' => $idSocio, ':id_prestamo' => $idPrestamo, ':id_actividad' => (int) $actividadPagoCapitalLiquidacion['id_actividad'],
                    ':motivo' => 'Pago de capital por liquidación préstamo #' . $idPrestamo . ' - ' . $calculo['socio']['nombre_completo'],
                    ':valor' => abs($capitalCubiertoPrestamo), ':medio' => 'Liquidaciones', ':ingreso' => 0, ':egreso' => 1,
                    ':obs' => 'Compensación automática de liquidación aplicada a capital.',
                    ':usuario' => $usuario, ':modulo' => 'liquidaciones',
                ]);
                $idMovCapital = (int) $pdo->lastInsertId();
                $movCompensacionId = $movCompensacionId ?: $idMovCapital;
                $movimientosGenerados[] = [
                    'tipo' => 'pago_capital_liquidacion',
                    'id_movimiento' => $idMovCapital,
                    'id_prestamo' => $idPrestamo,
                    'id_actividad' => (int) $actividadPagoCapitalLiquidacion['id_actividad'],
                    'valor' => $capitalCubiertoPrestamo,
                ];
            }

            $idsPrestamosAfectados[] = $idPrestamo;
            $interesesCubiertos += $interesCubierto;
            $capitalCubierto += $capitalCubiertoPrestamo;
            $pdo->prepare('UPDATE prestamos SET saldo_capital_actual = :cap, saldo_intereses_actual = :int, estado = :estado WHERE id_prestamo = :id')
                ->execute([':cap' => $nuevoCapital, ':int' => $nuevoInteres, ':estado' => $nuevoEstado, ':id' => $idPrestamo]);
        }
    }
    if ((float) $calculo['valor_neto'] > 0) {
        $insertMov->execute([
            ':fecha' => $fecha,
            ':anio' => $anio,
            ':mes' => $mes,
            ':quincena' => $quincena,
            ':id_socio' => $idSocio,
            ':id_prestamo' => null,
            ':id_actividad' => $idActividadLiquidacion,
            ':motivo' => 'Liquidación ' . $tipoLiquidacion . ' - ' . $calculo['socio']['nombre_completo'],
            ':valor' => -abs((float) $calculo['valor_neto']),
            ':medio' => 'Liquidaciones',
            ':ingreso' => 0,
            ':egreso' => 1,
            ':obs' => 'Movimiento principal de liquidación (' . $tipoLiquidacion . ').',
            ':usuario' => $usuario,
            ':modulo' => 'liquidaciones',
        ]);
        $movPrincipalId = (int) $pdo->lastInsertId();
        $movimientosGenerados[] = [
            'tipo' => 'pago_socio',
            'id_movimiento' => $movPrincipalId,
            'id_actividad' => $idActividadLiquidacion,
            'valor' => abs((float) $calculo['valor_neto']),
        ];
    }

    $movRetencionId = null;
    if ($cuotaManejo > 0 && (float) $calculo['deficit'] <= 0) {
        $insertMov->execute([
            ':fecha' => $fecha,
            ':anio' => $anio,
            ':mes' => $mes,
            ':quincena' => $quincena,
            ':id_socio' => $idSocio,
            ':id_prestamo' => null,
            ':id_actividad' => $idActividadRetencion,
            ':motivo' => 'Retención administración liquidación - ' . $calculo['socio']['nombre_completo'],
            ':valor' => -abs($cuotaManejo),
            ':medio' => 'Liquidaciones',
            ':ingreso' => 0,
            ':egreso' => 1,
            ':obs' => 'Retención de administración: descuento al socio y salida a bolsa administrativa.',
            ':usuario' => $usuario,
            ':modulo' => 'liquidaciones',
        ]);
        $movRetencionId = (int) $pdo->lastInsertId();
        $movimientosGenerados[] = [
            'tipo' => 'retencion_administracion',
            'id_movimiento' => $movRetencionId,
            'id_actividad' => $idActividadRetencion,
            'valor' => abs((float) $cuotaManejo),
        ];
    }

    $insertLiq->execute([
        ':socio' => $idSocio,
        ':tipo' => $tipoLiquidacion,
        ':saldo_base' => $calculo['saldo_base'],
        ':pollas' => $calculo['valor_pollas'],
        ':prestamos' => $calculo['valor_prestamos'],
        ':cuota' => $calculo['valor_cuota_manejo'],
        ':aplicado_deuda' => $calculo['valor_aplicado_deuda'],
        ':deficit' => $calculo['deficit'],
        ':intereses_cubiertos' => $interesesCubiertos,
        ':capital_cubierto' => $capitalCubierto,
        ':bruto' => $calculo['valor_bruto'],
        ':neto' => $calculo['valor_neto'],
        ':act_liq' => $idActividadLiquidacion,
        ':act_cuota' => $idActividadRetencion ?: null,
        ':act_fondo' => null,
        ':mov_liq' => $movPrincipalId,
        ':mov_cuota' => $movRetencionId,
        ':mov_fondo' => null,
        ':mov_comp' => $movCompensacionId,
        ':ids_prestamos' => json_encode(array_values(array_unique($idsPrestamosAfectados))),
        ':movs_json' => json_encode($movimientosGenerados),
        ':detalle_preliquidacion' => json_encode([
            'socio' => $calculo['socio'],
            'saldo_base' => $calculo['saldo_base'],
            'valor_pollas' => $calculo['valor_pollas'],
            'prestamos_descontados' => $calculo['prestamos_descontados'],
            'valor_prestamos' => $calculo['valor_prestamos'],
            'valor_aplicado_deuda' => $calculo['valor_aplicado_deuda'],
            'intereses_cubiertos' => $interesesCubiertos,
            'capital_cubierto' => $capitalCubierto,
            'ids_prestamos_afectados' => array_values(array_unique($idsPrestamosAfectados)),
            'deficit' => $calculo['deficit'],
            'valor_cuota_manejo' => $calculo['valor_cuota_manejo'],
            'valor_bruto' => $calculo['valor_bruto'],
            'valor_neto' => $calculo['valor_neto'],
            'advertencia_deficit' => $calculo['deficit'] > 0 ? 'El saldo del socio no cubre la totalidad de la deuda. El déficit restante deberá ser gestionado manualmente en el módulo de préstamos.' : null,
        ], JSON_UNESCAPED_UNICODE),
        ':fecha_preliquidacion' => $calculo['fecha_preliquidacion'],
        ':obs' => $observaciones,
        ':fecha' => $fecha,
        ':usuario' => $usuario,
        ':estado' => 'activa',
    ]);

    recalcularSaldosDesdeMovimientos($pdo);
    foreach (array_values(array_unique($idsPrestamosAfectados)) as $idPrestamoAfectado) {
        recalcularPrestamoDesdeMovimientos($pdo, (int) $idPrestamoAfectado);
    }
    $pdo->commit();

    $_SESSION['exito'] = $accion === 'editar'
        ? 'Liquidación editada y recalculada correctamente.'
        : 'Liquidación registrada correctamente con trazabilidad completa.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error'] = 'No se pudo guardar la liquidación: ' . $e->getMessage();
}

header('Location: ' . $redirect);
exit;
