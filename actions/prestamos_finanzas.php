<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

checkAuth();
header('Content-Type: application/json');

$idSocio = isset($_GET['id_socio']) ? (int) $_GET['id_socio'] : 0;

if ($idSocio <= 0) {
    echo json_encode([
        'monthly_active' => 0,
        'total_debt' => 0,
        'projected_income' => 0,
    ]);
    exit;
}

$stmtPrestamos = $pdo->prepare(
    'SELECT id_prestamo, monto_prestamo, numero_cuotas, saldo_capital_actual, saldo_intereses_actual
     FROM prestamos
     WHERE id_socio = :id AND estado = "vigente"'
);
$stmtPrestamos->execute([':id' => $idSocio]);
$prestamos = $stmtPrestamos->fetchAll();

$monthlyTotal = 0.0;
$totalDebt = 0.0;
$projectedIncome = 0.0;

$stmtCuotasPagadas = $pdo->prepare('SELECT COUNT(*) FROM cuotas_prestamo WHERE id_prestamo = :id AND fecha_pago IS NOT NULL');

foreach ($prestamos as $prestamo) {
    $numeroCuotas = (int) ($prestamo['numero_cuotas'] ?? 0);
    $montoPrestamo = (float) ($prestamo['monto_prestamo'] ?? 0);
    $saldoCapital = (float) ($prestamo['saldo_capital_actual'] ?? 0);
    $saldoInteres = (float) ($prestamo['saldo_intereses_actual'] ?? 0);

    $cuotaMensual = $numeroCuotas > 0 ? $montoPrestamo / $numeroCuotas : $montoPrestamo;
    $totalDebt += $saldoCapital + $saldoInteres;

    $stmtCuotasPagadas->execute([':id' => $prestamo['id_prestamo']]);
    $cuotasPagadas = (int) $stmtCuotasPagadas->fetchColumn();
    $mesesRestantes = max($numeroCuotas - $cuotasPagadas, 0);

    if ($mesesRestantes > 0) {
        $monthlyTotal += $cuotaMensual;
        $projectedIncome += $cuotaMensual * min(12, $mesesRestantes);
    }
}

echo json_encode([
    'monthly_active' => round($monthlyTotal, 2),
    'total_debt' => round($totalDebt, 2),
    'projected_income' => round($projectedIncome, 2),
]);
