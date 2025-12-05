<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$configGeneral = getConfiguracionGeneral($pdo);
$logoPath = !empty($configGeneral['logo_archivo'])
    ? '../public/assets/uploads/' . $configGeneral['logo_archivo']
    : null;

$prestamos = $pdo->query(
    "SELECT p.*, s.nombre_completo, aval.nombre_completo AS nombre_aval" .
    " FROM prestamos p" .
    " LEFT JOIN socios s ON p.id_socio = s.id_socio" .
    " LEFT JOIN socios aval ON p.id_socio_aval = aval.id_socio" .
    " ORDER BY p.fecha_prestamo DESC"
)->fetchAll();

$matrices = [];
foreach ($prestamos as $prestamo) {
    $matrices[$prestamo['id_prestamo']] = construirMatrizMovimientosPrestamo($pdo, $prestamo);
}
$historialPeriodos = [];
foreach ($prestamos as $prestamo) {
    $historialPeriodos[$prestamo['id_prestamo']] = obtenerHistorialPeriodosPrestamo($pdo, (int) $prestamo['id_prestamo']);
}

function formatearMoneda(float $valor): string {
    $prefijo = $valor < 0 ? '-' : '';
    return $prefijo . '$' . number_format(abs($valor), 0, ',', '.');
}
?>

<h2 class="mb-3 d-flex align-items-center gap-2">
    <i class="bi bi-table"></i>
    <span>Control mensual de préstamos</span>
</h2>
<div class="card mb-3">
    <div class="card-body">
        <p class="mb-2">
            La matriz parametrizada consolida los movimientos de cada préstamo entre diciembre de 2025 y noviembre de 2026,
            aplicando exactamente las reglas definidas en el Maestro de Actividades. Cada celda refleja el valor registrado
            con el signo configurado y marca su estado como <strong>Pagado</strong>, <strong>Pendiente</strong> o
            <strong>Futuro</strong> según el período.
        </p>
        <div class="small text-muted">La lógica financiera (suma/resta/neutral) proviene de las columnas de parametrización; no se realizan cálculos manuales adicionales.</div>
    </div>
</div>

