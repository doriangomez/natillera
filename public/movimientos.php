<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$socios = getSocios($pdo);
$actividades = getActividades($pdo);
$mediosPago = getMediosPago($pdo);

$periodosConfig = $pdo
    ->query('SELECT anio, mes FROM periodos_configuracion WHERE activo = 1 ORDER BY anio DESC, mes DESC')
    ->fetchAll();

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
$fechaDefault = sprintf('%04d-%02d-01', $anioDefault, $mesDefault);

$filtroSocio = isset($_GET['socio']) ? (int) $_GET['socio'] : 0;
$filtroActividad = isset($_GET['actividad']) ? (int) $_GET['actividad'] : 0;
$filtroFechaIni = $_GET['desde'] ?? '';
$filtroFechaFin = $_GET['hasta'] ?? '';
$filtroTipo = $_GET['tipo'] ?? '';
$filtroMedio = isset($_GET['medio']) ? (int) $_GET['medio'] : 0;

$where = [];
$params = [];
if ($filtroSocio) { $where[] = 'm.id_socio = :s'; $params[':s'] = $filtroSocio; }
if ($filtroActividad) { $where[] = 'm.id_actividad = :a'; $params[':a'] = $filtroActividad; }
if ($filtroFechaIni) { $where[] = 'm.fecha >= :fi'; $params[':fi'] = $filtroFechaIni; }
if ($filtroFechaFin) { $where[] = 'm.fecha <= :ff'; $params[':ff'] = $filtroFechaFin; }
if ($filtroTipo === 'ingreso') { $where[] = 'm.es_ingreso = 1'; }
if ($filtroTipo === 'egreso') { $where[] = 'm.es_egreso = 1'; }
if ($filtroMedio) { $where[] = 'm.id_medio_pago = :mp'; $params[':mp'] = $filtroMedio; }

$sql = "SELECT m.*, s.nombre_completo, a.nombre_actividad, mp.nombre AS medio_nombre FROM movimientos m
        LEFT JOIN socios s ON m.id_socio = s.id_socio
        LEFT JOIN actividades_maestro a ON m.id_actividad = a.id_actividad
        LEFT JOIN medios_pago mp ON m.id_medio_pago = mp.id";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY m.fecha DESC LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$movimientos = $stmt->fetchAll();

