<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$search = isset($_GET['q']) ? clean($_GET['q']) : '';
$lista = getSocios($pdo, $search);
$editId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editData = null;
if ($editId) {
    $stmt = $pdo->prepare("SELECT * FROM socios WHERE id_socio = :id");
    $stmt->execute([':id' => $editId]);
    $editData = $stmt->fetch();
}
?>
<h2 class="mb-3">Socios</h2>
<div class="row">
    <div class="col-md-4">
        <div class="card mb-3">
            <div class="card-header"><?php echo $editData ? 'Editar socio' : 'Nuevo socio'; ?></div>
            <div class="card-body">
                <form method="POST" action="../actions/socios_save.php">
                    <input type="hidden" name="id_socio" value="<?php echo $editData['id_socio'] ?? ''; ?>">
                    <div class="mb-2">
                        <label class="form-label">Nombre completo</label>
                        <input type="text" name="nombre_completo" class="form-control" required value="<?php echo $editData['nombre_completo'] ?? ''; ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Teléfono</label>
                        <input type="text" name="telefono" class="form-control" value="<?php echo $editData['telefono'] ?? ''; ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Número polla</label>
                        <input type="text" name="numero_polla" class="form-control" value="<?php echo $editData['numero_polla'] ?? ''; ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Periodicidad pago</label>
                        <select name="periodicidad_pago" class="form-select">
                            <option value="quincenal" <?php echo (isset($editData['periodicidad_pago']) && $editData['periodicidad_pago']==='quincenal')?'selected':''; ?>>Quincenal</option>
                            <option value="mensual" <?php echo (isset($editData['periodicidad_pago']) && $editData['periodicidad_pago']==='mensual')?'selected':''; ?>>Mensual</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Valor presupuestado</label>
                        <input type="number" step="0.01" name="valor_presupuestado" class="form-control" value="<?php echo $editData['valor_presupuestado'] ?? '0'; ?>">
                    </div>
                    <button class="btn btn-success" type="submit">Guardar</button>
                    <?php if ($editData): ?>
                        <a class="btn btn-secondary" href="socios.php">Cancelar</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <form class="row mb-2" method="GET">
            <div class="col">
                <input type="text" name="q" class="form-control" placeholder="Buscar" value="<?php echo $search; ?>">
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-primary">Buscar</button>
                <a class="btn btn-outline-secondary" href="../actions/export_csv.php?tipo=socios">Exportar CSV</a>
            </div>
        </form>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Teléfono</th>
                        <th>Polla</th>
                        <th>Periodicidad</th>
                        <th>Presupuesto</th>
                        <th>Saldo</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista as $s): ?>
                        <tr>
                            <td><?php echo $s['id_socio']; ?></td>
                            <td><?php echo clean($s['nombre_completo']); ?></td>
                            <td><?php echo clean($s['telefono']); ?></td>
                            <td><?php echo clean($s['numero_polla']); ?></td>
                            <td><?php echo clean($s['periodicidad_pago']); ?></td>
                            <td>$<?php echo number_format($s['valor_presupuestado'],0,',','.'); ?></td>
                            <td>$<?php echo number_format($s['saldo_socio'],0,',','.'); ?></td>
                            <td>
                                <a class="btn btn-sm btn-primary" href="?id=<?php echo $s['id_socio']; ?>">Editar</a>
                                <form method="POST" action="../actions/socios_save.php" class="d-inline" onsubmit="return confirm('¿Inactivar socio?');">
                                    <input type="hidden" name="id_socio" value="<?php echo $s['id_socio']; ?>">
                                    <input type="hidden" name="accion" value="inactivar">
                                    <button class="btn btn-sm btn-danger">Inactivar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
