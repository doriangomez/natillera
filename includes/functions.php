<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/prestamos_helpers.php';
require_once __DIR__ . '/../migrations/20260628_add_actividad_contrapartida.php';

function clean($value) {
    return htmlspecialchars(trim((string)$value), ENT_QUOTES, 'UTF-8');
}

function asegurarEsquemaBolsaAdministracion(PDO $pdo): void {
    static $asegurada = false;
    if ($asegurada) {
        return;
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS bolsa_administracion (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_socio INT DEFAULT NULL,
            id_movimiento INT DEFAULT NULL,
            id_liquidacion INT DEFAULT NULL,
            fecha DATE NOT NULL,
            valor DECIMAL(12,2) NOT NULL DEFAULT 0,
            concepto VARCHAR(255) NOT NULL,
            observaciones TEXT DEFAULT NULL,
            usuario_registro VARCHAR(100) DEFAULT NULL,
            fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_bolsa_admin_socio (id_socio),
            INDEX idx_bolsa_admin_movimiento (id_movimiento),
            INDEX idx_bolsa_admin_liquidacion (id_liquidacion)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        // No interrumpir el flujo principal si el motor no permite crear la tabla.
    }

    $asegurada = true;
}

function registrarBolsaAdministracion(PDO $pdo, ?int $idSocio, ?int $idMovimiento, ?int $idLiquidacion, string $fecha, float $valor, string $concepto, ?string $observaciones = null, ?string $usuario = null): void {
    asegurarEsquemaBolsaAdministracion($pdo);
    $stmt = $pdo->prepare('INSERT INTO bolsa_administracion (id_socio, id_movimiento, id_liquidacion, fecha, valor, concepto, observaciones, usuario_registro) VALUES (:socio, :movimiento, :liquidacion, :fecha, :valor, :concepto, :observaciones, :usuario)');
    $stmt->execute([
        ':socio' => $idSocio,
        ':movimiento' => $idMovimiento,
        ':liquidacion' => $idLiquidacion,
        ':fecha' => $fecha,
        ':valor' => $valor,
        ':concepto' => $concepto,
        ':observaciones' => $observaciones,
        ':usuario' => $usuario,
    ]);
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
        "id_liquidacion INT DEFAULT NULL",
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

function asegurarEsquemaLiquidaciones(PDO $pdo): void {
    static $creada = false;
    if ($creada) {
        return;
    }

    $sql = "CREATE TABLE IF NOT EXISTS liquidaciones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        socio_id INT NOT NULL,
        tipo_liquidacion VARCHAR(20) NOT NULL,
        saldo_base DECIMAL(12,2) DEFAULT 0,
        valor_pollas DECIMAL(12,2) DEFAULT 0,
        valor_prestamos DECIMAL(12,2) DEFAULT 0,
        valor_cuota_manejo DECIMAL(12,2) DEFAULT 0,
        valor_aplicado_deuda DECIMAL(12,2) DEFAULT 0,
        deficit DECIMAL(12,2) DEFAULT 0,
        intereses_cubiertos DECIMAL(12,2) DEFAULT 0,
        capital_cubierto DECIMAL(12,2) DEFAULT 0,
        valor_bruto DECIMAL(12,2) DEFAULT 0,
        valor_neto DECIMAL(12,2) DEFAULT 0,
        actividad_liquidacion_id INT DEFAULT NULL,
        actividad_cuota_id INT DEFAULT NULL,
        actividad_fondo_id INT DEFAULT NULL,
        movimiento_liquidacion_id INT DEFAULT NULL,
        movimiento_cuota_id INT DEFAULT NULL,
        movimiento_fondo_id INT DEFAULT NULL,
        id_movimiento_compensacion INT DEFAULT NULL,
        ids_prestamos_afectados TEXT DEFAULT NULL,
        movimientos_generados TEXT DEFAULT NULL,
        detalle_preliquidacion TEXT DEFAULT NULL,
        fecha_preliquidacion DATETIME DEFAULT NULL,
        observaciones TEXT,
        fecha DATE NOT NULL,
        usuario_id VARCHAR(50) DEFAULT NULL,
        estado VARCHAR(20) DEFAULT 'activa',
        creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
        actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_liquidaciones_socio FOREIGN KEY (socio_id) REFERENCES socios(id_socio) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $pdo->exec($sql);

    try {
        $columnas = [
            'estado_anterior_socio' => "ALTER TABLE liquidaciones ADD COLUMN estado_anterior_socio TEXT DEFAULT NULL AFTER socio_id",
            'prestamo_nuevo_id' => "ALTER TABLE liquidaciones ADD COLUMN prestamo_nuevo_id INT DEFAULT NULL AFTER ids_prestamos_afectados",
            'saldo_pendiente' => "ALTER TABLE liquidaciones ADD COLUMN saldo_pendiente DECIMAL(12,2) DEFAULT 0 AFTER deficit",
            'fecha_reverso' => "ALTER TABLE liquidaciones ADD COLUMN fecha_reverso DATETIME DEFAULT NULL AFTER estado",
            'usuario_reverso' => "ALTER TABLE liquidaciones ADD COLUMN usuario_reverso VARCHAR(50) DEFAULT NULL AFTER fecha_reverso",
            'motivo_reverso' => "ALTER TABLE liquidaciones ADD COLUMN motivo_reverso TEXT DEFAULT NULL AFTER usuario_reverso",
            'movimientos_generados' => "ALTER TABLE liquidaciones ADD COLUMN movimientos_generados TEXT DEFAULT NULL AFTER movimiento_fondo_id",
            'detalle_preliquidacion' => "ALTER TABLE liquidaciones ADD COLUMN detalle_preliquidacion TEXT DEFAULT NULL AFTER movimientos_generados",
            'fecha_preliquidacion' => "ALTER TABLE liquidaciones ADD COLUMN fecha_preliquidacion DATETIME DEFAULT NULL AFTER detalle_preliquidacion",
            'valor_aplicado_deuda' => "ALTER TABLE liquidaciones ADD COLUMN valor_aplicado_deuda DECIMAL(12,2) DEFAULT 0 AFTER valor_cuota_manejo",
            'deficit' => "ALTER TABLE liquidaciones ADD COLUMN deficit DECIMAL(12,2) DEFAULT 0 AFTER valor_aplicado_deuda",
            'intereses_cubiertos' => "ALTER TABLE liquidaciones ADD COLUMN intereses_cubiertos DECIMAL(12,2) DEFAULT 0 AFTER deficit",
            'capital_cubierto' => "ALTER TABLE liquidaciones ADD COLUMN capital_cubierto DECIMAL(12,2) DEFAULT 0 AFTER intereses_cubiertos",
            'id_movimiento_compensacion' => "ALTER TABLE liquidaciones ADD COLUMN id_movimiento_compensacion INT DEFAULT NULL AFTER movimiento_fondo_id",
            'ids_prestamos_afectados' => "ALTER TABLE liquidaciones ADD COLUMN ids_prestamos_afectados TEXT DEFAULT NULL AFTER id_movimiento_compensacion",
        ];
        foreach ($columnas as $columna => $alterSql) {
            $resultado = $pdo->query("SHOW COLUMNS FROM liquidaciones LIKE '" . $columna . "'");
            if ($resultado && $resultado->rowCount() === 0) {
                $pdo->exec($alterSql);
            }
        }

        $columnasSocios = [
            'estado_socio' => "ALTER TABLE socios ADD COLUMN estado_socio VARCHAR(60) DEFAULT 'Activo' AFTER activo",
            'clasificacion' => "ALTER TABLE socios ADD COLUMN clasificacion VARCHAR(120) DEFAULT NULL AFTER estado_socio",
        ];
        foreach ($columnasSocios as $columna => $alterSql) {
            $resultado = $pdo->query("SHOW COLUMNS FROM socios LIKE '" . $columna . "'");
            if ($resultado && $resultado->rowCount() === 0) {
                $pdo->exec($alterSql);
            }
        }
        $columnasPrestamos = [
            'clasificacion_cartera' => "ALTER TABLE prestamos ADD COLUMN clasificacion_cartera VARCHAR(120) DEFAULT NULL AFTER estado",
            'prestamo_origen_liquidacion_id' => "ALTER TABLE prestamos ADD COLUMN prestamo_origen_liquidacion_id INT DEFAULT NULL AFTER clasificacion_cartera",
        ];
        foreach ($columnasPrestamos as $columna => $alterSql) {
            $resultado = $pdo->query("SHOW COLUMNS FROM prestamos LIKE '" . $columna . "'");
            if ($resultado && $resultado->rowCount() === 0) {
                $pdo->exec($alterSql);
            }
        }
    } catch (Exception $e) {
        // Continuar sin bloquear el módulo si no es posible alterar estructura.
    }

    $creada = true;
}

function obtenerTiposLiquidacion(): array {
    return [
        'parcial' => 'Liquidación parcial',
        'anticipada' => 'Liquidación definitiva anticipada',
        'definitiva' => 'Liquidación definitiva',
    ];
}

function normalizarConceptosDeudaLiquidacion(array $idsActividades, array $valores, array $actividades = []): array {
    $nombresActividades = [];
    foreach ($actividades as $actividad) {
        $idActividad = (int) ($actividad['id_actividad'] ?? 0);
        if ($idActividad > 0) {
            $nombresActividades[$idActividad] = (string) ($actividad['nombre_actividad'] ?? '');
        }
    }

    $conceptos = [];
    foreach ($valores as $indice => $valorRaw) {
        $idActividad = (int) ($idsActividades[$indice] ?? 0);
        $valor = max(0, (float) $valorRaw);
        if ($idActividad <= 0 || $valor <= 0) {
            continue;
        }
        $conceptos[] = [
            'id_actividad' => $idActividad,
            'nombre_actividad' => $nombresActividades[$idActividad] ?? '',
            'valor' => $valor,
        ];
    }
    return $conceptos;
}

function calcularLiquidacionSocio(PDO $pdo, int $idSocio, float $cuotaManejo, array $otrosConceptosDeuda = []): ?array {
    asegurarEsquemaLiquidaciones($pdo);

    $stmtSocio = $pdo->prepare('SELECT id_socio, id_interno, nombre_completo, saldo_socio, activo FROM socios WHERE id_socio = :id');
    $stmtSocio->execute([':id' => $idSocio]);
    $socio = $stmtSocio->fetch(PDO::FETCH_ASSOC);
    if (!$socio || (int) ($socio['activo'] ?? 0) !== 1) {
        return null;
    }

    $stmtPollas = $pdo->prepare(
        "SELECT COALESCE(SUM(ABS(m.valor)),0)
         FROM movimientos m
         JOIN actividades_maestro a ON a.id_actividad = m.id_actividad
         WHERE m.id_socio = :id AND COALESCE(a.es_polla,0) = 1"
    );
    $stmtPollas->execute([':id' => $idSocio]);
    $valorPollas = (float) $stmtPollas->fetchColumn();

    $stmtAhorroBruto = $pdo->prepare(
        "SELECT COALESCE(SUM(
            CASE
                WHEN a.afecta_saldo_socio = 'suma' THEN ABS(m.valor)
                WHEN a.afecta_saldo_socio = 'resta' THEN -ABS(m.valor)
                ELSE 0
            END
        ), 0)
         FROM movimientos m
         JOIN actividades_maestro a ON a.id_actividad = m.id_actividad
         WHERE m.id_socio = :id
           AND COALESCE(a.es_prestamo,0) = 0
           AND COALESCE(a.es_pago_prestamo,0) = 0
           AND COALESCE(a.es_pago_interes,0) = 0
           AND COALESCE(a.es_polla,0) = 0
           AND COALESCE(m.modulo, '') <> 'liquidaciones'"
    );
    $stmtAhorroBruto->execute([':id' => $idSocio]);
    $ahorroAcumuladoBruto = (float) $stmtAhorroBruto->fetchColumn();
    $rendimientos = 0.0;

    $stmtPrestamos = $pdo->prepare(
        "SELECT id_prestamo, estado, saldo_capital_actual, saldo_intereses_actual
         FROM prestamos
         WHERE id_socio = :id AND estado IN ('Activo','En mora')
         ORDER BY id_prestamo ASC"
    );
    $stmtPrestamos->execute([':id' => $idSocio]);
    $prestamos = [];
    $valorPrestamos = 0.0;
    foreach ($stmtPrestamos->fetchAll(PDO::FETCH_ASSOC) as $prestamo) {
        $capital = (float) ($prestamo['saldo_capital_actual'] ?? 0);
        $intereses = (float) ($prestamo['saldo_intereses_actual'] ?? 0);
        $total = $capital + $intereses;
        $prestamos[] = [
            'id_prestamo' => (int) $prestamo['id_prestamo'],
            'estado' => (string) $prestamo['estado'],
            'capital_pendiente' => $capital,
            'intereses_pendientes' => $intereses,
            'total_pendiente' => $total,
        ];
        $valorPrestamos += $total;
    }

    $saldoActualSocio = (float) ($socio['saldo_socio'] ?? 0);
    $saldoBase = $ahorroAcumuladoBruto;
    $cuotaManejo = max(0, $cuotaManejo);
    $otrosConceptosDeuda = array_values(array_filter(array_map(static function ($concepto) {
        $idActividad = (int) ($concepto['id_actividad'] ?? 0);
        $nombreActividad = trim((string) ($concepto['nombre_actividad'] ?? ''));
        $valor = max(0, (float) ($concepto['valor'] ?? 0));
        if ($idActividad <= 0 || $valor <= 0) {
            return null;
        }
        return ['id_actividad' => $idActividad, 'nombre_actividad' => $nombreActividad, 'valor' => $valor];
    }, $otrosConceptosDeuda)));
    $totalOtrosConceptosDeuda = array_sum(array_column($otrosConceptosDeuda, 'valor'));
    $deudaTotal = $valorPrestamos + $cuotaManejo + $totalOtrosConceptosDeuda;
    $saldoLiquidacion = $ahorroAcumuladoBruto + $rendimientos - $deudaTotal;
    $valorAplicadoDeuda = min(max(0, $ahorroAcumuladoBruto + $rendimientos), $valorPrestamos);
    $deficit = max(0, abs(min(0, $saldoLiquidacion)));
    $valorBruto = $saldoBase - $valorPrestamos;
    $valorNeto = max(0, $saldoLiquidacion);
    $fechaPreliquidacion = date('Y-m-d H:i:s');

    return [
        'socio' => $socio,
        'saldo_base' => $saldoBase,
        'saldo_actual_socio' => $saldoActualSocio,
        'ahorro_acumulado_bruto' => $ahorroAcumuladoBruto,
        'rendimientos' => $rendimientos,
        'valor_pollas' => $valorPollas,
        'valor_prestamos' => $valorPrestamos,
        'deuda_total' => $deudaTotal,
        'saldo_liquidacion' => $saldoLiquidacion,
        'prestamos_descontados' => $prestamos,
        'valor_cuota_manejo' => $cuotaManejo,
        'otros_conceptos_deuda' => $otrosConceptosDeuda,
        'total_otros_conceptos_deuda' => $totalOtrosConceptosDeuda,
        'valor_aplicado_deuda' => $valorAplicadoDeuda,
        'deficit' => $deficit,
        'valor_bruto' => $valorBruto,
        'valor_neto' => $valorNeto,
        'fecha_preliquidacion' => $fechaPreliquidacion,
    ];
}

