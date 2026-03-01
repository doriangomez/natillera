<?php
require_once __DIR__ . '/functions.php';


function columnExists(PDO $pdo, string $tabla, string $columna): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tabla)) {
        return false;
    }

    $sql = 'SHOW COLUMNS FROM `' . $tabla . '` LIKE ' . $pdo->quote($columna);
    $stmt = $pdo->query($sql);
    return $stmt ? $stmt->rowCount() > 0 : false;
}

function indexExists(PDO $pdo, string $tabla, string $index): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tabla)) {
        return false;
    }

    $sql = 'SHOW INDEX FROM `' . $tabla . '` WHERE Key_name = ' . $pdo->quote($index);
    $stmt = $pdo->query($sql);
    return $stmt ? $stmt->rowCount() > 0 : false;
}

function eliminarIndicesUnicosLegadosRifasBoletas(PDO $pdo): void
{
    $sql = 'SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columnas
'
        . 'FROM information_schema.STATISTICS
'
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "rifas_boletas" AND NON_UNIQUE = 0
'
        . 'GROUP BY INDEX_NAME';

    $stmt = $pdo->query($sql);
    if (!$stmt) {
        return;
    }

    foreach ($stmt->fetchAll() as $index) {
        $nombre = (string) ($index['INDEX_NAME'] ?? '');
        $columnas = strtolower((string) ($index['columnas'] ?? ''));
        if ($nombre !== 'PRIMARY' && $columnas === 'id_rifa,numero') {
            $pdo->exec('ALTER TABLE rifas_boletas DROP INDEX `' . str_replace('`', '``', $nombre) . '`');
        }
    }
}


function tableExists(PDO $pdo, string $tabla): bool
{
    $sql = 'SHOW TABLES LIKE ' . $pdo->quote($tabla);
    $stmt = $pdo->query($sql);
    return $stmt ? $stmt->rowCount() > 0 : false;
}

