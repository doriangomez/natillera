<?php

function asegurarTablaPeriodosPrestamo(PDO $pdo): void {
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS periodos_prestamo (
            id_periodo INT AUTO_INCREMENT PRIMARY KEY,
            id_prestamo INT NOT NULL,
            anio INT NOT NULL,
            mes INT NOT NULL,
            capital_inicio DECIMAL(12,2) DEFAULT 0,
            interes_causado DECIMAL(12,2) DEFAULT 0,
            interes_pagado DECIMAL(12,2) DEFAULT 0,
            abono_capital DECIMAL(12,2) DEFAULT 0,
            capital_final DECIMAL(12,2) DEFAULT 0,
            estado VARCHAR(20) DEFAULT "Pendiente",
            UNIQUE KEY uniq_prestamo_mes (id_prestamo, anio, mes),
            FOREIGN KEY (id_prestamo) REFERENCES prestamos(id_prestamo)
        )');
    } catch (Exception $e) {
        // continuar sin interrumpir el flujo
    }
}

function obtenerPeriodosPrestamo(PDO $pdo, array $prestamoIds = []): array {
    asegurarTablaPeriodosPrestamo($pdo);

    $sql = 'SELECT * FROM periodos_prestamo';
    $params = [];
    if (!empty($prestamoIds)) {
        $placeholders = implode(',', array_fill(0, count($prestamoIds), '?'));
        $sql .= " WHERE id_prestamo IN ($placeholders)";
        $params = array_map('intval', $prestamoIds);
    }
    $sql .= ' ORDER BY id_prestamo, anio, mes';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $datos = [];

    foreach ($stmt->fetchAll() as $periodo) {
        $idPrestamo = (int) $periodo['id_prestamo'];
        if (!isset($datos[$idPrestamo])) {
            $datos[$idPrestamo] = [];
        }
        $datos[$idPrestamo][] = $periodo;
    }

    return $datos;
}

function obtenerPeriodosConfiguracionOrdenados(PDO $pdo): array {
    $periodos = getPeriodosConfiguracion($pdo);

    usort($periodos, function ($a, $b) {
        if ((int) $a['anio'] === (int) $b['anio']) {
            return (int) $a['mes'] <=> (int) $b['mes'];
        }

        return (int) $a['anio'] <=> (int) $b['anio'];
    });

    return $periodos;
}

function extenderPeriodosPrestamoHastaMesActual(PDO $pdo): void {
    asegurarTablaPeriodosPrestamo($pdo);

    $stmtPrestamos = $pdo->query('SELECT id_prestamo, fecha_prestamo, monto_prestamo, tasa_interes, saldo_capital_actual, estado FROM prestamos');
    $stmtPeriodos = $pdo->prepare('SELECT * FROM periodos_prestamo WHERE id_prestamo = :id ORDER BY anio, mes');

    $stmtInsert = $pdo->prepare(
        'INSERT INTO periodos_prestamo (id_prestamo, anio, mes, capital_inicio, interes_causado, interes_pagado, abono_capital, capital_final, estado)
         VALUES (:id_prestamo, :anio, :mes, :capital_inicio, :interes_causado, 0, 0, :capital_final, :estado)
         ON DUPLICATE KEY UPDATE capital_inicio = VALUES(capital_inicio), interes_causado = VALUES(interes_causado), capital_final = VALUES(capital_final), estado = VALUES(estado)'
    );

    $mesActual = new DateTime('first day of this month');

    foreach ($stmtPrestamos->fetchAll() as $prestamo) {
        $capitalPendiente = (float) $prestamo['saldo_capital_actual'];
        if ($capitalPendiente <= 0 && ($prestamo['estado'] ?? '') === 'Finalizado') {
            continue;
        }

        $stmtPeriodos->execute([':id' => $prestamo['id_prestamo']]);
        $periodos = $stmtPeriodos->fetchAll();

        if (!empty($periodos)) {
            $ultimo = $periodos[count($periodos) - 1];
            $capitalPendiente = (float) $ultimo['capital_final'];
            $cursor = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $ultimo['anio'], $ultimo['mes'])) ?: clone $mesActual;
            $cursor->modify('+1 month');
        } else {
            $cursor = DateTime::createFromFormat('Y-m-d', (string) $prestamo['fecha_prestamo']) ?: clone $mesActual;
            $cursor->modify('first day of this month');
        }

        $tasa = (float) $prestamo['tasa_interes'];
        while ($cursor <= $mesActual && $capitalPendiente > 0) {
            $interesMes = round($capitalPendiente * ($tasa / 100), 2);
            $stmtInsert->execute([
                ':id_prestamo' => $prestamo['id_prestamo'],
                ':anio' => (int) $cursor->format('Y'),
                ':mes' => (int) $cursor->format('n'),
                ':capital_inicio' => $capitalPendiente,
                ':interes_causado' => $interesMes,
                ':capital_final' => $capitalPendiente,
                ':estado' => 'Mora',
            ]);

            $cursor->modify('+1 month');
        }
    }
}

