<?php
require_once __DIR__ . '/../includes/header.php';

asegurarEsquemaLiquidaciones($pdo);

$tipos = obtenerTiposLiquidacion();
$socios = $pdo->query('SELECT id_socio, nombre_completo, saldo_socio FROM socios WHERE activo = 1 ORDER BY nombre_completo')->fetchAll();
$actividades = getActividades($pdo, false, false, false);

$filtroSocio = isset($_GET['filtro_socio']) ? (int) $_GET['filtro_socio'] : 0;
$filtroTipo = trim((string) ($_GET['filtro_tipo'] ?? ''));
$filtroEstado = trim((string) ($_GET['filtro_estado'] ?? 'activa'));

$idSocio = isset($_GET['id_socio']) ? (int) $_GET['id_socio'] : 0;
$tipoLiquidacion = trim((string) ($_GET['tipo_liquidacion'] ?? 'anticipada'));
$cuotaManejo = isset($_GET['cuota_manejo']) ? (float) $_GET['cuota_manejo'] : 0.0;
$idActividadLiquidacion = isset($_GET['id_actividad_liquidacion']) ? (int) $_GET['id_actividad_liquidacion'] : 0;
$idActividadRetencion = isset($_GET['id_actividad_retencion'])
    ? (int) $_GET['id_actividad_retencion']
    : (isset($_GET['id_actividad_cuota']) ? (int) $_GET['id_actividad_cuota'] : 0);
$editarId = isset($_GET['editar']) ? (int) $_GET['editar'] : 0;

if (!isset($tipos[$tipoLiquidacion])) {
    $tipoLiquidacion = 'anticipada';
}

$resultado = null;
$socioSeleccionado = null;
if ($idSocio > 0) {
    $resultado = calcularLiquidacionSocio($pdo, $idSocio, $cuotaManejo);
    if ($resultado) {
        $socioSeleccionado = $resultado['socio'];
    }
}

$params = [];
$sqlHistorial = 'SELECT l.*, s.nombre_completo FROM liquidaciones l JOIN socios s ON s.id_socio = l.socio_id WHERE 1=1';
if ($filtroSocio > 0) {
    $sqlHistorial .= ' AND l.socio_id = :socio';
    $params[':socio'] = $filtroSocio;
}
if (isset($tipos[$filtroTipo])) {
    $sqlHistorial .= ' AND l.tipo_liquidacion = :tipo';
    $params[':tipo'] = $filtroTipo;
}
if (in_array($filtroEstado, ['activa', 'anulada', 'editada', 'todas'], true) && $filtroEstado !== 'todas') {
    $sqlHistorial .= ' AND l.estado = :estado';
    $params[':estado'] = $filtroEstado;
}
$sqlHistorial .= ' ORDER BY l.fecha DESC, l.id DESC LIMIT 300';
$stmtHistorial = $pdo->prepare($sqlHistorial);
$stmtHistorial->execute($params);
$historial = $stmtHistorial->fetchAll(PDO::FETCH_ASSOC);

