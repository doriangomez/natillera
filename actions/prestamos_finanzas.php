<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

checkAuth();
header('Content-Type: application/json');

$idSocio = isset($_GET['id_socio']) ? (int) $_GET['id_socio'] : 0;

if ($idSocio <= 0) {
    echo json_encode([
        'accumulated_balance' => 0,
        'monthly_net' => 0,
        'total_debt' => 0,
        'projected_income' => 0,
    ]);
    exit;
}

// Préstamos vigentes del socio
$stmtPrestamos = $pdo->prepare(
    'SELECT id_prestamo, saldo_capital_actual, saldo_intereses_actual
     FROM prestamos
     WHERE id_socio = :id AND estado IN ("vigente", "Activo", "En mora")'
);
$stmtPrestamos->execute([':id' => $idSocio]);
$prestamos = $stmtPrestamos->fetchAll();

// Valor mensual de la cuota presupuestada del socio y proyección anual
$stmtSocio = $pdo->prepare('SELECT valor_presupuestado, periodicidad_pago FROM socios WHERE id_socio = :id');
$stmtSocio->execute([':id' => $idSocio]);
$socio = $stmtSocio->fetch();
$factorPeriodicidad = ($socio && strtolower((string) $socio['periodicidad_pago']) === 'quincenal') ? 2 : 1;
$pagoCuotaMensual = (float) (($socio['valor_presupuestado'] ?? 0) * $factorPeriodicidad);
$ahorroAnual = $pagoCuotaMensual * 12;

// Total recaudado real solo por Pago Cuota Socio (actividad 7) que sea ingreso
$stmtTotalRecaudado = $pdo->prepare(
    'SELECT COALESCE(SUM(valor), 0) AS total_recaudado
     FROM movimientos
     WHERE id_socio = :id AND id_actividad = 7 AND es_ingreso = 1'
);
$stmtTotalRecaudado->execute([':id' => $idSocio]);
$totalRecaudado = (float) ($stmtTotalRecaudado->fetchColumn() ?: 0);

// Saldo acumulado: base de pago de cuotas menos todos los conceptos que restan
$stmtSaldoAcumulado = $pdo->prepare(
    'SELECT
        COALESCE(SUM(CASE WHEN a.afecta_saldo_socio = "suma" THEN m.valor ELSE 0 END), 0) AS pago_cuota,
        COALESCE(SUM(CASE WHEN a.afecta_saldo_socio = "resta" THEN m.valor ELSE 0 END), 0) AS deducciones
     FROM movimientos m
     LEFT JOIN actividades_maestro a ON m.id_actividad = a.id_actividad
     WHERE m.id_socio = :id'
);
$stmtSaldoAcumulado->execute([':id' => $idSocio]);
$saldoMovimientos = $stmtSaldoAcumulado->fetch();

$pagoCuotaSocio = (float) ($saldoMovimientos['pago_cuota'] ?? 0);
$deducciones = (float) ($saldoMovimientos['deducciones'] ?? 0);
$saldoActual = $pagoCuotaSocio - $deducciones;

// Proyección final: ahorro anual menos lo ya recaudado, sin meses faltantes
$projectedIncome = max(0, $ahorroAnual - $totalRecaudado);

$totalDebt = 0.0;

foreach ($prestamos as $prestamo) {
    $saldoCapital = (float) ($prestamo['saldo_capital_actual'] ?? 0);
    $saldoInteres = (float) ($prestamo['saldo_intereses_actual'] ?? 0);

    $totalDebt += $saldoCapital + $saldoInteres;
}

echo json_encode([
    'accumulated_balance' => round($saldoActual, 2),
    'monthly_net' => round($pagoCuotaMensual, 2),
    'total_debt' => round($totalDebt, 2),
    'projected_income' => round($projectedIncome, 2),
]);
