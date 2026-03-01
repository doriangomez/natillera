<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/rifas_helpers.php';

$errorCarga = null;
$socios = [];
$mediosPago = [];
$actividadesRifa = [];
$actividadesIngreso = [];
$actividadesPremio = [];
$rifas = [];
$idRifaSeleccionada = 0;
$rifaActual = null;
$boletas = [];
$resumen = [];
$gruposRifa = [];
$resumenSocios = [];
$utilidadRifa = ['total_proyectado' => 0, 'total_vendido' => 0, 'total_recaudado' => 0, 'premio_entregado' => 0, 'utilidad_neta' => 0];
$informeRifa = ['movimientos' => [], 'totales' => ['ingresos' => 0, 'egresos' => 0]];

$filtroGrupo = isset($_GET['grupo']) ? (int) $_GET['grupo'] : 0;
$filtroSocio = isset($_GET['socio']) ? (int) $_GET['socio'] : 0;
$filtroEstado = isset($_GET['estado']) ? clean($_GET['estado']) : '';

try {
    asegurarEsquemaRifas($pdo);

    $socios = getSocios($pdo);
    $mediosPago = getMediosPago($pdo);
    $actividadesRifa = getActividades($pdo, false, false, false);
    $actividadesIngreso = array_filter($actividadesRifa, fn($a) => (int) ($a['es_ingreso'] ?? 0) === 1);
    $actividadesPremio = array_filter($actividadesRifa, fn($a) => (int) ($a['es_ingreso'] ?? 0) === 0);
    $rifas = obtenerRifas($pdo);
    $idRifaSeleccionada = isset($_GET['id_rifa']) ? (int) $_GET['id_rifa'] : ((int) ($rifas[0]['id_rifa'] ?? 0));
    $rifaActual = $idRifaSeleccionada ? obtenerRifa($pdo, $idRifaSeleccionada) : null;
    $boletas = $rifaActual ? obtenerBoletasRifa($pdo, $idRifaSeleccionada) : [];
    if ($rifaActual) {
        $boletas = array_values(array_filter($boletas, static function ($b) use ($filtroGrupo, $filtroSocio, $filtroEstado) {
            if ($filtroGrupo > 0 && (int) ($b['id_grupo'] ?? 0) !== $filtroGrupo) return false;
            if ($filtroSocio > 0 && (int) ($b['id_socio'] ?? 0) !== $filtroSocio) return false;
            if ($filtroEstado !== '' && ($b['estado'] ?? '') !== $filtroEstado) return false;
            return true;
        }));
    }
    $resumen = $rifaActual ? obtenerResumenBoletas($pdo, $idRifaSeleccionada) : [];
    $gruposRifa = $rifaActual ? obtenerGruposRifa($pdo, $idRifaSeleccionada) : [];
    $resumenSocios = $rifaActual ? obtenerResumenSociosRifa($pdo, $idRifaSeleccionada) : [];
    $utilidadRifa = $rifaActual ? obtenerUtilidadRifa($pdo, $idRifaSeleccionada) : ['total_proyectado' => 0, 'total_vendido' => 0, 'total_recaudado' => 0, 'premio_entregado' => 0, 'utilidad_neta' => 0];
} catch (Throwable $e) {
    $errorCarga = 'No se pudo cargar el módulo de rifas. Detalle técnico: ' . $e->getMessage();
}

if ($rifaActual) {
    if (!function_exists('obtenerInformeMovimientosRifa')) {
        $helperPath = __DIR__ . '/../includes/rifas_helpers.php';
        if (file_exists($helperPath)) {
            require_once $helperPath;
        }
    }

    if (function_exists('obtenerInformeMovimientosRifa')) {
        $informeRifa = obtenerInformeMovimientosRifa($pdo, $rifaActual);
    }
}
?>
<h2 class="mb-3 d-flex align-items-center gap-2"><i class="bi bi-stars text-primary"></i><span>Módulo de rifas</span></h2>
<?php if (!empty($_SESSION['error'])): ?>
    <div class="alert alert-danger"><?php echo clean($_SESSION['error']); unset($_SESSION['error']); ?></div>
<?php endif; ?>
<?php if (!empty($_SESSION['exito'])): ?>
    <div class="alert alert-success"><?php echo clean($_SESSION['exito']); unset($_SESSION['exito']); ?></div>
