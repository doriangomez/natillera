<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAdmin();

asegurarEsquemaActividades($pdo);

$id = $_POST['id_actividad'] ?? null;
$accion = $_POST['accion'] ?? 'guardar';

if ($accion === 'eliminar' && $id) {
    try {
        $pdo->beginTransaction();

        $stmtMovimientos = $pdo->prepare('DELETE FROM movimientos WHERE id_actividad = :id');
        $stmtMovimientos->execute([':id' => $id]);

        $stmt = $pdo->prepare('DELETE FROM actividades_maestro WHERE id_actividad = :id');
        $stmt->execute([':id' => $id]);

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = 'No se pudo eliminar la actividad y sus movimientos relacionados.';
    }

    header('Location: ../public/actividades.php');
    exit;
}
$data = [
    ':nombre_actividad' => $_POST['nombre_actividad'],
    ':descripcion' => $_POST['descripcion'] ?? '',
    ':afecta_saldo_socio' => $_POST['afecta_saldo_socio'] ?? 'neutral',
    ':afecta_saldo_natillera' => $_POST['afecta_saldo_natillera'] ?? 'neutral',
    ':es_ingreso' => isset($_POST['es_ingreso']) ? 1 : 0,
    ':es_prestamo' => isset($_POST['es_prestamo']) ? 1 : 0,
    ':es_pago_prestamo' => isset($_POST['es_pago_prestamo']) ? 1 : 0,
    ':es_pago_interes' => isset($_POST['es_pago_interes']) ? 1 : 0,
    ':es_interes_causado' => isset($_POST['es_interes_causado']) ? 1 : 0,
    ':es_polla' => isset($_POST['es_polla']) ? 1 : 0,
    ':es_gasto_general' => isset($_POST['es_gasto_general']) ? 1 : 0,
    ':activo' => isset($_POST['activo']) ? (int) $_POST['activo'] : 1,
];

if ($id) {
    $data[':id'] = $id;
    $sql = 'UPDATE actividades_maestro SET nombre_actividad=:nombre_actividad, descripcion=:descripcion, afecta_saldo_socio=:afecta_saldo_socio, afecta_saldo_natillera=:afecta_saldo_natillera, es_ingreso=:es_ingreso, es_prestamo=:es_prestamo, es_pago_prestamo=:es_pago_prestamo, es_pago_interes=:es_pago_interes, es_interes_causado=:es_interes_causado, es_polla=:es_polla, es_gasto_general=:es_gasto_general, activo=:activo WHERE id_actividad=:id';
} else {
    $sql = 'INSERT INTO actividades_maestro (nombre_actividad, descripcion, afecta_saldo_socio, afecta_saldo_natillera, es_ingreso, es_prestamo, es_pago_prestamo, es_pago_interes, es_interes_causado, es_polla, es_gasto_general, activo) VALUES (:nombre_actividad, :descripcion, :afecta_saldo_socio, :afecta_saldo_natillera, :es_ingreso, :es_prestamo, :es_pago_prestamo, :es_pago_interes, :es_interes_causado, :es_polla, :es_gasto_general, :activo)';
}
$stmt = $pdo->prepare($sql);
$stmt->execute($data);
header('Location: ../public/actividades.php');
?>