function obtenerPeriodosParaMatriz(PDO $pdo, array $prestamo): array {
    extenderPeriodosPrestamoHastaMesActual($pdo);

    $periodosPrestamo = obtenerPeriodosPrestamo($pdo, [(int) $prestamo['id_prestamo']]);
    $periodosConfigurados = obtenerPeriodosConfiguracionOrdenados($pdo);
    $periodos = [];
    $periodosRegistrados = $periodosPrestamo[(int) $prestamo['id_prestamo']] ?? [];
    $mapaRegistrados = [];

    foreach ($periodosRegistrados as $periodo) {
        $clave = sprintf('%04d-%02d', (int) $periodo['anio'], (int) $periodo['mes']);
        $mapaRegistrados[$clave] = $periodo;
    }

    $fechaPrestamo = DateTime::createFromFormat('Y-m-d', (string) $prestamo['fecha_prestamo']) ?: new DateTime('first day of this month');
    $fechaPrestamo->modify('first day of this month');

    if (!empty($periodosConfigurados)) {
        foreach ($periodosConfigurados as $periodoConfig) {
            $fechaConfig = DateTime::createFromFormat(
                'Y-m-d',
                sprintf('%04d-%02d-01', (int) $periodoConfig['anio'], (int) $periodoConfig['mes'])
            );

            if (!$fechaConfig || $fechaConfig < $fechaPrestamo) {
                continue;
            }

            $clave = sprintf('%04d-%02d', (int) $periodoConfig['anio'], (int) $periodoConfig['mes']);
            $periodoRegistrado = $mapaRegistrados[$clave] ?? null;

            $periodos[] = [
                'anio' => (int) $periodoConfig['anio'],
                'mes' => (int) $periodoConfig['mes'],
                'label' => $fechaConfig->format('M Y'),
                'fecha' => $fechaConfig,
                'estado_periodo' => $periodoRegistrado['estado'] ?? '',
            ];
        }
    }

    if (empty($periodos)) {
        foreach ($periodosRegistrados as $periodo) {
            $fecha = DateTime::createFromFormat(
                'Y-m-d',
                sprintf('%04d-%02d-01', (int) $periodo['anio'], (int) $periodo['mes'])
            );

            $periodos[] = [
                'anio' => (int) $periodo['anio'],
                'mes' => (int) $periodo['mes'],
                'label' => $fecha ? $fecha->format('M Y') : sprintf('%02d/%04d', $periodo['mes'], $periodo['anio']),
                'fecha' => $fecha,
                'estado_periodo' => $periodo['estado'] ?? '',
            ];
        }
    }

    if (empty($periodos)) {
        $periodos[] = [
            'anio' => (int) $fechaPrestamo->format('Y'),
            'mes' => (int) $fechaPrestamo->format('n'),
            'label' => $fechaPrestamo->format('M Y'),
            'fecha' => $fechaPrestamo,
            'estado_periodo' => '',
        ];
    }

    return $periodos;
}

function extraerIdPrestamoDesdeMotivo(?string $motivo): ?int {
    if (!$motivo) {
        return null;
    }

    if (preg_match('/#(\d+)/', $motivo, $coincidencias)) {
        return (int) $coincidencias[1];
    }

    return null;
}

function obtenerSignoActividad(array $actividad): int {
    $regla = normalizarReglaAfectacion($actividad['afecta_saldo_natillera'] ?? 'neutral');
    if ($regla === 'neutral') {
        $regla = normalizarReglaAfectacion($actividad['afecta_saldo_socio'] ?? 'neutral');
    }

    return $regla === 'suma' ? 1 : ($regla === 'resta' ? -1 : 0);
}

function filtrarMovimientosPrestamo(PDO $pdo, array $prestamo, DateTimeInterface $inicio, DateTimeInterface $fin): array {
    $sql = 'SELECT m.*, a.nombre_actividad, a.afecta_saldo_socio, a.afecta_saldo_natillera, a.es_prestamo, a.es_pago_prestamo, a.es_pago_interes, a.es_interes_causado'
        . ' FROM movimientos m'
        . ' JOIN actividades_maestro a ON m.id_actividad = a.id_actividad'
        . ' WHERE m.modulo IN (\'prestamos\', \'cuotas\')'
        . '   AND m.fecha BETWEEN :inicio AND :fin'
        . '   AND (m.id_prestamo = :id_prestamo OR m.id_prestamo IS NULL)';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':inicio' => $inicio->format('Y-m-d'),
        ':fin' => $fin->format('Y-m-t'),
        ':id_prestamo' => (int) $prestamo['id_prestamo'],
    ]);

    $movimientos = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $mov) {
        if ((int) ($mov['id_prestamo'] ?? 0) === (int) $prestamo['id_prestamo']) {
            $movimientos[] = $mov;
            continue;
        }

        $idDetectado = extraerIdPrestamoDesdeMotivo($mov['motivo'] ?? '') ?? null;
        if ($idDetectado !== null && $idDetectado === (int) $prestamo['id_prestamo']) {
            $movimientos[] = $mov;
            continue;
        }

        $fechaMovimiento = DateTime::createFromFormat('Y-m-d', (string) $mov['fecha']);
        $coincideFecha = $fechaMovimiento && $fechaMovimiento->format('Y-m-d') === (string) $prestamo['fecha_prestamo'];

        if ($coincideFecha && !empty($mov['es_prestamo']) && abs(((float) $mov['valor']) - (float) $prestamo['monto_prestamo']) < 0.01) {
            $movimientos[] = $mov;
            continue;
        }

        if ($coincideFecha && !empty($mov['es_pago_interes']) && abs(((float) $mov['valor']) - abs((float) $prestamo['interes_mensual'])) < 0.01) {
            $movimientos[] = $mov;
        }
    }

    return $movimientos;
}

