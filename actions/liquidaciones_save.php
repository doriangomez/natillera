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
        borrarMovimientoSiExiste($pdo, isset($liq['movimiento_liquidacion_id']) ? (int) $liq['movimiento_liquidacion_id'] : 0);
        borrarMovimientoSiExiste($pdo, isset($liq['movimiento_cuota_id']) ? (int) $liq['movimiento_cuota_id'] : 0);
        borrarMovimientoSiExiste($pdo, isset($liq['movimiento_fondo_id']) ? (int) $liq['movimiento_fondo_id'] : 0);

        $pdo->prepare('UPDATE liquidaciones SET estado = "anulada" WHERE id = :id')->execute([':id' => $idLiquidacion]);
        recalcularSaldosDesdeMovimientos($pdo);
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
$idActividadCuota = isset($_POST['id_actividad_cuota']) ? (int) $_POST['id_actividad_cuota'] : 0;
$idActividadFondo = isset($_POST['id_actividad_fondo']) ? (int) $_POST['id_actividad_fondo'] : 0;
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

if ($cuotaManejo > 0 && ($idActividadCuota <= 0 || $idActividadFondo <= 0)) {
    $_SESSION['error'] = 'Para cuota de administración debe seleccionar actividad de cuota y de fondo.';
    header('Location: ' . $redirect);
    exit;
}

$actividadLiquidacion = obtenerActividadValida($pdo, $idActividadLiquidacion);
$actividadCuota = $idActividadCuota > 0 ? obtenerActividadValida($pdo, $idActividadCuota) : null;
$actividadFondo = $idActividadFondo > 0 ? obtenerActividadValida($pdo, $idActividadFondo) : null;

if (!$actividadLiquidacion) {
    $_SESSION['error'] = 'La actividad principal de liquidación no es válida.';
    header('Location: ' . $redirect);
    exit;
}