function obtenerActividadCompensacionLiquidacionPrestamo(PDO $pdo): array {
    asegurarEsquemaActividades($pdo);

    $nombre = 'Compensación Liquidación a Préstamo';
    $stmt = $pdo->prepare('SELECT * FROM actividades_maestro WHERE nombre_actividad = :nombre LIMIT 1');
    $stmt->execute([':nombre' => $nombre]);
    $actividad = $stmt->fetch(PDO::FETCH_ASSOC);

    $data = [
        'nombre_actividad' => $nombre,
        'descripcion' => 'Compensación automática de saldo de liquidación contra deudas activas de préstamos',
        'afecta_saldo_socio' => 'resta',
        'afecta_saldo_natillera' => 'resta',
        'es_ingreso' => 0,
        'es_prestamo' => 0,
        'es_pago_prestamo' => 0,
        'es_pago_interes' => 0,
        'es_polla' => 0,
        'activo' => 1,
    ];

    if ($actividad) {
        $sets = [];
        $params = [':id' => (int) $actividad['id_actividad']];
        foreach ($data as $col => $valor) {
            $sets[] = "$col = :$col";
            $params[":$col"] = $valor;
        }
        $pdo->prepare('UPDATE actividades_maestro SET ' . implode(', ', $sets) . ' WHERE id_actividad = :id')->execute($params);
        $stmt->execute([':nombre' => $nombre]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    $campos = array_keys($data);
    $placeholders = array_map(static fn($c) => ':' . $c, $campos);
    $pdo->prepare('INSERT INTO actividades_maestro (' . implode(',', $campos) . ') VALUES (' . implode(',', $placeholders) . ')')
        ->execute(array_combine($placeholders, array_values($data)));

    $stmt->execute([':nombre' => $nombre]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
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
        'es_prestamo TINYINT(1) DEFAULT 0',
        'es_pago_prestamo TINYINT(1) DEFAULT 0',
        'es_pago_interes TINYINT(1) DEFAULT 0',
        'es_interes_causado TINYINT(1) DEFAULT 0',
        'es_polla TINYINT(1) DEFAULT 0',
        'es_gasto_general TINYINT(1) DEFAULT 0',
        'es_rifa TINYINT(1) DEFAULT 0',
        'categoria VARCHAR(150) NULL',
        'id_actividad_contrapartida INT NULL',
    ];

    migrarActividadContrapartida($pdo);

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

function obtenerDefinicionesConceptosPrestamo(): array {
    return [
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
}

function sincronizarConceptosPrestamo(PDO $pdo): array {
    asegurarEsquemaActividades($pdo);

    $conceptos = obtenerDefinicionesConceptosPrestamo();
    $flagsUnicos = ['es_prestamo', 'es_interes_causado'];
    $resultado = [];

    foreach ($conceptos as $flag => $data) {
        $columnasFlags = array_merge(array_fill_keys(array_keys($conceptos), 0), [
            'es_polla' => 0,
            'es_gasto_general' => 0,
        ]);
        $columnasFlags[$flag] = 1;

        $stmtNombre = $pdo->prepare('SELECT * FROM actividades_maestro WHERE nombre_actividad = :nombre LIMIT 1');
        $stmtNombre->execute([':nombre' => $data['nombre_actividad']]);
        $principal = $stmtNombre->fetch();

        if (!$principal) {
            $stmtBandera = $pdo->prepare("SELECT * FROM actividades_maestro WHERE $flag = 1 ORDER BY id_actividad ASC");
            $stmtBandera->execute();
            $existentes = $stmtBandera->fetchAll();
            $principal = $existentes[0] ?? null;
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
        } else {
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

        if (in_array($flag, $flagsUnicos, true)) {
            $pdo->prepare("UPDATE actividades_maestro SET $flag = 0 WHERE $flag = 1 AND id_actividad <> :id")
                ->execute([':id' => $resultado[$flag]]);
        }
    }

    return $resultado;
}

function sincronizarConceptosLiquidacionPrestamo(PDO $pdo): array {
    asegurarEsquemaActividades($pdo);

    $conceptosBase = obtenerDefinicionesConceptosPrestamo();
    $columnasBase = array_merge(array_fill_keys(array_keys($conceptosBase), 0), [
        'es_polla' => 0,
        'es_gasto_general' => 0,
    ]);

    $conceptos = [
        'pago_interes_liquidacion' => array_merge($columnasBase, [
            'nombre_actividad' => 'Pago de intereses por liquidación',
            'descripcion' => 'Pago de intereses de préstamo aplicado desde liquidación de socio',
            'afecta_saldo_socio' => 'neutral',
            'afecta_saldo_natillera' => 'resta',
            'es_ingreso' => 0,
            'es_pago_interes' => 1,
            'activo' => 1,
        ]),
        'pago_capital_liquidacion' => array_merge($columnasBase, [
            'nombre_actividad' => 'Pago de capital por liquidación',
            'descripcion' => 'Pago de capital de préstamo aplicado desde liquidación de socio',
            'afecta_saldo_socio' => 'resta',
            'afecta_saldo_natillera' => 'neutral',
            'es_ingreso' => 0,
            'es_pago_prestamo' => 1,
            'activo' => 1,
        ]),
    ];

    $resultado = [];
    foreach ($conceptos as $clave => $data) {
        $stmt = $pdo->prepare('SELECT * FROM actividades_maestro WHERE nombre_actividad = :nombre LIMIT 1');
        $stmt->execute([':nombre' => $data['nombre_actividad']]);
        $actividad = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($actividad) {
            $sets = [];
            $params = [':id' => (int) $actividad['id_actividad']];
            foreach ($data as $col => $valor) {
                $sets[] = "$col = :$col";
                $params[":$col"] = $valor;
            }
            $pdo->prepare('UPDATE actividades_maestro SET ' . implode(', ', $sets) . ' WHERE id_actividad = :id')->execute($params);
        } else {
            $campos = array_keys($data);
            $placeholders = array_map(static fn($c) => ':' . $c, $campos);
            $params = [];
            foreach ($data as $col => $valor) {
                $params[":$col"] = $valor;
            }
            $pdo->prepare('INSERT INTO actividades_maestro (' . implode(',', $campos) . ') VALUES (' . implode(',', $placeholders) . ')')
                ->execute($params);
        }

        $stmt->execute([':nombre' => $data['nombre_actividad']]);
        $resultado[$clave] = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    return $resultado;
}

function obtenerConceptoPorBandera(PDO $pdo, string $flag): ?array {
    $permitidos = ['es_prestamo', 'es_pago_prestamo', 'es_pago_interes', 'es_interes_causado'];
    if (!in_array($flag, $permitidos, true)) {
        return null;
    }

    $conceptos = obtenerDefinicionesConceptosPrestamo();
    sincronizarConceptosPrestamo($pdo);

    $stmt = $pdo->prepare("SELECT * FROM actividades_maestro WHERE $flag = 1 AND nombre_actividad = :nombre LIMIT 1");
    $stmt->execute([':nombre' => $conceptos[$flag]['nombre_actividad']]);
    $principal = $stmt->fetch();
    if ($principal) {
        return $principal;
    }

    $stmt = $pdo->prepare("SELECT * FROM actividades_maestro WHERE $flag = 1 ORDER BY id_actividad ASC LIMIT 1");
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

function getCategoriasActividades(PDO $pdo): array {
    asegurarEsquemaActividades($pdo);
    $stmt = $pdo->query("SELECT DISTINCT TRIM(categoria) AS categoria FROM actividades_maestro WHERE categoria IS NOT NULL AND TRIM(categoria) <> '' ORDER BY categoria");
    return $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
}

function getActividades($pdo, $soloPolla = false, $incluirInactivas = false, $soloRifa = false) {
    asegurarEsquemaActividades($pdo);
    $sql = "SELECT a.*, c.nombre_actividad AS nombre_contrapartida FROM actividades_maestro a LEFT JOIN actividades_maestro c ON c.id_actividad = a.id_actividad_contrapartida";
    $condiciones = [];
    if ($soloPolla) {
        $condiciones[] = "a.es_polla = 1";
    }
    if ($soloRifa) {
        $condiciones[] = "a.es_rifa = 1";
    }
    if (!$incluirInactivas) {
        $condiciones[] = "a.activo = 1";
    }
    if ($condiciones) {
        $sql .= ' WHERE ' . implode(' AND ', $condiciones);
    }
    $sql .= " ORDER BY a.nombre_actividad";
    return $pdo->query($sql)->fetchAll();
}

function obtenerNaturalezaActividad(array $actividad): string {
    if (!empty($actividad['es_prestamo'])) {
        return 'prestamo';
    }
    if (!empty($actividad['es_pago_prestamo'])) {
        return 'pago_prestamo';
    }
    if (!empty($actividad['es_pago_interes'])) {
        return 'pago_interes';
    }
    if (!empty($actividad['es_interes_causado'])) {
        return 'interes_causado';
    }
    if (!empty($actividad['es_ingreso'])) {
        return 'ingreso';
    }

    return 'egreso';
}

function actividadValidaParaCausacion(array $actividad): bool {
    return (int) ($actividad['activo'] ?? 0) === 1
        && obtenerNaturalezaActividad($actividad) === 'ingreso';
}

function actividadValidaParaPremioRifa(array $actividad): bool {
    if ((int) ($actividad['activo'] ?? 0) !== 1) {
        return false;
    }

    return in_array(obtenerNaturalezaActividad($actividad), ['egreso'], true);
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
    $iniciaTransaccion = !$pdo->inTransaction();
    try {
        if ($iniciaTransaccion) {
            $pdo->beginTransaction();
        }

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

        if ($iniciaTransaccion && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($iniciaTransaccion && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
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
        'posibles_pagos_duplicados' => [],
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


    $stmtDuplicados = $pdo->query(
        "SELECT m.id_movimiento, m.id_socio, COALESCE(s.nombre_completo, CONCAT('Socio #', m.id_socio)) AS socio, " .
        "m.fecha, m.id_actividad, COALESCE(a.nombre_actividad, CONCAT('Actividad #', m.id_actividad)) AS actividad, " .
        "m.valor, m.fecha_registro " .
        "FROM movimientos m " .
        "JOIN (" .
        "SELECT id_socio, fecha, id_actividad, valor " .
        "FROM movimientos " .
        "WHERE id_socio IS NOT NULL AND fecha_registro IS NOT NULL " .
        "GROUP BY id_socio, fecha, id_actividad, valor HAVING COUNT(*) > 1" .
        ") repetidos ON repetidos.id_socio = m.id_socio " .
        "AND repetidos.fecha = m.fecha " .
        "AND repetidos.id_actividad = m.id_actividad " .
        "AND repetidos.valor = m.valor " .
        "LEFT JOIN socios s ON s.id_socio = m.id_socio " .
        "LEFT JOIN actividades_maestro a ON a.id_actividad = m.id_actividad " .
        "WHERE m.fecha_registro IS NOT NULL " .
        "ORDER BY m.id_socio, m.fecha, m.id_actividad, m.valor, m.fecha_registro, m.id_movimiento"
    );

    $posiblesPagosDuplicados = [];
    $grupoActual = [];
    $claveActual = null;
    $registroAnterior = null;
    $cerrarGrupoDuplicado = static function (array $grupo) use (&$posiblesPagosDuplicados): void {
        if (count($grupo) < 2) {
            return;
        }

        $ids = [];
        $registros = [];
        foreach ($grupo as $movimiento) {
            $ids[] = (int) $movimiento['id_movimiento'];
            $registros[] = $movimiento['fecha_registro'];
        }

        $primero = $grupo[0];
        $posiblesPagosDuplicados[] = [
            'ids_movimiento' => $ids,
            'socio' => $primero['socio'],
            'fecha' => $primero['fecha'],
            'actividad' => $primero['actividad'],
            'valor' => (float) $primero['valor'],
            'fechas_registro' => $registros,
            'minutos_ventana' => (int) floor((strtotime(end($registros)) - strtotime($registros[0])) / 60),
        ];
    };

    foreach ($stmtDuplicados->fetchAll(PDO::FETCH_ASSOC) as $movimiento) {
        $clave = implode('|', [$movimiento['id_socio'], $movimiento['fecha'], $movimiento['id_actividad'], $movimiento['valor']]);
        $registro = strtotime($movimiento['fecha_registro']);
        if ($claveActual !== $clave || ($registroAnterior !== null && ($registro - $registroAnterior) >= 1800)) {
            $cerrarGrupoDuplicado($grupoActual);
            $grupoActual = [];
            $claveActual = $clave;
        }

        $grupoActual[] = $movimiento;
        $registroAnterior = $registro;
    }
    $cerrarGrupoDuplicado($grupoActual);

    $reporte['posibles_pagos_duplicados'] = $posiblesPagosDuplicados;
    $reporte['checks'][] = [
        'titulo' => 'Posibles pagos duplicados',
        'detalle' => 'Mismo socio, fecha, actividad y valor, registrados con menos de 30 minutos de diferencia. Solo se informa; no modifica datos.',
        'registrado' => count($posiblesPagosDuplicados),
        'esperado' => 0,
        'diferencia' => count($posiblesPagosDuplicados),
        'ok' => count($posiblesPagosDuplicados) === 0,
    ];

    if (!empty($posiblesPagosDuplicados)) {
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
