<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$saldoNatillera = getSaldoNatillera($pdo);
$saldosSocios = $pdo->query("SELECT nombre_completo, saldo_socio FROM socios WHERE activo=1 ORDER BY nombre_completo")->fetchAll();

$condicionAporte = "a.afecta_saldo_socio = 'suma' AND COALESCE(a.es_prestamo,0) = 0 AND COALESCE(a.es_pago_prestamo,0) = 0 AND COALESCE(a.es_pago_interes,0) = 0 AND COALESCE(a.es_polla,0) = 0";
$aportesTotal = (float) $pdo
    ->query(
        "SELECT COALESCE(SUM(ABS(m.valor)),0) FROM movimientos m JOIN actividades_maestro a ON m.id_actividad=a.id_actividad WHERE $condicionAporte"
    )
    ->fetchColumn();
$prestamosEntregados = (float) $pdo->query('SELECT COALESCE(SUM(monto_prestamo),0) FROM prestamos')->fetchColumn();
$capitalPendiente = (float) $pdo->query('SELECT COALESCE(SUM(saldo_capital_actual),0) FROM prestamos')->fetchColumn();
$interesesPendientesPrestamos = (float) $pdo->query('SELECT COALESCE(SUM(saldo_intereses_actual),0) FROM prestamos')->fetchColumn();
$interesesGeneradosTotal = (float) $pdo->query('SELECT COALESCE(SUM(interes_causado),0) FROM periodos_prestamo')->fetchColumn();
$interesesCobradosTotal = (float) $pdo->query('SELECT COALESCE(SUM(interes_pagado),0) FROM periodos_prestamo')->fetchColumn();
$interesesPendientesTotal = max($interesesPendientesPrestamos, $interesesGeneradosTotal - $interesesCobradosTotal);

$nombresMeses = getNombresMeses();
$periodosConfig = $pdo
    ->query('SELECT anio, mes FROM periodos_configuracion WHERE activo = 1 ORDER BY anio DESC, mes DESC')
    ->fetchAll();
$periodosPorAnio = [];
foreach ($periodosConfig as $p) {
    $periodosPorAnio[$p['anio']][] = (int) $p['mes'];
}

$anioPolla = isset($_GET['anio_polla']) ? (int) $_GET['anio_polla'] : null;
$mesPolla = isset($_GET['mes_polla']) ? (int) $_GET['mes_polla'] : null;
$anioDefecto = $periodosConfig[0]['anio'] ?? null;
$mesDefecto = $periodosConfig[0]['mes'] ?? null;
$periodoValido = $anioPolla && $mesPolla && isset($periodosPorAnio[$anioPolla]) && in_array($mesPolla, $periodosPorAnio[$anioPolla], true);
if (!$periodoValido && $anioDefecto && $mesDefecto) {
    $anioPolla = (int) $anioDefecto;
    $mesPolla = (int) $mesDefecto;
}

$sociosSinPagoPolla = [];
if ($anioPolla && $mesPolla) {
    $stmt = $pdo->prepare(
        "SELECT s.id_socio, s.nombre_completo
         FROM socios s
         WHERE s.activo = 1
           AND NOT EXISTS (
                SELECT 1 FROM movimientos m
                JOIN actividades_maestro a ON m.id_actividad = a.id_actividad
                WHERE a.es_polla = 1 AND a.afecta_saldo_natillera = 'suma'
                  AND m.id_socio = s.id_socio
                  AND m.anio = :anio AND m.mes = :mes
           )
         ORDER BY s.nombre_completo"
    );
    $stmt->execute([':anio' => $anioPolla, ':mes' => $mesPolla]);
    $sociosSinPagoPolla = $stmt->fetchAll();
}

