<?php

declare(strict_types=1);

namespace Fixwire\Symfony\Doctrine;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/** @internal */
final class TracedStatement extends AbstractStatementMiddleware
{
    public function __construct(Statement $statement, private Queries $queries, private string $sql, private ?string $system, private ?string $namespace)
    {
        parent::__construct($statement);
    }

    /**
     * DBAL 3 passes parameters here; DBAL 4 takes none.
     *
     * @param mixed $params
     */
    public function execute($params = null): Result
    {
        $args = \func_get_args();

        return $this->queries->run($this->sql, $this->system, $this->namespace, fn(): Result => parent::execute(...$args));
    }
}