$liquidacionEditar = null;
if ($editarId > 0) {
    $stmtEditar = $pdo->prepare('SELECT * FROM liquidaciones WHERE id = :id');
    $stmtEditar->execute([':id' => $editarId]);
    $liquidacionEditar = $stmtEditar->fetch(PDO::FETCH_ASSOC);
}
?>
<h2 class="mb-3">Módulo de Liquidaciones</h2>
<p class="text-muted">Liquidaciones parciales, definitivas anticipadas y definitivas con trazabilidad contable.</p>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-calculator"></i> Pre-cálculo y registro</div>
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="tipo_liquidacion">Tipo de liquidación</label>
                <select class="form-select" name="tipo_liquidacion" id="tipo_liquidacion" required>
                    <?php foreach ($tipos as $clave => $etiqueta): ?>
                        <option value="<?php echo $clave; ?>" <?php echo $tipoLiquidacion === $clave ? 'selected' : ''; ?>><?php echo clean($etiqueta); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="id_socio">Socio</label>
                <select class="form-select" name="id_socio" id="id_socio" required>
                    <option value="">Seleccione...</option>
                    <?php foreach ($socios as $socio): ?>
                        <option value="<?php echo (int) $socio['id_socio']; ?>" <?php echo $idSocio === (int) $socio['id_socio'] ? 'selected' : ''; ?>>
                            <?php echo clean($socio['nombre_completo']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="cuota_manejo">Cuota administración</label>
                <input class="form-control" type="number" min="0" step="0.01" id="cuota_manejo" name="cuota_manejo" value="<?php echo number_format($cuotaManejo, 2, '.', ''); ?>">
            </div>
            <div class="col-md-4 d-grid">
                <button class="btn btn-primary" type="submit"><i class="bi bi-lightning-charge"></i> Precalcular</button>
            </div>

            <div class="col-md-6">
                <label class="form-label" for="id_actividad_liquidacion">Actividad principal (pago al socio)</label>
                <select class="form-select" name="id_actividad_liquidacion" id="id_actividad_liquidacion" required>
                    <option value="">Seleccione...</option>
                    <?php foreach ($actividades as $actividad): ?>
                        <option value="<?php echo (int) $actividad['id_actividad']; ?>" <?php echo $idActividadLiquidacion === (int) $actividad['id_actividad'] ? 'selected' : ''; ?>><?php echo clean($actividad['nombre_actividad']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="id_actividad_retencion">Actividad retención administración</label>
                <select class="form-select" name="id_actividad_retencion" id="id_actividad_retencion">
                    <option value="">Seleccione...</option>
                    <?php foreach ($actividades as $actividad): ?>
                        <option value="<?php echo (int) $actividad['id_actividad']; ?>" <?php echo $idActividadRetencion === (int) $actividad['id_actividad'] ? 'selected' : ''; ?>><?php echo clean($actividad['nombre_actividad']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <?php if ($idSocio > 0 && !$resultado): ?>
            <div class="alert alert-warning mt-3">No se encontró el socio seleccionado.</div>
        <?php endif; ?>

        <?php if ($resultado): ?>
            <hr>
            <?php
            $identificacionSocio = $socioSeleccionado['id_interno'] !== null && $socioSeleccionado['id_interno'] !== ''
                ? str_pad((string) $socioSeleccionado['id_interno'], 2, '0', STR_PAD_LEFT)
                : (string) $socioSeleccionado['id_socio'];
            ?>
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <h5 class="mb-1">Tirilla de preliquidación</h5>
                    <div class="fw-semibold"><?php echo clean($socioSeleccionado['nombre_completo']); ?></div>
                    <div class="text-muted small">Identificación: <?php echo clean($identificacionSocio); ?></div>
                </div>
                <div class="text-muted small text-md-end">
                    Fecha y hora: <?php echo clean($resultado['fecha_preliquidacion']); ?>
                </div>
            </div>

            <?php if ($resultado['saldo_liquidacion'] < 0): ?>
                <div class="alert alert-warning">
                    <strong>El saldo de liquidación es negativo.</strong>
                    El saldo pendiente del socio es <strong>$<?php echo number_format(abs((float) $resultado['saldo_liquidacion']), 0, ',', '.'); ?></strong> y deberá ser gestionado manualmente.
                </div>
            <?php endif; ?>

            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered align-middle">
                    <tbody>
                    <tr><th>Ahorro acumulado</th><td>$<?php echo number_format($resultado['ahorro_acumulado_bruto'], 0, ',', '.'); ?></td></tr>
                    <tr><th>+ Rendimientos</th><td>$<?php echo number_format($resultado['rendimientos'], 0, ',', '.'); ?></td></tr>
                    <tr><th>- Capital pendiente</th><td>$<?php echo number_format(array_sum(array_column($resultado['prestamos_descontados'], 'capital_pendiente')), 0, ',', '.'); ?></td></tr>
                    <tr><th>- Intereses pendientes</th><td>$<?php echo number_format(array_sum(array_column($resultado['prestamos_descontados'], 'intereses_pendientes')), 0, ',', '.'); ?></td></tr>
                    <tr><th>- Cuota de administración</th><td>$<?php echo number_format($resultado['valor_cuota_manejo'], 0, ',', '.'); ?></td></tr>
                    <tr><th>Deuda total</th><td>$<?php echo number_format($resultado['deuda_total'], 0, ',', '.'); ?></td></tr>
                    <tr class="table-light"><th>Saldo de liquidación</th><td class="fw-bold"><?php echo $resultado['saldo_liquidacion'] < 0 ? '-' : ''; ?>$<?php echo number_format(abs((float) $resultado['saldo_liquidacion']), 0, ',', '.'); ?></td></tr>
                    <tr><th>Valor aplicado a préstamos</th><td>$<?php echo number_format($resultado['valor_aplicado_deuda'], 0, ',', '.'); ?></td></tr>
                    <?php if ($resultado['saldo_liquidacion'] < 0): ?>
                        <tr class="table-warning"><th>Saldo pendiente del socio</th><td class="fw-bold">$<?php echo number_format(abs((float) $resultado['saldo_liquidacion']), 0, ',', '.'); ?></td></tr>
                    <?php else: ?>
                        <tr class="table-success"><th>Valor neto a entregar al socio</th><td class="fw-bold">$<?php echo number_format($resultado['valor_neto'], 0, ',', '.'); ?></td></tr>
                    <?php endif; ?>
                    <tr><th>Valor de pollas informativo</th><td>$<?php echo number_format($resultado['valor_pollas'], 0, ',', '.'); ?></td></tr>
                    </tbody>
                </table>
            </div>

            <h6>Relación de préstamos descontados</h6>
            <div class="table-responsive mb-3">
                <table class="table table-sm table-striped table-bordered align-middle">
                    <thead>
                    <tr>
                        <th>ID préstamo</th>
                        <th>Estado</th>
                        <th class="text-end">Capital pendiente</th>
                        <th class="text-end">Intereses pendientes</th>
                        <th class="text-end">Total pendiente</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($resultado['prestamos_descontados'])): ?>
                        <tr><td colspan="5" class="text-center text-muted">No hay préstamos activos o en mora para descontar.</td></tr>
                    <?php else: ?>
                        <?php foreach ($resultado['prestamos_descontados'] as $prestamo): ?>
                            <tr>
                                <td><?php echo (int) $prestamo['id_prestamo']; ?></td>
                                <td><?php echo clean($prestamo['estado']); ?></td>
                                <td class="text-end">$<?php echo number_format($prestamo['capital_pendiente'], 0, ',', '.'); ?></td>
                                <td class="text-end">$<?php echo number_format($prestamo['intereses_pendientes'], 0, ',', '.'); ?></td>
                                <td class="text-end fw-semibold">$<?php echo number_format($prestamo['total_pendiente'], 0, ',', '.'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <p class="small text-muted mb-2">Fórmula auditada: saldo de liquidación = ahorro acumulado + rendimientos - capital pendiente - intereses pendientes - cuota de administración. Ese único saldo determina si se paga al socio o si queda un saldo pendiente; no se recalcula ningún déficit sobre el préstamo.</p>
            <?php if ($resultado['valor_cuota_manejo'] > 0 && $resultado['deficit'] <= 0): ?>
                <div class="alert alert-info py-2">
                    Se entregan <strong>$<?php echo number_format((float) $resultado['valor_neto'], 0, ',', '.'); ?></strong> al socio y
                    <strong>$<?php echo number_format((float) $resultado['valor_cuota_manejo'], 0, ',', '.'); ?></strong> pasan a administración.
                </div>
            <?php endif; ?>
            <div class="alert alert-secondary py-2">¿Confirma registrar esta liquidación?</div>
            <form method="post" action="../actions/liquidaciones_save.php" onsubmit="return confirm('¿Confirma registrar esta liquidación?');">
                <input type="hidden" name="accion" value="crear">
                <input type="hidden" name="confirmar_liquidacion" value="1">
                <input type="hidden" name="tipo_liquidacion" value="<?php echo clean($tipoLiquidacion); ?>">
                <input type="hidden" name="id_socio" value="<?php echo (int) $idSocio; ?>">
                <input type="hidden" name="cuota_manejo" value="<?php echo number_format($cuotaManejo, 2, '.', ''); ?>">
                <input type="hidden" name="id_actividad_liquidacion" value="<?php echo (int) $idActividadLiquidacion; ?>">
                <input type="hidden" name="id_actividad_retencion" value="<?php echo (int) $idActividadRetencion; ?>">
                <input type="hidden" name="observaciones" value="Liquidación <?php echo clean($tipoLiquidacion); ?> desde módulo central.">
                <button class="btn btn-success" type="submit"><i class="bi bi-check-circle"></i> Confirmar</button>
                <a class="btn btn-outline-secondary" href="liquidaciones.php">Cancelar</a>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($liquidacionEditar): ?>
    <div class="card mb-4 border-warning">
        <div class="card-header"><i class="bi bi-pencil-square"></i> Editar liquidación #<?php echo (int) $liquidacionEditar['id']; ?></div>
        <div class="card-body">
            <form method="post" action="../actions/liquidaciones_save.php" class="row g-3" onsubmit="return confirm('¿Guardar cambios y recalcular movimientos?');">
                <input type="hidden" name="accion" value="editar">
                <input type="hidden" name="id_liquidacion" value="<?php echo (int) $liquidacionEditar['id']; ?>">
                <div class="col-md-3">
                    <label class="form-label">Tipo</label>
                    <select class="form-select" name="tipo_liquidacion">
                        <?php foreach ($tipos as $clave => $etiqueta): ?>
                            <option value="<?php echo $clave; ?>" <?php echo ($liquidacionEditar['tipo_liquidacion'] === $clave) ? 'selected' : ''; ?>><?php echo clean($etiqueta); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Cuota administración</label>
                    <input type="number" class="form-control" step="0.01" min="0" name="cuota_manejo" value="<?php echo number_format((float) $liquidacionEditar['valor_cuota_manejo'], 2, '.', ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Actividad principal</label>
                    <select class="form-select" name="id_actividad_liquidacion" required>
                        <option value="">Seleccione...</option>
                        <?php foreach ($actividades as $actividad): ?>
                            <option value="<?php echo (int) $actividad['id_actividad']; ?>" <?php echo (int) $liquidacionEditar['actividad_liquidacion_id'] === (int) $actividad['id_actividad'] ? 'selected' : ''; ?>>
                                <?php echo clean($actividad['nombre_actividad']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Actividad retención administración</label>
                    <select class="form-select" name="id_actividad_retencion">
                        <option value="">Seleccione...</option>
                        <?php foreach ($actividades as $actividad): ?>
                            <option value="<?php echo (int) $actividad['id_actividad']; ?>" <?php echo (int) $liquidacionEditar['actividad_cuota_id'] === (int) $actividad['id_actividad'] ? 'selected' : ''; ?>>
                                <?php echo clean($actividad['nombre_actividad']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-9">
                    <label class="form-label">Observaciones</label>
                    <input class="form-control" type="text" name="observaciones" value="<?php echo clean((string) $liquidacionEditar['observaciones']); ?>">
                </div>
                <div class="col-md-12 d-grid">
                    <button class="btn btn-warning" type="submit">Guardar edición</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><i class="bi bi-clock-history"></i> Historial de liquidaciones</div>
    <div class="card-body">
        <form method="get" class="row g-2 mb-3">
            <div class="col-md-3">
                <select class="form-select" name="filtro_socio">
                    <option value="0">Todos los socios</option>
                    <?php foreach ($socios as $socio): ?>
                        <option value="<?php echo (int) $socio['id_socio']; ?>" <?php echo $filtroSocio === (int) $socio['id_socio'] ? 'selected' : ''; ?>><?php echo clean($socio['nombre_completo']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select class="form-select" name="filtro_tipo">
                    <option value="">Todos los tipos</option>
                    <?php foreach ($tipos as $clave => $etiqueta): ?>
                        <option value="<?php echo $clave; ?>" <?php echo $filtroTipo === $clave ? 'selected' : ''; ?>><?php echo clean($etiqueta); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select class="form-select" name="filtro_estado">
                    <?php foreach (['activa', 'anulada', 'editada', 'todas'] as $estado): ?>
                        <option value="<?php echo $estado; ?>" <?php echo $filtroEstado === $estado ? 'selected' : ''; ?>><?php echo ucfirst($estado); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-grid">
                <button class="btn btn-outline-primary">Filtrar</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                <tr>
                    <th>#</th><th>Fecha</th><th>Socio</th><th>Tipo</th><th>Bruto</th><th>Cuota</th><th>Neto</th><th>Estado</th><th>Acciones</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($historial as $fila): ?>
                    <tr>
                        <td><?php echo (int) $fila['id']; ?></td>
                        <td><?php echo clean($fila['fecha']); ?></td>
                        <td><?php echo clean($fila['nombre_completo']); ?></td>
                        <td><?php echo clean($tipos[$fila['tipo_liquidacion']] ?? $fila['tipo_liquidacion']); ?></td>
                        <td>$<?php echo number_format((float) $fila['valor_bruto'], 0, ',', '.'); ?></td>
                        <td>$<?php echo number_format((float) $fila['valor_cuota_manejo'], 0, ',', '.'); ?></td>
                        <td>$<?php echo number_format((float) $fila['valor_neto'], 0, ',', '.'); ?></td>
                        <td><span class="badge text-bg-<?php echo $fila['estado'] === 'activa' ? 'success' : ($fila['estado'] === 'anulada' ? 'secondary' : 'warning'); ?>"><?php echo clean($fila['estado']); ?></span></td>
                        <td>
                            <?php if ($fila['estado'] === 'activa'): ?>
                                <a class="btn btn-sm btn-outline-warning" href="liquidaciones.php?editar=<?php echo (int) $fila['id']; ?>">Editar</a>
                                <form method="post" action="../actions/liquidaciones_save.php" class="d-inline" onsubmit="return confirm('¿Anular liquidación y revertir sus movimientos?');">
                                    <input type="hidden" name="accion" value="anular">
                                    <input type="hidden" name="id_liquidacion" value="<?php echo (int) $fila['id']; ?>">
                                    <button class="btn btn-sm btn-outline-danger">Anular</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
