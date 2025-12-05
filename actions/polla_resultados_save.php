<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();

asegurarTablaResultadosPolla($pdo);

$accion = $_POST['accion'] ?? 'crear';
$idResultado = isset($_POST['id_resultado']) ? (int) $_POST['id_resultado'] : 0;
$mesResultado = trim($_POST['mes_resultado'] ?? '');
$anioSeleccionado = isset($_POST['anio']) ? (int) $_POST['anio'] : 0;
$mesSeleccionado = isset($_POST['mes']) ? (int) $_POST['mes'] : 0;
$numeroGanador = trim($_POST['numero_ganador'] ?? '');
$observaciones = trim($_POST['observaciones'] ?? '');

if ($accion === 'eliminar') {
    if ($idResultado) {
        $pdo->prepare('DELETE FROM polla_resultados WHERE id_resultado = :id')->execute([':id' => $idResultado]);
        $_SESSION['success'] = 'Resultado de polla eliminado correctamente.';
    }

    header('Location: ../public/pollas.php');
    exit;
}

if ((!$anioSeleccionado || !$mesSeleccionado) && preg_match('/^\d{4}-\d{2}$/', $mesResultado)) {
    $anioSeleccionado = (int) substr($mesResultado, 0, 4);
    $mesSeleccionado = (int) substr($mesResultado, 5, 2);
}

if (!$anioSeleccionado || !$mesSeleccionado) {
    $_SESSION['error'] = 'Debes elegir un año y mes válidos para guardar el resultado de la polla.';
    header('Location: ../public/pollas.php');
    exit;
}

if (!checkdate($mesSeleccionado, 1, $anioSeleccionado)) {
    $_SESSION['error'] = 'La combinación de año y mes seleccionada no es válida.';
    header('Location: ../public/pollas.php');
    exit;
}

$stmtPeriodo = $pdo->prepare('SELECT COUNT(*) FROM periodos_configuracion WHERE anio = :anio AND mes = :mes AND activo = 1');
$stmtPeriodo->execute([':anio' => $anioSeleccionado, ':mes' => $mesSeleccionado]);
if ((int) $stmtPeriodo->fetchColumn() === 0) {
    $_SESSION['error'] = 'El periodo seleccionado no está habilitado en la configuración.';
    header('Location: ../public/pollas.php');
    exit;
}

$anio = $anioSeleccionado;
$mes = $mesSeleccionado;
$mesResultado = sprintf('%04d-%02d', $anio, $mes);

if ($numeroGanador === '') {
    $_SESSION['error'] = 'El número ganador no puede estar vacío.';
    header('Location: ../public/pollas.php');
    exit;
}

if ($accion === 'actualizar' && $idResultado) {
    $stmt = $pdo->prepare('UPDATE polla_resultados SET anio = :anio, mes = :mes, numero_ganador = :numero, observaciones = :obs WHERE id_resultado = :id');
    $stmt->execute([
        ':anio' => $anio,
        ':mes' => $mes,
        ':numero' => $numeroGanador,
        ':obs' => $observaciones,
        ':id' => $idResultado,
    ]);
    $_SESSION['success'] = 'Resultado de polla actualizado.';
} else {
    $stmt = $pdo->prepare('INSERT INTO polla_resultados (anio, mes, numero_ganador, observaciones) VALUES (:anio, :mes, :numero, :obs)
        ON DUPLICATE KEY UPDATE numero_ganador = VALUES(numero_ganador), observaciones = VALUES(observaciones)');
    $stmt->execute([
        ':anio' => $anio,
        ':mes' => $mes,
        ':numero' => $numeroGanador,
        ':obs' => $observaciones,
    ]);
    $_SESSION['success'] = 'Resultado de polla registrado correctamente.';
}

header('Location: ../public/pollas.php');
