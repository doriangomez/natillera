<?php
use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

checkAuth();

// Validación de dependencias y codificación
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    exit('No se encuentra el autoload de Dompdf. Asegúrese de instalar las dependencias con Composer.');
}
require_once $autoloadPath;

if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
    if (mb_internal_encoding() !== 'UTF-8') {
        exit('La generación del PDF requiere UTF-8.');
    }
} elseif (!preg_match('//u', 'á')) {
    exit('La generación del PDF requiere UTF-8.');
}

function obtenerSocio(int $idSocio, PDO $pdo): array
{
    $socioStmt = $pdo->prepare('SELECT id_socio, nombre_completo, telefono, numero_polla, periodicidad_pago, valor_presupuestado, saldo_socio FROM socios WHERE id_socio = :id');
    $socioStmt->execute([':id' => $idSocio]);
    $socio = $socioStmt->fetch();
    if (!$socio) {
        throw new RuntimeException('Socio no encontrado.');
    }

    return $socio;
}

function obtenerMovimientos(PDO $pdo, array $filtros): array
{
    $where = ['m.id_socio = :s'];
    $params = [':s' => $filtros['socio']];
    if (!empty($filtros['desde'])) { $where[] = 'm.fecha >= :d'; $params[':d'] = $filtros['desde']; }
    if (!empty($filtros['hasta'])) { $where[] = 'm.fecha <= :h'; $params[':h'] = $filtros['hasta']; }
    if (!empty($filtros['actividad'])) { $where[] = 'm.id_actividad = :a'; $params[':a'] = $filtros['actividad']; }

    $sql = "SELECT m.*, a.nombre_actividad, a.afecta_saldo_socio, a.es_polla, a.es_prestamo, a.es_pago_prestamo,
                   COALESCE(mp.nombre, m.medio_consignacion) AS medio
            FROM movimientos m
            JOIN actividades_maestro a ON m.id_actividad = a.id_actividad
            LEFT JOIN medios_pago mp ON m.id_medio_pago = mp.id";
    if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
    $sql .= ' ORDER BY m.fecha ASC, m.id_movimiento ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function obtenerPrestamos(PDO $pdo, int $idSocio): array
{
    $prestamosStmt = $pdo->prepare('SELECT id_prestamo, nombre_deudor, saldo_capital_actual, saldo_intereses_actual FROM prestamos WHERE id_socio = :id');
    $prestamosStmt->execute([':id' => $idSocio]);
    $prestamos = $prestamosStmt->fetchAll();

    $totalCapital = 0;
    $totalIntereses = 0;
    foreach ($prestamos as $p) {
        $totalCapital += (float)$p['saldo_capital_actual'];
        $totalIntereses += (float)$p['saldo_intereses_actual'];
    }

    return [$prestamos, $totalCapital, $totalIntereses];
}

function obtenerPagosIntereses(PDO $pdo, int $idSocio): array
{
    $sql = "SELECT cp.fecha_pago, cp.numero_cuota, cp.valor_interes_pagado, p.id_prestamo
            FROM cuotas_prestamo cp
            JOIN prestamos p ON cp.id_prestamo = p.id_prestamo
            WHERE p.id_socio = :id AND cp.valor_interes_pagado > 0
            ORDER BY cp.fecha_pago ASC, cp.numero_cuota ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $idSocio]);
    $pagos = $stmt->fetchAll();

    if (!is_array($pagos)) {
        exit('No se pudieron obtener los pagos de intereses.');
    }

    return $pagos;
}

function formatearMoneda(float $valor): string
{
    return '$' . number_format($valor, 0, ',', '.');
}

