<?php
require_once __DIR__ . '/functions.php';

function asegurarEsquemaRifas(PDO $pdo): void
{
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
        CONSTRAINT fk_rifa_actividad_ingreso FOREIGN KEY (id_actividad_ingreso) REFERENCES actividades_maestro(id_actividad) ON DELETE RESTRICT ON UPDATE CASCADE,
        CONSTRAINT fk_rifa_actividad_premio FOREIGN KEY (id_actividad_premio) REFERENCES actividades_maestro(id_actividad) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rifas_boletas (
        id_boleta INT AUTO_INCREMENT PRIMARY KEY,
        id_rifa INT NOT NULL,
        numero VARCHAR(5) NOT NULL,
        id_socio INT DEFAULT NULL,
        estado VARCHAR(20) DEFAULT 'pendiente',
        valor DECIMAL(12,2) NOT NULL DEFAULT 0,
        fecha_asignacion DATETIME DEFAULT CURRENT_TIMESTAMP,
        fecha_pago DATETIME DEFAULT NULL,
        observaciones TEXT,
        usuario_ultimo VARCHAR(50) DEFAULT NULL,
        UNIQUE KEY uq_rifa_numero (id_rifa, numero),
        CONSTRAINT fk_boleta_rifa FOREIGN KEY (id_rifa) REFERENCES rifas(id_rifa) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_boleta_socio FOREIGN KEY (id_socio) REFERENCES socios(id_socio) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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

    $stmt = $pdo->prepare('INSERT INTO rifas (nombre, fecha_inicio, fecha_fin, valor_boleta, cantidad_boletas, observaciones, id_actividad_ingreso, id_actividad_premio, usuario_registro)
        VALUES (:nombre, :inicio, :fin, :valor, :cantidad, :obs, :act_ingreso, :act_premio, :usuario)');
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
    ]);

    $idRifa = (int) $pdo->lastInsertId();
    generarBoletasRifa($pdo, $idRifa, (int) $data['cantidad_boletas'], (float) $data['valor_boleta']);
    asignarBoletasAutomaticas($pdo, $idRifa, $data['usuario_registro'] ?? null);

    return $idRifa;
}

function generarBoletasRifa(PDO $pdo, int $idRifa, int $cantidad, float $valor): void
{
    $numeros = [];
    for ($i = 0; $i < $cantidad; $i++) {
        $numeros[] = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    }

    $stmt = $pdo->prepare('INSERT INTO rifas_boletas (id_rifa, numero, valor, usuario_ultimo) VALUES (:id_rifa, :numero, :valor, :usuario)');
    foreach ($numeros as $numero) {
        $stmt->execute([
            ':id_rifa' => $idRifa,
            ':numero' => $numero,
            ':valor' => $valor,
            ':usuario' => $_SESSION['usuario'] ?? null,
        ]);
    }
}

function asignarBoletasAutomaticas(PDO $pdo, int $idRifa, ?string $usuario = null): void
{
    $socios = getSocios($pdo);
    if (empty($socios)) {
        return;
    }

    $boletas = $pdo->prepare('SELECT * FROM rifas_boletas WHERE id_rifa = :id ORDER BY numero');
    $boletas->execute([':id' => $idRifa]);
    $boletas = $boletas->fetchAll();

    shuffle($boletas);
    $indexSocio = 0;
    $totalSocios = count($socios);

    foreach ($boletas as $boleta) {
        $socio = $socios[$indexSocio % $totalSocios];
        $stmtUpd = $pdo->prepare('UPDATE rifas_boletas SET id_socio = :socio, usuario_ultimo = :usuario WHERE id_boleta = :id');
        $stmtUpd->execute([
            ':socio' => $socio['id_socio'],
            ':usuario' => $usuario,
            ':id' => $boleta['id_boleta'],
        ]);

        registrarHistorialBoleta($pdo, (int) $boleta['id_boleta'], $idRifa, $boleta['numero'], 'asignacion', 'Asignación automática a ' . $socio['nombre_completo'], $usuario);
        $indexSocio++;
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

        recalcularSaldosDesdeMovimientos($pdo);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
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
    $stmt = $pdo->prepare('SELECT b.*, s.nombre_completo FROM rifas_boletas b LEFT JOIN socios s ON b.id_socio = s.id_socio WHERE b.id_rifa = :id ORDER BY numero');
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

function registrarPagoBoleta(PDO $pdo, int $idRifa, string $numero, string $fechaPago, string $medio, ?int $idMedio, ?string $usuario): void
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

    crearMovimientoRifa($pdo, (int) $boleta['id_actividad_ingreso'], (int) $boleta['id_socio'], (float) $boleta['valor'], $fechaPago, $medio, $idMedio, 'rifas', 'Recaudo de boleta ' . $numero);

    $stmtUpd = $pdo->prepare('UPDATE rifas_boletas SET estado = "pagada", fecha_pago = :fecha, usuario_ultimo = :usuario WHERE id_boleta = :id');
    $stmtUpd->execute([
        ':fecha' => $fechaPago,
        ':usuario' => $usuario,
        ':id' => $boleta['id_boleta'],
    ]);

    registrarHistorialBoleta($pdo, (int) $boleta['id_boleta'], $idRifa, $numero, 'pago', 'Pago registrado', $usuario);
    recalcularSaldosDesdeMovimientos($pdo);
}

function registrarPremioRifa(PDO $pdo, int $idRifa, string $numeroGanador, float $valorPremio, string $fecha, string $medio, ?int $idMedio, ?string $usuario): void
{
    $stmt = $pdo->prepare('SELECT b.*, r.id_actividad_premio FROM rifas_boletas b JOIN rifas r ON r.id_rifa = b.id_rifa WHERE b.id_rifa = :id AND b.numero = :numero');
    $stmt->execute([':id' => $idRifa, ':numero' => $numeroGanador]);
    $boleta = $stmt->fetch();
    if (!$boleta) {
        throw new RuntimeException('No se encontró la boleta ganadora.');
    }
    if (!$boleta['id_socio']) {
        throw new RuntimeException('El número ganador no tiene socio asignado.');
    }

    crearMovimientoRifa($pdo, (int) $boleta['id_actividad_premio'], (int) $boleta['id_socio'], $valorPremio, $fecha, $medio, $idMedio, 'rifas', 'Premio rifa ' . $idRifa);

    registrarHistorialBoleta($pdo, (int) $boleta['id_boleta'], $idRifa, $numeroGanador, 'premio', 'Pago de premio', $usuario);
    $pdo->prepare('UPDATE rifas SET estado = "cerrada" WHERE id_rifa = :id')
        ->execute([':id' => $idRifa]);
    recalcularSaldosDesdeMovimientos($pdo);
}

function obtenerInformeMovimientosRifa(PDO $pdo, array $rifa): array
{
    $stmt = $pdo->prepare(
        'SELECT m.fecha, m.motivo, m.valor, m.medio_consignacion, m.observaciones, m.usuario_registro, a.nombre_actividad, s.nombre_completo ' .
        'FROM movimientos m ' .
        'JOIN actividades_maestro a ON a.id_actividad = m.id_actividad ' .
        'LEFT JOIN socios s ON s.id_socio = m.id_socio ' .
        'WHERE m.modulo = "rifas" AND m.id_actividad IN (:ingreso, :premio) ' .
        'ORDER BY m.fecha DESC, m.id DESC'
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
?>
