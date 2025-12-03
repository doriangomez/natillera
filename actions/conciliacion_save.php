<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

checkAuth();

$anio = isset($_POST['anio']) ? (int) $_POST['anio'] : 0;
$mes = isset($_POST['mes']) ? (int) $_POST['mes'] : 0;
$medioIds = $_POST['medio_ids'] ?? [];
$valoresConciliados = $_POST['valor_conciliado'] ?? [];
$notas = $_POST['nota'] ?? [];
$cerrarMes = isset($_POST['cerrar_mes']);

$redirect = '../public/conciliaciones.php?anio=' . $anio . '&mes=' . $mes;

$stmtPeriodo = $pdo->prepare('SELECT COUNT(*) FROM periodos_configuracion WHERE anio = :anio AND mes = :mes AND activo = 1');
$stmtPeriodo->execute([':anio' => $anio, ':mes' => $mes]);

if ($mes < 1 || $mes > 12 || (int) $stmtPeriodo->fetchColumn() === 0) {
    $_SESSION['error'] = 'El periodo seleccionado no está configurado o es inválido.';
    header('Location: ' . $redirect);
    exit;
}

if (empty($medioIds)) {
    $_SESSION['error'] = 'Debe existir al menos un medio de pago activo para conciliar.';
    header('Location: ' . $redirect);
    exit;
}

$stmtMesCerrado = $pdo->prepare('SELECT COUNT(*) FROM conciliaciones_medios_pago WHERE anio = :anio AND mes = :mes AND cerrado = 1');
$stmtMesCerrado->execute([':anio' => $anio, ':mes' => $mes]);
if ((int) $stmtMesCerrado->fetchColumn() > 0) {
    $_SESSION['error'] = 'Este mes ya fue conciliado. No es posible modificarlo.';
    header('Location: ' . $redirect);
    exit;
}

$pdo->beginTransaction();

try {
    $deleteStmt = $pdo->prepare('DELETE FROM conciliaciones_medios_pago WHERE anio = :anio AND mes = :mes');
    $deleteStmt->execute([':anio' => $anio, ':mes' => $mes]);

    $insertStmt = $pdo->prepare(
        'INSERT INTO conciliaciones_medios_pago (id_medio, anio, mes, saldo_sistema, valor_conciliado, diferencia, nota, cerrado)
         VALUES (:id_medio, :anio, :mes, :saldo_sistema, :valor_conciliado, :diferencia, :nota, 0)'
    );

    $hayDescuadre = false;

    $stmtTotales = $pdo->prepare(
        'SELECT COALESCE(m.id_medio_pago, mp_lookup.id) AS medio_id, '
        . 'COALESCE(SUM(CASE'
        . " WHEN a.afecta_saldo_natillera = 'suma' THEN m.valor"
        . " WHEN a.afecta_saldo_natillera = 'resta' THEN -m.valor"
        . ' ELSE 0 END), 0) AS total '
        . 'FROM movimientos m '
        . 'JOIN actividades_maestro a ON m.id_actividad = a.id_actividad '
        . 'LEFT JOIN medios_pago mp_lookup ON mp_lookup.nombre = m.medio_consignacion '
        . 'WHERE m.anio = :anio AND m.mes = :mes '
        . 'GROUP BY medio_id'
    );
    $stmtTotales->execute([':anio' => $anio, ':mes' => $mes]);
    $totalesSistema = [];
    foreach ($stmtTotales->fetchAll(PDO::FETCH_ASSOC) as $filaTotal) {
        if ($filaTotal['medio_id'] === null) {
            continue;
        }
        $totalesSistema[(int) $filaTotal['medio_id']] = (float) $filaTotal['total'];
    }

    foreach ($medioIds as $idMedio) {
        $idMedio = (int) $idMedio;
        $saldoSistema = $totalesSistema[$idMedio] ?? 0.0;

        $valorConciliadoRaw = $valoresConciliados[$idMedio] ?? null;
        if ($valorConciliadoRaw === null || $valorConciliadoRaw === '') {
            throw new InvalidArgumentException('Todos los medios deben tener un valor conciliado.');
        }
        if (!is_numeric($valorConciliadoRaw)) {
            throw new InvalidArgumentException('Los valores conciliados deben ser numéricos.');
        }

        $valorConciliado = (float) $valorConciliadoRaw;
        if ($valorConciliado < 0) {
            throw new InvalidArgumentException('Los valores conciliados deben ser mayores o iguales a cero.');
        }

        $nota = trim((string) ($notas[$idMedio] ?? ''));
        $diferencia = $saldoSistema - $valorConciliado;
        if (abs($diferencia) > 0.009) {
            $hayDescuadre = true;
        }

        $insertStmt->execute([
            ':id_medio' => $idMedio,
            ':anio' => $anio,
            ':mes' => $mes,
            ':saldo_sistema' => $saldoSistema,
            ':valor_conciliado' => $valorConciliado,
            ':diferencia' => $diferencia,
            ':nota' => $nota,
        ]);
    }

    if ($cerrarMes) {
        $pdo->prepare('UPDATE conciliaciones_medios_pago SET cerrado = 1 WHERE anio = :anio AND mes = :mes')
            ->execute([':anio' => $anio, ':mes' => $mes]);
    }

    $pdo->commit();

    $_SESSION['success'] = $cerrarMes
        ? 'Conciliación guardada y mes cerrado correctamente.'
        : 'Conciliación guardada correctamente.';

    if ($hayDescuadre) {
        $_SESSION['warning'] = 'Se detectaron diferencias en la conciliación. Verifique las notas y los valores registrados.';
    } else {
        unset($_SESSION['warning']);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error'] = $e->getMessage();
}

header('Location: ' . $redirect);
exit;