function agruparMovimientos(array $movimientos): array
{
    $meses = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    $cuotasPorMes = [];
    $pollasPorMes = [];

    foreach ($movimientos as $m) {
        $fecha = $m['fecha'];
        $quincena = (int)($m['quincena'] ?? 0);
        $mesNum = (int)date('n', strtotime($fecha));
        $anio = date('Y', strtotime($fecha));
        $mesClave = date('Y-m', strtotime($fecha));
        $mesLabel = ($meses[$mesNum] ?? 'Mes') . ' ' . $anio . ($quincena ? ' - Q'.$quincena : '');

        $reglaSocio = normalizarReglaAfectacion($m['afecta_saldo_socio'] ?? 'neutral');
        $afectaSocio = $m['es_polla'] ? 'neutral' : $reglaSocio;
        $valorSocio = 0;
        if ($afectaSocio === 'suma') { $valorSocio = $m['valor']; }
        elseif ($afectaSocio === 'resta') { $valorSocio = -$m['valor']; }

        if ($afectaSocio !== 'neutral' && empty($m['es_polla']) && empty($m['es_prestamo']) && empty($m['es_pago_prestamo'])) {
            if (!isset($cuotasPorMes[$mesClave])) {
                $cuotasPorMes[$mesClave] = ['clave' => $mesClave, 'label' => $mesLabel, 'total' => 0];
            }
            $cuotasPorMes[$mesClave]['total'] += $valorSocio;
        }
        if (!empty($m['es_polla'])) {
            if (!isset($pollasPorMes[$mesClave])) {
                $pollasPorMes[$mesClave] = ['clave' => $mesClave, 'label' => $mesLabel, 'total' => 0];
            }
            $pollasPorMes[$mesClave]['total'] += abs($m['valor']);
        }
    }

    ksort($cuotasPorMes);
    ksort($pollasPorMes);

    return [$cuotasPorMes, $pollasPorMes];
}

function construirDetalleCuotas(array $movimientos): array
{
    $detalles = [];
    $saldo = 0;
    foreach ($movimientos as $m) {
        if (!empty($m['es_polla']) || !empty($m['es_prestamo']) || !empty($m['es_pago_prestamo'])) {
            continue;
        }
        $reglaSocio = normalizarReglaAfectacion($m['afecta_saldo_socio'] ?? 'neutral');
        if ($reglaSocio === 'neutral') {
            continue;
        }
        $valor = $reglaSocio === 'suma' ? (float)$m['valor'] : -(float)$m['valor'];
        $saldo += $valor;
        $detalles[] = [
            'fecha' => $m['fecha'],
            'quincena' => (int)($m['quincena'] ?? 0),
            'actividad' => $m['nombre_actividad'],
            'valor' => $valor,
            'saldo' => $saldo,
        ];
    }

    return $detalles;
}

function nombreArchivoSocio(array $socio): string
{
    $seguro = preg_replace('/[^A-Za-z0-9_\-]/', '_', $socio['nombre_completo']);
    $seguro = trim($seguro, '_');
    return $seguro !== '' ? $seguro : ('socio_' . (int)$socio['id_socio']);
}

function prepararLogo(array $config): string
{
    if (empty($config['logo_archivo'])) {
        exit('No se configuró un archivo de logo.');
    }

    $rutaLogo = realpath(__DIR__ . '/../public/assets/logo/' . basename((string)$config['logo_archivo']));
    if (!$rutaLogo || !is_readable($rutaLogo)) {
        exit('No se encontró el logo en la ruta esperada.');
    }

    $mime = mime_content_type($rutaLogo) ?: 'image/png';
    $contenido = (string) file_get_contents($rutaLogo);
    if ($contenido === '') {
        exit('El archivo de logo está vacío o es ilegible.');
    }

    return 'data:' . $mime . ';base64,' . base64_encode($contenido);
}

