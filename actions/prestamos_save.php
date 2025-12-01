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
                ':valor' => -abs((float) $prestamo['monto_prestamo']),
            ];
            $sqlDelDesembolso = 'DELETE FROM movimientos WHERE id_actividad = :id_act AND motivo = :motivo AND fecha = :fecha AND valor = :valor';
            $sqlDelDesembolso .= !empty($prestamo['id_socio']) ? ' AND id_socio = :id_socio' : ' AND id_socio IS NULL';
            $paramsDel[':motivo'] = 'Desembolso préstamo';
            if (!empty($prestamo['id_socio'])) {
                $paramsDel[':id_socio'] = $prestamo['id_socio'];
            }
            $sqlDelDesembolso .= ' LIMIT 1';
            $pdo->prepare($sqlDelDesembolso)->execute($paramsDel);
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
$esParticular = isset($_POST['es_particular']) ? (int) $_POST['es_particular'] : 0;
$idAval = $_POST['id_socio_aval'] ?: null;
$nombreDeudor = $_POST['nombre_deudor'] ?: null;
$monto = (float) $_POST['monto_prestamo'];
$tasa = (float) $_POST['tasa_interes'];
$cuotas = (int) $_POST['numero_cuotas'];

$stmt = $pdo->prepare('INSERT INTO prestamos (id_socio, es_particular, id_socio_aval, nombre_deudor, fecha_prestamo, monto_prestamo, tasa_interes, numero_cuotas, saldo_capital_actual, saldo_intereses_actual, estado) VALUES (:id_socio, :es_particular, :id_aval, :nombre_deudor, :fecha, :monto, :tasa, :cuotas, :saldo_capital_actual, :saldo_intereses_actual, :estado)');
$stmt->execute([
    ':id_socio' => $idSocio,
    ':es_particular' => $esParticular,
    ':id_aval' => $idAval,
    ':nombre_deudor' => $nombreDeudor,
    ':fecha' => $fecha,
    ':monto' => $monto,
    ':tasa' => $tasa,
    ':cuotas' => $cuotas,
    ':saldo_capital_actual' => $monto,
    ':saldo_intereses_actual' => 0,
    ':estado' => 'vigente'
]);
$idPrestamo = $pdo->lastInsertId();

$valorCuota = $cuotas > 0 ? $monto / $cuotas : $monto;
for ($i=1; $i<=$cuotas; $i++) {
    $fechaProg = date('Y-m-d', strtotime("+$i month", strtotime($fecha)));
    $stmtCuota = $pdo->prepare('INSERT INTO cuotas_prestamo (id_prestamo, numero_cuota, fecha_programada, valor_cuota, valor_capital_pagado, valor_interes_pagado, saldo_capital_despues, saldo_intereses_despues) VALUES (:id_prestamo, :num, :fecha_prog, :valor_cuota, 0, 0, :saldo_capital, 0)');
    $stmtCuota->execute([
        ':id_prestamo' => $idPrestamo,
        ':num' => $i,
        ':fecha_prog' => $fechaProg,
        ':valor_cuota' => $valorCuota,
        ':saldo_capital' => $monto - ($valorCuota*$i)
    ]);
}

// Registrar movimiento de desembolso si existe actividad marcada como préstamo
$actividadPrestamo = $pdo->query("SELECT id_actividad, afecta_saldo_socio, afecta_saldo_natillera FROM actividades_maestro WHERE es_prestamo=1 LIMIT 1")->fetch();
if ($actividadPrestamo) {
    $stmtMov = $pdo->prepare('INSERT INTO movimientos (fecha, id_socio, id_actividad, motivo, valor, medio_consignacion, es_ingreso, es_egreso, observaciones, usuario_registro, fecha_registro, modulo) VALUES (:fecha, :id_socio, :id_actividad, :motivo, :valor, :medio, 0, 1, :obs, :usuario, NOW(), :modulo)');
    $stmtMov->execute([
        ':fecha' => $fecha,
        ':id_socio' => $idSocio,
        ':id_actividad' => $actividadPrestamo['id_actividad'],
        ':motivo' => 'Desembolso préstamo',
        ':valor' => -abs($monto),
        ':medio' => 'Efectivo',
        ':obs' => 'Desembolso inicial',
        ':usuario' => $_SESSION['usuario'] ?? null,
        ':modulo' => 'prestamos',
    ]);
    actualizarSaldoSocio($pdo, $idSocio, -abs($monto), $actividadPrestamo['afecta_saldo_socio']);
    actualizarSaldoNatillera($pdo, -abs($monto), $actividadPrestamo['afecta_saldo_natillera']);
}

header('Location: ../public/prestamos.php');
?>
