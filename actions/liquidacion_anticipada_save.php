<?php
$_POST['accion'] = 'crear';
$_POST['tipo_liquidacion'] = 'anticipada';
$_POST['id_actividad_liquidacion'] = $_POST['id_actividad_devolucion'] ?? ($_POST['id_actividad_liquidacion'] ?? null);
require __DIR__ . '/liquidaciones_save.php';
