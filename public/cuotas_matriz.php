<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$config = getConfiguracionGeneral($pdo);
$actividadCuotaId = (int) ($config['actividad_pago_cuota'] ?? 0);
$actividadCuota = $actividadCuotaId > 0 ? getActividad($pdo, $actividadCuotaId) : null;
$periodos = getPeriodosActivosConfiguracion($pdo);
$socios = getSocios($pdo, '', 'id_interno');

$condicionesPeriodo = [];
$params = [];
foreach ($periodos as $idx => $p) {
    $condicionesPeriodo[] = "(anio = :anio$idx AND mes = :mes$idx)";
    $params[":anio$idx"] = (int) $p['anio'];
    $params[":mes$idx"] = (int) $p['mes'];
}

$pagosPorSocio = [];
if ($actividadCuotaId > 0 && !empty($condicionesPeriodo)) {
    $params[':actividad'] = $actividadCuotaId;
    $sql = 'SELECT id_socio, anio, mes, quincena, SUM(valor) AS total'
        . ' FROM movimientos'
        . ' WHERE id_actividad = :actividad AND id_socio IS NOT NULL';
    if (!empty($condicionesPeriodo)) {
        $sql .= ' AND (' . implode(' OR ', $condicionesPeriodo) . ')';
    }
    $sql .= ' GROUP BY id_socio, anio, mes, quincena';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $socioId = (int) $row['id_socio'];
        $clave = sprintf('%04d-%02d', (int) $row['anio'], (int) $row['mes']);
        $quincena = in_array((int) $row['quincena'], [1, 2], true) ? (int) $row['quincena'] : 0;

        if (!isset($pagosPorSocio[$socioId])) {
            $pagosPorSocio[$socioId] = [];
        }
        if (!isset($pagosPorSocio[$socioId][$clave])) {
            $pagosPorSocio[$socioId][$clave] = ['quincenas' => [], 'total' => 0];
        }

        if (!isset($pagosPorSocio[$socioId][$clave]['quincenas'][$quincena])) {
            $pagosPorSocio[$socioId][$clave]['quincenas'][$quincena] = 0;
        }

        $pagosPorSocio[$socioId][$clave]['quincenas'][$quincena] += (float) $row['total'];
        $pagosPorSocio[$socioId][$clave]['total'] += (float) $row['total'];
    }
}


$fechasPeriodos = [];
$fechaFinMatriz = null;
foreach ($periodos as $p) {
    $clavePeriodo = sprintf('%04d-%02d', (int) $p['anio'], (int) $p['mes']);
    $fechaFinPeriodo = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', (int) $p['anio'], (int) $p['mes']));
    if (!$fechaFinPeriodo) {
        continue;
    }

    $fechaFinPeriodo->modify('last day of this month');
    $fechasPeriodos[$clavePeriodo] = $fechaFinPeriodo->format('Y-m-d');

    if ($fechaFinMatriz === null || $fechaFinPeriodo->format('Y-m-d') > $fechaFinMatriz) {
        $fechaFinMatriz = $fechaFinPeriodo->format('Y-m-d');
    }
}

function inicializarDetalleSocioMes(array &$destino, int $socioId, string $clave): void {
    if (!isset($destino[$socioId])) {
        $destino[$socioId] = [];
    }

    if (!isset($destino[$socioId][$clave])) {
        $destino[$socioId][$clave] = 0.0;
    }
}

