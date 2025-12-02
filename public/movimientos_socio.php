<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$socios = getSocios($pdo);
$actividades = getActividades($pdo, false, true);
$medios = getMediosPago($pdo);

$fSocio = isset($_GET['socio']) ? (int) $_GET['socio'] : 0;
$fDesde = $_GET['desde'] ?? '';
$fHasta = $_GET['hasta'] ?? '';
$fActividad = isset($_GET['actividad']) ? (int) $_GET['actividad'] : 0;

$where = [];
$params = [];
if ($fSocio) { $where[] = 'm.id_socio = :s'; $params[':s'] = $fSocio; }
if ($fDesde) { $where[] = 'm.fecha >= :d'; $params[':d'] = $fDesde; }
if ($fHasta) { $where[] = 'm.fecha <= :h'; $params[':h'] = $fHasta; }
if ($fActividad) { $where[] = 'm.id_actividad = :a'; $params[':a'] = $fActividad; }

$sql = "SELECT m.*, s.nombre_completo, a.nombre_actividad, mp.nombre AS medio,
               a.afecta_saldo_socio, a.afecta_saldo_natillera, a.es_polla
        FROM movimientos m
        LEFT JOIN socios s ON m.id_socio = s.id_socio
        LEFT JOIN actividades_maestro a ON m.id_actividad = a.id_actividad
        LEFT JOIN medios_pago mp ON m.id_medio_pago = mp.id";
if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
$sql .= ' ORDER BY m.fecha DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$movs = $stmt->fetchAll();

$totales = ['ingresos'=>0,'egresos'=>0];
foreach ($movs as &$m) {
    $reglaSocio = normalizarReglaAfectacion($m['afecta_saldo_socio'] ?? 'neutral');
    $afectaSocio = $m['es_polla'] ? 'neutral' : $reglaSocio;
    $valorSocio = 0;
    if ($afectaSocio === 'suma') {
        $valorSocio = $m['valor'];
    } elseif ($afectaSocio === 'resta') {
        $valorSocio = -$m['valor'];
    }
    $m['valor_socio'] = $valorSocio;
    if ($valorSocio > 0) { $totales['ingresos'] += $valorSocio; }
    if ($valorSocio < 0) { $totales['egresos'] += $valorSocio; }
}
unset($m);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <p class="text-muted small mb-1">Consulta detallada</p>
        <h1 class="h4 mb-0">Movimientos por socio</h1>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="../actions/export_csv.php?tipo=movimientos&socio=<?php echo $fSocio; ?>&desde=<?php echo $fDesde; ?>&hasta=<?php echo $fHasta; ?>">Exportar filtrado</a>
        <button id="btnExportarMovimientos" class="btn btn-outline-danger"
            data-socio="<?php echo $fSocio; ?>"
            data-desde="<?php echo $fDesde; ?>"
            data-hasta="<?php echo $fHasta; ?>"
            data-actividad="<?php echo $fActividad; ?>">
            Exportar movimientos PDF
        </button>
    </div>
</div>
<div class="card mb-3">
    <div class="card-body">
        <form class="row g-2" method="GET">
            <div class="col-md-3">
                <label class="form-label">Socio</label>
                <select name="socio" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach($socios as $s): ?>
                        <option value="<?php echo $s['id_socio']; ?>" <?php echo $fSocio==$s['id_socio']?'selected':''; ?>><?php echo clean($s['nombre_completo']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Actividad</label>
                <select name="actividad" class="form-select">
                    <option value="">Todas</option>
                    <?php foreach($actividades as $a): ?>
                        <option value="<?php echo $a['id_actividad']; ?>" <?php echo $fActividad==$a['id_actividad']?'selected':''; ?>><?php echo clean($a['nombre_actividad']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Desde</label>
                <input type="date" name="desde" class="form-control" value="<?php echo $fDesde; ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Hasta</label>
                <input type="date" name="hasta" class="form-control" value="<?php echo $fHasta; ?>">
            </div>
            <div class="col-md-2 align-self-end">
                <button class="btn btn-primary">Filtrar</button>
            </div>
        </form>
    </div>
</div>
<div class="table-responsive">
    <table class="table table-sm table-striped">
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Socio</th>
                <th>Actividad</th>
                <th>Medio</th>
                <th>Valor</th>
                <th>Ingreso</th>
                <th>Egreso</th>
                <th>Afectación saldo socio</th>
                <th>Observaciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($movs as $m): ?>
                <?php
                    $afectaSocio = $m['es_polla'] ? 'neutral' : normalizarReglaAfectacion($m['afecta_saldo_socio'] ?? 'neutral');
                    $etiquetaAfectacion = 'No afecta';
                    $claseAfectacion = 'bg-secondary-subtle text-secondary';
                    if ($afectaSocio === 'suma') { $etiquetaAfectacion = 'Suma'; $claseAfectacion = 'bg-success-subtle text-success'; }
                    if ($afectaSocio === 'resta') { $etiquetaAfectacion = 'Resta'; $claseAfectacion = 'bg-danger-subtle text-danger'; }
                ?>
                <tr>
                    <td><?php echo $m['fecha']; ?></td>
                    <td><?php echo clean($m['nombre_completo'] ?? 'General'); ?></td>
                    <td><?php echo clean($m['nombre_actividad']); ?></td>
                    <td><?php echo clean($m['medio'] ?: $m['medio_consignacion']); ?></td>
                    <td>$<?php echo number_format($m['valor'],0,',','.'); ?></td>
                    <td><?php echo $m['es_ingreso'] ? 'Sí' : ''; ?></td>
                    <td><?php echo $m['es_egreso'] ? 'Sí' : ''; ?></td>
                    <td>
                        <div><span class="badge <?php echo $claseAfectacion; ?>"><?php echo $etiquetaAfectacion; ?></span></div>
                        <div class="small text-muted">$<?php echo number_format($m['valor_socio'],0,',','.'); ?></div>
                    </td>
                    <td class="small text-muted"><?php echo clean($m['observaciones']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<div class="alert alert-info">Ingresos socio: $<?php echo number_format($totales['ingresos'],0,',','.'); ?> | Egresos socio: $<?php echo number_format(abs($totales['egresos']),0,',','.'); ?> | Saldo neto socio: $<?php echo number_format($totales['ingresos']+$totales['egresos'],0,',','.'); ?></div>
<script>
const btnExportar = document.getElementById('btnExportarMovimientos');
if (btnExportar) {
    btnExportar.addEventListener('click', () => {
        const decision = (prompt('¿Exportar movimientos individual o colectivo?', 'individual') || '').trim().toLowerCase();
        if (decision !== 'individual' && decision !== 'colectivo') {
            alert('Debes indicar "individual" o "colectivo".');
            return;
        }

        const params = new URLSearchParams({
            socio: btnExportar.dataset.socio || '',
            desde: btnExportar.dataset.desde || '',
            hasta: btnExportar.dataset.hasta || '',
            actividad: btnExportar.dataset.actividad || ''
        });

        if (decision === 'individual') {
            if (!btnExportar.dataset.socio) {
                alert('Selecciona un socio en el filtro para exportar un PDF individual.');
                return;
            }
            params.set('modo', 'individual');
        } else {
            params.set('modo', 'colectivo');
            const ruta = (prompt('Ruta de extracción/carpeta para guardar los archivos separados', 'exportes_movimientos') || 'exportes_movimientos').trim();
            if (ruta) {
                params.set('ruta', ruta);
            }
        }

        window.location.href = '../actions/export_movimiento_socio_pdf.php?' + params.toString();
    });
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
