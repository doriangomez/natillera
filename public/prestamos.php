<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$socios = getSocios($pdo);
$actividades = getActividades($pdo);
$mediosPago = getMediosPago($pdo);

$prestamos = $pdo->query("SELECT p.*, s.nombre_completo, aval.nombre_completo AS nombre_aval FROM prestamos p LEFT JOIN socios s ON p.id_socio=s.id_socio LEFT JOIN socios aval ON p.id_socio_aval = aval.id_socio ORDER BY p.fecha_prestamo DESC LIMIT 100")->fetchAll();
?>
<h2 class="mb-3 d-flex align-items-center gap-2"><i class="bi bi-cash-coin text-primary"></i><span>Préstamos</span></h2>
<div class="card mb-3">
    <div class="card-header category-prestamos"><i class="bi bi-plus-circle"></i><span>Crear nuevo préstamo</span></div>
    <div class="card-body">
        <form method="POST" action="../actions/prestamos_save.php">
            <div class="row g-2">
                <div class="col-md-3">
                    <label class="form-label">Fecha préstamo</label>
                    <input type="date" name="fecha_prestamo" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tipo de deudor</label>
                    <select name="es_particular" class="form-select">
                        <option value="0">Socio</option>
                        <option value="1">Particular</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Socio beneficiario</label>
                    <select name="id_socio" class="form-select">
                        <option value="">Seleccione socio</option>
                        <?php foreach($socios as $s): ?>
                            <option value="<?php echo $s['id_socio']; ?>"><?php echo clean($s['nombre_completo']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Deudor particular (si aplica)</label>
                    <input type="text" name="nombre_deudor" class="form-control" placeholder="Nombre de particular">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Socio avalador (para particulares)</label>
                    <select name="id_socio_aval" class="form-select">
                        <option value="">Sin aval</option>
                        <?php foreach($socios as $s): ?>
                            <option value="<?php echo $s['id_socio']; ?>"><?php echo clean($s['nombre_completo']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Monto préstamo</label>
                    <input type="number" step="0.01" name="monto_prestamo" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Tasa interés (%)</label>
                    <input type="number" step="0.01" name="tasa_interes" class="form-control" value="2">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Número de cuotas</label>
                    <input type="number" name="numero_cuotas" class="form-control" value="6" required>
                </div>
            </div>
            <button class="btn btn-success mt-3 btn-icon"><span><i class="bi bi-check2-circle"></i> Crear préstamo</span></button>
        </form>
    </div>
</div>
<div class="card mb-3">
    <div class="card-header category-prestamos"><i class="bi bi-cash-stack"></i><span>Registrar pago de préstamo</span></div>
    <div class="card-body">
        <form method="POST" action="../actions/cuotas_save.php">
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label">Préstamo</label>
                    <select name="id_prestamo" class="form-select" required>
                        <?php foreach($prestamos as $p): ?>
                            <option value="<?php echo $p['id_prestamo']; ?>"><?php echo '#'.$p['id_prestamo'].' - '.($p['nombre_completo'] ?? $p['nombre_deudor']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Fecha pago</label>
                    <input type="date" name="fecha_pago" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Capital pagado</label>
                    <input type="number" step="0.01" name="valor_capital_pagado" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Interés pagado</label>
                    <input type="number" step="0.01" name="valor_interes_pagado" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Actividad pago (p.e. Pago Abono a Préstamo)</label>
                    <select name="id_actividad" class="form-select" required>
                        <?php foreach($actividades as $a): ?>
                            <?php if($a['es_pago_prestamo']) : ?>
                                <option value="<?php echo $a['id_actividad']; ?>"><?php echo clean($a['nombre_actividad']); ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Medio</label>
                    <select name="medio_consignacion" class="form-select" required>
                        <?php foreach($mediosPago as $mp): ?>
                            <option value="<?php echo clean($mp['nombre']); ?>"><?php echo clean($mp['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="mt-2 text-muted small">El número de cuota se asigna automáticamente al guardar el pago.</div>
            <button class="btn btn-primary mt-2 btn-icon"><span><i class="bi bi-receipt"></i> Registrar pago</span></button>
        </form>
    </div>
</div>
<h4 class="d-flex align-items-center gap-2"><i class="bi bi-activity text-primary"></i><span>Préstamos vigentes</span></h4>
<div class="table-responsive">
<table class="table table-sm table-bordered">
    <thead><tr><th>ID</th><th>Deudor</th><th>Aval</th><th>Tipo</th><th>Monto</th><th>Saldo capital</th><th>Saldo interés</th><th>Estado</th></tr></thead>
    <tbody>
        <?php foreach($prestamos as $p): ?>
            <tr>
                <td><?php echo $p['id_prestamo']; ?></td>
                <td><?php echo clean($p['nombre_completo'] ?? $p['nombre_deudor']); ?></td>
                <td><?php echo clean($p['nombre_aval']); ?></td>
                <td><?php echo $p['es_particular'] ? 'Particular' : 'Socio'; ?></td>
                <td>$<?php echo number_format($p['monto_prestamo'],0,',','.'); ?></td>
                <td>$<?php echo number_format($p['saldo_capital_actual'],0,',','.'); ?></td>
                <td>$<?php echo number_format($p['saldo_intereses_actual'],0,',','.'); ?></td>
                <td><?php echo $p['estado']; ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
