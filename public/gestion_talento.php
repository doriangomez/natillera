<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$hoy = new DateTimeImmutable('today');
$desdeDefault = $hoy->modify('-6 weeks')->format('Y-m-d');
$hastaDefault = $hoy->modify('+2 weeks')->format('Y-m-d');

$filtroDesde = $_GET['desde'] ?? $desdeDefault;
$filtroHasta = $_GET['hasta'] ?? $hastaDefault;
$filtroArea = trim($_GET['area'] ?? '');
$filtroProyecto = trim($_GET['proyecto'] ?? '');
$filtroRol = trim($_GET['rol'] ?? '');
$periodo = $_GET['periodo'] ?? 'semana';
if (!in_array($periodo, ['dia', 'semana', 'mes'], true)) {
    $periodo = 'semana';
}
$capacidadDiaria = max(1, (float) ($_GET['capacidad_diaria'] ?? 8));
$horasBaseTarea = max(0.5, (float) ($_GET['horas_base_tarea'] ?? 2));

$socios = getSocios($pdo);

$sqlFiltros = "SELECT DISTINCT COALESCE(NULLIF(m.modulo, ''), 'Sin proyecto') AS proyecto
               FROM movimientos m
               WHERE m.id_socio IS NOT NULL
               ORDER BY proyecto";
$proyectos = $pdo->query($sqlFiltros)->fetchAll(PDO::FETCH_COLUMN) ?: [];

$areas = [];
$roles = [];
$sociosMap = [];
foreach ($socios as $socio) {
    $idSocio = (int) $socio['id_socio'];
    $area = trim((string) ($socio['grupo'] ?? '')) ?: 'Sin área';
    $rolSocio = trim((string) ($socio['periodicidad_pago'] ?? '')) ?: 'Sin rol';
    $areas[$area] = true;
    $roles[$rolSocio] = true;
    $sociosMap[$idSocio] = [
        'id' => $idSocio,
        'nombre' => $socio['nombre_completo'],
        'area' => $area,
        'rol' => $rolSocio,
    ];
}
ksort($areas);
ksort($roles);

$where = ['m.id_socio IS NOT NULL'];
$params = [];
if ($filtroDesde !== '') {
    $where[] = 'm.fecha >= :desde';
    $params[':desde'] = $filtroDesde;
}
if ($filtroHasta !== '') {
    $where[] = 'm.fecha <= :hasta';
    $params[':hasta'] = $filtroHasta;
}
if ($filtroProyecto !== '') {
    if ($filtroProyecto === 'Sin proyecto') {
        $where[] = "(m.modulo IS NULL OR m.modulo = '')";
    } else {
        $where[] = 'm.modulo = :proyecto';
        $params[':proyecto'] = $filtroProyecto;
    }
}
if ($filtroArea !== '') {
    $where[] = 'COALESCE(NULLIF(s.grupo, \'\'), \'Sin área\') = :area';
    $params[':area'] = $filtroArea;
}
if ($filtroRol !== '') {
    $where[] = 'COALESCE(NULLIF(s.periodicidad_pago, \'\'), \'Sin rol\') = :rol';
    $params[':rol'] = $filtroRol;
}

$baseWhere = 'WHERE ' . implode(' AND ', $where);

$sqlCarga = "SELECT m.id_socio,
        m.fecha,
        COALESCE(NULLIF(m.modulo, ''), 'Sin proyecto') AS proyecto,
        COALESCE(NULLIF(s.grupo, ''), 'Sin área') AS area,
        COALESCE(NULLIF(s.periodicidad_pago, ''), 'Sin rol') AS rol,
        CASE
            WHEN m.valor BETWEEN 0 AND 24 THEN m.valor
            ELSE :horas_base
        END AS horas_equivalentes
    FROM movimientos m
    INNER JOIN socios s ON s.id_socio = m.id_socio
    $baseWhere
    ORDER BY m.fecha";
$stmtCarga = $pdo->prepare($sqlCarga);
$stmtCarga->bindValue(':horas_base', $horasBaseTarea);
foreach ($params as $llave => $valor) {
    $stmtCarga->bindValue($llave, $valor);
}
$stmtCarga->execute();
$registros = $stmtCarga->fetchAll(PDO::FETCH_ASSOC);

$inicio = new DateTimeImmutable($filtroDesde ?: $desdeDefault);
$fin = new DateTimeImmutable($filtroHasta ?: $hastaDefault);
if ($inicio > $fin) {
    [$inicio, $fin] = [$fin, $inicio];
}

