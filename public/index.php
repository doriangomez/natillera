<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$totalSocios = $pdo->query("SELECT COUNT(*) AS total FROM socios WHERE activo = 1")->fetch()['total'] ?? 0;
$totalMov = $pdo->query("SELECT COUNT(*) AS total FROM movimientos")->fetch()['total'] ?? 0;
$saldoNatillera = getSaldoNatillera($pdo);
?>
<div class="mt-2">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <p class="text-muted small mb-1">Resumen general</p>
            <h1 class="h4 mb-0">Panel principal</h1>
        </div>
        <a class="btn btn-outline-primary" href="../actions/export_csv.php?tipo=saldos">Exportar saldos</a>
    </div>
    <div class="row g-4">
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">Socios activos</p>
                    <h2 class="display-6 mb-0"><?php echo $totalSocios; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">Saldo natillera</p>
                    <h2 class="display-6 mb-0">$<?php echo number_format($saldoNatillera, 0, ',', '.'); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">Movimientos registrados</p>
                    <h2 class="display-6 mb-0"><?php echo $totalMov; ?></h2>
                </div>
            </div>
        </div>
    </div>
    <div class="row g-4 mt-1">
        <div class="col-lg-12">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6">Primeros pasos</h2>
                    <p class="text-muted">Usa el menú lateral para gestionar socios, actividades, movimientos, pollas, préstamos, gastos, reportes y exportaciones. Recuerda cargar el script SQL <code>database.sql</code> y luego ejecutar <code>actions/create_admin.php</code> una sola vez para generar el usuario administrador inicial.</p>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
