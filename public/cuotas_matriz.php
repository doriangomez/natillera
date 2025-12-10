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
                            <td class="text-end align-middle">
                                <?php if (!empty($quincenas)): ?>
                                    <div class="text-end small">
                                        <?php foreach ([1, 2] as $q): if (!empty($quincenas[$q])): ?>
                                            <div>Q<?php echo $q; ?>: <?php echo formatearMonedaCuotas((float) $quincenas[$q]); ?></div>
                                        <?php endif; endforeach; ?>
                                        <?php if (!empty($quincenas[0])): ?>
                                            <div>Sin quincena: <?php echo formatearMonedaCuotas((float) $quincenas[0]); ?></div>
                                        <?php endif; ?>
                                        <div class="fw-semibold mt-1">Total: <?php echo formatearMonedaCuotas((float) $valor['total']); ?></div>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
