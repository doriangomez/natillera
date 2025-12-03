<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';


$periodosConfig = $pdo
    ->query('SELECT anio, mes FROM periodos_configuracion WHERE activo = 1 ORDER BY anio DESC, mes DESC')
    ->fetchAll();

$nombresMeses = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
    5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
    9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
];

$periodosCerrados = $pdo
    ->query('SELECT DISTINCT anio, mes FROM conciliaciones_medios_pago WHERE cerrado = 1')
    ->fetchAll();

$periodosCerradosIndex = [];
foreach ($periodosCerrados as $p) {
    $periodosCerradosIndex[$p['anio'] . '-' . $p['mes']] = true;
}

$periodosDisponibles = [];
$periodosPorAnio = [];
foreach ($periodosConfig as $periodo) {
    $key = $periodo['anio'] . '-' . $periodo['mes'];
    if (isset($periodosCerradosIndex[$key])) {
        continue;
    }
    $periodosDisponibles[] = $periodo;
    $periodosPorAnio[$periodo['anio']][] = (int) $periodo['mes'];
}

if (!empty($periodosPorAnio)) {
    krsort($periodosPorAnio);
}

$anio = isset($_GET['anio']) ? (int) $_GET['anio'] : null;
$mes = isset($_GET['mes']) ? (int) $_GET['mes'] : null;

if (empty($periodosDisponibles)) {
    $anio = $anio ?: (int) date('Y');
    $mes = $mes ?: (int) date('n');
    $mesesDisponibles = range(1, 12);
    $aniosDisponibles = [$anio];
} else {
    $aniosDisponibles = array_keys($periodosPorAnio);
    $anio = in_array($anio, $aniosDisponibles, true) ? $anio : (int) reset($aniosDisponibles);
    $mesesDisponibles = $periodosPorAnio[$anio] ?? [];
    sort($mesesDisponibles);
    $mes = in_array($mes, $mesesDisponibles, true) ? $mes : (int) reset($mesesDisponibles);
}

$medios = getMediosPago($pdo, true);

$totalesSistema = [];

$stmtTotales = $pdo->prepare(
    'SELECT COALESCE(m.id_medio_pago, mp_lookup.id) AS medio_id, '
    . 'COALESCE(SUM(CASE'
    . " WHEN a.afecta_saldo_natillera = 'suma' THEN m.valor"
    . " WHEN a.afecta_saldo_natillera = 'resta' THEN -m.valor"
    . ' ELSE 0 END), 0) AS total '
    . 'FROM movimientos m '
    . 'JOIN actividades_maestro a ON m.id_actividad = a.id_actividad '
    . 'LEFT JOIN medios_pago mp_lookup ON mp_lookup.nombre = m.medio_consignacion '
    . 'WHERE m.anio = :y AND m.mes = :m '
    . 'GROUP BY medio_id'
);
$stmtTotales->execute([':y' => $anio, ':m' => $mes]);
foreach ($stmtTotales->fetchAll(PDO::FETCH_ASSOC) as $filaTotal) {
    if ($filaTotal['medio_id'] === null) {
        continue;
    }
    $totalesSistema[(int) $filaTotal['medio_id']] = (float) $filaTotal['total'];
}

$stmtConc = $pdo->prepare('SELECT * FROM conciliaciones_medios_pago WHERE anio = :y AND mes = :m');
$stmtConc->execute([':y' => $anio, ':m' => $mes]);
$conciliaciones = [];
$mesCerrado = false;
foreach ($stmtConc->fetchAll() as $row) {
    $conciliaciones[$row['id_medio']] = $row;
    if (!empty($row['cerrado'])) {
        $mesCerrado = true;
    }
}

$totalSistemaGlobal = 0;
$totalConciliadoGlobal = 0;

foreach ($medios as $medio) {
    $totalSistema = $totalesSistema[$medio['id']] ?? 0;
    $valorConciliado = isset($conciliaciones[$medio['id']]['valor_conciliado'])
        ? (float) $conciliaciones[$medio['id']]['valor_conciliado']
        : 0.0;

    $totalSistemaGlobal += $totalSistema;
    $totalConciliadoGlobal += $valorConciliado;
}

$diferenciaGlobal = $totalSistemaGlobal - $totalConciliadoGlobal;

