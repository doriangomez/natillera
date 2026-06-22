<?php
require_once __DIR__ . '/../includes/auth.php';
checkAdmin();
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$config = getConfiguracionGeneral($pdo);
$actividades = getActividades($pdo, false, true);
$categoriasActividades = getCategoriasActividades($pdo);
$medios = getMediosPago($pdo, true);
$periodosConfig = getPeriodosConfiguracion($pdo);
$usuarios = getUsuarios($pdo);
$periodoError = $_GET['periodo_error'] ?? '';
$periodoGuardado = isset($_GET['periodo_guardado']);
$anioActual = (int) date('Y');
$anioInicio = 2025;
$maxAnioPermitido = $anioActual + 10;
$aniosPeriodo = range($anioInicio, $maxAnioPermitido);
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
                    <input type="hidden" name="nombre_sistema" value="<?php echo clean($config['nombre_sistema'] ?? ''); ?>">
                    <input type="hidden" name="datos_globales" value="<?php echo clean($config['datos_globales'] ?? ''); ?>">
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
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Tasa interés socio (%)</label>
                            <input type="number" step="0.01" min="0" name="tasa_interes_socio" class="form-control" value="<?php echo clean($config['tasa_interes_socio'] ?? 0); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Tasa interés deudor particular (%)</label>
                            <input type="number" step="0.01" min="0" name="tasa_interes_particular" class="form-control" value="<?php echo clean($config['tasa_interes_particular'] ?? 0); ?>">
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label">Concepto para pago de cuota de socio</label>
                        <select name="actividad_pago_cuota" class="form-select">
                            <option value="0">Selecciona un concepto del maestro de actividades</option>
                            <?php foreach ($actividades as $a): ?>
                                <?php if (!actividadValidaParaCausacion($a)) { continue; } ?>
                                <option value="<?php echo (int) $a['id_actividad']; ?>" <?php echo ((int) ($config['actividad_pago_cuota'] ?? 0) === (int) $a['id_actividad']) ? 'selected' : ''; ?>>
                                    <?php echo clean($a['nombre_actividad']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Este concepto se usará para calcular la matriz de pagos de cuotas por socio.</div>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" type="submit">Guardar parámetros</button>
                        <a class="btn btn-outline-secondary" href="index.php">Volver al panel</a>
                    </div>
                </form>
            </div>
        </div>
        <div class="card mt-3" id="reglamento">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h2 class="h6 mb-0">Reglamento</h2>
                        <p class="text-muted small mb-0">Carga o descarga el reglamento en formato PDF</p>
                    </div>
                    <span class="badge bg-dark">Admin</span>
                </div>
                <form method="POST" action="../actions/configuracion_save.php" enctype="multipart/form-data">
                    <input type="hidden" name="nombre_sistema" value="<?php echo clean($config['nombre_sistema'] ?? ''); ?>">
                    <input type="hidden" name="datos_globales" value="<?php echo clean($config['datos_globales'] ?? ''); ?>">
                    <div class="mb-3">
                        <label class="form-label">Archivo PDF</label>
                        <input type="file" name="reglamento_pdf" class="form-control" accept="application/pdf">
                        <?php if (!empty($config['reglamento_archivo'])): ?>
                            <div class="mt-3 p-3 border rounded bg-light">
                                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                    <div>
                                        <span class="text-muted small d-block">Archivo actual</span>
                                        <strong><?php echo clean($config['reglamento_archivo']); ?></strong>
                                    </div>
                                    <a class="btn btn-outline-primary btn-sm" href="../actions/reglamento_download.php">
                                        <i class="bi bi-download"></i> Descargar
                                    </a>
                                </div>
                            </div>
                        <?php else: ?>
                            <p class="text-muted small mt-2 mb-0">No hay reglamento cargado.</p>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" type="submit">Subir / reemplazar</button>
                        <a class="btn btn-outline-secondary" href="index.php">Volver al panel</a>
                    </div>
                </form>
            </div>
        </div>
        <div class="card mt-3" id="periodos">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h2 class="h6 mb-0">Periodos configurados</h2>
                        <p class="text-muted small mb-0">Define los meses habilitados para movimientos y conciliación</p>
                    </div>
                    <span class="badge bg-dark">Admin</span>
                </div>
                <?php if ($periodoError): ?>
                    <div class="alert alert-danger py-2"><?php echo clean($periodoError); ?></div>
                <?php elseif ($periodoGuardado): ?>
                    <div class="alert alert-success py-2 mb-2">Periodo guardado correctamente.</div>
                <?php endif; ?>
                <form class="row g-2 align-items-end" method="POST" action="../actions/periodos_configuracion_save.php">
                    <div class="col-md-5">
                        <label class="form-label">Año</label>
                        <select name="anio" class="form-select">
                            <?php foreach ($aniosPeriodo as $a): ?>
                                <option value="<?php echo $a; ?>"><?php echo $a; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Mes</label>
                        <select name="mes" class="form-select">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>"><?php echo strftime('%B', mktime(0, 0, 0, $m, 1)); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Activo</label>
                        <select name="activo" class="form-select">
                            <option value="1" selected>Sí</option>
                            <option value="0">No</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary" type="submit">Agregar / actualizar periodo</button>
                    </div>
                </form>
                <div class="table-responsive mt-3">
                    <table class="table table-striped align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Mes</th>
                                <th>Año</th>
                                <th>Estado</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($periodosConfig)): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted small">No hay periodos configurados. Agrega uno para habilitar la conciliación.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($periodosConfig as $p): ?>
                                    <tr>
                                        <td><?php echo strftime('%B', mktime(0, 0, 0, $p['mes'], 1)); ?></td>
                                        <td><?php echo $p['anio']; ?></td>
                                        <td><span class="badge <?php echo $p['activo'] ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $p['activo'] ? 'Activo' : 'Inactivo'; ?></span></td>
                                        <td class="text-end">
                                            <form method="POST" action="../actions/periodos_configuracion_save.php" class="d-inline">
                                                <input type="hidden" name="toggle_id" value="<?php echo $p['id']; ?>">
                                                <input type="hidden" name="estado" value="<?php echo $p['activo'] ? 0 : 1; ?>">
                                                <button class="btn btn-sm <?php echo $p['activo'] ? 'btn-outline-danger' : 'btn-outline-success'; ?>" type="submit">
                                                    <?php echo $p['activo'] ? 'Desactivar' : 'Activar'; ?>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="card mt-3" id="usuarios">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h2 class="h6 mb-0">Usuarios y contraseñas</h2>
                        <p class="text-muted small mb-0">Crea cuentas nuevas y actualiza las existentes</p>
                    </div>
                    <span class="badge bg-dark">Admin</span>
                </div>
                <form method="POST" action="../actions/usuarios_create.php" class="border rounded p-3 mb-3">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                        <div>
                            <div class="fw-semibold">Crear nuevo usuario</div>
                            <div class="text-muted small">Asigna credenciales de acceso para otro administrador</div>
                        </div>
                        <span class="badge bg-success-subtle text-success">Alta de cuenta</span>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label">Usuario</label>
                            <input type="text" name="usuario" class="form-control" required minlength="3" maxlength="50" autocomplete="username">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Contraseña</label>
                            <input type="password" name="password" class="form-control" required minlength="8" autocomplete="new-password">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Confirmar contraseña</label>
                            <input type="password" name="confirmar_password" class="form-control" required minlength="8" autocomplete="new-password">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Rol</label>
                            <select name="rol" class="form-select" required>
                                <option value="admin">Administrador</option>
                            </select>
                        </div>
                        <div class="col-12 text-end">
                            <button class="btn btn-primary" type="submit">Crear usuario</button>
                        </div>
                    </div>
                    <p class="text-muted small mt-2 mb-0">La contraseña debe tener mínimo 8 caracteres.</p>
                </form>
                <?php if (empty($usuarios)): ?>
                    <p class="text-muted small mb-0">No hay usuarios registrados.</p>
                <?php else: ?>
                    <?php foreach ($usuarios as $u): ?>
                        <form method="POST" action="../actions/usuarios_password.php" class="border rounded p-3 mb-3">
                            <input type="hidden" name="usuario_id" value="<?php echo (int) $u['id']; ?>">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                                <div>
                                    <div class="fw-semibold">Usuario: <?php echo clean($u['usuario']); ?></div>
                                    <div class="text-muted small">Rol: <?php echo clean($u['rol']); ?></div>
                                </div>
                                <span class="badge bg-light text-dark">Cambio de contraseña</span>
                            </div>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label">Nueva contraseña</label>
                                    <input type="password" name="nuevo_password" class="form-control" required minlength="8" autocomplete="new-password">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Confirmar contraseña</label>
                                    <input type="password" name="confirmar_password" class="form-control" required minlength="8" autocomplete="new-password">
                                </div>
                                <div class="col-12 text-end">
                                    <button class="btn btn-primary" type="submit">Actualizar contraseña</button>
                                </div>
                            </div>
                            <p class="text-muted small mt-2 mb-0">Debe tener mínimo 8 caracteres.</p>
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>
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
                            <label class="form-label">Categoría</label>
                            <input type="text" name="categoria" class="form-control" list="categoriasActividadesConfig" maxlength="150" placeholder="Ej. Préstamos" value="<?php echo clean($editData['categoria'] ?? ''); ?>">
                            <datalist id="categoriasActividadesConfig">
                                <?php foreach ($categoriasActividades as $categoriaActividad): ?>
                                    <option value="<?php echo clean($categoriaActividad); ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                            <div class="form-text">Escribe una categoría nueva o reutiliza una existente.</div>
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
                                <th>Categoría</th>
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
                                    <td><?php echo clean($a['categoria'] ?: 'Sin categoría'); ?></td>
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
