<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAdmin();

$configActual = getConfiguracionGeneral($pdo);
$nombre = trim($_POST['nombre_sistema'] ?? ($configActual['nombre_sistema'] ?? 'Aplicativo de Natillera creado por Dorian Gómez'));
$datos = trim($_POST['datos_globales'] ?? ($configActual['datos_globales'] ?? ''));
$logoActual = $configActual['logo_archivo'] ?? null;
$nuevoLogo = $logoActual;
$reglamentoActual = $configActual['reglamento_archivo'] ?? null;
$nuevoReglamento = $reglamentoActual;

// Asegurar columna de reglamento
try {
    $pdo->query("SHOW COLUMNS FROM configuracion_general LIKE 'reglamento_archivo'");
    if ($pdo->query("SHOW COLUMNS FROM configuracion_general LIKE 'reglamento_archivo'")->rowCount() === 0) {
        $pdo->exec("ALTER TABLE configuracion_general ADD COLUMN reglamento_archivo VARCHAR(255) NULL");
    }
} catch (Exception $e) {
    // Continuar silenciosamente si no es posible crear la columna
}

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

if (isset($_FILES['reglamento_pdf']) && is_uploaded_file($_FILES['reglamento_pdf']['tmp_name'])) {
    if ($_FILES['reglamento_pdf']['type'] === 'application/pdf') {
        $destinoDir = __DIR__ . '/../public/assets/reglamento';
        if (!is_dir($destinoDir)) {
            mkdir($destinoDir, 0775, true);
        }
        $nombreArchivo = 'reglamento_' . time() . '.pdf';
        $rutaDestino = $destinoDir . '/' . $nombreArchivo;
        if (move_uploaded_file($_FILES['reglamento_pdf']['tmp_name'], $rutaDestino)) {
            $nuevoReglamento = $nombreArchivo;
        }
    } else {
        $_SESSION['error'] = 'Solo se permiten archivos PDF para el reglamento general.';
        header('Location: ../public/configuracion.php');
        exit;
    }
}

$stmt = $pdo->prepare("INSERT INTO configuracion_general (id_config, nombre_sistema, logo_archivo, datos_globales) VALUES (1, :nombre, :logo, :datos)
    ON DUPLICATE KEY UPDATE nombre_sistema = VALUES(nombre_sistema), logo_archivo = VALUES(logo_archivo), datos_globales = VALUES(datos_globales)");
$stmt->execute([
    ':nombre' => $nombre,
    ':logo' => $nuevoLogo,
    ':datos' => $datos,
]);

if ($nuevoReglamento !== $reglamentoActual) {
    try {
        $pdo->prepare('UPDATE configuracion_general SET reglamento_archivo = :file WHERE id_config = 1')->execute([':file' => $nuevoReglamento]);
    } catch (Exception $e) {
        // Ignorar si la columna no existe o no es posible escribir
    }
}

$_SESSION['success'] = 'Configuración actualizada correctamente.';

header('Location: ../public/configuracion.php?guardado=1');
?>
