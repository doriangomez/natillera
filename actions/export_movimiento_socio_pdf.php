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

function construirHtmlSocio(array $socio, array $movimientos, array $prestamos, float $totalCapital, float $totalIntereses): string
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

    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Movimientos socio <?php echo clean($socio['nombre_completo']); ?></title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
        <style>
            body { padding: 32px; }
            h1, h2 { color: #0f172a; }
            .section-title { border-bottom: 1px solid #e5e7eb; padding-bottom: 6px; margin-bottom: 12px; }
            .summary-table td { padding: 6px 10px; }
        </style>
    </head>
    <body>
        <h1 class="mb-1">Movimientos por socio</h1>
        <p class="text-muted">Generado: <?php echo date('Y-m-d H:i'); ?></p>

        <h2 class="h5 section-title">Datos del socio</h2>
        <table class="table table-bordered w-75 summary-table">
            <tbody>
                <tr><th>Nombre</th><td><?php echo clean($socio['nombre_completo']); ?></td></tr>
                <tr><th>Teléfono</th><td><?php echo clean($socio['telefono']); ?></td></tr>
                <tr><th>Tipo de pago</th><td><?php echo ucfirst(clean($socio['periodicidad_pago'])); ?></td></tr>
                <tr><th>Valor</th><td>$<?php echo number_format((float)$socio['valor_presupuestado'],0,',','.'); ?></td></tr>
                <tr><th>Número de polla</th><td><?php echo clean($socio['numero_polla']); ?></td></tr>
            </tbody>
        </table>

        <h2 class="h5 section-title">Pagos de cuota socio (agrupado por mes/quincena)</h2>
        <table class="table table-sm table-striped">
            <thead><tr><th>Mes</th><th class="text-end">Valor</th></tr></thead>
            <tbody>
                <?php if (!$cuotasPorMes): ?>
                    <tr><td colspan="2" class="text-muted">No hay registros para las cuotas del socio.</td></tr>
                <?php else: ?>
                    <?php foreach($cuotasPorMes as $mes => $valor): ?>
                        <tr>
                            <td><?php echo clean($mes); ?></td>
                            <td class="text-end">$<?php echo number_format($valor,0,',','.'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <h2 class="h5 section-title">Pagos de pollas por mes</h2>
        <table class="table table-sm table-striped">
            <thead><tr><th>Mes</th><th class="text-end">Valor</th></tr></thead>
            <tbody>
                <?php if (!$pollasPorMes): ?>
                    <tr><td colspan="2" class="text-muted">No hay pagos de pollas registrados.</td></tr>
                <?php else: ?>
                    <?php foreach($pollasPorMes as $mes => $valor): ?>
                        <tr>
                            <td><?php echo clean($mes); ?></td>
                            <td class="text-end">$<?php echo number_format($valor,0,',','.'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <h2 class="h5 section-title">Estado de préstamos</h2>
        <table class="table table-sm table-bordered">
            <thead><tr><th>ID</th><th>Deudor</th><th class="text-end">Saldo capital</th></tr></thead>
            <tbody>
                <?php if (!$prestamos): ?>
                    <tr><td colspan="3" class="text-muted">No hay préstamos asociados.</td></tr>
                <?php else: ?>
                    <?php foreach($prestamos as $p): ?>
                        <tr>
                            <td><?php echo (int)$p['id_prestamo']; ?></td>
                            <td><?php echo clean($p['nombre_deudor']); ?></td>
                            <td class="text-end">$<?php echo number_format($p['saldo_capital_actual'],0,',','.'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="fw-semibold">
                        <td colspan="2">Total</td>
                        <td class="text-end">$<?php echo number_format($totalCapital,0,',','.'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <h2 class="h5 section-title">Intereses por préstamos</h2>
        <table class="table table-sm table-bordered">
            <thead><tr><th>ID</th><th>Deudor</th><th class="text-end">Saldo intereses</th></tr></thead>
            <tbody>
                <?php if (!$prestamos): ?>
                    <tr><td colspan="3" class="text-muted">No hay intereses pendientes.</td></tr>
                <?php else: ?>
                    <?php foreach($prestamos as $p): ?>
                        <tr>
                            <td><?php echo (int)$p['id_prestamo']; ?></td>
                            <td><?php echo clean($p['nombre_deudor']); ?></td>
                            <td class="text-end">$<?php echo number_format($p['saldo_intereses_actual'],0,',','.'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="fw-semibold">
                        <td colspan="2">Total intereses</td>
                        <td class="text-end">$<?php echo number_format($totalIntereses,0,',','.'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <script>window.print();</script>
    </body>
    </html>
    <?php
    return ob_get_clean();
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

    foreach ($socios as $s) {
        try {
            $socioDetalle = obtenerSocio((int)$s['id_socio'], $pdo);
            $filtros['socio'] = (int)$s['id_socio'];
            $movs = obtenerMovimientos($pdo, $filtros);
            [$prestamos, $totalCapital, $totalIntereses] = obtenerPrestamos($pdo, (int)$s['id_socio']);
            $html = construirHtmlSocio($socioDetalle, $movs, $prestamos, $totalCapital, $totalIntereses);
            $nombreSeguro = preg_replace('/[^A-Za-z0-9_\-]/', '_', $socioDetalle['nombre_completo']);
            $nombreArchivo = ($rutaCarpeta ? $rutaCarpeta.'/' : '').'movimientos_'.$nombreSeguro.'.html';
            $zip->addFromString($nombreArchivo, $html);
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

header('Content-Type: text/html; charset=utf-8');
echo construirHtmlSocio($socio, $movimientos, $prestamos, $totalCapital, $totalIntereses);
