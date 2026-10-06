<?php

declare(strict_types=1);

namespace Fixwire\Symfony\Doctrine;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * @internal queries and prepared statements, recorded (the ORM prepares them all). Statements run
 * with exec() are not: its return type differs between DBAL 3 and 4.
 */
final class TracedConnection extends AbstractConnectionMiddleware
{
    public function __construct(Connection $connection, private Queries $queries, private ?string $system, private ?string $namespace)
    {
        parent::__construct($connection);
    }

    public function query(string $sql): Result
    {
        return $this->queries->run($sql, $this->system, $this->namespace, fn(): Result => parent::query($sql));
    }

    public function prepare(string $sql): Statement
    {
        return new TracedStatement(parent::prepare($sql), $this->queries, $sql, $this->system, $this->namespace);
    }
}