function tablaHtml(array $headers, array $rows, string $class = 'table'): string
{
    $thead = '<thead><tr>';
    foreach ($headers as $h) {
        $thead .= '<th>' . htmlspecialchars((string)$h, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    $thead .= '</tr></thead>';

    $tbody = '<tbody>';
    if (!$rows) {
        $colspan = count($headers);
        $tbody .= '<tr><td colspan="' . $colspan . '" style="text-align:center;">No hay información disponible.</td></tr>';
    } else {
        foreach ($rows as $row) {
            $tbody .= '<tr>';
            foreach ($row as $cell) {
                $tbody .= '<td>' . htmlspecialchars((string)$cell, ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $tbody .= '</tr>';
        }
    }
    $tbody .= '</tbody>';

    return '<table class="' . $class . '">' . $thead . $tbody . '</table>';
}

function construirHtmlPdf(array $data): string
{
    $logo = $data['logo'];
    $socio = $data['socio'];
    $config = $data['config'];
    $resultadosPolla = $data['resultadosPolla'];
    $fechaGeneracion = date('Y-m-d H:i');

    $datosSocio = [
        ['Campo' => 'Nombre', 'Valor' => $socio['nombre_completo']],
        ['Campo' => 'Teléfono', 'Valor' => $socio['telefono'] ?: 'Sin teléfono'],
        ['Campo' => 'Tipo de pago', 'Valor' => $socio['periodicidad_pago']],
        ['Campo' => 'Valor cuota', 'Valor' => formatearMoneda((float)$socio['valor_presupuestado'])],
        ['Campo' => 'Saldo socio', 'Valor' => formatearMoneda((float)$socio['saldo_socio'])],
    ];
    if (!empty($socio['numero_polla'])) {
        $datosSocio[] = ['Campo' => 'Número de polla', 'Valor' => $socio['numero_polla']];
    }

    $filasCuotas = [];
    $totalCuotas = 0;
    foreach ($data['cuotasPorMes'] as $c) {
        $filasCuotas[] = [$c['label'], formatearMoneda((float)$c['total'])];
        $totalCuotas += (float)$c['total'];
    }
    if ($filasCuotas) {
        $filasCuotas[] = ['Total aportado', formatearMoneda($totalCuotas)];
    }

    $filasDetalles = [];
    foreach ($data['detallesCuotas'] as $detalle) {
        $filasDetalles[] = [
            $detalle['fecha'] . ($detalle['quincena'] ? ' (Q' . $detalle['quincena'] . ')' : ''),
            $detalle['actividad'],
            formatearMoneda($detalle['valor']),
            formatearMoneda($detalle['saldo']),
        ];
    }

    $filasPollas = [];
    $totalPollas = 0;
    foreach ($data['pollasPorMes'] as $p) {
        $numero = $resultadosPolla[$p['clave']]['numero_ganador'] ?? '—';
        $filasPollas[] = [$p['label'], formatearMoneda((float)$p['total']), $numero];
        $totalPollas += (float)$p['total'];
    }
    if ($filasPollas) {
        $filasPollas[] = ['Total pagado', formatearMoneda($totalPollas), ''];
    }

    $filasPrestamos = [];
    foreach ($data['prestamos'] as $p) {
        $filasPrestamos[] = [
            'Préstamo #' . (int)$p['id_prestamo'],
            $p['nombre_deudor'],
            formatearMoneda((float)$p['saldo_capital_actual']),
            formatearMoneda((float)$p['saldo_intereses_actual']),
        ];
    }
    if ($filasPrestamos) {
        $filasPrestamos[] = ['Totales', '', formatearMoneda($data['totalCapital']), formatearMoneda($data['totalIntereses'])];
    }

    $filasIntereses = [];
    foreach ($data['pagosIntereses'] as $pago) {
        $concepto = 'Interés cuota #' . (int)$pago['numero_cuota'] . ' - Préstamo #' . (int)$pago['id_prestamo'];
        $filasIntereses[] = [
            $pago['fecha_pago'] ?: 'Sin fecha',
            $concepto,
            formatearMoneda((float)$pago['valor_interes_pagado']),
        ];
    }

    $mensajes = [];
    if (!empty($config['datos_globales'])) {
        $mensajes[] = trim($config['datos_globales']);
    }
    if (trim($data['mensajeUsuario']) !== '') {
        $mensajes[] = trim($data['mensajeUsuario']);
    }

    $htmlMensajes = '';
    foreach ($mensajes as $mensaje) {
        $lineas = explode("\n", $mensaje);
        foreach ($lineas as $linea) {
            $htmlMensajes .= '<p class="nota">' . htmlspecialchars($linea, ENT_QUOTES, 'UTF-8') . '</p>';
        }
    }

    $nombreSistema = $config['nombre_sistema'] ?? 'Creciendo Juntos';

    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <style>
            @page { margin: 1.5cm; }
            body { font-family: 'DejaVu Sans', sans-serif; color: #333; font-size: 12px; }
            .header { border-bottom: 2px solid #003366; padding-bottom: 10px; margin-bottom: 20px; }
            .header table { width: 100%; border-collapse: collapse; }
            .header img { width: 140px; }
            .titulo { color: #003366; font-size: 20px; margin: 0; text-align: center; }
            .subtitulo { color: #4a4a4a; font-size: 12px; margin: 4px 0 0 0; text-align: center; }
            .meta { font-size: 11px; color: #666; margin: 0; text-align: center; }
            .section-title { color: #003366; font-size: 14px; margin: 16px 0 8px; }
            .table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
            .table th { background: #f2f4f7; color: #003366; text-align: left; border: 1px solid #d4d9e2; padding: 6px; font-size: 11px; }
            .table td { border: 1px solid #d4d9e2; padding: 6px; font-size: 11px; }
            .nota { margin: 4px 0; font-size: 11px; }
            .footer { text-align: center; color: #777; font-size: 9px; margin-top: 14px; }
        </style>
    </head>
    <body>
        <div class="header">
            <table>
                <tr>
                    <td style="width: 160px;"><img src="<?php echo htmlspecialchars($logo, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo"></td>
                    <td style="text-align: center;">
                        <h1 class="titulo">Creciendo Juntos</h1>
                        <p class="subtitulo"><?php echo htmlspecialchars($nombreSistema, ENT_QUOTES, 'UTF-8'); ?></p>
                        <p class="meta">Movimientos por socio — Generado: <?php echo htmlspecialchars($fechaGeneracion, ENT_QUOTES, 'UTF-8'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <h2 class="section-title">Datos del socio</h2>
        <?php echo tablaHtml(['Campo', 'Valor'], array_map(fn($d) => [$d['Campo'], $d['Valor']], $datosSocio)); ?>

        <h2 class="section-title">Pagos de cuota</h2>
        <?php echo tablaHtml(['Mes', 'Valor'], $filasCuotas); ?>

        <h2 class="section-title">Detalle cuotas con saldo</h2>
        <?php echo tablaHtml(['Fecha', 'Actividad', 'Valor', 'Saldo después'], $filasDetalles); ?>

        <h2 class="section-title">Pagos de pollas</h2>
        <?php echo tablaHtml(['Mes', 'Valor', 'Número ganador'], $filasPollas); ?>

        <h2 class="section-title">Estado de préstamos</h2>
        <?php echo tablaHtml(['Identificador', 'Deudor', 'Capital pendiente', 'Intereses pendientes'], $filasPrestamos); ?>

        <?php if (!empty($data['prestamos']) || !empty($data['pagosIntereses'])): ?>
            <h2 class="section-title">Pago de intereses de préstamos</h2>
            <?php echo tablaHtml(['Fecha', 'Concepto', 'Valor'], $filasIntereses); ?>
        <?php endif; ?>

        <?php if ($htmlMensajes): ?>
            <h2 class="section-title">Noticias de la natillera</h2>
            <?php echo $htmlMensajes; ?>
        <?php endif; ?>

        <p class="footer">Documento generado electrónicamente — no requiere firma física.</p>
    </body>
    </html>
    <?php
    return (string) ob_get_clean();
}

function generarPdf(string $html): string
{
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->setChroot(__DIR__ . '/..');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    return $dompdf->output();
}

$modo = $_GET['modo'] ?? 'individual';
$idSocio = isset($_GET['socio']) ? (int) $_GET['socio'] : 0;
$desde = $_GET['desde'] ?? '';
$hasta = $_GET['hasta'] ?? '';
$actividad = isset($_GET['actividad']) ? (int) $_GET['actividad'] : 0;
$mensajeUsuario = $_GET['mensaje'] ?? '';

$filtros = [
    'socio' => $idSocio,
    'desde' => $desde,
    'hasta' => $hasta,
    'actividad' => $actividad,
];

asegurarTablaResultadosPolla($pdo);
$resultadosPollaIndex = indexResultadosPollaPorMes($pdo);
$config = getConfiguracionGeneral($pdo);
$logo = prepararLogo($config);

if ($modo === 'colectivo') {
    $rutaCarpeta = trim($_GET['ruta'] ?? 'exportes_movimientos');
    if ($rutaCarpeta === '') {
        $rutaCarpeta = 'exportes_movimientos';
    }
    $rutaCarpeta = trim(preg_replace('/[^A-Za-z0-9_\-\/]/', '_', $rutaCarpeta), '/');

    $socios = $pdo->query('SELECT id_socio, nombre_completo FROM socios ORDER BY nombre_completo ASC')->fetchAll();
    if (!$socios) {
        exit('No hay socios para exportar.');
    }

    $zip = new ZipArchive();
    $tmpFile = tempnam(sys_get_temp_dir(), 'movs_').'.zip';
    if ($zip->open($tmpFile, ZipArchive::CREATE)!==TRUE) {
        exit('No se pudo preparar el archivo comprimido.');
    }

    foreach ($socios as $s) {
        try {
            $socioDetalle = obtenerSocio((int)$s['id_socio'], $pdo);
            $filtros['socio'] = (int)$s['id_socio'];
            $movs = obtenerMovimientos($pdo, $filtros);
            [$prestamos, $totalCapital, $totalIntereses] = obtenerPrestamos($pdo, (int)$s['id_socio']);
            $pagosIntereses = obtenerPagosIntereses($pdo, (int)$s['id_socio']);
            [$cuotasPorMes, $pollasPorMes] = agruparMovimientos($movs);
            $detallesCuotas = construirDetalleCuotas($movs);

            $html = construirHtmlPdf([
                'socio' => $socioDetalle,
                'cuotasPorMes' => $cuotasPorMes,
                'pollasPorMes' => $pollasPorMes,
                'prestamos' => $prestamos,
                'totalCapital' => $totalCapital,
                'totalIntereses' => $totalIntereses,
                'config' => $config,
                'resultadosPolla' => $resultadosPollaIndex,
                'detallesCuotas' => $detallesCuotas,
                'pagosIntereses' => $pagosIntereses,
                'logo' => $logo,
                'mensajeUsuario' => $mensajeUsuario,
            ]);
            $pdf = generarPdf($html);
            $nombreArchivo = ($rutaCarpeta ? $rutaCarpeta.'/' : '') . nombreArchivoSocio($socioDetalle) . '_movimientos.pdf';
            $zip->addFromString($nombreArchivo, $pdf);
        } catch (Throwable $e) {
            continue;
        }
    }

    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="movimientos_socios.zip"');
    header('Content-Length: ' . filesize($tmpFile));
    readfile($tmpFile);
    unlink($tmpFile);
    exit;
}

if (!$idSocio) {
    exit('Debe seleccionar un socio para exportar el PDF.');
}

$socio = obtenerSocio($idSocio, $pdo);
$movimientos = obtenerMovimientos($pdo, $filtros);
[$prestamos, $totalCapital, $totalIntereses] = obtenerPrestamos($pdo, $idSocio);
$pagosIntereses = obtenerPagosIntereses($pdo, $idSocio);
[$cuotasPorMes, $pollasPorMes] = agruparMovimientos($movimientos);
$detallesCuotas = construirDetalleCuotas($movimientos);

$html = construirHtmlPdf([
    'socio' => $socio,
    'cuotasPorMes' => $cuotasPorMes,
    'pollasPorMes' => $pollasPorMes,
    'prestamos' => $prestamos,
    'totalCapital' => $totalCapital,
    'totalIntereses' => $totalIntereses,
    'config' => $config,
    'resultadosPolla' => $resultadosPollaIndex,
    'detallesCuotas' => $detallesCuotas,
    'pagosIntereses' => $pagosIntereses,
    'logo' => $logo,
    'mensajeUsuario' => $mensajeUsuario,
]);

$pdf = generarPdf($html);
$nombre = nombreArchivoSocio($socio) . '_movimientos.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
