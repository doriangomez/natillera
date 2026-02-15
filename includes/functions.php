<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/prestamos_helpers.php';

function clean($value) {
    return htmlspecialchars(trim((string)$value), ENT_QUOTES, 'UTF-8');
}

function asegurarColumnaIdInternoSocios(PDO $pdo): void {
    static $asegurada = false;
    if ($asegurada) {
        return;
    }

    try {
        $columna = $pdo->query("SHOW COLUMNS FROM socios LIKE 'id_interno'");
        if ($columna && $columna->rowCount() === 0) {
            $pdo->exec("ALTER TABLE socios ADD COLUMN id_interno TINYINT UNSIGNED DEFAULT NULL AFTER id_socio");
        }
    } catch (Exception $e) {
        $asegurada = true;
        return;
    }

    try {
        $indice = $pdo->query("SHOW INDEX FROM socios WHERE Key_name = 'uq_socios_id_interno'");
        if ($indice && $indice->rowCount() === 0) {
            $pdo->exec('CREATE UNIQUE INDEX uq_socios_id_interno ON socios (id_interno)');
        }
    } catch (Exception $e) {
        // Ignorar fallos al crear el índice para no interrumpir el flujo principal.
    }

    $asegurada = true;
}

function asegurarColumnaGrupoSocios(PDO $pdo): void {
    static $asegurada = false;
    if ($asegurada) {
        return;
    }

    try {
        $columna = $pdo->query("SHOW COLUMNS FROM socios LIKE 'grupo'");
        if ($columna && $columna->rowCount() === 0) {
            $pdo->exec("ALTER TABLE socios ADD COLUMN grupo VARCHAR(100) DEFAULT NULL AFTER numero_polla");
        }
    } catch (Exception $e) {
        // Continuar para no interrumpir el flujo principal.
    }

    $asegurada = true;
}

function asegurarForeignKey(PDO $pdo, array $definicion): void {
    $tabla = $definicion['tabla'];
    $columna = $definicion['columna'];
    $referencia = $definicion['referencia'];
    $colReferencia = $definicion['col_referencia'];
    $nombre = $definicion['nombre'];
    $onDelete = strtoupper($definicion['on_delete'] ?? 'CASCADE');
    $onUpdate = strtoupper($definicion['on_update'] ?? 'CASCADE');

    try {
        $colExiste = $pdo->prepare("SHOW COLUMNS FROM `$tabla` LIKE :columna");
        $colExiste->execute([':columna' => $columna]);
        if ($colExiste->rowCount() === 0) {
            return;
        }

        $stmtFk = $pdo->prepare(
            "SELECT rc.CONSTRAINT_NAME, rc.DELETE_RULE, rc.UPDATE_RULE, rc.REFERENCED_TABLE_NAME
             FROM information_schema.referential_constraints rc
             JOIN information_schema.key_column_usage k
               ON rc.constraint_name = k.constraint_name
              AND rc.constraint_schema = k.constraint_schema
            WHERE rc.constraint_schema = DATABASE()
              AND rc.table_name = :tabla
              AND k.column_name = :columna"
        );
        $stmtFk->execute([':tabla' => $tabla, ':columna' => $columna]);
        $fk = $stmtFk->fetch(PDO::FETCH_ASSOC);

        if ($fk) {
            $deleteRule = strtoupper($fk['DELETE_RULE'] ?? '');
            $updateRule = strtoupper($fk['UPDATE_RULE'] ?? '');
            $refActual = $fk['REFERENCED_TABLE_NAME'] ?? '';
            if ($deleteRule === $onDelete && $updateRule === $onUpdate && $refActual === $referencia) {
                return;
            }
            $pdo->exec("ALTER TABLE `$tabla` DROP FOREIGN KEY `{$fk['CONSTRAINT_NAME']}`");
        }

        $pdo->exec(
            "ALTER TABLE `$tabla`
             ADD CONSTRAINT `$nombre`
             FOREIGN KEY (`$columna`) REFERENCES `$referencia`(`$colReferencia`)
             ON DELETE $onDelete ON UPDATE $onUpdate"
        );
    } catch (Exception $e) {
        // Ignorar errores de motor o permisos; no se detiene el flujo principal.
    }
}

