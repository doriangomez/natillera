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

    $saldoStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(CASE'
        . " WHEN a.afecta_saldo_natillera = 'suma' THEN ABS(m.valor)"
        . " WHEN a.afecta_saldo_natillera = 'resta' THEN -ABS(m.valor)"
        . ' ELSE 0 END), 0) AS saldo '
        . 'FROM movimientos m '
        . 'JOIN actividades_maestro a ON m.id_actividad = a.id_actividad '
        . 'WHERE m.anio = :anio AND m.mes = :mes'
    );
    $saldoStmt->execute([':anio' => $anio, ':mes' => $mes]);
    $saldoSistema = (float) $saldoStmt->fetchColumn();

    $idMedio = (int) reset($medioIds);
    $valorConciliadoRaw = $valoresConciliados[$idMedio] ?? null;
    if ($valorConciliadoRaw === null || $valorConciliadoRaw === '') {
        throw new InvalidArgumentException('Debe ingresar el valor conciliado del periodo.');
    }
    if (!is_numeric($valorConciliadoRaw)) {
        throw new InvalidArgumentException('El valor conciliado debe ser numérico.');
    }

    $valorConciliado = (float) $valorConciliadoRaw;
    $nota = trim((string) ($notas[$idMedio] ?? ''));
    $diferencia = $saldoSistema - $valorConciliado;
    $hayDescuadre = abs($diferencia) > 0.009;

    $insertStmt = $pdo->prepare(
        'INSERT INTO conciliaciones_medios_pago (id_medio, anio, mes, saldo_sistema, valor_conciliado, diferencia, nota, cerrado)'
         VALUES (:id_medio, :anio, :mes, :saldo_sistema, :valor_conciliado, :diferencia, :nota, 0)'
    );

    $insertStmt->execute([
        ':id_medio' => $idMedio,
        ':anio' => $anio,
        ':mes' => $mes,
        ':saldo_sistema' => $saldoSistema,
        ':valor_conciliado' => $valorConciliado,
        ':diferencia' => $diferencia,
        ':nota' => $nota,
    ]);

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
