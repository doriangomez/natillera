<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

asegurarEsquemaLiquidaciones($pdo);
$nombresMeses = getNombresMeses();
$filtroSocio = isset($_GET['socio']) ? (int) $_GET['socio'] : 0;
$filtroEstado = trim((string) ($_GET['estado'] ?? 'activa'));
if (!in_array($filtroEstado, ['activa', 'reversada', 'editada', 'todas'], true)) {
    $filtroEstado = 'activa';
}

$params = [];
$whereLiquidaciones = [];
if ($filtroSocio > 0) {
    $whereLiquidaciones[] = 'l.socio_id = :socio';
    $params[':socio'] = $filtroSocio;
}
if ($filtroEstado !== 'todas') {
    $whereLiquidaciones[] = 'l.estado = :estado';
    $params[':estado'] = $filtroEstado;
}
$whereSql = $whereLiquidaciones ? 'WHERE ' . implode(' AND ', $whereLiquidaciones) : '';

$stmtSociosLiquidados = $pdo->prepare(
    "SELECT l.id, l.socio_id, l.fecha, l.tipo_liquidacion, l.estado, s.nombre_completo
     FROM liquidaciones l
     JOIN socios s ON s.id_socio = l.socio_id
     $whereSql
     ORDER BY s.nombre_completo, l.fecha DESC, l.id DESC"
);
$stmtSociosLiquidados->execute($params);
$liquidaciones = $stmtSociosLiquidados->fetchAll(PDO::FETCH_ASSOC);

$sociosFiltro = $pdo
    ->query('SELECT DISTINCT s.id_socio, s.nombre_completo FROM socios s JOIN liquidaciones l ON l.socio_id = s.id_socio ORDER BY s.nombre_completo')
    ->fetchAll(PDO::FETCH_ASSOC);

$conceptos = [];
$periodos = [];
$datos = [];
$resumenLiquidaciones = [];
$idsSocios = [];
foreach ($liquidaciones as $liq) {
    $idSocio = (int) $liq['socio_id'];
    $idsSocios[$idSocio] = $idSocio;
    if (!isset($resumenLiquidaciones[$idSocio])) {
        $resumenLiquidaciones[$idSocio] = [
            'nombre' => $liq['nombre_completo'],
            'liquidaciones' => [],
        ];
    }
    $resumenLiquidaciones[$idSocio]['liquidaciones'][] = '#' . (int) $liq['id'] . ' ' . $liq['fecha'] . ' (' . $liq['estado'] . ')';
}

if (!empty($idsSocios)) {
    $placeholders = implode(',', array_fill(0, count($idsSocios), '?'));
    $stmtMovimientos = $pdo->prepare(
        "SELECT m.id_socio, m.anio, m.mes, a.nombre_actividad,
                SUM(CASE
                    WHEN a.afecta_saldo_natillera = 'resta' OR a.afecta_saldo_socio = 'resta' THEN -ABS(m.valor)
                    ELSE ABS(m.valor)
                END) AS total
         FROM movimientos m
         JOIN actividades_maestro a ON a.id_actividad = m.id_actividad
         WHERE m.id_socio IN ($placeholders)
         GROUP BY m.id_socio, m.anio, m.mes, a.id_actividad, a.nombre_actividad
         ORDER BY m.anio, m.mes, a.nombre_actividad"
    );
    $stmtMovimientos->execute(array_values($idsSocios));
    foreach ($stmtMovimientos->fetchAll(PDO::FETCH_ASSOC) as $mov) {
        $idSocio = (int) $mov['id_socio'];
        $periodo = sprintf('%04d-%02d', (int) $mov['anio'], (int) $mov['mes']);
        $concepto = (string) $mov['nombre_actividad'];
        $conceptos[$concepto] = $concepto;
        $periodos[$periodo] = $periodo;
        $datos[$idSocio][$periodo][$concepto] = (float) $mov['total'];
    }
}
ksort($periodos);
ksort($conceptos, SORT_NATURAL | SORT_FLAG_CASE);
?>
<h2 class="mb-3">Reporte de socios liquidados por conceptos</h2>
<p class="text-muted">Muestra los socios con liquidaciones registradas y sus movimientos agrupados mes a mes por cada concepto.</p>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="socio">Socio liquidado</label>
                <select class="form-select" name="socio" id="socio">
                    <option value="0">Todos</option>
                    <?php foreach ($sociosFiltro as $socio): ?>
                        <option value="<?php echo (int) $socio['id_socio']; ?>" <?php echo $filtroSocio === (int) $socio['id_socio'] ? 'selected' : ''; ?>><?php echo clean($socio['nombre_completo']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="estado">Estado liquidación</label>
                <select class="form-select" name="estado" id="estado">
                    <?php foreach (['activa' => 'Activa', 'reversada' => 'Reversada', 'editada' => 'Editada', 'todas' => 'Todas'] as $valor => $label): ?>
                        <option value="<?php echo $valor; ?>" <?php echo $filtroEstado === $valor ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5 d-flex gap-2">
                <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> Consultar</button>
                <a class="btn btn-outline-secondary" href="../actions/export_csv.php?tipo=liquidaciones_conceptos&amp;socio=<?php echo $filtroSocio; ?>&amp;estado=<?php echo urlencode($filtroEstado); ?>"><i class="bi bi-filetype-csv"></i> Exportar CSV</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle">
                <thead class="table-light">
                <tr>
                    <th>Socio</th><th>Liquidaciones</th><th>Mes</th>
                    <?php foreach ($conceptos as $concepto): ?><th class="text-end"><?php echo clean($concepto); ?></th><?php endforeach; ?>
                    <th class="text-end">Total mes</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($resumenLiquidaciones)): ?>
                    <tr><td colspan="<?php echo 4 + count($conceptos); ?>" class="text-center text-muted">No hay socios liquidados para los filtros seleccionados.</td></tr>
                <?php else: ?>
                    <?php foreach ($resumenLiquidaciones as $idSocio => $resumen): ?>
                        <?php foreach ($periodos as $periodo): ?>
                            <?php $totalMes = 0; ?>
                            <tr>
                                <td><?php echo clean($resumen['nombre']); ?></td>
                                <td class="small"><?php echo clean(implode(', ', $resumen['liquidaciones'])); ?></td>
                                <td><?php [$anio, $mes] = array_map('intval', explode('-', $periodo)); echo clean(($nombresMeses[$mes] ?? $mes) . ' ' . $anio); ?></td>
                                <?php foreach ($conceptos as $concepto): ?>
                                    <?php $valor = (float) ($datos[$idSocio][$periodo][$concepto] ?? 0); $totalMes += $valor; ?>
                                    <td class="text-end"><?php echo $valor == 0.0 ? '-' : '$' . number_format($valor, 0, ',', '.'); ?></td>
                                <?php endforeach; ?>
                                <td class="text-end fw-semibold">$<?php echo number_format($totalMes, 0, ',', '.'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
