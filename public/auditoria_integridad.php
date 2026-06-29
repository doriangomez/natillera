<?php
require_once __DIR__ . '/../includes/auth.php';
checkAdmin();
require_once __DIR__ . '/../includes/header.php';

$generadoEn = date('Y-m-d H:i:s');

$sociosAuditoria = $pdo->query(
    "SELECT s.id_socio, s.nombre_completo, s.saldo_socio AS saldo_guardado," .
    " COALESCE(SUM(CASE" .
    " WHEN a.afecta_saldo_socio = 'suma' THEN ABS(m.valor)" .
    " WHEN a.afecta_saldo_socio = 'resta' THEN -ABS(m.valor)" .
    " ELSE 0 END), 0) AS saldo_recalculado" .
    " FROM socios s" .
    " LEFT JOIN movimientos m ON m.id_socio = s.id_socio" .
    " LEFT JOIN actividades_maestro a ON a.id_actividad = m.id_actividad" .
    " GROUP BY s.id_socio, s.nombre_completo, s.saldo_socio" .
    " ORDER BY s.nombre_completo"
)->fetchAll(PDO::FETCH_ASSOC);

$inconsistenciasSocios = 0;
foreach ($sociosAuditoria as &$socioAuditoria) {
    $saldoGuardado = (float) ($socioAuditoria['saldo_guardado'] ?? 0);
    $saldoRecalculado = (float) ($socioAuditoria['saldo_recalculado'] ?? 0);
    $diferencia = round($saldoGuardado - $saldoRecalculado, 2);
    $socioAuditoria['diferencia'] = $diferencia;
    if (abs($diferencia) > 0.009) {
        $inconsistenciasSocios++;
    }
}
unset($socioAuditoria);

$saldoNatilleraGuardado = (float) $pdo->query('SELECT COALESCE(saldo_actual, 0) FROM natillera_estado WHERE id_estado = 1 LIMIT 1')->fetchColumn();
$saldoNatilleraRecalculado = (float) $pdo->query(
    "SELECT COALESCE(SUM(CASE" .
    " WHEN a.afecta_saldo_natillera = 'suma' THEN ABS(m.valor)" .
    " WHEN a.afecta_saldo_natillera = 'resta' THEN -ABS(m.valor)" .
    " ELSE 0 END), 0)" .
    " FROM movimientos m" .
    " JOIN actividades_maestro a ON a.id_actividad = m.id_actividad"
)->fetchColumn();
$diferenciaNatillera = round($saldoNatilleraGuardado - $saldoNatilleraRecalculado, 2);
$natilleraCorrecta = abs($diferenciaNatillera) <= 0.009;

$movimientosHuerfanos = $pdo->query(
    "SELECT m.id_movimiento, m.fecha, m.id_socio, s.nombre_completo, m.id_actividad, m.motivo, m.valor, m.modulo" .
    " FROM movimientos m" .
    " LEFT JOIN actividades_maestro a ON a.id_actividad = m.id_actividad" .
    " LEFT JOIN socios s ON s.id_socio = m.id_socio" .
    " WHERE a.id_actividad IS NULL" .
    " ORDER BY m.fecha DESC, m.id_movimiento DESC"
)->fetchAll(PDO::FETCH_ASSOC);

function formatoDineroAuditoria($valor): string {
    return '$' . number_format((float) $valor, 2, ',', '.');
}

