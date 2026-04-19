<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();

$idSocio = isset($_POST['id_socio']) ? (int) $_POST['id_socio'] : 0;
$cuotaManejo = isset($_POST['cuota_manejo']) ? (float) $_POST['cuota_manejo'] : 0.0;
$idActividadDevolucion = isset($_POST['id_actividad_devolucion']) ? (int) $_POST['id_actividad_devolucion'] : 0;
$idActividadCuota = isset($_POST['id_actividad_cuota']) ? (int) $_POST['id_actividad_cuota'] : 0;

$redirect = '../public/liquidacion_anticipada.php?id_socio=' . $idSocio
    . '&cuota_manejo=' . urlencode((string) $cuotaManejo)
    . '&id_actividad_devolucion=' . $idActividadDevolucion
    . '&id_actividad_cuota=' . $idActividadCuota;

if ($idSocio <= 0 || $idActividadDevolucion <= 0 || $idActividadCuota <= 0) {
    $_SESSION['error'] = 'Faltan datos obligatorios para registrar la liquidación anticipada.';
    header('Location: ' . $redirect);
    exit;
}

$stmtSocio = $pdo->prepare('SELECT id_socio, nombre_completo, activo FROM socios WHERE id_socio = :id');
$stmtSocio->execute([':id' => $idSocio]);
$socio = $stmtSocio->fetch(PDO::FETCH_ASSOC);
if (!$socio || (int) ($socio['activo'] ?? 0) !== 1) {
    $_SESSION['error'] = 'El socio seleccionado no está activo o no existe.';
    header('Location: ' . $redirect);
    exit;
}

$actividadDevolucion = getActividad($pdo, $idActividadDevolucion);
$actividadCuota = getActividad($pdo, $idActividadCuota);

if (!$actividadDevolucion || !$actividadCuota) {
    $_SESSION['error'] = 'Debe seleccionar actividades válidas para registrar la liquidación.';
    header('Location: ' . $redirect);
    exit;
}

$reglaNatilleraCuota = normalizarReglaAfectacion($actividadCuota['afecta_saldo_natillera'] ?? 'neutral');
if ($reglaNatilleraCuota !== 'neutral') {
    $_SESSION['error'] = 'La actividad de cuota de manejo debe tener "Afecta saldo natillera" en neutral.';
    header('Location: ' . $redirect);
    exit;
}

$stmtTotales = $pdo->prepare(
    "SELECT
        COALESCE(SUM(CASE
            WHEN m.es_ingreso = 1
            AND COALESCE(a.es_prestamo,0) = 0
            AND COALESCE(a.es_pago_prestamo,0) = 0
            AND COALESCE(a.es_pago_interes,0) = 0
            AND COALESCE(a.es_polla,0) = 0
            THEN m.valor ELSE 0 END),0) AS ingresos_liquidables
    FROM movimientos m
    JOIN actividades_maestro a ON m.id_actividad = a.id_actividad
    WHERE m.id_socio = :id"
);
$stmtTotales->execute([':id' => $idSocio]);
$ingresosLiquidables = (float) $stmtTotales->fetchColumn();

$stmtPrestamos = $pdo->prepare(
    'SELECT
        COALESCE(SUM(saldo_capital_actual),0) AS saldo_capital,
        COALESCE(SUM(saldo_intereses_actual),0) AS saldo_intereses
     FROM prestamos
     WHERE id_socio = :id AND estado = "vigente"'
);
$stmtPrestamos->execute([':id' => $idSocio]);
$prestamos = $stmtPrestamos->fetch(PDO::FETCH_ASSOC) ?: ['saldo_capital' => 0, 'saldo_intereses' => 0];

$saldoPrestamos = (float) $prestamos['saldo_capital'] + (float) $prestamos['saldo_intereses'];
$valorNeto = $ingresosLiquidables - $saldoPrestamos - $cuotaManejo;

if ($valorNeto <= 0) {
    $_SESSION['error'] = 'El valor neto a devolver es menor o igual a cero. No se registra liquidación definitiva.';
    header('Location: ' . $redirect);
    exit;
}

$fecha = date('Y-m-d');
$anio = (int) date('Y');
$mes = (int) date('n');
$quincena = (int) ((int) date('j') <= 15 ? 1 : 2);
$usuario = $_SESSION['usuario'] ?? null;

$insertMovimiento = $pdo->prepare('INSERT INTO movimientos (fecha, anio, mes, quincena, id_socio, id_actividad, motivo, valor, medio_consignacion, es_ingreso, es_egreso, observaciones, usuario_registro, fecha_registro, modulo)
VALUES (:fecha, :anio, :mes, :quincena, :id_socio, :id_actividad, :motivo, :valor, :medio, :es_ingreso, :es_egreso, :obs, :usuario, NOW(), :modulo)');

$pdo->beginTransaction();

try {
    $reglaNatilleraDev = normalizarReglaAfectacion($actividadDevolucion['afecta_saldo_natillera'] ?? 'neutral');
    $reglaSocioDev = normalizarReglaAfectacion($actividadDevolucion['afecta_saldo_socio'] ?? 'neutral');
    $esIngresoDev = $reglaNatilleraDev === 'suma' ? 1 : 0;
    $esEgresoDev = $reglaNatilleraDev === 'resta' ? 1 : 0;
    $valorDev = $esEgresoDev ? -abs($valorNeto) : abs($valorNeto);

    $insertMovimiento->execute([
        ':fecha' => $fecha,
        ':anio' => $anio,
        ':mes' => $mes,
        ':quincena' => $quincena,
        ':id_socio' => $idSocio,
        ':id_actividad' => $idActividadDevolucion,
        ':motivo' => 'Devolución socio ' . $socio['nombre_completo'] . ' (liquidación anticipada)',
        ':valor' => $valorDev,
        ':medio' => 'Liquidación anticipada',
        ':es_ingreso' => $esIngresoDev,
        ':es_egreso' => $esEgresoDev,
        ':obs' => 'Registro definitivo de liquidación anticipada.',
        ':usuario' => $usuario,
        ':modulo' => 'liquidacion_anticipada',
    ]);

    actualizarSaldoSocio($pdo, $idSocio, $valorDev, $reglaSocioDev);
    actualizarSaldoNatillera($pdo, $valorDev, $reglaNatilleraDev);

    if ($cuotaManejo > 0) {
        $reglaSocioCuota = normalizarReglaAfectacion($actividadCuota['afecta_saldo_socio'] ?? 'neutral');
        $insertMovimiento->execute([
            ':fecha' => $fecha,
            ':anio' => $anio,
            ':mes' => $mes,
            ':quincena' => $quincena,
            ':id_socio' => $idSocio,
            ':id_actividad' => $idActividadCuota,
            ':motivo' => 'Cuota de manejo liquidación anticipada - ' . $socio['nombre_completo'],
            ':valor' => abs($cuotaManejo),
            ':medio' => 'Liquidación anticipada',
            ':es_ingreso' => 0,
            ':es_egreso' => 0,
            ':obs' => 'Cuota de manejo de liquidación anticipada. No afecta natillera.',
            ':usuario' => $usuario,
            ':modulo' => 'liquidacion_anticipada',
        ]);

        actualizarSaldoSocio($pdo, $idSocio, abs($cuotaManejo), $reglaSocioCuota);
    }

    $pdo->commit();
    $_SESSION['exito'] = 'Liquidación anticipada registrada definitivamente en movimientos.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error'] = 'No fue posible registrar la liquidación anticipada: ' . $e->getMessage();
}

header('Location: ' . $redirect);
exit;
