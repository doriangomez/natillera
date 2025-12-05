<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$desde = $_GET['desde'] ?? '';
$hasta = $_GET['hasta'] ?? '';
$retiros = obtenerRetirosCaja($pdo, $desde ?: null, $hasta ?: null);
$totalListado = array_sum(array_map(fn($r) => (float) ($r['valor'] ?? 0), $retiros));
?>
<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <p class="text-muted small mb-1">Control rápido de retiros desde cuentas hacia caja (efectivo)</p>
        <h1 class="h4 mb-0 d-flex align-items-center gap-2"><i class="bi bi-safe2-fill text-primary"></i><span>Retiros a caja</span></h1>
    </div>
</div>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-plus-circle"></i><span>Registrar retiro</span></div>
            <div class="card-body">
                <form method="POST" action="../actions/retiros_caja_save.php" class="vstack gap-3">
                    <div>
                        <label class="form-label">Fecha del retiro</label>
                        <input type="date" name="fecha" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div>
                        <label class="form-label">Valor retirado</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" name="valor" class="form-control" min="0" step="0.01" placeholder="0" required>
                        </div>
                        <div class="form-text">Usa valores positivos; el sistema los guarda como egreso desde la cuenta hacia caja.</div>
                    </div>
                    <div>
                        <label class="form-label">Medio o cuenta de origen</label>
                        <input type="text" name="medio" class="form-control" maxlength="120" placeholder="Cuenta de ahorros, banco, etc.">
                    </div>
                    <div>
                        <label class="form-label">Referencia o soporte</label>
                        <input type="text" name="referencia" class="form-control" maxlength="200" placeholder="Número de comprobante, transferencia, etc.">
                    </div>
                    <div>
                        <label class="form-label">Observaciones</label>
                        <textarea name="observaciones" class="form-control" rows="3" placeholder="Motivo del retiro o destino del efectivo"></textarea>
                    </div>
                    <div class="d-flex justify-content-between align-items-center gap-2">
                        <small class="text-muted">El registro solo afecta este historial, no los movimientos de socios.</small>
                        <button class="btn btn-primary btn-icon" type="submit"><span><i class="bi bi-check2-circle"></i> Guardar retiro</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-funnel"></i><span>Filtrar historial</span></div>
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">Desde</label>
                        <input type="date" name="desde" class="form-control" value="<?php echo clean($desde); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Hasta</label>
                        <input type="date" name="hasta" class="form-control" value="<?php echo clean($hasta); ?>">
                    </div>
                    <div class="col-md-4 d-flex gap-2">
                        <button class="btn btn-outline-primary btn-icon flex-fill" type="submit"><span><i class="bi bi-search"></i> Filtrar</span></button>
                        <a class="btn btn-outline-secondary btn-icon flex-fill" href="retiros_caja.php"><span><i class="bi bi-arrow-counterclockwise"></i> Reiniciar</span></a>
                    </div>
                </form>
            </div>
        </div>
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2"><i class="bi bi-list-check"></i><span>Historial de retiros</span></div>
                <span class="badge bg-primary-subtle text-primary">Últimos <?php echo count($retiros); ?> registros</span>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <div class="text-muted text-uppercase small">Total retirado en listado</div>
                        <div class="h4 mb-0">$<?php echo number_format($totalListado, 0, ',', '.'); ?></div>
                    </div>
                    <div class="text-end">
                        <div class="text-muted text-uppercase small">Filtro aplicado</div>
                        <div class="fw-semibold">Desde <?php echo $desde ? clean($desde) : 'inicio'; ?><?php echo $hasta ? ' hasta ' . clean($hasta) : ''; ?></div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Fecha</th>
                                <th>Valor</th>
                                <th>Medio</th>
                                <th>Referencia</th>
                                <th>Observaciones</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($retiros)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted">No hay retiros registrados con el filtro actual.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($retiros as $r): ?>
                                    <tr>
                                        <td><?php echo clean($r['fecha']); ?></td>
                                        <td class="fw-semibold">$<?php echo number_format((float) $r['valor'], 0, ',', '.'); ?></td>
                                        <td><?php echo clean($r['medio'] ?? ''); ?></td>
                                        <td><?php echo clean($r['referencia'] ?? ''); ?></td>
                                        <td class="text-break" style="max-width: 280px; white-space: pre-line;"><?php echo nl2br(clean($r['observaciones'] ?? '')); ?></td>
                                        <td class="text-end">
                                            <form method="POST" action="../actions/retiros_caja_save.php" onsubmit="return confirm('¿Eliminar este retiro del historial?');" class="d-inline">
                                                <input type="hidden" name="accion" value="eliminar">
                                                <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                                                <input type="hidden" name="redirect" value="../public/retiros_caja.php">
                                                <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <p class="text-muted small mb-0">Se muestran hasta 300 registros, ordenados del más reciente al más antiguo.</p>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
