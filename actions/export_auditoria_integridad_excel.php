<?php
require_once __DIR__ . '/../includes/auth.php';
checkAdmin();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auditoria_integridad_helpers.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    exit('No se encontró vendor/autoload.php. Ejecute "composer install".');
}
require_once $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$filtros = auditoriaFiltrosDesdeRequest($_GET);
$datos = obtenerDatosAuditoriaIntegridad($pdo, $filtros);
$spreadsheet = new Spreadsheet();
$okFill = ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D1E7DD']];
$errorFill = ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8D7DA']];

$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Saldos socios');
$sheet->fromArray(['Socio', 'Saldo guardado', 'Saldo recalculado', 'Diferencia', 'Último movimiento', 'Estado'], null, 'A1');
$row = 2;
foreach ($datos['socios'] as $socio) {
    $dif = (float) $socio['diferencia'];
    $sheet->fromArray([$socio['nombre_completo'], (float) $socio['saldo_guardado'], (float) $socio['saldo_recalculado'], $dif, $socio['ultimo_movimiento'] ?: 'Sin movimientos', abs($dif) <= 0.009 ? 'OK' : 'DIFERENCIA'], null, 'A' . $row);
    $sheet->getStyle('D' . $row . ':F' . $row)->getFill()->applyFromArray(abs($dif) <= 0.009 ? $okFill : $errorFill);
    $row++;
}

$sheet = $spreadsheet->createSheet();
$sheet->setTitle('Saldo natillera');
$sheet->fromArray(['Saldo guardado', 'Saldo recalculado', 'Diferencia', 'Estado'], null, 'A1');
$sheet->fromArray([(float) $datos['natillera']['guardado'], (float) $datos['natillera']['recalculado'], (float) $datos['natillera']['diferencia'], $datos['natillera']['correcto'] ? 'OK' : 'DIFERENCIA'], null, 'A2');
$sheet->getStyle('C2:D2')->getFill()->applyFromArray($datos['natillera']['correcto'] ? $okFill : $errorFill);

$sheet = $spreadsheet->createSheet();
$sheet->setTitle('Resumen actividad');
$sheet->fromArray(['Actividad', 'Impacto natillera', 'Movimientos', 'Valor total', 'Impacto neto'], null, 'A1');
$row = 2;
foreach ($datos['actividades'] as $actividad) {
    $sheet->fromArray([$actividad['nombre_actividad'], $actividad['afecta_saldo_natillera'], (int) $actividad['cantidad_movimientos'], (float) $actividad['valor_total'], (float) $actividad['impacto_neto']], null, 'A' . $row);
    $row++;
}
$sheet->fromArray(['Totales', '', (int) $datos['totales_actividad']['cantidad_movimientos'], (float) $datos['totales_actividad']['valor_total'], (float) $datos['totales_actividad']['impacto_neto']], null, 'A' . $row);

$sheet = $spreadsheet->createSheet();
$sheet->setTitle('Alertas');
$sheet->fromArray(['Tipo', 'Detalle', 'Monto'], null, 'A1');
$row = 2;
if (empty($datos['alertas'])) {
    $sheet->fromArray(['Sin alertas ✓', '', ''], null, 'A2');
    $sheet->getStyle('A2:C2')->getFill()->applyFromArray($okFill);
} else {
    foreach ($datos['alertas'] as $alerta) {
        $sheet->fromArray([$alerta['tipo'], $alerta['detalle'], $alerta['monto']], null, 'A' . $row);
        $sheet->getStyle('A' . $row . ':C' . $row)->getFill()->applyFromArray($errorFill);
        $row++;
    }
}

$sheet = $spreadsheet->createSheet();
$sheet->setTitle('Movimientos huerfanos');
$sheet->fromArray(['ID', 'Fecha', 'Socio', 'ID actividad faltante', 'Motivo', 'Módulo', 'Valor'], null, 'A1');
$row = 2;
if (empty($datos['huerfanos'])) {
    $sheet->fromArray(['Sin movimientos huérfanos ✓', '', '', '', '', '', ''], null, 'A2');
    $sheet->getStyle('A2:G2')->getFill()->applyFromArray($okFill);
} else {
    foreach ($datos['huerfanos'] as $mov) {
        $sheet->fromArray([(int) $mov['id_movimiento'], $mov['fecha'], $mov['nombre_completo'] ?: 'Sin socio', (int) $mov['id_actividad'], $mov['motivo'], $mov['modulo'], (float) $mov['valor']], null, 'A' . $row);
        $row++;
    }
}

foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
    foreach (range('A', $worksheet->getHighestColumn()) as $column) {
        $worksheet->getColumnDimension($column)->setAutoSize(true);
    }
    $worksheet->getStyle('1:1')->getFont()->setBold(true);
}

$filename = 'auditoria_' . date('Y-m-d') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
(new Xlsx($spreadsheet))->save('php://output');
exit;
