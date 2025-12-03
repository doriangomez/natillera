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
