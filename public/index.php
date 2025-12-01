<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$totalSocios = $pdo->query("SELECT COUNT(*) AS total FROM socios WHERE activo = 1")->fetch()['total'] ?? 0;
$totalMov = $pdo->query("SELECT COUNT(*) AS total FROM movimientos")->fetch()['total'] ?? 0;
$saldoNatillera = getSaldoNatillera($pdo);
?>
<div class="mt-4">
    <h1 class="mb-4">Panel principal</h1>
    <div class="row g-3">
        <div class="col-md-3">
            <div class="card text-bg-primary">
                <div class="card-body">
                    <h5 class="card-title">Socios activos</h5>
                    <p class="display-6"><?php echo $totalSocios; ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-bg-success">
                <div class="card-body">
                    <h5 class="card-title">Saldo natillera</h5>
                    <p class="display-6">$<?php echo number_format($saldoNatillera, 0, ',', '.'); ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-bg-info">
                <div class="card-body">
                    <h5 class="card-title">Movimientos</h5>
                    <p class="display-6"><?php echo $totalMov; ?></p>
                </div>
            </div>
        </div>
    </div>
    <div class="mt-4">
        <p>Usa el menú superior para gestionar socios, actividades, movimientos, pollas, préstamos y reportes. Recuerda cargar el script SQL (database.sql) en phpMyAdmin y luego ejecutar <code>actions/create_admin.php</code> una sola vez para generar el usuario administrador inicial.</p>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
