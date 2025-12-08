<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/rifas_helpers.php';

sincronizarActividadesRifa($pdo);
asegurarEsquemaRifas($pdo);

$socios = getSocios($pdo);
$mediosPago = getMediosPago($pdo);
$actividadesRifa = getActividades($pdo, false, true, true);
$actividadesIngreso = array_filter($actividadesRifa, fn($a) => (int) ($a['es_ingreso'] ?? 0) === 1);
$actividadesPremio = array_filter($actividadesRifa, fn($a) => (int) ($a['es_ingreso'] ?? 0) === 0);
$rifas = obtenerRifas($pdo);
$idRifaSeleccionada = isset($_GET['id_rifa']) ? (int) $_GET['id_rifa'] : ((int) ($rifas[0]['id_rifa'] ?? 0));
$rifaActual = $idRifaSeleccionada ? obtenerRifa($pdo, $idRifaSeleccionada) : null;
$boletas = $rifaActual ? obtenerBoletasRifa($pdo, $idRifaSeleccionada) : [];
$resumen = $rifaActual ? obtenerResumenBoletas($pdo, $idRifaSeleccionada) : [];
?>
<h2 class="mb-3 d-flex align-items-center gap-2"><i class="bi bi-stars text-primary"></i><span>Módulo de rifas</span></h2>
<?php if (!empty($_SESSION['error'])): ?>
    <div class="alert alert-danger"><?php echo clean($_SESSION['error']); unset($_SESSION['error']); ?></div>
<?php endif; ?>
<?php if (!empty($_SESSION['exito'])): ?>
    <div class="alert alert-success"><?php echo clean($_SESSION['exito']); unset($_SESSION['exito']); ?></div>
