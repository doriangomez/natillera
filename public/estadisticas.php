<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

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

$baseWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Ingresos vs egresos por mes
$sqlBar = "SELECT DATE_FORMAT(m.fecha, '%Y-%m') AS mes,
    SUM(CASE WHEN a.afecta_saldo_natillera = 'suma' THEN ABS(m.valor) ELSE 0 END) AS ingresos,
    SUM(CASE WHEN a.afecta_saldo_natillera = 'resta' THEN ABS(m.valor) ELSE 0 END) AS egresos
    FROM movimientos m
    LEFT JOIN actividades_maestro a ON m.id_actividad = a.id_actividad
    $baseWhere
    GROUP BY DATE_FORMAT(m.fecha, '%Y-%m')
    ORDER BY mes";
$stmtBar = $pdo->prepare($sqlBar);
$stmtBar->execute($params);
$ingVsEgr = $stmtBar->fetchAll();

// Distribución de ingresos por actividad
$sqlPie = "SELECT a.nombre_actividad, SUM(ABS(m.valor)) AS total
    FROM movimientos m
    JOIN actividades_maestro a ON m.id_actividad = a.id_actividad
    WHERE a.afecta_saldo_natillera = 'suma' " . ($baseWhere ? 'AND ' . substr($baseWhere, 6) : '') . "
    GROUP BY a.id_actividad
    ORDER BY total DESC";
$stmtPie = $pdo->prepare($sqlPie);
$stmtPie->execute($params);
$ingresosPorActividad = $stmtPie->fetchAll();

// Distribución de egresos por actividad
$sqlEgresosActividad = "SELECT a.nombre_actividad, SUM(ABS(m.valor)) AS total
    FROM movimientos m
    JOIN actividades_maestro a ON m.id_actividad = a.id_actividad
    WHERE a.afecta_saldo_natillera = 'resta' " . ($baseWhere ? 'AND ' . substr($baseWhere, 6) : '') . "
    GROUP BY a.id_actividad
    ORDER BY total DESC";
$stmtEgresosActividad = $pdo->prepare($sqlEgresosActividad);
$stmtEgresosActividad->execute($params);
$egresosPorActividad = $stmtEgresosActividad->fetchAll();

// Evolución mensual de pollas
$wherePollas = $where;
$wherePollas[] = 'a.es_polla = 1';
$pollasWhere = 'WHERE ' . implode(' AND ', $wherePollas);
$sqlLine = "SELECT DATE_FORMAT(m.fecha, '%Y-%m') AS mes,
    SUM(m.valor) AS total
    FROM movimientos m
    JOIN actividades_maestro a ON m.id_actividad = a.id_actividad
    $pollasWhere
    GROUP BY DATE_FORMAT(m.fecha, '%Y-%m')
    ORDER BY mes";
$stmtLine = $pdo->prepare($sqlLine);
$stmtLine->execute($params);
$pollasMes = $stmtLine->fetchAll();

// Capital prestado vs recuperado
$wherePrestamos = $where;
$wherePrestamos[] = '(a.es_prestamo = 1 OR a.es_pago_prestamo = 1)';
$prestamoWhere = 'WHERE ' . implode(' AND ', $wherePrestamos);
$sqlPrestamos = "SELECT
    SUM(CASE WHEN a.es_prestamo = 1 THEN m.valor ELSE 0 END) AS prestado,
    SUM(CASE WHEN a.es_pago_prestamo = 1 THEN m.valor ELSE 0 END) AS recuperado
    FROM movimientos m
    JOIN actividades_maestro a ON m.id_actividad = a.id_actividad
    $prestamoWhere";
$stmtPrestamos = $pdo->prepare($sqlPrestamos);
$stmtPrestamos->execute($params);
$prestamoData = $stmtPrestamos->fetch();

// Movimientos por medio de pago
$sqlMedios = "SELECT
    COALESCE(mp.nombre, 'Sin medio') AS medio,
    SUM(CASE WHEN a.afecta_saldo_natillera = 'suma' THEN ABS(m.valor) ELSE 0 END) AS ingresos,
    SUM(CASE WHEN a.afecta_saldo_natillera = 'resta' THEN ABS(m.valor) ELSE 0 END) AS egresos
    FROM movimientos m
    LEFT JOIN medios_pago mp ON m.id_medio_pago = mp.id
    LEFT JOIN actividades_maestro a ON m.id_actividad = a.id_actividad
    $baseWhere
    GROUP BY medio
    ORDER BY medio";
