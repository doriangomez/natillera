<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$periodosConfig = $pdo
    ->query('SELECT anio, mes FROM periodos_configuracion WHERE activo = 1 ORDER BY anio DESC, mes DESC')
    ->fetchAll();

$nombresMeses = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
    5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
    9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
];

$periodosPorAnio = [];
foreach ($periodosConfig as $p) {
    $periodosPorAnio[$p['anio']][] = (int) $p['mes'];
}
if (!empty($periodosPorAnio)) {
    krsort($periodosPorAnio);
}

$aniosDisponibles = array_keys($periodosPorAnio);
$anioDefault = !empty($aniosDisponibles) ? (int) reset($aniosDisponibles) : (int) date('Y');
$mesesDefault = $periodosPorAnio[$anioDefault] ?? [(int) date('n')];
$mesDefault = (int) (reset($mesesDefault) ?: date('n'));
$periodosParaFiltros = !empty($periodosPorAnio) ? $periodosPorAnio : [$anioDefault => $mesesDefault];

$filtroAnio = isset($_GET['anio']) ? (int) $_GET['anio'] : $anioDefault;
$mesesDisponibles = $periodosParaFiltros[$filtroAnio] ?? $mesesDefault;
$filtroMes = isset($_GET['mes']) ? (int) $_GET['mes'] : $mesDefault;
if (!in_array($filtroMes, $mesesDisponibles, true)) {
    $filtroMes = (int) reset($mesesDisponibles);
}

$fechaInicio = $_GET['desde'] ?? '';
$fechaFin = $_GET['hasta'] ?? '';
if (!$fechaInicio && !$fechaFin) {
    $fechaInicio = sprintf('%04d-%02d-01', $filtroAnio, $filtroMes);
    $fechaFin = date('Y-m-t', strtotime($fechaInicio));
}

$sql = "SELECT m.id_movimiento, m.fecha, m.anio, m.mes, m.quincena, a.nombre_actividad, m.motivo, m.valor, m.medio_consignacion
        FROM movimientos m
        INNER JOIN actividades_maestro a ON m.id_actividad = a.id_actividad
        WHERE a.es_gasto_general = 1";

$params = [];
if ($fechaInicio) {
    $sql .= ' AND m.fecha >= :fi';
    $params[':fi'] = $fechaInicio;
}
if ($fechaFin) {
    $sql .= ' AND m.fecha <= :ff';
    $params[':ff'] = $fechaFin;
}

$sql .= ' ORDER BY m.fecha DESC LIMIT 300';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$gastos = $stmt->fetchAll();
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
<div class="card mb-3">
    <div class="card-header category-gastos"><i class="bi bi-funnel"></i><span>Filtrar por periodo</span></div>
    <div class="card-body">
        <form class="row g-2" method="GET">
            <div class="col-md-2">
                <label class="form-label">Año</label>
                <select name="anio" class="form-select">
                    <?php foreach($periodosParaFiltros as $anio => $meses): ?>
                        <option value="<?php echo $anio; ?>" <?php echo ($filtroAnio===(int)$anio)?'selected':''; ?>><?php echo $anio; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Mes</label>
                <select name="mes" class="form-select">
                    <?php foreach($mesesDisponibles as $m): ?>
                        <option value="<?php echo $m; ?>" <?php echo ($filtroMes===$m)?'selected':''; ?>><?php echo $nombresMeses[$m]; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Desde</label>
                <input type="date" name="desde" class="form-control" value="<?php echo $fechaInicio; ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Hasta</label>
                <input type="date" name="hasta" class="form-control" value="<?php echo $fechaFin; ?>">
            </div>
            <div class="col-md-4 d-flex align-items-end gap-2">
                <button class="btn btn-primary btn-icon" type="submit"><span><i class="bi bi-funnel"></i> Aplicar</span></button>
                <a class="btn btn-outline-secondary btn-icon" href="gastos.php"><span><i class="bi bi-arrow-counterclockwise"></i> Reiniciar</span></a>
            </div>
        </form>
        <p class="text-muted small mb-0">Los periodos usan la <strong>fecha de movimiento</strong>; la fecha de registro solo se conserva como soporte.</p>
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
                                <th>Fecha movimiento</th>
                                <th>Periodo</th>
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
                                        <td><?php echo trim(($g['mes'] ?? '') . '/' . ($g['anio'] ?? '')); ?><?php echo !empty($g['quincena']) ? ' - Q' . $g['quincena'] : ''; ?></td>
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
<script>
const anioFiltro = document.querySelector('select[name="anio"]');
const mesFiltro = document.querySelector('select[name="mes"]');
const fechaDesde = document.querySelector('input[name="desde"]');
const fechaHasta = document.querySelector('input[name="hasta"]');
const periodosPorAnio = <?php echo json_encode($periodosPorAnio); ?>;
const nombresMeses = <?php echo json_encode($nombresMeses); ?>;

function actualizarMesesDisponibles(){
    if(!anioFiltro || !mesFiltro) return;
    const anio = anioFiltro.value;
    const mesesDisponibles = periodosPorAnio[anio] ?? [];
    if(mesesDisponibles.length){
        mesFiltro.querySelectorAll('option').forEach(opt => opt.remove());
        mesesDisponibles.forEach(val => {
            const option = document.createElement('option');
            option.value = val;
            option.textContent = nombresMeses[val] || val;
            mesFiltro.appendChild(option);
        });
        if(!mesesDisponibles.includes(parseInt(mesFiltro.value, 10))){
            mesFiltro.value = mesesDisponibles[0];
        }
    }
    actualizarRangoFechas();
}

function actualizarRangoFechas(){
    if(!anioFiltro || !mesFiltro || !fechaDesde || !fechaHasta) return;
    const anio = anioFiltro.value;
    const mes = mesFiltro.value.toString().padStart(2, '0');
    const ultimoDia = new Date(parseInt(anio, 10), parseInt(mes, 10), 0).getDate();
    fechaDesde.value = `${anio}-${mes}-01`;
    fechaHasta.value = `${anio}-${mes}-${String(ultimoDia).padStart(2, '0')}`;
}

if(anioFiltro && mesFiltro){
    anioFiltro.addEventListener('change', actualizarMesesDisponibles);
    mesFiltro.addEventListener('change', actualizarRangoFechas);
    actualizarMesesDisponibles();
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
