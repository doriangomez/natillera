<?php

function migrarActividadContrapartida(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW COLUMNS FROM actividades_maestro LIKE 'id_actividad_contrapartida'");
    if ($stmt && $stmt->rowCount() === 0) {
        $pdo->exec('ALTER TABLE actividades_maestro ADD COLUMN id_actividad_contrapartida INT NULL AFTER es_gasto_general');
    }

    $stmtFk = $pdo->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :tabla
           AND COLUMN_NAME = :columna
           AND REFERENCED_TABLE_NAME = :tabla_referenciada'
    );
    $stmtFk->execute([
        ':tabla' => 'actividades_maestro',
        ':columna' => 'id_actividad_contrapartida',
        ':tabla_referenciada' => 'actividades_maestro',
    ]);

    if ((int) $stmtFk->fetchColumn() === 0) {
        $pdo->exec(
            'ALTER TABLE actividades_maestro
             ADD CONSTRAINT fk_actividades_contrapartida
             FOREIGN KEY (id_actividad_contrapartida)
             REFERENCES actividades_maestro(id_actividad)
             ON DELETE SET NULL
             ON UPDATE CASCADE'
        );
    }
}