$totales = ['ingresos'=>0,'egresos'=>0];
foreach ($movimientos as $m) {
    if ($m['es_ingreso']) { $totales['ingresos'] += $m['valor']; }
    if ($m['es_egreso']) { $totales['egresos'] += $m['valor']; }
}
?>
<h2 class="mb-3 d-flex align-items-center gap-2"><i class="bi bi-arrows-left-right text-primary"></i><span>Movimientos</span></h2>
<div class="card mb-3">
    <div class="card-header category-ingresos"><i class="bi bi-funnel"></i><span>Filtrar movimientos</span></div>
    <div class="card-body">
        <form class="row g-2" method="GET">
            <div class="col-md-2"><label class="form-label">Desde</label><input type="date" name="desde" class="form-control" value="<?php echo $filtroFechaIni; ?>"></div>
            <div class="col-md-2"><label class="form-label">Hasta</label><input type="date" name="hasta" class="form-control" value="<?php echo $filtroFechaFin; ?>"></div>
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
            <div class="col-md-2"><label class="form-label">Medio de pago</label>
                <select name="medio" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach($mediosPago as $mp): ?>
                        <option value="<?php echo $mp['id']; ?>" <?php echo (!empty($_GET['medio']) && $_GET['medio']==$mp['id'])?'selected':''; ?>><?php echo clean($mp['nombre']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><label class="form-label">Tipo</label>
                <select name="tipo" class="form-select">
                    <option value="">Todos</option>
                    <option value="ingreso" <?php echo $filtroTipo==='ingreso'?'selected':''; ?>>Ingreso</option>
                    <option value="egreso" <?php echo $filtroTipo==='egreso'?'selected':''; ?>>Egreso</option>
                </select>
            </div>
            <div class="col-md-12">
                <button class="btn btn-primary btn-icon"><span><i class="bi bi-funnel"></i> Filtrar</span></button>
                <a class="btn btn-outline-secondary btn-icon" href="../actions/export_csv.php?tipo=movimientos"><span><i class="bi bi-file-earmark-arrow-down"></i> Exportar CSV</span></a>
            </div>
        </form>
    </div>
</div>
<div class="card mb-3">
    <div class="card-header category-gastos"><i class="bi bi-plus-circle"></i><span>Registrar movimiento</span></div>
    <div class="card-body">
        <?php if (empty($periodosPorAnio)): ?>
            <div class="alert alert-warning">Configure los periodos en el módulo de configuración para habilitar los meses y años permitidos.</div>
        <?php endif; ?>
        <form method="POST" action="../actions/movimientos_save.php">
            <div class="row g-2">
                <div class="col-md-2">
                    <label class="form-label"><span class="text-danger">*</span> Fecha</label>
                    <input type="date" name="fecha" class="form-control" required value="<?php echo $fechaDefault; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label"><span class="text-danger">*</span> Año</label>
                    <select name="anio" class="form-select" required>
                        <?php if (!empty($periodosPorAnio)): ?>
                            <?php foreach ($periodosPorAnio as $anio => $meses): ?>
                                <option value="<?php echo $anio; ?>" <?php echo ($anioDefault === (int) $anio) ? 'selected' : ''; ?>><?php echo $anio; ?></option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="<?php echo $anioDefault; ?>" selected><?php echo $anioDefault; ?></option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label"><span class="text-danger">*</span> Mes</label>
                    <select name="mes" class="form-select" required>
                        <?php for($m=1;$m<=12;$m++): ?>
                            <?php
                                $habilitado = empty($periodosPorAnio) || in_array($m, $mesesDefault, true);
                            ?>
                            <option value="<?php echo $m; ?>" <?php echo $m === $mesDefault ? 'selected' : ''; ?> <?php echo $habilitado ? '' : 'disabled'; ?>><?php echo $m; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label"><span class="text-danger">*</span> Quincena</label>
                    <select name="quincena" class="form-select" id="quincenaSelect" required>
                        <option value="" selected disabled>Seleccione la quincena</option>
                        <option value="1">Primera</option>
                        <option value="2">Segunda</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><span class="text-danger">*</span> Socio</label>
                    <select name="id_socio" class="form-select" id="socioSelect" required>
                        <option value="" selected disabled>Seleccione un socio</option>
                        <?php foreach($socios as $s): ?>
                            <option value="<?php echo $s['id_socio']; ?>" data-periodicidad="<?php echo clean($s['periodicidad_pago']); ?>"><?php echo clean($s['nombre_completo']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><span class="text-danger">*</span> Actividad</label>
                    <select name="id_actividad" class="form-select" required>
                        <option value="" selected disabled>Seleccione una actividad</option>
                        <?php foreach($actividades as $a): ?>
                            <option value="<?php echo $a['id_actividad']; ?>" data-regla="<?php echo clean($a['afecta_saldo_natillera']); ?>" data-es-polla="<?php echo !empty($a['es_polla']) ? '1' : '0'; ?>">
                                <?php echo clean($a['nombre_actividad']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label"><span class="text-danger">*</span> Valor</label>
                    <input type="number" step="0.01" name="valor" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tipo automático</label>
                    <input type="text" class="form-control" id="tipoActividad" value="Seleccione una actividad" disabled>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><span class="text-danger">*</span> Medio consignación</label>
                    <select name="id_medio_pago" class="form-select" required>
                        <?php foreach($mediosPago as $mp): ?>
                            <option value="<?php echo $mp['id']; ?>"><?php echo clean($mp['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Observaciones</label>
                    <input type="text" name="observaciones" class="form-control">
                </div>
            </div>
            <button class="btn btn-success mt-3 btn-icon"><span><i class="bi bi-check2-circle"></i> Guardar</span></button>
        </form>
    </div>
</div>
<div class="table-responsive">
    <table class="table table-striped table-sm">
        <thead>
            <tr>
                <th>Fecha</th><th>Periodo</th><th>Socio</th><th>Actividad</th><th>Valor</th><th>Medio</th><th>Módulo</th><th>Ingreso</th><th>Egreso</th><th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($movimientos as $m): ?>
                <tr>
                    <td><?php echo $m['fecha']; ?></td>
                    <td><?php echo trim(($m['mes'] ?? '') . '/' . ($m['anio'] ?? '')); ?><?php echo $m['quincena'] ? ' - Q' . $m['quincena'] : ''; ?></td>
                    <td><?php echo $m['nombre_completo'] ?? 'General'; ?></td>
                    <td><?php echo $m['nombre_actividad']; ?></td>
                    <td>$<?php echo number_format($m['valor'],0,',','.'); ?></td>
                    <td><?php echo clean($m['medio_nombre'] ?: $m['medio_consignacion']); ?></td>
                    <td><?php echo clean($m['modulo'] ?: 'movimientos'); ?></td>
                    <td><?php echo $m['es_ingreso'] ? 'Sí' : ''; ?></td>
                    <td><?php echo $m['es_egreso'] ? 'Sí' : ''; ?></td>
                    <td class="text-end">
                        <form method="POST" action="../actions/movimientos_save.php" class="d-inline" onsubmit="return confirm('Esta acción eliminará el movimiento seleccionado. ¿Deseas continuar?');">
                            <input type="hidden" name="accion" value="eliminar">
                            <input type="hidden" name="id_movimiento" value="<?php echo $m['id_movimiento']; ?>">
                            <input type="hidden" name="redirect" value="../public/movimientos.php">
                            <button class="btn btn-sm btn-outline-danger" type="submit">Eliminar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<div class="alert alert-info">Totales mostrados: Ingresos $<?php echo number_format($totales['ingresos'],0,',','.'); ?> | Egresos $<?php echo number_format($totales['egresos'],0,',','.'); ?></div>
<script>
const actividadSelect = document.querySelector('select[name="id_actividad"]');
const tipoActividadInput = document.getElementById('tipoActividad');
const anioSelect = document.querySelector('select[name="anio"]');
const mesSelect = document.querySelector('select[name="mes"]');
const valorInput = document.querySelector('input[name="valor"]');
const formularioMovimiento = document.querySelector('form[action="../actions/movimientos_save.php"]');
const fechaInput = document.querySelector('input[name="fecha"]');
const periodosPorAnio = <?php echo json_encode($periodosPorAnio); ?>;

function actualizarTipoActividad(){
    const regla = actividadSelect.selectedOptions[0]?.dataset.regla || '';
    if(!regla) tipoActividadInput.value = 'Seleccione una actividad';
    else if(regla === 'suma') tipoActividadInput.value = 'Ingreso automático';
    else if(regla === 'resta') tipoActividadInput.value = 'Egreso automático';
    else tipoActividadInput.value = 'Neutral (no afecta saldo)';
}
function actualizarMeses(){
    if(!mesSelect || !anioSelect) return;
    const anio = parseInt(anioSelect.value, 10);
    const mesesDisponibles = Object.keys(periodosPorAnio).length
        ? (periodosPorAnio[anio] ?? [])
        : Array.from({length: 12}, (_, i) => i + 1);
    mesSelect.querySelectorAll('option').forEach(opt => {
        const val = parseInt(opt.value, 10);
        const habilitado = mesesDisponibles.includes(val);
        opt.disabled = !habilitado;
    });
    if (!mesesDisponibles.includes(parseInt(mesSelect.value, 10))) {
        mesSelect.value = mesesDisponibles[0] ?? '';
    }
    actualizarFecha();
}

function actualizarFecha(){
    if(!fechaInput || !anioSelect || !mesSelect) return;
    const anio = anioSelect.value;
    const mes = mesSelect.value.toString().padStart(2, '0');
    const diaActual = (fechaInput.value?.split('-')[2]) || '01';
    const maxDia = new Date(parseInt(anio, 10), parseInt(mes, 10), 0).getDate();
    const dia = String(Math.min(parseInt(diaActual, 10) || 1, maxDia)).padStart(2, '0');
    fechaInput.value = `${anio}-${mes}-${dia}`;
}
if(actividadSelect){
    actividadSelect.addEventListener('change', actualizarTipoActividad);
    actualizarTipoActividad();
}
if(anioSelect && mesSelect){
    anioSelect.addEventListener('change', actualizarMeses);
    mesSelect.addEventListener('change', actualizarFecha);
    actualizarMeses();
}

function esPollaSeleccionada(){
    const opcion = actividadSelect?.selectedOptions[0];
    return opcion?.dataset.esPolla === '1';
}

if(formularioMovimiento){
    formularioMovimiento.addEventListener('submit', (ev) => {
        if(!esPollaSeleccionada()) return;
        const valor = Math.abs(parseFloat(valorInput?.value ?? ''));
        if(Number.isNaN(valor) || valor === 20000) return;
        const continuar = confirm('Advertencia: normalmente las pollas se registran por $20.000. ¿Deseas continuar con un valor diferente?');
        if(!continuar){
            ev.preventDefault();
        }
    });
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
