<?php
require_once __DIR__ . '/../includes/auth.php';
checkAdmin();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auditoria_integridad_helpers.php';
require_once __DIR__ . '/../includes/xlsxwriter.class.php';

$filtros = auditoriaFiltrosDesdeRequest($_GET);
$datos = obtenerDatosAuditoriaIntegridad($pdo, $filtros);

$writer = new XLSXWriter();
$okStyle = ['fill' => 'D1E7DD'];
$errorStyle = ['fill' => 'F8D7DA'];

function auditoriaEscribirCabeceraXlsx(XLSXWriter $writer, string $hoja, array $cabecera, array $anchos = []): void {
    $writer->writeSheetHeader($hoja, array_fill_keys($cabecera, 'string'), ['font-style' => 'bold', 'fill' => 'E2E3E5', 'widths' => $anchos]);
}

function auditoriaEstilosFila(int $columnas, array $columnasEstiladas, array $estilo): array {
    $estilos = array_fill(0, $columnas, []);
    foreach ($columnasEstiladas as $columna) {
        $estilos[$columna] = $estilo;
    }
    return $estilos;
}

auditoriaEscribirCabeceraXlsx($writer, 'Saldos socios', ['Socio', 'Saldo guardado', 'Saldo recalculado', 'Diferencia', 'Último movimiento', 'Estado'], [28, 18, 20, 14, 20, 14]);
foreach ($datos['socios'] as $socio) {
    $dif = (float) $socio['diferencia'];
    $correcto = abs($dif) <= 0.009;
    $writer->writeSheetRow('Saldos socios', [
        $socio['nombre_completo'],
        (float) $socio['saldo_guardado'],
        (float) $socio['saldo_recalculado'],
        $dif,
        $socio['ultimo_movimiento'] ?: 'Sin movimientos',
        $correcto ? 'OK' : 'DIFERENCIA',
    ], auditoriaEstilosFila(6, [3, 4, 5], $correcto ? $okStyle : $errorStyle));
}

auditoriaEscribirCabeceraXlsx($writer, 'Saldo natillera', ['Saldo guardado', 'Saldo recalculado', 'Diferencia', 'Estado'], [18, 20, 14, 14]);
$writer->writeSheetRow('Saldo natillera', [
    (float) $datos['natillera']['guardado'],
    (float) $datos['natillera']['recalculado'],
    (float) $datos['natillera']['diferencia'],
    $datos['natillera']['correcto'] ? 'OK' : 'DIFERENCIA',
], auditoriaEstilosFila(4, [2, 3], $datos['natillera']['correcto'] ? $okStyle : $errorStyle));

auditoriaEscribirCabeceraXlsx($writer, 'Resumen actividad', ['Actividad', 'Impacto natillera', 'Movimientos', 'Valor total', 'Impacto neto'], [30, 18, 14, 16, 16]);
foreach ($datos['actividades'] as $actividad) {
    $writer->writeSheetRow('Resumen actividad', [
        $actividad['nombre_actividad'],
        $actividad['afecta_saldo_natillera'],
        (int) $actividad['cantidad_movimientos'],
        (float) $actividad['valor_total'],
        (float) $actividad['impacto_neto'],
    ]);
}
$writer->writeSheetRow('Resumen actividad', [
    'Totales',
    '',
    (int) $datos['totales_actividad']['cantidad_movimientos'],
    (float) $datos['totales_actividad']['valor_total'],
    (float) $datos['totales_actividad']['impacto_neto'],
], array_fill(0, 5, ['font-style' => 'bold']));

auditoriaEscribirCabeceraXlsx($writer, 'Alertas', ['Tipo', 'Detalle', 'Monto'], [28, 70, 16]);
if (empty($datos['alertas'])) {
    $writer->writeSheetRow('Alertas', ['Sin alertas ✓', '', ''], array_fill(0, 3, $okStyle));
} else {
    foreach ($datos['alertas'] as $alerta) {
        $writer->writeSheetRow('Alertas', [$alerta['tipo'], $alerta['detalle'], $alerta['monto']], array_fill(0, 3, $errorStyle));
    }
}

auditoriaEscribirCabeceraXlsx($writer, 'Movimientos huerfanos', ['ID', 'Fecha', 'Socio', 'ID actividad faltante', 'Motivo', 'Módulo', 'Valor'], [10, 14, 28, 20, 34, 16, 16]);
if (empty($datos['huerfanos'])) {
    $writer->writeSheetRow('Movimientos huerfanos', ['Sin movimientos huérfanos ✓', '', '', '', '', '', ''], array_fill(0, 7, $okStyle));
} else {
    foreach ($datos['huerfanos'] as $mov) {
        $writer->writeSheetRow('Movimientos huerfanos', [
            (int) $mov['id_movimiento'],
            $mov['fecha'],
            $mov['nombre_completo'] ?: 'Sin socio',
            (int) $mov['id_actividad'],
            $mov['motivo'],
            $mov['modulo'],
            (float) $mov['valor'],
        ], array_fill(0, 7, $errorStyle));
    }
}

$filename = 'auditoria_' . date('Y-m-d') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
$writer->writeToStdOut($filename);
exit;
