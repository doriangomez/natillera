<?php

function formatoMonedaPrestamoDetalle(float $valor): string {
    return '$' . number_format($valor, 0, ',', '.');
}

function nombreMesPrestamoDetalle(int $mes): string {
    $nombres = getNombresMeses();
    return $nombres[$mes] ?? sprintf('Mes %02d', $mes);
}


function listarPrestamosParaLineaTiempo(PDO $pdo): array {
    $stmt = $pdo->query(
        'SELECT p.id_prestamo, p.es_particular, p.nombre_deudor, p.saldo_capital_actual, p.saldo_intereses_actual, p.estado,' .
        '       s.nombre_completo, aval.nombre_completo AS nombre_aval' .
        '  FROM prestamos p' .
        '  LEFT JOIN socios s ON p.id_socio = s.id_socio' .
        '  LEFT JOIN socios aval ON p.id_socio_aval = aval.id_socio' .
        ' ORDER BY p.fecha_prestamo DESC, p.id_prestamo DESC'
    );

    return $stmt->fetchAll();
}

function cargarDetallePrestamo(PDO $pdo, int $idPrestamo): ?array {
    $stmt = $pdo->prepare(
        'SELECT p.*, s.nombre_completo, aval.nombre_completo AS nombre_aval,' .
        '       (SELECT MAX(fecha_pago) FROM cuotas_prestamo cp WHERE cp.id_prestamo = p.id_prestamo) AS ultima_fecha_pago' .
        '  FROM prestamos p' .
        '  LEFT JOIN socios s ON p.id_socio = s.id_socio' .
        '  LEFT JOIN socios aval ON p.id_socio_aval = aval.id_socio' .
        ' WHERE p.id_prestamo = :id'
    );
    $stmt->execute([':id' => $idPrestamo]);
    $prestamo = $stmt->fetch();
    if (!$prestamo) {
        return null;
    }

    $stmtPeriodos = $pdo->prepare('SELECT * FROM periodos_prestamo WHERE id_prestamo = :id ORDER BY anio, mes');
    $stmtPeriodos->execute([':id' => $idPrestamo]);
    $periodos = $stmtPeriodos->fetchAll();

    $stmtCuotas = $pdo->prepare('SELECT * FROM cuotas_prestamo WHERE id_prestamo = :id ORDER BY COALESCE(fecha_pago, fecha_programada), id_cuota');
    $stmtCuotas->execute([':id' => $idPrestamo]);
    $cuotas = $stmtCuotas->fetchAll();

    $cuotasPorMes = [];
    foreach ($cuotas as $cuota) {
        $fechaBase = $cuota['fecha_programada'] ?: $cuota['fecha_pago'];
        if (!$fechaBase) {
            continue;
        }
        $dt = DateTime::createFromFormat('Y-m-d', (string) $fechaBase);
        if (!$dt) {
            continue;
        }
        $clave = $dt->format('Y-m');
        $cuotasPorMes[$clave][] = $cuota;
    }

    $filas = [];
    $totalCausado = 0.0;
    $totalInteresPagadoPeriodos = 0.0;
    $totalInteresPagadoCuotas = 0.0;
    $totalAbonoCapitalCuotas = 0.0;
    $totalAbonoCapitalPeriodos = 0.0;
    $hoy = new DateTime('today');
    $proximoVencimiento = null;

    foreach ($periodos as $periodo) {
        $clave = sprintf('%04d-%02d', (int) $periodo['anio'], (int) $periodo['mes']);
        $cuotasMes = $cuotasPorMes[$clave] ?? [];
        $interesPagadoCuotas = 0.0;
        $capitalPagadoCuotas = 0.0;
        $fechasInteres = [];
        $fechasCapital = [];
        foreach ($cuotasMes as $cuota) {
            $interes = (float) ($cuota['valor_interes_pagado'] ?? 0);
            $capital = (float) ($cuota['valor_capital_pagado'] ?? 0);
            $interesPagadoCuotas += $interes;
            $capitalPagadoCuotas += $capital;
            if ($interes > 0 && !empty($cuota['fecha_pago'])) {
                $fechasInteres[] = $cuota['fecha_pago'];
            }
            if ($capital > 0 && !empty($cuota['fecha_pago'])) {
                $fechasCapital[] = $cuota['fecha_pago'];
            }
        }

        $interesCausado = (float) ($periodo['interes_causado'] ?? 0);
        $interesPagadoPeriodo = (float) ($periodo['interes_pagado'] ?? 0);
        $abonoCapitalPeriodo = (float) ($periodo['abono_capital'] ?? 0);
        $interesPagado = $interesPagadoCuotas > 0 ? $interesPagadoCuotas : $interesPagadoPeriodo;
        $abonoCapital = $capitalPagadoCuotas > 0 ? $capitalPagadoCuotas : $abonoCapitalPeriodo;
        $finMes = DateTime::createFromFormat('Y-m-d', $clave . '-01');
        $finMes?->modify('last day of this month');
        $diasMora = 0;
        if ($finMes && $interesCausado > $interesPagado && $hoy > $finMes) {
            $diasMora = (int) $finMes->diff($hoy)->format('%a');
        }
        $pendienteInteres = max(0, $interesCausado - $interesPagado);
        if ($proximoVencimiento === null && $pendienteInteres > 0 && $finMes) {
            $dias = (int) $hoy->diff($finMes)->format('%r%a');
            $proximoVencimiento = ['fecha' => $finMes->format('Y-m-d'), 'dias' => $dias, 'valor' => $pendienteInteres];
        }

        $fuente = !empty($cuotasMes) ? 'cuotas_prestamo + periodos_prestamo' : 'Solo periodos_prestamo';
        $filas[] = [
            'mes_label' => nombreMesPrestamoDetalle((int) $periodo['mes']) . ' ' . (int) $periodo['anio'],
            'capital_inicio' => (float) ($periodo['capital_inicio'] ?? 0),
            'interes_causado' => $interesCausado,
            'interes_pagado' => $interesPagado,
            'fechas_interes' => implode(', ', array_unique($fechasInteres)),
            'abono_capital' => $abonoCapital,
            'fechas_capital' => implode(', ', array_unique($fechasCapital)),
            'capital_final' => (float) ($periodo['capital_final'] ?? 0),
            'dias_mora' => $diasMora,
            'estado' => $periodo['estado'] ?: 'Pendiente',
            'fuente' => $fuente,
        ];
        $totalCausado += $interesCausado;
        $totalInteresPagadoPeriodos += $interesPagadoPeriodo;
        $totalInteresPagadoCuotas += $interesPagadoCuotas;
        $totalAbonoCapitalCuotas += $capitalPagadoCuotas;
        $totalAbonoCapitalPeriodos += $abonoCapitalPeriodo;
    }

    $alertas = [];
    foreach ($periodos as $periodo) {
        if ((float) ($periodo['capital_inicio'] ?? 0) == 0.0 && strtolower((string) $prestamo['estado']) !== 'finalizado') {
            $alertas[] = 'Hay meses en periodos_prestamo con capital_inicio = 0 mientras el préstamo sigue activo.';
            break;
        }
    }

    $stmtDupCuotas = $pdo->prepare('SELECT fecha_pago, (valor_capital_pagado + valor_interes_pagado) AS valor, COUNT(*) total FROM cuotas_prestamo WHERE id_prestamo = :id AND fecha_pago IS NOT NULL GROUP BY fecha_pago, valor HAVING COUNT(*) > 1');
    $stmtDupCuotas->execute([':id' => $idPrestamo]);
    if ($stmtDupCuotas->fetch()) {
        $alertas[] = 'Hay pagos duplicados en cuotas_prestamo por la misma fecha y el mismo valor.';
    }
    $stmtDupMov = $pdo->prepare('SELECT fecha, valor, COUNT(*) total FROM movimientos WHERE id_prestamo = :id GROUP BY fecha, valor HAVING COUNT(*) > 1');
    $stmtDupMov->execute([':id' => $idPrestamo]);
    if ($stmtDupMov->fetch()) {
        $alertas[] = 'Hay movimientos duplicados por la misma fecha y el mismo valor.';
    }

    $interesPagadoHistorico = $totalInteresPagadoCuotas > 0 ? $totalInteresPagadoCuotas : $totalInteresPagadoPeriodos;
    $abonoCapitalHistorico = $totalAbonoCapitalCuotas > 0 ? $totalAbonoCapitalCuotas : $totalAbonoCapitalPeriodos;
    if (abs(($totalCausado - $interesPagadoHistorico) - (float) $prestamo['saldo_intereses_actual']) > 1) {
        $alertas[] = 'La diferencia entre interés causado y pagado no coincide con prestamos.saldo_intereses_actual.';
    }
    if (abs(((float) $prestamo['monto_prestamo'] - $abonoCapitalHistorico) - (float) $prestamo['saldo_capital_actual']) > 1) {
        $alertas[] = 'El capital esperado (monto original menos abonos) no coincide con prestamos.saldo_capital_actual.';
    }

    return [
        'prestamo' => $prestamo,
        'filas' => $filas,
        'alertas' => array_values(array_unique($alertas)),
        'totales' => [
            'interes_causado' => $totalCausado,
            'interes_pagado' => $interesPagadoHistorico,
            'abono_capital' => $abonoCapitalHistorico,
        ],
        'proximo_vencimiento' => $proximoVencimiento,
    ];
}
