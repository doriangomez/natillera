<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$config = getConfiguracionGeneral($pdo);
$actividadCuotaId = (int) ($config['actividad_pago_cuota'] ?? 0);
$actividadCuota = $actividadCuotaId > 0 ? getActividad($pdo, $actividadCuotaId) : null;
$periodos = getPeriodosActivosConfiguracion($pdo);
$socios = getSocios($pdo);

usort($socios, function (array $a, array $b): int {
    return (int) $a['id_socio'] <=> (int) $b['id_socio'];
});

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
    $sql = 'SELECT id_socio, anio, mes, SUM(valor) AS total'
        . ' FROM movimientos'
        . ' WHERE id_actividad = :actividad AND id_socio IS NOT NULL';
    if (!empty($condicionesPeriodo)) {
        $sql .= ' AND (' . implode(' OR ', $condicionesPeriodo) . ')';
    }
    $sql .= ' GROUP BY id_socio, anio, mes';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $socioId = (int) $row['id_socio'];
        $clave = sprintf('%04d-%02d', (int) $row['anio'], (int) $row['mes']);
        if (!isset($pagosPorSocio[$socioId])) {
            $pagosPorSocio[$socioId] = [];
        }
        $pagosPorSocio[$socioId][$clave] = (float) $row['total'];
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
                            <div class="text-muted small">ID: <?php echo (int) $s['id_socio']; ?></div>
                        </td>
                        <?php foreach ($periodos as $p): ?>
                            <?php $clave = sprintf('%04d-%02d', (int) $p['anio'], (int) $p['mes']); ?>
                            <?php $valor = $socioPeriodos[$clave] ?? 0.0; ?>
                            <td class="text-end <?php echo $valor < 0 ? 'text-danger' : ''; ?>">
                                <?php echo $valor !== 0.0 ? formatearMonedaCuotas((float) $valor) : '-'; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
