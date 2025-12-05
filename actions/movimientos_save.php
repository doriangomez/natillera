<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();

$accion = $_POST['accion'] ?? 'guardar';
$idMovimiento = isset($_POST['id_movimiento']) ? (int) $_POST['id_movimiento'] : 0;
$redirect = $_POST['redirect'] ?? '../public/movimientos.php';

if ($accion === 'eliminar') {
    $movInfo = null;
    if ($idMovimiento > 0) {
        $stmtInfo = $pdo->prepare('SELECT id_prestamo, modulo, fecha FROM movimientos WHERE id_movimiento = :id');
        $stmtInfo->execute([':id' => $idMovimiento]);
        $movInfo = $stmtInfo->fetch();

        $stmt = $pdo->prepare('DELETE FROM movimientos WHERE id_movimiento = :id LIMIT 1');
        $stmt->execute([':id' => $idMovimiento]);
    }

    if (!empty($movInfo['id_prestamo'])) {
        $idPrestamo = (int) $movInfo['id_prestamo'];

        if (($movInfo['modulo'] ?? '') === 'cuotas' && !empty($movInfo['fecha'])) {
            $stmtRestoMovs = $pdo->prepare(
                'SELECT COUNT(*) FROM movimientos WHERE id_prestamo = :id AND modulo = :mod AND fecha = :fecha'
            );
            $stmtRestoMovs->execute([
                ':id' => $idPrestamo,
                ':mod' => 'cuotas',
                ':fecha' => $movInfo['fecha'],
            ]);

            if ((int) $stmtRestoMovs->fetchColumn() === 0) {
                $stmtDelCuota = $pdo->prepare('DELETE FROM cuotas_prestamo WHERE id_prestamo = :id AND fecha_pago = :fecha');
                $stmtDelCuota->execute([
                    ':id' => $idPrestamo,
                    ':fecha' => $movInfo['fecha'],
                ]);
            }
        }

        recalcularPrestamoDesdeMovimientos($pdo, $idPrestamo);
    }

    recalcularSaldosDesdeMovimientos($pdo);
    header('Location: ' . $redirect);
    exit;
}

$fecha = $_POST['fecha'];
$anio = isset($_POST['anio']) ? (int) $_POST['anio'] : null;
$mes = isset($_POST['mes']) ? (int) $_POST['mes'] : null;
$quincena = isset($_POST['quincena']) ? (int) $_POST['quincena'] : null;
$idSocio = (isset($_POST['id_socio']) && $_POST['id_socio'] !== '') ? (int) $_POST['id_socio'] : null;
$idActividad = (int) $_POST['id_actividad'];
$valor = (float) $_POST['valor'];
$idPrestamo = isset($_POST['id_prestamo']) ? (int) $_POST['id_prestamo'] : null;
$motivo = '';

if (!$anio || !$mes) {
    $anio = (int) date('Y', strtotime($fecha));
    $mes = (int) date('n', strtotime($fecha));
}
$fechaObj = DateTime::createFromFormat('Y-m-d', $fecha);
if (!$fechaObj) {
    $_SESSION['error'] = 'La fecha del movimiento no es válida.';
    header('Location: ../public/movimientos.php');
    exit;
}
$medio = $_POST['medio_consignacion'] ?? '';
$idMedio = isset($_POST['id_medio_pago']) ? (int) $_POST['id_medio_pago'] : null;
$obs = $_POST['observaciones'] ?? '';

$camposObligatorios = [
    'fecha' => $fecha,
    'anio' => $anio,
    'mes' => $mes,
    'quincena' => $quincena,
    'socio' => $idSocio,
    'actividad' => $idActividad,
    'valor' => $valor,
];
foreach ($camposObligatorios as $campo => $dato) {
    if ($dato === '' || $dato === null) {
        $_SESSION['error'] = 'No se puede guardar el movimiento: falta ' . $campo . '.';
        header('Location: ../public/movimientos.php');
        exit;
    }
}
if ($valor <= 0) {
    $_SESSION['error'] = 'El valor del movimiento debe ser mayor a cero.';
    header('Location: ../public/movimientos.php');
    exit;
}