function asegurarEsquemaMovimientos(PDO $pdo): void {
    static $reforzado = false;
    if ($reforzado) {
        return;
    }
    $reforzado = true;

    $columnas = [
        'anio INT DEFAULT NULL',
        'mes INT DEFAULT NULL',
        'quincena INT DEFAULT 0',
        "modulo VARCHAR(100) DEFAULT NULL",
    ];

    foreach ($columnas as $def) {
        try {
            $nombre = explode(' ', $def)[0];
            $existe = $pdo->query("SHOW COLUMNS FROM movimientos LIKE '$nombre'");
            if ($existe && $existe->rowCount() === 0) {
                $pdo->exec("ALTER TABLE movimientos ADD COLUMN $def");
            }
        } catch (Exception $e) {
            // Continuar sin interrumpir la operación.
        }
    }

    $fks = [
        [
            'tabla' => 'movimientos',
            'columna' => 'id_socio',
            'referencia' => 'socios',
            'col_referencia' => 'id_socio',
            'nombre' => 'fk_movimientos_socios',
        ],
        [
            'tabla' => 'movimientos',
            'columna' => 'id_actividad',
            'referencia' => 'actividades_maestro',
            'col_referencia' => 'id_actividad',
            'nombre' => 'fk_movimientos_actividades',
        ],
        [
            'tabla' => 'movimientos',
            'columna' => 'id_medio_pago',
            'referencia' => 'medios_pago',
            'col_referencia' => 'id',
            'nombre' => 'fk_movimientos_medios_pago',
        ],
        [
            'tabla' => 'prestamos',
            'columna' => 'id_socio',
            'referencia' => 'socios',
            'col_referencia' => 'id_socio',
            'nombre' => 'fk_prestamos_socios',
        ],
        [
            'tabla' => 'prestamos',
            'columna' => 'id_socio_aval',
            'referencia' => 'socios',
            'col_referencia' => 'id_socio',
            'nombre' => 'fk_prestamos_aval',
            'on_delete' => 'SET NULL',
        ],
        [
            'tabla' => 'cuotas_prestamo',
            'columna' => 'id_prestamo',
            'referencia' => 'prestamos',
            'col_referencia' => 'id_prestamo',
            'nombre' => 'fk_cuotas_prestamo',
        ],
        [
            'tabla' => 'periodos_prestamo',
            'columna' => 'id_prestamo',
            'referencia' => 'prestamos',
            'col_referencia' => 'id_prestamo',
            'nombre' => 'fk_periodos_prestamo',
        ],
        [
            'tabla' => 'periodos_prestamo_historial',
            'columna' => 'id_prestamo',
            'referencia' => 'prestamos',
            'col_referencia' => 'id_prestamo',
            'nombre' => 'fk_historial_periodos_prestamo',
        ],
        [
            'tabla' => 'conciliaciones_medios_pago',
            'columna' => 'id_medio',
            'referencia' => 'medios_pago',
            'col_referencia' => 'id',
            'nombre' => 'fk_conciliaciones_medio',
        ],
    ];

    foreach ($fks as $fk) {
        asegurarForeignKey($pdo, $fk);
    }
}

asegurarEsquemaMovimientos($pdo);

function asegurarEsquemaRetirosCaja(PDO $pdo): void {
    static $creada = false;
    if ($creada) {
        return;
    }

    $sql = "CREATE TABLE IF NOT EXISTS retiros_caja (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fecha DATE NOT NULL,
        valor DECIMAL(12,2) NOT NULL,
        medio VARCHAR(120) DEFAULT NULL,
        referencia VARCHAR(200) DEFAULT NULL,
        observaciones TEXT,
        usuario_registro VARCHAR(50) DEFAULT NULL,
        fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $pdo->exec($sql);
    $creada = true;
}

function registrarRetiroCaja(PDO $pdo, array $data): void {
    asegurarEsquemaRetirosCaja($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO retiros_caja (fecha, valor, medio, referencia, observaciones, usuario_registro)
         VALUES (:fecha, :valor, :medio, :referencia, :observaciones, :usuario)'
    );

    $stmt->execute([
        ':fecha' => $data['fecha'],
        ':valor' => $data['valor'],
        ':medio' => $data['medio'],
        ':referencia' => $data['referencia'],
        ':observaciones' => $data['observaciones'],
        ':usuario' => $data['usuario'],
    ]);
}