<?php foreach ($prestamos as $prestamo): $matriz = $matrices[$prestamo['id_prestamo']] ?? ['periodos' => [], 'filas' => [], 'totales' => []]; ?>
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center category-prestamos">
            <div class="d-flex flex-column">
                <span class="fw-semibold">Préstamo #<?php echo $prestamo['id_prestamo']; ?></span>
                <small class="text-muted">
                    <?php echo $prestamo['es_particular'] ? 'Tercero: '.clean($prestamo['nombre_deudor']).' (Aval: '.clean($prestamo['nombre_aval'] ?? 'N/A').')' : 'Socio: '.clean($prestamo['nombre_completo']); ?>
                </small>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <span class="badge bg-light text-dark">Capital pendiente: <?php echo formatearMoneda((float) $prestamo['saldo_capital_actual']); ?></span>
                <span class="badge bg-light text-dark">Intereses pendientes: <?php echo formatearMoneda((float) $prestamo['saldo_intereses_actual']); ?></span>
                <a class="btn btn-outline-secondary btn-sm" href="../actions/export_matriz_prestamo.php?id_prestamo=<?php echo $prestamo['id_prestamo']; ?>&formato=excel">
                    <i class="bi bi-file-earmark-spreadsheet"></i> Exportar Excel
                </a>
                <a class="btn btn-outline-secondary btn-sm" href="../actions/export_matriz_prestamo.php?id_prestamo=<?php echo $prestamo['id_prestamo']; ?>&formato=pdf">
                    <i class="bi bi-file-earmark-pdf"></i> Exportar PDF
                </a>
                <a class="btn btn-outline-primary btn-sm" href="../actions/export_estado_prestamo_pdf.php?id_prestamo=<?php echo $prestamo['id_prestamo']; ?>">
                    <i class="bi bi-clipboard2-data"></i> Informe de estado
                </a>
            </div>
        </div>
        <div class="card-body">
            <div class="row mb-3 text-muted small">
                <div class="col-md-3">Fecha préstamo: <span class="fw-semibold"><?php echo clean($prestamo['fecha_prestamo']); ?></span></div>
                <div class="col-md-3">Tasa interés: <span class="fw-semibold"><?php echo clean($prestamo['tasa_interes']); ?>%</span></div>
                <div class="col-md-3">Estado: <span class="fw-semibold"><?php echo clean(ucfirst($prestamo['estado'])); ?></span></div>
                <div class="col-md-3">Totales de la matriz: <span class="fw-semibold">Capital <?php echo formatearMoneda($matriz['totales']['capital'] ?? 0); ?> | Intereses <?php echo formatearMoneda($matriz['totales']['intereses'] ?? 0); ?></span></div>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="min-width: 180px;">Concepto</th>
                            <?php foreach ($matriz['periodos'] as $periodo): ?>
                                <th class="text-end"><?php echo clean($periodo['label']); ?></th>
                            <?php endforeach; ?>
                            <th class="text-end">Saldo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($matriz['filas'] as $fila): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo clean($fila['actividad']['nombre_actividad'] ?? 'Concepto'); ?></div>
                                    <div class="small text-muted">Signo: <?php echo obtenerSignoActividad($fila['actividad'] ?? []) === -1 ? 'Resta' : (obtenerSignoActividad($fila['actividad'] ?? []) === 1 ? 'Suma' : 'Neutral'); ?></div>
                                </td>
                                <?php foreach ($matriz['periodos'] as $periodo): $clave = sprintf('%04d-%02d', $periodo['anio'], $periodo['mes']); $celda = $fila['meses'][$clave] ?? ['valor' => 0, 'tiene_movimiento' => false, 'estado' => '']; ?>
                                    <td class="text-end">
                                        <?php echo $celda['valor'] !== 0.0 ? formatearMoneda((float) $celda['valor']) : '-'; ?>
                                        <div class="small text-muted"><?php echo $celda['estado']; ?></div>
                                    </td>
                                <?php endforeach; ?>
                                <td class="text-end fw-semibold <?php echo ($fila['saldo'] ?? 0) < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo formatearMoneda((float) ($fila['saldo'] ?? 0)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php $historial = $historialPeriodos[$prestamo['id_prestamo']] ?? []; ?>
            <div class="mt-4">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="bi bi-clock-history"></i>
                    <h6 class="mb-0">Histórico de pagos y abonos</h6>
                </div>
                <?php if (!empty($historial)): ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Mes/Año</th>
                                    <th class="text-end">Capital inicio</th>
                                    <th class="text-end">Interés causado</th>
                                    <th class="text-end">Interés pagado</th>
                                    <th class="text-end">Abono capital</th>
                                    <th class="text-end">Capital final</th>
                                    <th>Estado</th>
                                    <th class="text-end">Registrado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($historial as $registro): ?>
                                    <tr>
                                        <td><?php echo sprintf('%02d/%04d', (int) $registro['mes'], (int) $registro['anio']); ?></td>
                                        <td class="text-end"><?php echo formatearMoneda((float) $registro['capital_inicio']); ?></td>
                                        <td class="text-end"><?php echo formatearMoneda((float) $registro['interes_causado']); ?></td>
                                        <td class="text-end"><?php echo formatearMoneda((float) $registro['interes_pagado']); ?></td>
                                        <td class="text-end"><?php echo formatearMoneda((float) $registro['abono_capital']); ?></td>
                                        <td class="text-end"><?php echo formatearMoneda((float) $registro['capital_final']); ?></td>
                                        <td><?php echo clean($registro['estado'] ?? ''); ?></td>
                                        <td class="text-end"><span class="text-muted small"><?php echo clean($registro['fecha_registro']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-muted small">Aún no hay trazabilidad registrada para este préstamo.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
