<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$totalSocios = (int) ($pdo->query("SELECT COUNT(*) AS total FROM socios WHERE activo = 1")->fetch()['total'] ?? 0);

$sociosSaldoNegativo = (int) ($pdo->query("SELECT COUNT(*) AS total FROM socios WHERE activo = 1 AND saldo_socio < 0")->fetch()['total'] ?? 0);
$prestamosEstados = $pdo->query("SELECT estado, COUNT(*) AS total FROM prestamos WHERE estado IN ('Activo', 'En mora') GROUP BY estado")->fetchAll(PDO::FETCH_KEY_PAIR);
$prestamosActivos = (int) ($prestamosEstados['Activo'] ?? 0);
$prestamosEnMora = (int) ($prestamosEstados['En mora'] ?? 0);
$totalPrestamosSeguimiento = $prestamosActivos + $prestamosEnMora;
$porcentajePrestamosActivos = $totalPrestamosSeguimiento > 0 ? ($prestamosActivos / $totalPrestamosSeguimiento) * 100 : 0;
$porcentajePrestamosMora = $totalPrestamosSeguimiento > 0 ? ($prestamosEnMora / $totalPrestamosSeguimiento) * 100 : 0;

$condicionAportePromedio = "m.es_ingreso = 1 AND COALESCE(a.es_prestamo,0) = 0 AND COALESCE(a.es_pago_prestamo,0) = 0 AND COALESCE(a.es_pago_interes,0) = 0 AND COALESCE(a.es_polla,0) = 0";
$aportePromedioSociosStmt = $pdo->query(
    "SELECT COALESCE(AVG(aporte_promedio), 0) AS promedio_general\n"
    . "FROM (\n"
    . "    SELECT CASE\n"
    . "        WHEN COUNT(DISTINCT CASE WHEN $condicionAportePromedio THEN DATE_FORMAT(m.fecha, '%Y-%m') END) > 0 THEN\n"
    . "            SUM(CASE WHEN $condicionAportePromedio THEN m.valor ELSE 0 END) / COUNT(DISTINCT CASE WHEN $condicionAportePromedio THEN DATE_FORMAT(m.fecha, '%Y-%m') END)\n"
    . "        ELSE 0\n"
    . "    END AS aporte_promedio\n"
    . "    FROM socios s\n"
    . "    LEFT JOIN movimientos m ON m.id_socio = s.id_socio\n"
    . "    LEFT JOIN actividades_maestro a ON m.id_actividad = a.id_actividad\n"
    . "    WHERE s.activo = 1\n"
    . "    GROUP BY s.id_socio\n"
    . ") aportes_socios"
);
$aportePromedioMensualSocio = (float) ($aportePromedioSociosStmt->fetch()['promedio_general'] ?? 0);

