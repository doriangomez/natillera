<?php

declare(strict_types=1);

/**
 * Escribe una línea en el archivo de logs principal.
 */
function registrarLog(string $nivel, string $mensaje, array $contexto = []): void
{
    $directorio = dirname(__DIR__) . '/logs';
    if (!is_dir($directorio)) {
        mkdir($directorio, 0775, true);
    }

    $archivo = $directorio . '/app.log';
    $origen = null;

    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
    foreach ($backtrace as $frame) {
        if (!isset($frame['file']) || str_ends_with($frame['file'], 'logger.php')) {
            continue;
        }

        $origen = [
            'archivo' => $frame['file'],
            'modulo' => basename($frame['file']),
        ];
        break;
    }

    $fecha = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->format('Y-m-d H:i:s.u T');

    if ($origen !== null) {
        $contexto += [
            'modulo_origen' => $origen['modulo'],
            'archivo_origen' => $origen['archivo'],
        ];
    }

    $linea = sprintf('[%s] %s: %s', $fecha, strtoupper($nivel), $mensaje);
    if (!empty($contexto)) {
        $linea .= ' ' . json_encode($contexto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    file_put_contents($archivo, $linea . PHP_EOL, FILE_APPEND);
}

class LoggedPDOStatement extends PDOStatement
{
    private $logger;
    private string $connectionId;

    protected function __construct(callable $logger, string $connectionId)
    {
        $this->logger = $logger;
        $this->connectionId = $connectionId;
    }

    public function execute($params = null): bool
    {
        $inicio = microtime(true);
        $contexto = [
            'conexion' => $this->connectionId,
            'sql' => $this->queryString,
            'parametros' => $params,
        ];

        ($this->logger)('query_start', 'Ejecución de consulta preparada', $contexto);

        try {
            $resultado = parent::execute($params ?? []);
            ($this->logger)(
                'query_success',
                'Consulta ejecutada correctamente',
                $contexto + ['duracion_ms' => round((microtime(true) - $inicio) * 1000, 2)]
            );

            return $resultado;
        } catch (Throwable $e) {
            ($this->logger)(
                'query_error',
                $e->getMessage(),
                $contexto + ['excepcion' => $e->getMessage()]
            );

            throw $e;
        }
    }
}

class LoggedPDO extends PDO
{
    private $logger;
    private string $connectionId;

    public function __construct(
        string $dsn,
        ?string $username = null,
        ?string $password = null,
        array $options = [],
        ?callable $logger = null
    ) {
        $this->logger = $logger ?? 'registrarLog';
        $this->connectionId = bin2hex(random_bytes(4));

        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
        $options[PDO::ATTR_DEFAULT_FETCH_MODE] = PDO::FETCH_ASSOC;
        $options[PDO::ATTR_EMULATE_PREPARES] = false;

        parent::__construct($dsn, $username ?? '', $password ?? '', $options);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [LoggedPDOStatement::class, [$this->logger, $this->connectionId]]);

        ($this->logger)(
            'connection_open',
            'Conexión creada con la base de datos',
            ['conexion' => $this->connectionId, 'dsn' => $dsn]
        );
    }

    public function prepare($statement, $options = []): PDOStatement|false
    {
        ($this->logger)(
            'prepare',
            'Preparando consulta SQL',
            ['conexion' => $this->connectionId, 'sql' => $statement]
        );

        return parent::prepare($statement, $options);
    }

    public function exec($statement): int|false
    {
        ($this->logger)(
            'exec',
            'Ejecutando SQL directo',
            ['conexion' => $this->connectionId, 'sql' => $statement]
        );

        try {
            $filas = parent::exec($statement);
            ($this->logger)(
                'exec_success',
                'SQL directo ejecutado',
                ['conexion' => $this->connectionId, 'sql' => $statement, 'filas_afectadas' => $filas]
            );

            return $filas;
        } catch (Throwable $e) {
            ($this->logger)(
                'exec_error',
                $e->getMessage(),
                ['conexion' => $this->connectionId, 'sql' => $statement, 'excepcion' => $e->getMessage()]
            );

            throw $e;
        }
    }

    public function query(string $statement, ?int $mode = null, ...$fetch_mode_args): PDOStatement|false
    {
        ($this->logger)(
            'query_direct',
            'Consulta directa',
            ['conexion' => $this->connectionId, 'sql' => $statement]
        );

        try {
            $stmt = $mode === null
                ? parent::query($statement)
                : parent::query($statement, $mode, ...$fetch_mode_args);
            ($this->logger)('query_direct_success', 'Consulta directa exitosa', [
                'conexion' => $this->connectionId,
                'sql' => $statement,
            ]);

            return $stmt;
        } catch (Throwable $e) {
            ($this->logger)(
                'query_direct_error',
                $e->getMessage(),
                ['conexion' => $this->connectionId, 'sql' => $statement, 'excepcion' => $e->getMessage()]
            );

            throw $e;
        }
    }
}

