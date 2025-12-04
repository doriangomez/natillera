<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$configGeneral = getConfiguracionGeneral($pdo);
asegurarTablaPeriodosPrestamo($pdo);
extenderPeriodosPrestamoHastaMesActual($pdo);
$conceptosPrestamo = sincronizarConceptosPrestamo($pdo);
$socios = getSocios($pdo);
$actividades = getActividades($pdo);
$mediosPago = getMediosPago($pdo);

$tasaSocioConfig = isset($configGeneral['tasa_interes_socio']) ? (float) $configGeneral['tasa_interes_socio'] : 0;
$tasaParticularConfig = isset($configGeneral['tasa_interes_particular']) ? (float) $configGeneral['tasa_interes_particular'] : 0;

$prestamos = $pdo->query(
    "SELECT p.*, s.nombre_completo, aval.nombre_completo AS nombre_aval,
            (SELECT MAX(fecha_pago) FROM cuotas_prestamo cp WHERE cp.id_prestamo = p.id_prestamo) AS ultima_fecha_pago
     FROM prestamos p
     LEFT JOIN socios s ON p.id_socio = s.id_socio
     LEFT JOIN socios aval ON p.id_socio_aval = aval.id_socio
     ORDER BY p.fecha_prestamo DESC
     LIMIT 100"
)->fetchAll();

