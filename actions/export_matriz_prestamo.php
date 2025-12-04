<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();

$idPrestamo = isset($_GET['id_prestamo']) ? (int) $_GET['id_prestamo'] : 0;
$formato = ($_GET['formato'] ?? 'excel') === 'pdf' ? 'pdf' : 'excel';

$stmt = $pdo->prepare('SELECT p.*, s.nombre_completo, aval.nombre_completo AS nombre_aval FROM prestamos p LEFT JOIN socios s ON p.id_socio = s.id_socio LEFT JOIN socios aval ON p.id_socio_aval = aval.id_socio WHERE p.id_prestamo = :id');
$stmt->execute([':id' => $idPrestamo]);
$prestamo = $stmt->fetch();

if (!$prestamo) {
    $_SESSION['error'] = 'No se encontró el préstamo solicitado para exportar.';
    header('Location: ../public/prestamos_matriz.php');
    exit;
}

$config = getConfiguracionGeneral($pdo);
$matriz = construirMatrizMovimientosPrestamo($pdo, $prestamo);

$nombreArchivo = 'matriz_prestamo_' . $idPrestamo . '_' . date('Ymd') . ($formato === 'pdf' ? '.html' : '.xls');

if ($formato === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename=' . $nombreArchivo);
} else {
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: attachment; filename=' . $nombreArchivo);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Matriz préstamo <?php echo $idPrestamo; ?></title>
    <style>
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 6px; }
        th { background: #f5f5f5; }
        .text-end { text-align: right; }
    </style>
</head>
<body>
    <h3>Matriz mensual de préstamo #<?php echo $idPrestamo; ?></h3>
    <div>Socio / Deudor: <strong><?php echo $prestamo['es_particular'] ? clean($prestamo['nombre_deudor']) : clean($prestamo['nombre_completo']); ?></strong></div>
    <?php if ($prestamo['es_particular']): ?>
        <div>Aval: <strong><?php echo clean($prestamo['nombre_aval'] ?? 'N/A'); ?></strong></div>
    <?php endif; ?>
    <div>Fecha préstamo: <strong><?php echo clean($prestamo['fecha_prestamo']); ?></strong> | Tasa: <strong><?php echo clean($prestamo['tasa_interes']); ?>%</strong></div>
    <div>Totales - Capital: <strong><?php echo number_format($matriz['totales']['capital'] ?? 0, 2, ',', '.'); ?></strong> | Intereses: <strong><?php echo number_format($matriz['totales']['intereses'] ?? 0, 2, ',', '.'); ?></strong></div>
    <table>
        <thead>
            <tr>
                <th>Concepto</th>
                <?php foreach ($matriz['periodos'] as $periodo): ?>
                    <th><?php echo clean($periodo['label']); ?></th>
                <?php endforeach; ?>
                <th>Saldo</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($matriz['filas'] as $fila): ?>
                <tr>
                    <td><?php echo clean($fila['actividad']['nombre_actividad'] ?? 'Concepto'); ?></td>
                    <?php foreach ($matriz['periodos'] as $periodo): $clave = sprintf('%04d-%02d', $periodo['anio'], $periodo['mes']); $celda = $fila['meses'][$clave] ?? ['valor' => 0, 'estado' => '']; ?>
                        <td class="text-end"><?php echo number_format((float) $celda['valor'], 2, ',', '.'); ?> (<?php echo $celda['estado']; ?>)</td>
                    <?php endforeach; ?>
                    <td class="text-end"><?php echo number_format((float) ($fila['saldo'] ?? 0), 2, ',', '.'); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($formato === 'pdf'): ?>
        <p style="margin-top: 12px; color: #666;">Guarda este archivo como PDF desde tu navegador para compartir o imprimir.</p>
    <?php endif; ?>
</body>
</html>
