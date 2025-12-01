<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Todos los usuarios autenticados pueden consultar el reglamento
checkAuth();

$config = getConfiguracionGeneral($pdo);
$archivo = $config['reglamento_archivo'] ?? null;

if (!$archivo) {
    $_SESSION['error'] = 'No hay un reglamento cargado para descargar.';
    header('Location: ../public/configuracion.php');
    exit;
}

$ruta = __DIR__ . '/../public/assets/reglamento/' . basename($archivo);

if (!is_file($ruta)) {
    $_SESSION['error'] = 'El archivo del reglamento no está disponible en el servidor.';
    header('Location: ../public/configuracion.php');
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . basename($archivo) . '"');
header('Content-Length: ' . filesize($ruta));
readfile($ruta);
exit;
?>
