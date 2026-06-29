<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../includes/auth.php';
checkAdmin();
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/auditoria_integridad_helpers.php';

$filtros = auditoriaFiltrosDesdeRequest($_GET);
$generadoEn = date('Y-m-d H:i:s');
$datosAuditoria = obtenerDatosAuditoriaIntegridad($pdo, $filtros);
$sociosFiltro = $pdo->query('SELECT id_socio, nombre_completo FROM socios ORDER BY nombre_completo')->fetchAll(PDO::FETCH_ASSOC);
$queryExport = http_build_query(array_filter($filtros, static fn($v) => $v !== '' && $v !== 0));
?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
    <div>
        <p class="text-muted small mb-1">Panel exclusivo para administradores · Solo lectura</p>
        <h1 class="h4 mb-1 d-flex align-items-center gap-2"><i class="bi bi-shield-check text-primary"></i><span>Auditoría de Integridad</span></h1>
        <p class="text-muted mb-0">Recalcula saldos desde movimientos y actividades para detectar inconsistencias sin modificar datos.</p>
    </div>
    <a class="btn btn-success" href="../actions/export_auditoria_integridad_excel.php<?php echo $queryExport ? '?' . clean($queryExport) : ''; ?>"><i class="bi bi-file-earmark-excel"></i> Exportar Excel</a>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-funnel"></i><span>Filtros</span></div>
    <div class="card-body">
        <form class="row g-3 align-items-end" method="get" action="auditoria_integridad.php">
            <div class="col-md-3"><label class="form-label">Fecha inicio</label><input class="form-control" type="date" name="fecha_inicio" value="<?php echo clean($filtros['fecha_inicio']); ?>"></div>
            <div class="col-md-3"><label class="form-label">Fecha fin</label><input class="form-control" type="date" name="fecha_fin" value="<?php echo clean($filtros['fecha_fin']); ?>"></div>
            <div class="col-md-4"><label class="form-label">Socio</label><select class="form-select" name="id_socio"><option value="0">Todos</option><?php foreach ($sociosFiltro as $s): ?><option value="<?php echo (int) $s['id_socio']; ?>" <?php echo (int) $filtros['id_socio'] === (int) $s['id_socio'] ? 'selected' : ''; ?>><?php echo clean($s['nombre_completo']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary w-100" type="submit">Aplicar</button><a class="btn btn-outline-secondary" href="auditoria_integridad.php">Limpiar</a></div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card h-100"><div class="card-body"><div class="text-muted small">Socios con inconsistencia</div><div class="display-6 mb-0 <?php echo $datosAuditoria['inconsistencias_socios'] === 0 ? 'text-success' : 'text-danger'; ?>"><?php echo (int) $datosAuditoria['inconsistencias_socios']; ?></div></div></div></div>
    <div class="col-md-4"><div class="card h-100"><div class="card-body"><div class="text-muted small">Saldo natillera</div><div class="h3 mb-0 <?php echo $datosAuditoria['natillera']['correcto'] ? 'text-success' : 'text-danger'; ?>"><?php echo $datosAuditoria['natillera']['correcto'] ? '✓ OK' : '✗ DIFERENCIA'; ?></div></div></div></div>
    <div class="col-md-4"><div class="card h-100"><div class="card-body"><div class="text-muted small">Fecha y hora del reporte</div><div class="h5 mb-0"><?php echo clean($generadoEn); ?></div></div></div></div>
</div>

<div class="card mb-4"><div class="card-header"><i class="bi bi-people"></i><span>Sección 1 — Saldos por socio</span></div><div class="card-body"><div class="table-responsive"><table class="table table-striped align-middle mb-0"><thead class="table-light"><tr><th>Nombre del socio</th><th class="text-end">Saldo guardado</th><th class="text-end">Saldo recalculado</th><th class="text-end">Diferencia</th><th>Último movimiento</th><th>Estado</th></tr></thead><tbody>
<?php if (empty($datosAuditoria['socios'])): ?><tr><td colspan="6" class="text-center text-muted">No hay socios para los filtros seleccionados.</td></tr><?php else: foreach ($datosAuditoria['socios'] as $socio): $dif = (float) $socio['diferencia']; ?><tr><td><?php echo clean($socio['nombre_completo']); ?></td><td class="text-end"><?php echo auditoriaFormatoDinero($socio['saldo_guardado']); ?></td><td class="text-end"><?php echo auditoriaFormatoDinero($socio['saldo_recalculado']); ?></td><td class="text-end <?php echo auditoriaClaseDiferencia($dif); ?>"><?php echo auditoriaFormatoDinero($dif); ?></td><td><?php echo $socio['ultimo_movimiento'] ? clean($socio['ultimo_movimiento']) : 'Sin movimientos'; ?></td><td class="fw-semibold <?php echo auditoriaClaseDiferencia($dif); ?>"><?php echo abs($dif) <= 0.009 ? '✓ OK' : '✗ DIFERENCIA ' . auditoriaFormatoDinero($dif); ?></td></tr><?php endforeach; endif; ?>
</tbody></table></div></div></div>

<div class="card mb-4"><div class="card-header"><i class="bi bi-bank"></i><span>Sección 2 — Saldo natillera</span></div><div class="card-body"><div class="table-responsive"><table class="table table-striped align-middle mb-0"><thead class="table-light"><tr><th class="text-end">Saldo guardado</th><th class="text-end">Saldo recalculado</th><th class="text-end">Diferencia</th><th>Estado</th></tr></thead><tbody><tr><td class="text-end"><?php echo auditoriaFormatoDinero($datosAuditoria['natillera']['guardado']); ?></td><td class="text-end"><?php echo auditoriaFormatoDinero($datosAuditoria['natillera']['recalculado']); ?></td><td class="text-end <?php echo auditoriaClaseDiferencia((float) $datosAuditoria['natillera']['diferencia']); ?>"><?php echo auditoriaFormatoDinero($datosAuditoria['natillera']['diferencia']); ?></td><td class="fw-semibold <?php echo auditoriaClaseDiferencia((float) $datosAuditoria['natillera']['diferencia']); ?>"><?php echo $datosAuditoria['natillera']['correcto'] ? '✓ OK' : '✗ DIFERENCIA ' . auditoriaFormatoDinero($datosAuditoria['natillera']['diferencia']); ?></td></tr></tbody></table></div></div></div>

<div class="card mb-4"><div class="card-header"><i class="bi bi-list-check"></i><span>Sección 3 — Resumen por actividad</span></div><div class="card-body"><div class="table-responsive"><table class="table table-striped align-middle mb-0"><thead class="table-light"><tr><th>Actividad</th><th>Impacto natillera</th><th class="text-end">Movimientos</th><th class="text-end">Valor total</th><th class="text-end">Impacto neto</th></tr></thead><tbody><?php if (empty($datosAuditoria['actividades'])): ?><tr><td colspan="5" class="text-center text-muted">No hay movimientos por actividad para los filtros seleccionados.</td></tr><?php else: foreach ($datosAuditoria['actividades'] as $a): ?><tr><td><?php echo clean($a['nombre_actividad']); ?></td><td><?php echo clean($a['afecta_saldo_natillera']); ?></td><td class="text-end"><?php echo (int) $a['cantidad_movimientos']; ?></td><td class="text-end"><?php echo auditoriaFormatoDinero($a['valor_total']); ?></td><td class="text-end"><?php echo auditoriaFormatoDinero($a['impacto_neto']); ?></td></tr><?php endforeach; ?><tr class="table-light fw-bold"><td colspan="2">Totales</td><td class="text-end"><?php echo (int) $datosAuditoria['totales_actividad']['cantidad_movimientos']; ?></td><td class="text-end"><?php echo auditoriaFormatoDinero($datosAuditoria['totales_actividad']['valor_total']); ?></td><td class="text-end"><?php echo auditoriaFormatoDinero($datosAuditoria['totales_actividad']['impacto_neto']); ?></td></tr><?php endif; ?></tbody></table></div></div></div>

<div class="card mb-4"><div class="card-header category-egresos"><i class="bi bi-exclamation-octagon"></i><span>Sección 4 — Alertas de integridad</span></div><div class="card-body"><?php if (empty($datosAuditoria['alertas'])): ?><div class="alert alert-success mb-0">Sin alertas ✓</div><?php else: ?><div class="table-responsive"><table class="table table-striped align-middle mb-0"><thead class="table-light"><tr><th>Tipo</th><th>Detalle</th><th class="text-end">Monto</th></tr></thead><tbody><?php foreach ($datosAuditoria['alertas'] as $alerta): ?><tr class="table-danger"><td class="fw-semibold"><?php echo clean($alerta['tipo']); ?></td><td><?php echo clean($alerta['detalle']); ?></td><td class="text-end"><?php echo $alerta['monto'] === null ? '—' : auditoriaFormatoDinero($alerta['monto']); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div></div>

<div class="card"><div class="card-header"><i class="bi bi-exclamation-triangle"></i><span>Sección 5 — Movimientos huérfanos</span></div><div class="card-body"><?php if (empty($datosAuditoria['huerfanos'])): ?><div class="alert alert-success mb-0">Sin movimientos huérfanos ✓</div><?php else: ?><div class="table-responsive"><table class="table table-striped align-middle mb-0"><thead class="table-light"><tr><th>ID</th><th>Fecha</th><th>Socio</th><th>ID actividad faltante</th><th>Motivo</th><th>Módulo</th><th class="text-end">Valor</th></tr></thead><tbody><?php foreach ($datosAuditoria['huerfanos'] as $m): ?><tr><td><?php echo (int) $m['id_movimiento']; ?></td><td><?php echo clean($m['fecha']); ?></td><td><?php echo $m['nombre_completo'] ? clean($m['nombre_completo']) : 'Sin socio'; ?></td><td><?php echo (int) $m['id_actividad']; ?></td><td><?php echo clean($m['motivo']); ?></td><td><?php echo clean($m['modulo']); ?></td><td class="text-end"><?php echo auditoriaFormatoDinero($m['valor']); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
