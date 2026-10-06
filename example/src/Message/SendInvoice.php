<?php

declare(strict_types=1);

namespace App\Message;

/** Sent to the async transport; a worker handles it, continuing the trace of the order. */
final class SendInvoice
{
    public function __construct(public readonly string $order) {}
}