<?php endif; ?>
<?php if ($errorCarga): ?>
    <div class="alert alert-danger"><?php echo clean($errorCarga); ?></div>
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
                    <span class="badge bg-primary-subtle text-primary"><i class="bi bi-diagram-3 me-1"></i>Normal / Gemela</span>
                </div>
                <form method="POST" action="../actions/rifas_save.php" class="row g-3" enctype="multipart/form-data" id="rifaWizardForm">
                    <input type="hidden" name="accion" value="crear_rifa">
                    <input type="hidden" name="tipo_rifa" id="tipo_rifa" value="">
                    <input type="hidden" name="grupos_json" id="grupos_json" value="">
                    <input type="hidden" name="cantidad_grupos" id="cantidad_grupos" value="1">
                    <input type="hidden" name="manual_asignaciones_json" id="manual_asignaciones_json" value="[]">

                    <div class="col-12" data-step="1">
                        <label class="form-label fw-semibold">Paso 1 · Selecciona el tipo de rifa</label>
                        <div class="d-flex gap-2 flex-wrap" id="tipoRifaOptions">
                            <button type="button" class="btn btn-outline-primary" data-tipo="normal">¿Rifa Normal?</button>
                            <button type="button" class="btn btn-outline-primary" data-tipo="gemela">¿Rifa Gemela?</button>
                        </div>
                    </div>

                    <div class="col-12 d-none" data-step="2">
                        <label class="form-label fw-semibold">Paso 2 · Configuración numérica</label>
                        <div class="row g-2 border rounded p-2">
                            <div class="col-md-4">
                                <label class="form-label">Cifras</label>
                                <input type="number" name="cifras_numero" id="cifras_numero" class="form-control" min="1" max="6" value="2" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Rango inicio</label>
                                <input type="number" name="rango_inicio" id="rango_inicio" class="form-control" min="0" value="0" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Rango fin</label>
                                <input type="number" name="rango_fin" id="rango_fin" class="form-control" min="0" value="99" required>
                            </div>
                            <div class="col-md-6 normal-only">
                                <label class="form-label">Numeración</label>
                                <select name="modo_numeracion" id="modo_numeracion" class="form-select">
                                    <option value="aleatoria">Automática</option>
                                    <option value="manual">Manual</option>
                                    <option value="mixta">Mixta</option>
                                </select>
                            </div>
                            <div class="col-md-6 normal-only">
                                <label class="form-label">Boletas por socio</label>
                                <input type="number" name="boletas_por_socio" class="form-control" min="1" value="1">
                            </div>
                            <div class="col-md-6 gemela-only d-none">
                                <label class="form-label">Numeración Grupo A</label>
                                <select id="metodo_grupo_a" class="form-select">
                                    <option value="aleatoria">Automática</option>
                                    <option value="manual">Manual</option>
                                    <option value="mixta">Mixta</option>
                                </select>
                            </div>
                            <div class="col-md-6 gemela-only d-none">
                                <label class="form-label">Numeración Grupo B</label>
                                <select id="metodo_grupo_b" class="form-select">
                                    <option value="aleatoria">Automática</option>
                                    <option value="manual">Manual</option>
                                    <option value="mixta">Mixta</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 d-none" data-step="3">
                        <label class="form-label fw-semibold">Paso 3 · Asignación de socios</label>
                        <div class="row g-2 border rounded p-2 normal-only" id="normalSociosWrap"></div>
                        <div class="border rounded p-2 gemela-only d-none" id="gruposBuilder"></div>
                    </div>

                    <div class="col-12 d-none" data-step="4">
                        <label class="form-label fw-semibold">Paso 4 · Numeración manual</label>
                        <div class="normal-only">
                            <small class="text-muted d-block mb-2">Si seleccionas Manual o Mixta, agrega pares número + socio.</small>
                            <div id="manualAsignacionesNormal"></div>
                            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="agregarAsignacionNormal">Agregar número manual</button>
                            <input type="hidden" name="numeros_manuales" id="numeros_manuales" value="">
                        </div>
                        <div class="gemela-only d-none" id="manualGemelaWrap">
                            <small class="text-muted d-block mb-2">Cada grupo puede repetir números respecto a otros grupos, pero no internamente.</small>
                        </div>
                    </div>

                    <div class="col-12 d-none" data-step="5">
                        <label class="form-label fw-semibold">Paso 5 · Arte de boletas</label>
                        <div class="row g-2 border rounded p-2">
                            <div class="col-md-6">
                                <label class="form-label">Arte base (subida o ruta)</label>
                                <input type="file" name="arte_base_file" id="arte_base_file" class="form-control mb-2" accept=".png,.jpg,.jpeg,.gif">
                                <input type="text" name="arte_base_path" id="arte_base_path" class="form-control" placeholder="uploads/rifas/base.png">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">X</label>
                                <input type="number" name="arte_numero_x" id="arte_numero_x" class="form-control" min="0" value="20" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Y</label>
                                <input type="number" name="arte_numero_y" id="arte_numero_y" class="form-control" min="0" value="40" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Tamaño</label>
                                <input type="number" name="arte_numero_size" id="arte_numero_size" class="form-control" min="8" max="144" value="28" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Color fuente</label>
                                <input type="color" name="arte_numero_color" id="arte_numero_color" class="form-control form-control-color" value="#000000">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Control visual de tamaño</label>
                                <input type="range" id="arte_numero_size_slider" class="form-range" min="8" max="144" value="28">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Fuente TTF (opcional)</label>
                                <input type="text" name="arte_font_path" class="form-control" placeholder="assets/fonts/Roboto-Regular.ttf">
                            </div>
                            <div class="col-12">
                                <div class="alert alert-light mb-0 py-2">Vista previa ejemplo: <strong id="previewNumero">00</strong>. Arrastra el número para posicionarlo.</div>
                            </div>
                            <div class="col-12">
                                <div id="arteEditor" class="border rounded position-relative overflow-hidden bg-light" style="min-height: 280px;">
                                    <img id="artePreviewImage" alt="Arte base" class="w-100 h-100 object-fit-contain d-none" style="max-height: 400px;">
                                    <div id="arteDragText" class="position-absolute fw-bold" style="left:20px; top:40px; font-size:28px; color:#000000; cursor:move; user-select:none;">00</div>
                                </div>
                                <small class="text-muted">También puedes ajustar los campos X, Y, tamaño y color manualmente.</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 d-none" data-step="6">
                        <label class="form-label fw-semibold">Paso 6 · Confirmación y generación</label>
                        <div class="row g-2 border rounded p-2">
                            <div class="col-md-6">
                                <label class="form-label">Nombre</label>
                                <input type="text" name="nombre" class="form-control" required placeholder="Ej: Rifa abril 2025">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Fecha inicio</label>
                                <input type="date" name="fecha_inicio" class="form-control" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Fecha fin</label>
                                <input type="date" name="fecha_fin" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Valor por boleta</label>
                                <input type="number" name="valor_boleta" class="form-control" min="1" step="0.01" value="10000" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Cantidad de boletas</label>
                                <input type="number" name="cantidad_boletas" class="form-control" min="1" max="500" value="100" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Distribución</label>
                                <select name="modo_distribucion" class="form-select">
                                    <option value="aleatoria">Aleatoria</option>
                                    <option value="manual">Manual dirigida</option>
                                </select>
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
                        </div>
                    </div>

                    <div class="col-12 d-flex justify-content-between">
                        <div id="wizardValidationMessage" class="text-danger small fw-semibold"></div>
                        <button type="button" class="btn btn-outline-secondary d-none" id="wizardPrev">Anterior</button>
                        <button type="button" class="btn btn-primary" id="wizardNext">Continuar</button>
                        <button class="btn btn-success d-none" id="wizardSubmit"><i class="bi bi-stars me-1"></i>Confirmar y generar</button>
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
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <span class="badge bg-success-subtle text-success">Pagadas: <?php echo $resumen['pagada']['cantidad'] ?? 0; ?></span>
            <span class="badge bg-warning-subtle text-warning">Pendientes: <?php echo $resumen['pendiente']['cantidad'] ?? 0; ?></span>
            <span class="badge bg-secondary-subtle text-secondary">Total: <?php echo $rifaActual['cantidad_boletas']; ?></span>

            <form method="POST" action="../actions/rifas_save.php">
                <input type="hidden" name="accion" value="descargar_boletas_zip">
                <input type="hidden" name="id_rifa" value="<?php echo (int) $rifaActual['id_rifa']; ?>">
                <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-zip me-1"></i>Descargar ZIP boletas</button>
            </form>
            <form method="POST" action="../actions/rifas_save.php" onsubmit="return confirm('¿Seguro que deseas eliminar esta rifa? Esta acción también removerá boletas y movimientos relacionados.');">
                <input type="hidden" name="accion" value="eliminar_rifa">
                <input type="hidden" name="id_rifa" value="<?php echo (int) $rifaActual['id_rifa']; ?>">
                <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash3 me-1"></i>Eliminar rifa</button>
            </form>
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
                            <label class="form-label">Concepto de ingreso</label>
                            <select name="id_actividad_movimiento" class="form-select">
                                <option value="">Usar concepto configurado en la rifa</option>
                                <?php foreach ($actividadesIngreso as $act): ?>
                                    <option value="<?php echo (int) $act['id_actividad']; ?>"><?php echo clean($act['nombre_actividad']); ?></option>
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
                            <label class="form-label">Grupo ganador (obligatorio si hay números repetidos entre grupos)</label>
                            <select name="id_grupo_ganador" class="form-select">
                                <option value="">Automático</option>
                                <?php foreach ($gruposRifa as $grupo): ?>
                                    <option value="<?php echo (int) $grupo['id_grupo']; ?>"><?php echo clean($grupo['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
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
                            <label class="form-label">Concepto de egreso (premio)</label>
                            <select name="id_actividad_premio_mov" class="form-select">
                                <option value="">Usar concepto configurado en la rifa</option>
                                <?php foreach ($actividadesPremio as $act): ?>
                                    <option value="<?php echo (int) $act['id_actividad']; ?>"><?php echo clean($act['nombre_actividad']); ?></option>
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
                    <form method="GET" class="row g-2 mb-3">
                        <input type="hidden" name="id_rifa" value="<?php echo (int) $rifaActual['id_rifa']; ?>">
                        <div class="col-md-4">
                            <select name="grupo" class="form-select form-select-sm">
                                <option value="0">Todos los grupos</option>
                                <?php foreach ($gruposRifa as $grupo): ?>
                                    <option value="<?php echo (int) $grupo['id_grupo']; ?>" <?php echo $filtroGrupo === (int) $grupo['id_grupo'] ? 'selected' : ''; ?>><?php echo clean($grupo['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <select name="socio" class="form-select form-select-sm">
                                <option value="0">Todos los socios</option>
                                <?php foreach ($socios as $s): ?>
                                    <option value="<?php echo (int) $s['id_socio']; ?>" <?php echo $filtroSocio === (int) $s['id_socio'] ? 'selected' : ''; ?>><?php echo clean($s['nombre_completo']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="estado" class="form-select form-select-sm">
                                <option value="">Todos los estados</option>
                                <?php foreach (['asignada','pagada','pendiente','anulada'] as $estado): ?>
                                    <option value="<?php echo $estado; ?>" <?php echo $filtroEstado === $estado ? 'selected' : ''; ?>><?php echo ucfirst($estado); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-1"><button class="btn btn-sm btn-outline-secondary w-100">OK</button></div>
                    </form>
                    <div class="table-responsive" style="max-height: 520px;">
                        <table class="table table-sm table-bordered align-middle">
                            <thead class="table-light"><tr><th>Grupo</th><th>Número</th><th>Socio</th><th>Estado</th><th>Valor</th><th>Fecha pago</th></tr></thead>
                            <tbody>
                                <?php foreach ($boletas as $boleta): ?>
                                    <tr>
                                        <td><?php echo clean($boleta['nombre_grupo'] ?? 'General'); ?></td>
                                        <td class="fw-semibold">#<?php echo clean($boleta['numero']); ?></td>
                                        <td><?php echo clean($boleta['nombre_completo'] ?? '—'); ?></td>
                                        <td>
                                            <?php if ($boleta['estado'] === 'pagada'): ?><span class="badge bg-success-subtle text-success">Pagada</span><?php endif; ?>
                                            <?php if ($boleta['estado'] === 'asignada'): ?><span class="badge bg-primary-subtle text-primary">Asignada</span><?php endif; ?>
                                            <?php if ($boleta['estado'] === 'pendiente'): ?><span class="badge bg-warning-subtle text-warning">Pendiente</span><?php endif; ?>
                                            <?php if ($boleta['estado'] === 'anulada'): ?><span class="badge bg-dark-subtle text-dark">Anulada</span><?php endif; ?>
                                        </td>
                                        <td>$<?php echo number_format($boleta['valor'],0,',','.'); ?></td>
                                        <td><?php echo $boleta['fecha_pago'] ? clean(date('Y-m-d', strtotime($boleta['fecha_pago']))) : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($boletas)): ?>
                                    <tr><td colspan="6" class="text-center text-muted">Aún no se han generado boletas.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="row g-3 mt-2">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-body">
                    <h6 class="mb-3">Recaudo por grupo</h6>
                    <div class="table-responsive" style="max-height:220px;">
                        <table class="table table-sm table-bordered align-middle mb-0">
                            <thead class="table-light"><tr><th>Grupo</th><th>Boletas</th><th>Pagadas</th><th>Recaudo</th></tr></thead>
                            <tbody>
                                <?php foreach ($gruposRifa as $grupo): ?>
                                <tr>
                                    <td><?php echo clean($grupo['nombre']); ?></td>
                                    <td><?php echo (int) $grupo['total_boletas']; ?></td>
                                    <td><?php echo (int) $grupo['boletas_pagadas']; ?></td>
                                    <td>$<?php echo number_format((float) $grupo['recaudo'], 0, ',', '.'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($gruposRifa)): ?><tr><td colspan="4" class="text-center text-muted">Sin grupos.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-body">
                    <h6 class="mb-3">Utilidad final</h6>
                    <div class="d-flex flex-column gap-2">
                        <div class="d-flex justify-content-between"><span>Total proyectado</span><strong>$<?php echo number_format($utilidadRifa['total_proyectado'] ?? 0, 0, ',', '.'); ?></strong></div>
                        <div class="d-flex justify-content-between"><span>Total vendido</span><strong>$<?php echo number_format($utilidadRifa['total_vendido'], 0, ',', '.'); ?></strong></div>
                        <div class="d-flex justify-content-between"><span>Total recaudado</span><strong>$<?php echo number_format($utilidadRifa['total_recaudado'], 0, ',', '.'); ?></strong></div>
                        <div class="d-flex justify-content-between"><span>Premio entregado</span><strong>$<?php echo number_format($utilidadRifa['premio_entregado'], 0, ',', '.'); ?></strong></div>
                        <div class="d-flex justify-content-between border-top pt-2"><span>Utilidad neta</span><strong class="<?php echo $utilidadRifa['utilidad_neta'] >= 0 ? 'text-success' : 'text-danger'; ?>">$<?php echo number_format(abs($utilidadRifa['utilidad_neta']), 0, ',', '.'); ?></strong></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h6 class="mb-3">Boletas por socio</h6>
                    <div class="table-responsive" style="max-height:240px;">
                        <table class="table table-sm table-bordered align-middle mb-0">
                            <thead class="table-light"><tr><th>Socio</th><th>Boletas</th><th>Pagadas</th><th>Pendientes</th><th>Total pagado</th></tr></thead>
                            <tbody>
                                <?php foreach ($resumenSocios as $row): ?>
                                <tr>
                                    <td><?php echo clean($row['nombre_completo']); ?></td>
                                    <td><?php echo (int) $row['boletas']; ?></td>
                                    <td><?php echo (int) $row['pagadas']; ?></td>
                                    <td><?php echo (int) $row['pendientes']; ?></td>
                                    <td>$<?php echo number_format((float) $row['total_pagado'], 0, ',', '.'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($resumenSocios)): ?><tr><td colspan="5" class="text-center text-muted">Sin asignaciones por socio.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="card mt-3">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <p class="text-muted mb-1">Informe contable</p>
                    <h5 class="mb-0">Movimientos de la rifa</h5>
                </div>
                <div class="d-flex gap-2">
                    <span class="badge bg-success-subtle text-success">Ingresos: $<?php echo number_format($informeRifa['totales']['ingresos'], 0, ',', '.'); ?></span>
                    <span class="badge bg-danger-subtle text-danger">Egresos: $<?php echo number_format($informeRifa['totales']['egresos'], 0, ',', '.'); ?></span>
                </div>
            </div>
            <div class="table-responsive" style="max-height: 320px;">
                <table class="table table-sm table-bordered align-middle">
                    <thead class="table-light">
                        <tr><th>Fecha</th><th>Actividad</th><th>Socio</th><th>Motivo</th><th>Medio</th><th>Valor</th><th>Registrado por</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($informeRifa['movimientos'] as $mov): ?>
                            <tr>
                                <td><?php echo clean(date('Y-m-d', strtotime($mov['fecha']))); ?></td>
                                <td><?php echo clean($mov['nombre_actividad']); ?></td>
                                <td><?php echo clean($mov['nombre_completo'] ?? '—'); ?></td>
                                <td><?php echo clean($mov['motivo'] . ($mov['observaciones'] ? ' - ' . $mov['observaciones'] : '')); ?></td>
                                <td><?php echo clean($mov['medio_consignacion']); ?></td>
                                <td class="text-end <?php echo ((float) $mov['valor']) >= 0 ? 'text-success' : 'text-danger'; ?>">$<?php echo number_format(abs($mov['valor']), 0, ',', '.'); ?></td>
                                <td><?php echo clean($mov['usuario_registro'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($informeRifa['movimientos'])): ?>
                            <tr><td colspan="7" class="text-center text-muted">Aún no hay movimientos asociados a esta rifa.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php $sociosWizard = array_values(array_map(static fn($s) => ['id' => (int) $s['id_socio'], 'nombre' => $s['nombre_completo']], $socios)); ?>
<script>
(() => {
  const form = document.getElementById('rifaWizardForm');
  if (!form) return;
  const socios = <?php echo json_encode($sociosWizard, JSON_UNESCAPED_UNICODE); ?>;
  let currentStep = 1;
  let tipo = '';
  const steps = [...form.querySelectorAll('[data-step]')];
  const prev = document.getElementById('wizardPrev');
  const next = document.getElementById('wizardNext');
  const submit = document.getElementById('wizardSubmit');
  const tipoInput = document.getElementById('tipo_rifa');
  const gruposWrap = document.getElementById('gruposBuilder');
  const normalSociosWrap = document.getElementById('normalSociosWrap');
  const manualGemelaWrap = document.getElementById('manualGemelaWrap');
  const validationMessage = document.getElementById('wizardValidationMessage');

  const numeroPreview = document.getElementById('previewNumero');
  const arteEditor = document.getElementById('arteEditor');
  const artePreviewImage = document.getElementById('artePreviewImage');
  const arteDragText = document.getElementById('arteDragText');
  const arteFileInput = document.getElementById('arte_base_file');
  const artePathInput = document.getElementById('arte_base_path');
  const arteXInput = document.getElementById('arte_numero_x');
  const arteYInput = document.getElementById('arte_numero_y');
  const arteSizeInput = document.getElementById('arte_numero_size');
  const arteSizeSlider = document.getElementById('arte_numero_size_slider');
  const arteColorInput = document.getElementById('arte_numero_color');

  const gemelaState = {
    included: new Set(socios.map(s => s.id)),
    groupA: new Set(),
    methodA: 'aleatoria',
    methodB: 'aleatoria'
  };

  function clearDependentByTipo() {
    gemelaState.included = new Set(socios.map(s => s.id));
    gemelaState.groupA = new Set();
    gemelaState.methodA = 'aleatoria';
    gemelaState.methodB = 'aleatoria';
    form.querySelector('#metodo_grupo_a').value = 'aleatoria';
    form.querySelector('#metodo_grupo_b').value = 'aleatoria';
    document.getElementById('manualAsignacionesNormal').innerHTML = '';
    manualGemelaWrap.innerHTML = '';
    document.getElementById('manual_asignaciones_json').value = '[]';
    document.getElementById('numeros_manuales').value = '';
    document.getElementById('grupos_json').value = '';
  }

  function resetManualAssignments() {
    document.getElementById('manualAsignacionesNormal').innerHTML = '';
    document.getElementById('manual_asignaciones_json').value = '[]';
    document.getElementById('numeros_manuales').value = '';
    if (tipo === 'gemela') buildGemela();
  }

  function renderSociosCheckbox(name, checked = true) {
    return socios.map(s => `<label class="form-check col-md-4"><input class="form-check-input" type="checkbox" name="${name}" value="${s.id}" ${checked ? 'checked' : ''}><span class="form-check-label">${s.nombre}</span></label>`).join('');
  }

  function addManualRow(container, sociosGrupo = [], data = {}) {
    const row = document.createElement('div');
    row.className = 'row g-2 mt-1 manual-group-row';
    row.innerHTML = `<div class="col-md-4"><input class="form-control manual-numero" placeholder="Número" value="${data.numero || ''}"></div>
      <div class="col-md-6"><select class="form-select manual-socio"><option value="">Socio</option>${sociosGrupo.map(s => `<option value="${s.id}" ${Number(data.id_socio)===s.id?'selected':''}>${s.nombre}</option>`).join('')}</select></div>
      <div class="col-md-2"><button type="button" class="btn btn-outline-danger w-100">X</button></div>`;
    row.querySelector('button').addEventListener('click', () => row.remove());
    container.appendChild(row);
  }

  function groupsManualUI(groupA, groupB) {
    manualGemelaWrap.innerHTML = '';
    const block = (id, label, sociosGrupo, method) => {
      const box = document.createElement('div');
      box.className = 'border rounded p-2 mb-2';
      box.innerHTML = `<div class="d-flex justify-content-between align-items-center"><h6 class="mb-0">${label}</h6><span class="badge bg-light text-dark">Método: ${method}</span></div>
      <div class="manual-zone mt-2"></div>
      <button type="button" class="btn btn-sm btn-outline-secondary mt-2 add-manual">Agregar número manual</button>`;
      const zone = box.querySelector('.manual-zone');
      box.querySelector('.add-manual').addEventListener('click', () => addManualRow(zone, sociosGrupo));
      if (method === 'aleatoria') {
        box.querySelector('.add-manual').classList.add('d-none');
        zone.innerHTML = '<small class="text-muted">Numeración automática.</small>';
      }
      box.dataset.groupId = id;
      manualGemelaWrap.appendChild(box);
    };
    block('A', 'Grupo A', groupA, gemelaState.methodA);
    block('B', 'Grupo B', groupB, gemelaState.methodB);
  }

  function buildGemela() {
    if (!gruposWrap) return;
    gruposWrap.innerHTML = `
      <div class="border rounded p-2 mb-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
          <h6 class="mb-0">Paso 1 · Definir participantes</h6>
          <small class="text-muted">Participan: <strong id="countParticipan">0</strong> · Excluidos: <strong id="countExcluidos">0</strong></small>
        </div>
        <div id="listaParticipacion" class="row g-1"></div>
      </div>
      <div class="border rounded p-2">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
          <h6 class="mb-0">Paso 2 · Distribuir participantes</h6>
          <small class="text-muted">Grupo A: <strong id="countGrupoA">0</strong> · Grupo B: <strong id="countGrupoB">0</strong></small>
        </div>
        <div class="row g-2 align-items-start">
          <div class="col-md-5">
            <div class="border rounded p-2">
              <div class="fw-semibold mb-2">Socios disponibles</div>
              <div class="small text-muted mb-2">Participan, pero aún no están en Grupo A.</div>
              <div id="disponiblesList" class="d-flex flex-column gap-1"></div>
            </div>
          </div>
          <div class="col-md-2 d-flex flex-md-column justify-content-center align-items-stretch gap-2">
            <button type="button" class="btn btn-outline-primary btn-sm" id="moveToA">➡️ Pasar a Grupo A</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="removeFromA">⬅️ Quitar de Grupo A</button>
          </div>
          <div class="col-md-5">
            <div class="border rounded p-2">
              <div class="fw-semibold mb-2">Grupo A</div>
              <div class="small text-muted mb-2">Los que no estén aquí quedan automáticamente en Grupo B.</div>
              <div id="grupoAList" class="d-flex flex-column gap-1"></div>
            </div>
          </div>
        </div>
      </div>`;

    const listaParticipacion = gruposWrap.querySelector('#listaParticipacion');
    const render = () => {
      const participantes = socios.filter(s => gemelaState.included.has(s.id));
      const groupA = participantes.filter(s => gemelaState.groupA.has(s.id));
      const disponibles = participantes.filter(s => !gemelaState.groupA.has(s.id));

      gruposWrap.querySelector('#countParticipan').textContent = String(participantes.length);
      gruposWrap.querySelector('#countExcluidos').textContent = String(Math.max(0, socios.length - participantes.length));
      gruposWrap.querySelector('#countGrupoA').textContent = String(groupA.length);
      gruposWrap.querySelector('#countGrupoB').textContent = String(disponibles.length);

      gruposWrap.querySelector('#disponiblesList').innerHTML = disponibles.length
        ? disponibles.map(s => `<label class="form-check"><input class="form-check-input disponible-check" type="checkbox" value="${s.id}"><span class="form-check-label">${s.nombre}</span></label>`).join('')
        : '<small class="text-muted">Sin socios disponibles.</small>';
      gruposWrap.querySelector('#grupoAList').innerHTML = groupA.length
        ? groupA.map(s => `<label class="form-check"><input class="form-check-input grupoa-check" type="checkbox" value="${s.id}"><span class="form-check-label">${s.nombre}</span></label>`).join('')
        : '<small class="text-muted">Aún no hay socios en Grupo A.</small>';

      groupsManualUI(groupA, disponibles);
      updateStepValidationState();
    };

    listaParticipacion.innerHTML = socios.map(s => `<label class="form-check col-md-6 col-lg-4"><input class="form-check-input toggle-participa" type="checkbox" value="${s.id}" ${gemelaState.included.has(s.id) ? 'checked' : ''}><span class="form-check-label">${s.nombre}</span></label>`).join('');

    listaParticipacion.querySelectorAll('.toggle-participa').forEach(cb => cb.addEventListener('change', () => {
      const id = Number(cb.value);
      if (cb.checked) {
        gemelaState.included.add(id);
      } else {
        gemelaState.included.delete(id);
        gemelaState.groupA.delete(id);
      }
      render();
    }));

    gruposWrap.querySelector('#moveToA').addEventListener('click', () => {
      gruposWrap.querySelectorAll('.disponible-check:checked').forEach(cb => {
        const id = Number(cb.value);
        if (gemelaState.included.has(id)) gemelaState.groupA.add(id);
      });
      render();
    });

    gruposWrap.querySelector('#removeFromA').addEventListener('click', () => {
      gruposWrap.querySelectorAll('.grupoa-check:checked').forEach(cb => {
        gemelaState.groupA.delete(Number(cb.value));
      });
      render();
    });

    render();
  }

  function syncArteInputsFromDrag() {
    const left = Math.max(0, Math.round(parseFloat(arteDragText.style.left) || 0));
    const top = Math.max(0, Math.round(parseFloat(arteDragText.style.top) || 0));
    arteXInput.value = left;
    arteYInput.value = top;
  }

  function applyArtePreview() {
    const size = Number(arteSizeInput.value || 28);
    arteDragText.style.fontSize = `${size}px`;
    arteDragText.style.color = arteColorInput.value || '#000000';
    arteDragText.style.left = `${Math.max(0, Number(arteXInput.value || 0))}px`;
    arteDragText.style.top = `${Math.max(0, Number(arteYInput.value || 0))}px`;
    arteSizeSlider.value = String(size);
  }

  function loadArtePreviewFromFile(file) {
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => {
      artePreviewImage.src = String(reader.result || '');
      artePreviewImage.classList.remove('d-none');
    };
    reader.readAsDataURL(file);
  }

  function applyTipoUI() {
    document.querySelectorAll('#tipoRifaOptions [data-tipo]').forEach(btn => {
      btn.classList.toggle('btn-primary', btn.dataset.tipo === tipo);
      btn.classList.toggle('btn-outline-primary', btn.dataset.tipo !== tipo);
    });
    form.querySelectorAll('.normal-only').forEach(el => el.classList.toggle('d-none', tipo !== 'normal'));
    form.querySelectorAll('.gemela-only').forEach(el => el.classList.toggle('d-none', tipo !== 'gemela'));
    tipoInput.value = tipo;
    document.getElementById('cantidad_grupos').value = tipo === 'gemela' ? '2' : '1';
  }

  function validateCurrentStep() {
    const inicio = Number(document.getElementById('rango_inicio').value || 0);
    const fin = Number(document.getElementById('rango_fin').value || 0);
    const cifras = Number(document.getElementById('cifras_numero').value || 0);
    const cantidad = Number(form.querySelector('[name="cantidad_boletas"]').value || 0);

    if (currentStep === 1 && !tipo) return 'Debes seleccionar tipo de rifa.';
    if (currentStep === 2) {
      if (cifras < 1 || cifras > 6) return 'La cantidad de cifras debe estar entre 1 y 6.';
      if (fin < inicio) return 'El rango numérico es inválido.';
      const totalRango = (fin - inicio) + 1;
      if (cantidad <= 0) return 'La cantidad de boletas debe ser mayor a cero.';
      if (cantidad > totalRango) return 'La cantidad de boletas no puede superar el total del rango.';
    }
    if (currentStep === 3) {
      if (tipo === 'normal') {
        const selected = [...normalSociosWrap.querySelectorAll('input[type="checkbox"]:checked')];
        if (!selected.length) return 'Debes seleccionar al menos un socio.';
      }
      if (tipo === 'gemela') {
        const participantes = socios.filter(s => gemelaState.included.has(s.id));
        if (!participantes.length) return 'Debes tener al menos un socio participante activo.';
        const groupA = socios.filter(s => gemelaState.included.has(s.id) && gemelaState.groupA.has(s.id));
        const groupB = socios.filter(s => gemelaState.included.has(s.id) && !gemelaState.groupA.has(s.id));
        if (!groupA.length || !groupB.length) return 'Debe existir al menos un socio en Grupo A y Grupo B.';
      }
    }
    if (currentStep === 4) {
      const globalManual = new Set();
      if (tipo === 'normal') {
        for (const r of document.querySelectorAll('#manualAsignacionesNormal .manual-row')) {
          const numero = r.querySelector('input').value.trim();
          if (!numero) continue;
          const n = Number(numero);
          if (!Number.isInteger(n) || n < inicio || n > fin) return 'Hay números manuales fuera de rango.';
          if (globalManual.has(n)) return 'Hay números manuales repetidos.';
          globalManual.add(n);
        }
      }
      if (tipo === 'gemela') {
        for (const box of manualGemelaWrap.querySelectorAll('[data-group-id]')) {
          const groupNums = new Set();
          for (const row of box.querySelectorAll('.manual-group-row')) {
            const numero = row.querySelector('.manual-numero').value.trim();
            if (!numero) continue;
            const n = Number(numero);
            if (!Number.isInteger(n) || n < inicio || n > fin) return `Número fuera de rango en grupo ${box.dataset.groupId}.`;
            if (groupNums.has(n)) return `Número duplicado dentro del grupo ${box.dataset.groupId}.`;
            groupNums.add(n);
          }
        }
      }
    }
    if (currentStep === 5) {
      const hasArte = Boolean((artePathInput.value || '').trim()) || (arteFileInput.files && arteFileInput.files.length > 0);
      if (!hasArte) return 'Debes cargar un arte base para continuar.';
      if (!arteXInput.value || !arteYInput.value) return 'Debes definir una posición para el número.';
    }
    return '';
  }

  function updateStepValidationState() {
    const msg = validateCurrentStep();
    validationMessage.textContent = msg;
    next.disabled = Boolean(msg);
    submit.disabled = Boolean(validateCurrentStep());
    return !msg;
  }

  function collectAndValidate() {
    const inicio = Number(document.getElementById('rango_inicio').value || 0);
    const fin = Number(document.getElementById('rango_fin').value || 0);
    const cantidad = Number(form.querySelector('[name="cantidad_boletas"]').value || 0);
    if (!tipo) throw new Error('Debes seleccionar tipo de rifa.');
    if (fin < inicio) throw new Error('El rango numérico es inválido.');

    if (tipo === 'normal') {
      const selected = [...normalSociosWrap.querySelectorAll('input[type="checkbox"]:checked')].map(el => Number(el.value));
      if (!selected.length) throw new Error('No se puede crear rifa sin asignar socios.');
      const manual = [];
      const nums = new Set();
      document.querySelectorAll('#manualAsignacionesNormal .manual-row').forEach(r => {
        const numero = r.querySelector('input').value.trim();
        const idSocio = Number(r.querySelector('select').value || 0);
        if (!numero) return;
        const n = Number(numero);
        if (!Number.isInteger(n) || n < inicio || n > fin) throw new Error('Hay números manuales fuera de rango.');
        if (nums.has(n)) throw new Error('Hay números manuales repetidos.');
        if (!selected.includes(idSocio)) throw new Error('Cada número manual debe tener un socio válido.');
        nums.add(n);
        manual.push({ numero: String(n), id_socio: idSocio });
      });
      if (manual.length > cantidad) throw new Error('Los manuales no pueden superar el total permitido.');
      document.getElementById('manual_asignaciones_json').value = JSON.stringify(manual);
      document.getElementById('numeros_manuales').value = manual.map(m => m.numero).join(',');
      document.getElementById('grupos_json').value = JSON.stringify([{ nombre:'Grupo 1', boletas_por_socio:Number(form.querySelector('[name="boletas_por_socio"]').value||1), metodo_distribucion:form.querySelector('[name="modo_distribucion"]').value||'aleatoria', socios:selected, asignaciones:manual }]);
      return;
    }

    const groupA = socios.filter(s => gemelaState.included.has(s.id) && gemelaState.groupA.has(s.id));
    const groupB = socios.filter(s => gemelaState.included.has(s.id) && !gemelaState.groupA.has(s.id));
    if (!groupA.length && !groupB.length) throw new Error('Debes tener al menos un socio participante activo.');
    if (!groupA.length || !groupB.length) throw new Error('Debe existir al menos un socio por grupo.');

    const parseManual = (groupId, sociosGrupo) => {
      const box = manualGemelaWrap.querySelector(`[data-group-id="${groupId}"]`);
      const ids = sociosGrupo.map(s => s.id);
      const nums = new Set();
      const manual = [];
      box.querySelectorAll('.manual-group-row').forEach(row => {
        const numero = row.querySelector('.manual-numero').value.trim();
        const idSocio = Number(row.querySelector('.manual-socio').value || 0);
        if (!numero) return;
        const n = Number(numero);
        if (!Number.isInteger(n) || n < inicio || n > fin) throw new Error(`Número fuera de rango en grupo ${groupId}.`);
        if (nums.has(n)) throw new Error(`Número duplicado dentro del grupo ${groupId}.`);
        if (!ids.includes(idSocio)) throw new Error(`El socio manual del grupo ${groupId} debe pertenecer al grupo.`);
        nums.add(n);
        manual.push({ numero: String(n), id_socio: idSocio });
      });
      if (manual.length > cantidad) throw new Error(`No se permiten más números manuales que el total disponible en grupo ${groupId}.`);
      return manual;
    };

    const manualA = parseManual('A', groupA);
    const manualB = parseManual('B', groupB);

    const grupos = [];
    grupos.push({ nombre:'Grupo A', boletas_por_socio:1, metodo_distribucion:gemelaState.methodA, socios:groupA.map(s=>s.id), asignaciones:manualA });
    grupos.push({ nombre:'Grupo B', boletas_por_socio:1, metodo_distribucion:gemelaState.methodB, socios:groupB.map(s=>s.id), asignaciones:manualB });
    document.getElementById('grupos_json').value = JSON.stringify(grupos);
    document.getElementById('manual_asignaciones_json').value = '[]';
    document.getElementById('numeros_manuales').value = '';
  }

  document.querySelectorAll('#tipoRifaOptions [data-tipo]').forEach(btn => btn.addEventListener('click', () => {
    tipo = btn.dataset.tipo;
    clearDependentByTipo();
    applyTipoUI();
    normalSociosWrap.innerHTML = `<label class="form-label">Socios participantes</label><div class="row">${renderSociosCheckbox('socios_normal')}</div>`;
    buildGemela();
    updateStepValidationState();
  }));

  document.getElementById('metodo_grupo_a')?.addEventListener('change', (e) => { gemelaState.methodA = e.target.value; resetManualAssignments(); updateStepValidationState(); });
  document.getElementById('metodo_grupo_b')?.addEventListener('change', (e) => { gemelaState.methodB = e.target.value; resetManualAssignments(); updateStepValidationState(); });

  document.getElementById('agregarAsignacionNormal').addEventListener('click', () => {
    const selected = [...normalSociosWrap.querySelectorAll('input[type="checkbox"]:checked')].map(el => Number(el.value));
    const sociosDisponibles = socios.filter(s => selected.includes(s.id));
    const row = document.createElement('div');
    row.className = 'row g-2 mb-2 manual-row';
    row.innerHTML = `<div class="col-md-4"><input class="form-control" placeholder="Número"></div>
      <div class="col-md-6"><select class="form-select"><option value="">Socio</option>${sociosDisponibles.map(s => `<option value="${s.id}">${s.nombre}</option>`).join('')}</select></div>
      <div class="col-md-2"><button type="button" class="btn btn-outline-danger w-100">X</button></div>`;
    row.querySelector('button').addEventListener('click', () => { row.remove(); updateStepValidationState(); });
    document.getElementById('manualAsignacionesNormal').appendChild(row);
    updateStepValidationState();
  });

  normalSociosWrap.addEventListener('change', (e) => {
    if (e.target.matches('input[type="checkbox"]')) {
      resetManualAssignments();
      updateStepValidationState();
    }
  });

  ['rango_inicio','rango_fin','cantidad_boletas'].forEach(id => {
    document.getElementById(id)?.addEventListener('change', () => {
      resetManualAssignments();
      updateStepValidationState();
    });
  });

  document.getElementById('cifras_numero').addEventListener('change', (e) => {
    const cifras = Number(e.target.value || 2);
    const ejemplo = String(0).padStart(cifras, '0');
    numeroPreview.textContent = ejemplo;
    arteDragText.textContent = ejemplo;
    updateStepValidationState();
  });

  if (arteEditor && arteDragText) {
    let dragging = false;
    let offsetX = 0;
    let offsetY = 0;

    arteDragText.addEventListener('mousedown', (e) => {
      dragging = true;
      const rect = arteDragText.getBoundingClientRect();
      offsetX = e.clientX - rect.left;
      offsetY = e.clientY - rect.top;
      e.preventDefault();
    });

    window.addEventListener('mousemove', (e) => {
      if (!dragging) return;
      const rect = arteEditor.getBoundingClientRect();
      const maxX = Math.max(0, rect.width - arteDragText.offsetWidth);
      const maxY = Math.max(0, rect.height - arteDragText.offsetHeight);
      const x = Math.min(Math.max(0, e.clientX - rect.left - offsetX), maxX);
      const y = Math.min(Math.max(0, e.clientY - rect.top - offsetY), maxY);
      arteDragText.style.left = `${Math.round(x)}px`;
      arteDragText.style.top = `${Math.round(y)}px`;
      syncArteInputsFromDrag();
    });

    window.addEventListener('mouseup', () => {
      if (!dragging) return;
      dragging = false;
      updateStepValidationState();
    });
  }

  arteFileInput?.addEventListener('change', () => {
    const file = arteFileInput.files?.[0];
    loadArtePreviewFromFile(file);
    updateStepValidationState();
  });

  artePathInput?.addEventListener('input', () => {
    const path = (artePathInput.value || '').trim();
    if (path !== '') {
      artePreviewImage.src = path;
      artePreviewImage.classList.remove('d-none');
    }
    updateStepValidationState();
  });

  arteXInput?.addEventListener('input', () => { applyArtePreview(); updateStepValidationState(); });
  arteYInput?.addEventListener('input', () => { applyArtePreview(); updateStepValidationState(); });
  arteSizeInput?.addEventListener('input', () => { applyArtePreview(); updateStepValidationState(); });
  arteColorInput?.addEventListener('input', () => { applyArtePreview(); updateStepValidationState(); });
  arteSizeSlider?.addEventListener('input', () => {
    arteSizeInput.value = arteSizeSlider.value;
    applyArtePreview();
    updateStepValidationState();
  });

  prev.addEventListener('click', () => {
    if (currentStep > 1) {
      currentStep--;
      updateStep();
    }
  });

  function updateStep() {
    steps.forEach(step => step.classList.toggle('d-none', Number(step.dataset.step) !== currentStep));
    prev.classList.toggle('d-none', currentStep === 1);
    next.classList.toggle('d-none', currentStep === 6);
    submit.classList.toggle('d-none', currentStep !== 6);
    updateStepValidationState();
  }

  next.addEventListener('click', () => {
    const msg = validateCurrentStep();
    if (msg) {
      validationMessage.textContent = msg;
      return;
    }
    if (currentStep < 6) {
      currentStep++;
      updateStep();
    }
  });

  form.addEventListener('submit', (e) => {
    try {
      const msg = validateCurrentStep();
      if (msg) throw new Error(msg);
      collectAndValidate();
    } catch (err) {
      e.preventDefault();
      alert(err.message);
    }
  });

  applyArtePreview();
  updateStep();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