function obtenerRetirosCaja(PDO $pdo, ?string $desde = null, ?string $hasta = null): array {
    asegurarEsquemaRetirosCaja($pdo);

    $sql = 'SELECT * FROM retiros_caja WHERE 1=1';
    $params = [];

    if ($desde) {
        $sql .= ' AND fecha >= :desde';
        $params[':desde'] = $desde;
    }
    if ($hasta) {
        $sql .= ' AND fecha <= :hasta';
        $params[':hasta'] = $hasta;
    }

    $sql .= ' ORDER BY fecha DESC, id DESC LIMIT 300';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function getSocios($pdo, $search = '', string $orden = 'nombre', string $direccion = 'asc'): array {
    asegurarColumnaIdInternoSocios($pdo);
    asegurarColumnaGrupoSocios($pdo);

    $sql = "SELECT * FROM socios WHERE activo = 1";
    $params = [];
    if ($search !== '') {
        $sql .= " AND (nombre_completo LIKE :q OR telefono LIKE :q OR id_socio LIKE :q OR id_interno LIKE :q)";
        $params[':q'] = "%$search%";
    }

    $columnasOrdenables = [
        'id_interno' => 'id_interno',
        'nombre' => 'nombre_completo',
        'id_socio' => 'id_socio',
    ];

    $ordenColumna = $columnasOrdenables[$orden] ?? 'nombre_completo';
    $direccionNormalizada = strtolower($direccion) === 'desc' ? 'DESC' : 'ASC';

    $ordenNormalizado = "$ordenColumna $direccionNormalizada";

    if ($ordenColumna !== 'nombre_completo') {
        $ordenNormalizado .= ', nombre_completo';
    }

    $sql .= " ORDER BY $ordenNormalizado";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}



function getGruposSocios(PDO $pdo): array {
    asegurarColumnaGrupoSocios($pdo);
    $stmt = $pdo->query("SELECT DISTINCT grupo FROM socios WHERE activo = 1 AND grupo IS NOT NULL AND TRIM(grupo) <> '' ORDER BY grupo");
    return $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
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
        'es_rifa TINYINT(1) DEFAULT 0',
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

function asegurarColumnasConfiguracionGeneral(PDO $pdo): void {
    $columnas = [
        'reglamento_archivo VARCHAR(255) NULL',
        'tasa_interes_socio DECIMAL(6,2) DEFAULT 0',
        'tasa_interes_particular DECIMAL(6,2) DEFAULT 0',
        'actividad_pago_cuota INT NULL',
    ];

    foreach ($columnas as $definicion) {
        $nombre = explode(' ', $definicion)[0];

        try {
            $existe = $pdo->query("SHOW COLUMNS FROM configuracion_general LIKE '$nombre'");
            if ($existe && $existe->rowCount() === 0) {
                $pdo->exec("ALTER TABLE configuracion_general ADD COLUMN $definicion");
            }
        } catch (Exception $e) {
            // Continuar silenciosamente si no es posible crear la columna
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
        $columnasFlags = array_merge(array_fill_keys(array_keys($conceptos), 0), [
            'es_polla' => 0,
            'es_gasto_general' => 0,
        ]);
        $columnasFlags[$flag] = 1;

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
            foreach ($columnasFlags as $col => $valor) {
                $sets[] = "$col = :$col";
                $params[":$col"] = $valor;
            }
            $params[':id'] = $principal['id_actividad'];
            $sqlUpdate = 'UPDATE actividades_maestro SET ' . implode(', ', $sets) . ' WHERE id_actividad = :id';
            $pdo->prepare($sqlUpdate)->execute($params);
            $resultado[$flag] = (int) $principal['id_actividad'];
            continue;
        }

        $columnasInsert = array_merge($data, $columnasFlags, ['activo' => 1]);
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

function getActividades($pdo, $soloPolla = false, $incluirInactivas = false, $soloRifa = false) {
    asegurarEsquemaActividades($pdo);
    $sql = "SELECT * FROM actividades_maestro";
    $condiciones = [];
    if ($soloPolla) {
        $condiciones[] = "es_polla = 1";
    }
    if ($soloRifa) {
        $condiciones[] = "es_rifa = 1";
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

function recalcularPrestamoDesdeMovimientos(PDO $pdo, int $idPrestamo): void {
    $stmtPrestamo = $pdo->prepare('SELECT monto_prestamo FROM prestamos WHERE id_prestamo = :id');
    $stmtPrestamo->execute([':id' => $idPrestamo]);
    $prestamo = $stmtPrestamo->fetch();

    if (!$prestamo) {
        return;
    }

    $stmtCapital = $pdo->prepare(
        'SELECT COALESCE(SUM(ABS(m.valor)), 0) FROM movimientos m '
        . 'JOIN actividades_maestro a ON m.id_actividad = a.id_actividad '
        . 'WHERE m.id_prestamo = :id AND a.es_pago_prestamo = 1'
    );
    $stmtCapital->execute([':id' => $idPrestamo]);
    $capitalPagado = (float) $stmtCapital->fetchColumn();

    $stmtInteresPagado = $pdo->prepare(
        'SELECT COALESCE(SUM(ABS(m.valor)), 0) FROM movimientos m '
        . 'JOIN actividades_maestro a ON m.id_actividad = a.id_actividad '
        . 'WHERE m.id_prestamo = :id AND a.es_pago_interes = 1'
    );
    $stmtInteresPagado->execute([':id' => $idPrestamo]);
    $interesPagado = (float) $stmtInteresPagado->fetchColumn();

    $interesCausado = 0.0;
    try {
        $stmtInteresCausado = $pdo->prepare('SELECT COALESCE(SUM(interes_causado), 0) FROM periodos_prestamo WHERE id_prestamo = :id');
        $stmtInteresCausado->execute([':id' => $idPrestamo]);
        $interesCausado = (float) $stmtInteresCausado->fetchColumn();
    } catch (Exception $e) {
        $interesCausado = 0.0;
    }

    $saldoCapital = max(0, (float) $prestamo['monto_prestamo'] - $capitalPagado);
    $saldoInteres = max(0, $interesCausado - $interesPagado);

    $estado = 'Activo';
    if ($saldoCapital <= 0.01) {
        $estado = 'Finalizado';
    } elseif ($saldoInteres > 0.01) {
        $estado = 'En mora';
    }

    $pdo->prepare('UPDATE prestamos SET saldo_capital_actual = :cap, saldo_intereses_actual = :int, estado = :estado WHERE id_prestamo = :id')
        ->execute([
            ':cap' => $saldoCapital,
            ':int' => $saldoInteres,
            ':estado' => $estado,
            ':id' => $idPrestamo,
        ]);
}

function generarConciliacionInterna(PDO $pdo): array {
    $reporte = [
        'ok' => true,
        'checks' => [],
        'desviaciones_socios' => [],
        'desviaciones_prestamos' => [],
    ];

    $saldoMovimientos = (float) $pdo->query(
        "SELECT COALESCE(SUM(CASE" .
        " WHEN a.afecta_saldo_natillera = 'suma' THEN ABS(m.valor)" .
        " WHEN a.afecta_saldo_natillera = 'resta' THEN -ABS(m.valor)" .
        " ELSE 0 END), 0)" .
        " FROM movimientos m" .
        " JOIN actividades_maestro a ON m.id_actividad = a.id_actividad"
    )->fetchColumn();

    $saldoRegistrado = getSaldoNatillera($pdo);
    $diferenciaNatillera = round($saldoRegistrado - $saldoMovimientos, 2);

    $reporte['checks'][] = [
        'titulo' => 'Saldo general de la natillera',
        'detalle' => 'Comparación entre saldo guardado y suma neta de movimientos',
        'registrado' => $saldoRegistrado,
        'esperado' => $saldoMovimientos,
        'diferencia' => $diferenciaNatillera,
        'ok' => abs($diferenciaNatillera) < 0.01,
    ];

    if (abs($diferenciaNatillera) >= 0.01) {
        $reporte['ok'] = false;
    }

    $saldosCalculados = [];
    $stmtSaldos = $pdo->query(
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

    foreach ($stmtSaldos->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $saldosCalculados[(int) $fila['id_socio']] = (float) ($fila['saldo'] ?? 0);
    }

    $desviaciones = [];
    $stmtSocios = $pdo->query('SELECT id_socio, nombre_completo, saldo_socio FROM socios');
    foreach ($stmtSocios->fetchAll(PDO::FETCH_ASSOC) as $socio) {
        $esperado = $saldosCalculados[(int) $socio['id_socio']] ?? 0.0;
        $registrado = (float) $socio['saldo_socio'];
        $diferencia = round($registrado - $esperado, 2);

        if (abs($diferencia) >= 0.01) {
            $desviaciones[] = [
                'id' => (int) $socio['id_socio'],
                'nombre' => $socio['nombre_completo'],
                'registrado' => $registrado,
                'esperado' => $esperado,
                'diferencia' => $diferencia,
            ];
        }
    }

    $reporte['desviaciones_socios'] = $desviaciones;
    $reporte['checks'][] = [
        'titulo' => 'Saldos de socios',
        'detalle' => 'Verificación de saldo almacenado vs. saldo reconstruido por movimientos',
        'registrado' => count($desviaciones) ? 'Hay diferencias' : 'Sin diferencias',
        'esperado' => 'Sin diferencias',
        'diferencia' => count($desviaciones),
        'ok' => count($desviaciones) === 0,
    ];

    if (!empty($desviaciones)) {
        $reporte['ok'] = false;
    }

    $prestamosConDesviaciones = [];

    $stmtPrestamos = $pdo->query(
        'SELECT p.id_prestamo, p.monto_prestamo, p.saldo_capital_actual, p.saldo_intereses_actual, '
        . 'COALESCE(s.nombre_completo, p.nombre_deudor) AS deudor '
        . 'FROM prestamos p '
        . 'LEFT JOIN socios s ON p.id_socio = s.id_socio'
    );

    $stmtCapitalPagado = $pdo->prepare(
        'SELECT COALESCE(SUM(ABS(m.valor)), 0) FROM movimientos m '
        . 'JOIN actividades_maestro a ON m.id_actividad = a.id_actividad '
        . 'WHERE m.id_prestamo = :id AND a.es_pago_prestamo = 1'
    );

    $stmtInteresPagado = $pdo->prepare(
        'SELECT COALESCE(SUM(ABS(m.valor)), 0) FROM movimientos m '
        . 'JOIN actividades_maestro a ON m.id_actividad = a.id_actividad '
        . 'WHERE m.id_prestamo = :id AND a.es_pago_interes = 1'
    );

    $stmtInteresCausado = $pdo->prepare(
        'SELECT COALESCE(SUM(interes_causado), 0) FROM periodos_prestamo WHERE id_prestamo = :id'
    );

    foreach ($stmtPrestamos->fetchAll(PDO::FETCH_ASSOC) as $prestamo) {
        $stmtCapitalPagado->execute([':id' => $prestamo['id_prestamo']]);
        $capitalPagado = (float) $stmtCapitalPagado->fetchColumn();

        $stmtInteresPagado->execute([':id' => $prestamo['id_prestamo']]);
        $interesPagado = (float) $stmtInteresPagado->fetchColumn();

        $stmtInteresCausado->execute([':id' => $prestamo['id_prestamo']]);
        $interesCausado = (float) $stmtInteresCausado->fetchColumn();

        $capitalEsperado = round((float) $prestamo['monto_prestamo'] - $capitalPagado, 2);
        $interesEsperado = round($interesCausado - $interesPagado, 2);

        $diferenciaCapital = round((float) $prestamo['saldo_capital_actual'] - $capitalEsperado, 2);
        $diferenciaInteres = round((float) $prestamo['saldo_intereses_actual'] - $interesEsperado, 2);

        if (abs($diferenciaCapital) >= 0.01 || abs($diferenciaInteres) >= 0.01) {
            $prestamosConDesviaciones[] = [
                'id' => (int) $prestamo['id_prestamo'],
                'deudor' => $prestamo['deudor'] ?? 'Sin identificar',
                'capital_registrado' => (float) $prestamo['saldo_capital_actual'],
                'capital_esperado' => $capitalEsperado,
                'diferencia_capital' => $diferenciaCapital,
                'interes_registrado' => (float) $prestamo['saldo_intereses_actual'],
                'interes_esperado' => $interesEsperado,
                'diferencia_interes' => $diferenciaInteres,
            ];
        }
    }

    $reporte['desviaciones_prestamos'] = $prestamosConDesviaciones;
    $reporte['checks'][] = [
        'titulo' => 'Préstamos alineados con movimientos',
        'detalle' => 'Saldos de capital e intereses coinciden con los movimientos y periodos registrados',
        'registrado' => count($prestamosConDesviaciones) ? 'Hay diferencias' : 'Sin diferencias',
        'esperado' => 'Sin diferencias',
        'diferencia' => count($prestamosConDesviaciones),
        'ok' => count($prestamosConDesviaciones) === 0,
    ];

    if (!empty($prestamosConDesviaciones)) {
        $reporte['ok'] = false;
    }

    $huerfanosPeriodos = (int) $pdo->query(
        'SELECT COUNT(*) FROM periodos_prestamo pp LEFT JOIN prestamos p ON pp.id_prestamo = p.id_prestamo ' .
        'WHERE p.id_prestamo IS NULL'
    )->fetchColumn();

    $huerfanosCuotas = (int) $pdo->query(
        'SELECT COUNT(*) FROM cuotas_prestamo cp LEFT JOIN prestamos p ON cp.id_prestamo = p.id_prestamo ' .
        'WHERE p.id_prestamo IS NULL'
    )->fetchColumn();

    $reporte['checks'][] = [
        'titulo' => 'Integridad de préstamos',
        'detalle' => 'Cuotas y periodos sin préstamo asociado',
        'registrado' => $huerfanosPeriodos + $huerfanosCuotas,
        'esperado' => 0,
        'diferencia' => $huerfanosPeriodos + $huerfanosCuotas,
        'ok' => ($huerfanosPeriodos + $huerfanosCuotas) === 0,
    ];

    if (($huerfanosPeriodos + $huerfanosCuotas) > 0) {
        $reporte['ok'] = false;
    }

    $movimientosSinMedio = (int) $pdo->query(
        'SELECT COUNT(*) FROM movimientos m WHERE m.id_medio_pago IS NOT NULL ' .
        'AND NOT EXISTS (SELECT 1 FROM medios_pago mp WHERE mp.id = m.id_medio_pago)'
    )->fetchColumn();

    $reporte['checks'][] = [
        'titulo' => 'Movimientos con medio de pago válido',
        'detalle' => 'Verifica que cada movimiento tenga medio de pago vigente cuando aplica',
        'registrado' => $movimientosSinMedio,
        'esperado' => 0,
        'diferencia' => $movimientosSinMedio,
        'ok' => $movimientosSinMedio === 0,
    ];

    if ($movimientosSinMedio > 0) {
        $reporte['ok'] = false;
    }

    return $reporte;
}

function getActividad($pdo, $id) {
    asegurarEsquemaActividades($pdo);
    $stmt = $pdo->prepare("SELECT * FROM actividades_maestro WHERE id_actividad = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch();
}

function getConfiguracionGeneral($pdo) {
    asegurarColumnasConfiguracionGeneral($pdo);

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
            'actividad_pago_cuota' => null,
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
    if (!array_key_exists('actividad_pago_cuota', $config)) {
        $config['actividad_pago_cuota'] = null;
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

function getPeriodosActivosConfiguracion(PDO $pdo): array {
    $stmt = $pdo->query('SELECT id, anio, mes FROM periodos_configuracion WHERE activo = 1 ORDER BY anio ASC, mes ASC');
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

function getUsuarios(PDO $pdo): array {
    $stmt = $pdo->query('SELECT id, usuario, rol FROM usuarios ORDER BY usuario ASC');
    return $stmt->fetchAll();
}

function getUsuarioPorId(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT id, usuario, rol FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $usuario = $stmt->fetch();

    return $usuario ?: null;
}

function getUsuarioPorNombre(PDO $pdo, string $nombre): ?array {
    $stmt = $pdo->prepare('SELECT id, usuario, rol FROM usuarios WHERE usuario = :nombre');
    $stmt->execute([':nombre' => $nombre]);
    $usuario = $stmt->fetch();

    return $usuario ?: null;
}

function actualizarPasswordUsuario(PDO $pdo, int $id, string $passwordPlano): void {
    $hash = password_hash($passwordPlano, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare('UPDATE usuarios SET contraseña_hash = :hash WHERE id = :id');
    $stmt->execute([
        ':hash' => $hash,
        ':id' => $id,
    ]);
}

function crearUsuario(PDO $pdo, string $nombre, string $passwordPlano, string $rol = 'admin'): int {
    $hash = password_hash($passwordPlano, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare('INSERT INTO usuarios (usuario, contraseña_hash, rol) VALUES (:usuario, :hash, :rol)');
    $stmt->execute([
        ':usuario' => $nombre,
        ':hash' => $hash,
        ':rol' => $rol,
    ]);

    return (int) $pdo->lastInsertId();
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
