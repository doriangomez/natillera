<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/prestamo_detalle_helpers.php';

$idPrestamo = isset($_GET['id_prestamo']) ? (int) $_GET['id_prestamo'] : 0;
$detalle = $idPrestamo > 0 ? cargarDetallePrestamo($pdo, $idPrestamo) : null;
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0 d-flex align-items-center gap-2"><i class="bi bi-clock-history text-primary"></i><span>Línea de tiempo del préstamo</span></h2>
    <a href="prestamos.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver a préstamos</a>
</div>
<?php if (!$detalle): ?>
    <div class="alert alert-danger">No se encontró el préstamo solicitado.</div>
<?php else: ?>
<?php
$prestamo = $detalle['prestamo'];
$deudor = $prestamo['es_particular'] ? ($prestamo['nombre_deudor'] ?: 'Particular sin nombre') : ($prestamo['nombre_completo'] ?: 'Socio sin nombre');
$estado = $prestamo['estado'] ?: (((float) $prestamo['saldo_capital_actual'] > 0) ? 'Activo' : 'Finalizado');
$proximo = $detalle['proximo_vencimiento'];
?>
<?php if ((int) $prestamo['es_particular'] === 1 && empty($prestamo['id_socio'])): ?>
    <div class="alert alert-warning fw-semibold"><i class="bi bi-exclamation-triangle"></i> Este préstamo no está vinculado a ningún socio.</div>
<?php endif; ?>
<div class="card border-primary mb-3">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <span>Préstamo #<?php echo (int) $prestamo['id_prestamo']; ?> — <?php echo clean($deudor); ?></span>
        <a class="btn btn-light btn-sm" target="_blank" href="../actions/export_linea_tiempo_prestamo_pdf.php?id_prestamo=<?php echo (int) $prestamo['id_prestamo']; ?>"><i class="bi bi-filetype-pdf"></i> Exportar PDF</a>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3"><div class="text-muted small">Deudor</div><div class="fw-semibold"><?php echo clean($deudor); ?></div><div class="small text-muted">Aval: <?php echo clean($prestamo['nombre_aval'] ?: 'No aplica'); ?></div></div>
            <div class="col-md-3"><div class="text-muted small">Monto / tasa / inicio</div><div class="fw-semibold"><?php echo formatoMonedaPrestamoDetalle((float) $prestamo['monto_prestamo']); ?> · <?php echo clean($prestamo['tasa_interes']); ?>%</div><div class="small text-muted"><?php echo clean($prestamo['fecha_prestamo']); ?></div></div>
            <div class="col-md-3"><div class="text-muted small">Saldos actuales</div><div class="fw-semibold">Capital: <?php echo formatoMonedaPrestamoDetalle((float) $prestamo['saldo_capital_actual']); ?></div><div class="small text-muted">Interés: <?php echo formatoMonedaPrestamoDetalle((float) $prestamo['saldo_intereses_actual']); ?></div></div>
            <div class="col-md-3"><div class="text-muted small">Estado actual</div><span class="badge bg-info-subtle text-info fs-6"><?php echo clean(ucfirst($estado)); ?></span></div>
            <div class="col-md-6"><div class="text-muted small">Interés causado histórico vs. pagado histórico</div><div class="fw-semibold"><?php echo formatoMonedaPrestamoDetalle($detalle['totales']['interes_causado']); ?> causado · <?php echo formatoMonedaPrestamoDetalle($detalle['totales']['interes_pagado']); ?> pagado</div><div class="small text-muted">Diferencia acumulada: <?php echo formatoMonedaPrestamoDetalle($detalle['totales']['interes_causado'] - $detalle['totales']['interes_pagado']); ?></div></div>
            <div class="col-md-6"><div class="alert <?php echo $proximo && $proximo['dias'] < 0 ? 'alert-danger' : 'alert-info'; ?> mb-0"><div class="fw-semibold">Próximo vencimiento de interés</div><?php if ($proximo): ?>Fecha: <?php echo clean($proximo['fecha']); ?> · <?php echo $proximo['dias'] < 0 ? abs($proximo['dias']) . ' días vencido' : $proximo['dias'] . ' días faltantes'; ?> · Valor esperado: <?php echo formatoMonedaPrestamoDetalle($proximo['valor']); ?><?php else: ?>No hay intereses pendientes en la matriz mensual.<?php endif; ?></div></div>
        </div>
    </div>
</div>
<?php if (!empty($detalle['alertas'])): ?>
    <div class="alert alert-danger"><div class="fw-semibold mb-1"><i class="bi bi-bug"></i> Alertas automáticas</div><ul class="mb-0"><?php foreach ($detalle['alertas'] as $alerta): ?><li><?php echo clean($alerta); ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<div class="card">
    <div class="card-header category-prestamos"><i class="bi bi-table"></i><span>Línea de tiempo mensual solo lectura</span></div>
    <div class="card-body table-responsive">
        <table class="table table-sm table-bordered align-middle">
            <thead><tr><th>Mes/Año</th><th class="text-end">Capital inicio</th><th class="text-end">Interés causado</th><th class="text-end">Interés pagado / fecha</th><th class="text-end">Abono capital / fecha</th><th class="text-end">Capital final</th><th class="text-end">Días mora</th><th>Estado</th><th>Fuente</th></tr></thead>
            <tbody>
                <?php foreach ($detalle['filas'] as $fila): ?>
                    <tr>
                        <td><?php echo clean($fila['mes_label']); ?></td>
                        <td class="text-end"><?php echo formatoMonedaPrestamoDetalle($fila['capital_inicio']); ?></td>
                        <td class="text-end"><?php echo formatoMonedaPrestamoDetalle($fila['interes_causado']); ?></td>
                        <td class="text-end"><?php echo formatoMonedaPrestamoDetalle($fila['interes_pagado']); ?><div class="small text-muted"><?php echo clean($fila['fechas_interes'] ?: 'Sin pago'); ?></div></td>
                        <td class="text-end"><?php echo formatoMonedaPrestamoDetalle($fila['abono_capital']); ?><div class="small text-muted"><?php echo clean($fila['fechas_capital'] ?: 'Sin abono'); ?></div></td>
                        <td class="text-end"><?php echo formatoMonedaPrestamoDetalle($fila['capital_final']); ?></td>
                        <td class="text-end"><?php echo (int) $fila['dias_mora']; ?></td>
                        <td><?php echo clean($fila['estado']); ?></td>
                        <td><span class="badge <?php echo str_starts_with($fila['fuente'], 'Solo') ? 'bg-warning-subtle text-warning' : 'bg-success-subtle text-success'; ?>"><?php echo clean($fila['fuente']); ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
