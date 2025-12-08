<?php
require_once __DIR__ . '/../includes/auth.php';

checkAdmin();

$directorioLogs = dirname(__DIR__) . '/logs';
$archivoLog = $directorioLogs . '/app.log';
$nombreDescarga = 'app_log_' . date('Ymd_His') . '.log';
$fechaLimite = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-3 days');

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

$lineasFiltradas = [];
while (($linea = fgets($handle)) !== false) {
    if (!preg_match('/^\[(?<fecha>[^\]]+)\]/', $linea, $coincidencia)) {
        continue;
    }

    $fecha = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u T', $coincidencia['fecha']);
    if ($fecha === false || $fecha < $fechaLimite) {
        continue;
    }

    $lineasFiltradas[] = $linea;
}

fclose($handle);

echo "Ruta de almacenamiento: {$archivoLog}\n";
echo "Mostrando entradas desde: " . $fechaLimite->format('Y-m-d H:i:s T') . "\n\n";

if (empty($lineasFiltradas)) {
    echo "No hay registros en los últimos 3 días.";
    exit;
}

echo implode('', $lineasFiltradas);

exit;