$stmtMedios = $pdo->prepare($sqlMedios);
$stmtMedios->execute($params);
$movimientosPorMedio = $stmtMedios->fetchAll();

function toChartPairs($rows, $labelKey, $valueKey) {
    $labels = [];
    $values = [];
    foreach ($rows as $row) {
        $labels[] = $row[$labelKey];
        $values[] = (float) $row[$valueKey];
    }
    return [$labels, $values];
}

list($mesesBar, $ingresosBar) = toChartPairs($ingVsEgr, 'mes', 'ingresos');
$egresosBar = array_map(fn($row) => (float) $row['egresos'], $ingVsEgr);
list($actividadesLabels, $ingresosValues) = toChartPairs($ingresosPorActividad, 'nombre_actividad', 'total');
list($egresosActividadLabels, $egresosValues) = toChartPairs($egresosPorActividad, 'nombre_actividad', 'total');
list($mesesPollas, $pollasValues) = toChartPairs($pollasMes, 'mes', 'total');
list($mediosLabels, $mediosIngresos) = toChartPairs($movimientosPorMedio, 'medio', 'ingresos');
$mediosEgresos = array_map(fn($row) => (float) $row['egresos'], $movimientosPorMedio);

$netoMensual = [];
$saldoAcumulado = [];
$saldo = 0;
foreach ($ingVsEgr as $row) {
    $neto = (float) $row['ingresos'] - (float) $row['egresos'];
    $saldo += $neto;
    $netoMensual[] = $neto;
    $saldoAcumulado[] = $saldo;
}

$prestado = (float) ($prestamoData['prestado'] ?? 0);
$recuperado = (float) ($prestamoData['recuperado'] ?? 0);
?>
<h2 class="mb-3 d-flex align-items-center gap-2"><i class="bi bi-pie-chart-fill text-primary"></i><span>Estadísticas</span></h2>
<div class="card mb-3">
    <div class="card-header"><i class="bi bi-funnel"></i><span>Filtros</span></div>
    <div class="card-body">
        <form class="row gy-2 gx-3 align-items-end" method="GET">
            <div class="col-md-3">
                <label class="form-label">Desde</label>
                <input type="date" name="desde" class="form-control" value="<?php echo $filtroFechaIni; ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Hasta</label>
                <input type="date" name="hasta" class="form-control" value="<?php echo $filtroFechaFin; ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Actividad</label>
                <select name="actividad" class="form-select">
                    <option value="">Todas</option>
                    <?php foreach($actividades as $a): ?>
                        <option value="<?php echo $a['id_actividad']; ?>" <?php echo $filtroActividad==$a['id_actividad']?'selected':''; ?>><?php echo clean($a['nombre_actividad']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Socio</label>
                <select name="socio" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach($socios as $s): ?>
                        <option value="<?php echo $s['id_socio']; ?>" <?php echo $filtroSocio==$s['id_socio']?'selected':''; ?>><?php echo clean($s['nombre_completo']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary btn-icon"><span><i class="bi bi-bar-chart"></i> Aplicar</span></button>
                <a class="btn btn-outline-secondary btn-icon" href="estadisticas.php"><span><i class="bi bi-eraser"></i> Limpiar</span></a>
            </div>
        </form>
    </div>
</div>
<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header category-ingresos"><i class="bi bi-graph-up-arrow"></i><span>Ingresos vs egresos por mes</span></div>
            <div class="card-body">
                <canvas id="chartIngresosEgresos"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header category-ingresos"><i class="bi bi-pie-chart"></i><span>Ingresos por actividad</span></div>
            <div class="card-body">
                <canvas id="chartIngresosActividad"></canvas>
            </div>
        </div>
    </div>
</div>
<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header category-pollas"><i class="bi bi-trophy-fill"></i><span>Evolución mensual de pollas</span></div>
            <div class="card-body">
                <canvas id="chartPollas"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header category-prestamos"><i class="bi bi-cash-stack"></i><span>Capital prestado vs recuperado</span></div>
            <div class="card-body">
                <canvas id="chartPrestamos"></canvas>
            </div>
        </div>
    </div>
</div>
<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header category-egresos"><i class="bi bi-graph-down"></i><span>Egresos por actividad</span></div>
            <div class="card-body">
                <canvas id="chartEgresosActividad"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header category-balance"><i class="bi bi-activity"></i><span>Saldo neto mensual</span></div>
            <div class="card-body">
                <canvas id="chartSaldoMensual"></canvas>
            </div>
        </div>
    </div>
</div>
<div class="row g-3">
    <div class="col-12">
        <div class="card h-100">
            <div class="card-header category-ingresos"><i class="bi bi-credit-card-2-back"></i><span>Movimientos por medio de pago</span></div>
            <div class="card-body">
                <canvas id="chartMediosPago"></canvas>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const ingresosEgresosCtx = document.getElementById('chartIngresosEgresos');
new Chart(ingresosEgresosCtx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($mesesBar); ?>,
        datasets: [
            {
                label: 'Ingresos',
                backgroundColor: '#22c55e',
                data: <?php echo json_encode($ingresosBar); ?>
            },
            {
                label: 'Egresos',
                backgroundColor: '#ef4444',
                data: <?php echo json_encode($egresosBar); ?>
            }
        ]
    },
    options: { responsive: true, maintainAspectRatio: false }
});

