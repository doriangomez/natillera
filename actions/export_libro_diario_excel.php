<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/libro_diario_helpers.php';
require_once __DIR__ . '/../includes/xlsxwriter.class.php';
checkAdmin();
$idSocio = isset($_GET['socio_detalle']) ? (int) $_GET['socio_detalle'] : null;
$socio = null;
if ($idSocio) { $st=$pdo->prepare('SELECT * FROM socios WHERE id_socio=:id'); $st->execute([':id'=>$idSocio]); $socio=$st->fetch(); }
$filtros = ['desde'=>$_GET['desde']??'', 'hasta'=>$_GET['hasta']??'', 'socio'=>$_GET['socio']??'', 'actividad'=>$_GET['actividad']??'', 'medio'=>$_GET['medio']??'', 'prestamo'=>$_GET['prestamo']??'', 'neutrales'=>isset($_GET['neutrales'])?'1':''];
$rows = libroDiarioObtenerMovimientos($pdo, $filtros, $idSocio ?: null); $totales=libroDiarioAplicarSaldo($rows,(bool)$idSocio);
$writer = new XLSXWriter();
$header = $idSocio ? ['Fecha'=>'string','Concepto / actividad'=>'string','Prestamo'=>'string','Medio'=>'string','Ingreso'=>'price','Egreso'=>'price','Neutral'=>'price','Saldo acumulado'=>'price','Liquidacion'=>'string','Usuario'=>'string','Observaciones'=>'string'] : ['Fecha'=>'string','Socio / tercero'=>'string','Concepto / actividad'=>'string','Prestamo'=>'string','Medio'=>'string','Ingreso'=>'price','Egreso'=>'price','Neutral'=>'price','Saldo acumulado'=>'price','Liquidacion'=>'string','Usuario'=>'string','Observaciones'=>'string'];
$writer->writeSheetHeader('Libro diario', $header);
foreach ($rows as $r) {
    $base = [$r['fecha']];
    if (!$idSocio) { $base[] = $r['id_socio'] ? $r['nombre_completo'] : 'SIN SOCIO'; }
    $base[] = ($r['nombre_actividad'] ?? '') . (!empty($r['es_aval']) ? ' (aval)' : '');
    $base[] = $r['id_prestamo'] ? '#'.$r['id_prestamo'] : '';
    $base[] = $r['medio_pago_nombre'] ?: $r['medio_consignacion'];
    $base[] = ((int)$r['es_ingreso']===1 && empty($r['es_aval'])) ? $r['valor_abs'] : null;
    $base[] = ((int)$r['es_egreso']===1 && empty($r['es_aval'])) ? $r['valor_abs'] : null;
    $base[] = ((int)$r['es_ingreso']!==1 && (int)$r['es_egreso']!==1) ? $r['valor_abs'] : null;
    $base[] = $r['saldo_acumulado']; $base[] = $r['id_liquidacion'] ? '#'.$r['id_liquidacion'] : ''; $base[] = $r['usuario_registro']; $base[] = $r['observaciones'];
    $writer->writeSheetRow('Libro diario', $base);
}
$writer->writeSheetRow('Libro diario', []);
$writer->writeSheetRow('Libro diario', ['Totales', '', '', '', $totales['ingresos'], $totales['egresos'], $totales['neutral'], $totales['saldo_final']]);
$filename = ($idSocio ? 'auxiliar_socio_' . $idSocio : 'libro_diario') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$filename.'"');
$writer->writeToStdOut();
