<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();
asegurarEsquemaLiquidaciones($pdo);

$accion = trim((string) ($_POST['accion'] ?? 'crear'));
$idLiquidacion = isset($_POST['id_liquidacion']) ? (int) $_POST['id_liquidacion'] : 0;
$redirect = '../public/liquidaciones.php';
$motivoReverso = trim((string) ($_POST['motivo_reverso'] ?? 'Reverso solicitado desde módulo de liquidaciones.'));


function parametrosSqlLiquidacion(string $sql): array {
    preg_match_all('/(?<!:):([a-zA-Z_][a-zA-Z0-9_]*)/', $sql, $matches);
    return array_values(array_unique(array_map(static fn($p) => ':' . $p, $matches[1] ?? [])));
}

function normalizarParametrosLiquidacion(array $params): array {
    $normalizados = [];
    foreach ($params as $clave => $valor) {
        if (is_int($clave)) {
            $normalizados[(string) $clave] = $valor;
            continue;
        }
        $claveTexto = (string) $clave;
        $normalizados[str_starts_with($claveTexto, ':') ? $claveTexto : ':' . $claveTexto] = $valor;
    }
    return $normalizados;
}

function ejecutarLiquidacionStatement(PDOStatement $stmt, array $params = []): void {
    $sql = (string) $stmt->queryString;
    $esperados = parametrosSqlLiquidacion($sql);
    $enviados = normalizarParametrosLiquidacion($params);
    $clavesEnviadas = array_keys($enviados);
    $faltantes = array_values(array_diff($esperados, $clavesEnviadas));
    $sobrantes = array_values(array_diff($clavesEnviadas, $esperados));

    if (!empty($faltantes) || !empty($sobrantes)) {
        $mensaje = "Error de parámetros PDO en liquidaciones.
" .
            "SQL:
" . $sql . "
" .
            "Parámetros SQL:
" . json_encode($esperados, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "
" .
            "execute():
" . json_encode($enviados, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "
" .
            "Faltantes:
" . json_encode($faltantes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "
" .
            "Sobrantes:
" . json_encode($sobrantes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        error_log($mensaje);
        throw new InvalidArgumentException($mensaje);
    }

    $stmt->execute($params);
}

function obtenerActividadValida(PDO $pdo, int $id): ?array {
    if ($id <= 0) {
        return null;
    }
    $actividad = getActividad($pdo, $id);
    return $actividad ?: null;
}