$columnas = [];
$iterador = $inicio;
while ($iterador <= $fin) {
    if ($periodo === 'dia') {
        $key = $iterador->format('Y-m-d');
        $label = $iterador->format('d M');
        $dias = 1;
        $siguiente = $iterador->modify('+1 day');
    } elseif ($periodo === 'semana') {
        $inicioSemana = $iterador->modify('monday this week');
        $finSemana = $inicioSemana->modify('+6 day');
        if ($inicioSemana < $inicio) {
            $inicioSemana = $inicio;
        }
        if ($finSemana > $fin) {
            $finSemana = $fin;
        }
        $key = $inicioSemana->format('Y-m-d');
        $label = $inicioSemana->format('d M') . ' - ' . $finSemana->format('d M');
        $dias = (int) $inicioSemana->diff($finSemana)->format('%a') + 1;
        $siguiente = $finSemana->modify('+1 day');
    } else {
        $inicioMes = $iterador->modify('first day of this month');
        $finMes = $iterador->modify('last day of this month');
        if ($inicioMes < $inicio) {
            $inicioMes = $inicio;
        }
        if ($finMes > $fin) {
            $finMes = $fin;
        }
        $key = $inicioMes->format('Y-m');
        $label = $inicioMes->format('M Y');
        $dias = (int) $inicioMes->diff($finMes)->format('%a') + 1;
        $siguiente = $finMes->modify('+1 day');
    }

    if (!isset($columnas[$key])) {
        $columnas[$key] = [
            'label' => $label,
            'dias' => $dias,
        ];
    }
    $iterador = $siguiente;
}

$metricasTalento = [];
foreach ($sociosMap as $idSocio => $perfil) {
    if ($filtroArea !== '' && $perfil['area'] !== $filtroArea) {
        continue;
    }
    if ($filtroRol !== '' && $perfil['rol'] !== $filtroRol) {
        continue;
    }

    $metricasTalento[$idSocio] = [
        'nombre' => $perfil['nombre'],
        'area' => $perfil['area'],
        'rol' => $perfil['rol'],
        'asignadas' => 0,
        'capacidad' => 0,
        'porcentaje' => 0,
        'periodos' => array_fill_keys(array_keys($columnas), 0),
    ];

    foreach ($columnas as $key => $metaColumna) {
        $metricasTalento[$idSocio]['capacidad'] += $metaColumna['dias'] * $capacidadDiaria;
    }
}

foreach ($registros as $registro) {
    $idSocio = (int) $registro['id_socio'];
    if (!isset($metricasTalento[$idSocio])) {
        continue;
    }

    $fecha = new DateTimeImmutable($registro['fecha']);
    if ($periodo === 'dia') {
        $key = $fecha->format('Y-m-d');
    } elseif ($periodo === 'semana') {
        $key = $fecha->modify('monday this week')->format('Y-m-d');
        if (!isset($columnas[$key])) {
            $key = array_key_first($columnas);
        }
    } else {
        $key = $fecha->format('Y-m');
    }

    if (!isset($metricasTalento[$idSocio]['periodos'][$key])) {
        continue;
    }

    $horas = (float) $registro['horas_equivalentes'];
    $metricasTalento[$idSocio]['periodos'][$key] += $horas;
    $metricasTalento[$idSocio]['asignadas'] += $horas;
}

$promedioUtilizacion = 0;
$totalAsignadas = 0;
$totalCapacidad = 0;
$totalSobreasignadas = 0;
$totalOciosas = 0;
$talentosRiesgo = 0;

foreach ($metricasTalento as &$talento) {
    $talento['porcentaje'] = $talento['capacidad'] > 0
        ? ($talento['asignadas'] / $talento['capacidad']) * 100
        : 0;

    if ($talento['porcentaje'] >= 90) {
        $talentosRiesgo++;
    }

    $totalAsignadas += $talento['asignadas'];
    $totalCapacidad += $talento['capacidad'];

    if ($talento['asignadas'] > $talento['capacidad']) {
        $totalSobreasignadas += ($talento['asignadas'] - $talento['capacidad']);
    }
    if ($talento['asignadas'] < $talento['capacidad']) {
        $totalOciosas += ($talento['capacidad'] - $talento['asignadas']);
    }
}
unset($talento);

