<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Solo los administradores pueden actualizar el reglamento
checkAdmin();

$configActual = getConfiguracionGeneral($pdo);
$reglamentoActual = $configActual['reglamento_archivo'] ?? null;

if (!isset($_FILES['reglamento_pdf']) || !is_uploaded_file($_FILES['reglamento_pdf']['tmp_name'])) {
    $_SESSION['error'] = 'Debes seleccionar un archivo PDF para cargar.';
    header('Location: ../public/reglamento.php');
    exit;
}

if ($_FILES['reglamento_pdf']['type'] !== 'application/pdf') {
    $_SESSION['error'] = 'Solo se permiten archivos PDF para el reglamento general.';
    header('Location: ../public/reglamento.php');
    exit;
}

$destinoDir = __DIR__ . '/../public/assets/reglamento';
if (!is_dir($destinoDir)) {
    mkdir($destinoDir, 0775, true);
}

$nombreArchivo = 'reglamento_' . time() . '.pdf';
$rutaDestino = $destinoDir . '/' . $nombreArchivo;

if (!move_uploaded_file($_FILES['reglamento_pdf']['tmp_name'], $rutaDestino)) {
    $_SESSION['error'] = 'No fue posible guardar el archivo. Intenta nuevamente.';
    header('Location: ../public/reglamento.php');
    exit;
}

// Eliminar archivo anterior si existe
if ($reglamentoActual) {
    $archivoAnterior = $destinoDir . '/' . basename($reglamentoActual);
    if (is_file($archivoAnterior)) {
        @unlink($archivoAnterior);
    }
}

$stmt = $pdo->prepare('UPDATE configuracion_general SET reglamento_archivo = :archivo WHERE id_config = 1');
$stmt->execute([':archivo' => $nombreArchivo]);

$_SESSION['success'] = 'Reglamento actualizado correctamente.';
header('Location: ../public/reglamento.php');
exit;
?>
