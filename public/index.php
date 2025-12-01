<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$totalSocios = (int) ($pdo->query("SELECT COUNT(*) AS total FROM socios WHERE activo = 1")->fetch()['total'] ?? 0);

$totalCuotas = (float) $pdo->query("
    SELECT COALESCE(SUM(ABS(m.valor)),0) total
    FROM movimientos m
    JOIN actividades_maestro a ON m.id_actividad=a.id_actividad
    WHERE m.es_ingreso=1 AND a.es_polla=0 AND a.es_prestamo=0 AND a.es_pago_prestamo=0 AND a.es_gasto_general=0
")->fetchColumn();

$totalPollas = (float) $pdo->query("
    SELECT COALESCE(SUM(ABS(m.valor)),0) total
    FROM movimientos m
    JOIN actividades_maestro a ON m.id_actividad=a.id_actividad
    WHERE m.es_ingreso=1 AND a.es_polla=1
")->fetchColumn();

$totalPrestamoRecuperado = (float) $pdo->query("SELECT COALESCE(SUM(valor_capital_pagado),0) FROM cuotas_prestamo")->fetchColumn();
$totalInteresesPrestamo = (float) $pdo->query("SELECT COALESCE(SUM(valor_interes_pagado),0) FROM cuotas_prestamo")->fetchColumn();
$totalPrestado = (float) $pdo->query("SELECT COALESCE(SUM(monto_prestamo),0) FROM prestamos")->fetchColumn();

$totalIngresos = (float) $pdo->query("SELECT COALESCE(SUM(ABS(valor)),0) FROM movimientos WHERE es_ingreso=1")->fetchColumn();
$totalEgresos = (float) $pdo->query("SELECT COALESCE(SUM(ABS(valor)),0) FROM movimientos WHERE es_egreso=1")->fetchColumn();

$totalOtrasActividades = max(0, $totalIngresos - ($totalCuotas + $totalPollas + $totalPrestamoRecuperado + $totalInteresesPrestamo));
$saldoNatillera = getSaldoNatillera($pdo);

$chartLabels = ['Cuotas', 'Pollas', 'Préstamos (capital recuperado)', 'Intereses', 'Otras actividades'];
$chartDataset = [$totalCuotas, $totalPollas, $totalPrestamoRecuperado, $totalInteresesPrestamo, $totalOtrasActividades];

$socios = getSocios($pdo);
$actividades = getActividades($pdo, false, true);

$filtroSocio = isset($_GET['socio']) ? (int) $_GET['socio'] : 0;
$filtroActividad = isset($_GET['actividad']) ? (int) $_GET['actividad'] : 0;
$filtroFechaIni = $_GET['desde'] ?? '';
$filtroFechaFin = $_GET['hasta'] ?? '';

$where = [];
$params = [];
if ($filtroSocio) { $where[] = 'm.id_socio = :s'; $params[':s'] = $filtroSocio; }
if ($filtroActividad) { $where[] = 'm.id_actividad = :a'; $params[':a'] = $filtroActividad; }
if ($filtroFechaIni) { $where[] = 'm.fecha >= :fi'; $params[':fi'] = $filtroFechaIni; }
if ($filtroFechaFin) { $where[] = 'm.fecha <= :ff'; $params[':ff'] = $filtroFechaFin; }

$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$movimientosStmt = $pdo->prepare("
    WITH mov_filtrado AS (
        SELECT m.id_movimiento, m.fecha, m.valor, m.es_ingreso, m.es_egreso, m.id_socio, m.id_actividad,
               s.nombre_completo, a.nombre_actividad, a.afecta_saldo_socio, a.afecta_saldo_natillera,
               COALESCE(mp.nombre, m.medio_consignacion) AS medio_nombre
        FROM movimientos m
        LEFT JOIN socios s ON m.id_socio = s.id_socio
        LEFT JOIN actividades_maestro a ON m.id_actividad = a.id_actividad
        LEFT JOIN medios_pago mp ON m.id_medio_pago = mp.id
        $sqlWhere
    ), calculado AS (
        SELECT mov_filtrado.*,
               SUM(CASE 
                       WHEN mov_filtrado.afecta_saldo_natillera = 'suma' THEN mov_filtrado.valor
                       WHEN mov_filtrado.afecta_saldo_natillera = 'resta' THEN -mov_filtrado.valor
                       ELSE 0 END
               ) OVER (ORDER BY mov_filtrado.fecha, mov_filtrado.id_movimiento ROWS UNBOUNDED PRECEDING) AS saldo_natillera,
               CASE WHEN mov_filtrado.id_socio IS NOT NULL THEN
                    SUM(CASE 
                            WHEN mov_filtrado.afecta_saldo_socio = 'suma' THEN mov_filtrado.valor
                            WHEN mov_filtrado.afecta_saldo_socio = 'resta' THEN -mov_filtrado.valor
                            ELSE 0 END
                    ) OVER (PARTITION BY mov_filtrado.id_socio ORDER BY mov_filtrado.fecha, mov_filtrado.id_movimiento ROWS UNBOUNDED PRECEDING)
               END AS saldo_socio
        FROM mov_filtrado
    )
    SELECT * FROM calculado
    ORDER BY fecha DESC, id_movimiento DESC
");
$movimientosStmt->execute($params);
$movimientos = $movimientosStmt->fetchAll();
?>
<div class="mt-2">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <p class="text-muted small mb-1">Resumen general</p>
            <h1 class="h4 mb-0 d-flex align-items-center gap-2"><i class="bi bi-speedometer2 text-primary"></i> <span>Panel principal</span></h1>
        </div>
        <a class="btn btn-outline-primary btn-icon" href="../actions/export_csv.php?tipo=saldos"><span><i class="bi bi-download"></i> Exportar saldos</span></a>
    </div>
    <div class="row g-4">
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-people-fill text-primary"></i><span>Socios activos</span></div>
                <div class="card-body">
                    <p class="text-muted mb-1">Socios activos</p>
                    <h2 class="display-6 mb-0"><?php echo $totalSocios; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-header category-ingresos"><i class="bi bi-cash-stack"></i><span>Cuotas acumuladas</span></div>
                <div class="card-body">
                    <p class="text-muted mb-1">Total por cuotas</p>
                    <h2 class="display-6 mb-0">$<?php echo number_format($totalCuotas, 0, ',', '.'); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-header category-prestamos"><i class="bi bi-bank"></i><span>Total prestado</span></div>
                <div class="card-body">
                    <p class="text-muted mb-1">Desembolsos registrados</p>
                    <h2 class="display-6 mb-0">$<?php echo number_format($totalPrestado, 0, ',', '.'); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-header category-gastos"><i class="bi bi-wallet2"></i><span>Saldo natillera</span></div>
                <div class="card-body">
                    <p class="text-muted mb-1">Saldo general</p>
                    <h2 class="display-6 mb-0">$<?php echo number_format($saldoNatillera, 0, ',', '.'); ?></h2>
                </div>
            </div>
        </div>
    </div>
    <div class="row g-4 mt-1">
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-header category-prestamos"><i class="bi bi-arrow-down-circle"></i><span>Capital recuperado</span></div>
                <div class="card-body">
                    <p class="text-muted mb-1">Pagos a capital</p>
                    <h2 class="display-6 mb-0">$<?php echo number_format($totalPrestamoRecuperado, 0, ',', '.'); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-header category-prestamos"><i class="bi bi-percent"></i><span>Intereses préstamos</span></div>
                <div class="card-body">
                    <p class="text-muted mb-1">Intereses acumulados</p>
                    <h2 class="display-6 mb-0">$<?php echo number_format($totalInteresesPrestamo, 0, ',', '.'); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-header category-pollas"><i class="bi bi-trophy"></i><span>Ingresos de pollas</span></div>
                <div class="card-body">
                    <p class="text-muted mb-1">Total registrado</p>
                    <h2 class="display-6 mb-0">$<?php echo number_format($totalPollas, 0, ',', '.'); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-header category-ingresos"><i class="bi bi-stars"></i><span>Otras actividades</span></div>
                <div class="card-body">
                    <p class="text-muted mb-1">Ingresos complementarios</p>
                    <h2 class="display-6 mb-0">$<?php echo number_format($totalOtrasActividades, 0, ',', '.'); ?></h2>
                </div>
            </div>
        </div>
    </div>
    <div class="row g-4 mt-1">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-pie-chart"></i><span>Distribución de ingresos</span></div>
                <div class="card-body">
                    <p class="text-muted small mb-2">Ingresos vs egresos por categoría</p>
                    <canvas id="ingresosChart" height="220"></canvas>
                    <div class="mt-3 d-flex flex-wrap gap-3">
                        <span class="badge-soft">Total ingresos: $<?php echo number_format($totalIngresos,0,',','.'); ?></span>
                        <span class="badge-soft">Total egresos: $<?php echo number_format($totalEgresos,0,',','.'); ?></span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-ui-checks"></i><span>Primeros pasos</span></div>
                <div class="card-body">
                    <h2 class="h6 d-flex align-items-center gap-2"><i class="bi bi-stars text-warning"></i><span>Guía rápida</span></h2>
                    <p class="text-muted">Usa el menú lateral para gestionar socios, actividades, movimientos, pollas, préstamos, gastos, reportes y exportaciones. Recuerda cargar el script SQL <code>database.sql</code> y luego ejecutar <code>actions/create_admin.php</code> una sola vez para generar el usuario administrador inicial.</p>
                    <div class="mt-3 row g-2">
                        <div class="col-md-6"><div class="badge-soft w-100 text-center"><i class="bi bi-check2-circle text-success"></i> Flujos de ingreso/egreso automáticos</div></div>
                        <div class="col-md-6"><div class="badge-soft w-100 text-center"><i class="bi bi-download"></i> Exporta reportes en segundos</div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-list-check"></i><span>Consolidado de movimientos</span>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-success btn-sm" id="btnExportExcel"><i class="bi bi-file-earmark-excel"></i> Exportar a Excel</button>
                <button class="btn btn-outline-danger btn-sm" id="btnExportPdf"><i class="bi bi-file-earmark-pdf"></i> Exportar a PDF</button>
            </div>
        </div>
        <div class="card-body">
            <form class="row g-3 mb-3" method="GET">
                <div class="col-md-3"><label class="form-label">Desde</label><input type="date" name="desde" class="form-control" value="<?php echo clean($filtroFechaIni); ?>"></div>
                <div class="col-md-3"><label class="form-label">Hasta</label><input type="date" name="hasta" class="form-control" value="<?php echo clean($filtroFechaFin); ?>"></div>
                <div class="col-md-3"><label class="form-label">Socio</label>
                    <select name="socio" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach($socios as $s): ?>
                            <option value="<?php echo $s['id_socio']; ?>" <?php echo ($filtroSocio==$s['id_socio'])?'selected':''; ?>><?php echo clean($s['nombre_completo']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3"><label class="form-label">Actividad</label>
                    <select name="actividad" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach($actividades as $a): ?>
                            <option value="<?php echo $a['id_actividad']; ?>" <?php echo ($filtroActividad==$a['id_actividad'])?'selected':''; ?>><?php echo clean($a['nombre_actividad']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary btn-icon"><span><i class="bi bi-funnel"></i> Filtrar</span></button>
                    <a class="btn btn-outline-secondary btn-icon" href="index.php"><span><i class="bi bi-x-circle"></i> Limpiar</span></a>
                </div>
            </form>
            <div class="table-responsive">
                <table class="table table-striped table-sm" id="tablaConsolidado">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Socio/Tercero</th>
                            <th>Actividad</th>
                            <th>Medio de pago</th>
                            <th>Tipo</th>
                            <th class="text-end">Valor</th>
                            <th class="text-end">Saldo socio</th>
                            <th class="text-end">Saldo general</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$movimientos): ?>
                            <tr><td colspan="8" class="text-center text-muted">No hay movimientos con los filtros seleccionados.</td></tr>
                        <?php endif; ?>
                        <?php foreach($movimientos as $m): ?>
                            <tr>
                                <td><?php echo clean($m['fecha']); ?></td>
                                <td><?php echo $m['nombre_completo'] ? clean($m['nombre_completo']) : 'General'; ?></td>
                                <td><?php echo clean($m['nombre_actividad']); ?></td>
                                <td><?php echo clean($m['medio_nombre']); ?></td>
                                <td><span class="badge <?php echo $m['es_ingreso'] ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger'; ?>">
                                    <?php echo $m['es_ingreso'] ? 'Ingreso' : 'Egreso'; ?></span></td>
                                <td class="text-end">$<?php echo number_format(abs($m['valor']),0,',','.'); ?></td>
                                <td class="text-end"><?php echo $m['saldo_socio'] !== null ? '$'.number_format($m['saldo_socio'],0,',','.') : '-'; ?></td>
                                <td class="text-end">$<?php echo number_format($m['saldo_natillera'],0,',','.'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const chartLabels = <?php echo json_encode($chartLabels, JSON_UNESCAPED_UNICODE); ?>;
const chartData = <?php echo json_encode($chartDataset); ?>;
const ctx = document.getElementById('ingresosChart');
if (ctx) {
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: chartLabels,
            datasets: [{
                label: 'Ingresos vs egresos',
                data: chartData,
                backgroundColor: ['#0f172a','#34d399','#f59e0b','#3b82f6','#a855f7'],
                borderWidth: 0
            }]
        },
        options: {
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
}

function exportTable(type){
    if(!confirm('¿Desea exportar a Excel o PDF?')){ return; }
    const table = document.getElementById('tablaConsolidado');
    const rows = Array.from(table.querySelectorAll('tr')).map(tr => Array.from(tr.cells).map(td => td.innerText));
    if(type === 'excel'){
        const csvContent = rows.map(r => r.map(value => '"'+value.replace(/"/g,'""')+'"').join(',')).join('\n');
        const blob = new Blob([csvContent], {type: 'text/csv;charset=utf-8;'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'consolidado_movimientos.csv';
        a.click();
        URL.revokeObjectURL(url);
    } else if(type === 'pdf'){
        const nuevaVentana = window.open('', '_blank');
        nuevaVentana.document.write('<html><head><title>Consolidado</title>');
        nuevaVentana.document.write('<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">');
        nuevaVentana.document.write('</head><body class="p-4">');
        nuevaVentana.document.write('<h3>Consolidado de movimientos</h3>');
        nuevaVentana.document.write(table.outerHTML);
        nuevaVentana.document.write('</body></html>');
        nuevaVentana.document.close();
        nuevaVentana.focus();
        nuevaVentana.print();
    }
}
document.getElementById('btnExportExcel')?.addEventListener('click', () => exportTable('excel'));
document.getElementById('btnExportPdf')?.addEventListener('click', () => exportTable('pdf'));
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
