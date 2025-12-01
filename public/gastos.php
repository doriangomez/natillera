<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$sql = "SELECT m.fecha, a.nombre_actividad, m.motivo, m.valor, m.medio_consignacion
        FROM movimientos m
        INNER JOIN actividades_maestro a ON m.id_actividad = a.id_actividad
        WHERE a.es_gasto_general = 1
        ORDER BY m.fecha DESC
        LIMIT 150";
$gastos = $pdo->query($sql)->fetchAll();
$totalGastos = array_sum(array_column($gastos, 'valor'));
?>
<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <p class="text-muted small mb-1">Consulta rápida de gastos generales registrados</p>
        <h1 class="h4 mb-0">Gastos</h1>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-primary" href="../actions/export_csv.php?tipo=gastos">Exportar CSV</a>
        <a class="btn btn-primary" href="movimientos.php">Registrar gasto</a>
    </div>
</div>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card text-bg-light h-100">
            <div class="card-body">
                <h2 class="h6 text-uppercase text-muted">Total de gastos listados</h2>
                <p class="display-6 mb-0">
                    $<?php echo number_format($totalGastos, 0, ',', '.'); ?>
                </p>
                <p class="text-muted mb-0 small">Incluye los últimos 150 registros marcados como gasto general.</p>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h6 mb-0">Detalle de movimientos</h2>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Fecha</th>
                                <th>Actividad</th>
                                <th>Motivo</th>
                                <th>Valor</th>
                                <th>Medio</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($gastos as $g): ?>
                                <tr>
                                    <td><?php echo $g['fecha']; ?></td>
                                    <td><?php echo clean($g['nombre_actividad']); ?></td>
                                    <td><?php echo clean($g['motivo']); ?></td>
                                    <td>$<?php echo number_format($g['valor'],0,',','.'); ?></td>
                                    <td><?php echo clean($g['medio_consignacion']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
