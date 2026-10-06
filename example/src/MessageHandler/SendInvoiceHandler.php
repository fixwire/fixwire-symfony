<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendInvoice;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendInvoiceHandler
{
    public function __invoke(SendInvoice $message): void
    {
        // The mail provider is down: Fixwire reports it with the message, in the order's trace.
        throw new \RuntimeException("the mail server refused the invoice for {$message->order}");
    }
}
