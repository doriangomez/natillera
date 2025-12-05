<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$socios = getSocios($pdo);
$actividadesPolla = getActividades($pdo, true);

$nombresMeses = getNombresMeses();
$periodosActivos = $pdo
    ->query('SELECT anio, mes FROM periodos_configuracion WHERE activo = 1 ORDER BY anio ASC, mes ASC')
    ->fetchAll();
$periodosPorAnio = [];
foreach ($periodosActivos as $p) {
    $periodosPorAnio[$p['anio']][] = (int) $p['mes'];
}
$periodoPorDefecto = $periodosActivos[0] ?? null;
$anioResultadoDefault = $periodoPorDefecto['anio'] ?? (int) date('Y');
$mesResultadoDefault = $periodoPorDefecto['mes'] ?? (int) date('n');
$mesResultadoTextoDefault = sprintf('%04d-%02d', $anioResultadoDefault, $mesResultadoDefault);
$periodosColumnas = array_map(function ($p) use ($nombresMeses) {
    return [
        'anio' => (int) $p['anio'],
        'mes' => (int) $p['mes'],
        'label' => $nombresMeses[(int) $p['mes']] . ' ' . (int) $p['anio'],
    ];
}, $periodosActivos);

$pagosPolla = $pdo->query(
    "SELECT m.id_socio, m.anio, m.mes, SUM(m.valor) AS valor_pagado
     FROM movimientos m
     JOIN actividades_maestro a ON m.id_actividad=a.id_actividad
     WHERE a.es_polla=1 AND m.es_ingreso=1 AND m.anio IS NOT NULL AND m.mes IS NOT NULL
     GROUP BY m.id_socio, m.anio, m.mes"
)->fetchAll();
$pagosIndex = [];
foreach ($pagosPolla as $pago) {
    $pagosIndex[(int) $pago['id_socio']][(int) $pago['anio']][(int) $pago['mes']] = (float) $pago['valor_pagado'];
}

asegurarTablaResultadosPolla($pdo);
$resultadosPolla = obtenerResultadosPolla($pdo);
$resultadosIndex = indexResultadosPollaPorMes($pdo);

$ingresos = $pdo->query("SELECT SUM(valor) as total FROM movimientos m JOIN actividades_maestro a ON m.id_actividad=a.id_actividad WHERE a.es_polla=1 AND m.es_ingreso=1")->fetch()['total'] ?? 0;
$egresos = $pdo->query("SELECT SUM(valor) as total FROM movimientos m JOIN actividades_maestro a ON m.id_actividad=a.id_actividad WHERE a.es_polla=1 AND m.es_egreso=1")->fetch()['total'] ?? 0;
$porSocio = $pdo->query("SELECT s.nombre_completo, SUM(CASE WHEN m.es_ingreso=1 THEN m.valor ELSE 0 END) ingresos, SUM(CASE WHEN m.es_egreso=1 THEN m.valor ELSE 0 END) egresos FROM movimientos m JOIN socios s ON m.id_socio=s.id_socio JOIN actividades_maestro a ON m.id_actividad=a.id_actividad WHERE a.es_polla=1 GROUP BY s.id_socio")->fetchAll();
$porMes = $pdo->query("SELECT DATE_FORMAT(m.fecha, '%Y-%m') mes, SUM(CASE WHEN m.es_ingreso=1 THEN m.valor ELSE 0 END) ingresos, SUM(CASE WHEN m.es_egreso=1 THEN m.valor ELSE 0 END) egresos FROM movimientos m JOIN actividades_maestro a ON m.id_actividad=a.id_actividad WHERE a.es_polla=1 GROUP BY DATE_FORMAT(m.fecha, '%Y-%m') ORDER BY mes DESC")->fetchAll();
foreach ($porMes as &$detalleMes) {
    $detalleMes['numero_ganador'] = $resultadosIndex[$detalleMes['mes']]['numero_ganador'] ?? '';
    $detalleMes['id_resultado'] = $resultadosIndex[$detalleMes['mes']]['id_resultado'] ?? null;
}
unset($detalleMes);
?>
<h2 class="mb-3 d-flex align-items-center gap-2"><i class="bi bi-trophy-fill text-primary"></i><span>Gestión de pollas</span></h2>
<div class="alert alert-info d-flex align-items-center gap-2"><i class="bi bi-info-circle-fill"></i><span>El registro de pagos y premios de pollas se realiza ahora exclusivamente desde el módulo de Movimientos. Esta vista permanece como panel informativo.</span></div>
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <p class="text-muted mb-1">Resultados de polla</p>
                <h4 class="mb-0">Número ganador por mes</h4>
            </div>
            <span class="badge bg-secondary-subtle text-secondary">Historial editable</span>
        </div>
        <form class="row g-3" method="POST" action="../actions/polla_resultados_save.php">
            <input type="hidden" name="accion" value="crear">
            <input type="hidden" name="mes_resultado" id="mesResultadoInput" value="<?php echo $mesResultadoTextoDefault; ?>">
            <div class="col-md-3">
                <label class="form-label">Año</label>
                <select name="anio" class="form-select" <?php echo empty($periodosPorAnio) ? 'disabled' : ''; ?> required>
                    <?php if (!empty($periodosPorAnio)): ?>
                        <?php foreach ($periodosPorAnio as $anio => $meses): ?>
                            <option value="<?php echo $anio; ?>" <?php echo ((int) $anio === (int) $anioResultadoDefault) ? 'selected' : ''; ?>><?php echo $anio; ?></option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="<?php echo $anioResultadoDefault; ?>" selected><?php echo $anioResultadoDefault; ?></option>
                    <?php endif; ?>
                </select>
                <?php if (empty($periodosPorAnio)): ?>
                    <div class="form-text text-warning">Configura periodos activos para habilitar el registro.</div>
                <?php endif; ?>
            </div>
            <div class="col-md-3">
                <label class="form-label">Mes</label>
                <select name="mes" class="form-select" <?php echo empty($periodosPorAnio) ? 'disabled' : ''; ?> required>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <?php
                            $habilitado = empty($periodosPorAnio) || in_array($m, $periodosPorAnio[$anioResultadoDefault] ?? [], true);
                        ?>
                        <option value="<?php echo $m; ?>" <?php echo $m === $mesResultadoDefault ? 'selected' : ''; ?> <?php echo $habilitado ? '' : 'disabled'; ?>><?php echo $nombresMeses[$m]; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Número ganador</label>
                <input type="text" name="numero_ganador" class="form-control" maxlength="50" placeholder="Ej: 23" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Observaciones</label>
                <input type="text" name="observaciones" class="form-control" maxlength="255" placeholder="Detalle opcional">
            </div>
            <div class="col-md-2 align-self-end">
                <button class="btn btn-primary w-100" <?php echo empty($periodosPorAnio) ? 'disabled' : ''; ?>>Guardar</button>
            </div>
        </form>
    </div>
