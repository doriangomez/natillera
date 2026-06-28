<?php
use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

checkAuth();

if (($_SESSION['rol'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Acceso restringido: solo usuarios administradores pueden descargar comprobantes de liquidación.');
}

function cargarAutoloadPdfLiquidacion(): void
{
    static $autoloadCargado = false;
    if ($autoloadCargado) {
        return;
    }

    foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/vendor/autoload.php'] as $path) {
        if (file_exists($path)) {
            require_once $path;
            $autoloadCargado = true;
            break;
        }
    }

    if (!$autoloadCargado) {
        exit('No se encontró vendor/autoload.php. Ejecute "composer install" en la raíz del proyecto.');
    }
}

function crearDompdfLiquidacion(): Dompdf
{
    cargarAutoloadPdfLiquidacion();

    if (!class_exists(Dompdf::class)) {
        exit('DOMPDF no está disponible. Ejecute "composer require dompdf/dompdf" en la raíz del proyecto.');
    }

    $opciones = new Options();
    $opciones->set('isRemoteEnabled', true);
    $opciones->set('isHtml5ParserEnabled', true);

    return new Dompdf($opciones);
}

function h($value): string
{
    return htmlspecialchars(trim((string) $value), ENT_QUOTES, 'UTF-8');
}

function monedaLiquidacion($valor): string
{
    $numero = (float) $valor;
    $prefijo = $numero < 0 ? '-' : '';
    return $prefijo . '$' . number_format(abs($numero), 0, ',', '.');
}

function claseValor(float $valor): string
{
    if ($valor < 0) {
        return 'negativo';
    }
    if ($valor > 0) {
        return 'positivo';
    }
    return '';
}

function logoLiquidacionBase64(array $config): ?string
{
    if (empty($config['logo_archivo'])) {
        return null;
    }

    $ruta = realpath(__DIR__ . '/../public/assets/logo/' . basename((string) $config['logo_archivo']));
    if (!$ruta || !is_readable($ruta)) {
        return null;
    }

    $contenido = file_get_contents($ruta);
    if ($contenido === false) {
        return null;
    }

    return 'data:' . (mime_content_type($ruta) ?: 'image/png') . ';base64,' . base64_encode($contenido);
}

function valorDetalle(array $detalle, array $liquidacion, string $clave, string $columna, float $default = 0.0): float
{
    if (array_key_exists($clave, $detalle)) {
        return (float) $detalle[$clave];
    }
    if (array_key_exists($columna, $liquidacion)) {
        return (float) $liquidacion[$columna];
    }
    return $default;
}

function idsMovimientosComprobante(array $liquidacion): array
{
    $permitidos = [
        'cancelacion_prestamo_original_liquidacion',
        'prestamo_saldo_pendiente_liquidacion',
        'abono_ahorro_prestamo_liquidacion',
        'cuota_administracion_saldo_pendiente',
    ];
    $ids = [];
    $movimientos = json_decode((string) ($liquidacion['movimientos_generados'] ?? '[]'), true);
    if (is_array($movimientos)) {
        foreach ($movimientos as $movimiento) {
            if (is_array($movimiento) && in_array((string) ($movimiento['tipo'] ?? ''), $permitidos, true) && !empty($movimiento['id_movimiento'])) {
                $ids[] = (int) $movimiento['id_movimiento'];
            }
        }
    }
    return array_values(array_unique(array_filter($ids)));
}

asegurarEsquemaLiquidaciones($pdo);
asegurarEsquemaMovimientos($pdo);

$idLiquidacion = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($idLiquidacion <= 0) {
    http_response_code(400);
    exit('Debe indicar el ID de la liquidación con el parámetro ?id=.');
}

$stmt = $pdo->prepare('SELECT l.*, s.nombre_completo FROM liquidaciones l JOIN socios s ON s.id_socio = l.socio_id WHERE l.id = :id');
$stmt->execute([':id' => $idLiquidacion]);
$liquidacion = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$liquidacion) {
    http_response_code(404);
    exit('No se encontró la liquidación solicitada.');
}

$detalle = json_decode((string) ($liquidacion['detalle_preliquidacion'] ?? '{}'), true);
if (!is_array($detalle)) {
    $detalle = [];
}

$prestamoNuevo = null;
if (!empty($liquidacion['prestamo_nuevo_id'])) {
    $stmtPrestamo = $pdo->prepare('SELECT id_prestamo, monto_prestamo, saldo_capital_actual, estado, fecha_prestamo FROM prestamos WHERE id_prestamo = :id');
    $stmtPrestamo->execute([':id' => (int) $liquidacion['prestamo_nuevo_id']]);
    $prestamoNuevo = $stmtPrestamo->fetch(PDO::FETCH_ASSOC) ?: null;
}

