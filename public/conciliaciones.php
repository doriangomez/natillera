<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$medios = getMediosPago($pdo);
$anio = isset($_GET['anio']) ? (int) $_GET['anio'] : (int) date('Y');
$mes = isset($_GET['mes']) ? (int) $_GET['mes'] : (int) date('n');

$totales = [];
foreach ($medios as $m) {
    $stmt = $pdo->prepare('SELECT SUM(valor) total FROM movimientos WHERE id_medio_pago = :id AND YEAR(fecha)=:y AND MONTH(fecha)=:m');
    $stmt->execute([':id'=>$m['id'], ':y'=>$anio, ':m'=>$mes]);
    $totales[$m['id']] = (float) ($stmt->fetchColumn() ?: 0);
}

// Traer conciliaciones guardadas
$stmtConc = $pdo->prepare('SELECT * FROM conciliaciones_medios_pago WHERE anio=:y AND mes=:m');
$stmtConc->execute([':y'=>$anio, ':m'=>$mes]);
$conciliaciones = [];
foreach ($stmtConc->fetchAll() as $row) {
    $conciliaciones[$row['id_medio']] = $row;
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <p class="text-muted small mb-1">Control mensual por medio de pago</p>
        <h1 class="h4 mb-0">Conciliación de medios de pago</h1>
    </div>
</div>
<div class="card mb-3">
    <div class="card-body">
        <form class="row g-2" method="GET">
            <div class="col-md-3">
                <label class="form-label">Mes</label>
                <select name="mes" class="form-select">
                    <?php for($i=1;$i<=12;$i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $i===$mes?'selected':''; ?>><?php echo date('F', mktime(0,0,0,$i,1)); ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Año</label>
                <input type="number" name="anio" class="form-control" value="<?php echo $anio; ?>">
            </div>
            <div class="col-md-2 align-self-end">
                <button class="btn btn-primary">Consultar</button>
            </div>
        </form>
    </div>
</div>
<form method="POST" action="../actions/conciliacion_save.php">
    <input type="hidden" name="mes" value="<?php echo $mes; ?>">
    <input type="hidden" name="anio" value="<?php echo $anio; ?>">
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Medio</th>
                            <th>Total sistema</th>
                            <th>Valor banco</th>
                            <th>Diferencia</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($medios as $m): 
                            $totalSistema = $totales[$m['id']] ?? 0;
                            $valorBanco = $conciliaciones[$m['id']]['valor_banco'] ?? 0;
                            $diff = $totalSistema - $valorBanco;
                        ?>
                            <tr>
                                <td>
                                    <?php echo clean($m['nombre']); ?>
                                    <input type="hidden" name="medio_ids[]" value="<?php echo $m['id']; ?>">
                                </td>
                                <td>$<?php echo number_format($totalSistema,0,',','.'); ?></td>
                                <td>
                                    <input type="number" step="0.01" name="banco[<?php echo $m['id']; ?>]" class="form-control" value="<?php echo $valorBanco; ?>">
                                </td>
                                <td><?php echo number_format($diff,0,',','.'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button class="btn btn-success">Guardar conciliación</button>
        </div>
    </div>
</form>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