// Validar periodo permitido (dic 2025 a nov 2026) y consistencia de fecha
$anioFecha = (int) $fechaObj->format('Y');
$mesFecha = (int) $fechaObj->format('n');
if ($anioFecha !== $anio || $mesFecha !== $mes) {
    $_SESSION['error'] = 'El año y el mes seleccionados deben coincidir con la fecha del movimiento.';
    header('Location: ../public/movimientos.php');
    exit;
}
// Validar que el periodo exista y esté activo en la configuración
$stmtPeriodo = $pdo->prepare('SELECT COUNT(*) FROM periodos_configuracion WHERE anio = :anio AND mes = :mes AND activo = 1');
$stmtPeriodo->execute([':anio' => $anio, ':mes' => $mes]);
if ((int) $stmtPeriodo->fetchColumn() === 0) {
    $_SESSION['error'] = 'El periodo seleccionado no está habilitado en la configuración.';
    header('Location: ../public/movimientos.php');
    exit;
}
if (!in_array($quincena, [1, 2], true)) {
    $_SESSION['error'] = 'Debe seleccionar si el movimiento corresponde a la primera o segunda quincena.';
    header('Location: ../public/movimientos.php');
    exit;
}

if (!$medio && $idMedio) {
    $medioInfo = getMedioPago($pdo, $idMedio);
    $medio = $medioInfo['nombre'] ?? '';
}
$medio = trim($medio);
if (!$medio) {
    $_SESSION['error'] = 'Debe seleccionar o registrar un medio de pago.';
    header('Location: ../public/movimientos.php');
    exit;
}

$actividad = getActividad($pdo, $idActividad);
$reglaNatillera = normalizarReglaAfectacion($actividad['afecta_saldo_natillera'] ?? 'neutral');
$reglaSocio = !empty($actividad['es_polla']) ? 'neutral' : normalizarReglaAfectacion($actividad['afecta_saldo_socio'] ?? 'neutral');
$esIngreso = $reglaNatillera === 'suma' ? 1 : 0;
$esEgreso = $reglaNatillera === 'resta' ? 1 : 0;

if ($actividad && !empty($actividad['es_polla']) && !$idSocio) {
    $_SESSION['error'] = 'Debe seleccionar un socio para registrar movimientos de polla.';
    header('Location: ../public/movimientos.php');
    exit;
}
if ($esEgreso) {
    $valor = -abs($valor);
}

$stmt = $pdo->prepare('INSERT INTO movimientos (fecha, anio, mes, quincena, id_socio, id_prestamo, id_actividad, motivo, valor, medio_consignacion, id_medio_pago, es_ingreso, es_egreso, observaciones, usuario_registro, fecha_registro, modulo)
VALUES (:fecha, :anio, :mes, :quincena, :id_socio, :id_prestamo, :id_actividad, :motivo, :valor, :medio, :medio_id, :ingreso, :egreso, :obs, :usuario, NOW(), :modulo)');
$stmt->execute([
    ':fecha' => $fecha,
    ':anio' => $anio,
    ':mes' => $mes,
    ':quincena' => $quincena,
    ':id_socio' => $idSocio,
    ':id_prestamo' => $idPrestamo ?: null,
    ':id_actividad' => $idActividad,
    ':motivo' => $motivo,
    ':valor' => $valor,
    ':medio' => $medio,
    ':medio_id' => $idMedio,
    ':ingreso' => $esIngreso,
    ':egreso' => $esEgreso,
    ':obs' => $obs,
    ':usuario' => $_SESSION['usuario'] ?? null,
    ':modulo' => 'movimientos',
]);

actualizarSaldoSocio($pdo, $idSocio, $valor, $reglaSocio);
actualizarSaldoNatillera($pdo, $valor, $reglaNatillera);
recalcularSaldosDesdeMovimientos($pdo);

header('Location: ../public/movimientos.php');
?>