$registroConciliaciones = $pdo
    ->query(
        'SELECT cm.id, cm.anio, cm.mes, mp.nombre AS medio_nombre, cm.saldo_sistema, cm.valor_conciliado, cm.diferencia,
                cm.nota, cm.fecha_registro, cm.cerrado
         FROM conciliaciones_medios_pago cm
         JOIN medios_pago mp ON mp.id = cm.id_medio
         ORDER BY cm.anio DESC, cm.mes DESC, mp.nombre'
    )
    ->fetchAll();

$totalesRegistro = [
    'sistema' => 0,
    'conciliado' => 0,
    'diferencia' => 0,
];

foreach ($registroConciliaciones as $cc) {
    $totalesRegistro['sistema'] += (float) $cc['saldo_sistema'];
    $totalesRegistro['conciliado'] += (float) $cc['valor_conciliado'];
    $totalesRegistro['diferencia'] += (float) $cc['diferencia'];
}

$diferenciaGlobal = $totalSistemaGlobal - $totalConciliadoGlobal;
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <p class="text-muted small mb-1">Control mensual por medio de pago</p>
        <h1 class="h4 mb-0">Conciliación de medios de pago</h1>
    </div>
    <?php if ($mesCerrado): ?>
        <span class="badge bg-secondary">Mes conciliado. Solo consulta.</span>
    <?php endif; ?>
</div>

<?php if (empty($periodosConfig)): ?>
    <div class="alert alert-warning">Configure los periodos disponibles en el módulo de configuración para habilitar la conciliación.</div>
<?php elseif (empty($periodosDisponibles)): ?>
    <div class="alert alert-info">Todos los periodos configurados están cerrados. No hay meses disponibles para conciliar.</div>
<?php endif; ?>

<?php if (!empty($periodosDisponibles)): ?>
    <div class="card mb-3">
        <div class="card-body">
            <form class="row g-2" method="GET">
                <div class="col-md-3">
                    <label class="form-label">Mes</label>
                    <select name="mes" class="form-select">
                        <?php foreach ($mesesDisponibles as $mesDisponible): ?>
                            <option value="<?php echo $mesDisponible; ?>" <?php echo (int) $mesDisponible === (int) $mes ? 'selected' : ''; ?>>
                                <?php echo $nombresMeses[$mesDisponible] ?? $mesDisponible; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Año</label>
                    <select name="anio" class="form-select">
                        <?php foreach ($aniosDisponibles as $anioDisponible): ?>
                            <option value="<?php echo $anioDisponible; ?>" <?php echo (int) $anioDisponible === (int) $anio ? 'selected' : ''; ?>><?php echo $anioDisponible; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 align-self-end">
                    <button class="btn btn-primary">Consultar</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if (empty($periodosDisponibles)): ?>
    <div class="alert alert-secondary">No hay periodos abiertos para conciliar.</div>
<?php elseif (empty($medios)): ?>
    <div class="alert alert-info">No hay medios de pago activos configurados. Configure medios en "Configuración → Medios de pago".</div>
