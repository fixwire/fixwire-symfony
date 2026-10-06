<?php

declare(strict_types=1);

namespace Fixwire\Symfony\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware as DriverMiddleware;

/**
 * @internal a Doctrine DBAL middleware (DoctrineBundle applies it to each connection): SQL queries
 * as breadcrumbs, and as spans of a sampled trace. SQL goes without its parameters (the values), and
 * is redacted anyway.
 */
final class Middleware implements DriverMiddleware
{
    public function __construct(private bool $breadcrumbs = true, private bool $spans = true) {}

    public function wrap(Driver $driver): Driver
    {
        return new TracedDriver($driver, new Queries($this->breadcrumbs, $this->spans));
    }
}
