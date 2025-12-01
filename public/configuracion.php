<?php
require_once __DIR__ . '/../includes/auth.php';
checkAdmin();
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$config = getConfiguracionGeneral($pdo);
$actividades = getActividades($pdo, false, true);
$medios = getMediosPago($pdo, true);
$editId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editData = $editId ? getActividad($pdo, $editId) : null;
$medioId = isset($_GET['medio_id']) ? (int) $_GET['medio_id'] : 0;
$medioData = $medioId ? getMedioPago($pdo, $medioId) : null;
?>
<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <p class="text-muted small mb-1">Panel exclusivo para administradores</p>
        <h1 class="h4 mb-0">Configuración</h1>
    </div>
    <?php if (isset($_GET['guardado'])): ?>
        <span class="badge bg-success">Cambios guardados</span>
    <?php endif; ?>
</div>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h2 class="h6 mb-0">Parámetros generales</h2>
                        <p class="text-muted small mb-0">Nombre del sistema, logo y datos globales</p>
                    </div>
                    <span class="badge bg-dark">Admin</span>
                </div>
                <form method="POST" action="../actions/configuracion_save.php" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label">Nombre del sistema</label>
                        <input type="text" name="nombre_sistema" class="form-control" required value="<?php echo clean($config['nombre_sistema'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Logo (opcional)</label>
                        <input type="file" name="logo" class="form-control" accept="image/*">
                        <?php if (!empty($config['logo_archivo'])): ?>
                            <div class="mt-2">
                                <span class="text-muted small d-block">Logo actual:</span>
                                <img src="assets/logo/<?php echo clean($config['logo_archivo']); ?>" alt="Logo" class="img-fluid rounded" style="max-height: 80px;">
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Datos globales</label>
                        <textarea name="datos_globales" rows="4" class="form-control"><?php echo clean($config['datos_globales'] ?? ''); ?></textarea>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" type="submit">Guardar parámetros</button>
                        <a class="btn btn-outline-secondary" href="index.php">Volver al panel</a>
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
                        <h2 class="h6 mb-0">Maestro de actividades</h2>
                        <p class="text-muted small mb-0">Crea, edita o desactiva actividades</p>
                    </div>
                    <?php if ($editData): ?>
                        <a class="btn btn-outline-secondary btn-sm" href="configuracion.php">Salir de edición</a>
                    <?php endif; ?>
                </div>
                <form method="POST" action="../actions/actividades_save.php" class="mb-4">
                    <input type="hidden" name="id_actividad" value="<?php echo $editData['id_actividad'] ?? ''; ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Nombre</label>
                            <input type="text" name="nombre_actividad" class="form-control" required value="<?php echo clean($editData['nombre_actividad'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Descripción</label>
                            <input type="text" name="descripcion" class="form-control" value="<?php echo clean($editData['descripcion'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Saldo socio</label>
                            <select class="form-select" name="afecta_saldo_socio">
                                <?php $opts=['suma','resta','neutral']; foreach($opts as $o): ?>
                                    <option value="<?php echo $o; ?>" <?php echo (isset($editData['afecta_saldo_socio']) && $editData['afecta_saldo_socio']===$o)?'selected':''; ?>><?php echo ucfirst($o); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Saldo natillera</label>
                            <select class="form-select" name="afecta_saldo_natillera">
                                <?php foreach($opts as $o): ?>
                                    <option value="<?php echo $o; ?>" <?php echo (isset($editData['afecta_saldo_natillera']) && $editData['afecta_saldo_natillera']===$o)?'selected':''; ?>><?php echo ucfirst($o); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 form-check">
                            <input class="form-check-input" type="checkbox" id="cfgPrestamo" name="es_prestamo" value="1" <?php echo (!empty($editData['es_prestamo']))?'checked':''; ?>>
                            <label class="form-check-label" for="cfgPrestamo">Préstamo</label>
                        </div>
                        <div class="col-md-3 form-check">
                            <input class="form-check-input" type="checkbox" id="cfgPago" name="es_pago_prestamo" value="1" <?php echo (!empty($editData['es_pago_prestamo']))?'checked':''; ?>>
                            <label class="form-check-label" for="cfgPago">Pago préstamo</label>
                        </div>
                        <div class="col-md-3 form-check">
                            <input class="form-check-input" type="checkbox" id="cfgPolla" name="es_polla" value="1" <?php echo (!empty($editData['es_polla']))?'checked':''; ?>>
                            <label class="form-check-label" for="cfgPolla">Polla</label>
                        </div>
                        <div class="col-md-3 form-check">
                            <input class="form-check-input" type="checkbox" id="cfgGasto" name="es_gasto_general" value="1" <?php echo (!empty($editData['es_gasto_general']))?'checked':''; ?>>
                            <label class="form-check-label" for="cfgGasto">Gasto general</label>
                        </div>
                    </div>
                    <input type="hidden" name="activo" value="<?php echo $editData['activo'] ?? 1; ?>">
                    <div class="d-flex gap-2 mt-3">
                        <button class="btn btn-primary" type="submit"><?php echo $editData ? 'Actualizar actividad' : 'Crear actividad'; ?></button>
                        <?php if ($editData): ?>
                            <a class="btn btn-outline-secondary" href="configuracion.php">Cancelar</a>
                        <?php endif; ?>
                    </div>
                </form>
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Nombre</th>
                                <th>Socio</th>
                                <th>Natillera</th>
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
                                            <input type="hidden" name="redirect" value="../public/configuracion.php">
                                            <button class="btn btn-sm <?php echo $a['activo'] ? 'btn-outline-danger' : 'btn-outline-success'; ?>" type="submit">
                                                <?php echo $a['activo'] ? 'Desactivar' : 'Activar'; ?>
                                            </button>
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
    <div class="col-lg-12">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h2 class="h6 mb-0">Medios de pago / consignación</h2>
                        <p class="text-muted small mb-0">Administra las cuentas o canales donde se reciben consignaciones</p>
                    </div>
                    <?php if ($medioData): ?>
                        <a class="btn btn-outline-secondary btn-sm" href="configuracion.php">Salir de edición</a>
                    <?php endif; ?>
                </div>
                <form method="POST" action="../actions/medios_pago_save.php" class="mb-4">
                    <input type="hidden" name="id" value="<?php echo $medioData['id'] ?? ''; ?>">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label">Nombre del medio</label>
                            <input type="text" name="nombre" class="form-control" required value="<?php echo clean($medioData['nombre'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Descripción</label>
                            <input type="text" name="descripcion" class="form-control" value="<?php echo clean($medioData['descripcion'] ?? ''); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Activo</label>
                            <select name="activo" class="form-select">
                                <option value="1" <?php echo (!isset($medioData['activo']) || $medioData['activo']) ? 'selected' : ''; ?>>Sí</option>
                                <option value="0" <?php echo (isset($medioData['activo']) && !$medioData['activo']) ? 'selected' : ''; ?>>No</option>
                            </select>
                        </div>
                    </div>
                    <button class="btn btn-primary mt-3" type="submit">Guardar medio de pago</button>
                </form>
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Nombre</th>
                                <th>Descripción</th>
                                <th>Estado</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($medios as $m): ?>
                                <tr>
                                    <td><?php echo $m['id']; ?></td>
                                    <td><?php echo clean($m['nombre']); ?></td>
                                    <td class="small text-muted"><?php echo clean($m['descripcion']); ?></td>
                                    <td><span class="badge <?php echo $m['activo'] ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $m['activo'] ? 'Activo' : 'Inactivo'; ?></span></td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-primary" href="?medio_id=<?php echo $m['id']; ?>">Editar</a>
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
