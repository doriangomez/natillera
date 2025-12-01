<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();

$accion = $_POST['accion'] ?? 'guardar';
$idMovimiento = isset($_POST['id_movimiento']) ? (int) $_POST['id_movimiento'] : 0;
$redirect = $_POST['redirect'] ?? '../public/movimientos.php';

if ($accion === 'eliminar') {
    if ($idMovimiento > 0) {
        $stmt = $pdo->prepare('DELETE FROM movimientos WHERE id_movimiento = :id LIMIT 1');
        $stmt->execute([':id' => $idMovimiento]);
    }
    header('Location: ' . $redirect);
    exit;
}

$columnas = ['anio INT DEFAULT NULL', 'mes INT DEFAULT NULL', 'quincena INT DEFAULT 0'];
foreach ($columnas as $def) {
    try {
        $nombre = explode(' ', $def)[0];
        $existe = $pdo->query("SHOW COLUMNS FROM movimientos LIKE '$nombre'");
        if ($existe && $existe->rowCount() === 0) {
            $pdo->exec("ALTER TABLE movimientos ADD COLUMN $def");
        }
    } catch (Exception $e) {
        // continuar
    }
}

$fecha = $_POST['fecha'];
$anio = isset($_POST['anio']) ? (int) $_POST['anio'] : null;
$mes = isset($_POST['mes']) ? (int) $_POST['mes'] : null;
$quincena = isset($_POST['quincena']) ? (int) $_POST['quincena'] : 0;
$idSocio = $_POST['id_socio'] ?: null;
$idActividad = (int) $_POST['id_actividad'];
$valor = (float) $_POST['valor'];
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
$inicioPeriodo = new DateTime('2025-12-01');
$finPeriodo = new DateTime('2026-11-30 23:59:59');
$anioFecha = (int) $fechaObj->format('Y');
$mesFecha = (int) $fechaObj->format('n');
$periodoValido = $fechaObj >= $inicioPeriodo && $fechaObj <= $finPeriodo;
if (!$periodoValido) {
    $_SESSION['error'] = 'La fecha del movimiento debe estar entre diciembre 2025 y noviembre 2026.';
    header('Location: ../public/movimientos.php');
    exit;
}
if ($anioFecha !== $anio || $mesFecha !== $mes) {
    $_SESSION['error'] = 'El año y el mes seleccionados deben coincidir con la fecha del movimiento.';
    header('Location: ../public/movimientos.php');
    exit;
}
if ($quincena < 0 || $quincena > 2) {
    $quincena = 0;
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
$reglaNatillera = $actividad['afecta_saldo_natillera'] ?? 'neutral';
$esIngreso = $reglaNatillera === 'suma' ? 1 : 0;
$esEgreso = $reglaNatillera === 'resta' ? 1 : 0;

// Ajuste de quincena según periodicidad
if ($idSocio) {
    $stmtSocio = $pdo->prepare('SELECT periodicidad_pago FROM socios WHERE id_socio = :id');
    $stmtSocio->execute([':id' => $idSocio]);
    $socioInfo = $stmtSocio->fetch();
    if ($socioInfo && strtolower($socioInfo['periodicidad_pago']) !== 'quincenal') {
        $quincena = 0;
    }
}

if ($actividad && !empty($actividad['es_polla']) && !$idSocio) {
    $_SESSION['error'] = 'Debe seleccionar un socio para registrar movimientos de polla.';
    header('Location: ../public/movimientos.php');
    exit;
}
if ($actividad && !empty($actividad['es_polla']) && (float) abs($valor) != 20000) {
    $_SESSION['error'] = 'Cada registro de polla debe ser exactamente de $20.000.';
    header('Location: ../public/movimientos.php');
    exit;
}

if ($esEgreso) {
    $valor = -abs($valor);
}

$stmt = $pdo->prepare('INSERT INTO movimientos (fecha, anio, mes, quincena, id_socio, id_actividad, motivo, valor, medio_consignacion, id_medio_pago, es_ingreso, es_egreso, observaciones, usuario_registro, fecha_registro)
VALUES (:fecha, :anio, :mes, :quincena, :id_socio, :id_actividad, :motivo, :valor, :medio, :medio_id, :ingreso, :egreso, :obs, :usuario, NOW())');
$stmt->execute([
    ':fecha' => $fecha,
    ':anio' => $anio,
    ':mes' => $mes,
    ':quincena' => $quincena,
    ':id_socio' => $idSocio,
    ':id_actividad' => $idActividad,
    ':motivo' => $motivo,
    ':valor' => $valor,
    ':medio' => $medio,
    ':medio_id' => $idMedio,
    ':ingreso' => $esIngreso,
    ':egreso' => $esEgreso,
    ':obs' => $obs,
    ':usuario' => $_SESSION['usuario'] ?? null,
]);

actualizarSaldoSocio($pdo, $idSocio, $valor, $actividad['afecta_saldo_socio']);
actualizarSaldoNatillera($pdo, $valor, $actividad['afecta_saldo_natillera']);

header('Location: ../public/movimientos.php');
?>
