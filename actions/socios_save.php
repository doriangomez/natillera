<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();

$id = $_POST['id_socio'] ?? null;
$accion = $_POST['accion'] ?? 'guardar';

if ($accion === 'inactivar' && $id) {
    $stmt = $pdo->prepare('UPDATE socios SET activo = 0 WHERE id_socio = :id');
    $stmt->execute([':id' => $id]);
    header('Location: ../public/socios.php');
    exit;
}

if ($accion === 'eliminar' && $id) {
    try {
        $pdo->beginTransaction();

        $prestamosStmt = $pdo->prepare('SELECT id_prestamo FROM prestamos WHERE id_socio = :id OR id_socio_aval = :id');
        $prestamosStmt->execute([':id' => $id]);
        $prestamoIds = $prestamosStmt->fetchAll(PDO::FETCH_COLUMN);

        if ($prestamoIds) {
            $placeholders = implode(',', array_fill(0, count($prestamoIds), '?'));
            $pdo->prepare("DELETE FROM cuotas_prestamo WHERE id_prestamo IN ($placeholders)")->execute($prestamoIds);
            $pdo->prepare("DELETE FROM prestamos WHERE id_prestamo IN ($placeholders)")->execute($prestamoIds);
        }

        $pdo->prepare('DELETE FROM movimientos WHERE id_socio = :id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM socios WHERE id_socio = :id')->execute([':id' => $id]);

        $pdo->commit();
        header('Location: ../public/socios.php');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'No fue posible eliminar el socio y sus registros relacionados.';
        header('Location: ../public/socios.php');
        exit;
    }
}

$numeroPollaRaw = trim($_POST['numero_polla'] ?? '');
$numeroPollaDigits = preg_replace('/\D/', '', $numeroPollaRaw);

if ($numeroPollaDigits === '' || strlen($numeroPollaDigits) > 2 || (int) $numeroPollaDigits > 99) {
    $_SESSION['error'] = 'El número de polla debe estar entre 00 y 99.';
    header('Location: ../public/socios.php' . ($id ? '?id=' . $id : ''));
    exit;
}

$numeroPolla = str_pad((string) (int) $numeroPollaDigits, 2, '0', STR_PAD_LEFT);

$conflictoStmt = $pdo->prepare('SELECT id_socio, nombre_completo FROM socios WHERE numero_polla = :numero_polla AND id_socio <> :id');
$conflictoStmt->execute([
    ':numero_polla' => $numeroPolla,
    ':id' => $id ?? 0,
]);
$socioExistente = $conflictoStmt->fetch();

if ($socioExistente) {
    $_SESSION['error'] = "El número de polla $numeroPolla ya está asignado a " . $socioExistente['nombre_completo'] . '.';
    $_SESSION['error_action'] = [
        'url' => '../public/socios.php?id=' . $socioExistente['id_socio'],
        'label' => 'Ver socio',
    ];
    header('Location: ../public/socios.php' . ($id ? '?id=' . $id : ''));
    exit;
}

$data = [
    ':nombre_completo' => $_POST['nombre_completo'],
    ':telefono' => $_POST['telefono'] ?? '',
    ':numero_polla' => $numeroPolla,
    ':periodicidad_pago' => $_POST['periodicidad_pago'] ?? 'mensual',
    ':valor_presupuestado' => $_POST['valor_presupuestado'] ?? 0,
];

if ($id) {
    $data[':id'] = $id;
    $stmt = $pdo->prepare('UPDATE socios SET nombre_completo=:nombre_completo, telefono=:telefono, numero_polla=:numero_polla, periodicidad_pago=:periodicidad_pago, valor_presupuestado=:valor_presupuestado WHERE id_socio=:id');
    $stmt->execute($data);
} else {
    $stmt = $pdo->prepare('INSERT INTO socios (nombre_completo, telefono, numero_polla, periodicidad_pago, valor_presupuestado) VALUES (:nombre_completo, :telefono, :numero_polla, :periodicidad_pago, :valor_presupuestado)');
    $stmt->execute($data);
}
header('Location: ../public/socios.php');
?>
