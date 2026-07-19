<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/libro_diario_helpers.php';
checkAdmin();

$filtros = [
    'desde' => $_GET['desde'] ?? '', 'hasta' => $_GET['hasta'] ?? '', 'socio' => $_GET['socio'] ?? '',
    'actividad' => $_GET['actividad'] ?? '', 'medio' => $_GET['medio'] ?? '', 'prestamo' => $_GET['prestamo'] ?? '',
    'neutrales' => isset($_GET['neutrales']) ? '1' : '',
];
$idSocio = isset($_GET['socio_detalle']) ? (int) $_GET['socio_detalle'] : null;
$socioDetalle = null;
if ($idSocio) {
    $stmtSocio = $pdo->prepare('SELECT id_socio, nombre_completo, saldo_socio FROM socios WHERE id_socio = :id');
    $stmtSocio->execute([':id' => $idSocio]);
    $socioDetalle = $stmtSocio->fetch();
    if (!$socioDetalle) { $idSocio = null; }
}
$socios = getSocios($pdo, '');
$actividades = getActividades($pdo, false, true);
$medios = getMediosPago($pdo, true);
$prestamos = $pdo->query("SELECT p.id_prestamo, COALESCE(s.nombre_completo, p.nombre_deudor, CONCAT('Préstamo ', p.id_prestamo)) AS deudor FROM prestamos p LEFT JOIN socios s ON s.id_socio = p.id_socio ORDER BY p.id_prestamo DESC")->fetchAll();
$rows = libroDiarioObtenerMovimientos($pdo, $filtros, $idSocio ?: null);
$totales = libroDiarioAplicarSaldo($rows, (bool) $idSocio);
$rowsValidacion = libroDiarioObtenerMovimientos($pdo, ['neutrales' => '1'], $idSocio ?: null, true);
$totalesValidacion = libroDiarioAplicarSaldo($rowsValidacion, (bool) $idSocio);
$saldoGuardado = $idSocio ? (float) ($socioDetalle['saldo_socio'] ?? 0) : getSaldoNatillera($pdo);
$diferencia = $totalesValidacion['saldo_final'] - $saldoGuardado;
$exportParams = libroDiarioQueryString(['socio_detalle' => $idSocio ?: null]);
?>
<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <p class="text-muted small mb-1">Reportes contables de solo lectura</p>
        <h1 class="h4 mb-0"><?php echo $idSocio ? 'Auxiliar individual: ' . clean($socioDetalle['nombre_completo']) : 'Libro diario / Auxiliar de movimientos'; ?></h1>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-success" href="../actions/export_libro_diario_excel.php?<?php echo clean($exportParams); ?>"><i class="bi bi-file-earmark-spreadsheet"></i> Excel</a>
        <a class="btn btn-outline-danger" href="../actions/export_libro_diario_pdf.php?<?php echo clean($exportParams); ?>"><i class="bi bi-filetype-pdf"></i> PDF</a>
    </div>
</div>
<?php if (abs($diferencia) >= 0.01): ?>
    <div class="alert alert-danger border-danger">
        <strong>Alerta de saldo:</strong> el saldo reconstruido sin filtros (<?php echo libroDiarioMoney($totalesValidacion['saldo_final']); ?>) no coincide con el saldo guardado (<?php echo libroDiarioMoney($saldoGuardado); ?>). Diferencia: <?php echo libroDiarioMoney($diferencia); ?>.
    </div>
