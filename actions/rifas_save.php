<?php
require_once __DIR__ . '/../includes/rifas_helpers.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();

$accion = $_POST['accion'] ?? '';
$usuario = $_SESSION['usuario'] ?? null;

try {
    asegurarEsquemaRifas($pdo);

    if ($accion === 'crear_rifa') {
        $datos = [
            'nombre' => clean($_POST['nombre'] ?? ''),
            'fecha_inicio' => $_POST['fecha_inicio'] ?? '',
            'fecha_fin' => $_POST['fecha_fin'] ?? '',
            'valor_boleta' => (float) ($_POST['valor_boleta'] ?? 0),
            'cantidad_boletas' => (int) ($_POST['cantidad_boletas'] ?? 100),
            'observaciones' => $_POST['observaciones'] ?? '',
            'id_actividad_ingreso' => (int) ($_POST['id_actividad_ingreso'] ?? 0),
            'id_actividad_premio' => (int) ($_POST['id_actividad_premio'] ?? 0),
            'usuario_registro' => $usuario,
        ];

        $actividadIngreso = getActividad($pdo, $datos['id_actividad_ingreso']);
        $actividadPremio = getActividad($pdo, $datos['id_actividad_premio']);

        if (!$actividadIngreso || (int) ($actividadIngreso['es_ingreso'] ?? 0) !== 1) {
            throw new RuntimeException('Debe seleccionar una actividad de ingreso válida.');
        }
        if (!$actividadPremio || (int) ($actividadPremio['es_ingreso'] ?? 1) !== 0) {
            throw new RuntimeException('Debe seleccionar una actividad de egreso/premio válida.');
        }

        foreach (['nombre','fecha_inicio','fecha_fin'] as $campo) {
            if (empty($datos[$campo])) {
                throw new RuntimeException('Faltan datos obligatorios para crear la rifa.');
            }
        }

        if ($datos['valor_boleta'] <= 0 || $datos['cantidad_boletas'] <= 0) {
            throw new RuntimeException('El valor y la cantidad de boletas deben ser mayores a cero.');
        }

        crearRifa($pdo, $datos);
        $_SESSION['exito'] = 'Rifa creada y boletas distribuidas automáticamente.';
    }

    if ($accion === 'reasignar_boleta') {
        $idRifa = (int) ($_POST['id_rifa'] ?? 0);
        reAsignarBoleta(
            $pdo,
            $idRifa,
            clean($_POST['numero_actual'] ?? ''),
            clean($_POST['numero_nuevo'] ?? ''),
            ($_POST['id_socio'] ?? '') !== '' ? (int) $_POST['id_socio'] : null,
            clean($_POST['motivo'] ?? ''),
            $usuario
        );
        $_SESSION['exito'] = 'Boleta ajustada correctamente.';
    }

    if ($accion === 'pagar_boleta') {
        $idRifa = (int) ($_POST['id_rifa'] ?? 0);
        registrarPagoBoleta(
            $pdo,
            $idRifa,
            clean($_POST['numero'] ?? ''),
            $_POST['fecha_pago'] ?? date('Y-m-d'),
            clean($_POST['medio'] ?? ''),
            ($_POST['id_medio_pago'] ?? '') !== '' ? (int) $_POST['id_medio_pago'] : null,
            $usuario
        );
        $_SESSION['exito'] = 'Pago registrado y contabilizado correctamente.';
    }

    if ($accion === 'registrar_premio') {
        $idRifa = (int) ($_POST['id_rifa'] ?? 0);
        registrarPremioRifa(
            $pdo,
            $idRifa,
            clean($_POST['numero_ganador'] ?? ''),
            (float) ($_POST['valor_premio'] ?? 0),
            $_POST['fecha_pago'] ?? date('Y-m-d'),
            clean($_POST['medio'] ?? ''),
            ($_POST['id_medio_pago'] ?? '') !== '' ? (int) $_POST['id_medio_pago'] : null,
            $usuario
        );
        $_SESSION['exito'] = 'Premio registrado y rifa cerrada.';
    }

    if ($accion === 'eliminar_rifa') {
        $idRifa = (int) ($_POST['id_rifa'] ?? 0);

        if (!function_exists('eliminarRifa')) {
            $helperPath = __DIR__ . '/../includes/rifas_helpers.php';
            if (file_exists($helperPath)) {
                require_once $helperPath;
            }
        }

        if (!function_exists('eliminarRifa')) {
            throw new RuntimeException('No se pudo cargar el helper para eliminar rifas.');
        }

        eliminarRifa($pdo, $idRifa);
        $_SESSION['exito'] = 'Rifa eliminada correctamente, incluyendo movimientos asociados.';
    }
} catch (Throwable $e) {
    $_SESSION['error'] = $e->getMessage();
}

header('Location: ../public/rifas.php');
exit;
