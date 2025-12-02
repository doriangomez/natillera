<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$anio = isset($_GET['anio']) ? (int) $_GET['anio'] : (int) date('Y');
$mes = isset($_GET['mes']) ? (int) $_GET['mes'] : (int) date('n');

$medios = getMediosPago($pdo);

$totalesSistema = [];
foreach ($medios as $medio) {
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(valor), 0) AS total FROM movimientos WHERE id_medio_pago = :id AND YEAR(fecha) = :y AND MONTH(fecha) = :m'
    );
    $stmt->execute([':id' => $medio['id'], ':y' => $anio, ':m' => $mes]);
    $totalesSistema[$medio['id']] = (float) $stmt->fetchColumn();
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

<div class="card mb-3">
    <div class="card-body">
        <form class="row g-2" method="GET">
            <div class="col-md-3">
                <label class="form-label">Mes</label>
                <select name="mes" class="form-select">
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $i === $mes ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $i, 1)); ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Año</label>
                <input type="number" name="anio" class="form-control" min="2000" value="<?php echo $anio; ?>">
            </div>
            <div class="col-md-2 align-self-end">
                <button class="btn btn-primary">Consultar</button>
            </div>
        </form>
    </div>
</div>

<?php if (empty($medios)): ?>
    <div class="alert alert-info">No hay medios de pago activos configurados. Configure medios en "Configuración → Medios de pago".</div>
<?php else: ?>
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
