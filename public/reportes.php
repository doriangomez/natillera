<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$saldoNatillera = getSaldoNatillera($pdo);
$saldosSocios = $pdo->query("SELECT nombre_completo, saldo_socio FROM socios WHERE activo=1 ORDER BY nombre_completo")->fetchAll();

$nombresMeses = getNombresMeses();
$periodosConfig = $pdo
    ->query('SELECT anio, mes FROM periodos_configuracion WHERE activo = 1 ORDER BY anio DESC, mes DESC')
    ->fetchAll();
$periodosPorAnio = [];
foreach ($periodosConfig as $p) {
    $periodosPorAnio[$p['anio']][] = (int) $p['mes'];
}

$anioPolla = isset($_GET['anio_polla']) ? (int) $_GET['anio_polla'] : null;
$mesPolla = isset($_GET['mes_polla']) ? (int) $_GET['mes_polla'] : null;
$anioDefecto = $periodosConfig[0]['anio'] ?? null;
$mesDefecto = $periodosConfig[0]['mes'] ?? null;
$periodoValido = $anioPolla && $mesPolla && isset($periodosPorAnio[$anioPolla]) && in_array($mesPolla, $periodosPorAnio[$anioPolla], true);
if (!$periodoValido && $anioDefecto && $mesDefecto) {
    $anioPolla = (int) $anioDefecto;
    $mesPolla = (int) $mesDefecto;
}

$sociosSinPagoPolla = [];
if ($anioPolla && $mesPolla) {
    $stmt = $pdo->prepare(
        "SELECT s.id_socio, s.nombre_completo
         FROM socios s
         WHERE s.activo = 1
           AND NOT EXISTS (
                SELECT 1 FROM movimientos m
                JOIN actividades_maestro a ON m.id_actividad = a.id_actividad
                WHERE a.es_polla = 1 AND m.es_ingreso = 1
                  AND m.id_socio = s.id_socio
                  AND m.anio = :anio AND m.mes = :mes
           )
         ORDER BY s.nombre_completo"
    );
    $stmt->execute([':anio' => $anioPolla, ':mes' => $mesPolla]);
    $sociosSinPagoPolla = $stmt->fetchAll();
}

$pyg = $pdo->query("SELECT a.nombre_actividad, SUM(CASE WHEN m.es_ingreso=1 THEN m.valor ELSE 0 END) ingresos, SUM(CASE WHEN m.es_egreso=1 THEN m.valor ELSE 0 END) egresos FROM movimientos m JOIN actividades_maestro a ON m.id_actividad=a.id_actividad GROUP BY a.id_actividad")->fetchAll();

$gastos = $pdo->query("SELECT a.nombre_actividad, SUM(m.valor) total FROM movimientos m JOIN actividades_maestro a ON m.id_actividad=a.id_actividad WHERE a.es_gasto_general=1 GROUP BY a.id_actividad")->fetchAll();

