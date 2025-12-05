<?php
use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

checkAuth();

function cargarAutoloadDompdf(): void
{
    static $autoloadCargado = false;
    if ($autoloadCargado) {
        return;
    }

    $paths = [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/vendor/autoload.php',
    ];

    foreach ($paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $autoloadCargado = true;
            break;
        }
    }

    if (!$autoloadCargado) {
        exit('No se encontró vendor/autoload.php. Ejecute "composer install".');
    }
}

function crearDompdf(): Dompdf
{
    cargarAutoloadDompdf();

    $opciones = new Options();
    $opciones->set('isRemoteEnabled', true);

    return new Dompdf($opciones);
}

function formatearMoneda(float $valor): string
{
    return '$' . number_format($valor, 0, ',', '.');
}

function cargarLogoBase64(array $config): ?string
{
    if (empty($config['logo_archivo'])) {
        return null;
    }

    $ruta = realpath(__DIR__ . '/../public/assets/logo/' . basename((string) $config['logo_archivo']));
    if (!$ruta || !is_readable($ruta)) {
        return null;
    }

    $mime = mime_content_type($ruta) ?: 'image/png';
    $contenido = file_get_contents($ruta);
    if ($contenido === false) {
        return null;
    }

    return 'data:' . $mime . ';base64,' . base64_encode($contenido);
}

$idPrestamo = isset($_GET['id_prestamo']) ? (int) $_GET['id_prestamo'] : 0;
if ($idPrestamo <= 0) {
    exit('Debe indicar un número de préstamo válido.');
}

extenderPeriodosPrestamoHastaMesActual($pdo);

$stmt = $pdo->prepare(
    'SELECT p.*, s.nombre_completo, s.telefono, aval.nombre_completo AS nombre_aval,' .
    '       (SELECT MAX(fecha_pago) FROM cuotas_prestamo cp WHERE cp.id_prestamo = p.id_prestamo) AS ultima_fecha_pago' .
    '  FROM prestamos p' .
    '  LEFT JOIN socios s ON p.id_socio = s.id_socio' .
    '  LEFT JOIN socios aval ON p.id_socio_aval = aval.id_socio' .
    ' WHERE p.id_prestamo = :id'
);
$stmt->execute([':id' => $idPrestamo]);
$prestamo = $stmt->fetch();

if (!$prestamo) {
    exit('No se encontró el préstamo solicitado.');
}

$periodosIndexados = obtenerPeriodosPrestamo($pdo, [$idPrestamo]);
$periodos = $periodosIndexados[$idPrestamo] ?? [];

$meses = getNombresMeses();
$estadisticas = [
    'pagados' => 0,
    'pendientes' => 0,
    'futuros' => 0,
];
$totalesMovimientos = [
    'interes_causado' => 0.0,
    'interes_pagado' => 0.0,
    'abono_capital' => 0.0,
];

$filasPeriodos = [];
foreach ($periodos as $periodo) {
    $estado = trim((string) ($periodo['estado'] ?? ''));
    $estadoLower = strtolower($estado);

    if ($estadoLower === 'pagado' || $estadoLower === 'ok') {
        $estadisticas['pagados']++;
        $estadoBadge = 'pagado';
    } elseif ($estadoLower === 'pendiente' || $estadoLower === 'mora') {
        $estadisticas['pendientes']++;
        $estadoBadge = 'pendiente';
    } else {
        $estadisticas['futuros']++;
        $estadoBadge = 'futuro';
        if ($estado === '') {
            $estado = 'Futuro';
        }
    }

    $totalesMovimientos['interes_causado'] += (float) ($periodo['interes_causado'] ?? 0);
    $totalesMovimientos['interes_pagado'] += (float) ($periodo['interes_pagado'] ?? 0);
    $totalesMovimientos['abono_capital'] += (float) ($periodo['abono_capital'] ?? 0);

    $labelMes = ($meses[(int) $periodo['mes']] ?? 'Mes') . ' ' . $periodo['anio'];

    $filasPeriodos[] = [
        'label' => $labelMes,
        'capital_inicio' => (float) ($periodo['capital_inicio'] ?? 0),
        'interes_causado' => (float) ($periodo['interes_causado'] ?? 0),
        'interes_pagado' => (float) ($periodo['interes_pagado'] ?? 0),
        'abono_capital' => (float) ($periodo['abono_capital'] ?? 0),
        'capital_final' => (float) ($periodo['capital_final'] ?? 0),
        'estado' => $estado !== '' ? $estado : 'Pendiente',
        'badge' => $estadoBadge,
    ];
}

$config = getConfiguracionGeneral($pdo);
$logoBase64 = cargarLogoBase64($config);

