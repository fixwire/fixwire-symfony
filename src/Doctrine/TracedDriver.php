<?php

declare(strict_types=1);

namespace Fixwire\Symfony\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/** @internal */
final class TracedDriver extends AbstractDriverMiddleware
{
    public function __construct(Driver $driver, private Queries $queries)
    {
        parent::__construct($driver);
    }

    /** @param array<string, mixed> $params */
    public function connect(#[\SensitiveParameter] array $params): Connection
    {
        $driver = \is_string($params['driver'] ?? null) ? $params['driver'] : null;
        // pdo_mysql, mysqli → mysql; pdo_pgsql → postgresql; pdo_sqlite, sqlite3 → sqlite; …
        $system = match (true) {
            $driver === null => null,
            str_contains($driver, 'mysql') => 'mysql',
            str_contains($driver, 'pgsql') => 'postgresql',
            str_contains($driver, 'sqlite') => 'sqlite',
            str_contains($driver, 'sqlsrv') => 'microsoft.sql_server',
            str_contains($driver, 'oci') => 'oracle.db',
            default => $driver,
        };
        $namespace = $params['dbname'] ?? null;

        return new TracedConnection(parent::connect($params), $this->queries, $system, \is_string($namespace) ? $namespace : null);
    }
}
