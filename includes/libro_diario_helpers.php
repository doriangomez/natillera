<?php

function libroDiarioColumnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare('SHOW COLUMNS FROM ' . $table . ' LIKE :column');
    $stmt->execute([':column' => $column]);
    return (bool) $stmt->fetch();
}

function libroDiarioMoney(float $value): string {
    return '$' . number_format($value, 0, ',', '.');
}

function libroDiarioQueryString(array $extra = []): string {
    $params = array_merge($_GET, $extra);
    return http_build_query(array_filter($params, static fn($v) => $v !== null && $v !== ''));
}

function libroDiarioPrestamoRequerido(array $row): bool {
    return (int) ($row['es_prestamo'] ?? 0) === 1
        || (int) ($row['es_pago_prestamo'] ?? 0) === 1
        || (int) ($row['es_pago_interes'] ?? 0) === 1
        || in_array(mb_strtolower(trim((string) ($row['nombre_actividad'] ?? ''))), ['pago de intereses', 'pago a préstamo', 'prestamo a socio', 'préstamo a socio'], true);
}

function libroDiarioAplicarSaldo(array &$rows, bool $vistaSocio = false): array {
    $saldo = 0.0;
    $totales = ['ingresos' => 0.0, 'egresos' => 0.0, 'neutral' => 0.0, 'saldo_final' => 0.0];
    foreach ($rows as &$row) {
        $valor = abs((float) ($row['valor'] ?? 0));
        $esIngreso = (int) ($row['es_ingreso'] ?? 0) === 1;
        $esEgreso = (int) ($row['es_egreso'] ?? 0) === 1;
        $esAval = !empty($row['es_aval']);
        $impacto = 0.0;
        if (!$esAval) {
            if ($esIngreso) {
                $impacto = $valor;
                $totales['ingresos'] += $valor;
            } elseif ($esEgreso) {
                $impacto = -$valor;
                $totales['egresos'] += $valor;
            } else {
                $totales['neutral'] += $valor;
            }
            $saldo += $impacto;
        } elseif (!$esIngreso && !$esEgreso) {
            $totales['neutral'] += $valor;
        }
        $row['valor_abs'] = $valor;
        $row['impacto_saldo'] = $impacto;
        $row['saldo_acumulado'] = $saldo;
        $row['alertas'] = [];
        if (empty($row['id_socio']) && !$esAval) {
            $row['alertas'][] = 'Sin socio asignado';
        }
        if (libroDiarioPrestamoRequerido($row) && empty($row['id_prestamo'])) {
            $row['alertas'][] = 'Actividad requiere préstamo asociado';
        }
        if (!empty($row['id_prestamo']) && !empty($row['id_socio']) && !empty($row['prestamo_id_socio']) && (int) $row['prestamo_id_socio'] !== (int) $row['id_socio']) {
            $row['alertas'][] = 'El socio del préstamo no coincide';
        }
    }
    unset($row);
    $totales['saldo_final'] = $saldo;
    return $totales;
}

function libroDiarioObtenerMovimientos(PDO $pdo, array $filtros, ?int $idSocio = null, bool $sinFiltros = false): array {
    $tieneLiquidacion = libroDiarioColumnExists($pdo, 'movimientos', 'id_liquidacion');
    $selectLiquidacion = $tieneLiquidacion ? 'm.id_liquidacion' : 'NULL AS id_liquidacion';
    $where = [];
    $params = [];
    if ($idSocio !== null) {
        $where[] = 'm.id_socio = :id_socio';
        $params[':id_socio'] = $idSocio;
    } elseif (!$sinFiltros && !empty($filtros['socio'])) {
        $where[] = 'm.id_socio = :socio';
        $params[':socio'] = (int) $filtros['socio'];
    }
    if (!$sinFiltros) {
        if (!empty($filtros['desde'])) { $where[] = 'm.fecha >= :desde'; $params[':desde'] = $filtros['desde']; }
        if (!empty($filtros['hasta'])) { $where[] = 'm.fecha <= :hasta'; $params[':hasta'] = $filtros['hasta']; }
        if (!empty($filtros['actividad'])) { $where[] = 'm.id_actividad = :actividad'; $params[':actividad'] = (int) $filtros['actividad']; }
        if (!empty($filtros['medio'])) { $where[] = '(m.id_medio_pago = :medio OR m.medio_consignacion = :medio_txt)'; $params[':medio'] = (int) $filtros['medio']; $params[':medio_txt'] = $filtros['medio']; }
        if (!empty($filtros['prestamo'])) { $where[] = 'm.id_prestamo = :prestamo'; $params[':prestamo'] = (int) $filtros['prestamo']; }
        if (empty($filtros['neutrales'])) { $where[] = '(m.es_ingreso = 1 OR m.es_egreso = 1)'; }
    }
    $sql = "SELECT m.*, $selectLiquidacion, s.nombre_completo, a.nombre_actividad, a.es_prestamo, a.es_pago_prestamo, a.es_pago_interes, mp.nombre AS medio_pago_nombre, p.id_socio AS prestamo_id_socio, p.nombre_deudor
            FROM movimientos m
            LEFT JOIN socios s ON s.id_socio = m.id_socio
            LEFT JOIN actividades_maestro a ON a.id_actividad = m.id_actividad
            LEFT JOIN medios_pago mp ON mp.id = m.id_medio_pago
            LEFT JOIN prestamos p ON p.id_prestamo = m.id_prestamo";
    if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
    $sql .= ' ORDER BY m.fecha ASC, m.id_movimiento ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($idSocio !== null) {
        $sqlAval = str_replace('m.id_socio = :id_socio', 'p.id_socio_aval = :id_socio AND (m.id_socio IS NULL OR m.id_socio <> :id_socio)', $sql);
        $stmtAval = $pdo->prepare($sqlAval);
        $stmtAval->execute($params);
        $avalRows = $stmtAval->fetchAll(PDO::FETCH_ASSOC);
        foreach ($avalRows as &$row) { $row['es_aval'] = 1; }
        $rows = array_merge($rows, $avalRows);
        usort($rows, static fn($a, $b) => [$a['fecha'], $a['id_movimiento'], $a['es_aval'] ?? 0] <=> [$b['fecha'], $b['id_movimiento'], $b['es_aval'] ?? 0]);
    }
    return $rows;
}
