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
        $rows = $pdo->query('SELECT fecha, id_socio, id_actividad, motivo, valor, medio_consignacion, es_ingreso, es_egreso FROM movimientos ORDER BY fecha DESC')->fetchAll(PDO::FETCH_NUM);
        generarCSV(['Fecha','Socio','Actividad','Motivo','Valor','Medio','Ingreso','Egreso'],$rows);
        break;
    case 'saldos':
        $rows = $pdo->query('SELECT nombre_completo, saldo_socio FROM socios WHERE activo=1')->fetchAll(PDO::FETCH_NUM);
        generarCSV(['Socio','Saldo'],$rows);
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
