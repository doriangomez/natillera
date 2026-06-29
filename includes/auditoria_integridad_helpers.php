<?php

function auditoriaFiltrosDesdeRequest(array $source): array {
    $fechaInicio = trim((string) ($source['fecha_inicio'] ?? ''));
    $fechaFin = trim((string) ($source['fecha_fin'] ?? ''));
    $idSocio = (int) ($source['id_socio'] ?? 0);
    return [
        'fecha_inicio' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaInicio) ? $fechaInicio : '',
        'fecha_fin' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaFin) ? $fechaFin : '',
        'id_socio' => $idSocio > 0 ? $idSocio : 0,
    ];
}

function auditoriaCondicionesMovimientos(array $filtros, string $alias = 'm', array &$params = []): string {
    $where = [];
    if ($filtros['fecha_inicio'] !== '') {
        $where[] = "$alias.fecha >= :fecha_inicio";
        $params[':fecha_inicio'] = $filtros['fecha_inicio'];
    }
    if ($filtros['fecha_fin'] !== '') {
        $where[] = "$alias.fecha <= :fecha_fin";
        $params[':fecha_fin'] = $filtros['fecha_fin'];
    }
    if ((int) $filtros['id_socio'] > 0) {
        $where[] = "$alias.id_socio = :id_socio";
        $params[':id_socio'] = (int) $filtros['id_socio'];
    }
    return $where ? ' WHERE ' . implode(' AND ', $where) : '';
}

function auditoriaTablaExiste(PDO $pdo, string $tabla): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tabla');
    $stmt->execute([':tabla' => $tabla]);
    return (int) $stmt->fetchColumn() > 0;
}