$nombreDeudor = $prestamo['es_particular'] ? ($prestamo['nombre_deudor'] ?? 'Tercero') : ($prestamo['nombre_completo'] ?? 'Socio');
$aval = $prestamo['nombre_aval'] ?? 'N/A';
$fechaGeneracion = date('Y-m-d H:i');

ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Estado préstamo #<?php echo (int) $idPrestamo; ?></title>
    <style>
        body { font-family: 'Helvetica', Arial, sans-serif; color: #1f2937; margin: 24px; }
        .header { display: flex; justify-content: space-between; align-items: center; padding-bottom: 12px; border-bottom: 2px solid #0d6efd; }
        .logo img { max-height: 60px; }
        .titulo { font-size: 22px; margin: 0; color: #0d6efd; }
        .subtitulo { margin: 4px 0 0; color: #6b7280; }
        .resumen-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin: 18px 0; }
        .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; background: #f8fafc; }
        .card h3 { margin: 0 0 6px; font-size: 13px; color: #475569; text-transform: uppercase; letter-spacing: .5px; }
        .valor { font-size: 16px; font-weight: 700; color: #0f172a; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { padding: 8px; border: 1px solid #e5e7eb; font-size: 12px; text-align: right; }
        th { background: #0d6efd; color: #fff; text-align: center; }
        td.text-start { text-align: left; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: 700; }
        .badge.pagado { background: #d1e7dd; color: #0f5132; }
        .badge.pendiente { background: #fff3cd; color: #664d03; }
        .badge.futuro { background: #e2e8f0; color: #475569; }
        .seccion { margin-top: 22px; }
        .seccion h2 { margin: 0 0 6px; color: #0f172a; font-size: 16px; }
        .detalle-lista { list-style: none; padding: 0; margin: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 8px; }
        .detalle-lista li { background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px; }
        .detalle-lista .etiqueta { display: block; font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: .5px; }
        .detalle-lista .dato { font-size: 14px; font-weight: 700; color: #0f172a; }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <p class="subtitulo">Informe de estado de cuenta</p>
            <h1 class="titulo">Préstamo #<?php echo (int) $idPrestamo; ?></h1>
            <p class="subtitulo">Generado el <?php echo htmlspecialchars($fechaGeneracion, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <div class="logo">
            <?php if ($logoBase64): ?>
                <img src="<?php echo $logoBase64; ?>" alt="Logo">
            <?php else: ?>
                <strong><?php echo htmlspecialchars($config['nombre_natillera'] ?? 'Natillera', ENT_QUOTES, 'UTF-8'); ?></strong>
            <?php endif; ?>
        </div>
    </div>

    <div class="resumen-grid">
        <div class="card">
            <h3>Titular / deudor</h3>
            <div class="valor"><?php echo htmlspecialchars($nombreDeudor, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="subtitulo">Aval: <?php echo htmlspecialchars($aval, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <div class="card">
            <h3>Saldo capital</h3>
            <div class="valor"><?php echo formatearMoneda((float) $prestamo['saldo_capital_actual']); ?></div>
            <div class="subtitulo">Monto inicial: <?php echo formatearMoneda((float) $prestamo['monto_prestamo']); ?></div>
        </div>
        <div class="card">
            <h3>Intereses pendientes</h3>
            <div class="valor"><?php echo formatearMoneda((float) $prestamo['saldo_intereses_actual']); ?></div>
            <div class="subtitulo">Tasa mensual: <?php echo htmlspecialchars((string) $prestamo['tasa_interes'], ENT_QUOTES, 'UTF-8'); ?>%</div>
        </div>
        <div class="card">
            <h3>Estado del préstamo</h3>
            <div class="valor"><?php echo htmlspecialchars(ucfirst((string) $prestamo['estado']), ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="subtitulo">Último pago: <?php echo htmlspecialchars($prestamo['ultima_fecha_pago'] ?? 'Sin registrar', ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
    </div>

    <div class="seccion">
        <h2>Resumen de cumplimiento</h2>
        <ul class="detalle-lista">
            <li><span class="etiqueta">Meses pagados</span><span class="dato"><?php echo $estadisticas['pagados']; ?></span></li>
            <li><span class="etiqueta">Meses pendientes o en mora</span><span class="dato"><?php echo $estadisticas['pendientes']; ?></span></li>
            <li><span class="etiqueta">Meses futuros programados</span><span class="dato"><?php echo $estadisticas['futuros']; ?></span></li>
            <li><span class="etiqueta">Interés causado acumulado</span><span class="dato"><?php echo formatearMoneda($totalesMovimientos['interes_causado']); ?></span></li>
            <li><span class="etiqueta">Interés pagado</span><span class="dato"><?php echo formatearMoneda($totalesMovimientos['interes_pagado']); ?></span></li>
            <li><span class="etiqueta">Abonos a capital</span><span class="dato"><?php echo formatearMoneda($totalesMovimientos['abono_capital']); ?></span></li>
        </ul>
    </div>

    <div class="seccion">
        <h2>Detalle mensual de capital e intereses</h2>
        <p class="subtitulo">Cada fila refleja el estado del mes, distinguiendo pagos realizados, pendientes y futuros.</p>
        <table>
            <thead>
                <tr>
                    <th>Mes</th>
                    <th>Capital inicio</th>
                    <th>Interés causado</th>
                    <th>Interés pagado</th>
                    <th>Abono capital</th>
                    <th>Capital final</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($filasPeriodos as $fila): ?>
                    <tr>
                        <td class="text-start"><?php echo htmlspecialchars($fila['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo formatearMoneda($fila['capital_inicio']); ?></td>
                        <td><?php echo formatearMoneda($fila['interes_causado']); ?></td>
                        <td><?php echo formatearMoneda($fila['interes_pagado']); ?></td>
                        <td><?php echo formatearMoneda($fila['abono_capital']); ?></td>
                        <td><?php echo formatearMoneda($fila['capital_final']); ?></td>
                        <td class="text-start"><span class="badge <?php echo $fila['badge']; ?>"><?php echo htmlspecialchars($fila['estado'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($filasPeriodos)): ?>
                    <tr><td colspan="7" class="text-start">No hay periodos registrados para este préstamo.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
<?php
$html = ob_get_clean();

$dompdf = crearDompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4');
$dompdf->render();

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="estado_prestamo_' . $idPrestamo . '.pdf"');
echo $dompdf->output();
