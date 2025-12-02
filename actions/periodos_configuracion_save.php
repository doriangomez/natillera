<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAdmin();

$redirectBase = '../public/configuracion.php';

if (isset($_POST['toggle_id'])) {
    $id = (int) ($_POST['toggle_id'] ?? 0);
    $estado = isset($_POST['estado']) ? (int) $_POST['estado'] : 0;
    if ($id > 0) {
        actualizarEstadoPeriodoConfiguracion($pdo, $id, $estado === 1);
        header('Location: ' . $redirectBase . '?periodo_guardado=1#periodos');
        exit;
    }
    header('Location: ' . $redirectBase . '?periodo_error=Datos%20inv%C3%A1lidos#periodos');
    exit;
}

$anio = isset($_POST['anio']) ? (int) $_POST['anio'] : 0;
$mes = isset($_POST['mes']) ? (int) $_POST['mes'] : 0;
$activo = isset($_POST['activo']) ? (int) $_POST['activo'] : 0;

$anioActual = (int) date('Y');
$maxAnioPermitido = max($anioActual + 3, 2026);
$minAnioPermitido = min($anioActual - 1, 2020);

if ($anio < $minAnioPermitido || $anio > $maxAnioPermitido || $mes < 1 || $mes > 12) {
    header('Location: ' . $redirectBase . '?periodo_error=Datos%20inv%C3%A1lidos#periodos');
    exit;
}

guardarPeriodoConfiguracion($pdo, $anio, $mes, $activo === 1);

header('Location: ' . $redirectBase . '?periodo_guardado=1#periodos');
exit;