$aportesPorSocio = $pdo
    ->query(
        "SELECT s.id_socio, s.nombre_completo, s.saldo_socio,
                COALESCE(SUM(CASE WHEN $condicionAporte THEN ABS(m.valor) ELSE 0 END),0) AS total_aportado,
                COALESCE(COUNT(DISTINCT CASE WHEN $condicionAporte THEN DATE_FORMAT(m.fecha, '%Y-%m') END),0) AS meses_aporte
         FROM socios s
         LEFT JOIN movimientos m ON m.id_socio = s.id_socio
         LEFT JOIN actividades_maestro a ON m.id_actividad = a.id_actividad
         WHERE s.activo = 1
         GROUP BY s.id_socio
         ORDER BY s.nombre_completo"
    )
    ->fetchAll();

$pyg = $pdo->query("SELECT a.nombre_actividad, SUM(CASE WHEN a.afecta_saldo_natillera = 'suma' THEN ABS(m.valor) ELSE 0 END) ingresos, SUM(CASE WHEN a.afecta_saldo_natillera = 'resta' THEN ABS(m.valor) ELSE 0 END) egresos FROM movimientos m JOIN actividades_maestro a ON m.id_actividad=a.id_actividad GROUP BY a.id_actividad")->fetchAll();

$gastos = $pdo->query("SELECT a.nombre_actividad, SUM(m.valor) total FROM movimientos m JOIN actividades_maestro a ON m.id_actividad=a.id_actividad WHERE a.es_gasto_general=1 GROUP BY a.id_actividad")->fetchAll();

$prestamos = $pdo->query(
    "SELECT p.id_prestamo,
            COALESCE(s.nombre_completo, p.nombre_deudor) AS nombre_deudor,
            p.saldo_capital_actual,
            p.saldo_intereses_actual,
            p.tasa_interes,
            p.fecha_prestamo,
            (SELECT MAX(fecha_pago) FROM cuotas_prestamo cp WHERE cp.id_prestamo = p.id_prestamo) AS ultima_fecha_pago
     FROM prestamos p
     LEFT JOIN socios s ON p.id_socio = s.id_socio
     ORDER BY p.id_prestamo DESC"
)->fetchAll();
foreach ($prestamos as &$prestamo) {
    $prestamo['interes_causado_estimado'] = round($prestamo['saldo_capital_actual'] * ($prestamo['tasa_interes'] / 100), 2);
    $prestamo['interes_sugerido'] = max(0, $prestamo['saldo_intereses_actual'] + $prestamo['interes_causado_estimado']);
}
unset($prestamo);

$periodosResumen = $pdo
    ->query(
        "SELECT id_prestamo,
                COALESCE(SUM(interes_causado),0) AS interes_causado,
                COALESCE(SUM(interes_pagado),0) AS interes_pagado,
                SUM(CASE WHEN estado = 'Mora' THEN 1 ELSE 0 END) AS periodos_mora,
                SUM(CASE WHEN estado = 'Pendiente' THEN 1 ELSE 0 END) AS periodos_pendientes
         FROM periodos_prestamo
         GROUP BY id_prestamo"
    )
    ->fetchAll(PDO::FETCH_ASSOC);

$periodosPorPrestamo = [];
foreach ($periodosResumen as $pr) {
    $periodosPorPrestamo[(int) $pr['id_prestamo']] = $pr;
}

foreach ($prestamos as &$prestamo) {
    $resumen = $periodosPorPrestamo[(int) $prestamo['id_prestamo']] ?? [
        'interes_causado' => 0,
        'interes_pagado' => 0,
        'periodos_mora' => 0,
        'periodos_pendientes' => 0,
    ];
    $prestamo['interes_causado_total'] = (float) $resumen['interes_causado'];
    $prestamo['interes_pagado_total'] = (float) $resumen['interes_pagado'];
    $prestamo['periodos_mora'] = (int) $resumen['periodos_mora'];
    $prestamo['periodos_pendientes'] = (int) $resumen['periodos_pendientes'];
}
unset($prestamo);

