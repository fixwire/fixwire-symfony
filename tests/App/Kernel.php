<?php

declare(strict_types=1);

namespace Fixwire\Symfony\Tests\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\DBAL\Connection;
use Fixwire\Symfony\FixwireBundle;
use Fixwire\Symfony\Tests\FakeIngest;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SendInvoice
{
    public function __construct(public string $order) {}
}

#[AsMessageHandler]
final class SendInvoiceHandler
{
    public function __invoke(SendInvoice $message): void
    {
        throw new \RuntimeException("the mail server refused the invoice for {$message->order}");
    }
}

#[AsCommand('app:sync-rates')]
final class SyncRates extends Command
{
    protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
    {
        throw new \DomainException('the rates feed is down');
    }
}

/** Drops what health checks capture (the before_send option, as a service). */
final class DropHealthChecks
{
    public function __invoke(\Fixwire\Event $event): ?\Fixwire\Event
    {
        return str_contains((string) $event->message, 'health') ? null : $event;
    }
}

/** What the mocked HTTP client was asked, for the tests. */
final class Recorded
{
    /** @var list<array{method: string, url: string, headers: list<string>}> */
    public static array $requests = [];
}

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public static ?FakeIngest $ingest = null;

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new SecurityBundle(), new DoctrineBundle(), new FixwireBundle()];
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/fixwire-symfony-tests/' . md5(__DIR__) . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/fixwire-symfony-tests/' . md5(__DIR__) . '/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'test',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'http_client' => ['mock_response_factory' => 'app.mock_responses'],
            'messenger' => [
                'transports' => ['async' => ['dsn' => 'in-memory://', 'retry_strategy' => ['max_retries' => 0]]],
                'routing' => [SendInvoice::class => 'async'],
            ],
        ]);
        $container->extension('security', [
            'password_hashers' => [\Symfony\Component\Security\Core\User\InMemoryUser::class => 'plaintext'],
            'providers' => ['users' => ['memory' => ['users' => ['ada' => ['password' => 'secret', 'roles' => ['ROLE_USER']]]]]],
            'firewalls' => ['main' => ['lazy' => true, 'stateless' => true, 'http_basic' => true, 'provider' => 'users']],
            'access_control' => [['path' => '^/admin', 'roles' => 'ROLE_ADMIN']],
        ]);
        $container->extension('doctrine', ['dbal' => ['driver' => 'pdo_sqlite', 'memory' => true]]);
        $container->extension('fixwire', [
            'dsn' => 'http://publickey@ingest.test',
            'release' => 'shop@1.0.0',
            'traces_sample_rate' => 1.0,
            'auto_session_tracking' => true,
            'trace_propagation_targets' => ['inventory.test'],
            'before_send' => DropHealthChecks::class,
            'options' => ['capture_uncaught' => false], // PHPUnit owns the global handlers
        ]);

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set('fixwire.transport', FakeIngest::class)->factory([self::class, 'ingest'])->public();
        $services->set('app.mock_responses', \Closure::class)->factory([self::class, 'responses']);
        $services->set('logger', \Psr\Log\NullLogger::class);
        $services->set(SendInvoiceHandler::class);
        $services->set(SyncRates::class);
        $services->set(DropHealthChecks::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('order_show', '/orders/{id}')->controller([$this, 'showOrder'])->methods(['GET']);
        $routes->add('checkout', '/checkout')->controller([$this, 'checkout'])->methods(['POST']);
        $routes->add('missing', '/missing')->controller([$this, 'missing']);
        $routes->add('admin', '/admin/refunds')->controller([$this, 'missing']);
    }

    public static function ingest(): FakeIngest
    {
        return self::$ingest ??= new FakeIngest();
    }

    public static function responses(): \Closure
    {
        return static function (string $method, string $url, array $options): MockResponse {
            Recorded::$requests[] = ['method' => $method, 'url' => $url, 'headers' => array_values($options['headers'] ?? [])];

            return new MockResponse('{"count":3}', ['http_code' => str_contains($url, 'down') ? 503 : 200]);
        };
    }

    public function showOrder(string $id, Connection $db): JsonResponse
    {
        $db->executeQuery('select ? as id', [$id])->fetchAssociative();

        throw new \RuntimeException("order {$id} has no lines");
    }

    public function checkout(HttpClientInterface $http, MessageBusInterface $bus): JsonResponse
    {
        $stock = $http->request('GET', 'https://inventory.test/stock/sku_1')->toArray()['count'];
        $http->request('GET', 'https://rates.example.com/eur')->getContent();
        $bus->dispatch(new SendInvoice('ord_7'));

        return new JsonResponse(['stock' => $stock]);
    }

    public function missing(): never
    {
        throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('no such page');
    }
}