<?php endif; ?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div>
                        <p class="text-muted mb-1">Nueva actividad contable</p>
                        <h5 class="mb-0">Crear rifa</h5>
                    </div>
                    <span class="badge bg-primary-subtle text-primary"><i class="bi bi-shuffle me-1"></i>Asignación automática</span>
                </div>
                <form method="POST" action="../actions/rifas_save.php" class="row g-2">
                    <input type="hidden" name="accion" value="crear_rifa">
                    <div class="col-12">
                        <label class="form-label">Nombre</label>
                        <input type="text" name="nombre" class="form-control" required placeholder="Ej: Rifa abril 2025">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Fecha inicio</label>
                        <input type="date" name="fecha_inicio" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Fecha fin</label>
                        <input type="date" name="fecha_fin" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Valor por boleta</label>
                        <input type="number" name="valor_boleta" class="form-control" min="1" step="0.01" value="10000" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Cantidad de boletas</label>
                        <input type="number" name="cantidad_boletas" class="form-control" min="1" max="500" value="100" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Actividad ingreso</label>
                        <select name="id_actividad_ingreso" class="form-select" required>
                            <?php foreach ($actividadesIngreso as $act): ?>
                                <option value="<?php echo (int) $act['id_actividad']; ?>"><?php echo clean($act['nombre_actividad']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Actividad premio</label>
                        <select name="id_actividad_premio" class="form-select" required>
                            <?php foreach ($actividadesPremio as $act): ?>
                                <option value="<?php echo (int) $act['id_actividad']; ?>"><?php echo clean($act['nombre_actividad']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Observaciones</label>
                        <textarea name="observaciones" rows="2" class="form-control" placeholder="Notas generales"></textarea>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary w-100"><i class="bi bi-plus-circle me-1"></i>Crear rifa</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <p class="text-muted mb-1">Resumen operativo</p>
                        <h5 class="mb-0">Rifas activas y cerradas</h5>
                    </div>
                    <a href="rifas.php" class="btn btn-outline-secondary btn-sm">Refrescar</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <thead>
                            <tr><th>Rifa</th><th>Periodo</th><th>Boletas</th><th>Recaudo</th><th>Estado</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rifas as $rifa): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo clean($rifa['nombre']); ?></td>
                                <td><?php echo clean($rifa['fecha_inicio']); ?> - <?php echo clean($rifa['fecha_fin']); ?></td>
                                <td>
                                    <span class="badge bg-success-subtle text-success"><?php echo (int) $rifa['boletas_pagadas']; ?> pagadas</span>
                                    <span class="badge bg-secondary-subtle text-secondary"><?php echo (int) $rifa['boletas_pendientes']; ?> pendientes</span>
                                </td>
                                <td>$<?php echo number_format($rifa['total_recaudado'], 0, ',', '.'); ?></td>
                                <td><span class="badge <?php echo $rifa['estado'] === 'cerrada' ? 'bg-dark-subtle text-dark' : 'bg-primary-subtle text-primary'; ?>"><?php echo clean($rifa['estado']); ?></span></td>
                                <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="?id_rifa=<?php echo (int) $rifa['id_rifa']; ?>">Gestionar</a></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($rifas)): ?>
                            <tr><td colspan="6" class="text-center text-muted">Aún no hay rifas registradas.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php if ($rifaActual): ?>
    <hr class="my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <p class="text-muted mb-1">Administración de boletas</p>
            <h4 class="mb-0"><?php echo clean($rifaActual['nombre']); ?></h4>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <span class="badge bg-success-subtle text-success">Pagadas: <?php echo $resumen['pagada']['cantidad'] ?? 0; ?></span>
            <span class="badge bg-warning-subtle text-warning">Pendientes: <?php echo $resumen['pendiente']['cantidad'] ?? 0; ?></span>
            <span class="badge bg-secondary-subtle text-secondary">Total: <?php echo $rifaActual['cantidad_boletas']; ?></span>
        </div>
    </div>
    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="mb-3 d-flex align-items-center gap-2"><i class="bi bi-pencil-square text-primary"></i><span>Ajuste manual de boleta</span></h6>
                    <form method="POST" action="../actions/rifas_save.php" class="row g-2">
                        <input type="hidden" name="accion" value="reasignar_boleta">
                        <input type="hidden" name="id_rifa" value="<?php echo (int) $rifaActual['id_rifa']; ?>">
                        <div class="col-12">
                            <label class="form-label">Boleta actual</label>
                            <input type="text" name="numero_actual" class="form-control" maxlength="3" required placeholder="Ej: 05">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Nuevo número</label>
                            <input type="text" name="numero_nuevo" class="form-control" maxlength="3" required placeholder="Ej: 25">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Socio</label>
                            <select name="id_socio" class="form-select">
                                <option value="">Mantener asignado</option>
                                <?php foreach ($socios as $s): ?>
                                    <option value="<?php echo (int) $s['id_socio']; ?>"><?php echo clean($s['nombre_completo']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Motivo</label>
                            <input type="text" name="motivo" class="form-control" placeholder="Motivo del ajuste">
                        </div>
                        <div class="col-12">
                            <button class="btn btn-outline-primary w-100">Guardar cambio</button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="mb-3 d-flex align-items-center gap-2"><i class="bi bi-cash-coin text-success"></i><span>Registrar pago de boleta</span></h6>
                    <form method="POST" action="../actions/rifas_save.php" class="row g-2">
                        <input type="hidden" name="accion" value="pagar_boleta">
                        <input type="hidden" name="id_rifa" value="<?php echo (int) $rifaActual['id_rifa']; ?>">
                        <div class="col-12">
                            <label class="form-label">Número</label>
                            <input type="text" name="numero" class="form-control" maxlength="3" required placeholder="Ej: 10">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Fecha pago</label>
                            <input type="date" name="fecha_pago" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Medio</label>
                            <input type="text" name="medio" class="form-control" required placeholder="Efectivo / Transferencia">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Medio configurado</label>
                            <select name="id_medio_pago" class="form-select">
                                <option value="">Seleccionar</option>
                                <?php foreach ($mediosPago as $mp): ?>
                                    <option value="<?php echo (int) $mp['id']; ?>"><?php echo clean($mp['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-success w-100">Registrar pago</button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <h6 class="mb-3 d-flex align-items-center gap-2"><i class="bi bi-award text-danger"></i><span>Registrar premio</span></h6>
                    <form method="POST" action="../actions/rifas_save.php" class="row g-2">
                        <input type="hidden" name="accion" value="registrar_premio">
                        <input type="hidden" name="id_rifa" value="<?php echo (int) $rifaActual['id_rifa']; ?>">
                        <div class="col-12">
                            <label class="form-label">Número ganador</label>
                            <input type="text" name="numero_ganador" class="form-control" maxlength="3" required placeholder="Ej: 33">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Valor del premio</label>
                            <input type="number" name="valor_premio" class="form-control" min="1" step="0.01" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Fecha de pago</label>
                            <input type="date" name="fecha_pago" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Medio</label>
                            <input type="text" name="medio" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Medio configurado</label>
                            <select name="id_medio_pago" class="form-select">
                                <option value="">Seleccionar</option>
                                <?php foreach ($mediosPago as $mp): ?>
                                    <option value="<?php echo (int) $mp['id']; ?>"><?php echo clean($mp['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-danger w-100">Registrar premio</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <p class="text-muted mb-1">Control de boletas</p>
                            <h5 class="mb-0">Asignaciones y pagos</h5>
                        </div>
                        <span class="badge bg-info-subtle text-info">Valor unitario: $<?php echo number_format($rifaActual['valor_boleta'],0,',','.'); ?></span>
                    </div>
                    <div class="table-responsive" style="max-height: 520px;">
                        <table class="table table-sm table-bordered align-middle">
                            <thead class="table-light"><tr><th>Número</th><th>Socio</th><th>Estado</th><th>Valor</th><th>Fecha pago</th></tr></thead>
                            <tbody>
                                <?php foreach ($boletas as $boleta): ?>
                                    <tr>
                                        <td class="fw-semibold">#<?php echo clean($boleta['numero']); ?></td>
                                        <td><?php echo clean($boleta['nombre_completo'] ?? '—'); ?></td>
                                        <td>
                                            <?php if ($boleta['estado'] === 'pagada'): ?>
                                                <span class="badge bg-success-subtle text-success">Pagada</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning-subtle text-warning">Pendiente</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>$<?php echo number_format($boleta['valor'],0,',','.'); ?></td>
                                        <td><?php echo $boleta['fecha_pago'] ? clean(date('Y-m-d', strtotime($boleta['fecha_pago']))) : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($boletas)): ?>
                                    <tr><td colspan="5" class="text-center text-muted">Aún no se han generado boletas.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
