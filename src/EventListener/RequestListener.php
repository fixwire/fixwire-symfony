<?php

declare(strict_types=1);

namespace Fixwire\Symfony\EventListener;

use Fixwire\Hub;
use Fixwire\ServerRequest;
use Fixwire\Symfony\Routing\RoutePatterns;
use Fixwire\Symfony\Users;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * @internal each main request: its own scope with the signed-in user, a server span named after its
 * route (GET /orders/{id}) that continues the caller's trace, and its session; the exceptions the
 * kernel handles, as crashes (not client errors: 4xx, sign-ins and access the firewall turns into
 * answers). What was captured is sent once the response has gone out.
 */
final class RequestListener implements EventSubscriberInterface
{
    /** Exceptions the security firewall answers (a sign-in page, a 403): not the app failing. */
    private const SECURITY = [
        'Symfony\Component\Security\Core\Exception\AuthenticationException',
        'Symfony\Component\Security\Core\Exception\AccessDeniedException',
    ];

    private ?ServerRequest $tracked = null;

    private bool $pushed = false;

    /** Requests this kernel served: under a worker runtime (FrankenPHP, RoadRunner), many. */
    private int $served = 0;

    public function __construct(private RoutePatterns $routes, private ?TokenStorageInterface $tokens = null) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 256], // before the router and the firewall
            KernelEvents::EXCEPTION => ['onException', 2], // before the firewall answers its own
            KernelEvents::RESPONSE => ['onResponse', -1024], // once the status is final
            KernelEvents::TERMINATE => ['onTerminate', -1024],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $hub = Hub::current();
        $client = $hub->getClient();
        if (!$event->isMainRequest() || $client === null || !$client->isEnabled()) {
            return;
        }
        $this->reset(); // a request before it that never ended
        $request = $event->getRequest();
        $scope = $hub->pushScope();
        $this->pushed = true;
        if ($this->served++ > 0) {
            // What happened while the worker booted belongs to its first request only.
            $scope->clearBreadcrumbs();
        }
        if ($this->tokens !== null) {
            $tokens = $this->tokens;
            $pii = $client->options()->sendDefaultPii;
            $scope->userProvider = static fn() => Users::signedIn($tokens, $pii);
        }
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[(string) $name] = implode(', ', array_filter($values, 'is_string'));
        }
        $this->tracked = ServerRequest::start($hub, $request->getMethod(), $request->getUri(), $headers, $request->getClientIp());
        // Known once the router matched it: events captured in a controller name it already.
        $routes = $this->routes;
        $this->tracked->request->routeProvider = static fn(): ?string => self::route($routes, $request);
    }

    public function onException(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
            return;
        }
        foreach (self::SECURITY as $class) {
            if ($e instanceof $class) {
                return;
            }
        }
        $hub = Hub::current();
        if ($hub->getClient()?->isCaptured($e) !== true) {
            $hub->captureException($e, 'symfony', false);
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && $this->tracked !== null) {
            $this->tracked->setRoute(self::route($this->routes, $event->getRequest()))->end($event->getResponse()->getStatusCode());
        }
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if ($this->pushed) {
            $this->reset();
            Hub::current()->flush();
        }
    }

    /** Ends what a request left (kernel.reset, between the requests of a worker runtime). */
    public function reset(): void
    {
        $this->tracked?->end(500); // does nothing when it ended
        $this->tracked = null;
        if ($this->pushed) {
            $this->pushed = false;
            Hub::current()->popScope();
        }
    }

    private static function route(RoutePatterns $routes, Request $request): ?string
    {
        return $routes->pathOf($request->attributes->get('_route'));
    }
}