$movimientosContablesStmt = $pdo->query("
    WITH mov_clasificado AS (
        SELECT
            m.id_movimiento,
            m.modulo,
            m.valor,
            CASE WHEN a.es_polla = 1 THEN 0 ELSE
                CASE a.afecta_saldo_socio
                    WHEN 'suma' THEN ABS(m.valor)
                    WHEN 'resta' THEN -ABS(m.valor)
                    ELSE 0
                END
            END AS valor_socio,
            CASE a.afecta_saldo_natillera
                WHEN 'suma' THEN ABS(m.valor)
                WHEN 'resta' THEN -ABS(m.valor)
                ELSE 0
            END AS valor_natillera,
            a.es_prestamo,
            a.es_pago_prestamo,
            a.es_pago_interes,
            a.es_polla,
            a.es_gasto_general,
            a.es_rifa
        FROM movimientos m
        JOIN actividades_maestro a ON m.id_actividad = a.id_actividad
    )
    SELECT
        *,
        CASE
            WHEN valor_natillera > 0 THEN 'ingreso'
            WHEN valor_natillera < 0 THEN 'egreso'
            ELSE 'neutral'
        END AS tipo_contable,
        CASE
            WHEN es_polla = 1 THEN 'pollas'
            WHEN es_prestamo = 1 OR es_pago_prestamo = 1 OR es_pago_interes = 1 THEN 'prestamos'
            WHEN es_rifa = 1 OR modulo = 'rifas' THEN 'rifas'
            ELSE 'natillera'
        END AS actividad
    FROM mov_clasificado
    WHERE valor_natillera <> 0
");
$movimientosContables = $movimientosContablesStmt->fetchAll(PDO::FETCH_ASSOC);

$estadoResultados = [
    'ingresos' => [
        'cuotas' => 0.0,
        'intereses' => 0.0,
        'pollas' => 0.0,
        'rifas' => 0.0,
        'otros' => 0.0,
    ],
    'egresos' => [
        'prestamos' => 0.0,
        'premios_rifas' => 0.0,
        'gastos' => 0.0,
        'otros' => 0.0,
    ],
];

$flujoActividades = [
    'natillera' => ['ingresos' => 0.0, 'egresos' => 0.0],
    'rifas' => ['ingresos' => 0.0, 'egresos' => 0.0],
    'pollas' => ['ingresos' => 0.0, 'egresos' => 0.0],
    'prestamos' => ['ingresos' => 0.0, 'egresos' => 0.0],
];

$totalIngresos = 0.0;
$totalEgresos = 0.0;
$saldoNatillera = 0.0;

foreach ($movimientosContables as $mov) {
    $valorNatillera = (float) ($mov['valor_natillera'] ?? 0);
    $valorAbsoluto = abs($valorNatillera);
    $tipoContable = $mov['tipo_contable'];
    $actividad = $mov['actividad'];

    $saldoNatillera += $valorNatillera;

    if ($tipoContable === 'ingreso') {
        $totalIngresos += $valorAbsoluto;
        $flujoActividades[$actividad]['ingresos'] += $valorAbsoluto;

        if ($actividad === 'pollas') {
            $estadoResultados['ingresos']['pollas'] += $valorAbsoluto;
            continue;
        }
        if ($actividad === 'rifas') {
            $estadoResultados['ingresos']['rifas'] += $valorAbsoluto;
            continue;
        }
        if ((int) $mov['es_pago_interes'] === 1 || ((int) $mov['es_pago_prestamo'] === 1 && (float) $mov['valor_socio'] === 0.0)) {
            $estadoResultados['ingresos']['intereses'] += $valorAbsoluto;
            continue;
        }

        if ($actividad === 'natillera'
            && (int) $mov['es_prestamo'] === 0
            && (int) $mov['es_pago_prestamo'] === 0
            && (int) $mov['es_pago_interes'] === 0
            && (int) $mov['es_polla'] === 0
            && (int) $mov['es_gasto_general'] === 0
            && (int) $mov['es_rifa'] === 0
            && ($mov['modulo'] ?? '') !== 'rifas') {
            $estadoResultados['ingresos']['cuotas'] += $valorAbsoluto;
            continue;
        }

        $estadoResultados['ingresos']['otros'] += $valorAbsoluto;
        continue;
    }

    if ($tipoContable === 'egreso') {
        $totalEgresos += $valorAbsoluto;
        $flujoActividades[$actividad]['egresos'] += $valorAbsoluto;

        if ((int) $mov['es_prestamo'] === 1) {
            $estadoResultados['egresos']['prestamos'] += $valorAbsoluto;
            continue;
        }
        if ($actividad === 'rifas') {
            $estadoResultados['egresos']['premios_rifas'] += $valorAbsoluto;
            continue;
        }
        if ((int) $mov['es_gasto_general'] === 1) {
            $estadoResultados['egresos']['gastos'] += $valorAbsoluto;
            continue;
        }

        $estadoResultados['egresos']['otros'] += $valorAbsoluto;
    }
}

$totalCuotas = $estadoResultados['ingresos']['cuotas'];
$totalInteresesPrestamo = $estadoResultados['ingresos']['intereses'];
$totalPollas = $estadoResultados['ingresos']['pollas'];
$totalRifasIngresos = $estadoResultados['ingresos']['rifas'];
$otrosIngresos = $estadoResultados['ingresos']['otros'];

$totalPrestado = $estadoResultados['egresos']['prestamos'];
$totalPremiosRifas = $estadoResultados['egresos']['premios_rifas'];
$totalGastos = $estadoResultados['egresos']['gastos'];
$otrosEgresos = $estadoResultados['egresos']['otros'];

$totalPrestamoRecuperado = 0.0;
foreach ($movimientosContables as $mov) {
    if ((int) $mov['es_pago_prestamo'] === 1 && (float) $mov['valor_socio'] !== 0.0 && (float) $mov['valor_natillera'] > 0) {
        $totalPrestamoRecuperado += (float) $mov['valor_natillera'];
    }
}

$resultadoNeto = $totalIngresos - $totalEgresos;
$saldoNatilleraGuardado = getSaldoNatillera($pdo);
$validacionSaldo = abs($saldoNatillera - $resultadoNeto) < 0.01;

$resultadoPorActividadSuma = 0.0;
foreach ($flujoActividades as $totalesActividad) {
    $resultadoPorActividadSuma += $totalesActividad['ingresos'] - $totalesActividad['egresos'];
}
$validacionActividad = abs($resultadoPorActividadSuma - $resultadoNeto) < 0.01;

$chartLabels = ['Cuotas', 'Pollas', 'Rifas (ingresos)', 'Intereses', 'Premios rifas (egreso)', 'Otros ingresos'];
$chartDataset = [
    $totalCuotas,
    $totalPollas,
    $totalRifasIngresos,
    $totalInteresesPrestamo,
    -$totalPremiosRifas,
    $otrosIngresos,
];

$resumenActividadStmt = $pdo->query("
    WITH mov_signado AS (
        SELECT m.modulo,
               CASE a.afecta_saldo_natillera WHEN 'suma' THEN ABS(m.valor) WHEN 'resta' THEN -ABS(m.valor) ELSE 0 END AS valor_natillera,
               a.es_prestamo, a.es_pago_prestamo, a.es_pago_interes, a.es_polla, a.es_rifa
        FROM movimientos m
        JOIN actividades_maestro a ON m.id_actividad = a.id_actividad
    )
    SELECT
        CASE
            WHEN es_polla = 1 THEN 'Pollas'
            WHEN es_prestamo = 1 OR es_pago_prestamo = 1 OR es_pago_interes = 1 THEN 'Préstamos'
            WHEN es_rifa = 1 OR modulo = 'rifas' THEN 'Rifas'
            ELSE 'Natillera'
        END AS actividad,
        COALESCE(SUM(CASE WHEN valor_natillera > 0 THEN valor_natillera END),0) AS ingresos,
        COALESCE(SUM(CASE WHEN valor_natillera < 0 THEN -valor_natillera END),0) AS egresos
    FROM mov_signado
    GROUP BY actividad
");

foreach ($resumenActividadStmt->fetchAll(PDO::FETCH_ASSOC) as $actividadItem) {
    $nombreActividad = $actividadItem['actividad'];
    if (!isset($flujoActividades[$nombreActividad])) {
        continue;
    }
    $flujoActividades[$nombreActividad]['ingresos'] = (float) ($actividadItem['ingresos'] ?? 0);
    $flujoActividades[$nombreActividad]['egresos'] = (float) ($actividadItem['egresos'] ?? 0);
}


$detalleActividadesStmt = $pdo->query("
    SELECT
        a.id_actividad,
        a.nombre_actividad,
        COALESCE(SUM(CASE
            WHEN a.afecta_saldo_natillera = 'suma' THEN ABS(m.valor)
            ELSE 0
        END), 0) AS ingresos,
        COALESCE(SUM(CASE
            WHEN a.afecta_saldo_natillera = 'resta' THEN ABS(m.valor)
            ELSE 0
        END), 0) AS egresos,
        COUNT(m.id_movimiento) AS movimientos,
        COALESCE(SUM(CASE
            WHEN a.afecta_saldo_natillera IN ('suma', 'resta') THEN ABS(m.valor)
            ELSE 0
        END), 0) AS total_movimiento
    FROM actividades_maestro a
    LEFT JOIN movimientos m ON m.id_actividad = a.id_actividad
    WHERE a.activo = 1
    GROUP BY a.id_actividad, a.nombre_actividad, a.afecta_saldo_natillera
    ORDER BY total_movimiento DESC, a.nombre_actividad ASC
");
$detalleActividades = $detalleActividadesStmt->fetchAll(PDO::FETCH_ASSOC);

$socios = getSocios($pdo);
$actividades = getActividades($pdo, false, true);

$filtroSocio = isset($_GET['socio']) ? (int) $_GET['socio'] : 0;
$filtroActividad = isset($_GET['actividad']) ? (int) $_GET['actividad'] : 0;
$filtroFechaIni = $_GET['desde'] ?? '';
$filtroFechaFin = $_GET['hasta'] ?? '';
$filtroResumen = $_GET['resumen'] ?? '';

$where = [];
$params = [];
if ($filtroSocio) { $where[] = 'm.id_socio = :s'; $params[':s'] = $filtroSocio; }
if ($filtroActividad) { $where[] = 'm.id_actividad = :a'; $params[':a'] = $filtroActividad; }
if ($filtroFechaIni) { $where[] = 'm.fecha >= :fi'; $params[':fi'] = $filtroFechaIni; }
if ($filtroFechaFin) { $where[] = 'm.fecha <= :ff'; $params[':ff'] = $filtroFechaFin; }

$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sqlWhereResumen = '';
if ($filtroResumen === 'otras') {
    $sqlWhereResumen = "WHERE valor_natillera > 0
        AND es_polla = 0
        AND es_pago_prestamo = 0
        AND es_pago_interes = 0
        AND NOT (es_prestamo = 0 AND es_pago_prestamo = 0 AND es_pago_interes = 0 AND es_polla = 0 AND es_gasto_general = 0)";
}

$movimientosStmt = $pdo->prepare("
    WITH mov_filtrado AS (
        SELECT m.id_movimiento, m.fecha, m.valor, m.id_socio, m.id_actividad, m.modulo, m.observaciones,
               s.nombre_completo, a.nombre_actividad, a.afecta_saldo_socio, a.afecta_saldo_natillera,
               a.es_prestamo, a.es_pago_prestamo, a.es_pago_interes, a.es_polla, a.es_gasto_general,
               COALESCE(mp.nombre, m.medio_consignacion) AS medio_nombre
        FROM movimientos m
        LEFT JOIN socios s ON m.id_socio = s.id_socio
        JOIN actividades_maestro a ON m.id_actividad = a.id_actividad
        LEFT JOIN medios_pago mp ON m.id_medio_pago = mp.id
        $sqlWhere
    ), mov_signado AS (
        SELECT mov_filtrado.*,
               CASE WHEN mov_filtrado.es_polla = 1 THEN 0 ELSE
                    CASE mov_filtrado.afecta_saldo_socio
                        WHEN 'suma' THEN ABS(mov_filtrado.valor)
                        WHEN 'resta' THEN -ABS(mov_filtrado.valor)
                        ELSE 0
                    END
               END AS valor_socio,
               CASE mov_filtrado.afecta_saldo_natillera
                    WHEN 'suma' THEN ABS(mov_filtrado.valor)
                    WHEN 'resta' THEN -ABS(mov_filtrado.valor)
                    ELSE 0 END AS valor_natillera
        FROM mov_filtrado
    ), calculado AS (
        SELECT mov_signado.*,
               SUM(mov_signado.valor_natillera) OVER (ORDER BY mov_signado.fecha, mov_signado.id_movimiento ROWS UNBOUNDED PRECEDING) AS saldo_natillera,
               CASE WHEN mov_signado.id_socio IS NOT NULL THEN
                    SUM(mov_signado.valor_socio) OVER (PARTITION BY mov_signado.id_socio ORDER BY mov_signado.fecha, mov_signado.id_movimiento ROWS UNBOUNDED PRECEDING)
               END AS saldo_socio,
               SUM(mov_signado.valor_natillera) OVER (ORDER BY mov_signado.fecha, mov_signado.id_movimiento ROWS UNBOUNDED PRECEDING) AS saldo_general
        FROM mov_signado
    )
    SELECT * FROM calculado
    $sqlWhereResumen
    ORDER BY id_movimiento DESC
");
$movimientosStmt->execute($params);
$movimientos = $movimientosStmt->fetchAll();
$totalMovimientosConsolidado = count($movimientos);
$totalIngresosConsolidado = 0.0;
$totalEgresosConsolidado = 0.0;
foreach ($movimientos as $movimientoConsolidado) {
    $valorConsolidado = (float) ($movimientoConsolidado['valor_natillera'] ?? 0);
    if ($valorConsolidado > 0) {
        $totalIngresosConsolidado += $valorConsolidado;
    } elseif ($valorConsolidado < 0) {
        $totalEgresosConsolidado += abs($valorConsolidado);
    }
}
?>
<div class="mt-2">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <p class="text-muted small mb-1">Resumen general</p>
            <h1 class="h4 mb-0 d-flex align-items-center gap-2"><i class="bi bi-speedometer2 text-primary"></i> <span>Panel principal</span></h1>
        </div>
        <a class="btn btn-outline-primary btn-icon" href="../actions/export_csv.php?tipo=saldos"><span><i class="bi bi-download"></i> Exportar saldos</span></a>
    </div>
    <?php if ($filtroResumen === 'otras'): ?>
        <div class="alert alert-info py-2">
            Mostrando únicamente movimientos clasificados como <strong>Otras actividades</strong>.
            <a href="index.php" class="alert-link">Quitar filtro</a>
        </div>
    <?php endif; ?>
    <div class="row g-4">
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-people-fill text-primary"></i><span>Socios activos</span></div>
                <div class="card-body">
                    <p class="text-muted mb-1">Socios activos</p>
                    <h2 class="display-6 mb-0"><?php echo $totalSocios; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-header category-ingresos"><i class="bi bi-cash-stack"></i><span>Cuotas acumuladas</span></div>
                <div class="card-body">
                    <p class="text-muted mb-1">Total por cuotas</p>
                    <h2 class="display-6 mb-0">$<?php echo number_format($totalCuotas, 0, ',', '.'); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-header category-prestamos"><i class="bi bi-bank"></i><span>Total prestado</span></div>
                <div class="card-body">
                    <p class="text-muted mb-1">Desembolsos registrados</p>
                    <h2 class="display-6 mb-0">$<?php echo number_format($totalPrestado, 0, ',', '.'); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-header category-gastos"><i class="bi bi-wallet2"></i><span>Saldo natillera</span></div>
                <div class="card-body">
                    <p class="text-muted mb-1">Saldo general (auditable)</p>
                    <button class="btn btn-link p-0 text-decoration-none" type="button" data-bs-toggle="collapse" data-bs-target="#saldoNatilleraDetalle" aria-expanded="false" aria-controls="saldoNatilleraDetalle">
                        <h2 class="display-6 mb-0 text-start">$<?php echo number_format($saldoNatillera, 0, ',', '.'); ?></h2>
                    </button>
                    <?php if ($isAdmin): ?>
                        <div class="small text-muted mt-2">Debug saldo guardado: <strong>$<?php echo number_format($saldoNatilleraGuardado, 0, ',', '.'); ?></strong></div>
                    <?php endif; ?>
                    <div class="collapse mt-2" id="saldoNatilleraDetalle">
                        <div class="small text-muted">Fórmula: <strong>Saldo = Total ingresos - Total egresos</strong></div>
                        <div class="small text-muted">$<?php echo number_format($totalIngresos, 0, ',', '.'); ?> - $<?php echo number_format($totalEgresos, 0, ',', '.'); ?> = $<?php echo number_format($resultadoNeto, 0, ',', '.'); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="row g-4 mt-1">
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-header category-prestamos"><i class="bi bi-arrow-down-circle"></i><span>Capital recuperado</span></div>
                <div class="card-body">
                    <p class="text-muted mb-1">Pagos a capital</p>
                    <h2 class="display-6 mb-0">$<?php echo number_format($totalPrestamoRecuperado, 0, ',', '.'); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-header category-prestamos"><i class="bi bi-percent"></i><span>Intereses préstamos</span></div>
                <div class="card-body">
                    <p class="text-muted mb-1">Intereses acumulados</p>
                    <h2 class="display-6 mb-0">$<?php echo number_format($totalInteresesPrestamo, 0, ',', '.'); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-header category-pollas"><i class="bi bi-trophy"></i><span>Ingresos de pollas</span></div>
                <div class="card-body">
                    <p class="text-muted mb-1">Total registrado</p>
                    <h2 class="display-6 mb-0">$<?php echo number_format($totalPollas, 0, ',', '.'); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-header category-ingresos"><i class="bi bi-ticket-perforated"></i><span>Ingresos de rifas</span></div>
                <div class="card-body">
                    <p class="text-muted mb-1">Recaudo registrado en rifas</p>
                    <h2 class="display-6 mb-0">$<?php echo number_format($totalRifasIngresos, 0, ',', '.'); ?></h2>
                </div>
            </div>
        </div>
    </div>
    <?php if (!$validacionSaldo): ?>
        <div class="alert alert-danger mt-3 d-flex align-items-center gap-2" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span>Inconsistencia contable: el saldo mostrado no coincide con Total ingresos - Total egresos.</span>
        </div>
    <?php endif; ?>
    <div class="row g-4 mt-1">
        <div class="col-md-4">
            <div class="card h-100 border-warning-subtle">
                <div class="card-header text-warning"><i class="bi bi-exclamation-triangle"></i><span>Socios en mora</span></div>
                <div class="card-body">
                    <p class="text-muted mb-1">Saldo negativo con la natillera</p>
                    <h2 class="display-6 mb-0"><?php echo $sociosSaldoNegativo; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header category-prestamos"><i class="bi bi-activity"></i><span>Estado de préstamos</span></div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-end mb-2">
                        <div><span class="text-muted small d-block">Activos</span><strong class="h4 mb-0"><?php echo $prestamosActivos; ?></strong></div>
                        <div class="text-end"><span class="text-muted small d-block">En mora</span><strong class="h4 mb-0 text-danger"><?php echo $prestamosEnMora; ?></strong></div>
                    </div>
                    <div class="progress" role="progressbar" aria-label="Distribución de estados de préstamos" aria-valuenow="<?php echo $prestamosEnMora; ?>" aria-valuemin="0" aria-valuemax="<?php echo max($totalPrestamosSeguimiento, 1); ?>">
                        <div class="progress-bar bg-success" style="width: <?php echo number_format($porcentajePrestamosActivos, 2, '.', ''); ?>%"></div>
                        <div class="progress-bar bg-danger" style="width: <?php echo number_format($porcentajePrestamosMora, 2, '.', ''); ?>%"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header category-ingresos"><i class="bi bi-graph-up-arrow"></i><span>Aporte mensual promedio</span></div>
                <div class="card-body">
                    <p class="text-muted mb-1">Promedio por socio activo</p>
                    <h2 class="display-6 mb-0">$<?php echo number_format($aportePromedioMensualSocio,0,',','.'); ?></h2>
                </div>
            </div>
        </div>
    </div>
    <div class="row g-4 mt-1">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-pie-chart"></i><span>Distribución de ingresos</span></div>
                <div class="card-body">
                    <p class="text-muted small mb-2">Incluye rifas (ingresos y premios como egreso)</p>
                    <div class="mx-auto" style="max-width: 420px; height: 260px;">
                        <canvas id="ingresosChart"></canvas>
                    </div>
                    <div class="mt-3 d-flex flex-wrap gap-3">
                        <span class="badge-soft">Total ingresos: $<?php echo number_format($totalIngresos,0,',','.'); ?></span>
                        <span class="badge-soft">Total egresos: $<?php echo number_format($totalEgresos,0,',','.'); ?></span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-ui-checks"></i><span>Primeros pasos</span></div>
                <div class="card-body">
                    <h2 class="h6 d-flex align-items-center gap-2"><i class="bi bi-stars text-warning"></i><span>Guía rápida</span></h2>
                    <p class="text-muted">Usa el menú lateral para gestionar socios, actividades, movimientos, pollas, préstamos, gastos, reportes y exportaciones. Recuerda cargar el script SQL <code>database.sql</code> y luego ejecutar <code>actions/create_admin.php</code> una sola vez para generar el usuario administrador inicial.</p>
                    <div class="mt-3 row g-2">
                        <div class="col-md-6"><div class="badge-soft w-100 text-center"><i class="bi bi-check2-circle text-success"></i> Flujos de ingreso/egreso automáticos</div></div>
                        <div class="col-md-6"><div class="badge-soft w-100 text-center"><i class="bi bi-download"></i> Exporta reportes en segundos</div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="row g-4 mt-1">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-journal-check"></i><span>Estado de resultados</span></div>
                <div class="card-body">
                    <h6 class="text-success">INGRESOS</h6>
                    <ul class="list-unstyled mb-3 small">
                        <li>Cuotas acumuladas: <strong>$<?php echo number_format($totalCuotas,0,',','.'); ?></strong></li>
                        <li>Intereses préstamos: <strong>$<?php echo number_format($totalInteresesPrestamo,0,',','.'); ?></strong></li>
                        <li>Ingresos de pollas: <strong>$<?php echo number_format($totalPollas,0,',','.'); ?></strong></li>
                        <li>Ingresos de rifas: <strong>$<?php echo number_format($totalRifasIngresos,0,',','.'); ?></strong></li>
                        <li>Otros ingresos: <strong>$<?php echo number_format($otrosIngresos,0,',','.'); ?></strong></li>
                        <li class="mt-2">Subtotal ingresos: <strong>$<?php echo number_format($totalIngresos,0,',','.'); ?></strong></li>
                    </ul>
                    <h6 class="text-danger">EGRESOS</h6>
                    <ul class="list-unstyled mb-3 small">
                        <li>Préstamos otorgados: <strong>$<?php echo number_format($totalPrestado,0,',','.'); ?></strong></li>
                        <li>Premios de rifas: <strong>$<?php echo number_format($totalPremiosRifas,0,',','.'); ?></strong></li>
                        <li>Gastos: <strong>$<?php echo number_format($totalGastos,0,',','.'); ?></strong></li>
                        <li>Otros egresos: <strong>$<?php echo number_format($otrosEgresos,0,',','.'); ?></strong></li>
                        <li class="mt-2">Subtotal egresos: <strong>$<?php echo number_format($totalEgresos,0,',','.'); ?></strong></li>
                    </ul>
                    <div class="p-2 rounded bg-light border">
                        <strong>RESULTADO NETO: $<?php echo number_format($resultadoNeto,0,',','.'); ?></strong>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-diagram-3"></i><span>Flujo financiero por actividad</span></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Actividad</th>
                                    <th class="text-end">Ingresos</th>
                                    <th class="text-end">Egresos</th>
                                    <th class="text-end">Resultado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($flujoActividades as $nombreActividad => $totalesActividad): ?>
                                    <?php
                                        $resultadoActividad = $totalesActividad['ingresos'] - $totalesActividad['egresos'];
                                        $nombreActividadLabel = ucfirst($nombreActividad);
                                    ?>
                                    <tr>
                                        <td><?php echo clean($nombreActividadLabel); ?></td>
                                        <td class="text-end">$<?php echo number_format($totalesActividad['ingresos'],0,',','.'); ?></td>
                                        <td class="text-end">$<?php echo number_format($totalesActividad['egresos'],0,',','.'); ?></td>
                                        <td class="text-end <?php echo $resultadoActividad >= 0 ? 'text-success' : 'text-danger'; ?>">$<?php echo number_format($resultadoActividad,0,',','.'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="small text-muted mt-2 mb-0"><i class="bi bi-info-circle"></i> La fila <strong>Préstamos</strong> agrupa los ingresos por intereses y los pagos a capital recuperado; el Estado de resultados los muestra separados para facilitar la lectura.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header"><i class="bi bi-card-list"></i><span>Detalle financiero por actividad individual</span></div>
        <div class="card-body">
            <p class="text-muted small mb-3">Desglose por cada actividad activa del maestro, usando la misma afectación al saldo de natillera de los movimientos registrados.</p>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Actividad</th>
                            <th class="text-end">Ingresos</th>
                            <th class="text-end">Egresos</th>
                            <th class="text-end">Resultado neto</th>
                            <th class="text-end">Movimientos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($detalleActividades as $detalleActividad): ?>
                            <?php
                                $ingresosDetalle = (float) ($detalleActividad['ingresos'] ?? 0);
                                $egresosDetalle = (float) ($detalleActividad['egresos'] ?? 0);
                                $resultadoDetalle = $ingresosDetalle - $egresosDetalle;
                            ?>
                            <tr>
                                <td><?php echo clean($detalleActividad['nombre_actividad']); ?></td>
                                <td class="text-end">$<?php echo number_format($ingresosDetalle,0,',','.'); ?></td>
                                <td class="text-end">$<?php echo number_format($egresosDetalle,0,',','.'); ?></td>
                                <td class="text-end <?php echo $resultadoDetalle >= 0 ? 'text-success' : 'text-danger'; ?>">$<?php echo number_format($resultadoDetalle,0,',','.'); ?></td>
                                <td class="text-end"><?php echo number_format((int) ($detalleActividad['movimientos'] ?? 0),0,',','.'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$detalleActividades): ?>
                            <tr><td colspan="5" class="text-center text-muted">No hay actividades activas registradas.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
                    <?php if (!$validacionActividad): ?>
                        <div class="alert alert-danger mt-3 mb-0 d-flex align-items-center gap-2" role="alert">
                            <i class="bi bi-exclamation-octagon-fill"></i>
                            <span>Inconsistencia de actividad: la suma de resultados por actividad no coincide con el resultado neto global.</span>
                        </div>
                    <?php endif; ?>

    <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-list-check"></i><span>Consolidado de movimientos</span>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-success btn-sm" id="btnExportExcel"><i class="bi bi-file-earmark-excel"></i> Exportar a Excel</button>
                <button class="btn btn-outline-danger btn-sm" id="btnExportPdf"><i class="bi bi-file-earmark-pdf"></i> Exportar a PDF</button>
            </div>
        </div>
        <div class="card-body">
            <form class="row g-3 mb-3" method="GET">
                <div class="col-md-3"><label class="form-label">Desde</label><input type="date" name="desde" class="form-control" value="<?php echo clean($filtroFechaIni); ?>"></div>
                <div class="col-md-3"><label class="form-label">Hasta</label><input type="date" name="hasta" class="form-control" value="<?php echo clean($filtroFechaFin); ?>"></div>
                <div class="col-md-3"><label class="form-label">Socio</label>
                    <select name="socio" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach($socios as $s): ?>
                            <option value="<?php echo $s['id_socio']; ?>" <?php echo ($filtroSocio==$s['id_socio'])?'selected':''; ?>><?php echo clean($s['nombre_completo']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3"><label class="form-label">Actividad</label>
                    <select name="actividad" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach($actividades as $a): ?>
                            <option value="<?php echo $a['id_actividad']; ?>" <?php echo ($filtroActividad==$a['id_actividad'])?'selected':''; ?>><?php echo clean($a['nombre_actividad']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary btn-icon"><span><i class="bi bi-funnel"></i> Filtrar</span></button>
                    <a class="btn btn-outline-secondary btn-icon" href="index.php"><span><i class="bi bi-x-circle"></i> Limpiar</span></a>
                </div>
            </form>
            <div class="row g-3 mb-3">
                <div class="col-md-4"><div class="p-3 rounded bg-success-subtle text-success h-100"><span class="small d-block">Ingresos filtrados</span><strong>$<?php echo number_format($totalIngresosConsolidado,0,',','.'); ?></strong></div></div>
                <div class="col-md-4"><div class="p-3 rounded bg-danger-subtle text-danger h-100"><span class="small d-block">Egresos filtrados</span><strong>$<?php echo number_format($totalEgresosConsolidado,0,',','.'); ?></strong></div></div>
                <div class="col-md-4"><div class="p-3 rounded bg-primary-subtle text-primary h-100"><span class="small d-block">Movimientos filtrados</span><strong><?php echo number_format($totalMovimientosConsolidado,0,',','.'); ?></strong></div></div>
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-sm" id="tablaConsolidado">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Socio/Tercero</th>
                            <th>Actividad</th>
                            <th>Medio de pago</th>
                            <th>Tipo</th>
                            <th class="text-end">Valor</th>
                            <th class="text-end">Saldo socio</th>
                            <th class="text-end">Saldo general</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$movimientos): ?>
                            <tr><td colspan="8" class="text-center text-muted">No hay movimientos con los filtros seleccionados.</td></tr>
                        <?php endif; ?>
                        <?php foreach($movimientos as $m): ?>
                            <?php
                                $tipoMovimiento = 'Neutral';
                                $claseTipo = 'bg-secondary-subtle text-secondary';
                                if ($m['valor_natillera'] > 0) {
                                    $tipoMovimiento = 'Ingreso';
                                    $claseTipo = 'bg-success-subtle text-success';
                                } elseif ($m['valor_natillera'] < 0) {
                                    $tipoMovimiento = 'Egreso';
                                    $claseTipo = 'bg-danger-subtle text-danger';
                                }

                                $nombreMovimiento = $m['nombre_completo'];
                                if (!$nombreMovimiento && in_array($m['modulo'], ['prestamos', 'cuotas'], true)) {
                                    $nombreMovimiento = $m['observaciones'] ?: $nombreMovimiento;
                                }
                                $nombreMovimiento = $nombreMovimiento ?: 'General';
                            ?>
                            <tr>
                                <td><?php echo clean($m['fecha']); ?></td>
                                <td><?php echo clean($nombreMovimiento); ?></td>
                                <td><?php echo clean($m['nombre_actividad']); ?></td>
                                <td><?php echo clean($m['medio_nombre']); ?></td>
                                <td><span class="badge <?php echo $claseTipo; ?>">
                                    <?php echo $tipoMovimiento; ?></span></td>
                                <td class="text-end">$<?php echo number_format($m['valor_natillera'],0,',','.'); ?></td>
                                <td class="text-end"><?php echo $m['saldo_socio'] !== null ? '$'.number_format($m['saldo_socio'],0,',','.') : '-'; ?></td>
                                <td class="text-end">$<?php echo number_format($m['saldo_general'],0,',','.'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalMovimientosConsolidado > 50): ?>
                <div class="text-center mt-3">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="btnVerMasMovimientos" data-step="50">
                        Ver más movimientos
                    </button>
                    <div class="small text-muted mt-2" id="contadorMovimientosVisibles"></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const chartLabels = <?php echo json_encode($chartLabels, JSON_UNESCAPED_UNICODE); ?>;
const chartData = <?php echo json_encode($chartDataset); ?>;
const ctx = document.getElementById('ingresosChart');
if (ctx) {
    new Chart(ctx, {
        type: 'doughnut',
                data: {
            labels: chartLabels,
            datasets: [{
                label: 'Ingresos vs egresos',
                data: chartData,
                backgroundColor: ['#0f172a','#34d399','#8b5cf6','#3b82f6','#ef4444','#a855f7'],
                borderWidth: 0
            }]
        },
        options: {
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'right' }
            }
        }
    });
}

function exportTable(type){
    if(!confirm('¿Desea exportar a Excel o PDF?')){ return; }
    const table = document.getElementById('tablaConsolidado');
    const rows = Array.from(table.querySelectorAll('tr')).map(tr => Array.from(tr.cells).map(td => td.innerText));
    if(type === 'excel'){
        const csvContent = rows.map(r => r.map(value => '"'+value.replace(/"/g,'""')+'"').join(',')).join('\n');
        const blob = new Blob([csvContent], {type: 'text/csv;charset=utf-8;'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'consolidado_movimientos.csv';
        a.click();
        URL.revokeObjectURL(url);
    } else if(type === 'pdf'){
        const nuevaVentana = window.open('', '_blank');
        nuevaVentana.document.write('<html><head><title>Consolidado</title>');
        nuevaVentana.document.write('<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">');
        nuevaVentana.document.write('</head><body class="p-4">');
        nuevaVentana.document.write('<h3>Consolidado de movimientos</h3>');
        nuevaVentana.document.write(table.outerHTML);
        nuevaVentana.document.write('</body></html>');
        nuevaVentana.document.close();
        nuevaVentana.focus();
        nuevaVentana.print();
    }
}
document.getElementById('btnExportExcel')?.addEventListener('click', () => exportTable('excel'));
document.getElementById('btnExportPdf')?.addEventListener('click', () => exportTable('pdf'));

const filasConsolidado = Array.from(document.querySelectorAll('#tablaConsolidado tbody tr'));
const btnVerMasMovimientos = document.getElementById('btnVerMasMovimientos');
const contadorMovimientosVisibles = document.getElementById('contadorMovimientosVisibles');
let movimientosVisibles = 50;
function actualizarMovimientosVisibles(){
    filasConsolidado.forEach((fila, index) => {
        fila.classList.toggle('d-none', index >= movimientosVisibles);
    });
    if (contadorMovimientosVisibles) {
        contadorMovimientosVisibles.textContent = `Mostrando ${Math.min(movimientosVisibles, filasConsolidado.length)} de ${filasConsolidado.length} movimientos`;
    }
    if (btnVerMasMovimientos && movimientosVisibles >= filasConsolidado.length) {
        btnVerMasMovimientos.classList.add('d-none');
    }
}
if (btnVerMasMovimientos) {
    actualizarMovimientosVisibles();
    btnVerMasMovimientos.addEventListener('click', () => {
        movimientosVisibles += Number(btnVerMasMovimientos.dataset.step || 50);
        actualizarMovimientosVisibles();
    });
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
