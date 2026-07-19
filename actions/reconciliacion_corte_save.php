<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/reconciliacion_efectivo_helpers.php';
checkAdmin(); reconciliacionAsegurarEsquema($pdo);
$fecha=$_POST['fecha_corte']??date('Y-m-d'); $obs=trim($_POST['observaciones']??''); $valores=$_POST['valor']??[]; $obsItems=$_POST['observacion_item']??[];
$calculo=reconciliacionCalculoActual($pdo); $custodios=reconciliacionCustodios($pdo,true); $total=0; foreach($custodios as $c){$total+=(float)str_replace([',',' '],['',''], $valores[$c['id']]??0);} $dif=$calculo['efectivo_esperado']-$total;
$pdo->beginTransaction();
try{
$st=$pdo->prepare('INSERT INTO reconciliacion_cortes (fecha_corte,saldo_general,cartera_vigente,efectivo_esperado,total_ubicado,diferencia,usuario_registro,observaciones) VALUES (:f,:s,:car,:e,:t,:d,:u,:o)');
$st->execute([':f'=>$fecha,':s'=>$calculo['saldo_general'],':car'=>$calculo['cartera_vigente'],':e'=>$calculo['efectivo_esperado'],':t'=>$total,':d'=>$dif,':u'=>$_SESSION['usuario']??null,':o'=>$obs]); $id=(int)$pdo->lastInsertId();
$it=$pdo->prepare('INSERT INTO reconciliacion_corte_items (id_corte,id_custodio,nombre_custodio,tipo_custodio,valor,observaciones) VALUES (:c,:idc,:n,:tp,:v,:o)'); foreach($custodios as $c){$v=(float)str_replace([',',' '],['',''],$valores[$c['id']]??0);$it->execute([':c'=>$id,':idc'=>$c['id'],':n'=>$c['nombre'],':tp'=>$c['tipo'],':v'=>$v,':o'=>trim($obsItems[$c['id']]??'')]);}
$ca=$pdo->prepare('INSERT INTO reconciliacion_cartera_detalle (id_corte,id_prestamo,deudor,estado,saldo_capital_actual) VALUES (:c,:p,:d,:e,:s)'); foreach($calculo['cartera_detalle'] as $p){$ca->execute([':c'=>$id,':p'=>$p['id_prestamo'],':d'=>$p['deudor'],':e'=>$p['estado'],':s'=>$p['saldo_capital_actual']]);}
$pdo->commit(); header('Location: ../public/reconciliacion_efectivo.php?corte='.$id.'&guardado=1'); exit;
}catch(Throwable $e){$pdo->rollBack(); throw $e;}
?>
