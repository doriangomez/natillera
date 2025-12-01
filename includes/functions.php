<?php
require_once __DIR__ . '/../config/db.php';

function clean($value) {
    return htmlspecialchars(trim((string)$value), ENT_QUOTES, 'UTF-8');
}

function getSocios($pdo, $search = '') {
    $sql = "SELECT * FROM socios WHERE activo = 1";
    $params = [];
    if ($search !== '') {
        $sql .= " AND (nombre_completo LIKE :q OR telefono LIKE :q OR id_socio LIKE :q)";
        $params[':q'] = "%$search%";
    }
    $sql .= " ORDER BY nombre_completo";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getActividades($pdo, $soloPolla = false, $incluirInactivas = false) {
    $sql = "SELECT * FROM actividades_maestro";
    $condiciones = [];
    if ($soloPolla) {
        $condiciones[] = "es_polla = 1";
    }
    if (!$incluirInactivas) {
        $condiciones[] = "activo = 1";
    }
    if ($condiciones) {
        $sql .= ' WHERE ' . implode(' AND ', $condiciones);
    }
    $sql .= " ORDER BY nombre_actividad";
    return $pdo->query($sql)->fetchAll();
}

function getMediosPago($pdo, $incluirInactivos = false) {
    $sql = "SELECT * FROM medios_pago";
    if (!$incluirInactivos) {
        $sql .= " WHERE activo = 1";
    }
    $sql .= " ORDER BY nombre";
    return $pdo->query($sql)->fetchAll();
}

function getMedioPago($pdo, $id) {
    $stmt = $pdo->prepare('SELECT * FROM medios_pago WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch();
}

function getSaldoNatillera($pdo) {
    $stmt = $pdo->query("SELECT saldo_actual FROM natillera_estado LIMIT 1");
    $row = $stmt->fetch();
    return $row ? (float)$row['saldo_actual'] : 0.0;
}

function actualizarSaldoNatillera($pdo, $monto, $regla) {
    if ($regla === 'neutral') {
        return;
    }
    $operador = $regla === 'suma' ? '+' : '-';
    $stmt = $pdo->prepare("UPDATE natillera_estado SET saldo_actual = saldo_actual $operador :monto WHERE id_estado = 1");
    $stmt->execute([':monto' => abs($monto)]);
}

function actualizarSaldoSocio($pdo, $idSocio, $monto, $regla) {
    if (!$idSocio || $regla === 'neutral') {
        return;
    }
    $operador = $regla === 'suma' ? '+' : '-';
    $stmt = $pdo->prepare("UPDATE socios SET saldo_socio = saldo_socio $operador :monto WHERE id_socio = :id");
    $stmt->execute([':monto' => abs($monto), ':id' => $idSocio]);
}

function getActividad($pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM actividades_maestro WHERE id_actividad = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch();
}

function getConfiguracionGeneral($pdo) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM configuracion_general WHERE id_config = 1');
        $stmt->execute();
        $config = $stmt->fetch();
    } catch (PDOException $e) {
        $config = false;
    }

    if (!$config) {
        $config = [
            'nombre_sistema' => 'Aplicativo de Natillera creado por Dorian Gómez',
            'logo_archivo' => null,
            'datos_globales' => '',
        ];
    }
    return $config;
}

function generarCSV($header, $rows) {
    $fh = fopen('php://output', 'w');
    fputcsv($fh, $header, ';');
    foreach ($rows as $r) {
        fputcsv($fh, $r, ';');
    }
    fclose($fh);
}
?>