$movimientos = [];
$idsMovimientos = idsMovimientosComprobante($liquidacion);
if ($idsMovimientos) {
    $placeholders = implode(',', array_fill(0, count($idsMovimientos), '?'));
    $sqlMovimientos = "SELECT m.fecha, m.motivo, m.valor, m.es_ingreso, m.es_egreso, a.nombre_actividad, a.afecta_saldo_socio, a.es_polla
        FROM movimientos m
        JOIN actividades_maestro a ON a.id_actividad = m.id_actividad
        WHERE m.id_movimiento IN ($placeholders)
          AND COALESCE(a.es_polla, 0) = 0
          AND LOWER(COALESCE(a.nombre_actividad, '')) NOT LIKE '%polla%'
          AND LOWER(COALESCE(m.motivo, '')) NOT LIKE '%polla%'
        ORDER BY m.fecha ASC, m.id_movimiento ASC";
    $stmtMovimientos = $pdo->prepare($sqlMovimientos);
    $stmtMovimientos->execute($idsMovimientos);
    $movimientos = $stmtMovimientos->fetchAll(PDO::FETCH_ASSOC);
}

$saldoSocio = 0.0;
foreach ($movimientos as &$movimiento) {
    $regla = normalizarReglaAfectacion($movimiento['afecta_saldo_socio'] ?? 'neutral');
    if ($regla === 'suma') {
        $saldoSocio += (float) $movimiento['valor'];
    } elseif ($regla === 'resta') {
        $saldoSocio -= (float) $movimiento['valor'];
    }
    $movimiento['saldo_socio_calculado'] = $saldoSocio;
}
unset($movimiento);

$ahorro = valorDetalle($detalle, $liquidacion, 'ahorro_acumulado_bruto', 'saldo_base');
$rendimientos = valorDetalle($detalle, $liquidacion, 'rendimientos', 'valor_bruto') - $ahorro;
$capitalPendiente = valorDetalle($detalle, $liquidacion, 'capital_cubierto', 'capital_cubierto');
$interesesPendientes = valorDetalle($detalle, $liquidacion, 'intereses_cubiertos', 'intereses_cubiertos');
if (!empty($detalle['prestamos_descontados']) && is_array($detalle['prestamos_descontados'])) {
    $capitalPendiente = array_sum(array_map(static fn($p) => (float) ($p['capital_pendiente'] ?? 0), $detalle['prestamos_descontados']));
    $interesesPendientes = array_sum(array_map(static fn($p) => (float) ($p['intereses_pendientes'] ?? 0), $detalle['prestamos_descontados']));
}
$cuotaAdmin = valorDetalle($detalle, $liquidacion, 'valor_cuota_manejo', 'valor_cuota_manejo');
$saldoLiquidacion = array_key_exists('saldo_liquidacion', $detalle)
    ? (float) $detalle['saldo_liquidacion']
    : ($ahorro + $rendimientos - $capitalPendiente - $interesesPendientes - $cuotaAdmin);
$saldoPendiente = valorDetalle($detalle, $liquidacion, 'saldo_pendiente', 'saldo_pendiente', max(0, -$saldoLiquidacion));
$config = getConfiguracionGeneral($pdo);
$logo = logoLiquidacionBase64($config);
$fechaGeneracion = date('Y-m-d H:i:s');