<?php endif; ?>
<div class="card mb-3"><div class="card-body">
    <form class="row g-2" method="GET">
        <?php if ($idSocio): ?><input type="hidden" name="socio_detalle" value="<?php echo $idSocio; ?>"><?php endif; ?>
        <div class="col-md-2"><label class="form-label">Desde</label><input type="date" name="desde" class="form-control" value="<?php echo clean($filtros['desde']); ?>"></div>
        <div class="col-md-2"><label class="form-label">Hasta</label><input type="date" name="hasta" class="form-control" value="<?php echo clean($filtros['hasta']); ?>"></div>
        <?php if (!$idSocio): ?>
        <div class="col-md-3"><label class="form-label">Socio</label><select name="socio" class="form-select"><option value="">Todos</option><?php foreach ($socios as $s): ?><option value="<?php echo $s['id_socio']; ?>" <?php echo (string)$filtros['socio']===(string)$s['id_socio']?'selected':''; ?>><?php echo clean($s['nombre_completo']); ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <div class="col-md-3"><label class="form-label">Actividad</label><select name="actividad" class="form-select"><option value="">Todas</option><?php foreach ($actividades as $a): ?><option value="<?php echo $a['id_actividad']; ?>" <?php echo (string)$filtros['actividad']===(string)$a['id_actividad']?'selected':''; ?>><?php echo clean($a['nombre_actividad']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Medio</label><select name="medio" class="form-select"><option value="">Todos</option><?php foreach ($medios as $m): ?><option value="<?php echo $m['id']; ?>" <?php echo (string)$filtros['medio']===(string)$m['id']?'selected':''; ?>><?php echo clean($m['nombre']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">Préstamo</label><select name="prestamo" class="form-select"><option value="">Todos</option><?php foreach ($prestamos as $p): ?><option value="<?php echo $p['id_prestamo']; ?>" <?php echo (string)$filtros['prestamo']===(string)$p['id_prestamo']?'selected':''; ?>>#<?php echo $p['id_prestamo']; ?> - <?php echo clean($p['deudor']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3 align-self-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="neutrales" value="1" id="neutrales" <?php echo $filtros['neutrales']?'checked':''; ?>><label class="form-check-label" for="neutrales">Mostrar movimientos neutrales</label></div></div>
        <div class="col-md-3 align-self-end d-flex gap-2"><button class="btn btn-primary">Filtrar</button><a class="btn btn-outline-secondary" href="libro_diario.php<?php echo $idSocio ? '?socio_detalle='.$idSocio : ''; ?>">Limpiar</a></div>
        <?php if (!$idSocio): ?><div class="col-md-3 align-self-end"><select class="form-select" onchange="if(this.value) location.href='libro_diario.php?socio_detalle='+this.value"><option value="">Abrir auxiliar de socio...</option><?php foreach ($socios as $s): ?><option value="<?php echo $s['id_socio']; ?>"><?php echo clean($s['nombre_completo']); ?></option><?php endforeach; ?></select></div><?php endif; ?>
    </form>
</div></div>
<div class="table-responsive"><table class="table table-sm table-striped table-hover align-middle"><thead class="table-light"><tr><th>Fecha</th><?php if (!$idSocio): ?><th>Socio / tercero</th><?php endif; ?><th>Concepto / actividad</th><th>Préstamo</th><th>Medio</th><th class="text-end">Ingreso</th><th class="text-end">Egreso</th><th class="text-end">Neutral</th><th class="text-end">Saldo acumulado</th><th>Liquidación</th><th>Usuario</th><th>Observaciones</th></tr></thead><tbody>
<?php foreach ($rows as $r): $alerta = !empty($r['alertas']); ?>
<tr class="<?php echo $alerta ? 'table-danger' : ''; ?>"><td><?php echo clean($r['fecha']); ?></td><?php if (!$idSocio): ?><td><?php echo $r['id_socio'] ? clean($r['nombre_completo']) : '<span class="badge bg-danger">SIN SOCIO</span>'; ?></td><?php endif; ?><td><?php echo clean($r['nombre_actividad']); ?><?php echo !empty($r['es_aval']) ? ' <span class="badge bg-warning text-dark">(aval)</span>' : ''; ?><?php if ($alerta): ?><div class="small text-danger"><?php echo clean(implode(' · ', $r['alertas'])); ?></div><?php endif; ?></td><td><?php echo $r['id_prestamo'] ? '#'.(int)$r['id_prestamo'] : ''; ?></td><td><?php echo clean($r['medio_pago_nombre'] ?: $r['medio_consignacion']); ?></td><td class="text-end text-success"><?php echo ((int)$r['es_ingreso']===1 && empty($r['es_aval'])) ? libroDiarioMoney($r['valor_abs']) : ''; ?></td><td class="text-end text-danger"><?php echo ((int)$r['es_egreso']===1 && empty($r['es_aval'])) ? libroDiarioMoney($r['valor_abs']) : ''; ?></td><td class="text-end text-muted"><?php echo ((int)$r['es_ingreso']!==1 && (int)$r['es_egreso']!==1) ? libroDiarioMoney($r['valor_abs']) : ''; ?></td><td class="text-end fw-semibold"><?php echo libroDiarioMoney($r['saldo_acumulado']); ?></td><td><?php echo $r['id_liquidacion'] ? '#'.(int)$r['id_liquidacion'] : ''; ?></td><td><?php echo clean($r['usuario_registro']); ?></td><td class="small"><?php echo clean($r['observaciones']); ?></td></tr>
<?php endforeach; ?>
</tbody><tfoot class="table-light"><tr><th colspan="<?php echo $idSocio ? 4 : 5; ?>">Totales del período filtrado</th><th class="text-end text-success"><?php echo libroDiarioMoney($totales['ingresos']); ?></th><th class="text-end text-danger"><?php echo libroDiarioMoney($totales['egresos']); ?></th><th class="text-end text-muted"><?php echo libroDiarioMoney($totales['neutral']); ?></th><th class="text-end"><?php echo libroDiarioMoney($totales['saldo_final']); ?></th><th colspan="3"></th></tr></tfoot></table></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
