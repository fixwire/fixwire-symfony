<?php

declare(strict_types=1);

namespace Fixwire\Symfony\Messenger;

use Fixwire\Breadcrumb;
use Fixwire\Hub;
use Fixwire\Span;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * @internal Messenger: a message sent to a transport carries the sender's trace; a worker handles
 * each message in its own scope (the transport as a tag, the message as context) as a span that
 * continues that trace, and sends what it captured after each message. A message whose handler
 * throws is a crash, with the message.
 */
final class MessengerListener implements EventSubscriberInterface
{
    /** @var list<?Span> the messages under way, innermost last */
    private array $running = [];

    public function __construct(private bool $breadcrumbs = true, private bool $tracing = true) {}

    public static function getSubscribedEvents(): array
    {
        return [
            SendMessageToTransportsEvent::class => 'onSend',
            WorkerMessageReceivedEvent::class => ['onReceived', 128],
            WorkerMessageHandledEvent::class => ['onHandled', -128],
            WorkerMessageFailedEvent::class => ['onFailed', 128],
        ];
    }

    public function onSend(SendMessageToTransportsEvent $event): void
    {
        $span = Hub::current()->getSpan();
        $envelope = $event->getEnvelope();
        if (!$this->tracing || $span === null || $envelope->last(TraceStamp::class) !== null) {
            return;
        }
        $event->setEnvelope($envelope->with(new TraceStamp($span->traceparent(), $span->tracestate, $span->baggage)));
    }

    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        $hub = Hub::current();
        $message = $event->getEnvelope()->getMessage()::class;
        $scope = $hub->pushScope();
        $scope->setTag('messenger.transport', $event->getReceiverName());
        $scope->setContext('message', ['class' => $message, 'transport' => $event->getReceiverName()]);
        $scope->setTransaction($message);
        if ($this->breadcrumbs) {
            $hub->addBreadcrumb(new Breadcrumb('messenger', "handling {$message}", data: ['transport' => $event->getReceiverName()]));
        }
        $span = null;
        if ($this->tracing) {
            $trace = $event->getEnvelope()->last(TraceStamp::class);
            $span = $hub->continueTrace(
                $trace instanceof TraceStamp ? $trace->traceparent : null,
                $trace instanceof TraceStamp ? $trace->tracestate : null,
                $trace instanceof TraceStamp ? $trace->baggage : null,
                $message,
                'queue.process',
                ['messaging.system' => 'symfony', 'messaging.destination.name' => $event->getReceiverName()],
            );
        }
        $this->running[] = $span;
    }

    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        $this->end(null, false);
    }

    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        $this->end($event->getThrowable(), $event->willRetry());
    }

    private function end(?\Throwable $error, bool $retry): void
    {
        if ($this->running === []) {
            return;
        }
        $span = array_pop($this->running);
        $hub = Hub::current();
        if ($error !== null) {
            // The handler's own exception, not Messenger's wrapper around it.
            $cause = $error instanceof HandlerFailedException ? ($error->getPrevious() ?? $error) : $error;
            $hub->withScope(static function () use ($hub, $cause, $retry): void {
                $hub->getScope()->setTag('messenger.retry', $retry ? 'yes' : 'no');
                $hub->captureException($cause, 'symfony.messenger', false);
            });
            $span?->setError($cause);
        }
        $span?->finish();
        $hub->popScope();
        $hub->flush(); // a worker runs for hours
    }
}
