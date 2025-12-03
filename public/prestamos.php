<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$configGeneral = getConfiguracionGeneral($pdo);
$socios = getSocios($pdo);
$actividades = getActividades($pdo);
$mediosPago = getMediosPago($pdo);

$tasaSocioConfig = isset($configGeneral['tasa_interes_socio']) ? (float) $configGeneral['tasa_interes_socio'] : 0;
$tasaParticularConfig = isset($configGeneral['tasa_interes_particular']) ? (float) $configGeneral['tasa_interes_particular'] : 0;

$prestamos = $pdo->query("SELECT p.*, s.nombre_completo, aval.nombre_completo AS nombre_aval FROM prestamos p LEFT JOIN socios s ON p.id_socio=s.id_socio LEFT JOIN socios aval ON p.id_socio_aval = aval.id_socio ORDER BY p.fecha_prestamo DESC LIMIT 100")->fetchAll();
?>
<h2 class="mb-3 d-flex align-items-center gap-2"><i class="bi bi-cash-coin text-primary"></i><span>Préstamos</span></h2>
<div class="card mb-3">
    <div class="card-header category-prestamos"><i class="bi bi-plus-circle"></i><span>Crear nuevo préstamo</span></div>
    <div class="card-body">
        <form method="POST" action="../actions/prestamos_save.php">
            <div class="row g-2">
                <div class="col-md-3">
                    <label class="form-label">Fecha préstamo</label>
                    <input type="date" name="fecha_prestamo" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tipo de deudor</label>
                    <select name="es_particular" class="form-select" required>
                        <option value="">Seleccione tipo</option>
                        <option value="0">Socio</option>
                        <option value="1">Particular</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Socio beneficiario</label>
                    <select name="id_socio" class="form-select" required>
                        <option value="">Seleccione socio</option>
                        <?php foreach($socios as $s): ?>
                            <option value="<?php echo $s['id_socio']; ?>"><?php echo clean($s['nombre_completo']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Deudor particular (si aplica)</label>
                    <input type="text" name="nombre_deudor" class="form-control" placeholder="Nombre de particular" disabled>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Socio avalador (para particulares)</label>
                    <select name="id_socio_aval" class="form-select" disabled>
                        <option value="">Seleccione socio avalador</option>
                        <?php foreach($socios as $s): ?>
                            <option value="<?php echo $s['id_socio']; ?>"><?php echo clean($s['nombre_completo']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Monto préstamo</label>
                    <input type="number" step="0.01" name="monto_prestamo" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Tasa interés (%)</label>
                    <input type="number" step="0.01" name="tasa_interes" class="form-control" value="<?php echo $tasaSocioConfig; ?>" data-tasa-socio="<?php echo $tasaSocioConfig; ?>" data-tasa-particular="<?php echo $tasaParticularConfig; ?>">
                </div>
            </div>
            <div class="alert alert-info mt-3 mb-2">
                <div class="fw-semibold text-uppercase small text-muted">Resumen financiero proyectado</div>
                <div class="row g-3 mt-1" id="resumenFinanciero">
                    <div class="col-md-4">
                        <div class="p-3 bg-body-tertiary rounded border h-100">
                            <div class="text-muted small">Saldo actual acumulado</div>
                            <div class="fs-5 fw-semibold" id="saldoAcumulado">$0</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-body-tertiary rounded border h-100">
                            <div class="text-muted small">Saldo actual de préstamos</div>
                            <div class="fs-5 fw-semibold" id="saldoPrestamos">$0</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-body-tertiary rounded border h-100">
                            <div class="text-muted small">Ingresos proyectados próximos meses</div>
                            <div class="fs-5 fw-semibold" id="ingresosProyectados">$0</div>
                        </div>
                    </div>
                </div>
                <div class="mt-3" id="alertaRiesgo">
                    <div class="p-3 rounded border d-flex flex-column flex-md-row gap-2 justify-content-between align-items-start align-items-md-center">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge rounded-pill px-3 py-2" id="badgeRiesgo">🟢 Riesgo bajo</span>
                            <div class="fw-semibold" id="mensajeRiesgo">Riesgo bajo: capacidad de pago adecuada.</div>
                        </div>
                        <div class="text-muted small">Relación deuda / ingresos proyectados: <span class="fw-semibold" id="ratioRiesgo">0%</span></div>
                    </div>
                    <div class="text-muted small mt-2">Este análisis es informativo y no bloquea la creación del préstamo.</div>
                </div>
            </div>
            <div class="alert alert-info mt-3 mb-2">
                <div class="fw-semibold text-uppercase small text-muted">Resumen financiero proyectado</div>
                <div class="row g-3 mt-1" id="resumenFinanciero">
                    <div class="col-md-4">
                        <div class="p-3 bg-body-tertiary rounded border h-100">
                            <div class="text-muted small">Saldo actual acumulado</div>
                            <div class="fs-5 fw-semibold" id="saldoAcumulado">$0</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-body-tertiary rounded border h-100">
                            <div class="text-muted small">Saldo actual de préstamos</div>
                            <div class="fs-5 fw-semibold" id="saldoPrestamos">$0</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-body-tertiary rounded border h-100">
                            <div class="text-muted small">Ingresos proyectados próximos meses</div>
                            <div class="fs-5 fw-semibold" id="ingresosProyectados">$0</div>
                        </div>
                    </div>
                </div>
                <div class="mt-3" id="alertaRiesgo">
                    <div class="p-3 rounded border d-flex flex-column flex-md-row gap-2 justify-content-between align-items-start align-items-md-center">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge rounded-pill px-3 py-2" id="badgeRiesgo">🟢 Riesgo bajo</span>
                            <div class="fw-semibold" id="mensajeRiesgo">Riesgo bajo: capacidad de pago adecuada.</div>
                        </div>
                        <div class="text-muted small">Relación deuda / ingresos proyectados: <span class="fw-semibold" id="ratioRiesgo">0%</span></div>
                    </div>
                    <div class="text-muted small mt-2">Este análisis es informativo y no bloquea la creación del préstamo.</div>
                </div>
            </div>
            <button class="btn btn-success mt-3 btn-icon"><span><i class="bi bi-check2-circle"></i> Crear préstamo</span></button>
        </form>
    </div>
</div>
<div class="card mb-3">
    <div class="card-header category-prestamos"><i class="bi bi-cash-stack"></i><span>Registrar pago de préstamo</span></div>
    <div class="card-body">
        <form method="POST" action="../actions/cuotas_save.php">
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label">Préstamo</label>
                    <select name="id_prestamo" class="form-select" required>
                        <?php foreach($prestamos as $p): ?>
                            <?php $labelDeudor = $p['es_particular'] ? ($p['nombre_deudor'] . ($p['nombre_aval'] ? ' (Aval: '.$p['nombre_aval'].')' : '')) : $p['nombre_completo']; ?>
                            <option value="<?php echo $p['id_prestamo']; ?>"><?php echo '#'.$p['id_prestamo'].' - '.clean($labelDeudor); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Fecha pago</label>
                    <input type="date" name="fecha_pago" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Capital pagado</label>
                    <input type="number" step="0.01" name="valor_capital_pagado" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Interés pagado</label>
                    <input type="number" step="0.01" name="valor_interes_pagado" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Actividad pago (p.e. Pago Abono a Préstamo)</label>
                    <select name="id_actividad" class="form-select" required>
                        <?php foreach($actividades as $a): ?>
                            <?php if($a['es_pago_prestamo']) : ?>
                                <option value="<?php echo $a['id_actividad']; ?>"><?php echo clean($a['nombre_actividad']); ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Medio</label>
                    <select name="medio_consignacion" class="form-select" required>
                        <?php foreach($mediosPago as $mp): ?>
                            <option value="<?php echo clean($mp['nombre']); ?>"><?php echo clean($mp['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="mt-2 text-muted small">El número de cuota se asigna automáticamente al guardar el pago.</div>
            <button class="btn btn-primary mt-2 btn-icon"><span><i class="bi bi-receipt"></i> Registrar pago</span></button>
        </form>
    </div>
</div>
<h4 class="d-flex align-items-center gap-2"><i class="bi bi-activity text-primary"></i><span>Préstamos vigentes</span></h4>
<div class="table-responsive">
<table class="table table-sm table-bordered">
    <thead><tr><th>ID</th><th>Deudor</th><th>Aval</th><th>Tipo</th><th>Monto</th><th>Saldo capital</th><th>Saldo interés</th><th>Estado</th><th></th></tr></thead>
    <tbody>
        <?php foreach($prestamos as $p): ?>
            <?php $estadoPrestamo = ((float)$p['saldo_capital_actual'] > 0) ? 'Vigente' : 'Cancelado'; ?>
            <tr>
                <td><?php echo $p['id_prestamo']; ?></td>
                <td>
                    <div><?php echo clean($p['es_particular'] ? $p['nombre_deudor'] : $p['nombre_completo']); ?></div>
                    <?php if($p['es_particular'] && $p['nombre_aval']): ?>
                        <div class="text-muted small">Aval: <?php echo clean($p['nombre_aval']); ?></div>
                    <?php endif; ?>
                </td>
                <td><?php echo clean($p['nombre_aval']); ?></td>
                <td><?php echo $p['es_particular'] ? 'Particular' : 'Socio'; ?></td>
                <td>$<?php echo number_format($p['monto_prestamo'],0,',','.'); ?></td>
                <td>$<?php echo number_format($p['saldo_capital_actual'],0,',','.'); ?></td>
                <td>$<?php echo number_format($p['saldo_intereses_actual'],0,',','.'); ?></td>
                <td><?php echo $estadoPrestamo; ?></td>
                <td class="text-end">
                    <form method="POST" action="../actions/prestamos_save.php" class="d-inline" onsubmit="return confirm('Esta acción eliminará el préstamo, sus cuotas y movimientos asociados. ¿Deseas continuar?');">
                        <input type="hidden" name="accion" value="eliminar">
                        <input type="hidden" name="id_prestamo" value="<?php echo $p['id_prestamo']; ?>">
                        <button class="btn btn-sm btn-outline-danger" type="submit">Eliminar</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const socioSelect = document.querySelector('select[name="id_socio"]');
    const tipoDeudor = document.querySelector('select[name="es_particular"]');
    const montoPrestamo = document.querySelector('input[name="monto_prestamo"]');
    const tasaInteres = document.querySelector('input[name="tasa_interes"]');
    const avalSelect = document.querySelector('select[name="id_socio_aval"]');
    const deudorParticular = document.querySelector('input[name="nombre_deudor"]');

    const saldoAcumulado = document.getElementById('saldoAcumulado');
    const saldoPrestamos = document.getElementById('saldoPrestamos');
    const ingresosProyectados = document.getElementById('ingresosProyectados');
    const badgeRiesgo = document.getElementById('badgeRiesgo');
    const mensajeRiesgo = document.getElementById('mensajeRiesgo');
    const ratioRiesgo = document.getElementById('ratioRiesgo');
    const alertaRiesgo = document.getElementById('alertaRiesgo');

    let resumenBase = {
        accumulated: 0,
        debt: 0,
        projection: 0,
    };

    const formatter = new Intl.NumberFormat('es-CO', {
        style: 'currency',
        currency: 'COP',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    });

    async function obtenerResumenSocio() {
        const socioId = socioSelect.value;
        const esParticular = tipoDeudor.value === '1';

        if (!socioId || esParticular) {
            resumenBase = { accumulated: 0, debt: 0, projection: 0 };
            actualizarResumen();
            return;
        }

        try {
            const response = await fetch(`../actions/prestamos_finanzas.php?id_socio=${encodeURIComponent(socioId)}`);
            if (!response.ok) {
                throw new Error('No se pudo obtener el resumen financiero.');
            }
            const data = await response.json();
            resumenBase = {
                accumulated: Number(data.accumulated_balance ?? 0),
                debt: Number(data.total_debt ?? 0),
                projection: Number(data.projected_income ?? 0),
            };
        } catch (error) {
            console.error(error);
            resumenBase = { accumulated: 0, debt: 0, projection: 0 };
        }
        actualizarResumen();
    }

    function sincronizarCamposDeudor() {
        const esParticular = tipoDeudor.value === '1';

        if (esParticular) {
            socioSelect.value = '';
            socioSelect.disabled = true;
            socioSelect.required = false;
            avalSelect.disabled = false;
            avalSelect.required = true;
            deudorParticular.disabled = false;
            deudorParticular.required = true;
        } else {
            socioSelect.disabled = false;
            socioSelect.required = true;
            avalSelect.value = '';
            avalSelect.disabled = true;
            avalSelect.required = false;
            deudorParticular.value = '';
            deudorParticular.disabled = true;
            deudorParticular.required = false;
        }
    }

    function calcularConNuevoPrestamo() {
        const monto = parseFloat(montoPrestamo.value) || 0;

        return {
            accumulated: resumenBase.accumulated,
            debt: resumenBase.debt + monto,
            projection: resumenBase.projection,
        };
    }

    function definirRiesgo(ratio, deuda) {
        if (ratio <= 30) {
            return {
                nivel: 'bajo',
                badge: '🟢 Riesgo bajo',
                mensaje: 'Riesgo bajo: capacidad de pago adecuada.',
                clase: 'bg-success-subtle text-success border-success',
            };
        }
        if (ratio <= 60) {
            return {
                nivel: 'medio',
                badge: '🟡 Riesgo medio',
                mensaje: 'Riesgo medio: revisar condiciones del préstamo.',
                clase: 'bg-warning-subtle text-warning border-warning',
            };
        }
        if (deuda > 0) {
            return {
                nivel: 'alto',
                badge: '🔴 Riesgo alto',
                mensaje: 'Riesgo alto: el nivel de endeudamiento proyectado es elevado.',
                clase: 'bg-danger-subtle text-danger border-danger',
            };
        }
        return {
            nivel: 'alto',
            badge: '🔴 Riesgo alto',
            mensaje: 'Riesgo alto: el nivel de endeudamiento proyectado es elevado.',
            clase: 'bg-danger-subtle text-danger border-danger',
        };
    }

    function actualizarResumen() {
        const totales = calcularConNuevoPrestamo();

        const proyeccion = Math.max(totales.projection, 0);
        saldoAcumulado.textContent = formatter.format(Math.max(totales.accumulated, 0));
        saldoPrestamos.textContent = formatter.format(Math.max(totales.debt, 0));
        ingresosProyectados.textContent = formatter.format(proyeccion);

        const ratio = proyeccion > 0 ? (totales.debt / proyeccion) * 100 : (totales.debt > 0 ? 100 : 0);
        const riesgo = definirRiesgo(ratio, totales.debt);

        badgeRiesgo.textContent = riesgo.badge;
        mensajeRiesgo.textContent = riesgo.mensaje;
        ratioRiesgo.textContent = `${ratio.toFixed(1)}%`;

        alertaRiesgo.querySelector('.p-3').className = `p-3 rounded border d-flex flex-column flex-md-row gap-2 justify-content-between align-items-start align-items-md-center ${riesgo.clase}`;
    }

    socioSelect.addEventListener('change', obtenerResumenSocio);
    tipoDeudor.addEventListener('change', () => {
        sincronizarCamposDeudor();
        obtenerResumenSocio();
        const tasaSocio = parseFloat(tasaInteres.dataset.tasaSocio || '0');
        const tasaParticular = parseFloat(tasaInteres.dataset.tasaParticular || '0');
        tasaInteres.value = tipoDeudor.value === '1' ? tasaParticular : tasaSocio;
    });
    montoPrestamo.addEventListener('input', actualizarResumen);

    sincronizarCamposDeudor();
    actualizarResumen();
});
</script>
