<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();
asegurarEsquemaBolsaAdministracion($pdo);

$accion = trim((string) ($_POST['accion'] ?? ''));
$idBolsa = isset($_POST['id_bolsa']) ? (int) $_POST['id_bolsa'] : 0;
$redirect = '../public/bolsa_administracion.php';

if ($accion !== 'eliminar' || $idBolsa <= 0) {
    $_SESSION['error'] = 'Registro de bolsa inválido.';
    header('Location: ' . $redirect);
    exit;
}

try {
    $stmt = $pdo->prepare('DELETE FROM bolsa_administracion WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $idBolsa]);

    $_SESSION['exito'] = $stmt->rowCount() > 0
        ? 'Registro de bolsa de administración eliminado correctamente.'
        : 'El registro de bolsa de administración ya no existe.';
} catch (Throwable $e) {
    $_SESSION['error'] = 'No se pudo eliminar el registro de bolsa: ' . $e->getMessage();
}

header('Location: ' . $redirect);
exit;
