<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

checkAuth();

$action = $_POST['action'] ?? '';
$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$redirectBase = $_POST['redirect'] ?? '../public/conciliaciones.php';

if ($id <= 0 || !in_array($action, ['delete', 'reopen'], true)) {
    $_SESSION['error'] = 'Solicitud inválida para gestionar conciliaciones.';
    header('Location: ' . $redirectBase);
    exit;
}

$stmt = $pdo->prepare('SELECT anio, mes FROM conciliaciones_medios_pago WHERE id = :id');
$stmt->execute([':id' => $id]);
$conciliacion = $stmt->fetch();

if (!$conciliacion) {
    $_SESSION['error'] = 'No se encontró la conciliación solicitada.';
    header('Location: ' . $redirectBase);
    exit;
}

$redirect = $redirectBase . '?anio=' . (int) $conciliacion['anio'] . '&mes=' . (int) $conciliacion['mes'];

try {
    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM conciliaciones_medios_pago WHERE id = :id')->execute([':id' => $id]);
        $_SESSION['success'] = 'Conciliación eliminada correctamente.';
    } elseif ($action === 'reopen') {
        $pdo->prepare('UPDATE conciliaciones_medios_pago SET cerrado = 0 WHERE anio = :anio AND mes = :mes')
            ->execute([':anio' => $conciliacion['anio'], ':mes' => $conciliacion['mes']]);
        $_SESSION['success'] = 'Conciliación reabierta. El mes quedó disponible para edición.';
    }
} catch (Exception $e) {
    $_SESSION['error'] = 'No se pudo actualizar la conciliación: ' . $e->getMessage();
}

header('Location: ' . $redirect);
exit;