</div>
<div class="row g-3">
    <div class="col-md-4">
        <div class="card text-bg-primary">
            <div class="card-body">
                <h5>Total recaudado</h5>
                <p class="display-6">$<?php echo number_format($ingresos,0,',','.'); ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-bg-danger">
            <div class="card-body">
                <h5>Total premios pagados</h5>
                <p class="display-6">$<?php echo number_format($egresos,0,',','.'); ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-bg-success">
            <div class="card-body">
                <h5>Utilidad neta</h5>
                <p class="display-6">$<?php echo number_format($ingresos - $egresos,0,',','.'); ?></p>
            </div>
        </div>
    </div>
</div>
<h4 class="mt-4 d-flex align-items-center gap-2"><i class="bi bi-calendar3"></i><span>Resumen de pago por periodo</span></h4>
<?php if (empty($periodosColumnas)): ?>
    <div class="alert alert-warning">Configure los periodos activos en el módulo de configuración para visualizar los pagos por mes y año.</div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-bordered table-sm align-middle">
            <thead>
                <tr>
                    <th>Socio</th>
                    <?php foreach ($periodosColumnas as $col): ?>
                        <th class="text-center"><?php echo clean($col['label']); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($socios as $socio): ?>
                    <tr>
                        <td><?php echo clean($socio['nombre_completo']); ?></td>
                        <?php foreach ($periodosColumnas as $col): ?>
                            <?php $valorPagado = $pagosIndex[$socio['id_socio']][$col['anio']][$col['mes']] ?? 0; ?>
                            <td class="text-center">
                                <?php if ($valorPagado > 0): ?>
                                    $<?php echo number_format($valorPagado, 0, ',', '.'); ?>
                                <?php else: ?>
                                    <span class="text-danger fw-semibold">No ha pagado</span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<h4 class="mt-4">Resumen por socio</h4>
<div class="table-responsive">
<table class="table table-striped table-sm">
    <thead><tr><th>Socio</th><th>Aportes</th><th>Premios</th><th>Neto</th></tr></thead>
    <tbody>
        <?php foreach($porSocio as $r): ?>
            <tr>
                <td><?php echo clean($r['nombre_completo']); ?></td>
                <td>$<?php echo number_format($r['ingresos'],0,',','.'); ?></td>
                <td>$<?php echo number_format($r['egresos'],0,',','.'); ?></td>
                <td>$<?php echo number_format($r['ingresos'] - $r['egresos'],0,',','.'); ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<h4 class="mt-4">Totales mensuales</h4>