<?php else: ?>
    <?php if (!$mesCerrado && abs($diferenciaGlobal) > 0.009): ?>
        <div class="alert alert-warning">El total conciliado no coincide con el total del sistema. Puedes guardar la conciliación igualmente; se registrará una advertencia.</div>
    <?php endif; ?>
    <form method="POST" action="../actions/conciliacion_save.php">
        <input type="hidden" name="mes" value="<?php echo $mes; ?>">
        <input type="hidden" name="anio" value="<?php echo $anio; ?>">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-credit-card-2-front"></i>
                    <span>Conciliación mensual</span>
                </div>
                <?php if ($mesCerrado): ?>
                    <span class="text-muted small">Conciliación cerrada – solo consulta.</span>
                <?php else: ?>
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="cerrarMes" name="cerrar_mes">
                        <label class="form-check-label" for="cerrarMes">Cerrar conciliación del mes</label>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Medio de pago</th>
                                <th class="text-end">Total sistema</th>
                                <th class="text-end">Valor conciliado</th>
                                <th class="text-end">Diferencia</th>
                                <th>Nota</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($medios as $medio):
                                $totalSistema = $totalesSistema[$medio['id']] ?? 0;
                                $valorConciliado = isset($conciliaciones[$medio['id']]['valor_conciliado'])
                                    ? (float) $conciliaciones[$medio['id']]['valor_conciliado']
                                    : 0.0;
                                $nota = $conciliaciones[$medio['id']]['nota'] ?? '';
                                $diferencia = $totalSistema - $valorConciliado;
                            ?>
                                <tr data-total-sistema="<?php echo $totalSistema; ?>">
                                    <td>
                                        <div class="fw-semibold mb-1"><?php echo clean($medio['nombre']); ?></div>
                                        <input type="hidden" name="medio_ids[]" value="<?php echo $medio['id']; ?>">
                                    </td>
                                    <td class="text-end fw-semibold" data-total-text>
                                        $<?php echo number_format($totalSistema, 2, ',', '.'); ?>
                                    </td>
                                    <td class="text-end">
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            name="valor_conciliado[<?php echo $medio['id']; ?>]"
                                            class="form-control text-end valor-conciliado"
                                            value="<?php echo number_format($valorConciliado, 2, '.', ''); ?>"
                                            <?php echo $mesCerrado ? 'disabled' : ''; ?>
                                            aria-label="Valor conciliado para <?php echo clean($medio['nombre']); ?>">
                                    </td>
                                    <td class="text-end fw-semibold diferencia">$<?php echo number_format($diferencia, 2, ',', '.'); ?></td>
                                    <td style="min-width: 240px;">
                                        <textarea
                                            name="nota[<?php echo $medio['id']; ?>]"
                                            class="form-control"
                                            rows="2"
                                            <?php echo $mesCerrado ? 'disabled' : ''; ?>
                                            placeholder="Notas opcionales"><?php echo clean($nota); ?></textarea>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="row g-3 mt-3">
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded border">
                            <div class="text-muted small">TOTAL SISTEMA GLOBAL</div>
                            <div class="fs-5 fw-bold" id="total-sistema-global">$<?php echo number_format($totalSistemaGlobal, 2, ',', '.'); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded border">
                            <div class="text-muted small">TOTAL CONCILIADO GLOBAL</div>
                            <div class="fs-5 fw-bold" id="total-conciliado-global">$<?php echo number_format($totalConciliadoGlobal, 2, ',', '.'); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded border">
                            <div class="text-muted small">DIFERENCIA GLOBAL</div>
                            <div class="fs-5 fw-bold" id="diferencia-global">$<?php echo number_format($diferenciaGlobal, 2, ',', '.'); ?></div>
                        </div>
                    </div>
                </div>
                <?php if (!$mesCerrado): ?>
                    <div class="mt-4 d-flex justify-content-end">
                        <button class="btn btn-success" type="submit">Guardar conciliación</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </form>
<?php endif; ?>

<script>
const selectAnio = document.querySelector('select[name="anio"]');
const selectMes = document.querySelector('select[name="mes"]');
const periodosPorAnio = <?php echo json_encode($periodosPorAnio); ?>;
const nombresMeses = <?php echo json_encode($nombresMeses); ?>;

function actualizarMesesConciliacion() {
    if (!selectAnio || !selectMes) return;
    const anio = parseInt(selectAnio.value, 10);
    const meses = periodosPorAnio[anio] ?? [];
    selectMes.querySelectorAll('option').forEach(opt => opt.remove());
    meses.forEach(m => {
        const opt = document.createElement('option');
        opt.value = m;
        opt.textContent = nombresMeses[m] || m;
        selectMes.appendChild(opt);
    });
    if (!meses.includes(parseInt(selectMes.value, 10))) {
        selectMes.value = meses[0] ?? '';
    }
}

