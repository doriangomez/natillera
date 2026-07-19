<?php
function reconciliacionAsegurarEsquema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS reconciliacion_custodios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(150) NOT NULL,
        tipo ENUM('efectivo','nequi','persona','banco','otro') NOT NULL DEFAULT 'otro',
        activo TINYINT(1) NOT NULL DEFAULT 1,
        observaciones TEXT DEFAULT NULL,
        fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_reconciliacion_custodio_nombre (nombre)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reconciliacion_cortes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fecha_corte DATE NOT NULL,
        saldo_general DECIMAL(12,2) NOT NULL DEFAULT 0,
        cartera_vigente DECIMAL(12,2) NOT NULL DEFAULT 0,
        efectivo_esperado DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_ubicado DECIMAL(12,2) NOT NULL DEFAULT 0,
        diferencia DECIMAL(12,2) NOT NULL DEFAULT 0,
        usuario_registro VARCHAR(100) DEFAULT NULL,
        observaciones TEXT DEFAULT NULL,
        fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_reconciliacion_cortes_fecha (fecha_corte)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reconciliacion_corte_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_corte INT NOT NULL,
        id_custodio INT DEFAULT NULL,
        nombre_custodio VARCHAR(150) NOT NULL,
        tipo_custodio VARCHAR(30) NOT NULL DEFAULT 'otro',
        valor DECIMAL(12,2) NOT NULL DEFAULT 0,
        observaciones TEXT DEFAULT NULL,
        FOREIGN KEY (id_corte) REFERENCES reconciliacion_cortes(id) ON DELETE CASCADE,
        FOREIGN KEY (id_custodio) REFERENCES reconciliacion_custodios(id) ON DELETE SET NULL,
        INDEX idx_reconciliacion_items_corte (id_corte)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reconciliacion_cartera_detalle (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_corte INT NOT NULL,
        id_prestamo INT DEFAULT NULL,
        deudor VARCHAR(150) NOT NULL,
        estado VARCHAR(50) DEFAULT NULL,
        saldo_capital_actual DECIMAL(12,2) NOT NULL DEFAULT 0,
        FOREIGN KEY (id_corte) REFERENCES reconciliacion_cortes(id) ON DELETE CASCADE,
        INDEX idx_reconciliacion_cartera_corte (id_corte)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $stmt = $pdo->prepare('INSERT IGNORE INTO reconciliacion_custodios (nombre,tipo,observaciones) VALUES (?, ?, ?)');
    foreach ([['Efectivo (bolsillos)','efectivo','Custodio inicial sugerido'],['Nequi','nequi','Custodio inicial sugerido'],['Gloria','persona','Custodio inicial sugerido'],['Andrea','persona','Custodio inicial sugerido'],['Bancolombia','banco','Custodio inicial sugerido']] as $c) { $stmt->execute($c); }
}
function reconciliacionFormatoMoneda($v): string { return '$' . number_format((float)$v, 0, ',', '.'); }
function reconciliacionCustodios(PDO $pdo, bool $soloActivos=true): array { reconciliacionAsegurarEsquema($pdo); $sql='SELECT * FROM reconciliacion_custodios'.($soloActivos?' WHERE activo=1':'').' ORDER BY FIELD(tipo,\'efectivo\',\'nequi\',\'persona\',\'banco\',\'otro\'), nombre'; return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
function reconciliacionCarteraVigente(PDO $pdo): array { $sql="SELECT p.id_prestamo, COALESCE(s.nombre_completo,p.nombre_deudor,CONCAT('Préstamo ',p.id_prestamo)) AS deudor, p.estado, COALESCE(p.saldo_capital_actual,0) AS saldo_capital_actual FROM prestamos p LEFT JOIN socios s ON s.id_socio=p.id_socio WHERE COALESCE(p.estado,'') <> 'Finalizado' ORDER BY deudor"; return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
function reconciliacionCalculoActual(PDO $pdo): array { $saldo=getSaldoNatillera($pdo); $cartera=reconciliacionCarteraVigente($pdo); $total=array_sum(array_map(static fn($p)=>(float)$p['saldo_capital_actual'],$cartera)); return ['saldo_general'=>$saldo,'cartera_vigente'=>$total,'efectivo_esperado'=>$saldo-$total,'cartera_detalle'=>$cartera]; }
function reconciliacionHistorico(PDO $pdo): array { reconciliacionAsegurarEsquema($pdo); return $pdo->query('SELECT * FROM reconciliacion_cortes ORDER BY fecha_corte DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC); }
function reconciliacionCorte(PDO $pdo, int $id): ?array { reconciliacionAsegurarEsquema($pdo); $s=$pdo->prepare('SELECT * FROM reconciliacion_cortes WHERE id=:id'); $s->execute([':id'=>$id]); $c=$s->fetch(PDO::FETCH_ASSOC); if(!$c){return null;} foreach(['items'=>'reconciliacion_corte_items','cartera'=>'reconciliacion_cartera_detalle'] as $k=>$t){$q=$pdo->prepare("SELECT * FROM $t WHERE id_corte=:id"); $q->execute([':id'=>$id]); $c[$k]=$q->fetchAll(PDO::FETCH_ASSOC);} return $c; }
?>
