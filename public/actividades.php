<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$actividades = getActividades($pdo);
$editId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editData = null;
if ($editId) {
    $editData = getActividad($pdo, $editId);
}
?>
<h2 class="mb-3">Maestro de actividades</h2>
<div class="row">
    <div class="col-md-4">
        <div class="card mb-3">
            <div class="card-header"><?php echo $editData ? 'Editar actividad' : 'Nueva actividad'; ?></div>
            <div class="card-body">
                <form method="POST" action="/actions/actividades_save.php">
                    <input type="hidden" name="id_actividad" value="<?php echo $editData['id_actividad'] ?? ''; ?>">
                    <div class="mb-2">
                        <label class="form-label">Nombre</label>
                        <input type="text" name="nombre_actividad" class="form-control" required value="<?php echo $editData['nombre_actividad'] ?? ''; ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Descripción</label>
                        <textarea name="descripcion" class="form-control"><?php echo $editData['descripcion'] ?? ''; ?></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-2">
                            <label class="form-label">Afecta saldo socio</label>
                            <select class="form-select" name="afecta_saldo_socio">
                                <?php $opts=['suma','resta','neutral']; foreach($opts as $o): ?>
                                    <option value="<?php echo $o; ?>" <?php echo (isset($editData['afecta_saldo_socio']) && $editData['afecta_saldo_socio']===$o)?'selected':''; ?>><?php echo ucfirst($o); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label">Afecta saldo natillera</label>
                            <select class="form-select" name="afecta_saldo_natillera">
                                <?php foreach($opts as $o): ?>
                                    <option value="<?php echo $o; ?>" <?php echo (isset($editData['afecta_saldo_natillera']) && $editData['afecta_saldo_natillera']===$o)?'selected':''; ?>><?php echo ucfirst($o); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-2 form-check">
                            <input class="form-check-input" type="checkbox" name="es_prestamo" value="1" <?php echo (!empty($editData['es_prestamo']))?'checked':''; ?>>
                            <label class="form-check-label">Es préstamo</label>
                        </div>
                        <div class="col-md-6 mb-2 form-check">
                            <input class="form-check-input" type="checkbox" name="es_pago_prestamo" value="1" <?php echo (!empty($editData['es_pago_prestamo']))?'checked':''; ?>>
                            <label class="form-check-label">Es pago préstamo</label>
                        </div>
                        <div class="col-md-6 mb-2 form-check">
                            <input class="form-check-input" type="checkbox" name="es_polla" value="1" <?php echo (!empty($editData['es_polla']))?'checked':''; ?>>
                            <label class="form-check-label">Es polla</label>
                        </div>
                        <div class="col-md-6 mb-2 form-check">
                            <input class="form-check-input" type="checkbox" name="es_gasto_general" value="1" <?php echo (!empty($editData['es_gasto_general']))?'checked':''; ?>>
                            <label class="form-check-label">Es gasto general</label>
                        </div>
                    </div>
                    <button class="btn btn-success">Guardar</button>
                    <?php if ($editData): ?>
                        <a class="btn btn-secondary" href="/public/actividades.php">Cancelar</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>ID</th><th>Nombre</th><th>Saldo socio</th><th>Saldo natillera</th><th>Flags</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($actividades as $a): ?>
                        <tr>
                            <td><?php echo $a['id_actividad']; ?></td>
                            <td><?php echo clean($a['nombre_actividad']); ?></td>
                            <td><?php echo $a['afecta_saldo_socio']; ?></td>
                            <td><?php echo $a['afecta_saldo_natillera']; ?></td>
                            <td>
                                <?php echo $a['es_prestamo'] ? 'Préstamo ' : ''; ?>
                                <?php echo $a['es_pago_prestamo'] ? 'Pago préstamo ' : ''; ?>
                                <?php echo $a['es_polla'] ? 'Polla ' : ''; ?>
                                <?php echo $a['es_gasto_general'] ? 'Gasto ' : ''; ?>
                            </td>
                            <td><a class="btn btn-sm btn-primary" href="?id=<?php echo $a['id_actividad']; ?>">Editar</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