if (selectAnio && selectMes) {
    selectAnio.addEventListener('change', () => {
        actualizarMesesConciliacion();
    });
    actualizarMesesConciliacion();
}
</script>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-calendar-check"></i>
            <span>Registro de conciliaciones</span>
        </div>
        <span class="text-muted small">Historial con estado de cierre</span>
    </div>
    <div class="card-body">
        <?php if (empty($registroConciliaciones)): ?>
            <div class="alert alert-info mb-0">Aún no hay conciliaciones registradas.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Mes</th>
                            <th>Año</th>
                            <th>Medio de pago</th>
                            <th class="text-end">Valor sistema</th>
                            <th class="text-end">Valor conciliado</th>
                            <th class="text-end">Diferencia</th>
                            <th>Nota</th>
                            <th>Fecha registro</th>
                            <th>Estado</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registroConciliaciones as $cerrada): ?>
                            <tr>
                                <td><?php echo $nombresMeses[(int) $cerrada['mes']] ?? $cerrada['mes']; ?></td>
                                <td><?php echo $cerrada['anio']; ?></td>
                                <td><?php echo clean($cerrada['medio_nombre']); ?></td>
                                <td class="text-end">$<?php echo number_format($cerrada['saldo_sistema'], 2, ',', '.'); ?></td>
                                <td class="text-end">$<?php echo number_format($cerrada['valor_conciliado'], 2, ',', '.'); ?></td>
                                <td class="text-end fw-semibold">$<?php echo number_format($cerrada['diferencia'], 2, ',', '.'); ?></td>
                                <td><?php echo $cerrada['nota'] !== null ? clean($cerrada['nota']) : '—'; ?></td>
                                <td><?php echo $cerrada['fecha_registro'] ?: '—'; ?></td>
                                <td>
                                    <?php if (!empty($cerrada['cerrado'])): ?>
                                        <span class="badge bg-success">Cerrada</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Abierta</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end gap-1">
                                        <form method="POST" action="../actions/conciliacion_manage.php" class="d-inline" onsubmit="return confirm('¿Deseas eliminar esta conciliación?');">
                                            <input type="hidden" name="id" value="<?php echo $cerrada['id']; ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="redirect" value="../public/conciliaciones.php">
                                            <button type="submit" class="btn btn-outline-danger btn-sm" aria-label="Eliminar conciliación">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                        <?php if (!empty($cerrada['cerrado'])): ?>
                                            <form method="POST" action="../actions/conciliacion_manage.php" class="d-inline" onsubmit="return confirm('¿Reabrir este mes para edición?');">
                                                <input type="hidden" name="id" value="<?php echo $cerrada['id']; ?>">
                                                <input type="hidden" name="action" value="reopen">
                                                <input type="hidden" name="redirect" value="../public/conciliaciones.php">
                                                <button type="submit" class="btn btn-outline-secondary btn-sm" aria-label="Reabrir mes">
                                                    <i class="bi bi-arrow-counterclockwise"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="3" class="text-end">Totales</th>
                            <th class="text-end">$<?php echo number_format($totalesRegistro['sistema'], 2, ',', '.'); ?></th>
                            <th class="text-end">$<?php echo number_format($totalesRegistro['conciliado'], 2, ',', '.'); ?></th>
                            <th class="text-end">$<?php echo number_format($totalesRegistro['diferencia'], 2, ',', '.'); ?></th>
                            <th colspan="4"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    const formatoCOP = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP' });

    function recalcularTotales() {
        let totalSistemaGlobal = 0;
        let totalConciliadoGlobal = 0;

        document.querySelectorAll('tbody tr[data-total-sistema]').forEach((fila) => {
            const totalSistema = parseFloat(fila.dataset.totalSistema) || 0;
            const inputConciliado = fila.querySelector('.valor-conciliado');
            const valorConciliado = inputConciliado ? parseFloat(inputConciliado.value) || 0 : 0;
            const diferencia = totalSistema - valorConciliado;

            totalSistemaGlobal += totalSistema;
            totalConciliadoGlobal += valorConciliado;

            const celdaDiferencia = fila.querySelector('.diferencia');
            if (celdaDiferencia) {
                celdaDiferencia.textContent = formatoCOP.format(diferencia);
            }
        });

        const diferenciaGlobal = totalSistemaGlobal - totalConciliadoGlobal;

        document.getElementById('total-sistema-global').textContent = formatoCOP.format(totalSistemaGlobal);
        document.getElementById('total-conciliado-global').textContent = formatoCOP.format(totalConciliadoGlobal);
        document.getElementById('diferencia-global').textContent = formatoCOP.format(diferenciaGlobal);
    }

    document.querySelectorAll('.valor-conciliado').forEach((input) => {
        input.addEventListener('input', recalcularTotales);
    });

    recalcularTotales();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
