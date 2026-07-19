<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/reconciliacion_efectivo_helpers.php';
require_once __DIR__ . '/../includes/xlsxwriter.class.php';
checkAdmin(); reconciliacionAsegurarEsquema($pdo);
$writer = new XLSXWriter();
$id = isset($_GET['corte']) ? (int)$_GET['corte'] : 0;
$cortes = $id ? array_filter([reconciliacionCorte($pdo,$id)]) : array_map(static fn($h)=>reconciliacionCorte($pdo,(int)$h['id']), reconciliacionHistorico($pdo));
$writer->writeSheetHeader('Historico', ['Fecha'=>'string','Saldo general'=>'price','Cartera vigente'=>'price','Efectivo esperado'=>'price','Total ubicado'=>'price','Diferencia'=>'price','Usuario'=>'string','Observaciones'=>'string']);
foreach($cortes as $c){ $writer->writeSheetRow('Historico', [$c['fecha_corte'],$c['saldo_general'],$c['cartera_vigente'],$c['efectivo_esperado'],$c['total_ubicado'],$c['diferencia'],$c['usuario_registro'],$c['observaciones']]); }
$writer->writeSheetHeader('Sitios de custodia', ['Corte'=>'integer','Fecha'=>'string','Sitio/persona'=>'string','Tipo'=>'string','Valor'=>'price','Observaciones'=>'string']);
$writer->writeSheetHeader('Cartera vigente', ['Corte'=>'integer','Fecha'=>'string','Prestamo'=>'integer','Deudor'=>'string','Estado'=>'string','Saldo capital'=>'price']);
foreach($cortes as $c){ foreach($c['items'] as $i){$writer->writeSheetRow('Sitios de custodia', [$c['id'],$c['fecha_corte'],$i['nombre_custodio'],$i['tipo_custodio'],$i['valor'],$i['observaciones']]);} foreach($c['cartera'] as $p){$writer->writeSheetRow('Cartera vigente', [$c['id'],$c['fecha_corte'],$p['id_prestamo'],$p['deudor'],$p['estado'],$p['saldo_capital_actual']]);} }
$filename = $id ? 'reconciliacion_efectivo_corte_'.$id.'.xlsx' : 'reconciliacion_efectivo_historico.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="'.$filename.'"'); $writer->writeToStdOut();
?>
