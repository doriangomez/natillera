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

if ($cuotaManejo > 0 && $idActividadRetencion <= 0) {
    $_SESSION['error'] = 'Para cuota de administración debe seleccionar la actividad de retención.';
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

if ($cuotaManejo > 0 && !$actividadRetencion) {
    $_SESSION['error'] = 'La actividad de retención de administración no es válida.';
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
    $reglaSocioRetencion = normalizarReglaAfectacion($actividadRetencion['afecta_saldo_socio'] ?? 'neutral');
    $reglaNatilleraRetencion = normalizarReglaAfectacion($actividadRetencion['afecta_saldo_natillera'] ?? 'neutral');
    if ($reglaSocioRetencion !== 'resta' || $reglaNatilleraRetencion !== 'resta') {
        $_SESSION['error'] = 'Actividad retención inválida: debe restar saldo socio y saldo natillera.';
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

$insertLiq = $pdo->prepare('INSERT INTO liquidaciones (socio_id, tipo_liquidacion, saldo_base, valor_pollas, valor_prestamos, valor_cuota_manejo, valor_bruto, valor_neto, actividad_liquidacion_id, actividad_cuota_id, actividad_fondo_id, movimiento_liquidacion_id, movimiento_cuota_id, movimiento_fondo_id, movimientos_generados, observaciones, fecha, usuario_id, estado)
VALUES (:socio, :tipo, :saldo_base, :pollas, :prestamos, :cuota, :bruto, :neto, :act_liq, :act_cuota, :act_fondo, :mov_liq, :mov_cuota, :mov_fondo, :movs_json, :obs, :fecha, :usuario, :estado)');

$pdo->beginTransaction();
try {
    if ($accion === 'editar' && $liquidacionBase) {
        foreach (extraerIdsMovimientosLiquidacion($liquidacionBase) as $idMovimiento) {
            borrarMovimientoSiExiste($pdo, $idMovimiento);
        }
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
    $movimientosGenerados = [[
        'tipo' => 'pago_socio',
        'id_movimiento' => $movPrincipalId,
        'id_actividad' => $idActividadLiquidacion,
        'valor' => abs((float) $calculo['valor_neto']),
    ]];

    $movRetencionId = null;
    if ($cuotaManejo > 0) {
        $insertMov->execute([
            ':fecha' => $fecha,
            ':anio' => $anio,
            ':mes' => $mes,
            ':quincena' => $quincena,
            ':id_socio' => $idSocio,
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
        ':bruto' => $calculo['valor_bruto'],
        ':neto' => $calculo['valor_neto'],
        ':act_liq' => $idActividadLiquidacion,
        ':act_cuota' => $idActividadRetencion ?: null,
        ':act_fondo' => null,
        ':mov_liq' => $movPrincipalId,
        ':mov_cuota' => $movRetencionId,
        ':mov_fondo' => null,
        ':movs_json' => json_encode($movimientosGenerados),
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
