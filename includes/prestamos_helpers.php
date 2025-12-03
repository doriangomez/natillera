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
