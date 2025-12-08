<?php
use Dompdf\Dompdf;
use Dompdf\Options;

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
    $sql .= ' ORDER BY m.id_movimiento DESC';
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

function obtenerPagosIntereses(PDO $pdo, int $idSocio, array $filtros): array
{
    $where = ['m.id_socio = :id', 'a.es_pago_interes = 1'];
    $params = [':id' => $idSocio];

    if (!empty($filtros['desde'])) { $where[] = 'm.fecha >= :d'; $params[':d'] = $filtros['desde']; }
    if (!empty($filtros['hasta'])) { $where[] = 'm.fecha <= :h'; $params[':h'] = $filtros['hasta']; }
    if (!empty($filtros['actividad'])) { $where[] = 'm.id_actividad = :a'; $params[':a'] = $filtros['actividad']; }

    $sql = "SELECT m.fecha AS fecha_pago, m.motivo, m.valor AS valor_interes_pagado, m.id_actividad, a.nombre_actividad, NULL AS numero_cuota, NULL AS id_prestamo
            FROM movimientos m
            JOIN actividades_maestro a ON m.id_actividad = a.id_actividad
            WHERE " . implode(' AND ', $where) . '
            ORDER BY m.id_movimiento DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
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
            $pollasPorMes[] = [
                'clave' => $mesClave,
                'label' => $mesLabel,
                'total' => abs($m['valor']),
                'orden' => strtotime($fecha),
            ];
        }
    }

    ksort($cuotasPorMes);
    usort($pollasPorMes, function ($a, $b) {
        return ($a['orden'] <=> $b['orden']);
    });

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

