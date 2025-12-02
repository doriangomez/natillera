<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();

function obtenerSocio(int $idSocio, PDO $pdo): array
{
    $socioStmt = $pdo->prepare('SELECT id_socio, nombre_completo, telefono, numero_polla, periodicidad_pago, valor_presupuestado FROM socios WHERE id_socio = :id');
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

function obtenerLogoDataUri(array $config): ?string
{
    if (empty($config['logo_archivo'])) {
        return null;
    }

    $rutaLogo = __DIR__ . '/../public/assets/logo/' . basename($config['logo_archivo']);
    if (!is_readable($rutaLogo)) {
        return null;
    }

    $contenido = file_get_contents($rutaLogo);
    if ($contenido === false) {
        return null;
    }

    $mime = mime_content_type($rutaLogo) ?: 'image/png';
    return 'data:' . $mime . ';base64,' . base64_encode($contenido);
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
        $mesLabel = ($meses[$mesNum] ?? 'Mes') . ' ' . $anio . ($quincena ? ' - Q'.$quincena : '');

        $reglaSocio = normalizarReglaAfectacion($m['afecta_saldo_socio'] ?? 'neutral');
        $afectaSocio = $m['es_polla'] ? 'neutral' : $reglaSocio;
        $valorSocio = 0;
        if ($afectaSocio === 'suma') { $valorSocio = $m['valor']; }
        elseif ($afectaSocio === 'resta') { $valorSocio = -$m['valor']; }

        if ($afectaSocio !== 'neutral' && empty($m['es_polla']) && empty($m['es_prestamo']) && empty($m['es_pago_prestamo'])) {
            $cuotasPorMes[$mesLabel] = ($cuotasPorMes[$mesLabel] ?? 0) + $valorSocio;
        }
        if (!empty($m['es_polla'])) {
            $pollasPorMes[$mesLabel] = ($pollasPorMes[$mesLabel] ?? 0) + abs($m['valor']);
        }
    }

    ksort($cuotasPorMes);
    ksort($pollasPorMes);

    return [$cuotasPorMes, $pollasPorMes];
}

function escaparPdfTexto(string $texto): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texto);
}

function reservarObjeto(array &$objetos): int
{
    $objetos[] = null;
    return count($objetos);
}

function definirObjeto(array &$objetos, int $id, string $contenido): void
{
    $objetos[$id - 1] = $contenido;
}

