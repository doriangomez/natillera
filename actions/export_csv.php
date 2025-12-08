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
        $sql = "SELECT m.fecha, s.nombre_completo, COALESCE(p.nombre_deudor, s.nombre_completo) AS deudor, a.nombre_actividad, COALESCE(mp.nombre, m.medio_consignacion) medio, m.motivo, m.valor, m.es_ingreso, m.es_egreso, m.observaciones
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
        generarCSV(['Fecha','Socio','Deudor','Actividad','Medio','Motivo','Valor','Ingreso','Egreso','Observaciones'],$rows);
        break;
    case 'saldos':
        $rows = $pdo->query('SELECT nombre_completo, saldo_socio FROM socios WHERE activo=1')->fetchAll(PDO::FETCH_NUM);
        generarCSV(['Socio','Saldo'],$rows);
        break;
    case 'aportes_socios':
        $condicionAporte = "m.es_ingreso = 1 AND COALESCE(a.es_prestamo,0) = 0 AND COALESCE(a.es_pago_prestamo,0) = 0 AND COALESCE(a.es_pago_interes,0) = 0 AND COALESCE(a.es_polla,0) = 0";
        $rows = $pdo->query(
            "SELECT s.id_socio, s.nombre_completo, s.saldo_socio,\n"
            . "       COALESCE(SUM(CASE WHEN $condicionAporte THEN m.valor ELSE 0 END),0) AS total_aportado,\n"
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
    case 'prestamos':
        $rows = $pdo->query('SELECT id_prestamo, nombre_deudor, saldo_capital_actual, saldo_intereses_actual FROM prestamos')->fetchAll(PDO::FETCH_NUM);
        generarCSV(['ID','Deudor','Saldo capital','Saldo intereses'],$rows);
        break;
    case 'pyg':
        $rows = $pdo->query("SELECT a.nombre_actividad, SUM(CASE WHEN m.es_ingreso=1 THEN m.valor ELSE 0 END) ingresos, SUM(CASE WHEN m.es_egreso=1 THEN m.valor ELSE 0 END) egresos FROM movimientos m JOIN actividades_maestro a ON m.id_actividad=a.id_actividad GROUP BY a.id_actividad")->fetchAll(PDO::FETCH_NUM);
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