$prestamosTerceros = $pdo
    ->query("SELECT p.id_prestamo, p.nombre_deudor, p.monto_prestamo, p.saldo_capital_actual, p.saldo_intereses_actual, p.estado, aval.nombre_completo AS nombre_aval FROM prestamos p LEFT JOIN socios aval ON p.id_socio_aval = aval.id_socio WHERE p.es_particular = 1 ORDER BY p.id_prestamo DESC")
    ->fetchAll();

$prestamosParaDetalle = $pdo
    ->query("SELECT p.id_prestamo, COALESCE(s.nombre_completo, p.nombre_deudor) AS deudor FROM prestamos p LEFT JOIN socios s ON p.id_socio = s.id_socio ORDER BY p.id_prestamo DESC")
    ->fetchAll();
$prestamoSeleccionadoId = isset($_GET['prestamo_detalle']) ? (int) $_GET['prestamo_detalle'] : null;
if (!$prestamoSeleccionadoId && !empty($prestamosParaDetalle)) {
    $prestamoSeleccionadoId = (int) $prestamosParaDetalle[0]['id_prestamo'];
}

$prestamoSeleccionado = null;
$cuotasDetalle = [];
$resumenPagosPrestamo = ['capital' => 0, 'interes' => 0];
if ($prestamoSeleccionadoId) {
    $stmtPrestamoSel = $pdo->prepare(
        "SELECT p.*, COALESCE(s.nombre_completo, p.nombre_deudor) AS deudor
         FROM prestamos p
         LEFT JOIN socios s ON p.id_socio = s.id_socio
         WHERE p.id_prestamo = :id"
    );
    $stmtPrestamoSel->execute([':id' => $prestamoSeleccionadoId]);
    $prestamoSeleccionado = $stmtPrestamoSel->fetch();

    $stmtCuotas = $pdo->prepare(
        'SELECT numero_cuota, fecha_programada, fecha_pago, valor_capital_pagado, valor_interes_pagado, saldo_capital_despues, saldo_intereses_despues
         FROM cuotas_prestamo
         WHERE id_prestamo = :id
         ORDER BY numero_cuota'
    );
    $stmtCuotas->execute([':id' => $prestamoSeleccionadoId]);
    $cuotasDetalle = $stmtCuotas->fetchAll();

    $stmtResumenPagos = $pdo->prepare(
        'SELECT COALESCE(SUM(valor_capital_pagado),0) AS capital, COALESCE(SUM(valor_interes_pagado),0) AS interes
         FROM cuotas_prestamo
         WHERE id_prestamo = :id'
    );
    $stmtResumenPagos->execute([':id' => $prestamoSeleccionadoId]);
    $resumenPagosPrestamo = $stmtResumenPagos->fetch(PDO::FETCH_ASSOC) ?: ['capital' => 0, 'interes' => 0];
}

$periodosEnMora = $pdo
    ->query(
        "SELECT pp.id_prestamo, pp.anio, pp.mes, pp.capital_inicio, pp.interes_causado, pp.interes_pagado, pp.estado,
                COALESCE(s.nombre_completo, p.nombre_deudor) AS deudor, p.saldo_intereses_actual
         FROM periodos_prestamo pp
         JOIN prestamos p ON pp.id_prestamo = p.id_prestamo
         LEFT JOIN socios s ON p.id_socio = s.id_socio
         WHERE pp.estado = 'Mora'
         ORDER BY pp.anio DESC, pp.mes DESC"
    )
    ->fetchAll();
