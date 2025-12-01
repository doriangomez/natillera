<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAdmin();

$nombre = trim($_POST['nombre_sistema'] ?? 'Aplicativo de Natillera creado por Dorian Gómez');
$datos = trim($_POST['datos_globales'] ?? '');
$configActual = getConfiguracionGeneral($pdo);
$logoActual = $configActual['logo_archivo'] ?? null;
$nuevoLogo = $logoActual;

if (isset($_FILES['logo']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
    $permitidos = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (in_array($_FILES['logo']['type'], $permitidos)) {
        $destinoDir = __DIR__ . '/../public/assets/logo';
        if (!is_dir($destinoDir)) {
            mkdir($destinoDir, 0775, true);
        }
        $extension = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
        $nombreArchivo = 'logo_' . time() . '.' . $extension;
        $rutaDestino = $destinoDir . '/' . $nombreArchivo;
        if (move_uploaded_file($_FILES['logo']['tmp_name'], $rutaDestino)) {
            $nuevoLogo = $nombreArchivo;
        }
    }
}

$stmt = $pdo->prepare("INSERT INTO configuracion_general (id_config, nombre_sistema, logo_archivo, datos_globales) VALUES (1, :nombre, :logo, :datos)
    ON DUPLICATE KEY UPDATE nombre_sistema = VALUES(nombre_sistema), logo_archivo = VALUES(logo_archivo), datos_globales = VALUES(datos_globales)");
$stmt->execute([
    ':nombre' => $nombre,
    ':logo' => $nuevoLogo,
    ':datos' => $datos,
]);

header('Location: ../public/configuracion.php?guardado=1');
?>