if ($cuotaManejo > 0 && (!$actividadCuota || !$actividadFondo)) {
    $_SESSION['error'] = 'Las actividades de cuota/fondo de administración no son válidas.';
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

if ($cuotaManejo > 0) {
    $reglaSocioCuota = normalizarReglaAfectacion($actividadCuota['afecta_saldo_socio'] ?? 'neutral');
    $reglaNatilleraCuota = normalizarReglaAfectacion($actividadCuota['afecta_saldo_natillera'] ?? 'neutral');
    $reglaSocioFondo = normalizarReglaAfectacion($actividadFondo['afecta_saldo_socio'] ?? 'neutral');
    $reglaNatilleraFondo = normalizarReglaAfectacion($actividadFondo['afecta_saldo_natillera'] ?? 'neutral');
    if ($reglaSocioCuota !== 'resta' || $reglaNatilleraCuota !== 'neutral') {
        $_SESSION['error'] = 'Actividad cuota inválida: debe restar socio y ser neutral en natillera.';
        header('Location: ' . $redirect);
        exit;
    }
    if ($reglaSocioFondo !== 'neutral' || $reglaNatilleraFondo !== 'resta') {
        $_SESSION['error'] = 'Actividad fondo inválida: debe ser neutral en socio y restar natillera.';
        header('Location: ' . $redirect);
        exit;
    }
}

$calculo = calcularLiquidacionSocio($pdo, $idSocio, $cuotaManejo);
if (!$calculo) {
    $_SESSION['error'] = 'No se encontró el socio para liquidar.';
    header('Location: ' . $redirect);
    exit;
}

if ($calculo['valor_bruto'] <= 0 || $calculo['valor_neto'] < 0) {
    $_SESSION['error'] = 'El cálculo de liquidación no es válido (bruto <= 0 o neto negativo).';
    header('Location: ' . $redirect);
    exit;
}

$fecha = date('Y-m-d');
$anio = (int) date('Y');
$mes = (int) date('n');
$quincena = (int) ((int) date('j') <= 15 ? 1 : 2);
$usuario = $_SESSION['usuario'] ?? null;

$insertMov = $pdo->prepare('INSERT INTO movimientos (fecha, anio, mes, quincena, id_socio, id_actividad, motivo, valor, medio_consignacion, es_ingreso, es_egreso, observaciones, usuario_registro, fecha_registro, modulo)
VALUES (:fecha, :anio, :mes, :quincena, :id_socio, :id_actividad, :motivo, :valor, :medio, :ingreso, :egreso, :obs, :usuario, NOW(), :modulo)');

$insertLiq = $pdo->prepare('INSERT INTO liquidaciones (socio_id, tipo_liquidacion, saldo_base, valor_pollas, valor_prestamos, valor_cuota_manejo, valor_bruto, valor_neto, actividad_liquidacion_id, actividad_cuota_id, actividad_fondo_id, movimiento_liquidacion_id, movimiento_cuota_id, movimiento_fondo_id, observaciones, fecha, usuario_id, estado)
VALUES (:socio, :tipo, :saldo_base, :pollas, :prestamos, :cuota, :bruto, :neto, :act_liq, :act_cuota, :act_fondo, :mov_liq, :mov_cuota, :mov_fondo, :obs, :fecha, :usuario, :estado)');

$pdo->beginTransaction();
try {
    if ($accion === 'editar' && $liquidacionBase) {
        borrarMovimientoSiExiste($pdo, (int) ($liquidacionBase['movimiento_liquidacion_id'] ?? 0));
        borrarMovimientoSiExiste($pdo, (int) ($liquidacionBase['movimiento_cuota_id'] ?? 0));
        borrarMovimientoSiExiste($pdo, (int) ($liquidacionBase['movimiento_fondo_id'] ?? 0));
        $pdo->prepare('UPDATE liquidaciones SET estado = "editada" WHERE id = :id')->execute([':id' => $idLiquidacion]);
    }

    $insertMov->execute([
        ':fecha' => $fecha,
        ':anio' => $anio,
        ':mes' => $mes,
        ':quincena' => $quincena,
        ':id_socio' => $idSocio,
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

    $movCuotaId = null;
    $movFondoId = null;
    if ($cuotaManejo > 0) {
        $insertMov->execute([
            ':fecha' => $fecha,
            ':anio' => $anio,
            ':mes' => $mes,
            ':quincena' => $quincena,
            ':id_socio' => $idSocio,
            ':id_actividad' => $idActividadCuota,
            ':motivo' => 'Cuota administración liquidación - ' . $calculo['socio']['nombre_completo'],
            ':valor' => abs($cuotaManejo),
            ':medio' => 'Liquidaciones',
            ':ingreso' => 0,
            ':egreso' => 0,
            ':obs' => 'Descuento al socio por cuota de administración.',
            ':usuario' => $usuario,
            ':modulo' => 'liquidaciones',
        ]);
        $movCuotaId = (int) $pdo->lastInsertId();

        $insertMov->execute([
            ':fecha' => $fecha,
            ':anio' => $anio,
            ':mes' => $mes,
            ':quincena' => $quincena,
            ':id_socio' => null,
            ':id_actividad' => $idActividadFondo,
            ':motivo' => 'Fondo administración liquidaciones - ' . $calculo['socio']['nombre_completo'],
            ':valor' => -abs($cuotaManejo),
            ':medio' => 'Liquidaciones',
            ':ingreso' => 0,
            ':egreso' => 1,
            ':obs' => 'Traslado de cuota de administración al fondo.',
            ':usuario' => $usuario,
            ':modulo' => 'liquidaciones',
        ]);
        $movFondoId = (int) $pdo->lastInsertId();
    }

    $insertLiq->execute([
        ':socio' => $idSocio,
        ':tipo' => $tipoLiquidacion,
        ':saldo_base' => $calculo['saldo_base'],
        ':pollas' => $calculo['valor_pollas'],
        ':prestamos' => $calculo['valor_prestamos'],
        ':cuota' => $calculo['valor_cuota_manejo'],
        ':bruto' => $calculo['valor_bruto'],
        ':neto' => $calculo['valor_neto'],
        ':act_liq' => $idActividadLiquidacion,
        ':act_cuota' => $idActividadCuota ?: null,
        ':act_fondo' => $idActividadFondo ?: null,
        ':mov_liq' => $movPrincipalId,
        ':mov_cuota' => $movCuotaId,
        ':mov_fondo' => $movFondoId,
        ':obs' => $observaciones,
        ':fecha' => $fecha,
        ':usuario' => $usuario,
        ':estado' => 'activa',
    ]);

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
