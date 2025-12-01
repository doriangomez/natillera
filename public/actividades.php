<?php
require_once __DIR__ . '/../includes/auth.php';
checkAdmin();
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$actividades = getActividades($pdo, false, true);
$editId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editData = null;
if ($editId) {
    $editData = getActividad($pdo, $editId);
}
?>
<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <p class="text-muted small mb-1">Define reglas y flags para cada actividad registrada</p>
        <h1 class="h4 mb-0">Maestro de actividades</h1>
    </div>
    <?php if ($editData): ?>
        <a class="btn btn-outline-secondary" href="actividades.php">Limpiar edición</a>
    <?php endif; ?>
</div>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h6 mb-0"><?php echo $editData ? 'Editar actividad' : 'Nueva actividad'; ?></h2>
                    <span class="badge bg-dark">Configuración</span>
                </div>
                <form method="POST" action="../actions/actividades_save.php">
                    <input type="hidden" name="id_actividad" value="<?php echo $editData['id_actividad'] ?? ''; ?>">
                    <div class="mb-3">
                        <label class="form-label">Nombre</label>
                        <input type="text" name="nombre_actividad" class="form-control" required value="<?php echo clean($editData['nombre_actividad'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descripción</label>
                        <textarea name="descripcion" class="form-control" rows="3"><?php echo clean($editData['descripcion'] ?? ''); ?></textarea>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Afecta saldo socio</label>
                            <select class="form-select" name="afecta_saldo_socio">
                                <?php $opts=['suma','resta','neutral']; foreach($opts as $o): ?>
                                    <option value="<?php echo $o; ?>" <?php echo (isset($editData['afecta_saldo_socio']) && $editData['afecta_saldo_socio']===$o)?'selected':''; ?>><?php echo ucfirst($o); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Afecta saldo natillera</label>
                            <select class="form-select" name="afecta_saldo_natillera">
                                <?php foreach($opts as $o): ?>
                                    <option value="<?php echo $o; ?>" <?php echo (isset($editData['afecta_saldo_natillera']) && $editData['afecta_saldo_natillera']===$o)?'selected':''; ?>><?php echo ucfirst($o); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row g-3 mt-1">
                        <div class="col-md-6 form-check">
                            <input class="form-check-input" type="checkbox" id="flagPrestamo" name="es_prestamo" value="1" <?php echo (!empty($editData['es_prestamo']))?'checked':''; ?>>
                            <label class="form-check-label" for="flagPrestamo">Es préstamo</label>
                        </div>
                        <div class="col-md-6 form-check">
                            <input class="form-check-input" type="checkbox" id="flagPagoPrestamo" name="es_pago_prestamo" value="1" <?php echo (!empty($editData['es_pago_prestamo']))?'checked':''; ?>>
                            <label class="form-check-label" for="flagPagoPrestamo">Es pago préstamo</label>
                        </div>
                        <div class="col-md-6 form-check">
                            <input class="form-check-input" type="checkbox" id="flagPolla" name="es_polla" value="1" <?php echo (!empty($editData['es_polla']))?'checked':''; ?>>
                            <label class="form-check-label" for="flagPolla">Es polla</label>
                        </div>
                        <div class="col-md-6 form-check">
                            <input class="form-check-input" type="checkbox" id="flagGasto" name="es_gasto_general" value="1" <?php echo (!empty($editData['es_gasto_general']))?'checked':''; ?>>
                            <label class="form-check-label" for="flagGasto">Es gasto general</label>
                        </div>
                    </div>
                    <input type="hidden" name="activo" value="<?php echo $editData['activo'] ?? 1; ?>">
                    <div class="d-flex gap-2 mt-4">
                        <button class="btn btn-primary" type="submit">Guardar</button>
                        <?php if ($editData): ?>
                            <a class="btn btn-outline-secondary" href="actividades.php">Cancelar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h6 mb-0">Actividades registradas</h2>
                    <a class="btn btn-outline-primary btn-sm" href="../actions/export_csv.php?tipo=pyg">Exportar PYG</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Nombre</th>
                                <th>Saldo socio</th>
                                <th>Saldo natillera</th>
                                <th>Flags</th>
                                <th>Estado</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($actividades as $a): ?>
                                <tr>
                                    <td><?php echo $a['id_actividad']; ?></td>
                                    <td><?php echo clean($a['nombre_actividad']); ?></td>
                                    <td><?php echo $a['afecta_saldo_socio']; ?></td>
                                    <td><?php echo $a['afecta_saldo_natillera']; ?></td>
                                    <td class="small">
                                        <?php echo $a['es_prestamo'] ? 'Préstamo ' : ''; ?>
                                        <?php echo $a['es_pago_prestamo'] ? 'Pago préstamo ' : ''; ?>
                                        <?php echo $a['es_polla'] ? 'Polla ' : ''; ?>
                                        <?php echo $a['es_gasto_general'] ? 'Gasto ' : ''; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $a['activo'] ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $a['activo'] ? 'Activa' : 'Inactiva'; ?></span>
                                    </td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-primary" href="?id=<?php echo $a['id_actividad']; ?>">Editar</a>
                                        <form method="POST" action="../actions/actividades_estado.php" class="d-inline">
                                            <input type="hidden" name="id_actividad" value="<?php echo $a['id_actividad']; ?>">
                                            <input type="hidden" name="estado" value="<?php echo $a['activo'] ? 0 : 1; ?>">
                                            <input type="hidden" name="redirect" value="../public/actividades.php">
                                            <button class="btn btn-sm <?php echo $a['activo'] ? 'btn-outline-danger' : 'btn-outline-success'; ?>" type="submit">
                                                <?php echo $a['activo'] ? 'Desactivar' : 'Activar'; ?>
                                            </button>
                                        </form>
                                        <form method="POST" action="../actions/actividades_save.php" class="d-inline" onsubmit="return confirm('Esta acción eliminará la actividad y todos sus movimientos asociados. ¿Deseas continuar?');">
                                            <input type="hidden" name="id_actividad" value="<?php echo $a['id_actividad']; ?>">
                                            <input type="hidden" name="accion" value="eliminar">
                                            <button class="btn btn-sm btn-outline-danger" type="submit">Eliminar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
