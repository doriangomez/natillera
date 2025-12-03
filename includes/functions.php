<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/prestamos_helpers.php';

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

function asegurarEsquemaActividades(PDO $pdo): void {
    $columnas = [
        'es_ingreso TINYINT(1) DEFAULT 0',
        'es_pago_interes TINYINT(1) DEFAULT 0',
        'es_interes_causado TINYINT(1) DEFAULT 0',
    ];

    foreach ($columnas as $definicion) {
        $nombre = explode(' ', $definicion)[0];
        try {
            $existe = $pdo->query("SHOW COLUMNS FROM actividades_maestro LIKE '$nombre'");
            if ($existe && $existe->rowCount() === 0) {
                $pdo->exec("ALTER TABLE actividades_maestro ADD COLUMN $definicion");
            }
        } catch (Exception $e) {
            // Continuar sin interrumpir la operación
        }
    }
}

function sincronizarConceptosPrestamo(PDO $pdo): array {
    asegurarEsquemaActividades($pdo);

    $conceptos = [
        'es_prestamo' => [
            'nombre_actividad' => 'Préstamo a socio',
            'descripcion' => 'Desembolso de préstamo al socio o aval para un particular',
            'afecta_saldo_socio' => 'resta',
            'afecta_saldo_natillera' => 'resta',
            'es_ingreso' => 0,
        ],
        'es_pago_prestamo' => [
            'nombre_actividad' => 'Pago a préstamo',
            'descripcion' => 'Abonos a capital de préstamos vigentes',
            'afecta_saldo_socio' => 'suma',
            'afecta_saldo_natillera' => 'suma',
            'es_ingreso' => 1,
        ],
        'es_pago_interes' => [
            'nombre_actividad' => 'Pago de intereses',
            'descripcion' => 'Pagos de intereses de préstamos',
            'afecta_saldo_socio' => 'suma',
            'afecta_saldo_natillera' => 'suma',
            'es_ingreso' => 1,
        ],
        'es_interes_causado' => [
            'nombre_actividad' => 'Causación de intereses',
            'descripcion' => 'Causación automática de intereses mensuales',
            'afecta_saldo_socio' => 'resta',
            'afecta_saldo_natillera' => 'neutral',
            'es_ingreso' => 0,
        ],
    ];

    $resultado = [];

    foreach ($conceptos as $flag => $data) {
        $columnasActualizar = array_merge($data, array_fill_keys(array_keys($conceptos), 0), [
            'es_polla' => 0,
            'es_gasto_general' => 0,
        ]);
        $columnasActualizar[$flag] = 1;

        $stmtBandera = $pdo->prepare("SELECT * FROM actividades_maestro WHERE $flag = 1 ORDER BY id_actividad ASC");
        $stmtBandera->execute();
        $existentes = $stmtBandera->fetchAll();

        if (count($existentes) > 1) {
            foreach (array_slice($existentes, 1) as $duplicado) {
                $pdo->prepare("UPDATE actividades_maestro SET $flag = 0 WHERE id_actividad = :id")
                    ->execute([':id' => $duplicado['id_actividad']]);
            }
        }

        $principal = $existentes[0] ?? null;

        if (!$principal) {
            $stmtNombre = $pdo->prepare('SELECT * FROM actividades_maestro WHERE nombre_actividad = :nombre LIMIT 1');
            $stmtNombre->execute([':nombre' => $data['nombre_actividad']]);
            $principal = $stmtNombre->fetch();
        }

        if ($principal) {
            $sets = [];
            $params = [];
            foreach ($columnasActualizar as $col => $valor) {
                $sets[] = "$col = :$col";
                $params[":$col"] = $valor;
            }
            $params[':id'] = $principal['id_actividad'];
            $sqlUpdate = 'UPDATE actividades_maestro SET ' . implode(', ', $sets) . ' WHERE id_actividad = :id';
            $pdo->prepare($sqlUpdate)->execute($params);
            $resultado[$flag] = (int) $principal['id_actividad'];
            continue;
        }

        $columnasInsert = array_merge($columnasActualizar, ['activo' => 1]);
        $campos = array_keys($columnasInsert);
        $placeholders = array_map(fn($c) => ':' . $c, $campos);
        $sql = 'INSERT INTO actividades_maestro (' . implode(',', $campos) . ') VALUES (' . implode(',', $placeholders) . ')';
        $stmtInsert = $pdo->prepare($sql);
        $paramsInsert = [];
        foreach ($columnasInsert as $col => $valor) {
            $paramsInsert[":$col"] = $valor;
        }
        $stmtInsert->execute($paramsInsert);
        $resultado[$flag] = (int) $pdo->lastInsertId();
    }

    return $resultado;
}

function obtenerConceptoPorBandera(PDO $pdo, string $flag): ?array {
    $permitidos = ['es_prestamo', 'es_pago_prestamo', 'es_pago_interes', 'es_interes_causado'];
    if (!in_array($flag, $permitidos, true)) {
        return null;
    }

    sincronizarConceptosPrestamo($pdo);

    $stmt = $pdo->prepare("SELECT * FROM actividades_maestro WHERE $flag = 1 LIMIT 1");
    $stmt->execute();
    return $stmt->fetch() ?: null;
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
    asegurarEsquemaActividades($pdo);
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

function recalcularSaldosDesdeMovimientos(PDO $pdo): void {
    try {
        $pdo->beginTransaction();

        $pdo->exec('UPDATE socios SET saldo_socio = 0');

        $stmtSocios = $pdo->query(
            "SELECT m.id_socio, SUM(CASE" .
            " WHEN a.es_polla = 1 THEN 0" .
            " WHEN a.afecta_saldo_socio = 'suma' THEN ABS(m.valor)" .
            " WHEN a.afecta_saldo_socio = 'resta' THEN -ABS(m.valor)" .
            " ELSE 0 END) AS saldo" .
            " FROM movimientos m" .
            " JOIN actividades_maestro a ON m.id_actividad = a.id_actividad" .
            " WHERE m.id_socio IS NOT NULL" .
            " GROUP BY m.id_socio"
        );

        foreach ($stmtSocios->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $pdo->prepare('UPDATE socios SET saldo_socio = :saldo WHERE id_socio = :id')
                ->execute([
                    ':saldo' => (float) ($fila['saldo'] ?? 0),
                    ':id' => (int) $fila['id_socio'],
                ]);
        }

        $saldoNatillera = $pdo->query(
            "SELECT COALESCE(SUM(CASE" .
            " WHEN a.afecta_saldo_natillera = 'suma' THEN ABS(m.valor)" .
            " WHEN a.afecta_saldo_natillera = 'resta' THEN -ABS(m.valor)" .
            " ELSE 0 END), 0)" .
            " FROM movimientos m" .
            " JOIN actividades_maestro a ON m.id_actividad = a.id_actividad"
        )->fetchColumn();

        $pdo->prepare('UPDATE natillera_estado SET saldo_actual = :saldo WHERE id_estado = 1')
            ->execute([':saldo' => (float) $saldoNatillera]);

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

function getActividad($pdo, $id) {
    asegurarEsquemaActividades($pdo);
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
            'tasa_interes_socio' => 0,
            'tasa_interes_particular' => 0,
        ];
    }
    if (!isset($config['reglamento_archivo'])) {
        $config['reglamento_archivo'] = null;
    }
    if (!isset($config['tasa_interes_socio'])) {
        $config['tasa_interes_socio'] = 0;
    }
    if (!isset($config['tasa_interes_particular'])) {
        $config['tasa_interes_particular'] = 0;
    }
    return $config;
}

function getNombresMeses(): array {
    return [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];
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
