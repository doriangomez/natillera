<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';




checkAuth();

function limpiarCarpeta($ruta) {
    foreach (glob($ruta . '/*') as $archivo) {
        if (is_dir($archivo)) {
            limpiarCarpeta($archivo);
            rmdir($archivo);
        } else {
            unlink($archivo);
        }
    }
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

function renderPlantillaPDF(string $html_body): string
{
    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <style>
            @page { margin: 1.5cm; }
            :root { color-scheme: light; }
            @font-face {
                font-family: 'DejaVu Sans';
                font-style: normal;
                font-weight: normal;
                src: local('DejaVu Sans'), local('DejaVuSans');
            }
            @font-face {
                font-family: 'DejaVu Sans';
                font-style: normal;
                font-weight: bold;
                src: local('DejaVu Sans Bold'), local('DejaVuSans-Bold');
            }
            body {
                font-family: 'DejaVu Sans', 'Inter', 'Segoe UI', sans-serif;
                color: #0f172a;
                font-size: 12px;
                line-height: 1.6;
                background: #f5f7fb;
            }
            .layout {
                max-width: 960px;
                margin: 0 auto;
                background: #ffffff;
                border-radius: 16px;
                padding: 24px 28px;
                box-shadow: 0 14px 36px rgba(15, 23, 42, 0.08);
            }
            .header {
                display: grid;
                grid-template-columns: 110px 1fr 110px;
                align-items: center;
                gap: 16px;
                padding: 16px 18px;
                border-radius: 14px;
                background: linear-gradient(120deg, #0f62fe, #17b3c1);
                color: #fff;
                margin-bottom: 18px;
            }
            .header img {
                width: 110px;
                height: 110px;
                object-fit: contain;
                background: rgba(255,255,255,0.08);
                border-radius: 14px;
                padding: 10px;
                border: 1px solid rgba(255,255,255,0.15);
            }
            .eyebrow {
                text-transform: uppercase;
                letter-spacing: 0.08em;
                font-size: 10px;
                opacity: 0.85;
                margin: 0 0 4px 0;
            }
            .titulo { font-size: 24px; margin: 0 0 4px 0; font-weight: 700; }
            .subtitulo { margin: 0 0 2px 0; opacity: 0.9; }
            .meta { font-size: 11px; margin: 0; opacity: 0.8; }
            .pill {
                justify-self: end;
                background: rgba(255,255,255,0.16);
                border: 1px solid rgba(255,255,255,0.2);
                color: #fff;
                padding: 8px 12px;
                border-radius: 12px;
                font-weight: 600;
                text-align: center;
            }
            .section {
                margin-top: 18px;
                padding: 14px 16px;
                border-radius: 12px;
                background: #f9fafb;
                border: 1px solid #e5e7eb;
            }
            .section-title {
                color: #0f172a;
                font-size: 14px;
                margin: 0 0 8px 0;
                display: flex;
                align-items: center;
                gap: 6px;
                font-weight: 700;
            }
            .section-title::before {
                content: '';
                width: 10px;
                height: 10px;
                border-radius: 50%;
                background: linear-gradient(120deg, #0f62fe, #17b3c1);
                display: inline-block;
            }
            .table {
                width: 100%;
                border-collapse: collapse;
                margin: 10px 0 6px;
                background: #ffffff;
                border-radius: 12px;
                overflow: hidden;
                box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
            }
            .table th {
                background: linear-gradient(120deg, #0f62fe, #0ea5e9);
                color: #ffffff;
                text-align: left;
                padding: 8px 10px;
                font-size: 11px;
                letter-spacing: 0.01em;
            }
            .table td {
                padding: 8px 10px;
                font-size: 11px;
                border-bottom: 1px solid #eef2f7;
            }
            .table tr:last-child td { border-bottom: none; }
            .table tbody tr:nth-child(odd) { background: #f8fafc; }
            .nota {
                margin: 6px 0;
                font-size: 11px;
                color: #0f172a;
                background: #ecfeff;
                border: 1px solid #bae6fd;
                padding: 8px 10px;
                border-radius: 10px;
            }
            .footer {
                text-align: center;
                color: #6b7280;
                font-size: 10px;
                margin-top: 18px;
            }
        </style>
    </head>
    <body>
        <?php echo $html_body; ?>
    </body>
    </html>
    <?php
    return (string) ob_get_clean();
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
    <div class="layout">
        <div class="header">
            <img src="<?php echo htmlspecialchars($logo, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo">
            <div>
                <p class="eyebrow">Reporte detallado</p>
                <h1 class="titulo"><?php echo htmlspecialchars($nombreSistema, ENT_QUOTES, 'UTF-8'); ?></h1>
                <p class="subtitulo">Movimientos por socio</p>
                <p class="meta">Generado: <?php echo htmlspecialchars($fechaGeneracion, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div class="pill">Creciendo Juntos</div>
        </div>

        <div class="section">
            <h2 class="section-title">Datos del socio</h2>
            <?php echo tablaHtml(['Campo', 'Valor'], array_map(fn($d) => [$d['Campo'], $d['Valor']], $datosSocio)); ?>
        </div>

        <div class="section">
            <h2 class="section-title">Pagos de cuota</h2>
            <?php echo tablaHtml(['Mes', 'Valor'], $filasCuotas); ?>
        </div>

        <div class="section">
            <h2 class="section-title">Detalle cuotas con saldo</h2>
            <?php echo tablaHtml(['Fecha', 'Actividad', 'Valor', 'Saldo después'], $filasDetalles); ?>
        </div>

        <div class="section">
            <h2 class="section-title">Pagos de pollas</h2>
            <?php echo tablaHtml(['Mes', 'Valor', 'Número ganador'], $filasPollas); ?>
        </div>

        <div class="section">
            <h2 class="section-title">Estado de préstamos</h2>
            <?php echo tablaHtml(['Identificador', 'Deudor', 'Capital pendiente', 'Intereses pendientes'], $filasPrestamos); ?>
        </div>

        <?php if (!empty($data['prestamos']) || !empty($data['pagosIntereses'])): ?>
            <div class="section">
                <h2 class="section-title">Pago de intereses de préstamos</h2>
                <?php echo tablaHtml(['Fecha', 'Concepto', 'Valor'], $filasIntereses); ?>
            </div>
        <?php endif; ?>

        <?php if ($htmlMensajes): ?>
            <div class="section">
                <h2 class="section-title">Noticias y recordatorios</h2>
                <?php echo $htmlMensajes; ?>
            </div>
        <?php endif; ?>

        <p class="footer">Documento generado electrónicamente — no requiere firma física.</p>
    </div>
    <?php
    $contenidoSocio = (string) ob_get_clean();

    return renderPlantillaPDF($contenidoSocio);
}

$modo = $_GET['modo'] ?? 'individual';
$idSocio = isset($_GET['socio']) ? (int) $_GET['socio'] : 0;
$desde = $_GET['desde'] ?? '';
$hasta = $_GET['hasta'] ?? '';
$actividad = isset($_GET['actividad']) ? (int) $_GET['actividad'] : 0;
$mensajeUsuario = $_GET['mensaje'] ?? '';
$carpetaDestino = $_GET['ruta'] ?? '';

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
    $rutaHtml = __DIR__ . '/html_pdfs';
    $rutaPdf = __DIR__ . '/pdf_generados';

    if (isset($_GET['zip']) && $_GET['zip']) {
        exit('ZIP NO PERMITIDO – flujo inválido');
    }

    if (!is_dir($rutaHtml)) {
        mkdir($rutaHtml, 0777, true);
    }
    if (!is_dir($rutaPdf)) {
        mkdir($rutaPdf, 0777, true);
    }

    limpiarCarpeta($rutaHtml);
    limpiarCarpeta($rutaPdf);

    $socios = $pdo->query('SELECT id_socio, nombre_completo FROM socios ORDER BY nombre_completo ASC')->fetchAll();
    if (!$socios) {
        exit('No hay socios para exportar.');
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
            $nombreArchivo = nombreArchivoSocio($socioDetalle) . '.html';
            file_put_contents($rutaHtml . '/' . $nombreArchivo, $html);
        } catch (Throwable $e) {
            continue;
        }
    }

    limpiarCarpeta($rutaPdf);

    $rutaHtmlParaConversion = $rutaHtml;
    $rutaPdfGenerados = $rutaPdf;
    require __DIR__ . '/convertir_html_a_pdf.php';
    $nombreCarpetaZip = preg_replace('/[^A-Za-z0-9_\-]/', '_', $carpetaDestino) ?: 'reportes_movimientos';
    $nombreZip = $nombreCarpetaZip . '_' . date('Ymd_His') . '.zip';
    $rutaZip = sys_get_temp_dir() . '/' . $nombreZip;

    $zip = new ZipArchive();
    if ($zip->open($rutaZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        exit('No se pudo crear el archivo ZIP.');
    }

    $prefijo = $nombreCarpetaZip;
    foreach (glob($rutaPdfGenerados . '/*.pdf') as $archivoPdf) {
        $nombreInterno = $prefijo ? ($prefijo . '/' . basename($archivoPdf)) : basename($archivoPdf);
        $zip->addFile($archivoPdf, $nombreInterno);
    }

    $zip->close();

    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Exportación masiva finalizada correctamente';
    exit;
}

if (!$idSocio) {
    exit('Debe seleccionar un socio para exportar el reporte.');
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

$nombre = nombreArchivoSocio($socio) . '_movimientos.html';
header('Content-Type: text/html; charset=UTF-8');
header('Content-Disposition: inline; filename="' . $nombre . '"');
echo $html;
