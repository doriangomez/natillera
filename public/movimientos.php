<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$socios = getSocios($pdo);
$actividades = getActividades($pdo);
$mediosPago = getMediosPago($pdo);

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
<h2 class="mb-3">Movimientos</h2>
<div class="card mb-3">
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
                <button class="btn btn-primary">Filtrar</button>
                <a class="btn btn-outline-secondary" href="../actions/export_csv.php?tipo=movimientos">Exportar CSV</a>
            </div>
        </form>
    </div>
</div>
<div class="card mb-3">
    <div class="card-header">Registrar movimiento</div>
    <div class="card-body">
        <form method="POST" action="../actions/movimientos_save.php">
            <div class="row g-2">
                <div class="col-md-2">
                    <label class="form-label">Fecha</label>
                    <input type="date" name="fecha" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Socio (opcional)</label>
                    <select name="id_socio" class="form-select">
                        <option value="">Gasto general</option>
                        <?php foreach($socios as $s): ?>
                            <option value="<?php echo $s['id_socio']; ?>"><?php echo clean($s['nombre_completo']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Actividad</label>
                    <select name="id_actividad" class="form-select" required>
                        <?php foreach($actividades as $a): ?>
                            <option value="<?php echo $a['id_actividad']; ?>"><?php echo clean($a['nombre_actividad']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Valor</label>
                    <input type="number" step="0.01" name="valor" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Ingreso/Egreso</label>
                    <select name="tipo_mov" class="form-select">
                        <option value="ingreso">Ingreso</option>
                        <option value="egreso">Egreso</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Medio consignación</label>
                    <select name="id_medio_pago" class="form-select" required>
                        <?php foreach($mediosPago as $mp): ?>
                            <option value="<?php echo $mp['id']; ?>"><?php echo clean($mp['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Motivo</label>
                    <input type="text" name="motivo" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Observaciones</label>
                    <input type="text" name="observaciones" class="form-control">
                </div>
            </div>
            <button class="btn btn-success mt-3">Guardar</button>
        </form>
    </div>
</div>
<div class="table-responsive">
    <table class="table table-striped table-sm">
        <thead>
            <tr>
                <th>Fecha</th><th>Socio</th><th>Actividad</th><th>Motivo</th><th>Valor</th><th>Medio</th><th>Ingreso</th><th>Egreso</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($movimientos as $m): ?>
                <tr>
                    <td><?php echo $m['fecha']; ?></td>
                    <td><?php echo $m['nombre_completo'] ?? 'General'; ?></td>
                    <td><?php echo $m['nombre_actividad']; ?></td>
                    <td><?php echo clean($m['motivo']); ?></td>
                    <td>$<?php echo number_format($m['valor'],0,',','.'); ?></td>
                    <td><?php echo clean($m['medio_nombre'] ?: $m['medio_consignacion']); ?></td>
                    <td><?php echo $m['es_ingreso'] ? 'Sí' : ''; ?></td>
                    <td><?php echo $m['es_egreso'] ? 'Sí' : ''; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<div class="alert alert-info">Totales mostrados: Ingresos $<?php echo number_format($totales['ingresos'],0,',','.'); ?> | Egresos $<?php echo number_format($totales['egresos'],0,',','.'); ?></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
