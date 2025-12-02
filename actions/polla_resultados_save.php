<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();

asegurarTablaResultadosPolla($pdo);

$accion = $_POST['accion'] ?? 'crear';
$idResultado = isset($_POST['id_resultado']) ? (int) $_POST['id_resultado'] : 0;
$mesResultado = trim($_POST['mes_resultado'] ?? '');
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

if (!preg_match('/^\d{4}-\d{2}$/', $mesResultado)) {
    $_SESSION['error'] = 'Debes elegir un mes válido para guardar el resultado de la polla.';
    header('Location: ../public/pollas.php');
    exit;
}

$anio = (int) substr($mesResultado, 0, 4);
$mes = (int) substr($mesResultado, 5, 2);

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
