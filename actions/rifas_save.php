<?php
require_once __DIR__ . '/../includes/rifas_helpers.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();

$accion = $_POST['accion'] ?? '';
$usuario = $_SESSION['usuario'] ?? null;

try {
    asegurarEsquemaRifas($pdo);

    if ($accion === 'crear_rifa') {
        $cifrasNumero = max(1, (int) ($_POST['cifras_numero'] ?? 2));
        $rangoInicio = max(0, (int) ($_POST['rango_inicio'] ?? 0));
        $rangoFin = (int) ($_POST['rango_fin'] ?? ((10 ** $cifrasNumero) - 1));
        $datos = [
            'nombre' => clean($_POST['nombre'] ?? ''),
            'fecha_inicio' => $_POST['fecha_inicio'] ?? '',
            'fecha_fin' => $_POST['fecha_fin'] ?? '',
            'valor_boleta' => (float) ($_POST['valor_boleta'] ?? 0),
            'cantidad_boletas' => (int) ($_POST['cantidad_boletas'] ?? 100),
            'observaciones' => $_POST['observaciones'] ?? '',
            'id_actividad_ingreso' => (int) ($_POST['id_actividad_ingreso'] ?? 0),
            'id_actividad_premio' => (int) ($_POST['id_actividad_premio'] ?? 0),
            'tipo_rifa' => clean($_POST['tipo_rifa'] ?? 'normal'),
            'cantidad_grupos' => max(1, (int) ($_POST['cantidad_grupos'] ?? 1)),
            'cifras_numero' => $cifrasNumero,
            'rango_inicio' => $rangoInicio,
            'rango_fin' => max($rangoInicio, $rangoFin),
            'modo_numeracion' => clean($_POST['modo_numeracion'] ?? 'secuencial'),
            'numeros_manuales' => clean($_POST['numeros_manuales'] ?? ''),
            'modo_distribucion' => clean($_POST['modo_distribucion'] ?? 'aleatoria'),
            'boletas_por_socio' => max(1, (int) ($_POST['boletas_por_socio'] ?? 1)),
            'grupos_json' => $_POST['grupos_json'] ?? '',
            'arte_base_path' => clean($_POST['arte_base_path'] ?? ''),
            'arte_numero_x' => ($_POST['arte_numero_x'] ?? '') !== '' ? (int) $_POST['arte_numero_x'] : null,
            'arte_numero_y' => ($_POST['arte_numero_y'] ?? '') !== '' ? (int) $_POST['arte_numero_y'] : null,
            'arte_numero_size' => ($_POST['arte_numero_size'] ?? '') !== '' ? (int) $_POST['arte_numero_size'] : null,
            'arte_numero_color' => clean($_POST['arte_numero_color'] ?? ''),
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
        if (!function_exists('eliminarRifa')) {
            throw new RuntimeException('No se pudo cargar la función para eliminar rifas.');
        }

        $idRifa = (int) ($_POST['id_rifa'] ?? 0);
        eliminarRifa($pdo, $idRifa);
        $_SESSION['exito'] = 'Rifa eliminada correctamente, incluyendo movimientos asociados.';
    }
} catch (Throwable $e) {
    $_SESSION['error'] = $e->getMessage();
}

header('Location: ../public/rifas.php');
exit;
