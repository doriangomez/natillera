<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$socios = $pdo->query('SELECT id_socio, nombre_completo, saldo_socio FROM socios WHERE activo = 1 ORDER BY nombre_completo')->fetchAll();
$idSocio = isset($_GET['id_socio']) ? (int) $_GET['id_socio'] : 0;
$cuotaManejo = isset($_GET['cuota_manejo']) ? (float) $_GET['cuota_manejo'] : 0.0;

$resultado = null;
$socioSeleccionado = null;

if ($idSocio > 0) {
    $stmtSocio = $pdo->prepare('SELECT id_socio, nombre_completo, saldo_socio FROM socios WHERE id_socio = :id AND activo = 1');
    $stmtSocio->execute([':id' => $idSocio]);
    $socioSeleccionado = $stmtSocio->fetch(PDO::FETCH_ASSOC);

    if ($socioSeleccionado) {
        $stmtTotales = $pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE
                    WHEN m.es_ingreso = 1
                    AND COALESCE(a.es_prestamo,0) = 0
                    AND COALESCE(a.es_pago_prestamo,0) = 0
                    AND COALESCE(a.es_pago_interes,0) = 0
                    AND COALESCE(a.es_polla,0) = 0
                    THEN m.valor ELSE 0 END),0) AS ingresos_liquidables,
                COALESCE(SUM(CASE
                    WHEN m.es_ingreso = 1
                    AND COALESCE(a.es_polla,0) = 1
                    THEN m.valor ELSE 0 END),0) AS total_polla
            FROM movimientos m
            JOIN actividades_maestro a ON m.id_actividad = a.id_actividad
            WHERE m.id_socio = :id"
        );
        $stmtTotales->execute([':id' => $idSocio]);
        $totales = $stmtTotales->fetch(PDO::FETCH_ASSOC) ?: ['ingresos_liquidables' => 0, 'total_polla' => 0];

        $stmtPrestamos = $pdo->prepare(
            'SELECT
                COALESCE(SUM(saldo_capital_actual),0) AS saldo_capital,
                COALESCE(SUM(saldo_intereses_actual),0) AS saldo_intereses,
                COALESCE(SUM(monto_prestamo),0) AS total_desembolsado
             FROM prestamos
             WHERE id_socio = :id AND estado = "vigente"'
        );
        $stmtPrestamos->execute([':id' => $idSocio]);
        $prestamos = $stmtPrestamos->fetch(PDO::FETCH_ASSOC) ?: ['saldo_capital' => 0, 'saldo_intereses' => 0, 'total_desembolsado' => 0];

        $ingresosLiquidables = (float) $totales['ingresos_liquidables'];
        $totalPolla = (float) $totales['total_polla'];
        $saldoCapital = (float) $prestamos['saldo_capital'];
        $saldoIntereses = (float) $prestamos['saldo_intereses'];
        $saldoPrestamos = $saldoCapital + $saldoIntereses;
        $valorBruto = $ingresosLiquidables;
        $valorNeto = $valorBruto - $saldoPrestamos - $cuotaManejo;

        $resultado = [
            'ingresos_liquidables' => $ingresosLiquidables,
            'total_polla' => $totalPolla,
            'saldo_capital' => $saldoCapital,
            'saldo_intereses' => $saldoIntereses,
            'saldo_prestamos' => $saldoPrestamos,
            'cuota_manejo' => $cuotaManejo,
            'valor_bruto' => $valorBruto,
            'valor_neto' => $valorNeto,
            'saldo_socio' => (float) $socioSeleccionado['saldo_socio'],
        ];
    }
}
?>
<h2 class="mb-3">Liquidación anticipada de socio</h2>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-5">
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
            <div class="col-md-3">
                <label class="form-label" for="cuota_manejo">Cuota de manejo</label>
                <input type="number" step="0.01" min="0" class="form-control" id="cuota_manejo" name="cuota_manejo" value="<?php echo number_format($cuotaManejo, 2, '.', ''); ?>">
            </div>
            <div class="col-md-4 d-grid">
                <button type="submit" class="btn btn-primary"><i class="bi bi-calculator"></i> Calcular liquidación</button>
            </div>
        </form>
    </div>
</div>

<?php if ($idSocio > 0 && !$socioSeleccionado): ?>
    <div class="alert alert-warning">No se encontró un socio activo con el identificador seleccionado.</div>
<?php endif; ?>

<?php if ($resultado): ?>
    <div class="card mb-3">
        <div class="card-header">Resultado de liquidación para <?php echo clean($socioSeleccionado['nombre_completo']); ?></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="border rounded p-3 h-100">
                        <div class="text-muted small">Ingresos liquidables</div>
                        <div class="fs-4 fw-bold text-success">$<?php echo number_format($resultado['ingresos_liquidables'], 0, ',', '.'); ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded p-3 h-100">
                        <div class="text-muted small">Préstamos pendientes (capital + intereses)</div>
                        <div class="fs-4 fw-bold text-danger">$<?php echo number_format($resultado['saldo_prestamos'], 0, ',', '.'); ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded p-3 h-100">
                        <div class="text-muted small">Cuota de manejo</div>
                        <div class="fs-4 fw-bold">$<?php echo number_format($resultado['cuota_manejo'], 0, ',', '.'); ?></div>
                    </div>
                </div>
            </div>

            <hr>

            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <tbody>
                        <tr>
                            <th>Saldo registrado del socio</th>
                            <td>$<?php echo number_format($resultado['saldo_socio'], 0, ',', '.'); ?></td>
                        </tr>
                        <tr>
                            <th>Total aportado a pollas (no retornable)</th>
                            <td>$<?php echo number_format($resultado['total_polla'], 0, ',', '.'); ?></td>
                        </tr>
                        <tr>
                            <th>Valor bruto liquidable</th>
                            <td>$<?php echo number_format($resultado['valor_bruto'], 0, ',', '.'); ?></td>
                        </tr>
                        <tr>
                            <th>(-) Saldo de préstamos pendientes</th>
                            <td>$<?php echo number_format($resultado['saldo_prestamos'], 0, ',', '.'); ?></td>
                        </tr>
                        <tr>
                            <th>(-) Cuota de manejo</th>
                            <td>$<?php echo number_format($resultado['cuota_manejo'], 0, ',', '.'); ?></td>
                        </tr>
                        <tr class="table-light">
                            <th>Valor neto a entregar</th>
                            <td class="fw-bold <?php echo $resultado['valor_neto'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                $<?php echo number_format($resultado['valor_neto'], 0, ',', '.'); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="small text-muted mb-0">
                Nota: los aportes identificados como <strong>pollas</strong> se muestran como referencia pero no hacen parte de la devolución en esta liquidación anticipada.
            </p>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