$prestamos = $pdo->query("SELECT id_prestamo, nombre_deudor, saldo_capital_actual, saldo_intereses_actual FROM prestamos ORDER BY id_prestamo DESC")->fetchAll();
?>
<h2 class="mb-3">Reportes</h2>
<div class="row g-3">
    <div class="col-md-4">
        <div class="card text-bg-success">
            <div class="card-body">
                <h5>Saldo global natillera</h5>
                <p class="display-6">$<?php echo number_format($saldoNatillera,0,',','.'); ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">Saldos por socio</div>
            <div class="card-body">
                <a class="btn btn-outline-secondary mb-2" href="../actions/export_csv.php?tipo=saldos">Exportar CSV</a>
                <div class="table-responsive">
                    <table class="table table-sm table-striped">
                        <thead><tr><th>Socio</th><th>Saldo</th></tr></thead>
                        <tbody>
                            <?php foreach($saldosSocios as $s): ?>
                                <tr><td><?php echo clean($s['nombre_completo']); ?></td><td>$<?php echo number_format($s['saldo_socio'],0,',','.'); ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="card mt-3">
    <div class="card-header">Socios que no han pagado polla</div>
    <div class="card-body">
        <?php if (empty($periodosPorAnio)): ?>
            <div class="alert alert-warning mb-0">Configure periodos activos en el módulo de configuración para habilitar el filtro por mes y año.</div>
        <?php else: ?>
            <form class="row g-2 align-items-end mb-3" method="GET">
                <div class="col-md-3">
                    <label class="form-label">Año</label>
                    <select name="anio_polla" class="form-select">
                        <?php foreach ($periodosPorAnio as $anio => $meses): ?>
                            <option value="<?php echo $anio; ?>" <?php echo ($anio == $anioPolla) ? 'selected' : ''; ?>><?php echo $anio; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Mes</label>
                    <select name="mes_polla" class="form-select">
                        <?php foreach ($periodosPorAnio[$anioPolla] as $mes): ?>
                            <option value="<?php echo $mes; ?>" <?php echo ($mes == $mesPolla) ? 'selected' : ''; ?>><?php echo $nombresMeses[$mes]; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary"><i class="bi bi-funnel"></i> Filtrar</button>
                </div>
            </form>
            <div class="table-responsive">
                <table class="table table-sm table-striped">
                    <thead><tr><th>Socio</th><th>Estado</th></tr></thead>
                    <tbody>
                        <?php if (empty($sociosSinPagoPolla)): ?>
                            <tr><td colspan="2" class="text-center text-success">Todos los socios tienen pago registrado para el periodo seleccionado.</td></tr>
                        <?php else: ?>
                            <?php foreach ($sociosSinPagoPolla as $s): ?>
                                <tr>
                                    <td><?php echo clean($s['nombre_completo']); ?></td>
                                    <td class="text-danger fw-semibold">No ha pagado</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<div class="card mt-3">
    <div class="card-header">PYG por actividad</div>
    <div class="card-body">
        <a class="btn btn-outline-secondary mb-2" href="../actions/export_csv.php?tipo=pyg">Exportar CSV</a>
        <div class="table-responsive">
            <table class="table table-bordered table-sm">
                <thead><tr><th>Actividad</th><th>Ingresos</th><th>Egresos</th><th>Neto</th></tr></thead>
                <tbody>
                    <?php foreach($pyg as $r): ?>
                        <tr>
                            <td><?php echo clean($r['nombre_actividad']); ?></td>
                            <td>$<?php echo number_format($r['ingresos'],0,',','.'); ?></td>
                            <td>$<?php echo number_format($r['egresos'],0,',','.'); ?></td>
                            <td>$<?php echo number_format($r['ingresos'] - $r['egresos'],0,',','.'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<div class="card mt-3">
    <div class="card-header">Gastos de la natillera</div>
    <div class="card-body">
        <a class="btn btn-outline-secondary mb-2" href="../actions/export_csv.php?tipo=gastos">Exportar CSV</a>
        <div class="table-responsive">
            <table class="table table-striped table-sm">
                <thead><tr><th>Actividad</th><th>Total</th></tr></thead>
                <tbody>
                    <?php foreach($gastos as $g): ?>
                        <tr><td><?php echo clean($g['nombre_actividad']); ?></td><td>$<?php echo number_format($g['total'],0,',','.'); ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<div class="card mt-3">
    <div class="card-header">Préstamos y saldos</div>
    <div class="card-body">
        <a class="btn btn-outline-secondary mb-2" href="../actions/export_csv.php?tipo=prestamos">Exportar CSV</a>
        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead><tr><th>ID</th><th>Deudor</th><th>Saldo capital</th><th>Saldo intereses</th></tr></thead>
                <tbody>
                    <?php foreach($prestamos as $p): ?>
                        <tr>
                            <td><?php echo $p['id_prestamo']; ?></td>
                            <td><?php echo clean($p['nombre_deudor']); ?></td>
                            <td>$<?php echo number_format($p['saldo_capital_actual'],0,',','.'); ?></td>
                            <td>$<?php echo number_format($p['saldo_intereses_actual'],0,',','.'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