function claseDiferenciaAuditoria(float $diferencia): string {
    return abs($diferencia) <= 0.009 ? 'text-success' : 'text-danger';
}
?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
    <div>
        <p class="text-muted small mb-1">Panel exclusivo para administradores · Solo lectura</p>
        <h1 class="h4 mb-1 d-flex align-items-center gap-2"><i class="bi bi-shield-check text-primary"></i><span>Auditoría de Integridad</span></h1>
        <p class="text-muted mb-0">Recalcula saldos desde movimientos y actividades para detectar inconsistencias sin modificar datos.</p>
    </div>
    <span class="badge bg-dark">Generado: <?php echo clean($generadoEn); ?></span>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small">Socios con inconsistencia</div>
            <div class="display-6 mb-0 <?php echo $inconsistenciasSocios === 0 ? 'text-success' : 'text-danger'; ?>"><?php echo $inconsistenciasSocios; ?></div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small">Saldo natillera</div>
            <div class="h3 mb-0 <?php echo $natilleraCorrecta ? 'text-success' : 'text-danger'; ?>"><?php echo $natilleraCorrecta ? '✓ OK' : '✗ DIFERENCIA'; ?></div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small">Fecha y hora del reporte</div>
            <div class="h5 mb-0"><?php echo clean($generadoEn); ?></div>
        </div></div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-people"></i><span>Sección 1 — Saldos por socio</span></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
                <thead class="table-light"><tr><th>Nombre del socio</th><th class="text-end">Saldo guardado</th><th class="text-end">Saldo recalculado</th><th class="text-end">Diferencia</th><th>Estado</th></tr></thead>
                <tbody>
                    <?php if (empty($sociosAuditoria)): ?>
                        <tr><td colspan="5" class="text-center text-muted">No hay socios registrados.</td></tr>
                    <?php else: ?>
                        <?php foreach ($sociosAuditoria as $socio): ?>
                            <?php $diferencia = (float) $socio['diferencia']; ?>
                            <tr>
                                <td><?php echo clean($socio['nombre_completo']); ?></td>
                                <td class="text-end"><?php echo formatoDineroAuditoria($socio['saldo_guardado']); ?></td>
                                <td class="text-end"><?php echo formatoDineroAuditoria($socio['saldo_recalculado']); ?></td>
                                <td class="text-end <?php echo claseDiferenciaAuditoria($diferencia); ?>"><?php echo formatoDineroAuditoria($diferencia); ?></td>
                                <td class="fw-semibold <?php echo claseDiferenciaAuditoria($diferencia); ?>"><?php echo abs($diferencia) <= 0.009 ? '✓ OK' : '✗ DIFERENCIA ' . formatoDineroAuditoria($diferencia); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-bank"></i><span>Sección 2 — Saldo natillera</span></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
                <thead class="table-light"><tr><th class="text-end">Saldo guardado</th><th class="text-end">Saldo recalculado</th><th class="text-end">Diferencia</th><th>Estado</th></tr></thead>
                <tbody><tr>
                    <td class="text-end"><?php echo formatoDineroAuditoria($saldoNatilleraGuardado); ?></td>
                    <td class="text-end"><?php echo formatoDineroAuditoria($saldoNatilleraRecalculado); ?></td>
                    <td class="text-end <?php echo claseDiferenciaAuditoria($diferenciaNatillera); ?>"><?php echo formatoDineroAuditoria($diferenciaNatillera); ?></td>
                    <td class="fw-semibold <?php echo claseDiferenciaAuditoria($diferenciaNatillera); ?>"><?php echo $natilleraCorrecta ? '✓ OK' : '✗ DIFERENCIA ' . formatoDineroAuditoria($diferenciaNatillera); ?></td>
                </tr></tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-exclamation-triangle"></i><span>Sección 3 — Movimientos huérfanos</span></div>
    <div class="card-body">
        <?php if (empty($movimientosHuerfanos)): ?>
            <div class="alert alert-success mb-0">Sin movimientos huérfanos ✓</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                    <thead class="table-light"><tr><th>ID</th><th>Fecha</th><th>Socio</th><th>ID actividad faltante</th><th>Motivo</th><th>Módulo</th><th class="text-end">Valor</th></tr></thead>
                    <tbody>
                        <?php foreach ($movimientosHuerfanos as $movimiento): ?>
                            <tr>
                                <td><?php echo (int) $movimiento['id_movimiento']; ?></td>
                                <td><?php echo clean($movimiento['fecha']); ?></td>
                                <td><?php echo $movimiento['nombre_completo'] ? clean($movimiento['nombre_completo']) : 'Sin socio'; ?></td>
                                <td><?php echo (int) $movimiento['id_actividad']; ?></td>
                                <td><?php echo clean($movimiento['motivo']); ?></td>
                                <td><?php echo clean($movimiento['modulo']); ?></td>
                                <td class="text-end"><?php echo formatoDineroAuditoria($movimiento['valor']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
