<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();

$accion = $_POST['accion'] ?? 'guardar';
$idConciliacion = isset($_POST['id_conciliacion']) ? (int) $_POST['id_conciliacion'] : 0;

if ($accion === 'eliminar') {
    if ($idConciliacion > 0) {
        $stmt = $pdo->prepare('DELETE FROM conciliaciones_medios_pago WHERE id = :id');
        $stmt->execute([':id' => $idConciliacion]);
    }
    header('Location: ../public/conciliaciones.php');
    exit;
}

$anio = (int) $_POST['anio'];
$mes = (int) $_POST['mes'];
$medios = $_POST['medio_ids'] ?? [];
$bancos = $_POST['banco'] ?? [];
$hayDescuadre = false;

foreach ($medios as $idMedio) {
    $valorBanco = isset($bancos[$idMedio]) ? (float) $bancos[$idMedio] : 0;
    $stmtTotal = $pdo->prepare('SELECT SUM(valor) total FROM movimientos WHERE id_medio_pago = :id AND YEAR(fecha)=:y AND MONTH(fecha)=:m');
    $stmtTotal->execute([':id'=>$idMedio, ':y'=>$anio, ':m'=>$mes]);
    $totalSistema = (float) ($stmtTotal->fetchColumn() ?: 0);
    $diff = $totalSistema - $valorBanco;
    if (abs($diff) > 0.009) {
        $hayDescuadre = true;
    }

    $stmt = $pdo->prepare('INSERT INTO conciliaciones_medios_pago (id_medio, anio, mes, total_sistema, valor_banco, diferencia) VALUES (:id,:y,:m,:ts,:vb,:df)
        ON DUPLICATE KEY UPDATE total_sistema=VALUES(total_sistema), valor_banco=VALUES(valor_banco), diferencia=VALUES(diferencia)');
    $stmt->execute([
        ':id' => $idMedio,
        ':y' => $anio,
        ':m' => $mes,
        ':ts' => $totalSistema,
        ':vb' => $valorBanco,
        ':df' => $diff
    ]);
}
$redirect = '../public/conciliaciones.php?anio='.$anio.'&mes='.$mes.'&guardado=1';
if ($hayDescuadre) {
    $_SESSION['warning'] = 'Se detectaron descuadres en la conciliación. Verifique los valores registrados.';
}
header('Location: ' . $redirect);
exit;
?>