function asegurarActividadLiquidacionSimple(PDO $pdo, string $nombre, string $descripcion, string $afectaSocio, string $afectaNatillera, int $esPrestamo = 0, int $esIngreso = 0): int {
    $stmt = $pdo->prepare('SELECT id_actividad FROM actividades_maestro WHERE nombre_actividad = :nombre LIMIT 1');
    ejecutarLiquidacionStatement($stmt, [':nombre' => $nombre]);
    $id = (int) $stmt->fetchColumn();
    $data = [
        ':nombre' => $nombre,
        ':descripcion' => $descripcion,
        ':afecta_socio' => $afectaSocio,
        ':afecta_natillera' => $afectaNatillera,
        ':es_prestamo' => $esPrestamo,
        ':es_ingreso' => $esIngreso,
    ];
    if ($id > 0) {
        $data[':id'] = $id;
        $paramsUpdate = $data;
        unset($paramsUpdate[':nombre']);
        $stmtUpdate = $pdo->prepare("UPDATE actividades_maestro SET descripcion = :descripcion, afecta_saldo_socio = :afecta_socio, afecta_saldo_natillera = :afecta_natillera, es_ingreso = :es_ingreso, es_prestamo = :es_prestamo, es_pago_prestamo = 0, es_pago_interes = 0, es_polla = 0, activo = 1 WHERE id_actividad = :id");
        ejecutarLiquidacionStatement($stmtUpdate, $paramsUpdate);
        return $id;
    }

    $stmtInsertActividad = $pdo->prepare("INSERT INTO actividades_maestro (nombre_actividad, descripcion, afecta_saldo_socio, afecta_saldo_natillera, es_ingreso, es_prestamo, es_pago_prestamo, es_pago_interes, es_polla, activo) VALUES (:nombre, :descripcion, :afecta_socio, :afecta_natillera, :es_ingreso, :es_prestamo, 0, 0, 0, 1)");
    ejecutarLiquidacionStatement($stmtInsertActividad, $data);
    return (int) $pdo->lastInsertId();
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
        $detalle = json_decode((string) ($liq['detalle_preliquidacion'] ?? '{}'), true);
        $idPrestamoNuevo = (int) ($liq['prestamo_nuevo_id'] ?? 0);
        if ($idPrestamoNuevo > 0) {
            $stmtPagosNuevo = $pdo->prepare('SELECT COUNT(*) FROM cuotas_prestamo WHERE id_prestamo = :id AND fecha_pago IS NOT NULL');
            $stmtPagosNuevo->execute([':id' => $idPrestamoNuevo]);
            if ((int) $stmtPagosNuevo->fetchColumn() > 0) {
                throw new RuntimeException('No se puede reversar: el nuevo préstamo ya tiene pagos posteriores.');
            }
            $pdo->prepare('DELETE FROM periodos_prestamo WHERE id_prestamo = :id')->execute([':id' => $idPrestamoNuevo]);
            $pdo->prepare('DELETE FROM cuotas_prestamo WHERE id_prestamo = :id')->execute([':id' => $idPrestamoNuevo]);
            $pdo->prepare('DELETE FROM movimientos WHERE id_prestamo = :id AND modulo IN ("prestamos", "liquidaciones")')->execute([':id' => $idPrestamoNuevo]);
            $pdo->prepare('DELETE FROM prestamos WHERE id_prestamo = :id')->execute([':id' => $idPrestamoNuevo]);
        }

        foreach (extraerIdsMovimientosLiquidacion($liq) as $idMovimiento) {
            borrarMovimientoSiExiste($pdo, $idMovimiento);
        }

        if (is_array($detalle) && !empty($detalle['prestamos_originales'])) {
            $stmtRestore = $pdo->prepare('UPDATE prestamos SET saldo_capital_actual = :capital, saldo_intereses_actual = :intereses, estado = :estado WHERE id_prestamo = :id');
            foreach ($detalle['prestamos_originales'] as $prestamoOriginal) {
                $stmtRestore->execute([
                    ':capital' => (float) ($prestamoOriginal['saldo_capital_actual'] ?? $prestamoOriginal['capital_pendiente'] ?? 0),
                    ':intereses' => (float) ($prestamoOriginal['saldo_intereses_actual'] ?? $prestamoOriginal['intereses_pendientes'] ?? 0),
                    ':estado' => (string) ($prestamoOriginal['estado'] ?? 'Activo'),
                    ':id' => (int) $prestamoOriginal['id_prestamo'],
                ]);
            }
        }

        $estadoAnterior = json_decode((string) ($liq['estado_anterior_socio'] ?? '{}'), true);
        $pdo->prepare('UPDATE socios SET activo = :activo, estado_socio = :estado_socio, clasificacion = :clasificacion WHERE id_socio = :id')->execute([
            ':activo' => (int) ($estadoAnterior['activo'] ?? 1),
            ':estado_socio' => (string) ($estadoAnterior['estado_socio'] ?? 'Activo'),
            ':clasificacion' => $estadoAnterior['clasificacion'] ?? null,
            ':id' => (int) $liq['socio_id'],
        ]);

        $pdo->prepare('UPDATE liquidaciones SET estado = "reversada", fecha_reverso = NOW(), usuario_reverso = :usuario, motivo_reverso = :motivo WHERE id = :id')->execute([':id' => $idLiquidacion, ':usuario' => $_SESSION['usuario'] ?? null, ':motivo' => $motivoReverso]);
        recalcularSaldosDesdeMovimientos($pdo);
        $pdo->commit();
        $_SESSION['exito'] = 'Liquidación reversada correctamente.';
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

$insertLiq = $pdo->prepare('INSERT INTO liquidaciones (socio_id, estado_anterior_socio, tipo_liquidacion, saldo_base, valor_pollas, valor_prestamos, valor_cuota_manejo, valor_aplicado_deuda, deficit, saldo_pendiente, intereses_cubiertos, capital_cubierto, valor_bruto, valor_neto, actividad_liquidacion_id, actividad_cuota_id, actividad_fondo_id, movimiento_liquidacion_id, movimiento_cuota_id, movimiento_fondo_id, id_movimiento_compensacion, ids_prestamos_afectados, prestamo_nuevo_id, movimientos_generados, detalle_preliquidacion, fecha_preliquidacion, observaciones, fecha, usuario_id, estado)
VALUES (:socio, :estado_anterior_socio, :tipo, :saldo_base, :pollas, :prestamos, :cuota, :aplicado_deuda, :deficit, :saldo_pendiente, :intereses_cubiertos, :capital_cubierto, :bruto, :neto, :act_liq, :act_cuota, :act_fondo, :mov_liq, :mov_cuota, :mov_fondo, :mov_comp, :ids_prestamos, :prestamo_nuevo_id, :movs_json, :detalle_preliquidacion, :fecha_preliquidacion, :obs, :fecha, :usuario, :estado)');

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

    if ($accion === 'crear') {
        $stmtActiva = $pdo->prepare('SELECT COUNT(*) FROM liquidaciones WHERE socio_id = :socio AND estado = "activa"');
        $stmtActiva->execute([':socio' => $idSocio]);
        if ((int) $stmtActiva->fetchColumn() > 0) {
            throw new InvalidArgumentException('El socio ya tiene una liquidación activa.');
        }
    }

    if ((float) $calculo['deuda_total'] <= 0 && (float) $calculo['valor_neto'] <= 0) {
        throw new InvalidArgumentException('El cálculo de liquidación no es válido (sin valor para pagar, aplicar a deuda o registrar como saldo pendiente).');
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
    $stmtSocioAnterior = $pdo->prepare('SELECT activo, estado_socio, clasificacion FROM socios WHERE id_socio = :id');
    $stmtSocioAnterior->execute([':id' => $idSocio]);
    $estadoAnteriorSocio = $stmtSocioAnterior->fetch(PDO::FETCH_ASSOC) ?: ['activo' => 1, 'estado_socio' => 'Activo', 'clasificacion' => null];
    $prestamosOriginales = $calculo['prestamos_descontados'];
    $prestamoNuevoId = null;
    $saldoPendiente = (float) $calculo['deficit'];

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
                ejecutarLiquidacionStatement($insertMov, [
                    ':fecha' => $fecha, ':anio' => $anio, ':mes' => $mes, ':quincena' => $quincena,
                    ':id_socio' => $idSocio, ':id_prestamo' => $idPrestamo, ':id_actividad' => (int) $actividadPagoInteresLiquidacion['id_actividad'],
                    ':motivo' => 'Pago de intereses por liquidación préstamo #' . $idPrestamo . ' - ' . $calculo['socio']['nombre_completo'],
                    ':valor' => abs($interesCubierto), ':medio' => 'Liquidaciones', ':ingreso' => 0, ':egreso' => 0,
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
                ejecutarLiquidacionStatement($insertMov, [
                    ':fecha' => $fecha, ':anio' => $anio, ':mes' => $mes, ':quincena' => $quincena,
                    ':id_socio' => $idSocio, ':id_prestamo' => $idPrestamo, ':id_actividad' => (int) $actividadPagoCapitalLiquidacion['id_actividad'],
                    ':motivo' => 'Pago de capital por liquidación préstamo #' . $idPrestamo . ' - ' . $calculo['socio']['nombre_completo'],
                    ':valor' => abs($capitalCubiertoPrestamo), ':medio' => 'Liquidaciones', ':ingreso' => 0, ':egreso' => 0,
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
    $idsPrestamosAfectados = array_values(array_unique(array_merge(
        $idsPrestamosAfectados,
        array_map(static fn($p) => (int) $p['id_prestamo'], $calculo['prestamos_descontados'])
    )));
    if (!empty($idsPrestamosAfectados)) {
        $pdo->prepare("UPDATE prestamos SET estado = 'Cancelado por liquidación', saldo_capital_actual = 0, saldo_intereses_actual = 0 WHERE id_prestamo IN (" . implode(',', array_fill(0, count($idsPrestamosAfectados), '?')) . ")")
            ->execute($idsPrestamosAfectados);
    }

    if (!empty($calculo['prestamos_descontados'])) {
        $idActividadCancelacion = asegurarActividadLiquidacionSimple($pdo, 'Cancelación préstamo por liquidación', 'Cancelación contable del préstamo original por liquidación', 'suma', 'neutral');
        foreach ($calculo['prestamos_descontados'] as $prestamoCancelado) {
            $capitalCancelado = (float) $prestamoCancelado['capital_pendiente'];
            if ($capitalCancelado <= 0.01) {
                continue;
            }
            $idPrestamoCancelado = (int) $prestamoCancelado['id_prestamo'];
            ejecutarLiquidacionStatement($insertMov, [
                ':fecha' => $fecha, ':anio' => $anio, ':mes' => $mes, ':quincena' => $quincena,
                ':id_socio' => $idSocio, ':id_prestamo' => $idPrestamoCancelado, ':id_actividad' => $idActividadCancelacion,
                ':motivo' => 'Cancelación préstamo por liquidación #' . $idPrestamoCancelado,
                ':valor' => abs($capitalCancelado), ':medio' => 'Liquidaciones', ':ingreso' => 0, ':egreso' => 0,
                ':obs' => 'Cancelación del préstamo original por liquidación.',
                ':usuario' => $usuario, ':modulo' => 'liquidaciones',
            ]);
            $idMovCancelacion = (int) $pdo->lastInsertId();
            $movimientosGenerados[] = ['tipo' => 'cancelacion_prestamo_original_liquidacion', 'id_movimiento' => $idMovCancelacion, 'id_prestamo' => $idPrestamoCancelado, 'valor' => $capitalCancelado];
        }
    }

    if ($cuotaManejo > 0 && $saldoPendiente > 0.01) {
        $idActividadCuotaPendiente = asegurarActividadLiquidacionSimple($pdo, 'Cuota administración liquidación pendiente', 'Cuota de administración capitalizada en saldo pendiente de liquidación', 'neutral', 'neutral');
        ejecutarLiquidacionStatement($insertMov, [
            ':fecha' => $fecha, ':anio' => $anio, ':mes' => $mes, ':quincena' => $quincena,
            ':id_socio' => $idSocio, ':id_prestamo' => null, ':id_actividad' => $idActividadCuotaPendiente,
            ':motivo' => 'Cuota administración capitalizada en liquidación - ' . $calculo['socio']['nombre_completo'],
            ':valor' => abs($cuotaManejo), ':medio' => 'Liquidaciones', ':ingreso' => 0, ':egreso' => 0,
            ':obs' => 'Cuota de administración incluida en el saldo pendiente de liquidación.',
            ':usuario' => $usuario, ':modulo' => 'liquidaciones',
        ]);
        $idMovCuotaPendiente = (int) $pdo->lastInsertId();
        $movimientosGenerados[] = ['tipo' => 'cuota_administracion_saldo_pendiente', 'id_movimiento' => $idMovCuotaPendiente, 'valor' => abs((float) $cuotaManejo)];
    }

    if ($saldoPendiente > 0.01) {
        $stmtInsertPrestamoPendiente = $pdo->prepare("INSERT INTO prestamos (id_socio, es_particular, id_socio_aval, nombre_deudor, fecha_prestamo, monto_prestamo, tasa_interes, interes_mensual, saldo_capital_actual, saldo_intereses_actual, estado, clasificacion_cartera) VALUES (:socio, 0, NULL, NULL, :fecha, :monto, 0, 0, :saldo, 0, 'Activo', 'Socio retirado con deuda pendiente')");
        ejecutarLiquidacionStatement($stmtInsertPrestamoPendiente, [':socio' => $idSocio, ':fecha' => $fecha, ':monto' => $saldoPendiente, ':saldo' => $saldoPendiente]);
        $prestamoNuevoId = (int) $pdo->lastInsertId();
        $idActividadPrestamoRetiro = asegurarActividadLiquidacionSimple($pdo, 'Creación préstamo por retiro', 'Ingreso a cartera por saldo pendiente de socio retirado', 'resta', 'neutral', 1, 1);
        ejecutarLiquidacionStatement($insertMov, [
            ':fecha' => $fecha, ':anio' => $anio, ':mes' => $mes, ':quincena' => $quincena,
            ':id_socio' => $idSocio, ':id_prestamo' => $prestamoNuevoId, ':id_actividad' => $idActividadPrestamoRetiro,
            ':motivo' => 'Creación préstamo por saldo pendiente de retiro',
            ':valor' => abs($saldoPendiente), ':medio' => 'Liquidaciones', ':ingreso' => 1, ':egreso' => 0,
            ':obs' => 'Creación de préstamo por saldo pendiente de liquidación.',
            ':usuario' => $usuario, ':modulo' => 'liquidaciones',
        ]);
        $idMovPrestamoNuevo = (int) $pdo->lastInsertId();
        $movimientosGenerados[] = ['tipo' => 'prestamo_saldo_pendiente_liquidacion', 'id_movimiento' => $idMovPrestamoNuevo, 'id_prestamo' => $prestamoNuevoId, 'valor' => $saldoPendiente];
    }

    $stmtRetirarSocio = $pdo->prepare("UPDATE socios SET activo = 0, estado_socio = :estado, clasificacion = :clasificacion WHERE id_socio = :id");
    ejecutarLiquidacionStatement($stmtRetirarSocio, [
        ':estado' => $saldoPendiente > 0.01 ? 'Retirado con deuda pendiente' : 'Retirado',
        ':clasificacion' => $saldoPendiente > 0.01 ? 'Socio retirado con deuda pendiente' : 'Socio retirado',
        ':id' => $idSocio,
    ]);

    if ((float) $calculo['valor_neto'] > 0) {
        ejecutarLiquidacionStatement($insertMov, [
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
        ejecutarLiquidacionStatement($insertMov, [
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

    ejecutarLiquidacionStatement($insertLiq, [
        ':socio' => $idSocio,
        ':estado_anterior_socio' => json_encode($estadoAnteriorSocio, JSON_UNESCAPED_UNICODE),
        ':tipo' => $tipoLiquidacion,
        ':saldo_base' => $calculo['saldo_base'],
        ':pollas' => $calculo['valor_pollas'],
        ':prestamos' => $calculo['valor_prestamos'],
        ':cuota' => $calculo['valor_cuota_manejo'],
        ':aplicado_deuda' => $calculo['valor_aplicado_deuda'],
        ':deficit' => $calculo['deficit'],
        ':saldo_pendiente' => $saldoPendiente,
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
        ':prestamo_nuevo_id' => $prestamoNuevoId,
        ':movs_json' => json_encode($movimientosGenerados),
        ':detalle_preliquidacion' => json_encode([
            'socio' => $calculo['socio'],
            'saldo_base' => $calculo['saldo_base'],
            'saldo_actual_socio' => $calculo['saldo_actual_socio'],
            'ahorro_acumulado_bruto' => $calculo['ahorro_acumulado_bruto'],
            'rendimientos' => $calculo['rendimientos'],
            'valor_pollas' => $calculo['valor_pollas'],
            'prestamos_descontados' => $calculo['prestamos_descontados'],
            'prestamos_originales' => $prestamosOriginales,
            'valor_prestamos' => $calculo['valor_prestamos'],
            'deuda_total' => $calculo['deuda_total'],
            'saldo_liquidacion' => $calculo['saldo_liquidacion'],
            'valor_aplicado_deuda' => $calculo['valor_aplicado_deuda'],
            'intereses_cubiertos' => $interesesCubiertos,
            'capital_cubierto' => $capitalCubierto,
            'ids_prestamos_afectados' => array_values(array_unique($idsPrestamosAfectados)),
            'prestamo_nuevo_id' => $prestamoNuevoId,
            'saldo_pendiente' => $saldoPendiente,
            'deficit' => $calculo['deficit'],
            'valor_cuota_manejo' => $calculo['valor_cuota_manejo'],
            'valor_bruto' => $calculo['valor_bruto'],
            'valor_neto' => $calculo['valor_neto'],
            'advertencia_deficit' => $calculo['deficit'] > 0 ? 'El saldo de liquidación es negativo. Se creó un nuevo préstamo por el saldo pendiente exacto y el socio quedó retirado con deuda pendiente.' : null,
        ], JSON_UNESCAPED_UNICODE),
        ':fecha_preliquidacion' => $calculo['fecha_preliquidacion'],
        ':obs' => $observaciones,
        ':fecha' => $fecha,
        ':usuario' => $usuario,
        ':estado' => 'activa',
    ]);

    $idLiquidacionCreada = (int) $pdo->lastInsertId();
    $idsMovimientosLiquidacion = array_values(array_filter(array_map(static fn($mov) => (int) ($mov['id_movimiento'] ?? 0), $movimientosGenerados), static fn($id) => $id > 0));
    if (!empty($idsMovimientosLiquidacion)) {
        $placeholdersMovs = implode(',', array_fill(0, count($idsMovimientosLiquidacion), '?'));
        $pdo->prepare("UPDATE movimientos SET id_liquidacion = ?, motivo = CONCAT('Liquidación #', ?, ' - ', motivo), observaciones = CONCAT('Liquidación #', ?, '. ', COALESCE(observaciones, '')) WHERE id_movimiento IN ($placeholdersMovs)")
            ->execute(array_merge([$idLiquidacionCreada, $idLiquidacionCreada, $idLiquidacionCreada], $idsMovimientosLiquidacion));
        $stmtUpdateLiquidacionMovs = $pdo->prepare('UPDATE liquidaciones SET movimientos_generados = :movs_json WHERE id = :id');
        ejecutarLiquidacionStatement($stmtUpdateLiquidacionMovs, [':movs_json' => json_encode($movimientosGenerados), ':id' => $idLiquidacionCreada]);
    }

    recalcularSaldosDesdeMovimientos($pdo);
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
