<?php
require_once __DIR__ . '/../includes/rifas_helpers.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();

$accion = $_POST['accion'] ?? '';
$usuario = $_SESSION['usuario'] ?? null;


function rifaDebeExistir(PDO $pdo, int $idRifa, string $accion): void
{
    if ($idRifa <= 0) {
        throw new RuntimeException('Debe seleccionar una rifa válida para ' . $accion . '.');
    }
    if (!obtenerRifa($pdo, $idRifa)) {
        throw new RuntimeException('La rifa seleccionada ya no existe. Actualiza la vista y vuelve a intentarlo.');
    }
}

try {
    asegurarEsquemaRifas($pdo);

    if ($accion === 'crear_rifa') {
        $cifrasNumero = max(1, (int) ($_POST['cifras_numero'] ?? 2));
        $rangoInicio = max(0, (int) ($_POST['rango_inicio'] ?? 0));
        $rangoFin = (int) ($_POST['rango_fin'] ?? ((10 ** $cifrasNumero) - 1));

        $manualAsignaciones = json_decode((string) ($_POST['manual_asignaciones_json'] ?? '[]'), true);
        if (!is_array($manualAsignaciones)) {
            $manualAsignaciones = [];
        }

        $gruposWizard = $_POST['grupos_json'] ?? '';
        $gruposParsed = json_decode((string) $gruposWizard, true);
        if ($gruposWizard !== '' && !is_array($gruposParsed)) {
            throw new RuntimeException('La configuración de grupos es inválida.');
        }

        $arteBasePath = clean($_POST['arte_base_path'] ?? '');
        if (!empty($_FILES['arte_base_file']['tmp_name']) && is_uploaded_file($_FILES['arte_base_file']['tmp_name'])) {
            $dir = dirname(__DIR__) . '/public/uploads/rifas/base';
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $ext = strtolower(pathinfo($_FILES['arte_base_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif'], true)) {
                throw new RuntimeException('El arte base debe ser una imagen PNG/JPG/GIF.');
            }
            $nombre = 'base_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destino = $dir . '/' . $nombre;
            if (!move_uploaded_file($_FILES['arte_base_file']['tmp_name'], $destino)) {
                throw new RuntimeException('No se pudo guardar el arte base cargado.');
            }
            $arteBasePath = 'uploads/rifas/base/' . $nombre;
        }
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
            'modo_distribucion' => 'manual',
            'boletas_por_socio' => max(1, (int) ($_POST['boletas_por_socio'] ?? 1)),
            'grupos_json' => $_POST['grupos_json'] ?? '',
            'arte_base_path' => $arteBasePath,
            'arte_numero_x' => ($_POST['arte_numero_x'] ?? '') !== '' ? (int) $_POST['arte_numero_x'] : null,
            'arte_numero_y' => ($_POST['arte_numero_y'] ?? '') !== '' ? (int) $_POST['arte_numero_y'] : null,
            'arte_numero_size' => ($_POST['arte_numero_size'] ?? '') !== '' ? (int) $_POST['arte_numero_size'] : null,
            'arte_numero_color' => clean($_POST['arte_numero_color'] ?? ''),
            'arte_font_path' => clean($_POST['arte_font_path'] ?? ''),
            'usuario_registro' => $usuario,
        ];

        $actividadIngreso = getActividad($pdo, $datos['id_actividad_ingreso']);
        $actividadPremio = getActividad($pdo, $datos['id_actividad_premio']);

        if (!$actividadIngreso || !actividadValidaParaCausacion($actividadIngreso)) {
            throw new RuntimeException('Debe seleccionar una actividad de ingreso válida.');
        }
        if (!$actividadPremio || !actividadValidaParaPremioRifa($actividadPremio)) {
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

        if (empty($datos['arte_base_path']) && empty($_FILES['arte_base_file']['tmp_name'])) {
            throw new RuntimeException('Debe cargar un arte base para crear la rifa.');
        }

        if ($datos['arte_numero_x'] === null || $datos['arte_numero_y'] === null || $datos['arte_numero_size'] === null) {
            throw new RuntimeException('Debe definir posición y tamaño del número en el arte.');
        }

        if ($datos['cantidad_boletas'] > (($datos['rango_fin'] - $datos['rango_inicio']) + 1)) {
            throw new RuntimeException('La cantidad de boletas no puede superar los números disponibles en el rango.');
        }


        $datos['manual_asignaciones'] = $manualAsignaciones;
        $datos['grupos_json'] = $gruposWizard;

        crearRifa($pdo, $datos);
        $_SESSION['exito'] = 'Rifa creada. Ahora puedes registrar boletas manualmente.';
    }



    if ($accion === 'crear_boleta_manual') {
        $idRifa = (int) ($_POST['id_rifa'] ?? 0);
        rifaDebeExistir($pdo, $idRifa, 'crear boleta manual');
        $idGrupo = (int) ($_POST['id_grupo'] ?? 0);
        $idSocio = (int) ($_POST['id_socio'] ?? 0);
        $numero = clean($_POST['numero'] ?? '');
        if ($idGrupo <= 0 || $idSocio <= 0 || $numero === '') {
            throw new RuntimeException('Debe seleccionar grupo, socio y número para crear la boleta manual.');
        }
        crearBoletaManual($pdo, $idRifa, $idGrupo, $idSocio, $numero, $usuario);
        $_SESSION['exito'] = 'Boleta manual creada correctamente.';
    }

    if ($accion === 'eliminar_boletas') {
        $idRifa = (int) ($_POST['id_rifa'] ?? 0);
        rifaDebeExistir($pdo, $idRifa, 'eliminar boletas');
        $idGrupo = ($_POST['id_grupo'] ?? '') !== '' ? (int) $_POST['id_grupo'] : null;
        $idSocio = ($_POST['id_socio'] ?? '') !== '' ? (int) $_POST['id_socio'] : null;
        if (($idGrupo ?? 0) <= 0 && ($idSocio ?? 0) <= 0 && (int) ($_POST['eliminar_todo'] ?? 0) !== 1) {
            throw new RuntimeException('Seleccione eliminar todo o filtre por grupo/socio para continuar.');
        }
        $eliminadas = eliminarBoletasRifa($pdo, $idRifa, $idGrupo, $idSocio);
        $_SESSION['exito'] = 'Boletas eliminadas: ' . $eliminadas . '.';
    }

    if ($accion === 'reasignar_boleta') {
        $idRifa = (int) ($_POST['id_rifa'] ?? 0);
        rifaDebeExistir($pdo, $idRifa, 'reasignar boleta');
        reAsignarBoleta(
            $pdo,
            $idRifa,
            clean($_POST['numero_actual'] ?? ''),
            clean($_POST['numero_nuevo'] ?? ''),
            ($_POST['id_socio'] ?? '') !== '' ? (int) $_POST['id_socio'] : null,
            clean($_POST['motivo'] ?? ''),
            $usuario,
            ($_POST['id_grupo'] ?? '') !== '' ? (int) $_POST['id_grupo'] : null
        );
        $_SESSION['exito'] = 'Boleta ajustada correctamente.';
    }

    if ($accion === 'pagar_boleta') {
        $idRifa = (int) ($_POST['id_rifa'] ?? 0);
        rifaDebeExistir($pdo, $idRifa, 'registrar pago');
        registrarPagoBoleta(
            $pdo,
            $idRifa,
            clean($_POST['numero'] ?? ''),
            $_POST['fecha_pago'] ?? date('Y-m-d'),
            clean($_POST['medio'] ?? ''),
            ($_POST['id_medio_pago'] ?? '') !== '' ? (int) $_POST['id_medio_pago'] : null,
            $usuario,
            ($_POST['id_actividad_movimiento'] ?? '') !== '' ? (int) $_POST['id_actividad_movimiento'] : null,
            ($_POST['id_grupo'] ?? '') !== '' ? (int) $_POST['id_grupo'] : null
        );
        $_SESSION['exito'] = 'Pago registrado y contabilizado correctamente.';
    }

    if ($accion === 'registrar_premio') {
        $idRifa = (int) ($_POST['id_rifa'] ?? 0);
        rifaDebeExistir($pdo, $idRifa, 'registrar premio');
        registrarPremioRifa(
            $pdo,
            $idRifa,
            clean($_POST['numero_ganador'] ?? ''),
            ($_POST['id_grupo_ganador'] ?? '') !== '' ? (int) $_POST['id_grupo_ganador'] : null,
            (float) ($_POST['valor_premio'] ?? 0),
            $_POST['fecha_pago'] ?? date('Y-m-d'),
            clean($_POST['medio'] ?? ''),
            ($_POST['id_medio_pago'] ?? '') !== '' ? (int) $_POST['id_medio_pago'] : null,
            $usuario,
            ($_POST['id_actividad_premio_mov'] ?? '') !== '' ? (int) $_POST['id_actividad_premio_mov'] : null
        );
        $_SESSION['exito'] = 'Premio registrado y rifa cerrada.';
    }


    if ($accion === 'descargar_boletas_zip') {
        $idRifa = (int) ($_POST['id_rifa'] ?? 0);
        rifaDebeExistir($pdo, $idRifa, 'descargar boletas');
        $idGrupo = ($_POST['id_grupo'] ?? '') !== '' ? (int) $_POST['id_grupo'] : null;
        $idSocio = ($_POST['id_socio'] ?? '') !== '' ? (int) $_POST['id_socio'] : null;
        $rutaZip = exportarBoletasZipFiltrado($idRifa, $idGrupo, $idSocio);
        if (!$rutaZip || !is_file($rutaZip)) {
            throw new RuntimeException('No hay imágenes generadas para exportar en ZIP.');
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($rutaZip) . '"');
        header('Content-Length: ' . filesize($rutaZip));
        readfile($rutaZip);
        exit;
    }

    if ($accion === 'reiniciar_asignaciones') {
        throw new RuntimeException('La regeneración automática fue deshabilitada. Use la eliminación manual de boletas.');
        $idRifa = (int) ($_POST['id_rifa'] ?? 0);
        rifaDebeExistir($pdo, $idRifa, 'reasignar asignaciones');
        $forzar = (int) ($_POST['forzar_con_pagos'] ?? 0) === 1;
        limpiarAsignacionesRifa($pdo, $idRifa, $usuario, $forzar);
        $_SESSION['exito'] = 'Asignaciones eliminadas. Los números base se conservaron para regenerar nuevamente.';
    }

    if ($accion === 'regenerar_asignaciones') {
        throw new RuntimeException('La regeneración automática fue deshabilitada. Registre boletas manualmente.');
        $idRifa = (int) ($_POST['id_rifa'] ?? 0);
        rifaDebeExistir($pdo, $idRifa, 'regenerar asignaciones');
        regenerarAsignacionesRifa($pdo, $idRifa, $usuario);
        $_SESSION['exito'] = 'Asignaciones regeneradas correctamente.';
    }

    if ($accion === 'eliminar_rifa') {
        if (!function_exists('eliminarRifa')) {
            throw new RuntimeException('No se pudo cargar la función para eliminar rifas.');
        }

        $idRifa = (int) ($_POST['id_rifa'] ?? 0);
        rifaDebeExistir($pdo, $idRifa, 'eliminar rifa');
        eliminarRifa($pdo, $idRifa);
        $_SESSION['exito'] = 'Rifa eliminada correctamente, incluyendo movimientos asociados.';
    }
} catch (Throwable $e) {
    $_SESSION['error'] = $e->getMessage();
}

if ($accion === 'eliminar_rifa') {
    header('Location: ../public/rifas.php?deleted=1');
} else {
    $idRifaRedirect = isset($_POST['id_rifa']) ? (int) ($_POST['id_rifa'] ?? 0) : 0;
    $qs = $idRifaRedirect > 0 ? ('?id_rifa=' . $idRifaRedirect) : '';
    header('Location: ../public/rifas.php' . $qs);
}
exit;