function construirMatrizMovimientosPrestamo(PDO $pdo, array $prestamo): array {
    $periodos = obtenerPeriodosParaMatriz($pdo, $prestamo);
    $inicio = $periodos[0]['fecha'] ?? new DateTime('first day of this month');
    $fin = $periodos[count($periodos) - 1]['fecha'] ?? new DateTime('last day of this month');

    $movimientos = filtrarMovimientosPrestamo($pdo, $prestamo, $inicio, $fin);

    $actividadesBase = $pdo->query('SELECT * FROM actividades_maestro WHERE es_prestamo = 1 OR es_pago_prestamo = 1 OR es_pago_interes = 1 OR es_interes_causado = 1')->fetchAll();
    $actividades = [];
    foreach ($actividadesBase as $act) {
        $actividades[(int) $act['id_actividad']] = $act;
    }
    foreach ($movimientos as $mov) {
        $actividades[(int) $mov['id_actividad']] = $mov;
    }

    $filas = [];
    $periodoActual = new DateTime('first day of this month');

    foreach ($actividades as $idActividad => $actividad) {
        $filas[$idActividad] = [
            'actividad' => $actividad,
            'meses' => [],
            'saldo' => 0.0,
        ];
        foreach ($periodos as $periodo) {
            $clave = sprintf('%04d-%02d', $periodo['anio'], $periodo['mes']);
            $filas[$idActividad]['meses'][$clave] = [
                'valor' => 0.0,
                'tiene_movimiento' => false,
                'estado' => $periodo['estado_periodo'] ?? '',
            ];
        }
    }

    foreach ($movimientos as $mov) {
        $anioMov = (int) ($mov['anio'] ?: (DateTime::createFromFormat('Y-m-d', (string) $mov['fecha'])?->format('Y') ?? 0));
        $mesMov = (int) ($mov['mes'] ?: (DateTime::createFromFormat('Y-m-d', (string) $mov['fecha'])?->format('n') ?? 0));
        $clave = sprintf('%04d-%02d', $anioMov, $mesMov);
        if (!isset($filas[(int) $mov['id_actividad']]['meses'][$clave])) {
            continue;
        }

        $signo = obtenerSignoActividad($mov);
        $valorFirmado = abs((float) $mov['valor']) * $signo;
        $fila =& $filas[(int) $mov['id_actividad']];
        $fila['meses'][$clave]['valor'] += $valorFirmado;
        $fila['meses'][$clave]['tiene_movimiento'] = true;
    }

    $totales = [
        'capital' => 0.0,
        'intereses' => 0.0,
    ];

    foreach ($filas as $idActividad => &$fila) {
        foreach ($fila['meses'] as $clave => &$celda) {
            if ($celda['tiene_movimiento']) {
                $celda['estado'] = 'Pagado';
                continue;
            }

            if (empty($celda['estado'])) {
                [$anioCelda, $mesCelda] = array_map('intval', explode('-', $clave));
                $fechaCelda = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $anioCelda, $mesCelda)) ?: clone $periodoActual;
                $celda['estado'] = $fechaCelda < $periodoActual ? 'Pendiente' : 'Futuro';
            }
        }
        unset($celda);

        $fila['saldo'] = array_reduce(
            $fila['meses'],
            fn($carry, $celda) => $carry + (float) $celda['valor'],
            0.0
        );

        $actividad = $fila['actividad'];
        if (!empty($actividad['es_prestamo']) || !empty($actividad['es_pago_prestamo'])) {
            $totales['capital'] += $fila['saldo'];
        }
        if (!empty($actividad['es_pago_interes']) || !empty($actividad['es_interes_causado'])) {
            $totales['intereses'] += $fila['saldo'];
        }
    }
    unset($fila);

    return [
        'periodos' => $periodos,
        'filas' => $filas,
        'totales' => $totales,
    ];
}
