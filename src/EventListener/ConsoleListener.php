<?php

declare(strict_types=1);

namespace Fixwire\Symfony\EventListener;

use Fixwire\Breadcrumb;
use Fixwire\Hub;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @internal console commands: each in its own scope named after it, an exception that ends one as a
 * crash, and what it captured sent when it ends (a long command doesn't wait for the process).
 */
final class ConsoleListener implements EventSubscriberInterface
{
    private int $depth = 0;

    public function __construct(private bool $breadcrumbs = true) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => ['onCommand', 128],
            ConsoleEvents::ERROR => ['onError', 128],
            ConsoleEvents::TERMINATE => ['onTerminate', -1024],
        ];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $name = $event->getCommand()?->getName() ?? 'console';
        $hub = Hub::current();
        if ($this->breadcrumbs) {
            $hub->addBreadcrumb(new Breadcrumb('command', 'bin/console ' . $name));
        }
        $hub->pushScope()->setTransaction($name)->setTag('command', $name);
        $this->depth++;
    }

    public function onError(ConsoleErrorEvent $event): void
    {
        $hub = Hub::current();
        $e = $event->getError();
        if ($hub->getClient()?->isCaptured($e) !== true) {
            $hub->captureException($e, 'symfony.console', false);
        }
    }

    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        $hub = Hub::current();
        if ($this->depth > 0) {
            $this->depth--;
            $hub->popScope();
        }
        $hub->flush();
    }
}
