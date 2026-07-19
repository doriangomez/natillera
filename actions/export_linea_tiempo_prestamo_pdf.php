<?php
use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/prestamo_detalle_helpers.php';

checkAuth();

$idPrestamo = isset($_GET['id_prestamo']) ? (int) $_GET['id_prestamo'] : 0;
$detalle = $idPrestamo > 0 ? cargarDetallePrestamo($pdo, $idPrestamo) : null;
if (!$detalle) {
    exit('No se encontró el préstamo solicitado.');
}

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    exit('No se encontró vendor/autoload.php. Ejecute "composer install".');
}
require_once $autoload;

$prestamo = $detalle['prestamo'];
$deudor = $prestamo['es_particular'] ? ($prestamo['nombre_deudor'] ?: 'Particular sin nombre') : ($prestamo['nombre_completo'] ?: 'Socio sin nombre');
$proximo = $detalle['proximo_vencimiento'];

ob_start();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
body{font-family:DejaVu Sans,Arial,sans-serif;font-size:11px;color:#1f2937} h1{color:#0d6efd;font-size:20px}.grid{width:100%;border-collapse:collapse;margin:10px 0}.grid td{border:1px solid #ddd;padding:7px;vertical-align:top}.label{color:#64748b;font-size:9px;text-transform:uppercase}.value{font-weight:bold}.alert{border:1px solid #dc3545;background:#f8d7da;color:#842029;padding:8px;margin:8px 0}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ddd;padding:5px;text-align:right}th{background:#0d6efd;color:#fff}.left{text-align:left}.muted{color:#64748b;font-size:9px}.badge{font-weight:bold}
</style>
</head>
<body>
<h1>Línea de tiempo del préstamo #<?php echo (int) $idPrestamo; ?></h1>
<p>Generado el <?php echo htmlspecialchars(date('Y-m-d H:i'), ENT_QUOTES, 'UTF-8'); ?></p>
<?php if ((int) $prestamo['es_particular'] === 1 && empty($prestamo['id_socio'])): ?><div class="alert">Este préstamo no está vinculado a ningún socio.</div><?php endif; ?>
<table class="grid"><tr>
<td><div class="label">Deudor</div><div class="value"><?php echo htmlspecialchars($deudor, ENT_QUOTES, 'UTF-8'); ?></div><div class="muted">Aval: <?php echo htmlspecialchars($prestamo['nombre_aval'] ?: 'No aplica', ENT_QUOTES, 'UTF-8'); ?></div></td>
<td><div class="label">Monto / tasa / inicio</div><div class="value"><?php echo formatoMonedaPrestamoDetalle((float) $prestamo['monto_prestamo']); ?> · <?php echo htmlspecialchars((string) $prestamo['tasa_interes'], ENT_QUOTES, 'UTF-8'); ?>%</div><div class="muted"><?php echo htmlspecialchars((string) $prestamo['fecha_prestamo'], ENT_QUOTES, 'UTF-8'); ?></div></td>
<td><div class="label">Saldos</div><div class="value">Capital: <?php echo formatoMonedaPrestamoDetalle((float) $prestamo['saldo_capital_actual']); ?></div><div class="muted">Interés: <?php echo formatoMonedaPrestamoDetalle((float) $prestamo['saldo_intereses_actual']); ?></div></td>
<td><div class="label">Intereses históricos</div><div class="value"><?php echo formatoMonedaPrestamoDetalle($detalle['totales']['interes_causado']); ?> causado</div><div class="muted"><?php echo formatoMonedaPrestamoDetalle($detalle['totales']['interes_pagado']); ?> pagado</div></td>
</tr></table>
<p><strong>Próximo vencimiento:</strong> <?php echo $proximo ? htmlspecialchars($proximo['fecha'], ENT_QUOTES, 'UTF-8') . ' · ' . ($proximo['dias'] < 0 ? abs($proximo['dias']) . ' días vencido' : $proximo['dias'] . ' días faltantes') . ' · ' . formatoMonedaPrestamoDetalle($proximo['valor']) : 'Sin intereses pendientes.'; ?></p>
<?php if (!empty($detalle['alertas'])): ?><div class="alert"><strong>Alertas:</strong><ul><?php foreach ($detalle['alertas'] as $alerta): ?><li><?php echo htmlspecialchars($alerta, ENT_QUOTES, 'UTF-8'); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<table><thead><tr><th class="left">Mes/Año</th><th>Capital inicio</th><th>Interés causado</th><th>Interés pagado</th><th>Abono capital</th><th>Capital final</th><th>Días mora</th><th class="left">Estado</th><th class="left">Fuente</th></tr></thead><tbody>
<?php foreach ($detalle['filas'] as $fila): ?><tr><td class="left"><?php echo htmlspecialchars($fila['mes_label'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo formatoMonedaPrestamoDetalle($fila['capital_inicio']); ?></td><td><?php echo formatoMonedaPrestamoDetalle($fila['interes_causado']); ?></td><td><?php echo formatoMonedaPrestamoDetalle($fila['interes_pagado']); ?><br><span class="muted"><?php echo htmlspecialchars($fila['fechas_interes'] ?: 'Sin pago', ENT_QUOTES, 'UTF-8'); ?></span></td><td><?php echo formatoMonedaPrestamoDetalle($fila['abono_capital']); ?><br><span class="muted"><?php echo htmlspecialchars($fila['fechas_capital'] ?: 'Sin abono', ENT_QUOTES, 'UTF-8'); ?></span></td><td><?php echo formatoMonedaPrestamoDetalle($fila['capital_final']); ?></td><td><?php echo (int) $fila['dias_mora']; ?></td><td class="left"><?php echo htmlspecialchars($fila['estado'], ENT_QUOTES, 'UTF-8'); ?></td><td class="left"><?php echo htmlspecialchars($fila['fuente'], ENT_QUOTES, 'UTF-8'); ?></td></tr><?php endforeach; ?>
</tbody></table>
</body></html>
<?php
$html = ob_get_clean();
$options = new Options();
$options->set('isRemoteEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="linea_tiempo_prestamo_' . $idPrestamo . '.pdf"');
echo $dompdf->output();