<div class="table-responsive">
<table class="table table-bordered table-sm">
    <thead><tr><th>Mes</th><th>Ingresos</th><th>Egresos</th><th>Neto</th><th>Número ganador</th><th>Acciones</th></tr></thead>
    <tbody>
        <?php foreach($porMes as $r): ?>
            <tr>
                <td class="align-middle"><?php echo clean($r['mes']); ?></td>
                <td class="align-middle">$<?php echo number_format($r['ingresos'],0,',','.'); ?></td>
                <td class="align-middle">$<?php echo number_format($r['egresos'],0,',','.'); ?></td>
                <td class="align-middle">$<?php echo number_format($r['ingresos'] - $r['egresos'],0,',','.'); ?></td>
                <td class="align-middle fw-semibold text-primary"><?php echo $r['numero_ganador'] !== '' ? clean($r['numero_ganador']) : '—'; ?></td>
                <td class="align-middle">
                    <div class="d-flex gap-1">
                        <form method="POST" action="../actions/polla_resultados_save.php" class="d-flex gap-2 flex-wrap">
                            <input type="hidden" name="accion" value="<?php echo $r['id_resultado'] ? 'actualizar' : 'crear'; ?>">
                            <?php if ($r['id_resultado']): ?>
                                <input type="hidden" name="id_resultado" value="<?php echo (int)$r['id_resultado']; ?>">
                            <?php endif; ?>
                            <input type="hidden" name="mes_resultado" value="<?php echo clean($r['mes']); ?>">
                            <input type="text" name="numero_ganador" value="<?php echo clean($r['numero_ganador']); ?>" class="form-control form-control-sm" placeholder="N°" style="width: 90px;">
                            <button class="btn btn-sm btn-outline-primary" title="Guardar"><i class="bi bi-save"></i></button>
                        </form>
                        <?php if ($r['id_resultado']): ?>
                            <form method="POST" action="../actions/polla_resultados_save.php" onsubmit="return confirm('¿Eliminar el resultado de <?php echo clean($r['mes']); ?>?');">
                                <input type="hidden" name="accion" value="eliminar">
                                <input type="hidden" name="id_resultado" value="<?php echo (int)$r['id_resultado']; ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<h4 class="mt-4">Historial de resultados</h4>
<div class="table-responsive">
    <table class="table table-striped table-sm align-middle">
        <thead><tr><th>Mes</th><th>Número ganador</th><th>Observaciones</th><th></th></tr></thead>
    <tbody>
        <?php foreach($resultadosPolla as $r): ?>
            <tr>
                <td><?php echo sprintf('%04d-%02d', $r['anio'], $r['mes']); ?></td>
                <td class="fw-semibold text-primary"><?php echo clean($r['numero_ganador']); ?></td>
                <td class="text-muted small"><?php echo clean($r['observaciones']); ?></td>
                <td class="text-end">
                    <form method="POST" action="../actions/polla_resultados_save.php" onsubmit="return confirm('¿Eliminar el resultado guardado?');" class="d-inline">
                        <input type="hidden" name="accion" value="eliminar">
                        <input type="hidden" name="id_resultado" value="<?php echo (int)$r['id_resultado']; ?>">
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const periodosPorAnio = <?php echo json_encode($periodosPorAnio); ?>;
    const nombresMeses = <?php echo json_encode($nombresMeses); ?>;
    const anioSelect = document.querySelector('select[name="anio"]');
    const mesSelect = document.querySelector('select[name="mes"]');
    const mesResultadoInput = document.getElementById('mesResultadoInput');

    function poblarMeses(anio, mesSeleccionado = null) {
        if (!mesSelect) {
            return;
        }

        const mesesDisponibles = periodosPorAnio?.[anio] || [];
        const meses = mesesDisponibles.length ? mesesDisponibles : Array.from({ length: 12 }, (_, i) => i + 1);
        mesSelect.innerHTML = '';

        meses.forEach((mes) => {
            const option = document.createElement('option');
            option.value = mes;
            option.textContent = nombresMeses[mes] || mes;
            option.selected = mesSeleccionado ? Number(mesSeleccionado) === Number(mes) : mesSelect.options.length === 0;
            mesSelect.appendChild(option);
        });
    }

    function actualizarMesResultado() {
        if (!mesResultadoInput || !anioSelect || !mesSelect) {
            return;
        }
        const anio = anioSelect.value;
        const mes = mesSelect.value;
        mesResultadoInput.value = anio && mes ? `${anio}-${String(mes).padStart(2, '0')}` : '';
    }

    if (anioSelect) {
        anioSelect.addEventListener('change', () => {
            const anio = Number(anioSelect.value || new Date().getFullYear());
            poblarMeses(anio, mesSelect?.value || null);
            actualizarMesResultado();
        });
    }

    if (mesSelect) {
        mesSelect.addEventListener('change', actualizarMesResultado);
    }

    if (anioSelect) {
        poblarMeses(Number(anioSelect.value || new Date().getFullYear()), Number(mesSelect?.value || 0));
    }
    actualizarMesResultado();
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
