<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

asegurarEsquemaBolsaAdministracion($pdo);

$desde = trim((string) ($_GET['desde'] ?? ''));
$hasta = trim((string) ($_GET['hasta'] ?? ''));
$idSocio = isset($_GET['id_socio']) ? (int) $_GET['id_socio'] : 0;

$where = ['1=1'];
$params = [];
if ($desde !== '') {
    $where[] = 'ba.fecha >= :desde';
    $params[':desde'] = $desde;
}
if ($hasta !== '') {
    $where[] = 'ba.fecha <= :hasta';
    $params[':hasta'] = $hasta;
}
if ($idSocio > 0) {
    $where[] = 'ba.id_socio = :socio';
    $params[':socio'] = $idSocio;
}
$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("SELECT ba.*, s.nombre_completo, m.motivo AS motivo_movimiento
    FROM bolsa_administracion ba
    LEFT JOIN socios s ON s.id_socio = ba.id_socio
    LEFT JOIN movimientos m ON m.id_movimiento = ba.id_movimiento
    WHERE $whereSql
    ORDER BY ba.fecha DESC, ba.id DESC");
$stmt->execute($params);
$movimientosBolsa = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmtTotal = $pdo->prepare("SELECT COALESCE(SUM(valor), 0) FROM bolsa_administracion ba WHERE $whereSql");
$stmtTotal->execute($params);
$totalBolsa = (float) $stmtTotal->fetchColumn();

$totalesSocios = $pdo->query("SELECT s.id_socio, s.nombre_completo, COALESCE(SUM(ba.valor), 0) AS total
    FROM bolsa_administracion ba
    LEFT JOIN socios s ON s.id_socio = ba.id_socio
    GROUP BY s.id_socio, s.nombre_completo
    ORDER BY total DESC, s.nombre_completo ASC")->fetchAll(PDO::FETCH_ASSOC);
$socios = $pdo->query('SELECT id_socio, nombre_completo FROM socios ORDER BY nombre_completo ASC')->fetchAll(PDO::FETCH_ASSOC);
?>
<h2 class="mb-3 d-flex align-items-center gap-2"><i class="bi bi-piggy-bank text-primary"></i><span>Bolsa administración</span></h2>
<p class="text-muted">Consulta la bolsa separada donde se acumulan las cuotas de administración de liquidaciones con saldo pendiente, sin mezclar estos valores con el fondo común de la natillera.</p>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small">Saldo bolsa administración</div>
                <div class="display-6 fw-bold">$<?php echo number_format($totalBolsa, 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-funnel"></i><span>Filtros</span></div>
            <div class="card-body">
                <form class="row g-2" method="get">
                    <div class="col-md-4">
                        <label class="form-label">Socio</label>
                        <select class="form-select" name="id_socio">
                            <option value="0">Todos</option>
                            <?php foreach ($socios as $s): ?>
                                <option value="<?php echo (int) $s['id_socio']; ?>" <?php echo $idSocio === (int) $s['id_socio'] ? 'selected' : ''; ?>><?php echo clean($s['nombre_completo']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Desde</label>
                        <input class="form-control" type="date" name="desde" value="<?php echo clean($desde); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Hasta</label>
                        <input class="form-control" type="date" name="hasta" value="<?php echo clean($hasta); ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button class="btn btn-primary w-100" type="submit">Filtrar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-list-check"></i><span>Movimientos de la bolsa</span></div>
            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                    <thead><tr><th>Fecha</th><th>Socio</th><th>Concepto</th><th>Movimiento</th><th class="text-end">Valor</th></tr></thead>
                    <tbody>
                    <?php if (empty($movimientosBolsa)): ?>
                        <tr><td colspan="5" class="text-center text-muted">No hay movimientos en la bolsa de administración.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($movimientosBolsa as $mov): ?>
                        <tr>
                            <td><?php echo clean($mov['fecha']); ?></td>
                            <td><?php echo clean($mov['nombre_completo'] ?? 'Sin socio'); ?></td>
                            <td><?php echo clean($mov['concepto']); ?></td>
                            <td><?php echo $mov['id_movimiento'] ? '#' . (int) $mov['id_movimiento'] . ' - ' . clean($mov['motivo_movimiento'] ?? '') : 'N/A'; ?></td>
                            <td class="text-end fw-semibold">$<?php echo number_format((float) $mov['valor'], 0, ',', '.'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-people"></i><span>Acumulado por socio</span></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Socio</th><th class="text-end">Total</th></tr></thead>
                    <tbody>
                    <?php if (empty($totalesSocios)): ?>
                        <tr><td colspan="2" class="text-center text-muted">Sin acumulados.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($totalesSocios as $total): ?>
                        <tr>
                            <td><?php echo clean($total['nombre_completo'] ?? 'Sin socio'); ?></td>
                            <td class="text-end">$<?php echo number_format((float) $total['total'], 0, ',', '.'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