$interesesPorSocio = [];
if (!empty($condicionesPeriodo)) {
    $paramsIntereses = [];
    $condicionesIntereses = [];

    foreach ($periodos as $idx => $p) {
        $condicionesIntereses[] = "(m.anio = :anio$idx AND m.mes = :mes$idx)";
        $paramsIntereses[":anio$idx"] = (int) $p['anio'];
        $paramsIntereses[":mes$idx"] = (int) $p['mes'];
    }

    $sqlIntereses = 'SELECT m.id_socio, m.anio, m.mes, SUM(ABS(m.valor)) AS total_interes'
        . ' FROM movimientos m'
        . ' JOIN actividades_maestro a ON m.id_actividad = a.id_actividad'
        . ' WHERE m.id_socio IS NOT NULL'
        . ' AND a.es_pago_interes = 1'
        . ' AND (' . implode(' OR ', $condicionesIntereses) . ')'
        . ' GROUP BY m.id_socio, m.anio, m.mes';

    $stmtIntereses = $pdo->prepare($sqlIntereses);
    $stmtIntereses->execute($paramsIntereses);

    foreach ($stmtIntereses->fetchAll() as $row) {
        $socioId = (int) $row['id_socio'];
        $clave = sprintf('%04d-%02d', (int) $row['anio'], (int) $row['mes']);
        inicializarDetalleSocioMes($interesesPorSocio, $socioId, $clave);
        $interesesPorSocio[$socioId][$clave] += (float) $row['total_interes'];
    }
}

$pollasPorSocio = [];
if (!empty($condicionesPeriodo)) {
    $paramsPollas = [];
    $condicionesPollas = [];

    foreach ($periodos as $idx => $p) {
        $condicionesPollas[] = "(m.anio = :anio$idx AND m.mes = :mes$idx)";
        $paramsPollas[":anio$idx"] = (int) $p['anio'];
        $paramsPollas[":mes$idx"] = (int) $p['mes'];
    }

    $sqlPollas = 'SELECT m.id_socio, m.anio, m.mes, SUM(ABS(m.valor)) AS total_polla'
        . ' FROM movimientos m'
        . ' JOIN actividades_maestro a ON m.id_actividad = a.id_actividad'
        . ' WHERE m.id_socio IS NOT NULL'
        . ' AND a.es_polla = 1'
        . ' AND (' . implode(' OR ', $condicionesPollas) . ')'
        . ' GROUP BY m.id_socio, m.anio, m.mes';

    $stmtPollas = $pdo->prepare($sqlPollas);
    $stmtPollas->execute($paramsPollas);

    foreach ($stmtPollas->fetchAll() as $row) {
        $socioId = (int) $row['id_socio'];
        $clave = sprintf('%04d-%02d', (int) $row['anio'], (int) $row['mes']);
        inicializarDetalleSocioMes($pollasPorSocio, $socioId, $clave);
        $pollasPorSocio[$socioId][$clave] += (float) $row['total_polla'];
    }
}

