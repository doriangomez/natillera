<?php
require_once __DIR__ . '/../includes/auth.php';

checkAdmin();

$archivoLog = dirname(__DIR__) . '/logs/app.log';
$nombreDescarga = 'app_log_' . date('Ymd_His') . '.log';

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nombreDescarga . '"');

if (!file_exists($archivoLog)) {
    echo "No se encontraron registros de log disponibles.";
    exit;
}

$handle = fopen($archivoLog, 'rb');
if ($handle === false) {
    echo "No fue posible abrir el archivo de logs.";
    exit;
}

while (!feof($handle)) {
    echo fread($handle, 8192);
}

fclose($handle);
exit;
