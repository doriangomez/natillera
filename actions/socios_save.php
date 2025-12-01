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
    $bloqueos = [
        'movimientos' => $pdo->prepare('SELECT COUNT(*) FROM movimientos WHERE id_socio = :id'),
        'prestamos' => $pdo->prepare('SELECT COUNT(*) FROM prestamos WHERE id_socio = :id OR id_socio_aval = :id'),
        'pollas' => $pdo->prepare('SELECT COUNT(*) FROM movimientos m JOIN actividades_maestro a ON m.id_actividad=a.id_actividad WHERE m.id_socio = :id AND a.es_polla = 1'),
        'abonos' => $pdo->prepare('SELECT COUNT(*) FROM cuotas_prestamo cp JOIN prestamos p ON cp.id_prestamo = p.id_prestamo WHERE p.id_socio = :id'),
    ];

    $tieneRelaciones = false;
    foreach ($bloqueos as $stmt) {
        $stmt->execute([':id' => $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            $tieneRelaciones = true;
            break;
        }
    }

    if ($tieneRelaciones) {
        $_SESSION['error'] = 'No es posible eliminar el socio: existen movimientos, préstamos, pollas o abonos relacionados.';
        header('Location: ../public/socios.php');
        exit;
    }

    $stmt = $pdo->prepare('DELETE FROM socios WHERE id_socio = :id');
    $stmt->execute([':id' => $id]);
    header('Location: ../public/socios.php');
    exit;
}

$data = [
    ':nombre_completo' => $_POST['nombre_completo'],
    ':telefono' => $_POST['telefono'] ?? '',
    ':numero_polla' => $_POST['numero_polla'] ?? '',
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