function crearPdfSocio(array $socio, array $cuotasPorMes, array $pollasPorMes, array $prestamos, float $totalCapital, float $totalIntereses, array $config): string
{
    $ancho = 595; // A4 en puntos
    $alto = 842;
    $margen = 36;
    $y = $alto - $margen;
    $salto = 16;
    $paginas = [''];
    $paginaActual = 0;

    $agregarLinea = function(string $texto, int $tam = 12, bool $negrita = false) use (&$paginas, &$paginaActual, &$y, $salto, $margen, $alto) {
        $seccion = & $paginas[$paginaActual];
        if ($y - $salto < $margen) {
            $paginas[] = '';
            $paginaActual++;
            $y = $alto - $margen;
            $seccion = & $paginas[$paginaActual];
        }
        $fuente = $negrita ? 'F2' : 'F1';
        $seccion .= "BT /$fuente $tam Tf 1 0 0 1 $margen $y Tm (" . escaparPdfTexto($texto) . ") Tj ET\n";
        $y -= $salto;
    };

    $dibujarLinea = function() use (&$paginas, &$paginaActual, &$y, $ancho, $margen, $alto, $salto) {
        $seccion = & $paginas[$paginaActual];
        if ($y - ($salto / 2) < $margen) {
            $paginas[] = '';
            $paginaActual++;
            $y = $alto - $margen;
            $seccion = & $paginas[$paginaActual];
        }
        $y -= ($salto / 2);
        $seccion .= ($margen) . ' ' . $y . ' m ' . ($ancho - $margen) . ' ' . $y . " l S\n";
        $y -= ($salto / 2);
    };

    $agregarLinea(trim(($config['nombre_sistema'] ?? 'Natillera') . ' • Movimientos por socio'), 14, true);
    $agregarLinea('Generado: ' . date('Y-m-d H:i')); $dibujarLinea();

    $agregarLinea('Datos del socio', 13, true);
    $agregarLinea('Nombre: ' . $socio['nombre_completo']);
    $agregarLinea('Teléfono: ' . $socio['telefono']);
    $agregarLinea('Tipo de pago: ' . $socio['periodicidad_pago']);
    $agregarLinea('Valor cuota: $' . number_format((float)$socio['valor_presupuestado'], 0, ',', '.'));
    if (!empty($socio['numero_polla'])) {
        $agregarLinea('Número de polla: ' . $socio['numero_polla']);
    }
    $dibujarLinea();

    $agregarLinea('Pagos de cuota socio (por mes/quincena)', 13, true);
    if (!$cuotasPorMes) {
        $agregarLinea('No hay registros para las cuotas del socio.', 11);
    } else {
        foreach ($cuotasPorMes as $mes => $valor) {
            $agregarLinea($mes . ': $' . number_format($valor, 0, ',', '.'), 11);
        }
    }
    $dibujarLinea();

    $agregarLinea('Pagos de pollas por mes', 13, true);
    if (!$pollasPorMes) {
        $agregarLinea('No hay pagos de pollas registrados.', 11);
    } else {
        foreach ($pollasPorMes as $mes => $valor) {
            $agregarLinea($mes . ': $' . number_format($valor, 0, ',', '.'), 11);
        }
    }
    $dibujarLinea();

    $agregarLinea('Estado de préstamos', 13, true);
    if (!$prestamos) {
        $agregarLinea('No hay préstamos asociados.', 11);
    } else {
        foreach ($prestamos as $p) {
            $agregarLinea('Préstamo ' . (int)$p['id_prestamo'] . ' — ' . $p['nombre_deudor'] . ': $' . number_format($p['saldo_capital_actual'], 0, ',', '.'));
        }
        $agregarLinea('Total capital: $' . number_format($totalCapital, 0, ',', '.'));
    }
    $dibujarLinea();

    $agregarLinea('Intereses por préstamos', 13, true);
    if (!$prestamos) {
        $agregarLinea('No hay intereses pendientes.', 11);
    } else {
        foreach ($prestamos as $p) {
            $agregarLinea('Préstamo ' . (int)$p['id_prestamo'] . ' — ' . $p['nombre_deudor'] . ': $' . number_format($p['saldo_intereses_actual'], 0, ',', '.'));
        }
        $agregarLinea('Total intereses: $' . number_format($totalIntereses, 0, ',', '.'));
    }

    $objetos = [];
    $fuenteNormal = reservarObjeto($objetos);
    definirObjeto($objetos, $fuenteNormal, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
    $fuenteNegrita = reservarObjeto($objetos);
    definirObjeto($objetos, $fuenteNegrita, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>');

    $idPaginas = reservarObjeto($objetos);
    $contenidoIds = [];
    $paginasIds = [];

    foreach ($paginas as $contenido) {
        $contenido = trim($contenido) . "\n";
        $contenidoIds[] = reservarObjeto($objetos);
        definirObjeto($objetos, end($contenidoIds), "<< /Length " . strlen($contenido) . " >>\nstream\n$contenido endstream");

        $paginasIds[] = reservarObjeto($objetos);
        $pageObj = "<< /Type /Page /Parent $idPaginas 0 R /MediaBox [0 0 $ancho $alto] /Resources << /Font << /F1 $fuenteNormal 0 R /F2 $fuenteNegrita 0 R >> >> /Contents " . end($contenidoIds) . " 0 R >>";
        definirObjeto($objetos, end($paginasIds), $pageObj);
    }

    $kids = array_map(fn($id) => "$id 0 R", $paginasIds);
    definirObjeto($objetos, $idPaginas, '<< /Type /Pages /Count ' . count($paginasIds) . ' /Kids [' . implode(' ', $kids) . '] >>');

    $idCatalogo = reservarObjeto($objetos);
    definirObjeto($objetos, $idCatalogo, "<< /Type /Catalog /Pages $idPaginas 0 R >>");

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objetos as $index => $obj) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n" . $obj . "\nendobj\n";
    }

    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objetos) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    foreach ($offsets as $off) {
        $pdf .= sprintf("%010d 00000 n \n", $off);
    }
    $pdf .= "trailer\n<< /Size " . (count($objetos) + 1) . " /Root $idCatalogo 0 R >>\nstartxref\n" . $xref . "\n%%EOF";

    return $pdf;
}

function nombreArchivoSocio(array $socio): string
{
    $seguro = preg_replace('/[^A-Za-z0-9_\-]/', '_', $socio['nombre_completo']);
    $seguro = trim($seguro, '_');
    return $seguro !== '' ? $seguro : ('socio_' . (int)$socio['id_socio']);
}

$modo = $_GET['modo'] ?? 'individual';
$idSocio = isset($_GET['socio']) ? (int) $_GET['socio'] : 0;
$desde = $_GET['desde'] ?? '';
$hasta = $_GET['hasta'] ?? '';
$actividad = isset($_GET['actividad']) ? (int) $_GET['actividad'] : 0;

$filtros = [
    'socio' => $idSocio,
    'desde' => $desde,
    'hasta' => $hasta,
    'actividad' => $actividad,
];

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

    $config = getConfiguracionGeneral($pdo);
    foreach ($socios as $s) {
        try {
            $socioDetalle = obtenerSocio((int)$s['id_socio'], $pdo);
            $filtros['socio'] = (int)$s['id_socio'];
            $movs = obtenerMovimientos($pdo, $filtros);
            [$prestamos, $totalCapital, $totalIntereses] = obtenerPrestamos($pdo, (int)$s['id_socio']);
            [$cuotasPorMes, $pollasPorMes] = agruparMovimientos($movs);
            $pdf = crearPdfSocio($socioDetalle, $cuotasPorMes, $pollasPorMes, $prestamos, $totalCapital, $totalIntereses, $config);
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
$config = getConfiguracionGeneral($pdo);

[$cuotasPorMes, $pollasPorMes] = agruparMovimientos($movimientos);
$pdf = crearPdfSocio($socio, $cuotasPorMes, $pollasPorMes, $prestamos, $totalCapital, $totalIntereses, $config);
$nombre = nombreArchivoSocio($socio) . '_movimientos.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