function obtenerDatosAuditoriaIntegridad(PDO $pdo, array $filtros): array {
    $params = [];
    $whereMov = auditoriaCondicionesMovimientos($filtros, 'm', $params);
    $joinFiltroMov = $whereMov ? ' AND ' . substr($whereMov, 7) : '';
    $paramsSocios = $params;
    $whereSocios = (int) $filtros['id_socio'] > 0 ? ' WHERE s.id_socio = :id_socio' : '';
    $paramsWhereSocios = (int) $filtros['id_socio'] > 0 ? [':id_socio' => (int) $filtros['id_socio']] : [];

    $stmtSocios = $pdo->prepare(
        "SELECT s.id_socio, s.nombre_completo, s.saldo_socio AS saldo_guardado," .
        " COALESCE(SUM(CASE WHEN a.afecta_saldo_socio = 'suma' THEN ABS(m.valor) WHEN a.afecta_saldo_socio = 'resta' THEN -ABS(m.valor) ELSE 0 END), 0) AS saldo_recalculado," .
        " MAX(m.fecha) AS ultimo_movimiento" .
        " FROM socios s" .
        " LEFT JOIN movimientos m ON m.id_socio = s.id_socio" . $joinFiltroMov .
        " LEFT JOIN actividades_maestro a ON a.id_actividad = m.id_actividad" .
        $whereSocios .
        " GROUP BY s.id_socio, s.nombre_completo, s.saldo_socio" .
        " ORDER BY s.nombre_completo"
    );
    $stmtSocios->execute(array_merge($paramsSocios, $paramsWhereSocios));
    $socios = $stmtSocios->fetchAll(PDO::FETCH_ASSOC);
    $inconsistenciasSocios = 0;
    foreach ($socios as &$socio) {
        $socio['diferencia'] = round((float) $socio['saldo_guardado'] - (float) $socio['saldo_recalculado'], 2);
        if (abs((float) $socio['diferencia']) > 0.009) {
            $inconsistenciasSocios++;
        }
    }
    unset($socio);

    $saldoGuardado = (float) $pdo->query('SELECT COALESCE(saldo_actual, 0) FROM natillera_estado WHERE id_estado = 1 LIMIT 1')->fetchColumn();
    $paramsNat = [];
    $whereNat = auditoriaCondicionesMovimientos($filtros, 'm', $paramsNat);
    $stmtNat = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN a.afecta_saldo_natillera = 'suma' THEN ABS(m.valor) WHEN a.afecta_saldo_natillera = 'resta' THEN -ABS(m.valor) ELSE 0 END), 0) FROM movimientos m JOIN actividades_maestro a ON a.id_actividad = m.id_actividad" . $whereNat);
    $stmtNat->execute($paramsNat);
    $saldoRecalculado = (float) $stmtNat->fetchColumn();
    $diferenciaNatillera = round($saldoGuardado - $saldoRecalculado, 2);

    $paramsAct = [];
    $whereAct = auditoriaCondicionesMovimientos($filtros, 'm', $paramsAct);
    $stmtAct = $pdo->prepare(
        "SELECT a.nombre_actividad, a.afecta_saldo_natillera, COUNT(m.id_movimiento) AS cantidad_movimientos," .
        " COALESCE(SUM(ABS(m.valor)),0) AS valor_total," .
        " COALESCE(SUM(CASE WHEN a.afecta_saldo_natillera = 'suma' THEN ABS(m.valor) WHEN a.afecta_saldo_natillera = 'resta' THEN -ABS(m.valor) ELSE 0 END),0) AS impacto_neto" .
        " FROM actividades_maestro a JOIN movimientos m ON m.id_actividad = a.id_actividad" .
        $whereAct .
        " GROUP BY a.id_actividad, a.nombre_actividad, a.afecta_saldo_natillera ORDER BY a.nombre_actividad"
    );
    $stmtAct->execute($paramsAct);
    $actividades = $stmtAct->fetchAll(PDO::FETCH_ASSOC);
    $totalesActividad = ['cantidad_movimientos' => 0, 'valor_total' => 0.0, 'impacto_neto' => 0.0];
    foreach ($actividades as $actividad) {
        $totalesActividad['cantidad_movimientos'] += (int) $actividad['cantidad_movimientos'];
        $totalesActividad['valor_total'] += (float) $actividad['valor_total'];
        $totalesActividad['impacto_neto'] += (float) $actividad['impacto_neto'];
    }

    $alertas = [];
    $paramsNeg = [];
    $whereNeg = auditoriaCondicionesMovimientos($filtros, 'm', $paramsNeg);
    $stmtNeg = $pdo->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(ABS(valor)),0) AS valor FROM movimientos m' . $whereNeg . ($whereNeg ? ' AND ' : ' WHERE ') . 'm.valor < 0');
    $stmtNeg->execute($paramsNeg);
    $neg = $stmtNeg->fetch(PDO::FETCH_ASSOC);
    if ((int) $neg['total'] > 0) {
        $alertas[] = ['tipo' => 'Movimientos con valor negativo', 'detalle' => (int) $neg['total'] . ' movimientos tienen valor negativo directo.', 'monto' => (float) $neg['valor']];
    }

    $paramsPrest = [];
    $wherePrestMov = auditoriaCondicionesMovimientos($filtros, 'm', $paramsPrest);
    $extraSocioPrest = (int) $filtros['id_socio'] > 0 ? ' AND p.id_socio = :prest_id_socio' : '';
    if ((int) $filtros['id_socio'] > 0) { $paramsPrest[':prest_id_socio'] = (int) $filtros['id_socio']; }
    $stmtPrest = $pdo->prepare("SELECT p.id_prestamo, COALESCE(s.nombre_completo, p.nombre_deudor) AS deudor FROM prestamos p LEFT JOIN socios s ON s.id_socio = p.id_socio WHERE p.estado IN ('Activo','En mora')" . $extraSocioPrest . " AND NOT EXISTS (SELECT 1 FROM movimientos m JOIN actividades_maestro a ON a.id_actividad = m.id_actividad WHERE m.id_prestamo = p.id_prestamo AND a.es_prestamo = 1" . ($wherePrestMov ? ' AND ' . substr($wherePrestMov, 7) : '') . ") ORDER BY p.id_prestamo");
    $stmtPrest->execute($paramsPrest);
    foreach ($stmtPrest->fetchAll(PDO::FETCH_ASSOC) as $prestamo) {
        $alertas[] = ['tipo' => 'Préstamo sin desembolso', 'detalle' => 'Préstamo #' . $prestamo['id_prestamo'] . ' (' . $prestamo['deudor'] . ') no tiene movimiento de desembolso asociado.', 'monto' => null];
    }

    if (auditoriaTablaExiste($pdo, 'rifas_boletas')) {
        $paramsRifaB = [];
        $whereBoleta = [];
        if ($filtros['fecha_inicio'] !== '') { $whereBoleta[] = 'DATE(b.fecha_pago) >= :rb_inicio'; $paramsRifaB[':rb_inicio'] = $filtros['fecha_inicio']; }
        if ($filtros['fecha_fin'] !== '') { $whereBoleta[] = 'DATE(b.fecha_pago) <= :rb_fin'; $paramsRifaB[':rb_fin'] = $filtros['fecha_fin']; }
        if ((int) $filtros['id_socio'] > 0) { $whereBoleta[] = 'b.id_socio = :rb_socio'; $paramsRifaB[':rb_socio'] = (int) $filtros['id_socio']; }
        $sqlBoletas = 'SELECT COUNT(*) FROM rifas_boletas b WHERE b.estado = "pagada"' . ($whereBoleta ? ' AND ' . implode(' AND ', $whereBoleta) : '');
        $stmtBoletas = $pdo->prepare($sqlBoletas); $stmtBoletas->execute($paramsRifaB); $boletasPagadas = (int) $stmtBoletas->fetchColumn();
        $paramsMovRifa = [];
        $whereMovRifa = auditoriaCondicionesMovimientos($filtros, 'm', $paramsMovRifa);
        $stmtMovRifa = $pdo->prepare('SELECT COUNT(*) FROM movimientos m JOIN actividades_maestro a ON a.id_actividad = m.id_actividad' . $whereMovRifa . ($whereMovRifa ? ' AND ' : ' WHERE ') . 'a.es_rifa = 1');
        $stmtMovRifa->execute($paramsMovRifa); $movsRifa = (int) $stmtMovRifa->fetchColumn();
        if ($boletasPagadas !== $movsRifa) {
            $alertas[] = ['tipo' => 'Rifas pagadas sin movimiento', 'detalle' => "Boletas pagadas: $boletasPagadas; movimientos de rifa: $movsRifa.", 'monto' => null];
        }
    }

    $paramsNegSoc = (int) $filtros['id_socio'] > 0 ? [':id_socio' => (int) $filtros['id_socio']] : [];
    $stmtNegSoc = $pdo->prepare('SELECT s.id_socio, s.nombre_completo, s.saldo_socio FROM socios s WHERE s.saldo_socio < 0' . ((int) $filtros['id_socio'] > 0 ? ' AND s.id_socio = :id_socio' : '') . " AND NOT EXISTS (SELECT 1 FROM prestamos p WHERE p.id_socio = s.id_socio AND p.estado IN ('Activo','En mora')) ORDER BY s.nombre_completo");
    $stmtNegSoc->execute($paramsNegSoc);
    foreach ($stmtNegSoc->fetchAll(PDO::FETCH_ASSOC) as $socioNeg) {
        $alertas[] = ['tipo' => 'Socio negativo sin préstamo', 'detalle' => $socioNeg['nombre_completo'] . ' tiene saldo negativo sin préstamo activo o en mora.', 'monto' => (float) $socioNeg['saldo_socio']];
    }

    $paramsHue = [];
    $whereHue = auditoriaCondicionesMovimientos($filtros, 'm', $paramsHue);
    $stmtHue = $pdo->prepare("SELECT m.id_movimiento, m.fecha, m.id_socio, s.nombre_completo, m.id_actividad, m.motivo, m.valor, m.modulo FROM movimientos m LEFT JOIN actividades_maestro a ON a.id_actividad = m.id_actividad LEFT JOIN socios s ON s.id_socio = m.id_socio" . $whereHue . ($whereHue ? ' AND ' : ' WHERE ') . "a.id_actividad IS NULL ORDER BY m.fecha DESC, m.id_movimiento DESC");
    $stmtHue->execute($paramsHue);

    return [
        'socios' => $socios,
        'inconsistencias_socios' => $inconsistenciasSocios,
        'natillera' => ['guardado' => $saldoGuardado, 'recalculado' => $saldoRecalculado, 'diferencia' => $diferenciaNatillera, 'correcto' => abs($diferenciaNatillera) <= 0.009],
        'actividades' => $actividades,
        'totales_actividad' => $totalesActividad,
        'alertas' => $alertas,
        'huerfanos' => $stmtHue->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function auditoriaFormatoDinero($valor): string { return '$' . number_format((float) $valor, 2, ',', '.'); }
function auditoriaClaseDiferencia(float $diferencia): string { return abs($diferencia) <= 0.009 ? 'text-success' : 'text-danger'; }