$saldosPrestamoPorSocio = [];
if ($fechaFinMatriz !== null && !empty($fechasPeriodos)) {
    $stmtPrestamosSocio = $pdo->prepare(
        'SELECT id_prestamo, id_socio, fecha_prestamo, monto_prestamo, saldo_capital_actual, estado'
        . ' FROM prestamos'
        . ' WHERE id_socio IS NOT NULL'
        . ' AND fecha_prestamo <= :fecha_fin'
    );
    $stmtPrestamosSocio->execute([':fecha_fin' => $fechaFinMatriz]);
    $prestamosSocio = $stmtPrestamosSocio->fetchAll();

    $prestamosPorId = [];
    foreach ($prestamosSocio as $prestamo) {
        $prestamosPorId[(int) $prestamo['id_prestamo']] = $prestamo;
    }

    $saldosHistoricos = [];
    if (!empty($prestamosPorId) && !empty($condicionesPeriodo)) {
        $paramsPeriodosPrestamo = [];
        $condicionesPeriodosPrestamo = [];

        foreach ($periodos as $idx => $p) {
            $condicionesPeriodosPrestamo[] = "(pp.anio = :anio$idx AND pp.mes = :mes$idx)";
            $paramsPeriodosPrestamo[":anio$idx"] = (int) $p['anio'];
            $paramsPeriodosPrestamo[":mes$idx"] = (int) $p['mes'];
        }

        $sqlPeriodosPrestamo = 'SELECT pp.id_prestamo, pp.anio, pp.mes, pp.capital_final'
            . ' FROM periodos_prestamo pp'
            . ' JOIN prestamos p ON p.id_prestamo = pp.id_prestamo'
            . ' WHERE p.id_socio IS NOT NULL'
            . ' AND (' . implode(' OR ', $condicionesPeriodosPrestamo) . ')';

        $stmtPeriodosPrestamo = $pdo->prepare($sqlPeriodosPrestamo);
        $stmtPeriodosPrestamo->execute($paramsPeriodosPrestamo);

        foreach ($stmtPeriodosPrestamo->fetchAll() as $row) {
            $idPrestamo = (int) $row['id_prestamo'];
            $clave = sprintf('%04d-%02d', (int) $row['anio'], (int) $row['mes']);

            if (!isset($saldosHistoricos[$idPrestamo])) {
                $saldosHistoricos[$idPrestamo] = [];
            }

            $saldosHistoricos[$idPrestamo][$clave] = (float) $row['capital_final'];
        }
    }

    $abonosCapitalPorPrestamo = [];
    if (!empty($prestamosPorId)) {
        $stmtAbonosCapital = $pdo->prepare(
            'SELECT m.id_prestamo, m.fecha, ABS(m.valor) AS valor_capital'
            . ' FROM movimientos m'
            . ' JOIN actividades_maestro a ON m.id_actividad = a.id_actividad'
            . ' JOIN prestamos p ON p.id_prestamo = m.id_prestamo'
            . ' WHERE p.id_socio IS NOT NULL'
            . ' AND a.es_pago_prestamo = 1'
            . ' AND m.fecha <= :fecha_fin'
            . ' ORDER BY m.id_prestamo, m.fecha'
        );
        $stmtAbonosCapital->execute([':fecha_fin' => $fechaFinMatriz]);

        foreach ($stmtAbonosCapital->fetchAll() as $row) {
            $idPrestamo = (int) $row['id_prestamo'];

            if (!isset($abonosCapitalPorPrestamo[$idPrestamo])) {
                $abonosCapitalPorPrestamo[$idPrestamo] = [];
            }

            $abonosCapitalPorPrestamo[$idPrestamo][] = [
                'fecha' => (string) $row['fecha'],
                'valor' => (float) $row['valor_capital'],
            ];
        }
    }

    foreach ($prestamosSocio as $prestamo) {
        $idPrestamo = (int) $prestamo['id_prestamo'];
        $socioId = (int) $prestamo['id_socio'];
        $fechaPrestamo = (string) $prestamo['fecha_prestamo'];
        $montoPrestamo = (float) $prestamo['monto_prestamo'];
        $abonosPrestamo = $abonosCapitalPorPrestamo[$idPrestamo] ?? [];

        foreach ($fechasPeriodos as $clave => $fechaFinPeriodo) {
            if ($fechaPrestamo > $fechaFinPeriodo) {
                continue;
            }

            if (isset($saldosHistoricos[$idPrestamo][$clave])) {
                $saldoPeriodo = (float) $saldosHistoricos[$idPrestamo][$clave];
            } else {
                $capitalPagado = 0.0;
                foreach ($abonosPrestamo as $abono) {
                    if ($abono['fecha'] <= $fechaFinPeriodo) {
                        $capitalPagado += (float) $abono['valor'];
                    }
                }

                $saldoPeriodo = max(0, $montoPrestamo - $capitalPagado);
            }

            if ($saldoPeriodo <= 0.01) {
                continue;
            }

            inicializarDetalleSocioMes($saldosPrestamoPorSocio, $socioId, $clave);
            $saldosPrestamoPorSocio[$socioId][$clave] += $saldoPeriodo;
        }
    }
}

function formatearMonedaCuotas(float $valor): string {
    $prefijo = $valor < 0 ? '-' : '';
    return $prefijo . '$' . number_format(abs($valor), 0, ',', '.');
}
?>

<h2 class="mb-3 d-flex align-items-center gap-2">
    <i class="bi bi-grid-3x3-gap"></i>
    <span>Matriz de pago de cuotas</span>
</h2>

