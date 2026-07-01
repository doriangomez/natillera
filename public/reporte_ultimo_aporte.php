<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();

function obtenerReporteUltimoAporte(PDO $pdo): array
{
    return $pdo
        ->query(
            "SELECT s.id_socio,
                    s.nombre_completo,
                    cuota.fecha AS cuota_fecha,
                    cuota.quincena AS cuota_quincena,
                    polla.fecha AS polla_fecha,
                    polla.quincena AS polla_quincena
             FROM socios s
             LEFT JOIN movimientos cuota ON cuota.id_movimiento = (
                SELECT m.id_movimiento
                FROM movimientos m
                JOIN actividades_maestro a ON a.id_actividad = m.id_actividad
                WHERE m.id_socio = s.id_socio
                  AND a.nombre_actividad = 'Pago Cuota Socio'
                ORDER BY m.fecha DESC, m.id_movimiento DESC
                LIMIT 1
             )
             LEFT JOIN movimientos polla ON polla.id_movimiento = (
                SELECT m.id_movimiento
                FROM movimientos m
                JOIN actividades_maestro a ON a.id_actividad = m.id_actividad
                WHERE m.id_socio = s.id_socio
                  AND COALESCE(a.es_polla, 0) = 1
                ORDER BY m.fecha DESC, m.id_movimiento DESC
                LIMIT 1
             )
             WHERE s.activo = 1
             ORDER BY s.nombre_completo"
        )
        ->fetchAll(PDO::FETCH_ASSOC);
}

function valorReporteUltimoAporte($fecha, $quincena): string
{
    if ($fecha === null || $fecha === '') {
        return 'Sin registro';
    }

    $fechaFormateada = DateTime::createFromFormat('Y-m-d', (string) $fecha);
    if (!$fechaFormateada) {
        return 'Sin registro';
    }

    return $fechaFormateada->format('d/m/Y') . ' (Q' . $quincena . ')';
}

$reporteUltimoAporte = obtenerReporteUltimoAporte($pdo);

if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_ultimo_aporte_' . date('Ymd_His') . '.xls"');
    echo "\xEF\xBB\xBF";
    ?>
    <table border="1">
        <thead>
            <tr>
                <th>Socio</th>
                <th>Último Pago Cuota</th>
                <th>Última Polla</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($reporteUltimoAporte as $fila): ?>
                <tr>
                    <td><?php echo htmlspecialchars($fila['nombre_completo'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars(valorReporteUltimoAporte($fila['cuota_fecha'], $fila['cuota_quincena']), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars(valorReporteUltimoAporte($fila['polla_fecha'], $fila['polla_quincena']), ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>
<h2 class="mb-3">Reporte de último aporte por socio</h2>
<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>Socios activos y últimos registros de cuota y polla</span>
        <a class="btn btn-success btn-sm" href="reporte_ultimo_aporte.php?export=excel"><i class="bi bi-file-earmark-excel"></i> Exportar a Excel</a>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                    <tr>
                        <th>Socio</th>
                        <th>Último Pago Cuota</th>
                        <th>Última Polla</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reporteUltimoAporte)): ?>
                        <tr><td colspan="3" class="text-center text-muted">No hay socios activos para mostrar.</td></tr>
                    <?php else: ?>
                        <?php foreach ($reporteUltimoAporte as $fila): ?>
                            <tr>
                                <td><?php echo clean($fila['nombre_completo']); ?></td>
                                <td><?php echo clean(valorReporteUltimoAporte($fila['cuota_fecha'], $fila['cuota_quincena'])); ?></td>
                                <td><?php echo clean(valorReporteUltimoAporte($fila['polla_fecha'], $fila['polla_quincena'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
