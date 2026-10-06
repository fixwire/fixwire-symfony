<?php

declare(strict_types=1);

namespace Fixwire\Symfony\Tests;

use Fixwire\Client;
use Fixwire\Hub;
use Fixwire\Symfony\Messenger\TraceStamp;
use Fixwire\Symfony\Tests\App\Kernel;
use Fixwire\Symfony\Tests\App\Recorded;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

final class SymfonyTest extends WebTestCase
{
    private KernelBrowser $browser;

    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . '/fixwire-symfony-tests');
    }

    protected function setUp(): void
    {
        Hub::setCurrent(new Hub()); // a fresh scope for each app
        Client::resetRateLimits();
        Kernel::$ingest = new FakeIngest();
        Recorded::$requests = [];
        $this->browser = static::createClient();
        $this->browser->disableReboot();
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    private function ingest(): FakeIngest
    {
        return Kernel::ingest();
    }

    public function testReportsTheExceptionsTheKernelHandles(): void
    {
        $this->browser->request('GET', '/orders/7', server: [
            'HTTP_TRACEPARENT' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
            'PHP_AUTH_USER' => 'ada',
            'PHP_AUTH_PW' => 'secret',
        ]);
        self::assertSame(500, $this->browser->getResponse()->getStatusCode());

        $events = $this->ingest()->events();
        self::assertCount(1, $events);
        $e = $events[0];
        self::assertSame(['RuntimeException', 'order 7 has no lines', false], [$e['exception.type'], $e['exception.message'], $e['fixwire.handled']]);
        self::assertSame('symfony', $e['fixwire.exceptions'][0]['mechanism']['type']);
        self::assertSame('GET /orders/{id}', $e['fixwire.transaction']);
        self::assertSame('ada', $e['user.id'], 'from the lazy firewall');
        self::assertSame('select ? as id', $e['fixwire.breadcrumbs'][array_key_last($e['fixwire.breadcrumbs'])]['message']);
        $frames = $e['fixwire.exceptions'][0]['frames'];
        self::assertSame('tests/App/Kernel.php', $frames[array_key_last($frames)]['file'], 'relative to the project');

        $request = $this->ingest()->span('GET /orders/{id}');
        self::assertNotNull($request);
        self::assertSame(['4bf92f3577b34da6a3ce929d0e0e4736', '00f067aa0ba902b7'], [$request['traceId'], $request['parentSpanId']]);
        self::assertSame(500, $request['attributes']['http.response.status_code']);
        self::assertSame($request['spanId'], $e['spanId']);
        $query = $this->ingest()->span('select ? as id');
        self::assertNotNull($query);
        self::assertSame([$request['spanId'], 'sqlite'], [$query['parentSpanId'], $query['attributes']['db.system.name']]);

        $sessions = $this->ingest()->bodies('/v1/sessions');
        self::assertSame(1, $sessions[0]['aggregates'][0]['crashed'] ?? 0);
        $resource = $this->ingest()->received[0]['body']['resourceLogs'][0]['resource']['attributes'];
        self::assertContains(['key' => 'deployment.environment.name', 'value' => ['stringValue' => 'test']], $resource);
    }

    public function testLeavesOutClientErrors(): void
    {
        $this->browser->request('GET', '/missing');
        self::assertSame(404, $this->browser->getResponse()->getStatusCode());
        $this->browser->request('GET', '/no-such-route');
        self::assertSame(404, $this->browser->getResponse()->getStatusCode());
        $this->browser->request('GET', '/admin/refunds');
        self::assertSame(401, $this->browser->getResponse()->getStatusCode());
        $this->browser->request('GET', '/admin/refunds', server: ['PHP_AUTH_USER' => 'ada', 'PHP_AUTH_PW' => 'secret']);
        self::assertSame(403, $this->browser->getResponse()->getStatusCode());

        self::assertSame([], $this->ingest()->events());
        self::assertSame(404, $this->ingest()->span('GET /missing')['attributes']['http.response.status_code'] ?? null);
    }

    public function testTracesOutgoingRequestsAndMessages(): void
    {
        $this->browser->request('POST', '/checkout');
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        $request = $this->ingest()->span('POST /checkout');
        $call = $this->ingest()->span('GET https://inventory.test/stock/sku_1');
        self::assertNotNull($request);
        self::assertNotNull($call);
        self::assertSame([$request['traceId'], $request['spanId']], [$call['traceId'], $call['parentSpanId']]);
        self::assertSame(200, $call['attributes']['http.response.status_code']);
        self::assertContains('traceparent: 00-' . $request['traceId'] . '-' . $call['spanId'] . '-01', Recorded::$requests[0]['headers']);
        self::assertSame([], preg_grep('/^traceparent/i', Recorded::$requests[1]['headers']), 'not a propagation target');

        // The message waits on its transport with the request's trace.
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        $stamp = $sent[0]->last(TraceStamp::class);
        self::assertInstanceOf(TraceStamp::class, $stamp);
        self::assertSame($request['traceId'], substr($stamp->traceparent, 3, 32));

        // A worker handles it (what messenger:consume runs): its handler throws.
        $this->ingest()->received = [];
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(1));
        (new Worker(['async' => $transport], self::getContainer()->get(MessageBusInterface::class), $dispatcher))->run();

        $events = $this->ingest()->events();
        self::assertCount(1, $events);
        $e = $events[0];
        self::assertSame('the mail server refused the invoice for ord_7', $e['exception.message'], "the handler's exception, not Messenger's wrapper");
        self::assertSame(['symfony.messenger', false], [$e['fixwire.exceptions'][0]['mechanism']['type'], $e['fixwire.handled']]);
        self::assertSame('Fixwire\Symfony\Tests\App\SendInvoice', $e['fixwire.transaction']);
        self::assertSame(['messenger.transport' => 'async', 'messenger.retry' => 'no'], $e['fixwire.tags']);
        $job = $this->ingest()->span('Fixwire\Symfony\Tests\App\SendInvoice');
        self::assertNotNull($job);
        self::assertSame([$request['traceId'], 2], [$job['traceId'], $job['status']['code']], "the worker continues the request's trace");
        self::assertSame($e['spanId'], $job['spanId']);
    }

    public function testChangesOrDropsEventsWithAService(): void
    {
        \Fixwire\captureMessage('health check failed');
        \Fixwire\captureMessage('disk almost full');
        Hub::current()->flush();
        self::assertSame(['disk almost full'], array_column($this->ingest()->events(), 'body'));
    }

    public function testReportsFailedCommands(): void
    {
        $app = new Application(self::$kernel);
        $app->setAutoExit(false);
        $tester = new ApplicationTester($app);
        self::assertSame(1, $tester->run(['command' => 'app:sync-rates']));

        $events = $this->ingest()->events();
        self::assertCount(1, $events);
        self::assertSame(['the rates feed is down', 'symfony.console', 'app:sync-rates'], [
            $events[0]['exception.message'], $events[0]['fixwire.exceptions'][0]['mechanism']['type'], $events[0]['fixwire.transaction'],
        ]);
        self::assertNull(Hub::current()->getScope()->getTransaction(), "the command's scope ended");
    }
}