function asegurarEsquemaRifas(PDO $pdo): void
{
    try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS rifas (
        id_rifa INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(150) NOT NULL,
        fecha_inicio DATE NOT NULL,
        fecha_fin DATE NOT NULL,
        valor_boleta DECIMAL(12,2) NOT NULL,
        cantidad_boletas INT NOT NULL DEFAULT 100,
        observaciones TEXT,
        id_actividad_ingreso INT NOT NULL,
        id_actividad_premio INT NOT NULL,
        estado VARCHAR(20) DEFAULT 'abierta',
        usuario_registro VARCHAR(50) DEFAULT NULL,
        fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
        tipo_rifa VARCHAR(20) NOT NULL DEFAULT 'normal',
        cantidad_grupos INT NOT NULL DEFAULT 1,
        cifras_numero INT NOT NULL DEFAULT 2,
        rango_inicio INT NOT NULL DEFAULT 0,
        rango_fin INT NOT NULL DEFAULT 99,
        modo_numeracion VARCHAR(20) NOT NULL DEFAULT 'secuencial',
        modo_distribucion VARCHAR(20) NOT NULL DEFAULT 'aleatoria',
        arte_base_path VARCHAR(255) DEFAULT NULL,
        arte_numero_x INT DEFAULT NULL,
        arte_numero_y INT DEFAULT NULL,
        arte_numero_size INT DEFAULT NULL,
        arte_numero_color VARCHAR(20) DEFAULT NULL,
        arte_font_path VARCHAR(255) DEFAULT NULL,
        numero_ganador VARCHAR(20) DEFAULT NULL,
        id_boleta_ganadora INT DEFAULT NULL,
        premio_valor DECIMAL(12,2) DEFAULT NULL,
        premio_descripcion VARCHAR(255) DEFAULT NULL,
        fecha_cierre DATETIME DEFAULT NULL,
        CONSTRAINT fk_rifa_actividad_ingreso FOREIGN KEY (id_actividad_ingreso) REFERENCES actividades_maestro(id_actividad) ON DELETE RESTRICT ON UPDATE CASCADE,
        CONSTRAINT fk_rifa_actividad_premio FOREIGN KEY (id_actividad_premio) REFERENCES actividades_maestro(id_actividad) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columnasRifa = [
        'tipo_rifa' => "ALTER TABLE rifas ADD COLUMN tipo_rifa VARCHAR(20) NOT NULL DEFAULT 'normal'",
        'cantidad_grupos' => 'ALTER TABLE rifas ADD COLUMN cantidad_grupos INT NOT NULL DEFAULT 1',
        'cifras_numero' => 'ALTER TABLE rifas ADD COLUMN cifras_numero INT NOT NULL DEFAULT 2',
        'rango_inicio' => 'ALTER TABLE rifas ADD COLUMN rango_inicio INT NOT NULL DEFAULT 0',
        'rango_fin' => 'ALTER TABLE rifas ADD COLUMN rango_fin INT NOT NULL DEFAULT 99',
        'modo_numeracion' => "ALTER TABLE rifas ADD COLUMN modo_numeracion VARCHAR(20) NOT NULL DEFAULT 'secuencial'",
        'modo_distribucion' => "ALTER TABLE rifas ADD COLUMN modo_distribucion VARCHAR(20) NOT NULL DEFAULT 'aleatoria'",
        'arte_base_path' => 'ALTER TABLE rifas ADD COLUMN arte_base_path VARCHAR(255) DEFAULT NULL',
        'arte_numero_x' => 'ALTER TABLE rifas ADD COLUMN arte_numero_x INT DEFAULT NULL',
        'arte_numero_y' => 'ALTER TABLE rifas ADD COLUMN arte_numero_y INT DEFAULT NULL',
        'arte_numero_size' => 'ALTER TABLE rifas ADD COLUMN arte_numero_size INT DEFAULT NULL',
        'arte_numero_color' => 'ALTER TABLE rifas ADD COLUMN arte_numero_color VARCHAR(20) DEFAULT NULL',
        'arte_font_path' => 'ALTER TABLE rifas ADD COLUMN arte_font_path VARCHAR(255) DEFAULT NULL',
        'numero_ganador' => 'ALTER TABLE rifas ADD COLUMN numero_ganador VARCHAR(20) DEFAULT NULL',
        'id_boleta_ganadora' => 'ALTER TABLE rifas ADD COLUMN id_boleta_ganadora INT DEFAULT NULL',
        'premio_valor' => 'ALTER TABLE rifas ADD COLUMN premio_valor DECIMAL(12,2) DEFAULT NULL',
        'premio_descripcion' => 'ALTER TABLE rifas ADD COLUMN premio_descripcion VARCHAR(255) DEFAULT NULL',
        'fecha_cierre' => 'ALTER TABLE rifas ADD COLUMN fecha_cierre DATETIME DEFAULT NULL',
    ];

    foreach ($columnasRifa as $columna => $sql) {
        if (!columnExists($pdo, 'rifas', $columna)) {
            $pdo->exec($sql);
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS rifas_grupos (
        id_grupo INT AUTO_INCREMENT PRIMARY KEY,
        id_rifa INT NOT NULL,
        nombre VARCHAR(100) NOT NULL,
        orden_grupo INT NOT NULL DEFAULT 1,
        boletas_por_socio INT NOT NULL DEFAULT 1,
        metodo_distribucion VARCHAR(20) NOT NULL DEFAULT 'aleatoria',
        socios_json TEXT DEFAULT NULL,
        asignaciones_json TEXT DEFAULT NULL,
        usuario_registro VARCHAR(50) DEFAULT NULL,
        fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_rifa_grupo_nombre (id_rifa, nombre),
        CONSTRAINT fk_grupo_rifa FOREIGN KEY (id_rifa) REFERENCES rifas(id_rifa) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (!columnExists($pdo, 'rifas_grupos', 'metodo_distribucion')) {
        $pdo->exec("ALTER TABLE rifas_grupos ADD COLUMN metodo_distribucion VARCHAR(20) NOT NULL DEFAULT 'aleatoria' AFTER boletas_por_socio");
    }
    if (!columnExists($pdo, 'rifas_grupos', 'socios_json')) {
        $pdo->exec("ALTER TABLE rifas_grupos ADD COLUMN socios_json TEXT DEFAULT NULL AFTER metodo_distribucion");
    }
    if (!columnExists($pdo, 'rifas_grupos', 'asignaciones_json')) {
        $pdo->exec("ALTER TABLE rifas_grupos ADD COLUMN asignaciones_json TEXT DEFAULT NULL AFTER socios_json");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS rifas_grupos_socios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_grupo INT NOT NULL,
        id_socio INT NOT NULL,
        boletas_asignadas INT NOT NULL DEFAULT 0,
        UNIQUE KEY uq_grupo_socio (id_grupo, id_socio),
        CONSTRAINT fk_grupo_socio_grupo FOREIGN KEY (id_grupo) REFERENCES rifas_grupos(id_grupo) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_grupo_socio_socio FOREIGN KEY (id_socio) REFERENCES socios(id_socio) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rifas_boletas (
        id_boleta INT AUTO_INCREMENT PRIMARY KEY,
        id_rifa INT NOT NULL,
        id_grupo INT DEFAULT NULL,
        numero VARCHAR(5) NOT NULL,
        id_socio INT DEFAULT NULL,
        estado VARCHAR(20) DEFAULT 'pendiente',
        valor DECIMAL(12,2) NOT NULL DEFAULT 0,
        fecha_asignacion DATETIME DEFAULT CURRENT_TIMESTAMP,
        fecha_pago DATETIME DEFAULT NULL,
        forma_pago VARCHAR(50) DEFAULT NULL,
        observaciones TEXT,
        usuario_ultimo VARCHAR(50) DEFAULT NULL,
        UNIQUE KEY uq_rifa_grupo_numero (id_rifa, id_grupo, numero),
        CONSTRAINT fk_boleta_rifa FOREIGN KEY (id_rifa) REFERENCES rifas(id_rifa) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_boleta_socio FOREIGN KEY (id_socio) REFERENCES socios(id_socio) ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT fk_boleta_grupo FOREIGN KEY (id_grupo) REFERENCES rifas_grupos(id_grupo) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (!columnExists($pdo, 'rifas_boletas', 'id_grupo')) {
        $pdo->exec('ALTER TABLE rifas_boletas ADD COLUMN id_grupo INT DEFAULT NULL AFTER id_rifa');
    }
    if (!columnExists($pdo, 'rifas_boletas', 'forma_pago')) {
        $pdo->exec('ALTER TABLE rifas_boletas ADD COLUMN forma_pago VARCHAR(50) DEFAULT NULL AFTER fecha_pago');
    }

    eliminarIndicesUnicosLegadosRifasBoletas($pdo);
    if (!indexExists($pdo, 'rifas_boletas', 'uq_rifa_grupo_numero')) {
        $pdo->exec('CREATE UNIQUE INDEX uq_rifa_grupo_numero ON rifas_boletas (id_rifa, id_grupo, numero)');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS rifas_boletas_historial (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_boleta INT NOT NULL,
        id_rifa INT NOT NULL,
        numero VARCHAR(5) NOT NULL,
        accion VARCHAR(50) NOT NULL,
        detalles TEXT,
        usuario VARCHAR(50) DEFAULT NULL,
        fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_historial_boleta FOREIGN KEY (id_boleta) REFERENCES rifas_boletas(id_boleta) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_historial_rifa FOREIGN KEY (id_rifa) REFERENCES rifas(id_rifa) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    asegurarForeignKey($pdo, [
        'tabla' => 'rifas_boletas',
        'columna' => 'id_rifa',
        'referencia' => 'rifas',
        'col_referencia' => 'id_rifa',
        'nombre' => 'fk_boleta_rifa',
    ]);

    asegurarForeignKey($pdo, [
        'tabla' => 'rifas_boletas_historial',
        'columna' => 'id_boleta',
        'referencia' => 'rifas_boletas',
        'col_referencia' => 'id_boleta',
        'nombre' => 'fk_historial_boleta',
    ]);

    asegurarForeignKey($pdo, [
        'tabla' => 'rifas_boletas_historial',
        'columna' => 'id_rifa',
        'referencia' => 'rifas',
        'col_referencia' => 'id_rifa',
        'nombre' => 'fk_historial_rifa',
    ]);

    asegurarForeignKey($pdo, [
        'tabla' => 'rifas_grupos',
        'columna' => 'id_rifa',
        'referencia' => 'rifas',
        'col_referencia' => 'id_rifa',
        'nombre' => 'fk_grupo_rifa',
    ]);

    asegurarForeignKey($pdo, [
        'tabla' => 'rifas_boletas',
        'columna' => 'id_grupo',
        'referencia' => 'rifas_grupos',
        'col_referencia' => 'id_grupo',
        'nombre' => 'fk_boleta_grupo',
    ]);
    } catch (Throwable $e) {
        error_log('Rifas esquema: ' . $e->getMessage());
    }

}

function sincronizarActividadesRifa(PDO $pdo): array
{
    asegurarEsquemaActividades($pdo);
    $actividades = [
        'ingreso' => [
            'nombre' => 'Rifa - Recaudo',
            'descripcion' => 'Cobro de boletas de rifa',
            'afecta_saldo_socio' => 'resta',
            'afecta_saldo_natillera' => 'suma',
            'es_ingreso' => 1,
        ],
        'premio' => [
            'nombre' => 'Rifa - Premio',
            'descripcion' => 'Pago de premio de rifa',
            'afecta_saldo_socio' => 'suma',
            'afecta_saldo_natillera' => 'resta',
            'es_ingreso' => 0,
        ],
    ];

    $ids = [];
    foreach ($actividades as $key => $data) {
        $stmt = $pdo->prepare('SELECT * FROM actividades_maestro WHERE nombre_actividad = :nombre LIMIT 1');
        $stmt->execute([':nombre' => $data['nombre']]);
        $actividad = $stmt->fetch();

        if ($actividad) {
            $ids[$key] = (int) $actividad['id_actividad'];
            if ((int) ($actividad['es_rifa'] ?? 0) === 0) {
                $pdo->prepare('UPDATE actividades_maestro SET es_rifa = 1 WHERE id_actividad = :id')
                    ->execute([':id' => $ids[$key]]);
            }
            continue;
        }

        $stmtInsert = $pdo->prepare('INSERT INTO actividades_maestro (nombre_actividad, descripcion, afecta_saldo_socio, afecta_saldo_natillera, es_ingreso, es_rifa, activo)
            VALUES (:nombre, :descripcion, :afecta_socio, :afecta_natillera, :es_ingreso, 1, 1)');
        $stmtInsert->execute([
            ':nombre' => $data['nombre'],
            ':descripcion' => $data['descripcion'],
            ':afecta_socio' => $data['afecta_saldo_socio'],
            ':afecta_natillera' => $data['afecta_saldo_natillera'],
            ':es_ingreso' => $data['es_ingreso'],
        ]);
        $ids[$key] = (int) $pdo->lastInsertId();
    }

    return $ids;
}

function crearRifa(PDO $pdo, array $data): int
{
    asegurarEsquemaRifas($pdo);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO rifas (nombre, fecha_inicio, fecha_fin, valor_boleta, cantidad_boletas, observaciones, id_actividad_ingreso, id_actividad_premio, usuario_registro, tipo_rifa, cantidad_grupos, cifras_numero, rango_inicio, rango_fin, modo_numeracion, modo_distribucion, arte_base_path, arte_numero_x, arte_numero_y, arte_numero_size, arte_numero_color, arte_font_path)
            VALUES (:nombre, :inicio, :fin, :valor, :cantidad, :obs, :act_ingreso, :act_premio, :usuario, :tipo, :grupos, :cifras, :rango_inicio, :rango_fin, :modo_num, :modo_dist, :arte_path, :arte_x, :arte_y, :arte_size, :arte_color, :arte_font_path)');
        $stmt->execute([
            ':nombre' => $data['nombre'],
            ':inicio' => $data['fecha_inicio'],
            ':fin' => $data['fecha_fin'],
            ':valor' => $data['valor_boleta'],
            ':cantidad' => $data['cantidad_boletas'],
            ':obs' => $data['observaciones'],
            ':act_ingreso' => $data['id_actividad_ingreso'],
            ':act_premio' => $data['id_actividad_premio'],
            ':usuario' => $data['usuario_registro'] ?? null,
            ':tipo' => $data['tipo_rifa'] ?? 'normal',
            ':grupos' => (int) ($data['cantidad_grupos'] ?? 1),
            ':cifras' => (int) ($data['cifras_numero'] ?? 2),
            ':rango_inicio' => (int) ($data['rango_inicio'] ?? 0),
            ':rango_fin' => (int) ($data['rango_fin'] ?? ((10 ** ((int) ($data['cifras_numero'] ?? 2))) - 1)),
            ':modo_num' => $data['modo_numeracion'] ?? 'secuencial',
            ':modo_dist' => $data['modo_distribucion'] ?? 'aleatoria',
            ':arte_path' => $data['arte_base_path'] ?? null,
            ':arte_x' => $data['arte_numero_x'] ?? null,
            ':arte_y' => $data['arte_numero_y'] ?? null,
            ':arte_size' => $data['arte_numero_size'] ?? null,
            ':arte_color' => $data['arte_numero_color'] ?? null,
            ':arte_font_path' => $data['arte_font_path'] ?? null,
        ]);

        $idRifa = (int) $pdo->lastInsertId();
        $grupos = crearGruposRifa($pdo, $idRifa, $data);
        generarBoletasRifa($pdo, $idRifa, (int) $data['cantidad_boletas'], (float) $data['valor_boleta'], $data, $grupos);
        procesarGeneracionBoletasEnFases($pdo, $idRifa, $data, $grupos, $data['usuario_registro'] ?? null);
        generarImagenesBoletasRifa($pdo, $idRifa, $data);

        $pdo->commit();
        return $idRifa;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function generarBoletasRifa(PDO $pdo, int $idRifa, int $cantidad, float $valor, array $config = [], array $grupos = []): void
{
    $numeros = construirNumerosRifa($cantidad, $config);
    if (empty($grupos)) {
        $grupos = [['id_grupo' => null]];
    }

    $stmt = $pdo->prepare('INSERT INTO rifas_boletas (id_rifa, id_grupo, numero, valor, usuario_ultimo) VALUES (:id_rifa, :id_grupo, :numero, :valor, :usuario)');
    foreach ($grupos as $grupo) {
        foreach ($numeros as $numero) {
            $stmt->execute([
                ':id_rifa' => $idRifa,
                ':id_grupo' => $grupo['id_grupo'],
                ':numero' => $numero,
                ':valor' => $valor,
                ':usuario' => $_SESSION['usuario'] ?? null,
            ]);
        }
    }
}

function asignarBoletasAutomaticas(PDO $pdo, int $idRifa, ?string $usuario = null, array $config = [], array $grupos = []): void
{
    procesarGeneracionBoletasEnFases($pdo, $idRifa, $config, $grupos, $usuario);
}

function procesarGeneracionBoletasEnFases(PDO $pdo, int $idRifa, array $config, array $grupos, ?string $usuario = null): void
{
    // Fase 1: creación de boletas base por grupo (sin asignar).
    // Esta fase se ejecuta previamente al llamar generarBoletasRifa().

    // Fase 2: asignación manual con validaciones atómicas por grupo.
    asignarBoletasManualesPorGrupo($pdo, $idRifa, $config, $grupos, $usuario);

    // Fase 3: asignación aleatoria para completar cupos por grupo.
    asignarBoletasAleatoriasPorGrupo($pdo, $idRifa, $config, $grupos, $usuario);
}

function asignarBoletasManualesPorGrupo(PDO $pdo, int $idRifa, array $config, array $grupos, ?string $usuario = null): void
{
    $socios = getSocios($pdo);
    if (empty($socios)) {
        return;
    }

    $sociosMap = [];
    foreach ($socios as $socio) {
        $sociosMap[(int) $socio['id_socio']] = $socio;
    }

    $erroresPorGrupo = [];

    foreach ($grupos as $grupo) {
        $nombreGrupo = (string) ($grupo['nombre'] ?? ('ID ' . (string) ($grupo['id_grupo'] ?? 'N/A')));
        $idGrupo = $grupo['id_grupo'] ?? null;
        try {
            $stmtBoletas = $pdo->prepare('SELECT * FROM rifas_boletas WHERE id_rifa = :id AND ((:grupo IS NULL AND id_grupo IS NULL) OR id_grupo = :grupo) ORDER BY numero');
            $stmtBoletas->execute([':id' => $idRifa, ':grupo' => $idGrupo]);
            $boletas = $stmtBoletas->fetchAll();
            if (empty($boletas)) {
                continue;
            }

            $boletasPorNumero = [];
            foreach ($boletas as $boleta) {
                $boletasPorNumero[(string) $boleta['numero']] = $boleta;
            }

            $idsSociosGrupo = $grupo['socios'] ?? array_keys($sociosMap);
            $idsSociosGrupo = array_values(array_filter(array_map('intval', $idsSociosGrupo), static fn($id) => $id > 0 && isset($sociosMap[$id])));
            if (empty($idsSociosGrupo)) {
                throw new RuntimeException('No se puede crear rifa sin asignar socios en el grupo configurado.');
            }

            $stmtUpd = $pdo->prepare('UPDATE rifas_boletas SET id_socio = :socio, estado = "asignada", usuario_ultimo = :usuario WHERE id_boleta = :id');

            $manualAsignadas = [];
            $manualNumeros = [];
            $manualPorSocio = array_fill_keys($idsSociosGrupo, 0);
            $asignacionesNumero = is_array($grupo['asignaciones'] ?? null) ? $grupo['asignaciones'] : [];
            foreach ($asignacionesNumero as $asig) {
                if (!isset($asig['numero']) || !isset($asig['id_socio'])) {
                    continue;
                }
                $numero = (string) $asig['numero'];
                $idSocio = (int) ($asig['id_socio'] ?? 0);

                if (!isset($boletasPorNumero[$numero])) {
                    $numeroNormalizado = str_pad((string) ((int) $numero), max(1, strlen((string) $boletas[0]['numero'])), '0', STR_PAD_LEFT);
                    $numero = $numeroNormalizado;
                }

                if (!isset($boletasPorNumero[$numero])) {
                    throw new RuntimeException('Asignación manual inválida: número no disponible en el grupo.');
                }
                if (!in_array($idSocio, $idsSociosGrupo, true)) {
                    throw new RuntimeException('Asignación manual inválida: el socio no pertenece al grupo.');
                }
                if (isset($manualNumeros[$numero])) {
                    throw new RuntimeException('No se permiten números manuales duplicados dentro del mismo grupo.');
                }

                $manualNumeros[$numero] = true;
                $boleta = $boletasPorNumero[$numero];
                $stmtUpd->execute([
                    ':socio' => $idSocio,
                    ':usuario' => $usuario,
                    ':id' => $boleta['id_boleta'],
                ]);
                registrarHistorialBoleta($pdo, (int) $boleta['id_boleta'], $idRifa, $boleta['numero'], 'asignacion', 'Asignación manual a ' . $sociosMap[$idSocio]['nombre_completo'], $usuario);
                $manualAsignadas[(int) $boleta['id_boleta']] = true;
                $manualPorSocio[$idSocio] = ($manualPorSocio[$idSocio] ?? 0) + 1;
            }

        } catch (Throwable $e) {
            $erroresPorGrupo[] = $nombreGrupo . ': ' . $e->getMessage();
        }
    }

    if (!empty($erroresPorGrupo)) {
        throw new RuntimeException('Fase 2 (asignación manual) falló: ' . implode(' | ', $erroresPorGrupo));
    }
}

function asignarBoletasAleatoriasPorGrupo(PDO $pdo, int $idRifa, array $config, array $grupos, ?string $usuario = null): void
{
    $socios = getSocios($pdo);
    if (empty($socios)) {
        return;
    }

    $sociosMap = [];
    foreach ($socios as $socio) {
        $sociosMap[(int) $socio['id_socio']] = $socio;
    }

    $erroresPorGrupo = [];
    foreach ($grupos as $grupo) {
        $nombreGrupo = (string) ($grupo['nombre'] ?? ('ID ' . (string) ($grupo['id_grupo'] ?? 'N/A')));
        $idGrupo = $grupo['id_grupo'] ?? null;
        try {
            $stmtBoletas = $pdo->prepare('SELECT * FROM rifas_boletas WHERE id_rifa = :id AND ((:grupo IS NULL AND id_grupo IS NULL) OR id_grupo = :grupo) ORDER BY numero');
            $stmtBoletas->execute([':id' => $idRifa, ':grupo' => $idGrupo]);
            $boletas = $stmtBoletas->fetchAll();
            if (empty($boletas)) {
                continue;
            }

            $idsSociosGrupo = $grupo['socios'] ?? array_keys($sociosMap);
            $idsSociosGrupo = array_values(array_filter(array_map('intval', $idsSociosGrupo), static fn($id) => $id > 0 && isset($sociosMap[$id])));
            if (empty($idsSociosGrupo)) {
                throw new RuntimeException('No se puede crear rifa sin asignar socios en el grupo configurado.');
            }

            $boletasPorSocio = max(1, (int) ($grupo['boletas_por_socio'] ?? ($config['boletas_por_socio'] ?? 1)));
            $totalEsperadoGrupo = count($idsSociosGrupo) * $boletasPorSocio;
            if (count($boletas) < $totalEsperadoGrupo) {
                throw new RuntimeException('No hay boletas suficientes para completar el cupo esperado del grupo.');
            }

            $stmtUpd = $pdo->prepare('UPDATE rifas_boletas SET id_socio = :socio, estado = "asignada", usuario_ultimo = :usuario WHERE id_boleta = :id');

            $stmtManuales = $pdo->prepare('SELECT id_socio, COUNT(*) AS total FROM rifas_boletas WHERE id_rifa = :id_rifa AND ((:grupo IS NULL AND id_grupo IS NULL) OR id_grupo = :grupo) AND id_socio IS NOT NULL GROUP BY id_socio');
            $stmtManuales->execute([':id_rifa' => $idRifa, ':grupo' => $idGrupo]);
            $manualPorSocio = array_fill_keys($idsSociosGrupo, 0);
            foreach ($stmtManuales->fetchAll() as $filaManual) {
                $idSocioManual = (int) $filaManual['id_socio'];
                if (isset($manualPorSocio[$idSocioManual])) {
                    $manualPorSocio[$idSocioManual] = (int) $filaManual['total'];
                }
            }

            foreach ($manualPorSocio as $idSocio => $manualesSocio) {
                if ($manualesSocio > $boletasPorSocio) {
                    throw new RuntimeException('Un socio tiene más manuales que su cupo de boletas.');
                }
            }

            $restantes = array_values(array_filter($boletas, static fn($b) => (int) ($b['id_socio'] ?? 0) <= 0));
            shuffle($restantes);

            $asignacion = [];
            foreach ($idsSociosGrupo as $idSocio) {
                $faltan = max(0, $boletasPorSocio - (int) ($manualPorSocio[$idSocio] ?? 0));
                if ($faltan > 0) {
                    $asignacion[$idSocio] = $faltan;
                }
            }

            $totalFaltantes = array_sum($asignacion);
            if ($totalFaltantes > count($restantes)) {
                throw new RuntimeException('No hay números disponibles suficientes para completar los faltantes del grupo.');
            }

            $i = 0;
            foreach ($asignacion as $idSocio => $cantidad) {
                for ($j = 0; $j < $cantidad && isset($restantes[$i]); $j++, $i++) {
                    $boleta = $restantes[$i];
                    $stmtUpd->execute([
                        ':socio' => $idSocio,
                        ':usuario' => $usuario,
                        ':id' => $boleta['id_boleta'],
                    ]);
                    registrarHistorialBoleta($pdo, (int) $boleta['id_boleta'], $idRifa, $boleta['numero'], 'asignacion', 'Asignación automática a ' . $sociosMap[$idSocio]['nombre_completo'], $usuario);
                }
            }

            $stmtValida = $pdo->prepare('SELECT COUNT(*) FROM rifas_boletas WHERE id_rifa = :id_rifa AND ((:grupo IS NULL AND id_grupo IS NULL) OR id_grupo = :grupo) AND id_socio IS NOT NULL');
            $stmtValida->execute([':id_rifa' => $idRifa, ':grupo' => $idGrupo]);
            $totalAsignadoGrupo = (int) $stmtValida->fetchColumn();
            if ($totalAsignadoGrupo !== $totalEsperadoGrupo) {
                throw new RuntimeException('La distribución quedó incompleta: asignadas ' . $totalAsignadoGrupo . ' de ' . $totalEsperadoGrupo . ' boletas esperadas.');
            }
        } catch (Throwable $e) {
            $erroresPorGrupo[] = $nombreGrupo . ': ' . $e->getMessage();
        }
    }

    if (!empty($erroresPorGrupo)) {
        throw new RuntimeException('Fase 3 (asignación aleatoria) falló: ' . implode(' | ', $erroresPorGrupo));
    }
}

function registrarHistorialBoleta(PDO $pdo, int $idBoleta, int $idRifa, string $numero, string $accion, string $detalle, ?string $usuario): void
{
    $stmt = $pdo->prepare('INSERT INTO rifas_boletas_historial (id_boleta, id_rifa, numero, accion, detalles, usuario)
        VALUES (:boleta, :rifa, :numero, :accion, :detalles, :usuario)');
    $stmt->execute([
        ':boleta' => $idBoleta,
        ':rifa' => $idRifa,
        ':numero' => $numero,
        ':accion' => $accion,
        ':detalles' => $detalle,
        ':usuario' => $usuario,
    ]);
}

function obtenerRifas(PDO $pdo): array
{
    asegurarEsquemaRifas($pdo);
    $stmt = $pdo->query('SELECT r.*,
        (SELECT COUNT(*) FROM rifas_boletas b WHERE b.id_rifa = r.id_rifa) AS total_boletas,
        (SELECT COUNT(*) FROM rifas_boletas b WHERE b.id_rifa = r.id_rifa AND b.estado = "pagada") AS boletas_pagadas,
        (SELECT COALESCE(SUM(valor),0) FROM rifas_boletas b WHERE b.id_rifa = r.id_rifa AND b.estado = "pagada") AS total_recaudado,
        (SELECT COUNT(*) FROM rifas_boletas b WHERE b.id_rifa = r.id_rifa AND b.estado = "pendiente") AS boletas_pendientes
        FROM rifas r ORDER BY r.fecha_inicio DESC');
    return $stmt->fetchAll();
}

function eliminarRifa(PDO $pdo, int $idRifa): void
{
    $stmtRifa = $pdo->prepare('SELECT * FROM rifas WHERE id_rifa = :id LIMIT 1');
    $stmtRifa->execute([':id' => $idRifa]);
    $rifa = $stmtRifa->fetch();

    if (!$rifa) {
        throw new RuntimeException('La rifa seleccionada no existe.');
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare('DELETE FROM rifas_boletas_historial WHERE id_rifa = :id')
            ->execute([':id' => $idRifa]);

        $pdo->prepare('DELETE FROM rifas_boletas WHERE id_rifa = :id')
            ->execute([':id' => $idRifa]);

        $pdo->prepare('DELETE FROM movimientos WHERE modulo = "rifas" AND id_actividad IN (:ingreso, :premio)')
            ->execute([
                ':ingreso' => (int) $rifa['id_actividad_ingreso'],
                ':premio' => (int) $rifa['id_actividad_premio'],
            ]);

        $pdo->prepare('DELETE FROM rifas WHERE id_rifa = :id')
            ->execute([':id' => $idRifa]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    recalcularSaldosDesdeMovimientos($pdo);
}

function obtenerRifa(PDO $pdo, int $idRifa): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM rifas WHERE id_rifa = :id');
    $stmt->execute([':id' => $idRifa]);
    $rifa = $stmt->fetch();
    return $rifa ?: null;
}

function obtenerBoletasRifa(PDO $pdo, int $idRifa): array
{
    $stmt = $pdo->prepare('SELECT b.*, s.nombre_completo, g.nombre AS nombre_grupo FROM rifas_boletas b LEFT JOIN socios s ON b.id_socio = s.id_socio LEFT JOIN rifas_grupos g ON g.id_grupo = b.id_grupo WHERE b.id_rifa = :id ORDER BY b.id_grupo, b.numero');
    $stmt->execute([':id' => $idRifa]);
    return $stmt->fetchAll();
}

function reAsignarBoleta(PDO $pdo, int $idRifa, string $numeroActual, string $numeroNuevo, ?int $idSocio, ?string $motivo, ?string $usuario): void
{
    $stmtBoletaActual = $pdo->prepare('SELECT * FROM rifas_boletas WHERE id_rifa = :id AND numero = :numero');
    $stmtBoletaActual->execute([':id' => $idRifa, ':numero' => $numeroActual]);
    $boleta = $stmtBoletaActual->fetch();
    if (!$boleta) {
        throw new RuntimeException('La boleta seleccionada no existe.');
    }

    $stmtVerificar = $pdo->prepare('SELECT COUNT(*) FROM rifas_boletas WHERE id_rifa = :id AND numero = :nuevo');
    $stmtVerificar->execute([':id' => $idRifa, ':nuevo' => $numeroNuevo]);
    if ((int) $stmtVerificar->fetchColumn() > 0 && $numeroNuevo !== $numeroActual) {
        $stmtNumeroLibre = $pdo->prepare('SELECT id_boleta FROM rifas_boletas WHERE id_rifa = :id AND numero = :nuevo AND id_boleta = :boleta');
        $stmtNumeroLibre->execute([':id' => $idRifa, ':nuevo' => $numeroNuevo, ':boleta' => $boleta['id_boleta']]);
        if (!$stmtNumeroLibre->fetch()) {
            throw new RuntimeException('El número solicitado ya está asignado a otra boleta.');
        }
    }

    $stmtActualizar = $pdo->prepare('UPDATE rifas_boletas SET numero = :nuevo, id_socio = :socio, usuario_ultimo = :usuario WHERE id_boleta = :id');
    $stmtActualizar->execute([
        ':nuevo' => $numeroNuevo,
        ':socio' => $idSocio,
        ':usuario' => $usuario,
        ':id' => $boleta['id_boleta'],
    ]);

    registrarHistorialBoleta($pdo, (int) $boleta['id_boleta'], $idRifa, $numeroNuevo, 'reasignacion', $motivo ?: 'Ajuste manual de boleta', $usuario);
}

function crearMovimientoRifa(PDO $pdo, int $idActividad, int $idSocio, float $valor, string $fecha, string $medio, ?int $idMedio, string $modulo, string $observaciones = ''): void
{
    $actividad = getActividad($pdo, $idActividad);
    $reglaNatillera = normalizarReglaAfectacion($actividad['afecta_saldo_natillera'] ?? 'neutral');
    $reglaSocio = normalizarReglaAfectacion($actividad['afecta_saldo_socio'] ?? 'neutral');

    $anio = (int) date('Y', strtotime($fecha));
    $mes = (int) date('n', strtotime($fecha));
    $quincena = (int) (date('j', strtotime($fecha)) <= 15 ? 1 : 2);

    $stmtPeriodo = $pdo->prepare('SELECT COUNT(*) FROM periodos_configuracion WHERE anio = :anio AND mes = :mes AND activo = 1');
    $stmtPeriodo->execute([':anio' => $anio, ':mes' => $mes]);
    if ((int) $stmtPeriodo->fetchColumn() === 0) {
        throw new RuntimeException('El periodo seleccionado no está habilitado en la configuración.');
    }

    $esIngreso = $reglaNatillera === 'suma' ? 1 : 0;
    $esEgreso = $reglaNatillera === 'resta' ? 1 : 0;
    $valorMovimiento = $esEgreso ? -abs($valor) : abs($valor);

    $stmt = $pdo->prepare('INSERT INTO movimientos (fecha, anio, mes, quincena, id_socio, id_actividad, motivo, valor, medio_consignacion, id_medio_pago, es_ingreso, es_egreso, observaciones, usuario_registro, fecha_registro, modulo)
        VALUES (:fecha, :anio, :mes, :quincena, :socio, :actividad, :motivo, :valor, :medio, :medio_id, :ingreso, :egreso, :obs, :usuario, NOW(), :modulo)');
    $stmt->execute([
        ':fecha' => $fecha,
        ':anio' => $anio,
        ':mes' => $mes,
        ':quincena' => $quincena,
        ':socio' => $idSocio,
        ':actividad' => $idActividad,
        ':motivo' => 'Rifa',
        ':valor' => $valorMovimiento,
        ':medio' => $medio,
        ':medio_id' => $idMedio,
        ':ingreso' => $esIngreso,
        ':egreso' => $esEgreso,
        ':obs' => $observaciones,
        ':usuario' => $_SESSION['usuario'] ?? null,
        ':modulo' => $modulo,
    ]);

    actualizarSaldoSocio($pdo, $idSocio, $valorMovimiento, $reglaSocio);
    actualizarSaldoNatillera($pdo, $valorMovimiento, $reglaNatillera);
}

function registrarPagoBoleta(PDO $pdo, int $idRifa, string $numero, string $fechaPago, string $medio, ?int $idMedio, ?string $usuario, ?int $idActividadIngreso = null): void
{
    $stmt = $pdo->prepare('SELECT b.*, r.id_actividad_ingreso FROM rifas_boletas b JOIN rifas r ON r.id_rifa = b.id_rifa WHERE b.id_rifa = :id AND b.numero = :numero');
    $stmt->execute([':id' => $idRifa, ':numero' => $numero]);
    $boleta = $stmt->fetch();
    if (!$boleta) {
        throw new RuntimeException('No se encontró la boleta.');
    }
    if (!$boleta['id_socio']) {
        throw new RuntimeException('La boleta no tiene un socio asignado.');
    }

    $idActividadMovimiento = $idActividadIngreso ?: (int) $boleta['id_actividad_ingreso'];
    $actividadIngreso = getActividad($pdo, $idActividadMovimiento);
    if (!$actividadIngreso || (int) ($actividadIngreso['es_ingreso'] ?? 0) !== 1) {
        throw new RuntimeException('El concepto seleccionado para el ingreso no es válido.');
    }

    crearMovimientoRifa($pdo, $idActividadMovimiento, (int) $boleta['id_socio'], (float) $boleta['valor'], $fechaPago, $medio, $idMedio, 'rifas', 'Recaudo de boleta ' . $numero);

    $stmtUpd = $pdo->prepare('UPDATE rifas_boletas SET estado = "pagada", fecha_pago = :fecha, forma_pago = :forma_pago, usuario_ultimo = :usuario WHERE id_boleta = :id');
    $stmtUpd->execute([
        ':fecha' => $fechaPago,
        ':forma_pago' => $medio,
        ':usuario' => $usuario,
        ':id' => $boleta['id_boleta'],
    ]);

    registrarHistorialBoleta($pdo, (int) $boleta['id_boleta'], $idRifa, $numero, 'pago', 'Pago registrado', $usuario);
    recalcularSaldosDesdeMovimientos($pdo);
}

function registrarPremioRifa(PDO $pdo, int $idRifa, string $numeroGanador, ?int $idGrupoGanador, float $valorPremio, string $fecha, string $medio, ?int $idMedio, ?string $usuario, ?int $idActividadPremio = null): void
{
    $sql = 'SELECT b.*, r.id_actividad_premio FROM rifas_boletas b JOIN rifas r ON r.id_rifa = b.id_rifa WHERE b.id_rifa = :id AND b.numero = :numero';
    $params = [':id' => $idRifa, ':numero' => $numeroGanador];
    if ($idGrupoGanador) {
        $sql .= ' AND b.id_grupo = :grupo';
        $params[':grupo'] = $idGrupoGanador;
    }
    $sql .= ' ORDER BY b.id_grupo LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $boleta = $stmt->fetch();
    if (!$boleta) {
        throw new RuntimeException('No se encontró la boleta ganadora.');
    }
    if (!$boleta['id_socio']) {
        throw new RuntimeException('El número ganador no tiene socio asignado.');
    }

    $idActividadMovimiento = $idActividadPremio ?: (int) $boleta['id_actividad_premio'];
    $actividadPremio = getActividad($pdo, $idActividadMovimiento);
    if (!$actividadPremio || (int) ($actividadPremio['es_ingreso'] ?? 1) !== 0) {
        throw new RuntimeException('El concepto seleccionado para el premio no es válido.');
    }

    crearMovimientoRifa($pdo, $idActividadMovimiento, (int) $boleta['id_socio'], $valorPremio, $fecha, $medio, $idMedio, 'rifas', 'Premio rifa ' . $idRifa);

    registrarHistorialBoleta($pdo, (int) $boleta['id_boleta'], $idRifa, $numeroGanador, 'premio', 'Pago de premio', $usuario);
    $pdo->prepare('UPDATE rifas SET estado = "cerrada", numero_ganador = :numero, id_boleta_ganadora = :boleta, premio_valor = :valor, fecha_cierre = NOW() WHERE id_rifa = :id')
        ->execute([':id' => $idRifa, ':numero' => $numeroGanador, ':boleta' => $boleta['id_boleta'], ':valor' => $valorPremio]);
    recalcularSaldosDesdeMovimientos($pdo);
}

function crearGruposRifa(PDO $pdo, int $idRifa, array $data): array
{
    $cantidadGrupos = max(1, (int) ($data['cantidad_grupos'] ?? 1));
    $gruposTexto = trim((string) ($data['grupos_json'] ?? ''));
    $grupos = [];

    if ($gruposTexto !== '') {
        $decoded = json_decode($gruposTexto, true);
        if (is_array($decoded)) {
            foreach ($decoded as $idx => $grupo) {
                if (!is_array($grupo)) {
                    continue;
                }
                $socios = array_values(array_filter(array_map('intval', (array) ($grupo['socios'] ?? [])), static fn($id) => $id > 0));
                $asignaciones = [];
                if (is_array($grupo['asignaciones'] ?? null)) {
                    foreach ($grupo['asignaciones'] as $asig) {
                        $numero = isset($asig['numero']) ? trim((string) $asig['numero']) : '';
                        $idSocio = (int) ($asig['id_socio'] ?? 0);
                        if ($numero !== '' && $idSocio > 0) {
                            $asignaciones[] = ['numero' => $numero, 'id_socio' => $idSocio];
                        }
                    }
                }
                $grupos[] = [
                    'nombre' => trim((string) ($grupo['nombre'] ?? ('Grupo ' . ($idx + 1)))) ?: ('Grupo ' . ($idx + 1)),
                    'boletas_por_socio' => max(1, (int) ($grupo['boletas_por_socio'] ?? 1)),
                    'metodo_distribucion' => in_array(($grupo['metodo_distribucion'] ?? 'aleatoria'), ['aleatoria', 'manual', 'mixta'], true) ? $grupo['metodo_distribucion'] : 'aleatoria',
                    'socios' => $socios,
                    'asignaciones' => $asignaciones,
                ];
            }
        }
    }

    if (empty($grupos)) {
        for ($i = 1; $i <= $cantidadGrupos; $i++) {
            $grupos[] = [
                'nombre' => 'Grupo ' . $i,
                'boletas_por_socio' => max(1, (int) ($data['boletas_por_socio'] ?? 1)),
                'metodo_distribucion' => in_array(($data['modo_distribucion'] ?? 'aleatoria'), ['aleatoria', 'manual', 'mixta'], true) ? $data['modo_distribucion'] : 'aleatoria',
                'socios' => [],
                'asignaciones' => [],
            ];
        }
    }

    $stmt = $pdo->prepare('INSERT INTO rifas_grupos (id_rifa, nombre, orden_grupo, boletas_por_socio, metodo_distribucion, socios_json, asignaciones_json, usuario_registro) VALUES (:id_rifa, :nombre, :orden, :boletas, :metodo, :socios_json, :asignaciones_json, :usuario)');
    $creados = [];
    foreach ($grupos as $i => $grupo) {
        $stmt->execute([
            ':id_rifa' => $idRifa,
            ':nombre' => $grupo['nombre'],
            ':orden' => $i + 1,
            ':boletas' => $grupo['boletas_por_socio'],
            ':metodo' => $grupo['metodo_distribucion'],
            ':socios_json' => !empty($grupo['socios']) ? json_encode($grupo['socios']) : null,
            ':asignaciones_json' => !empty($grupo['asignaciones']) ? json_encode($grupo['asignaciones']) : null,
            ':usuario' => $data['usuario_registro'] ?? null,
        ]);
        $creados[] = [
            'id_grupo' => (int) $pdo->lastInsertId(),
            'nombre' => $grupo['nombre'],
            'boletas_por_socio' => $grupo['boletas_por_socio'],
            'metodo_distribucion' => $grupo['metodo_distribucion'],
            'socios' => $grupo['socios'],
            'asignaciones' => $grupo['asignaciones'],
        ];
    }

    return $creados;
}

function construirNumerosRifa(int $cantidad, array $config = []): array
{
    $cifras = max(1, (int) ($config['cifras_numero'] ?? 2));
    $inicio = max(0, (int) ($config['rango_inicio'] ?? 0));
    $finDefault = (10 ** $cifras) - 1;
    $fin = (int) ($config['rango_fin'] ?? $finDefault);
    if ($fin < $inicio) {
        $fin = $inicio;
    }

    $pool = range($inicio, $fin);
    $capacidad = count($pool);
    if ($cantidad > $capacidad) {
        throw new RuntimeException('La cantidad de boletas excede el rango disponible de numeración.');
    }

    $manualRaw = trim((string) ($config['numeros_manuales'] ?? ''));
    $manual = $manualRaw === '' ? [] : array_values(array_filter(preg_split('/\s*,\s*/', $manualRaw), static fn($n) => $n !== ''));
    $manualNumeros = [];
    $manualSet = [];
    foreach ($manual as $n) {
        if (!preg_match('/^\d+$/', (string) $n)) {
            throw new RuntimeException('Los números manuales deben ser numéricos.');
        }
        $valor = (int) $n;
        if ($valor < $inicio || $valor > $fin) {
            throw new RuntimeException('Hay números manuales fuera del rango configurado.');
        }
        if (isset($manualSet[$valor])) {
            throw new RuntimeException('Hay números manuales repetidos.');
        }
        $manualSet[$valor] = true;
        $manualNumeros[] = $valor;
    }

    $modo = $config['modo_numeracion'] ?? 'secuencial';
    if ($modo === 'manual') {
        if (count($manualNumeros) !== $cantidad) {
            throw new RuntimeException('En modo manual debe ingresar exactamente la cantidad total de boletas.');
        }
        return array_map(static fn($n) => str_pad((string) $n, $cifras, '0', STR_PAD_LEFT), $manualNumeros);
    }

    $poolRestante = array_values(array_filter($pool, static fn($n) => !isset($manualSet[$n])));
    if ($modo === 'aleatoria' || $modo === 'mixta') {
        shuffle($poolRestante);
    }

    if ($modo === 'mixta') {
        if (count($manualNumeros) > $cantidad) {
            throw new RuntimeException('La cantidad de números manuales supera el total de boletas.');
        }
        $faltantes = $cantidad - count($manualNumeros);
        $auto = array_slice($poolRestante, 0, $faltantes);
        $resultado = array_merge($manualNumeros, $auto);
        return array_map(static fn($n) => str_pad((string) $n, $cifras, '0', STR_PAD_LEFT), $resultado);
    }

    if ($modo === 'aleatoria') {
        $seleccion = array_slice($pool, 0, $cantidad);
        shuffle($seleccion);
        return array_map(static fn($n) => str_pad((string) $n, $cifras, '0', STR_PAD_LEFT), $seleccion);
    }

    $seleccion = array_slice($pool, 0, $cantidad);
    return array_map(static fn($n) => str_pad((string) $n, $cifras, '0', STR_PAD_LEFT), $seleccion);
}

function generarImagenesBoletasRifa(PDO $pdo, int $idRifa, array $config = []): void
{
    $rutaBase = trim((string) ($config['arte_base_path'] ?? ''));
    if ($rutaBase === '') {
        return;
    }

    $archivoBase = dirname(__DIR__) . '/public/' . ltrim($rutaBase, '/');
    if (!is_file($archivoBase)) {
        return;
    }

    if (!function_exists('imagecreatefrompng')) {
        return;
    }

    $boletas = obtenerBoletasRifa($pdo, $idRifa);
    if (empty($boletas)) {
        return;
    }

    $ext = strtolower(pathinfo($archivoBase, PATHINFO_EXTENSION));
    $loader = match ($ext) {
        'jpg', 'jpeg' => 'imagecreatefromjpeg',
        'gif' => 'imagecreatefromgif',
        default => 'imagecreatefrompng',
    };

    if (!function_exists($loader)) {
        return;
    }

    $destino = dirname(__DIR__) . '/public/uploads/rifas/' . $idRifa;
    if (!is_dir($destino)) {
        mkdir($destino, 0775, true);
    }

    $x = (int) ($config['arte_numero_x'] ?? 20);
    $y = (int) ($config['arte_numero_y'] ?? 40);
    $size = max(10, (int) ($config['arte_numero_size'] ?? 24));
    $colorHex = trim((string) ($config['arte_numero_color'] ?? '#000000'));
    $fontPath = trim((string) ($config['arte_font_path'] ?? ''));
    if ($fontPath !== '' && !str_starts_with($fontPath, '/')) {
        $fontPath = dirname(__DIR__) . '/public/' . ltrim($fontPath, '/');
    }

    foreach ($boletas as $boleta) {
        $img = $loader($archivoBase);
        if (!$img) {
            continue;
        }

        $rgb = sscanf($colorHex, '#%02x%02x%02x');
        if (!is_array($rgb) || count($rgb) < 3) {
            $rgb = [0, 0, 0];
        }
        $color = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);

        if ($fontPath !== '' && is_file($fontPath) && function_exists('imagettftext')) {
            imagettftext($img, $size, 0, $x, $y, $color, $fontPath, (string) $boleta['numero']);
        } else {
            imagestring($img, 5, $x, $y, (string) $boleta['numero'], $color);
        }

        $grupoSlug = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($boleta['nombre_grupo'] ?: 'General'));
        $socioSlug = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($boleta['nombre_completo'] ?: 'Sin_Asignar'));
        $idGrupo = (int) ($boleta['id_grupo'] ?? 0);
        $idSocio = (int) ($boleta['id_socio'] ?? 0);

        $grupoDir = $destino . '/Grupo_' . $idGrupo . '_' . $grupoSlug;
        $socioDir = $grupoDir . '/Socio_' . $idSocio . '_' . $socioSlug;
        if (!is_dir($socioDir)) {
            mkdir($socioDir, 0775, true);
        }
        imagepng($img, $socioDir . '/boleta_' . $boleta['numero'] . '.png');
        imagedestroy($img);
    }
}

function obtenerGruposRifa(PDO $pdo, int $idRifa): array
{
    if (!tableExists($pdo, 'rifas_grupos')) {
        return [];
    }

    $stmt = $pdo->prepare('SELECT g.*, COUNT(b.id_boleta) AS total_boletas, SUM(CASE WHEN b.estado = "pagada" THEN 1 ELSE 0 END) AS boletas_pagadas, COALESCE(SUM(CASE WHEN b.estado = "pagada" THEN b.valor ELSE 0 END), 0) AS recaudo FROM rifas_grupos g LEFT JOIN rifas_boletas b ON b.id_grupo = g.id_grupo WHERE g.id_rifa = :id GROUP BY g.id_grupo ORDER BY g.orden_grupo');
    $stmt->execute([':id' => $idRifa]);
    return $stmt->fetchAll();
}

function obtenerResumenSociosRifa(PDO $pdo, int $idRifa): array
{
    if (!tableExists($pdo, 'rifas_boletas')) {
        return [];
    }

    $stmt = $pdo->prepare('SELECT s.id_socio, s.nombre_completo, COUNT(b.id_boleta) AS boletas, SUM(CASE WHEN b.estado = "pagada" THEN 1 ELSE 0 END) AS pagadas, SUM(CASE WHEN b.estado IN ("pendiente", "asignada") THEN 1 ELSE 0 END) AS pendientes, COALESCE(SUM(CASE WHEN b.estado = "pagada" THEN b.valor ELSE 0 END),0) AS total_pagado FROM rifas_boletas b JOIN socios s ON s.id_socio = b.id_socio WHERE b.id_rifa = :id GROUP BY s.id_socio, s.nombre_completo ORDER BY s.nombre_completo');
    $stmt->execute([':id' => $idRifa]);
    return $stmt->fetchAll();
}

function obtenerUtilidadRifa(PDO $pdo, int $idRifa): array
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(CASE WHEN estado = "pagada" THEN valor ELSE 0 END),0) AS total_recaudado, COALESCE(SUM(valor),0) AS total_vendido FROM rifas_boletas WHERE id_rifa = :id');
    $stmt->execute([':id' => $idRifa]);
    $base = $stmt->fetch() ?: ['total_recaudado' => 0, 'total_vendido' => 0];

    $stmtRifa = $pdo->prepare('SELECT premio_valor, cantidad_boletas, valor_boleta FROM rifas WHERE id_rifa = :id LIMIT 1');
    $stmtRifa->execute([':id' => $idRifa]);
    $rifa = $stmtRifa->fetch() ?: ['premio_valor' => 0, 'cantidad_boletas' => 0, 'valor_boleta' => 0];
    $premio = (float) ($rifa['premio_valor'] ?? 0);
    $totalProyectado = (float) ($rifa['cantidad_boletas'] ?? 0) * (float) ($rifa['valor_boleta'] ?? 0);

    return [
        'total_proyectado' => $totalProyectado,
        'total_vendido' => (float) $base['total_vendido'],
        'total_recaudado' => (float) $base['total_recaudado'],
        'premio_entregado' => $premio,
        'utilidad_neta' => (float) $base['total_recaudado'] - $premio,
    ];
}

function obtenerInformeMovimientosRifa(PDO $pdo, array $rifa): array
{
    $stmt = $pdo->prepare(
        'SELECT m.fecha, m.motivo, m.valor, m.medio_consignacion, m.observaciones, m.usuario_registro, a.nombre_actividad, s.nombre_completo ' .
        'FROM movimientos m ' .
        'JOIN actividades_maestro a ON a.id_actividad = m.id_actividad ' .
        'LEFT JOIN socios s ON s.id_socio = m.id_socio ' .
        'WHERE m.modulo = "rifas" AND m.id_actividad IN (:ingreso, :premio) ' .
        'ORDER BY m.fecha DESC, m.id_movimiento DESC'
    );
    $stmt->execute([
        ':ingreso' => (int) $rifa['id_actividad_ingreso'],
        ':premio' => (int) $rifa['id_actividad_premio'],
    ]);

    $movimientos = $stmt->fetchAll();

    $totales = [
        'ingresos' => 0,
        'egresos' => 0,
    ];

    foreach ($movimientos as $mov) {
        if ((float) $mov['valor'] >= 0) {
            $totales['ingresos'] += abs((float) $mov['valor']);
        } else {
            $totales['egresos'] += abs((float) $mov['valor']);
        }
    }

    return ['movimientos' => $movimientos, 'totales' => $totales];
}

function obtenerResumenBoletas(PDO $pdo, int $idRifa): array
{
    $stmt = $pdo->prepare('SELECT estado, COUNT(*) as cantidad, COALESCE(SUM(valor),0) as total FROM rifas_boletas WHERE id_rifa = :id GROUP BY estado');
    $stmt->execute([':id' => $idRifa]);
    $datos = ['pendiente' => ['cantidad' => 0, 'total' => 0], 'pagada' => ['cantidad' => 0, 'total' => 0], 'anulada' => ['cantidad' => 0, 'total' => 0]];
    foreach ($stmt->fetchAll() as $row) {
        $estado = $row['estado'];
        $datos[$estado] = ['cantidad' => (int) $row['cantidad'], 'total' => (float) $row['total']];
    }
    return $datos;
}

function exportarBoletasZip(int $idRifa): ?string
{
    return exportarBoletasZipFiltrado($idRifa, null, null);
}

function obtenerConfiguracionGruposRifa(PDO $pdo, int $idRifa): array
{
    $stmt = $pdo->prepare('SELECT * FROM rifas_grupos WHERE id_rifa = :id ORDER BY orden_grupo');
    $stmt->execute([':id' => $idRifa]);
    $grupos = [];
    foreach ($stmt->fetchAll() as $grupo) {
        $socios = json_decode((string) ($grupo['socios_json'] ?? ''), true);
        $asignaciones = json_decode((string) ($grupo['asignaciones_json'] ?? ''), true);
        $grupos[] = [
            'id_grupo' => (int) $grupo['id_grupo'],
            'nombre' => $grupo['nombre'],
            'boletas_por_socio' => (int) $grupo['boletas_por_socio'],
            'metodo_distribucion' => $grupo['metodo_distribucion'],
            'socios' => is_array($socios) ? array_values(array_filter(array_map('intval', $socios), static fn($id) => $id > 0)) : [],
            'asignaciones' => is_array($asignaciones) ? $asignaciones : [],
        ];
    }

    return $grupos;
}

function reiniciarAsignacionesRifa(PDO $pdo, int $idRifa, ?string $usuario = null, bool $forzarConPagos = false): void
{
    $rifa = obtenerRifa($pdo, $idRifa);
    if (!$rifa) {
        throw new RuntimeException('La rifa no existe.');
    }

    $stmtPagos = $pdo->prepare('SELECT COUNT(*) FROM rifas_boletas WHERE id_rifa = :id AND estado = "pagada"');
    $stmtPagos->execute([':id' => $idRifa]);
    $pagadas = (int) $stmtPagos->fetchColumn();
    if ($pagadas > 0 && !$forzarConPagos) {
        throw new RuntimeException('La rifa tiene pagos registrados (' . $pagadas . '). Confirme para continuar con el reinicio.');
    }

    $grupos = obtenerConfiguracionGruposRifa($pdo, $idRifa);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM rifas_boletas_historial WHERE id_rifa = :id')->execute([':id' => $idRifa]);
        $pdo->prepare('DELETE FROM movimientos WHERE modulo = "rifas" AND id_actividad IN (:ingreso, :premio)')->execute([
            ':ingreso' => (int) $rifa['id_actividad_ingreso'],
            ':premio' => (int) $rifa['id_actividad_premio'],
        ]);
        $pdo->prepare('UPDATE rifas_boletas SET id_socio = NULL, estado = "pendiente", fecha_pago = NULL, forma_pago = NULL, usuario_ultimo = :usuario WHERE id_rifa = :id')
            ->execute([':id' => $idRifa, ':usuario' => $usuario]);
        $pdo->prepare('UPDATE rifas SET estado = "abierta", numero_ganador = NULL, id_boleta_ganadora = NULL, premio_valor = NULL, premio_descripcion = NULL, fecha_cierre = NULL WHERE id_rifa = :id')
            ->execute([':id' => $idRifa]);

        asignarBoletasAutomaticas($pdo, $idRifa, $usuario, $rifa, $grupos);
        generarImagenesBoletasRifa($pdo, $idRifa, $rifa);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    recalcularSaldosDesdeMovimientos($pdo);
}

function exportarBoletasZipFiltrado(int $idRifa, ?int $idGrupo = null, ?int $idSocio = null): ?string
{
    $dir = dirname(__DIR__) . '/public/uploads/rifas/' . $idRifa;
    if (!is_dir($dir)) {
        return null;
    }

    $suffix = $idGrupo ? '_grupo_' . $idGrupo : '';
    $suffix .= $idSocio ? '_socio_' . $idSocio : '';
    $zipPath = $dir . '/boletas_rifa_' . $idRifa . $suffix . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return null;
    }

    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $file) {
        if (strtolower($file->getExtension()) !== 'png') {
            continue;
        }
        $real = $file->getPathname();
        $local = ltrim(str_replace($dir, '', $real), '/');
        if ($idGrupo !== null || $idSocio !== null) {
            $partes = explode('/', $local);
            $grupoOk = $idGrupo === null;
            $socioOk = $idSocio === null;
            if (isset($partes[0]) && $idGrupo !== null && preg_match('/^Grupo_' . preg_quote((string) $idGrupo, '/') . '(_|$)/', $partes[0])) {
                $grupoOk = true;
            }
            if (isset($partes[1]) && $idSocio !== null && preg_match('/^Socio_' . preg_quote((string) $idSocio, '/') . '(_|$)/', $partes[1])) {
                $socioOk = true;
            }
            if (!$grupoOk || !$socioOk) {
                continue;
            }
        }
        $zip->addFile($real, $local);
    }

    $zip->close();
    return $zipPath;
}