const ingresosActividadCtx = document.getElementById('chartIngresosActividad');
new Chart(ingresosActividadCtx, {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode($actividadesLabels); ?>,
        datasets: [{
            data: <?php echo json_encode($ingresosValues); ?>,
            backgroundColor: ['#22c55e','#10b981','#16a34a','#15803d','#22d3ee','#a855f7','#f97316','#eab308']
        }]
    },
    options: { responsive: true, maintainAspectRatio: false }
});

const egresosActividadCtx = document.getElementById('chartEgresosActividad');
new Chart(egresosActividadCtx, {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode($egresosActividadLabels); ?>,
        datasets: [{
            data: <?php echo json_encode($egresosValues); ?>,
            backgroundColor: ['#ef4444', '#f97316', '#f59e0b', '#eab308', '#0ea5e9', '#3b82f6', '#6366f1', '#a855f7']
        }]
    },
    options: { responsive: true, maintainAspectRatio: false }
});

const saldoMensualCtx = document.getElementById('chartSaldoMensual');
new Chart(saldoMensualCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($mesesBar); ?>,
        datasets: [
            {
                label: 'Neto del mes',
                data: <?php echo json_encode($netoMensual); ?>,
                borderColor: '#22d3ee',
                backgroundColor: 'rgba(34,211,238,0.2)',
                fill: true,
                tension: 0.2
            },
            {
                label: 'Saldo acumulado',
                data: <?php echo json_encode($saldoAcumulado); ?>,
                borderColor: '#0f172a',
                backgroundColor: 'rgba(15,23,42,0.15)',
                fill: true,
                tension: 0.2
            }
        ]
    },
    options: { responsive: true, maintainAspectRatio: false }
});

const mediosPagoCtx = document.getElementById('chartMediosPago');
new Chart(mediosPagoCtx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($mediosLabels); ?>,
        datasets: [
            {
                label: 'Ingresos',
                backgroundColor: '#22c55e',
                data: <?php echo json_encode($mediosIngresos); ?>
            },
            {
                label: 'Egresos',
                backgroundColor: '#ef4444',
                data: <?php echo json_encode($mediosEgresos); ?>
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            x: {
                stacked: true
            },
            y: {
                stacked: true
            }
        }
    }
});

const pollasCtx = document.getElementById('chartPollas');
new Chart(pollasCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($mesesPollas); ?>,
        datasets: [{
            label: 'Total pollas',
            data: <?php echo json_encode($pollasValues); ?>,
            fill: false,
            borderColor: '#8b5cf6',
            tension: 0.2
        }]
    },
    options: { responsive: true, maintainAspectRatio: false }
});

const prestamosCtx = document.getElementById('chartPrestamos');
new Chart(prestamosCtx, {
    type: 'bar',
    data: {
        labels: ['Capital prestado', 'Capital recuperado'],
        datasets: [{
            label: 'Préstamos',
            data: [<?php echo $prestado; ?>, <?php echo $recuperado; ?>],
            backgroundColor: ['#1d4ed8', '#0ea5e9']
        }]
    },
    options: { responsive: true, maintainAspectRatio: false }
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