?>
<h2 class="mb-3">Reportes</h2>
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted">Total aportes recibidos</div>
                <div class="display-6 fw-bold">$<?php echo number_format($aportesTotal,0,',','.'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted">Préstamos desembolsados</div>
                <div class="display-6 fw-bold">$<?php echo number_format($prestamosEntregados,0,',','.'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted">Capital pendiente</div>
                <div class="display-6 fw-bold">$<?php echo number_format($capitalPendiente,0,',','.'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted">Caja disponible</div>
                <div class="display-6 fw-bold text-success">$<?php echo number_format($saldoNatillera,0,',','.'); ?></div>
            </div>
        </div>
    </div>
</div>
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted">Intereses generados acumulados</div>
                <div class="display-6 fw-bold">$<?php echo number_format($interesesGeneradosTotal,0,',','.'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted">Intereses cobrados</div>
                <div class="display-6 fw-bold text-success">$<?php echo number_format($interesesCobradosTotal,0,',','.'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted">Intereses pendientes por cobrar</div>
                <div class="display-6 fw-bold text-danger">$<?php echo number_format($interesesPendientesTotal,0,',','.'); ?></div>
            </div>
        </div>
    </div>
</div>
<div class="card mb-3">
    <div class="card-header">Detalle de participantes</div>
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2 mb-2">
            <a class="btn btn-outline-secondary" href="../actions/export_csv.php?tipo=saldos">Exportar saldos</a>
            <a class="btn btn-outline-primary" href="../actions/export_csv.php?tipo=aportes_socios">Exportar aportes por socio</a>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead><tr><th>#</th><th>Participante</th><th>Aporte mensual promedio</th><th>Total aportado</th><th>Saldo vigente</th></tr></thead>
                <tbody>
                    <?php foreach($aportesPorSocio as $index => $s): ?>
                        <?php $aporteMensual = ((int)$s['meses_aporte'] > 0) ? ($s['total_aportado'] / (int)$s['meses_aporte']) : 0; ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><?php echo clean($s['nombre_completo']); ?></td>
                            <td>$<?php echo number_format($aporteMensual,0,',','.'); ?></td>
                            <td>$<?php echo number_format($s['total_aportado'],0,',','.'); ?></td>
                            <td>$<?php echo number_format($s['saldo_socio'],0,',','.'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($aportesPorSocio)): ?>
                        <tr><td colspan="5" class="text-center text-muted">Sin participantes activos.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<div class="card mt-3">
    <div class="card-header">Préstamos y saldos</div>
    <div class="card-body">
        <a class="btn btn-outline-secondary mb-2" href="../actions/export_csv.php?tipo=prestamos">Exportar CSV</a>
        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead><tr><th>ID</th><th>Deudor</th><th>Saldo capital</th><th>Saldo intereses</th><th>Interés sugerido</th><th>Cuotas en mora</th><th>Último pago</th></tr></thead>
                <tbody>
                    <?php foreach($prestamos as $p): ?>
                        <tr>
                            <td><?php echo $p['id_prestamo']; ?></td>
                            <td><?php echo clean($p['nombre_deudor']); ?></td>
                            <td>$<?php echo number_format($p['saldo_capital_actual'],0,',','.'); ?></td>
                            <td>$<?php echo number_format($p['saldo_intereses_actual'],0,',','.'); ?></td>
                            <td>$<?php echo number_format($p['interes_sugerido'],0,',','.'); ?></td>
                            <td><?php echo $p['periodos_mora']; ?></td>
                            <td><?php echo $p['ultima_fecha_pago'] ? clean($p['ultima_fecha_pago']) : 'Sin pagos'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<div class="card mt-3">
    <div class="card-header">Flujo de pagos detallado</div>
    <div class="card-body">
        <form class="row g-2 align-items-end mb-3" method="GET">
            <div class="col-md-6">
                <label class="form-label">Préstamo</label>
                <select name="prestamo_detalle" class="form-select">
                    <?php foreach ($prestamosParaDetalle as $p): ?>
                        <option value="<?php echo $p['id_prestamo']; ?>" <?php echo ($prestamoSeleccionadoId == $p['id_prestamo']) ? 'selected' : ''; ?>>
                            #<?php echo $p['id_prestamo']; ?> - <?php echo clean($p['deudor']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary"><i class="bi bi-search"></i> Ver flujo</button>
            </div>
        </form>
        <?php if ($prestamoSeleccionado): ?>
            <div class="alert alert-secondary d-flex flex-wrap gap-3">
                <div><strong>Capital desembolsado:</strong> $<?php echo number_format($prestamoSeleccionado['monto_prestamo'],0,',','.'); ?></div>
                <div><strong>Tasa mensual:</strong> <?php echo number_format($prestamoSeleccionado['tasa_interes'],2,',','.'); ?>%</div>
                <div><strong>Total abonado a capital:</strong> $<?php echo number_format($resumenPagosPrestamo['capital'],0,',','.'); ?></div>
                <div><strong>Intereses pagados:</strong> $<?php echo number_format($resumenPagosPrestamo['interes'],0,',','.'); ?></div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-striped">
                    <thead><tr><th>Cuota</th><th>Fecha programada</th><th>Fecha pago</th><th>Pago capital</th><th>Pago intereses</th><th>Saldo capital</th><th>Saldo intereses</th></tr></thead>
                    <tbody>
                        <?php if (empty($cuotasDetalle)): ?>
                            <tr><td colspan="7" class="text-center text-muted">No hay cuotas registradas para este préstamo.</td></tr>
                        <?php else: ?>
                            <?php foreach ($cuotasDetalle as $c): ?>
                                <tr>
                                    <td><?php echo $c['numero_cuota']; ?></td>
                                    <td><?php echo $c['fecha_programada'] ? clean($c['fecha_programada']) : '-'; ?></td>
                                    <td><?php echo $c['fecha_pago'] ? clean($c['fecha_pago']) : 'Pendiente'; ?></td>
                                    <td>$<?php echo number_format($c['valor_capital_pagado'],0,',','.'); ?></td>
                                    <td>$<?php echo number_format($c['valor_interes_pagado'],0,',','.'); ?></td>
                                    <td>$<?php echo number_format($c['saldo_capital_despues'],0,',','.'); ?></td>
                                    <td>$<?php echo number_format($c['saldo_intereses_despues'],0,',','.'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-warning mb-0">No hay préstamos para mostrar detalle.</div>
        <?php endif; ?>
    </div>
</div>
<div class="card mt-3">
    <div class="card-header">Control de morosidad</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead><tr><th>Préstamo</th><th>Período</th><th>Días en mora (aprox.)</th><th>Capital base</th><th>Interés causado</th><th>Interés pagado</th><th>Estado</th></tr></thead>
                <tbody>
                    <?php if (empty($periodosEnMora)): ?>
                        <tr><td colspan="7" class="text-center text-muted">Sin períodos en mora.</td></tr>
                    <?php else: ?>
                        <?php foreach ($periodosEnMora as $mora): ?>
                            <?php
                                $fechaPeriodo = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', (int)$mora['anio'], (int)$mora['mes']));
                                $diasMora = $fechaPeriodo ? $fechaPeriodo->diff(new DateTime())->days : 0;
                            ?>
                            <tr>
                                <td>#<?php echo $mora['id_prestamo']; ?> - <?php echo clean($mora['deudor']); ?></td>
                                <td><?php echo $nombresMeses[(int)$mora['mes']] . ' ' . $mora['anio']; ?></td>
                                <td><?php echo $diasMora; ?> días</td>
                                <td>$<?php echo number_format($mora['capital_inicio'],0,',','.'); ?></td>
                                <td>$<?php echo number_format($mora['interes_causado'],0,',','.'); ?></td>
                                <td>$<?php echo number_format($mora['interes_pagado'],0,',','.'); ?></td>
                                <td><span class="badge text-bg-danger">Mora</span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<div class="card mt-3">
    <div class="card-header">Socios que no han pagado polla</div>
    <div class="card-body">
        <?php if (empty($periodosPorAnio)): ?>
            <div class="alert alert-warning mb-0">Configure periodos activos en el módulo de configuración para habilitar el filtro por mes y año.</div>
        <?php else: ?>
            <form class="row g-2 align-items-end mb-3" method="GET">
                <div class="col-md-3">
                    <label class="form-label">Año</label>
                    <select name="anio_polla" class="form-select">
                        <?php foreach ($periodosPorAnio as $anio => $meses): ?>
                            <option value="<?php echo $anio; ?>" <?php echo ($anio == $anioPolla) ? 'selected' : ''; ?>><?php echo $anio; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Mes</label>
                    <select name="mes_polla" class="form-select">
                        <?php foreach ($periodosPorAnio[$anioPolla] as $mes): ?>
                            <option value="<?php echo $mes; ?>" <?php echo ($mes == $mesPolla) ? 'selected' : ''; ?>><?php echo $nombresMeses[$mes]; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary"><i class="bi bi-funnel"></i> Filtrar</button>
                </div>
            </form>
            <div class="table-responsive">
                <table class="table table-sm table-striped">
                    <thead><tr><th>Socio</th><th>Estado</th></tr></thead>
                    <tbody>
                        <?php if (empty($sociosSinPagoPolla)): ?>
                            <tr><td colspan="2" class="text-center text-success">Todos los socios tienen pago registrado para el periodo seleccionado.</td></tr>
                        <?php else: ?>
                            <?php foreach ($sociosSinPagoPolla as $s): ?>
                                <tr>
                                    <td><?php echo clean($s['nombre_completo']); ?></td>
                                    <td class="text-danger fw-semibold">No ha pagado</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<div class="card mt-3">
    <div class="card-header">PYG por actividad</div>
    <div class="card-body">
        <a class="btn btn-outline-secondary mb-2" href="../actions/export_csv.php?tipo=pyg">Exportar CSV</a>
        <div class="table-responsive">
            <table class="table table-bordered table-sm">
                <thead><tr><th>Actividad</th><th>Ingresos</th><th>Egresos</th><th>Neto</th></tr></thead>
                <tbody>
                    <?php foreach($pyg as $r): ?>
                        <tr>
                            <td><?php echo clean($r['nombre_actividad']); ?></td>
                            <td>$<?php echo number_format($r['ingresos'],0,',','.'); ?></td>
                            <td>$<?php echo number_format($r['egresos'],0,',','.'); ?></td>
                            <td>$<?php echo number_format($r['ingresos'] - $r['egresos'],0,',','.'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<div class="card mt-3">
    <div class="card-header">Gastos de la natillera</div>
    <div class="card-body">
        <a class="btn btn-outline-secondary mb-2" href="../actions/export_csv.php?tipo=gastos">Exportar CSV</a>
        <div class="table-responsive">
            <table class="table table-striped table-sm">
                <thead><tr><th>Actividad</th><th>Total</th></tr></thead>
                <tbody>
                    <?php foreach($gastos as $g): ?>
                        <tr><td><?php echo clean($g['nombre_actividad']); ?></td><td>$<?php echo number_format($g['total'],0,',','.'); ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<div class="card mt-3">
    <div class="card-header">Préstamos a terceros</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle">
                <thead><tr><th>ID</th><th>Deudor</th><th>Aval</th><th>Monto desembolsado</th><th>Saldo capital</th><th>Saldo intereses</th><th>Estado</th></tr></thead>
                <tbody>
                    <?php if (empty($prestamosTerceros)): ?>
                        <tr><td colspan="7" class="text-center text-muted">No hay préstamos a terceros registrados.</td></tr>
                    <?php else: ?>
                        <?php foreach ($prestamosTerceros as $p): ?>
                            <tr>
                                <td><?php echo $p['id_prestamo']; ?></td>
                                <td><?php echo clean($p['nombre_deudor']); ?></td>
                                <td><?php echo $p['nombre_aval'] ? clean($p['nombre_aval']) : 'Sin aval'; ?></td>
                                <td>$<?php echo number_format($p['monto_prestamo'],0,',','.'); ?></td>
                                <td>$<?php echo number_format($p['saldo_capital_actual'],0,',','.'); ?></td>
                                <td>$<?php echo number_format($p['saldo_intereses_actual'],0,',','.'); ?></td>
                                <td><?php echo clean($p['estado']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