$periodosPorPrestamo = obtenerPeriodosPrestamo($pdo, array_column($prestamos, 'id_prestamo'));
$periodosJson = json_encode($periodosPorPrestamo, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
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
                    <input type="number" step="0.01" name="tasa_interes" class="form-control" value="<?php echo $tasaSocioConfig; ?>" data-tasa-socio="<?php echo $tasaSocioConfig; ?>" data-tasa-particular="<?php echo $tasaParticularConfig; ?>" required>
                </div>
            </div>
            <div class="alert alert-info mt-3 mb-2">
                <div class="fw-semibold text-uppercase small text-muted">Resumen financiero proyectado</div>
                <div class="row g-3 mt-1" id="resumenFinanciero">
                    <div class="col-md-3">
                        <div class="p-3 bg-body-tertiary rounded border h-100">
                            <div class="text-muted small">Saldo actual acumulado</div>
                            <div class="fs-5 fw-semibold" id="saldoAcumulado">$0</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-3 bg-body-tertiary rounded border h-100">
                            <div class="text-muted small">Saldo actual de préstamos</div>
                            <div class="fs-5 fw-semibold" id="saldoPrestamos">$0</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-3 bg-body-tertiary rounded border h-100">
                            <div class="text-muted small">Ingresos proyectados próximos meses</div>
                            <div class="fs-5 fw-semibold" id="ingresosProyectados">$0</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-3 bg-body-tertiary rounded border h-100">
                            <div class="text-muted small">Saldo estimado total</div>
                            <div class="fs-5 fw-semibold" id="saldoEstimadoTotal">$0</div>
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
                            <option
                                value="<?php echo $p['id_prestamo']; ?>"
                                data-tasa="<?php echo $p['tasa_interes']; ?>"
                                data-capital="<?php echo $p['saldo_capital_actual']; ?>"
                                data-interes="<?php echo $p['saldo_intereses_actual']; ?>"
                                data-fecha-prestamo="<?php echo $p['fecha_prestamo']; ?>"
                                data-ultima-fecha="<?php echo $p['ultima_fecha_pago']; ?>"
                                data-tiene-pagos="<?php echo $p['ultima_fecha_pago'] ? '1' : '0'; ?>"
                            >
                                <?php echo '#'.$p['id_prestamo'].' - '.clean($labelDeudor); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tipo de pago</label>
                    <select name="tipo_pago" class="form-select" id="tipoPago" required>
                        <option value="capital">Pago a préstamo (capital)</option>
                        <option value="interes">Pago a intereses</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Fecha pago</label>
                    <input type="date" name="fecha_pago" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Capital pagado</label>
                    <div class="input-group">
                        <input type="number" step="0.01" min="0" name="valor_capital_pagado" class="form-control" value="0" required>
                        <button class="btn btn-outline-secondary" type="button" id="btnPagarCapitalCompleto">Total</button>
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Interés pagado</label>
                    <div class="input-group">
                        <input type="number" step="0.01" min="0" name="valor_interes_pagado" class="form-control" value="0" required>
                        <button class="btn btn-outline-secondary" type="button" id="btnLiquidarInteres">Liquidar</button>
                    </div>
                </div>
                <div class="col-12">
                    <div class="alert alert-info d-flex flex-column gap-1" id="resumenPago">
                        <div><strong>Interés sugerido del periodo:</strong> <span id="interesSugerido">$0</span></div>
                        <div class="text-muted small">Intereses pendientes estimados: <span id="detalleInteresPendiente">$0</span></div>
                        <div class="text-muted small">Saldo capital actual: <span id="saldoCapitalPendiente">$0</span></div>
                        <div class="text-muted small" id="detallePeriodos"></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Concepto del pago</label>
                    <select name="id_actividad" class="form-select" id="actividadPago" required>
                        <?php foreach($actividades as $a): ?>
                            <?php if($a['es_pago_prestamo'] || $a['es_pago_interes']) : ?>
                                <option value="<?php echo $a['id_actividad']; ?>" data-tipo="<?php echo $a['es_pago_interes'] ? 'interes' : 'capital'; ?>">
                                    <?php echo clean($a['nombre_actividad']); ?>
                                </option>
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
    <thead><tr><th>ID</th><th>Deudor</th><th>Aval</th><th>Tipo</th><th>Monto</th><th>Tasa mensual (%)</th><th>Interés mensual</th><th>Saldo capital</th><th>Saldo interés</th><th>Estado</th><th></th></tr></thead>
    <tbody>
        <?php foreach($prestamos as $p): ?>
            <?php
                $periodos = $periodosPorPrestamo[$p['id_prestamo']] ?? [];
                $periodosMora = array_filter($periodos, fn($per) => ($per['estado'] ?? '') === 'Mora');
                $estadoPrestamo = $p['estado'] ?: (((float)$p['saldo_capital_actual'] > 0) ? 'Activo' : 'Finalizado');
                $semaforo = '🟢';
                if (count($periodosMora) >= 2) {
                    $semaforo = '🔴';
                } elseif (count($periodosMora) === 1) {
                    $semaforo = '🟡';
                }
            ?>
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
                <td><?php echo number_format($p['tasa_interes'],2,',','.'); ?>%</td>
                <td>$<?php echo number_format($p['interes_mensual'],0,',','.'); ?></td>
                <td>$<?php echo number_format($p['saldo_capital_actual'],0,',','.'); ?></td>
                <td>$<?php echo number_format($p['saldo_intereses_actual'],0,',','.'); ?></td>
                <td><?php echo $estadoPrestamo; ?> <span class="small"><?php echo $semaforo; ?></span></td>
                <td class="text-end">
                    <form method="POST" action="../actions/prestamos_save.php" class="d-inline" onsubmit="return confirm('Esta acción eliminará el préstamo y sus movimientos asociados. ¿Deseas continuar?');">
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
<div class="card mt-3">
    <div class="card-header category-prestamos"><i class="bi bi-calendar3"></i><span>Matriz mensual de cumplimiento</span></div>
    <div class="card-body">
        <?php foreach ($prestamos as $p): ?>
            <?php $periodos = $periodosPorPrestamo[$p['id_prestamo']] ?? []; ?>
            <?php if (empty($periodos)) { continue; } ?>
            <?php $periodosVisibles = $periodos; ?>
            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="fw-semibold">Préstamo #<?php echo $p['id_prestamo']; ?> - <?php echo clean($p['es_particular'] ? $p['nombre_deudor'] : $p['nombre_completo']); ?></div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-body-secondary text-dark">Estado: <?php echo clean($p['estado']); ?></span>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-primary verHistorialBtn"
                            data-id="<?php echo $p['id_prestamo']; ?>"
                            data-label="<?php echo clean($p['es_particular'] ? $p['nombre_deudor'] : $p['nombre_completo']); ?>"
                        >
                            Ver historial completo
                        </button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Mes/Año</th>
                                <th class="text-end">Capital inicio</th>
                                <th class="text-end">Interés causado</th>
                                <th class="text-end">Interés pagado</th>
                                <th class="text-end">Abono capital</th>
                                <th class="text-end">Capital final</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($periodosVisibles as $per): ?>
                                <?php
                                    $mesNombre = DateTime::createFromFormat('!m', (int) $per['mes'])->format('M');
                                    $estado = $per['estado'] ?: 'Pendiente';
                                    $badgeClass = 'bg-secondary-subtle text-secondary';
                                    if ($estado === 'OK') {
                                        $badgeClass = 'bg-success-subtle text-success';
                                    } elseif ($estado === 'Mora') {
                                        $badgeClass = 'bg-danger-subtle text-danger';
                                    } elseif ($estado === 'Finalizado') {
                                        $badgeClass = 'bg-primary-subtle text-primary';
                                    }
                                ?>
                                <tr>
                                    <td><?php echo $mesNombre . ' ' . $per['anio']; ?></td>
                                    <td class="text-end">$<?php echo number_format((float) $per['capital_inicio'], 0, ',', '.'); ?></td>
                                    <td class="text-end">$<?php echo number_format((float) $per['interes_causado'], 0, ',', '.'); ?></td>
                                    <td class="text-end">$<?php echo number_format((float) $per['interes_pagado'], 0, ',', '.'); ?></td>
                                    <td class="text-end">$<?php echo number_format((float) $per['abono_capital'], 0, ',', '.'); ?></td>
                                    <td class="text-end">$<?php echo number_format((float) $per['capital_final'], 0, ',', '.'); ?></td>
                                    <td><span class="badge <?php echo $badgeClass; ?>"><?php echo $estado; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (empty(array_filter($periodosPorPrestamo))): ?>
            <div class="alert alert-info mb-0">No hay periodos calculados aún para los préstamos.</div>
        <?php endif; ?>
    </div>
</div>
<div class="modal fade" id="historialPeriodosModal" tabindex="-1" aria-labelledby="historialPeriodosTitulo" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="historialPeriodosTitulo">Historial mensual</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Mes/Año</th>
                                <th class="text-end">Capital inicio</th>
                                <th class="text-end">Interés causado</th>
                                <th class="text-end">Interés pagado</th>
                                <th class="text-end">Abono capital</th>
                                <th class="text-end">Capital final</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody id="historialPeriodosBody"></tbody>
                    </table>
                </div>
                <div class="alert alert-info d-none" id="historialVacio">No hay periodos registrados para este préstamo.</div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const periodosPrestamos = <?php echo $periodosJson ?: '{}'; ?>;
    const socioSelect = document.querySelector('select[name="id_socio"]');
    const tipoDeudor = document.querySelector('select[name="es_particular"]');
    const montoPrestamo = document.querySelector('input[name="monto_prestamo"]');
    const tasaInteres = document.querySelector('input[name="tasa_interes"]');
    const avalSelect = document.querySelector('select[name="id_socio_aval"]');
    const prestamoSelect = document.querySelector('select[name="id_prestamo"]');
    const fechaPagoInput = document.querySelector('input[name="fecha_pago"]');
    const interesInput = document.querySelector('input[name="valor_interes_pagado"]');
    const capitalInput = document.querySelector('input[name="valor_capital_pagado"]');
    const interesSugeridoSpan = document.getElementById('interesSugerido');
    const detalleInteresPendiente = document.getElementById('detalleInteresPendiente');
    const saldoCapitalPendiente = document.getElementById('saldoCapitalPendiente');
    const detallePeriodos = document.getElementById('detallePeriodos');
    const tipoPagoSelect = document.getElementById('tipoPago');
    const actividadPago = document.getElementById('actividadPago');
    const deudorParticular = document.querySelector('input[name="nombre_deudor"]');

    const saldoAcumulado = document.getElementById('saldoAcumulado');
    const saldoPrestamos = document.getElementById('saldoPrestamos');
    const ingresosProyectados = document.getElementById('ingresosProyectados');
    const saldoEstimadoTotal = document.getElementById('saldoEstimadoTotal');
    const badgeRiesgo = document.getElementById('badgeRiesgo');
    const mensajeRiesgo = document.getElementById('mensajeRiesgo');
    const ratioRiesgo = document.getElementById('ratioRiesgo');
    const alertaRiesgo = document.getElementById('alertaRiesgo');
    const historialModalEl = document.getElementById('historialPeriodosModal');
    const historialPeriodosBody = document.getElementById('historialPeriodosBody');
    const historialTitulo = document.getElementById('historialPeriodosTitulo');
    const historialVacio = document.getElementById('historialVacio');
    const modalHistorial = historialModalEl ? new bootstrap.Modal(historialModalEl) : null;

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

    function inicioMes(fecha) {
        const dt = new Date(fecha);
        dt.setDate(1);
        dt.setHours(0, 0, 0, 0);
        return dt;
    }

    function obtenerPeriodoRegistrado(idPrestamo, fechaBase) {
        const periodos = periodosPrestamos?.[idPrestamo] || [];
        const referencia = inicioMes(fechaBase);
        return periodos.find(per => Number(per.anio) === referencia.getFullYear() && Number(per.mes) === (referencia.getMonth() + 1));
    }

    function calcularMesesPendientes(prestamo, fechaPagoStr) {
        if (!prestamo) {
            return { meses: 0, referencia: null, periodoTexto: '' };
        }

        const fechaPagoBase = fechaPagoStr || new Date().toISOString().slice(0, 10);
        const fechaPago = inicioMes(fechaPagoBase);
        const referencia = prestamo.ultimaFecha || prestamo.fechaPrestamo;
        const fechaReferencia = inicioMes(referencia);
        const diffMeses = (fechaPago.getFullYear() - fechaReferencia.getFullYear()) * 12 + (fechaPago.getMonth() - fechaReferencia.getMonth());
        const mesesCalculados = prestamo.tienePagos ? Math.max(0, diffMeses) : Math.max(1, diffMeses + 1);
        const nombreMeses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        const textoReferencia = `${nombreMeses[fechaReferencia.getMonth()]} ${fechaReferencia.getFullYear()}`;
        const textoPago = `${nombreMeses[fechaPago.getMonth()]} ${fechaPago.getFullYear()}`;

        const periodoRegistrado = prestamo.id ? obtenerPeriodoRegistrado(prestamo.id, fechaPagoBase) : null;
        const interesPagado = periodoRegistrado ? Number(periodoRegistrado.interes_pagado || 0) : 0;
        const interesCausado = periodoRegistrado ? Number(periodoRegistrado.interes_causado || 0) : 0;
        const mesAlDia = periodoRegistrado && interesPagado + 0.01 >= interesCausado;

        if (mesAlDia) {
            return {
                meses: 0,
                referencia: referencia,
                periodoTexto: `El interés de ${textoPago} ya fue pagado.`,
            };
        }

        return {
            meses: mesesCalculados,
            referencia: referencia,
            periodoTexto: mesesCalculados > 0
                ? `Intereses causados desde ${textoReferencia} hasta ${textoPago} (${mesesCalculados} mes(es)).`
                : 'Intereses al día para el mes seleccionado.',
        };
    }

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

    function obtenerPrestamoSeleccionado() {
        if (!prestamoSelect) {
            return null;
        }
        const opcion = prestamoSelect.selectedOptions[0];
        if (!opcion) {
            return null;
        }
        return {
            id: opcion.value,
            tasa: parseFloat(opcion.dataset.tasa || '0'),
            saldoCapital: parseFloat(opcion.dataset.capital || '0'),
            saldoInteres: parseFloat(opcion.dataset.interes || '0'),
            fechaPrestamo: opcion.dataset.fechaPrestamo,
            ultimaFecha: opcion.dataset.ultimaFecha,
            tienePagos: opcion.dataset.tienePagos === '1',
        };
    }

    function actualizarSugerenciaPago() {
        if (!interesSugeridoSpan || !detalleInteresPendiente || !saldoCapitalPendiente) {
            return;
        }
        const prestamo = obtenerPrestamoSeleccionado();
        if (!prestamo) {
            interesSugeridoSpan.textContent = formatter.format(0);
            detalleInteresPendiente.textContent = formatter.format(0);
            saldoCapitalPendiente.textContent = formatter.format(0);
            if (detallePeriodos) {
                detallePeriodos.textContent = '';
            }
            return;
        }

        const fechaPagoSeleccionada = fechaPagoInput?.value || new Date().toISOString().slice(0, 10);
        const periodos = calcularMesesPendientes(prestamo, fechaPagoSeleccionada);
        const interesGenerado = prestamo.saldoCapital * (prestamo.tasa / 100) * periodos.meses;
        const interesPendiente = Math.max(0, prestamo.saldoInteres + interesGenerado);

        interesSugeridoSpan.textContent = formatter.format(interesPendiente);
        detalleInteresPendiente.textContent = formatter.format(interesPendiente);
        saldoCapitalPendiente.textContent = formatter.format(prestamo.saldoCapital);
        if (detallePeriodos) {
            detallePeriodos.textContent = periodos.periodoTexto;
        }

        if (interesInput && (interesInput.dataset.touched !== '1' || !interesInput.value || interesInput.value === '0')) {
            interesInput.value = interesPendiente.toFixed(2);
            interesInput.dataset.touched = '0';
        }
        if (capitalInput) {
            capitalInput.max = prestamo.saldoCapital.toFixed(2);
        }
    }

    function actualizarConceptoSegunTipo() {
        if (!actividadPago || !tipoPagoSelect) {
            return;
        }
        const tipo = tipoPagoSelect.value;
        let primeraCoincidencia = null;

        actividadPago.querySelectorAll('option').forEach(opt => {
            const coincide = opt.dataset.tipo === tipo;
            opt.hidden = !coincide;
            opt.disabled = !coincide;
            if (coincide && !primeraCoincidencia) {
                primeraCoincidencia = opt.value;
            }
        });

        if (primeraCoincidencia) {
            actividadPago.value = primeraCoincidencia;
        }
    }

    function actualizarUIporTipoPago() {
        if (!tipoPagoSelect) {
            return;
        }
        const tipo = tipoPagoSelect.value;
        if (tipo === 'interes') {
            if (capitalInput) {
                capitalInput.value = '0';
                capitalInput.disabled = true;
                capitalInput.required = false;
            }
            if (interesInput) {
                interesInput.disabled = false;
                interesInput.required = true;
            }
        } else {
            if (capitalInput) {
                capitalInput.disabled = false;
                capitalInput.required = true;
            }
            if (interesInput) {
                interesInput.disabled = false;
                interesInput.required = false;
            }
        }
        actualizarConceptoSegunTipo();
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
        const saldoEstimado = totales.accumulated - totales.debt + proyeccion;
        saldoAcumulado.textContent = formatter.format(Math.max(totales.accumulated, 0));
        saldoPrestamos.textContent = formatter.format(Math.max(totales.debt, 0));
        ingresosProyectados.textContent = formatter.format(proyeccion);
        saldoEstimadoTotal.textContent = formatter.format(saldoEstimado);

        const ratio = proyeccion > 0 ? (totales.debt / proyeccion) * 100 : (totales.debt > 0 ? 100 : 0);
        const riesgo = definirRiesgo(ratio, totales.debt);

        badgeRiesgo.textContent = riesgo.badge;
        mensajeRiesgo.textContent = riesgo.mensaje;
        ratioRiesgo.textContent = `${ratio.toFixed(1)}%`;

        alertaRiesgo.querySelector('.p-3').className = `p-3 rounded border d-flex flex-column flex-md-row gap-2 justify-content-between align-items-start align-items-md-center ${riesgo.clase}`;
    }

    function construirBadgeEstado(estado) {
        const badgeClass = {
            OK: 'bg-success-subtle text-success',
            Mora: 'bg-danger-subtle text-danger',
            Finalizado: 'bg-primary-subtle text-primary',
        }[estado] || 'bg-secondary-subtle text-secondary';

        return `<span class="badge ${badgeClass}">${estado}</span>`;
    }

    function abrirHistorialPeriodos(id, etiqueta) {
        if (!modalHistorial || !historialPeriodosBody || !historialTitulo || !historialVacio) {
            return;
        }

        historialTitulo.textContent = `Historial mensual - ${etiqueta}`;
        historialPeriodosBody.innerHTML = '';

        const periodos = [...(periodosPrestamos?.[id] || [])].sort((a, b) => {
            if (a.anio === b.anio) {
                return Number(a.mes) - Number(b.mes);
            }
            return Number(a.anio) - Number(b.anio);
        });

        if (periodos.length === 0) {
            historialVacio.classList.remove('d-none');
            modalHistorial.show();
            return;
        }

        historialVacio.classList.add('d-none');
        const nombreMeses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

        periodos.forEach((per) => {
            const estado = per.estado || 'Pendiente';
            const fila = document.createElement('tr');
            fila.innerHTML = `
                <td>${nombreMeses[Number(per.mes) - 1]} ${per.anio}</td>
                <td class="text-end">${formatter.format(Number(per.capital_inicio || 0))}</td>
                <td class="text-end">${formatter.format(Number(per.interes_causado || 0))}</td>
                <td class="text-end">${formatter.format(Number(per.interes_pagado || 0))}</td>
                <td class="text-end">${formatter.format(Number(per.abono_capital || 0))}</td>
                <td class="text-end">${formatter.format(Number(per.capital_final || 0))}</td>
                <td>${construirBadgeEstado(estado)}</td>
            `;
            historialPeriodosBody.appendChild(fila);
        });

        modalHistorial.show();
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

    if (tipoPagoSelect) {
        tipoPagoSelect.addEventListener('change', () => {
            actualizarUIporTipoPago();
            actualizarSugerenciaPago();
        });
    }

    if (prestamoSelect) {
        prestamoSelect.addEventListener('change', () => {
            if (interesInput) {
                interesInput.dataset.touched = '0';
                interesInput.value = '';
            }
            if (capitalInput) {
                capitalInput.value = '0';
            }
            actualizarSugerenciaPago();
        });
    }
    if (fechaPagoInput) {
        fechaPagoInput.addEventListener('change', actualizarSugerenciaPago);
    }

    if (interesInput) {
        interesInput.addEventListener('input', () => {
            interesInput.dataset.touched = '1';
        });
    }

    const btnPagarCapitalCompleto = document.getElementById('btnPagarCapitalCompleto');
    const btnLiquidarInteres = document.getElementById('btnLiquidarInteres');

    if (btnPagarCapitalCompleto) {
        btnPagarCapitalCompleto.addEventListener('click', () => {
            const prestamo = obtenerPrestamoSeleccionado();
            if (!prestamo || !capitalInput) {
                return;
            }
            capitalInput.value = prestamo.saldoCapital.toFixed(2);
            capitalInput.dispatchEvent(new Event('input'));
        });
    }

    if (btnLiquidarInteres) {
        btnLiquidarInteres.addEventListener('click', () => {
            const prestamo = obtenerPrestamoSeleccionado();
            if (!prestamo || !interesInput) {
                return;
            }
            const fechaPagoSeleccionada = fechaPagoInput?.value || new Date().toISOString().slice(0, 10);
            const periodos = calcularMesesPendientes(prestamo, fechaPagoSeleccionada);
            const interesGenerado = prestamo.saldoCapital * (prestamo.tasa / 100) * periodos.meses;
            const interesPendiente = Math.max(0, prestamo.saldoInteres + interesGenerado);
            interesInput.value = interesPendiente.toFixed(2);
            interesInput.dataset.touched = '1';
        });
    }

    document.querySelectorAll('.verHistorialBtn').forEach((btn) => {
        btn.addEventListener('click', () => {
            const prestamoId = btn.dataset.id;
            const etiqueta = btn.dataset.label || `Préstamo #${prestamoId}`;
            abrirHistorialPeriodos(prestamoId, etiqueta);
        });
    });

    sincronizarCamposDeudor();
    actualizarUIporTipoPago();
    actualizarResumen();
    actualizarSugerenciaPago();
});
</script>
