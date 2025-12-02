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

function normalizarReglaAfectacion($regla) {
    $regla = strtolower(trim((string)$regla));
    if (!in_array($regla, ['suma', 'resta', 'neutral'], true)) {
        $regla = 'neutral';
    }
    return $regla;
}

function obtenerSiguienteIdSocioDisponible(PDO $pdo): int {
    $ids = $pdo->query('SELECT id_socio FROM socios ORDER BY id_socio')->fetchAll(PDO::FETCH_COLUMN);

    $esperado = 1;
    foreach ($ids as $id) {
        $idActual = (int) $id;
        if ($idActual === $esperado) {
            $esperado++;
            continue;
        }
        if ($idActual > $esperado) {
            break;
        }
    }

    return $esperado;
}

function recalcularAutoIncrementSocios(PDO $pdo): void {
    $siguiente = (int) $pdo->query('SELECT COALESCE(MAX(id_socio), 0) + 1 FROM socios')->fetchColumn();
    $pdo->exec('ALTER TABLE socios AUTO_INCREMENT = ' . $siguiente);
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
    $regla = normalizarReglaAfectacion($regla);
    if ($regla === 'neutral') {
        return;
    }
    $operador = $regla === 'suma' ? '+' : '-';
    $stmt = $pdo->prepare("UPDATE natillera_estado SET saldo_actual = saldo_actual $operador :monto WHERE id_estado = 1");
    $stmt->execute([':monto' => abs($monto)]);
}

function actualizarSaldoSocio($pdo, $idSocio, $monto, $regla) {
    $regla = normalizarReglaAfectacion($regla);
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
            'reglamento_archivo' => null,
        ];
    }
    if (!isset($config['reglamento_archivo'])) {
        $config['reglamento_archivo'] = null;
    }
    return $config;
}

function getPeriodosConfiguracion(PDO $pdo): array {
    $stmt = $pdo->query('SELECT id, anio, mes, activo FROM periodos_configuracion ORDER BY anio DESC, mes DESC');
    return $stmt->fetchAll();
}

function guardarPeriodoConfiguracion(PDO $pdo, int $anio, int $mes, bool $activo = true): void {
    $stmt = $pdo->prepare(
        'INSERT INTO periodos_configuracion (anio, mes, activo)
         VALUES (:anio, :mes, :activo)
         ON DUPLICATE KEY UPDATE activo = VALUES(activo)'
    );
    $stmt->execute([
        ':anio' => $anio,
        ':mes' => $mes,
        ':activo' => $activo ? 1 : 0,
    ]);
}

function actualizarEstadoPeriodoConfiguracion(PDO $pdo, int $id, bool $activo): void {
    $stmt = $pdo->prepare('UPDATE periodos_configuracion SET activo = :activo WHERE id = :id');
    $stmt->execute([
        ':id' => $id,
        ':activo' => $activo ? 1 : 0,
    ]);
}

function generarCSV($header, $rows) {
    $fh = fopen('php://output', 'w');
    fputcsv($fh, $header, ';');
    foreach ($rows as $r) {
        fputcsv($fh, $r, ';');
    }
    fclose($fh);
}

function asegurarTablaResultadosPolla(PDO $pdo): void {
    $sql = "CREATE TABLE IF NOT EXISTS polla_resultados (
        id_resultado INT AUTO_INCREMENT PRIMARY KEY,
        anio INT NOT NULL,
        mes INT NOT NULL,
        numero_ganador VARCHAR(50) NOT NULL,
        observaciones TEXT,
        fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_polla_mes (anio, mes)
    )";

    $pdo->exec($sql);
}

function obtenerResultadosPolla(PDO $pdo): array {
    asegurarTablaResultadosPolla($pdo);
    $stmt = $pdo->query('SELECT id_resultado, anio, mes, numero_ganador, observaciones FROM polla_resultados ORDER BY anio DESC, mes DESC');
    return $stmt->fetchAll();
}

function indexResultadosPollaPorMes(PDO $pdo): array {
    $resultados = obtenerResultadosPolla($pdo);
    $index = [];
    foreach ($resultados as $r) {
        $mesClave = sprintf('%04d-%02d', (int)$r['anio'], (int)$r['mes']);
        $index[$mesClave] = $r;
    }
    return $index;
}
?>