<div class="card mb-3">
    <div class="card-body d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <p class="mb-1">Configura un concepto del maestro de actividades para registrar los pagos de cuota de socio y visualiza el detalle por periodo.</p>
            <div class="text-muted small">Solo se muestran los periodos marcados como activos en Configuración &gt; Periodos.</div>
        </div>
        <?php if ($actividadCuota): ?>
            <div class="text-end">
                <div class="fw-semibold">Concepto configurado</div>
                <div class="badge bg-light text-dark">#<?php echo (int) $actividadCuota['id_actividad']; ?> - <?php echo clean($actividadCuota['nombre_actividad']); ?></div>
            </div>
        <?php else: ?>
            <div class="alert alert-warning mb-0" role="alert">
                Define un concepto en Configuración para habilitar la matriz.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!$actividadCuota): ?>
    <div class="alert alert-info">Selecciona un concepto válido en la Configuración para consultar la matriz de cuotas.</div>
<?php elseif (empty($periodos)): ?>
    <div class="alert alert-info">Aún no hay periodos activos configurados. Agrégalos desde Configuración &gt; Periodos.</div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-light">
                <tr>
                    <th style="min-width: 200px;">Socio</th>
                    <?php foreach ($periodos as $p): ?>
                        <?php $label = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', (int) $p['anio'], (int) $p['mes'])); ?>
                        <th class="text-end"><?php echo $label ? $label->format('M Y') : sprintf('%02d/%04d', $p['mes'], $p['anio']); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($socios as $s): ?>
                    <?php $socioPeriodos = $pagosPorSocio[(int) $s['id_socio']] ?? []; ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?php echo clean($s['nombre_completo']); ?></div>
                            <div class="text-muted small">ID interno: <?php echo $s['id_interno'] !== null ? str_pad((string) $s['id_interno'], 2, '0', STR_PAD_LEFT) : '—'; ?></div>
                            <div class="text-muted small">ID: <?php echo (int) $s['id_socio']; ?></div>
                        </td>
                        <?php foreach ($periodos as $p): ?>
                            <?php $clave = sprintf('%04d-%02d', (int) $p['anio'], (int) $p['mes']); ?>
                            <?php $valor = $socioPeriodos[$clave] ?? ['quincenas' => [], 'total' => 0.0]; ?>
                            <?php $quincenas = $valor['quincenas'] ?? []; ?>
                            <?php ksort($quincenas); ?>
                            <?php
                            $saldoPrestamo = $saldosPrestamoPorSocio[(int) $s['id_socio']][$clave] ?? null;
                            $interesPagado = $interesesPorSocio[(int) $s['id_socio']][$clave] ?? null;
                            $pollaPagada = $pollasPorSocio[(int) $s['id_socio']][$clave] ?? null;
                            ?>
                            <td class="text-end align-middle">
                                <div class="small">
                                    <div class="mb-1">
                                        <div class="fw-semibold">Cuota:</div>
                                        <?php if (!empty($quincenas)): ?>
                                            <?php foreach ([1, 2] as $q): if (!empty($quincenas[$q])): ?>
                                                <div>Q<?php echo $q; ?>: <?php echo formatearMonedaCuotas((float) $quincenas[$q]); ?></div>
                                            <?php endif; endforeach; ?>
                                            <?php if (!empty($quincenas[0])): ?>
                                                <div>Sin quincena: <?php echo formatearMonedaCuotas((float) $quincenas[0]); ?></div>
                                            <?php endif; ?>
                                            <div class="fw-semibold mt-1">Total: <?php echo formatearMonedaCuotas((float) $valor['total']); ?></div>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="mt-2">
                                        <span class="fw-semibold">Préstamo:</span>
                                        <?php if ($saldoPrestamo !== null && (float) $saldoPrestamo > 0.01): ?>
                                            <?php echo formatearMonedaCuotas((float) $saldoPrestamo); ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </div>

                                    <div>
                                        <span class="fw-semibold">Intereses:</span>
                                        <?php if ($interesPagado !== null && (float) $interesPagado > 0.01): ?>
                                            <?php echo formatearMonedaCuotas((float) $interesPagado); ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </div>

                                    <div>
                                        <span class="fw-semibold">Polla:</span>
                                        <?php if ($pollaPagada !== null && (float) $pollaPagada > 0.01): ?>
                                            <span class="text-success"><?php echo formatearMonedaCuotas((float) $pollaPagada); ?></span>
                                        <?php else: ?>
                                            <span class="text-danger">No ha pagado</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