if ($totalCapacidad > 0) {
    $promedioUtilizacion = ($totalAsignadas / $totalCapacidad) * 100;
}

usort($metricasTalento, static function (array $a, array $b): int {
    return $b['porcentaje'] <=> $a['porcentaje'];
});

function claseEstadoCarga(float $porcentaje): string {
    if ($porcentaje > 100) {
        return 'estado-sobrecarga';
    }
    if ($porcentaje >= 90) {
        return 'estado-riesgo';
    }
    if ($porcentaje >= 70) {
        return 'estado-saludable';
    }
    if ($porcentaje < 1) {
        return 'estado-sin-asignacion';
    }
    if ($porcentaje < 60) {
        return 'estado-subutilizado';
    }
    return 'estado-estable';
}

$labelsTalento = array_map(static fn(array $t) => $t['nombre'], $metricasTalento);
$capacidadTalento = array_map(static fn(array $t) => round((float) $t['capacidad'], 2), $metricasTalento);
$cargaTalento = array_map(static fn(array $t) => round((float) $t['asignadas'], 2), $metricasTalento);
$porcentajeTalento = array_map(static fn(array $t) => round((float) $t['porcentaje'], 1), $metricasTalento);
?>

<style>
.kpi-card{border-radius:18px;padding:1rem 1.25rem;background:linear-gradient(135deg,#0f172a,#1d4ed8);color:#fff;min-height:130px}
.kpi-label{font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;opacity:.85}
.kpi-value{font-size:2rem;font-weight:700;line-height:1.1}
.kpi-sub{opacity:.85;font-size:.85rem}
.heatmap-wrap{overflow:auto}
.heatmap{border-collapse:separate;border-spacing:6px;min-width:920px}
.heatmap th{font-size:.75rem;color:#64748b;white-space:nowrap}
.heat-cell{border-radius:10px;padding:.45rem .5rem;text-align:center;font-weight:600;color:#0f172a;font-size:.83rem;min-width:90px}
.estado-sobrecarga{background:#ef4444;color:#fff}
.estado-riesgo{background:#facc15}
.estado-saludable{background:#22c55e;color:#fff}
.estado-subutilizado{background:#bfdbfe}
.estado-sin-asignacion{background:#e5e7eb;color:#6b7280}
.estado-estable{background:#86efac}
</style>

<h2 class="mb-3 d-flex align-items-center gap-2"><i class="bi bi-grid-1x2-fill text-primary"></i><span>Gestión Visual de Carga y Capacidad del Talento</span></h2>

<div class="card mb-3">
    <div class="card-header"><i class="bi bi-funnel"></i><span>Filtros operativos</span></div>
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label">Desde</label>
                <input type="date" class="form-control" name="desde" value="<?php echo clean($filtroDesde); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Hasta</label>
                <input type="date" class="form-control" name="hasta" value="<?php echo clean($filtroHasta); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Área</label>
                <select class="form-select" name="area">
                    <option value="">Todas</option>
                    <?php foreach (array_keys($areas) as $area): ?>
                        <option value="<?php echo clean($area); ?>" <?php echo $filtroArea === $area ? 'selected' : ''; ?>><?php echo clean($area); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Proyecto</label>
                <select class="form-select" name="proyecto">
                    <option value="">Todos</option>
                    <?php foreach ($proyectos as $proyecto): ?>
                        <option value="<?php echo clean($proyecto); ?>" <?php echo $filtroProyecto === $proyecto ? 'selected' : ''; ?>><?php echo clean($proyecto); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Rol</label>
                <select class="form-select" name="rol">
                    <option value="">Todos</option>
                    <?php foreach (array_keys($roles) as $rol): ?>
                        <option value="<?php echo clean($rol); ?>" <?php echo $filtroRol === $rol ? 'selected' : ''; ?>><?php echo clean($rol); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Vista</label>
                <select class="form-select" name="periodo">
                    <option value="dia" <?php echo $periodo === 'dia' ? 'selected' : ''; ?>>Día</option>
                    <option value="semana" <?php echo $periodo === 'semana' ? 'selected' : ''; ?>>Semana</option>
                    <option value="mes" <?php echo $periodo === 'mes' ? 'selected' : ''; ?>>Mes</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Capacidad diaria (h)</label>
                <input type="number" step="0.5" min="1" class="form-control" name="capacidad_diaria" value="<?php echo clean((string) $capacidadDiaria); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Horas base / tarea</label>
                <input type="number" step="0.5" min="0.5" class="form-control" name="horas_base_tarea" value="<?php echo clean((string) $horasBaseTarea); ?>">
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-eye"></i> Actualizar tablero</button>
                <a href="gestion_talento.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-counterclockwise"></i> Restablecer</a>
            </div>
        </form>
        <div class="small text-muted mt-2">La ocupación se estima automáticamente con movimientos asignados por talento y fecha. Si el valor del movimiento está entre 0 y 24 se toma como horas registradas; en otros casos usa la base configurable por tarea.</div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="kpi-card"><div class="kpi-label">Utilización promedio</div><div class="kpi-value"><?php echo number_format($promedioUtilizacion, 1); ?>%</div><div class="kpi-sub">Carga total / capacidad total</div></div></div>
    <div class="col-md-3"><div class="kpi-card"><div class="kpi-label">Horas sobreasignadas</div><div class="kpi-value"><?php echo number_format($totalSobreasignadas, 1); ?>h</div><div class="kpi-sub">Por encima del 100%</div></div></div>
    <div class="col-md-3"><div class="kpi-card"><div class="kpi-label">Talentos en riesgo</div><div class="kpi-value"><?php echo (int) $talentosRiesgo; ?></div><div class="kpi-sub">Entre 90% y sobrecarga</div></div></div>
    <div class="col-md-3"><div class="kpi-card"><div class="kpi-label">Capacidad ociosa</div><div class="kpi-value"><?php echo number_format($totalOciosas, 1); ?>h</div><div class="kpi-sub">Disponibilidad global</div></div></div>
</div>

<div class="card mb-3">
    <div class="card-header"><i class="bi bi-calendar3"></i><span>Heatmap de ocupación del equipo</span></div>
    <div class="card-body heatmap-wrap">
        <table class="heatmap">
            <thead>
                <tr>
                    <th>Talento</th>
                    <?php foreach ($columnas as $columna): ?>
                        <th><?php echo clean($columna['label']); ?></th>
                    <?php endforeach; ?>
                    <th>% total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($metricasTalento as $talento): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?php echo clean($talento['nombre']); ?></div>
                            <div class="small text-muted"><?php echo clean($talento['area']); ?> · <?php echo clean($talento['rol']); ?></div>
                        </td>
                        <?php foreach ($talento['periodos'] as $key => $horasPeriodo):
                            $capacidadPeriodo = $columnas[$key]['dias'] * $capacidadDiaria;
                            $porcentajePeriodo = $capacidadPeriodo > 0 ? ($horasPeriodo / $capacidadPeriodo) * 100 : 0;
                            $clase = claseEstadoCarga($porcentajePeriodo);
                        ?>
                            <td class="heat-cell <?php echo $clase; ?>" title="<?php echo number_format($horasPeriodo, 1); ?>h / <?php echo number_format($capacidadPeriodo, 1); ?>h">
                                <?php echo number_format($porcentajePeriodo, 0); ?>%
                            </td>
                        <?php endforeach; ?>
                        <td class="heat-cell <?php echo claseEstadoCarga($talento['porcentaje']); ?>"><?php echo number_format($talento['porcentaje'], 0); ?>%</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="small mt-2 text-muted">Leyenda: verde saludable (70-90), amarillo riesgo (90-100), rojo sobrecarga (>100), gris sin asignación y azul subutilización (&lt;60).</div>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-bar-chart-line"></i><span>Capacidad vs carga por talento</span></div>
    <div class="card-body">
        <canvas id="chartCapacidadCarga" height="120"></canvas>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('chartCapacidadCarga'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($labelsTalento); ?>,
        datasets: [
            { label: 'Capacidad disponible', data: <?php echo json_encode($capacidadTalento); ?>, backgroundColor: '#93c5fd' },
            { label: 'Horas asignadas', data: <?php echo json_encode($cargaTalento); ?>, backgroundColor: '#2563eb' }
        ]
    },
    options: {
        responsive: true,
        scales: { x: { stacked: false }, y: { beginAtZero: true } },
        plugins: {
            tooltip: {
                callbacks: {
                    afterBody: function(items) {
                        const idx = items[0].dataIndex;
                        const pct = <?php echo json_encode($porcentajeTalento); ?>[idx];
                        return 'Utilización: ' + pct + '%';
                    }
                }
            }
        }
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
