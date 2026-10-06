<?php

declare(strict_types=1);

namespace Fixwire\Symfony\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/** The trace of the code that sent a message, for the worker that handles it to continue. */
final class TraceStamp implements StampInterface
{
    public function __construct(
        public readonly string $traceparent,
        public readonly ?string $tracestate = null,
        public readonly ?string $baggage = null,
    ) {}
}
