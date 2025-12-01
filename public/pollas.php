<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$socios = getSocios($pdo);
$actividadesPolla = getActividades($pdo, true);

$ingresos = $pdo->query("SELECT SUM(valor) as total FROM movimientos m JOIN actividades_maestro a ON m.id_actividad=a.id_actividad WHERE a.es_polla=1 AND m.es_ingreso=1")->fetch()['total'] ?? 0;
$egresos = $pdo->query("SELECT SUM(valor) as total FROM movimientos m JOIN actividades_maestro a ON m.id_actividad=a.id_actividad WHERE a.es_polla=1 AND m.es_egreso=1")->fetch()['total'] ?? 0;
$porSocio = $pdo->query("SELECT s.nombre_completo, SUM(CASE WHEN m.es_ingreso=1 THEN m.valor ELSE 0 END) ingresos, SUM(CASE WHEN m.es_egreso=1 THEN m.valor ELSE 0 END) egresos FROM movimientos m JOIN socios s ON m.id_socio=s.id_socio JOIN actividades_maestro a ON m.id_actividad=a.id_actividad WHERE a.es_polla=1 GROUP BY s.id_socio")->fetchAll();
$porMes = $pdo->query("SELECT DATE_FORMAT(m.fecha, '%Y-%m') mes, SUM(CASE WHEN m.es_ingreso=1 THEN m.valor ELSE 0 END) ingresos, SUM(CASE WHEN m.es_egreso=1 THEN m.valor ELSE 0 END) egresos FROM movimientos m JOIN actividades_maestro a ON m.id_actividad=a.id_actividad WHERE a.es_polla=1 GROUP BY DATE_FORMAT(m.fecha, '%Y-%m') ORDER BY mes DESC")->fetchAll();
?>
<h2 class="mb-3">Gestión de pollas</h2>
<div class="alert alert-info">El registro de pagos y premios de pollas se realiza ahora exclusivamente desde el módulo de Movimientos. Esta vista permanece como panel informativo.</div>
<div class="row g-3">
    <div class="col-md-4">
        <div class="card text-bg-primary">
            <div class="card-body">
                <h5>Total recaudado</h5>
                <p class="display-6">$<?php echo number_format($ingresos,0,',','.'); ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-bg-danger">
            <div class="card-body">
                <h5>Total premios pagados</h5>
                <p class="display-6">$<?php echo number_format($egresos,0,',','.'); ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-bg-success">
            <div class="card-body">
                <h5>Utilidad neta</h5>
                <p class="display-6">$<?php echo number_format($ingresos - $egresos,0,',','.'); ?></p>
            </div>
        </div>
    </div>
</div>
<h4 class="mt-4">Resumen por socio</h4>
<div class="table-responsive">
<table class="table table-striped table-sm">
    <thead><tr><th>Socio</th><th>Aportes</th><th>Premios</th><th>Neto</th></tr></thead>
    <tbody>
        <?php foreach($porSocio as $r): ?>
            <tr>
                <td><?php echo clean($r['nombre_completo']); ?></td>
                <td>$<?php echo number_format($r['ingresos'],0,',','.'); ?></td>
                <td>$<?php echo number_format($r['egresos'],0,',','.'); ?></td>
                <td>$<?php echo number_format($r['ingresos'] - $r['egresos'],0,',','.'); ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<h4 class="mt-4">Totales mensuales</h4>
<div class="table-responsive">
<table class="table table-bordered table-sm">
    <thead><tr><th>Mes</th><th>Ingresos</th><th>Egresos</th><th>Neto</th></tr></thead>
    <tbody>
        <?php foreach($porMes as $r): ?>
            <tr>
                <td><?php echo clean($r['mes']); ?></td>
                <td>$<?php echo number_format($r['ingresos'],0,',','.'); ?></td>
                <td>$<?php echo number_format($r['egresos'],0,',','.'); ?></td>
                <td>$<?php echo number_format($r['ingresos'] - $r['egresos'],0,',','.'); ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