function renderHtmlSocioIndividual(
    int $idSocio,
    array $filtros,
    PDO $pdo,
    array $config,
    array $resultadosPollaIndex,
    string $logo,
    string $mensajeUsuario
): string {
    $socio = obtenerSocio($idSocio, $pdo);
    $filtrosSocio = $filtros;
    $filtrosSocio['socio'] = $idSocio;

    $movimientos = obtenerMovimientos($pdo, $filtrosSocio);
    [$prestamos, $totalCapital, $totalIntereses] = obtenerPrestamos($pdo, $idSocio);
    $pagosIntereses = obtenerPagosIntereses($pdo, $idSocio, $filtrosSocio);
    [$cuotasPorMes, $pollasPorMes] = agruparMovimientos($movimientos);
    $detallesCuotas = construirDetalleCuotas($movimientos);

    return construirHtmlPdf([
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

function tablaHtml(array $headers, array $rows, string $class = 'table', array $alignments = []): string
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
            foreach ($row as $index => $cell) {
                $alignClass = $alignments[$index] ?? '';
                $classAttr = $alignClass !== '' ? ' class="' . htmlspecialchars($alignClass, ENT_QUOTES, 'UTF-8') . '"' : '';
                $tbody .= '<td' . $classAttr . '>' . htmlspecialchars((string)$cell, ENT_QUOTES, 'UTF-8') . '</td>';
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
                font-family: 'DejaVu Sans', sans-serif;
                color: #111827;
                font-size: 10.5pt;
                line-height: 1.35;
                margin: 0;
                padding: 0;
                background: #fff;
            }
            .document {
                width: 100%;
            }
            .header {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 0 0 8px;
                margin-bottom: 6px;
                border-bottom: 2px solid #003366;
            }
            .header img {
                width: 80px;
                height: 80px;
                object-fit: contain;
            }
            .header-title {
                font-size: 14pt;
                font-weight: 700;
                margin: 0;
                color: #0b1f33;
            }
            .meta {
                margin: 2px 0 0;
                font-size: 9.5pt;
                color: #4b5563;
            }
            .section {
                margin-bottom: 10px;
            }
            .section-title {
                font-size: 14pt;
                font-weight: 700;
                color: #003366;
                margin: 0 0 6px 0;
            }
            .table {
                width: 100%;
                border-collapse: collapse;
                font-size: 10pt;
            }
            .table th {
                background: #003366;
                color: #fff;
                padding: 5px;
                border: 1px solid #999;
                text-align: left;
            }
            .table td {
                padding: 4px;
                border: 1px solid #999;
            }
            .table tr:nth-child(even) td {
                background: #F4F4F4;
            }
            .text-right { text-align: right; }
            .text-center { text-align: center; }
            .nota {
                margin: 0;
                font-size: 10pt;
                color: #111827;
                background: #eef2ff;
                border: 1px solid #c7d2fe;
                padding: 6px 8px;
            }
            .footer {
                text-align: center;
                color: #4b5563;
                font-size: 9.5pt;
                margin-top: 10px;
            }
        </style>
    </head>
    <body>
        <div class="document">
            <?php echo $html_body; ?>
        </div>
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
    foreach ($data['pollasPorMes'] as $p) {
        $numero = $resultadosPolla[$p['clave']]['numero_ganador'] ?? '—';
        $filasPollas[] = [$p['label'], formatearMoneda((float)$p['total']), $numero];
    }

    $filasPrestamos = [];
    $totalPrestamoCapital = 0;
    $totalPrestamoInteres = 0;
    foreach ($data['prestamos'] as $p) {
        $filasPrestamos[] = [
            'Préstamo #' . (int)$p['id_prestamo'],
            $p['nombre_deudor'],
            formatearMoneda((float)$p['saldo_capital_actual']),
            formatearMoneda((float)$p['saldo_intereses_actual']),
        ];
        $totalPrestamoCapital += (float)$p['saldo_capital_actual'];
        $totalPrestamoInteres += (float)$p['saldo_intereses_actual'];
    }
    if ($filasPrestamos) {
        $filasPrestamos[] = ['Totales', '', formatearMoneda($data['totalCapital']), formatearMoneda($data['totalIntereses'])];
    }

    $filasIntereses = [];
    foreach ($data['pagosIntereses'] as $pago) {
        $concepto = 'Pago de intereses';
        if (!empty($pago['numero_cuota']) && isset($pago['id_prestamo'])) {
            $concepto = 'Interés cuota #' . (int)$pago['numero_cuota'] . ' - Préstamo #' . (int)$pago['id_prestamo'];
        } elseif (!empty($pago['motivo'])) {
            $concepto = $pago['motivo'];
        } elseif (!empty($pago['nombre_actividad'])) {
            $concepto = $pago['nombre_actividad'];
        }

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
    $etiquetasSocio = [];
    if (!empty($socio['periodicidad_pago'])) { $etiquetasSocio[] = 'Periodicidad: ' . htmlspecialchars($socio['periodicidad_pago'], ENT_QUOTES, 'UTF-8'); }
    if (!empty($socio['numero_polla'])) { $etiquetasSocio[] = 'Polla #' . htmlspecialchars($socio['numero_polla'], ENT_QUOTES, 'UTF-8'); }
    $etiquetasSocio[] = 'Valor cuota: ' . formatearMoneda((float)$socio['valor_presupuestado']);
    $etiquetasSocio[] = 'Teléfono: ' . ($socio['telefono'] ?: 'Sin teléfono');

    ob_start();
    ?>
    <div class="header">
        <img src="<?php echo htmlspecialchars($logo, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo" data-role="logo">
        <div>
            <p class="header-title">Creciendo Juntos – Estado de Cuenta del Socio</p>
            <p class="meta">Generado: <?php echo htmlspecialchars($fechaGeneracion, ENT_QUOTES, 'UTF-8'); ?> · Socio #<?php echo (int)$socio['id_socio']; ?></p>
            <p class="meta"><?php echo htmlspecialchars($nombreSistema, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
    </div>

    <div class="section" data-section="datos-socio">
        <h2 class="section-title">Datos del socio</h2>
        <?php echo tablaHtml(['Campo', 'Valor'], array_map(fn($d) => [$d['Campo'], $d['Valor']], $datosSocio)); ?>
    </div>

    <div class="section" data-section="pagos-cuota">
        <h2 class="section-title">Pagos de cuota</h2>
        <?php echo tablaHtml(['Mes', 'Valor'], $filasCuotas, 'table', ['','text-right']); ?>
    </div>

    <div class="section" data-section="detalle-cuotas">
        <h2 class="section-title">Detalle de cuotas</h2>
        <?php echo tablaHtml(['Fecha', 'Actividad', 'Valor', 'Saldo después'], $filasDetalles, 'table', ['', '', 'text-right', 'text-right']); ?>
    </div>

    <div class="section" data-section="pagos-pollas">
        <h2 class="section-title">Pagos de pollas</h2>
        <?php echo tablaHtml(['Mes', 'Valor', 'Número ganador'], $filasPollas, 'table', ['', 'text-right', 'text-center']); ?>
    </div>

    <div class="section" data-section="estado-prestamos">
        <h2 class="section-title">Estado de préstamos</h2>
        <?php echo tablaHtml(['Identificador', 'Deudor', 'Capital pendiente', 'Intereses pendientes'], $filasPrestamos, 'table', ['', '', 'text-right', 'text-right']); ?>
    </div>

    <div class="section" data-section="pago-intereses">
        <h2 class="section-title">Pago de intereses</h2>
        <?php echo tablaHtml(['Fecha', 'Concepto', 'Valor'], $filasIntereses, 'table', ['', '', 'text-right']); ?>
    </div>

    <div class="section" data-section="observaciones">
        <h2 class="section-title">Observaciones</h2>
        <?php echo $htmlMensajes ?: '<p class="nota">Sin observaciones registradas.</p>'; ?>
    </div>

    <p class="footer">Documento generado electrónicamente — no requiere firma física.</p>
    <?php
    $contenidoSocio = (string) ob_get_clean();

    return renderPlantillaPDF($contenidoSocio);
}

function validarHtmlPremium(string $html): void
{
    if (!preg_match('/data-role="logo"/', $html)) {
        exit('Validación fallida: el HTML no incluye el logo corporativo.');
    }

    $tieneTablaCorporativa = strpos($html, 'border-collapse: collapse') !== false
        && strpos($html, 'table {') !== false
        && strpos($html, 'tr:nth-child(even) td') !== false;

    if (!$tieneTablaCorporativa) {
        exit('Validación fallida: faltan los estilos corporativos de tablas.');
    }

    $seccionesObligatorias = [
        'Datos del socio',
        'Pagos de cuota',
        'Detalle de cuotas',
        'Pagos de pollas',
        'Estado de préstamos',
        'Pago de intereses',
        'Observaciones',
    ];

    foreach ($seccionesObligatorias as $seccion) {
        if (strpos($html, $seccion) === false) {
            exit('Validación fallida: falta la sección obligatoria: ' . $seccion . '.');
        }
    }
}

function cargarAutoloadDompdf(): void
{
    static $autoloadCargado = false;
    if ($autoloadCargado) {
        return;
    }

    $autoloadPaths = [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/vendor/autoload.php',
    ];

    foreach ($autoloadPaths as $autoload) {
        if (file_exists($autoload)) {
            require_once $autoload;
            $autoloadCargado = true;
            break;
        }
    }

    if (!$autoloadCargado) {
        exit('No se encontró vendor/autoload.php. Ejecute "composer install" en la raíz del proyecto.');
    }
}

function crearDompdf(): Dompdf
{
    cargarAutoloadDompdf();

    $opciones = new Options();
    $opciones->set('isRemoteEnabled', true);

    return new Dompdf($opciones);
}

function guardarPdfDesdeHtml(string $html, string $rutaDestino): void
{
    $dompdf = crearDompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4');
    $dompdf->render();

    $directorio = dirname($rutaDestino);
    if (!is_dir($directorio)) {
        mkdir($directorio, 0777, true);
    }

    file_put_contents($rutaDestino, $dompdf->output());
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
    $rutaPdf = __DIR__ . '/pdf_generados';

    if (isset($_GET['zip']) && $_GET['zip']) {
        exit('ZIP deshabilitado – sólo PDFs individuales permitidos');
    }

    if (!is_dir($rutaPdf)) {
        mkdir($rutaPdf, 0777, true);
    }

    limpiarCarpeta($rutaPdf);

    $socios = $pdo->query('SELECT id_socio, nombre_completo FROM socios ORDER BY nombre_completo ASC')->fetchAll();
    if (!$socios) {
        exit('No hay socios para exportar.');
    }

    foreach ($socios as $s) {
        try {
            $html = renderHtmlSocioIndividual((int)$s['id_socio'], $filtros, $pdo, $config, $resultadosPollaIndex, $logo, $mensajeUsuario);
            validarHtmlPremium($html);
            $nombreBase = nombreArchivoSocio(['id_socio' => $s['id_socio'], 'nombre_completo' => $s['nombre_completo']]);
            $rutaArchivo = $rutaPdf . '/' . $nombreBase . '.pdf';
            guardarPdfDesdeHtml($html, $rutaArchivo);
        } catch (Throwable $e) {
            continue;
        }
    }

    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Exportación masiva finalizada: archivos disponibles en /actions/pdf_generados/';
    exit;
}

if (!$idSocio) {
    exit('Debe seleccionar un socio para exportar el reporte.');
}

$html = renderHtmlSocioIndividual($idSocio, $filtros, $pdo, $config, $resultadosPollaIndex, $logo, $mensajeUsuario);
validarHtmlPremium($html);
$socio = obtenerSocio($idSocio, $pdo);
$nombre = nombreArchivoSocio($socio) . '_movimientos.pdf';
$dompdf = crearDompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4');
$dompdf->render();

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $nombre . '"');
echo $dompdf->output();
