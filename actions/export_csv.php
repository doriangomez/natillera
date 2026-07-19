<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();

$tipo = $_GET['tipo'] ?? '';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$tipo.'_'.date('Ymd_His').'.csv"');

switch ($tipo) {
    case 'socios':
        $rows = $pdo->query('SELECT id_socio, nombre_completo, telefono, numero_polla, periodicidad_pago, valor_presupuestado, saldo_socio FROM socios WHERE activo=1')->fetchAll(PDO::FETCH_NUM);
        generarCSV(['ID','Nombre','Teléfono','Polla','Periodicidad','Presupuesto','Saldo'],$rows);
        break;
    case 'movimientos':
        $fSocio = isset($_GET['socio']) ? (int) $_GET['socio'] : 0;
        $fDesde = $_GET['desde'] ?? '';
        $fHasta = $_GET['hasta'] ?? '';
        $params = [];
        $where = [];
        if ($fSocio) { $where[] = 'm.id_socio = :s'; $params[':s'] = $fSocio; }
        if ($fDesde) { $where[] = 'm.fecha >= :d'; $params[':d'] = $fDesde; }
        if ($fHasta) { $where[] = 'm.fecha <= :h'; $params[':h'] = $fHasta; }
        $sql = "SELECT m.fecha, s.nombre_completo, COALESCE(p.nombre_deudor, s.nombre_completo) AS deudor, a.nombre_actividad, COALESCE(mp.nombre, m.medio_consignacion) medio, m.motivo, CASE a.afecta_saldo_natillera WHEN 'suma' THEN ABS(m.valor) WHEN 'resta' THEN -ABS(m.valor) ELSE 0 END AS valor_contable, CASE WHEN a.afecta_saldo_natillera = 'suma' THEN 'Ingreso' WHEN a.afecta_saldo_natillera = 'resta' THEN 'Egreso' ELSE 'Neutral' END AS tipo_movimiento, m.observaciones
                FROM movimientos m
                LEFT JOIN socios s ON m.id_socio = s.id_socio
                LEFT JOIN actividades_maestro a ON m.id_actividad = a.id_actividad
                LEFT JOIN medios_pago mp ON m.id_medio_pago = mp.id
                LEFT JOIN prestamos p ON a.es_prestamo = 1 AND p.id_socio = m.id_socio";
        if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
        $sql .= ' ORDER BY m.id_movimiento DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        generarCSV(['Fecha','Socio','Deudor','Actividad','Medio','Motivo','Valor contable','Tipo','Observaciones'],$rows);
        break;
    case 'saldos':
        $rows = $pdo->query('SELECT nombre_completo, saldo_socio FROM socios WHERE activo=1')->fetchAll(PDO::FETCH_NUM);
        generarCSV(['Socio','Saldo'],$rows);
        break;
    case 'aportes_socios':
        $condicionAporte = "a.afecta_saldo_socio = 'suma' AND COALESCE(a.es_prestamo,0) = 0 AND COALESCE(a.es_pago_prestamo,0) = 0 AND COALESCE(a.es_pago_interes,0) = 0 AND COALESCE(a.es_polla,0) = 0";
        $rows = $pdo->query(
            "SELECT s.id_socio, s.nombre_completo, s.saldo_socio,\n"
            . "       COALESCE(SUM(CASE WHEN $condicionAporte THEN ABS(m.valor) ELSE 0 END),0) AS total_aportado,\n"
            . "       COALESCE(COUNT(DISTINCT CASE WHEN $condicionAporte THEN DATE_FORMAT(m.fecha, '%Y-%m') END),0) AS meses_aporte\n"
            . "FROM socios s\n"
            . "LEFT JOIN movimientos m ON m.id_socio = s.id_socio\n"
            . "LEFT JOIN actividades_maestro a ON m.id_actividad = a.id_actividad\n"
            . "WHERE s.activo = 1\n"
            . "GROUP BY s.id_socio\n"
            . "ORDER BY s.nombre_completo"
        )->fetchAll();

        $rowsFormateadas = [];
        foreach ($rows as $r) {
            $mesesAporte = (int) ($r['meses_aporte'] ?? 0);
            $totalAportado = (float) ($r['total_aportado'] ?? 0);
            $aportePromedio = $mesesAporte > 0 ? $totalAportado / $mesesAporte : 0;

            $rowsFormateadas[] = [
                $r['id_socio'],
                $r['nombre_completo'],
                round($aportePromedio, 2),
                $totalAportado,
                $r['saldo_socio'],
            ];
        }

        generarCSV(['ID','Nombre','Aporte mensual promedio','Total aportado','Saldo vigente'],$rowsFormateadas);
        break;
    case 'liquidaciones_conceptos':
        asegurarEsquemaLiquidaciones($pdo);
        $fSocio = isset($_GET['socio']) ? (int) $_GET['socio'] : 0;
        $fEstado = trim((string) ($_GET['estado'] ?? 'activa'));
        if (!in_array($fEstado, ['activa', 'reversada', 'editada', 'todas'], true)) {
            $fEstado = 'activa';
        }

        $params = [];
        $where = [];
        if ($fSocio > 0) {
            $where[] = 'l.socio_id = :socio';
            $params[':socio'] = $fSocio;
        }
        if ($fEstado !== 'todas') {
            $where[] = 'l.estado = :estado';
            $params[':estado'] = $fEstado;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmtLiquidaciones = $pdo->prepare(
            "SELECT l.id, l.socio_id, l.fecha, l.estado, s.nombre_completo
             FROM liquidaciones l
             JOIN socios s ON s.id_socio = l.socio_id
             $whereSql
             ORDER BY s.nombre_completo, l.fecha DESC, l.id DESC"
        );
        $stmtLiquidaciones->execute($params);
        $liquidaciones = $stmtLiquidaciones->fetchAll(PDO::FETCH_ASSOC);

        $idsSocios = [];
        $resumenLiquidaciones = [];
        foreach ($liquidaciones as $liq) {
            $idSocio = (int) $liq['socio_id'];
            $idsSocios[$idSocio] = $idSocio;
            if (!isset($resumenLiquidaciones[$idSocio])) {
                $resumenLiquidaciones[$idSocio] = ['nombre' => $liq['nombre_completo'], 'liquidaciones' => []];
            }
            $resumenLiquidaciones[$idSocio]['liquidaciones'][] = '#' . (int) $liq['id'] . ' ' . $liq['fecha'] . ' (' . $liq['estado'] . ')';
        }

        $conceptos = [];
        $periodos = [];
        $datos = [];
        if (!empty($idsSocios)) {
            $placeholders = implode(',', array_fill(0, count($idsSocios), '?'));
            $stmtMovimientos = $pdo->prepare(
                "SELECT m.id_socio, m.anio, m.mes, a.nombre_actividad,
                        SUM(CASE
                            WHEN a.afecta_saldo_natillera = 'resta' OR a.afecta_saldo_socio = 'resta' THEN -ABS(m.valor)
                            ELSE ABS(m.valor)
                        END) AS total
                 FROM movimientos m
                 JOIN actividades_maestro a ON a.id_actividad = m.id_actividad
                 WHERE m.id_socio IN ($placeholders)
                 GROUP BY m.id_socio, m.anio, m.mes, a.id_actividad, a.nombre_actividad
                 ORDER BY m.anio, m.mes, a.nombre_actividad"
            );
            $stmtMovimientos->execute(array_values($idsSocios));
            foreach ($stmtMovimientos->fetchAll(PDO::FETCH_ASSOC) as $mov) {
                $idSocio = (int) $mov['id_socio'];
                $periodo = sprintf('%04d-%02d', (int) $mov['anio'], (int) $mov['mes']);
                $concepto = (string) $mov['nombre_actividad'];
                $conceptos[$concepto] = $concepto;
                $periodos[$periodo] = $periodo;
                $datos[$idSocio][$periodo][$concepto] = (float) $mov['total'];
            }
        }
        ksort($periodos);
        ksort($conceptos, SORT_NATURAL | SORT_FLAG_CASE);

        $header = array_merge(['Socio', 'Liquidaciones', 'Periodo'], array_values($conceptos), ['Total mes']);
        $rows = [];
        foreach ($resumenLiquidaciones as $idSocio => $resumen) {
            foreach ($periodos as $periodo) {
                $totalMes = 0;
                $row = [$resumen['nombre'], implode(', ', $resumen['liquidaciones']), $periodo];
                foreach ($conceptos as $concepto) {
                    $valor = (float) ($datos[$idSocio][$periodo][$concepto] ?? 0);
                    $totalMes += $valor;
                    $row[] = $valor;
                }
                $row[] = $totalMes;
                $rows[] = $row;
            }
        }
        generarCSV($header, $rows);
        break;
    case 'prestamos':
        $rows = $pdo->query('SELECT id_prestamo, nombre_deudor, saldo_capital_actual, saldo_intereses_actual FROM prestamos')->fetchAll(PDO::FETCH_NUM);
        generarCSV(['ID','Deudor','Saldo capital','Saldo intereses'],$rows);
        break;
    case 'pyg':
        $rows = $pdo->query("SELECT a.nombre_actividad, SUM(CASE WHEN a.afecta_saldo_natillera = 'suma' THEN ABS(m.valor) ELSE 0 END) ingresos, SUM(CASE WHEN a.afecta_saldo_natillera = 'resta' THEN ABS(m.valor) ELSE 0 END) egresos FROM movimientos m JOIN actividades_maestro a ON m.id_actividad=a.id_actividad GROUP BY a.id_actividad")->fetchAll(PDO::FETCH_NUM);
        generarCSV(['Actividad','Ingresos','Egresos'],$rows);
        break;
    case 'gastos':
        $rows = $pdo->query("SELECT a.nombre_actividad, SUM(m.valor) total FROM movimientos m JOIN actividades_maestro a ON m.id_actividad=a.id_actividad WHERE a.es_gasto_general=1 GROUP BY a.id_actividad")->fetchAll(PDO::FETCH_NUM);
        generarCSV(['Actividad','Total'],$rows);
        break;
    case 'menu':
        echo "Usa los botones de exportación disponibles en cada pantalla.";
        break;
    default:
        echo "Tipo no soportado";
}
?>
