<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/reconciliacion_efectivo_helpers.php';
checkAdmin(); reconciliacionAsegurarEsquema($pdo);
$id=(int)($_POST['id']??0); $nombre=trim($_POST['nombre']??''); $tipo=$_POST['tipo']??'otro'; $activo=isset($_POST['activo'])?(int)$_POST['activo']:1; $obs=trim($_POST['observaciones']??'');
if($nombre!=='') { if($id>0){$st=$pdo->prepare('UPDATE reconciliacion_custodios SET nombre=:n,tipo=:t,activo=:a,observaciones=:o WHERE id=:id');$st->execute([':n'=>$nombre,':t'=>$tipo,':a'=>$activo,':o'=>$obs,':id'=>$id]);} else {$st=$pdo->prepare('INSERT INTO reconciliacion_custodios (nombre,tipo,activo,observaciones) VALUES (:n,:t,:a,:o)');$st->execute([':n'=>$nombre,':t'=>$tipo,':a'=>$activo,':o'=>$obs]);}}
header('Location: ../public/reconciliacion_efectivo.php?custodio=1'); exit;
?>
