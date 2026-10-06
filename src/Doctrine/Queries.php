<?php

declare(strict_types=1);

namespace Fixwire\Symfony\Doctrine;

use Fixwire\Breadcrumb;
use Fixwire\Hub;
use Fixwire\Span;
use Fixwire\SpanKind;

/** @internal records a query that ran: a breadcrumb, and a span dated back to when it started */
final class Queries
{
    public function __construct(private bool $breadcrumbs, private bool $spans) {}

    /**
     * Runs a query and records it, failed or not.
     *
     * @template T
     *
     * @param \Closure(): T $query
     *
     * @return T
     */
    public function run(string $sql, ?string $system, ?string $namespace, \Closure $query): mixed
    {
        $start = microtime(true);
        $error = null;

        try {
            return $query();
        } catch (\Throwable $e) {
            $error = $e;

            throw $e;
        } finally {
            $this->record($sql, $system, $namespace, $start, microtime(true), $error);
        }
    }

    private function record(string $sql, ?string $system, ?string $namespace, float $start, float $end, ?\Throwable $error): void
    {
        $hub = Hub::current();
        if ($hub->getClient()?->isEnabled() !== true) {
            return;
        }
        if ($this->breadcrumbs) {
            $hub->addBreadcrumb(new Breadcrumb('db.query', $sql, type: 'query', data: ['duration_ms' => round(($end - $start) * 1000, 3)]));
        }
        $parent = $hub->getSpan();
        if ($this->spans && $parent !== null && $parent->sampled) {
            $span = Span::start($hub, mb_substr($sql, 0, 200), 'db.query', [
                'db.system.name' => $system,
                'db.namespace' => $namespace,
                'db.query.text' => $sql,
            ], SpanKind::Client, current: false, startTime: $start);
            if ($error !== null) {
                $span->setError($error);
            }
            $span->finish($end);
        }
    }
}