ob_start();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { size: letter; margin: 2cm; }
        body { background: #fff; color: #333; font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; }
        .header { background: #1a237e; color: #fff; padding: 16px; border-radius: 8px; margin-bottom: 18px; }
        .brand { display: table; width: 100%; }
        .brand-left, .brand-right { display: table-cell; vertical-align: middle; }
        .brand-left { width: 95px; }
        .logo { width: 76px; height: 76px; object-fit: contain; background: #fff; border-radius: 6px; padding: 6px; }
        .logo-fallback { width: 88px; height: 64px; background: #3949ab; border: 2px solid #fff; border-radius: 6px; text-align: center; padding-top: 22px; font-weight: bold; }
        h1 { margin: 0 0 5px; font-size: 24px; }
        h2 { color: #1a237e; font-size: 15px; border-bottom: 2px solid #1a237e; padding-bottom: 5px; margin: 18px 0 8px; }
        .meta { color: #e8eaf6; line-height: 1.7; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th, td { border: 1px solid #ddd; padding: 7px; }
        th { background: #eef1fb; color: #1a237e; text-align: left; }
        .label { font-weight: bold; color: #444; width: 42%; }
        .text-end { text-align: right; }
        .destacado { background: #f5f7ff; font-weight: bold; }
        .positivo { color: #2e7d32; font-weight: bold; }
        .negativo { color: #c62828; font-weight: bold; }
        .footer { margin-top: 24px; color: #555; font-size: 11px; }
        .firma { margin-top: 40px; width: 250px; border-top: 1px solid #333; text-align: center; padding-top: 6px; }
        .muted { color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <div class="brand">
            <div class="brand-left">
                <?php if ($logo): ?><img class="logo" src="<?php echo $logo; ?>" alt="Logo"><?php else: ?><div class="logo-fallback">Creciendo<br>Juntos</div><?php endif; ?>
            </div>
            <div class="brand-right">
                <h1>Creciendo Juntos</h1>
                <div style="font-size:18px;font-weight:bold;">Comprobante de Liquidación</div>
                <div class="meta">
                    Número de liquidación: #<?php echo (int) $liquidacion['id']; ?><br>
                    Fecha de generación: <?php echo h($fechaGeneracion); ?><br>
                    Fecha de liquidación: <?php echo h($liquidacion['fecha']); ?>
                </div>
            </div>
        </div>
    </div>

    <h2>Sección 1 — Datos del socio</h2>
    <table>
        <tr><td class="label">Nombre completo</td><td><?php echo h($liquidacion['nombre_completo']); ?></td></tr>
        <tr><td class="label">Estado anterior</td><td>Activo</td></tr>
        <tr><td class="label">Estado nuevo</td><td>Retirado con deuda pendiente</td></tr>
    </table>

    <h2>Sección 2 — Resumen financiero</h2>
    <table>
        <tr><td class="label">Ahorro acumulado</td><td class="text-end positivo"><?php echo monedaLiquidacion($ahorro); ?></td></tr>
        <tr><td class="label">Rendimientos</td><td class="text-end positivo"><?php echo monedaLiquidacion($rendimientos); ?></td></tr>
        <tr><td class="label">Capital pendiente préstamo</td><td class="text-end negativo"><?php echo monedaLiquidacion($capitalPendiente); ?></td></tr>
        <tr><td class="label">Intereses pendientes</td><td class="text-end negativo"><?php echo monedaLiquidacion($interesesPendientes); ?></td></tr>
        <tr><td class="label">Cuota de administración</td><td class="text-end negativo"><?php echo monedaLiquidacion($cuotaAdmin); ?></td></tr>
        <tr class="destacado"><td class="label">Saldo de liquidación</td><td class="text-end <?php echo claseValor($saldoLiquidacion); ?>"><?php echo monedaLiquidacion($saldoLiquidacion); ?></td></tr>
        <tr class="destacado"><td class="label">Saldo pendiente del socio</td><td class="text-end <?php echo $saldoPendiente > 0 ? 'negativo' : ''; ?>"><?php echo monedaLiquidacion($saldoPendiente); ?></td></tr>
    </table>

    <h2>Sección 3 — Nuevo préstamo generado</h2>
    <table>
        <tr><td class="label">Número de préstamo</td><td><?php echo $prestamoNuevo ? '#' . (int) $prestamoNuevo['id_prestamo'] : 'N/A'; ?></td></tr>
        <tr><td class="label">Capital inicial</td><td class="text-end"><?php echo $prestamoNuevo ? monedaLiquidacion($prestamoNuevo['monto_prestamo']) : 'N/A'; ?></td></tr>
        <tr><td class="label">Saldo actual</td><td class="text-end"><?php echo $prestamoNuevo ? monedaLiquidacion($prestamoNuevo['saldo_capital_actual']) : 'N/A'; ?></td></tr>
        <tr><td class="label">Estado</td><td><?php echo $prestamoNuevo ? h($prestamoNuevo['estado']) : 'N/A'; ?></td></tr>
        <tr><td class="label">Fecha de creación</td><td><?php echo $prestamoNuevo ? h($prestamoNuevo['fecha_prestamo']) : 'N/A'; ?></td></tr>
    </table>

    <h2>Sección 4 — Movimientos registrados</h2>
    <table>
        <thead><tr><th>Fecha</th><th>Actividad</th><th>Tipo</th><th class="text-end">Valor</th><th class="text-end">Saldo socio</th></tr></thead>
        <tbody>
        <?php if (!$movimientos): ?>
            <tr><td colspan="5" class="muted" style="text-align:center;">No hay movimientos propios de liquidación para mostrar.</td></tr>
        <?php else: ?>
            <?php foreach ($movimientos as $movimiento): ?>
                <tr>
                    <td><?php echo h($movimiento['fecha']); ?></td>
                    <td><?php echo h($movimiento['nombre_actividad']); ?></td>
                    <td><?php echo h($movimiento['motivo']); ?></td>
                    <td class="text-end <?php echo !empty($movimiento['es_egreso']) ? 'negativo' : 'positivo'; ?>"><?php echo monedaLiquidacion($movimiento['valor']); ?></td>
                    <td class="text-end <?php echo claseValor((float) $movimiento['saldo_socio_calculado']); ?>"><?php echo monedaLiquidacion($movimiento['saldo_socio_calculado']); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <div class="footer">
        Este documento es un comprobante oficial generado por el sistema de administración de la natillera Creciendo Juntos.<br>
        Fecha y hora de generación: <?php echo h($fechaGeneracion); ?>
        <div class="firma">Administrador</div>
    </div>
</body>
</html>
<?php
$html = ob_get_clean();

$dompdf = crearDompdfLiquidacion();
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('letter', 'portrait');
$dompdf->render();
$dompdf->stream('comprobante_liquidacion_' . $idLiquidacion . '.pdf', ['Attachment' => false]);
exit;
