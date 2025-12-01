<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$sql = "SELECT m.id_movimiento, m.fecha, a.nombre_actividad, m.motivo, m.valor, m.medio_consignacion
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
        <h1 class="h4 mb-0 d-flex align-items-center gap-2"><i class="bi bi-receipt-cutoff text-warning"></i><span>Gastos</span></h1>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-primary btn-icon" href="../actions/export_csv.php?tipo=gastos"><span><i class="bi bi-file-earmark-spreadsheet"></i> Exportar CSV</span></a>
        <a class="btn btn-primary btn-icon" href="movimientos.php"><span><i class="bi bi-plus-circle"></i> Registrar gasto</span></a>
    </div>
</div>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card text-bg-light h-100">
            <div class="card-body">
                <h2 class="h6 text-uppercase text-muted d-flex align-items-center gap-2"><i class="bi bi-cash-coin"></i><span>Total de gastos listados</span></h2>
                <p class="display-6 mb-0">
                    $<?php echo number_format($totalGastos, 0, ',', '.'); ?>
                </p>
                <p class="text-muted mb-0 small">Incluye los últimos 150 registros marcados como gasto general.</p>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header category-gastos"><i class="bi bi-list-check"></i><span>Detalle de movimientos</span></div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h6 mb-0 d-flex align-items-center gap-2"><i class="bi bi-clipboard-data"></i><span>Detalle de movimientos</span></h2>
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
                                <th></th>
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
                                    <td class="text-end">
                                        <form method="POST" action="../actions/movimientos_save.php" class="d-inline" onsubmit="return confirm('Esta acción eliminará el gasto seleccionado. ¿Deseas continuar?');">
                                            <input type="hidden" name="accion" value="eliminar">
                                            <input type="hidden" name="id_movimiento" value="<?php echo $g['id_movimiento']; ?>">
                                            <input type="hidden" name="redirect" value="../public/gastos.php">
                                            <button class="btn btn-sm btn-outline-danger" type="submit">Eliminar</button>
                                        </form>
                                    </td>
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
