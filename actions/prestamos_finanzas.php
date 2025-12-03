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

$stmtPrestamos = $pdo->prepare(
    'SELECT id_prestamo, saldo_capital_actual, saldo_intereses_actual
     FROM prestamos
     WHERE id_socio = :id AND estado = "vigente"'
);
$stmtPrestamos->execute([':id' => $idSocio]);
$prestamos = $stmtPrestamos->fetchAll();

$stmtSaldoAcumulado = $pdo->prepare(
    'SELECT
        COALESCE(SUM(CASE WHEN es_ingreso = 1 THEN valor ELSE 0 END), 0) AS ingresos,
        COALESCE(SUM(CASE WHEN es_egreso = 1 THEN valor ELSE 0 END), 0) AS egresos
     FROM movimientos
     WHERE id_socio = :id'
);
$stmtSaldoAcumulado->execute([':id' => $idSocio]);
$saldoMovimientos = $stmtSaldoAcumulado->fetch();

$saldoActual = (float) ($saldoMovimientos['ingresos'] - $saldoMovimientos['egresos']);

$stmtMensual = $pdo->prepare(
    'SELECT
        COALESCE(SUM(CASE WHEN es_ingreso = 1 THEN valor ELSE 0 END), 0) AS ingresos,
        COALESCE(SUM(CASE WHEN es_egreso = 1 THEN valor ELSE 0 END), 0) AS egresos
     FROM movimientos
     WHERE id_socio = :id AND anio = YEAR(CURDATE()) AND mes = MONTH(CURDATE())'
);
$stmtMensual->execute([':id' => $idSocio]);
$mensual = $stmtMensual->fetch();

$ingresoMensual = (float) ($mensual['ingresos'] - $mensual['egresos']);

$totalDebt = 0.0;

foreach ($prestamos as $prestamo) {
    $saldoCapital = (float) ($prestamo['saldo_capital_actual'] ?? 0);
    $saldoInteres = (float) ($prestamo['saldo_intereses_actual'] ?? 0);

    $totalDebt += $saldoCapital + $saldoInteres;
}

$projectedIncome = ($ingresoMensual * 12) - $saldoActual;

echo json_encode([
    'accumulated_balance' => round($saldoActual, 2),
    'monthly_net' => round($ingresoMensual, 2),
    'total_debt' => round($totalDebt, 2),
    'projected_income' => round($projectedIncome, 2),
]);
