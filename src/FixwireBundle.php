<?php

declare(strict_types=1);

namespace Fixwire\Symfony;

use Fixwire\Monolog\Handler as MonologHandler;
use Fixwire\Symfony\Doctrine\Middleware as DoctrineMiddleware;
use Fixwire\Symfony\EventListener\ConsoleListener;
use Fixwire\Symfony\EventListener\RequestListener;
use Fixwire\Symfony\HttpClient\TracingHttpClient;
use Fixwire\Symfony\Messenger\MessengerListener;
use Fixwire\Symfony\Routing\RoutePatterns;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fixwire in a Symfony app: the SDK from the fixwire configuration, exceptions the kernel handles,
 * each request, the signed-in user, console commands, Messenger workers, Doctrine queries and the
 * HTTP client.
 *
 *     # config/packages/fixwire.yaml
 *     fixwire:
 *         release: '%env(default::FIXWIRE_RELEASE)%'
 *         traces_sample_rate: 0.2
 */
final class FixwireBundle extends AbstractBundle
{
    protected string $extensionAlias = 'fixwire';

    public function configure(DefinitionConfigurator $definition): void
    {
        $root = $definition->rootNode();
        if (!$root instanceof ArrayNodeDefinition) {
            return;
        }
        $node = $root->children();
        $node->scalarNode('dsn')->defaultValue('%env(default::FIXWIRE_DSN)%')->info('Where to send; nothing is sent without one');
        $node->scalarNode('release')->defaultValue('%env(default::FIXWIRE_RELEASE)%')->info('The app\'s version, such as shop@1.4.0');
        $node->scalarNode('environment')->defaultNull()->info('Where the app runs; the kernel\'s environment when not set');
        $node->floatNode('traces_sample_rate')->defaultValue(0.0)->info('The share of new traces kept');
        $node->arrayNode('trace_propagation_targets')->info('Where outgoing requests carry trace headers: a URL prefix (https://api.example.com/v2), or a host and its subdomains (example.com)')->scalarPrototype();
        $node->booleanNode('send_default_pii')->defaultFalse()->info('Send the user\'s email and IP address and identifying request headers');
        $node->booleanNode('auto_session_tracking')->defaultFalse()->info('A session per request, for release health (one more request to Fixwire per request)');
        $node->scalarNode('before_send')->defaultNull()->info('A service id: an invokable that changes an event or drops it by returning null');
        $node->variableNode('options')->defaultValue([])->info('Any other option of the PHP SDK, in its snake_case names');
        $breadcrumbs = $node->arrayNode('breadcrumbs')->info('What leaves breadcrumbs')->addDefaultsIfNotSet()->children();
        $tracing = $node->arrayNode('tracing')->info('What becomes spans of a sampled trace')->addDefaultsIfNotSet()->children();
        foreach (['queries', 'commands', 'messenger'] as $name) {
            $breadcrumbs->booleanNode($name)->defaultTrue();
        }
        foreach (['queries', 'http_client', 'messenger'] as $name) {
            $tracing->booleanNode($name)->defaultTrue();
        }
    }

    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /** @var array<string, mixed> $extra */
        $extra = \is_array($config['options']) ? $config['options'] : [];
        $options = [
            'dsn' => $config['dsn'],
            'release' => $config['release'],
            'environment' => $config['environment'] ?? '%kernel.environment%',
            'traces_sample_rate' => $config['traces_sample_rate'],
            'trace_propagation_targets' => $config['trace_propagation_targets'],
            'send_default_pii' => $config['send_default_pii'],
            'auto_session_tracking' => $config['auto_session_tracking'],
            'project_root' => '%kernel.project_dir%',
            'track_request' => false, // the request listener does it, with the route
        ] + $extra;
        $container->parameters()->set('fixwire.options', $options);
        if (\is_string($config['before_send']) && $config['before_send'] !== '') {
            $builder->setAlias('fixwire.before_send', $config['before_send'])->setPublic(true);
        }
        /** @var array{queries: bool, commands: bool, messenger: bool} $breadcrumbs */
        $breadcrumbs = $config['breadcrumbs'];
        /** @var array{queries: bool, http_client: bool, messenger: bool} $tracing */
        $tracing = $config['tracing'];

        $services = $container->services();
        $services->set('fixwire.route_patterns', RoutePatterns::class)
            ->args([service('router')->nullOnInvalid(), param('kernel.cache_dir'), param('kernel.debug')])
            ->tag('kernel.cache_warmer');
        $services->set('fixwire.request_listener', RequestListener::class)
            ->args([service('fixwire.route_patterns'), service('security.token_storage')->nullOnInvalid()])
            ->tag('kernel.event_subscriber')
            ->tag('kernel.reset', ['method' => 'reset']);
        if (class_exists(ConsoleEvents::class)) {
            $services->set('fixwire.console_listener', ConsoleListener::class)
                ->args([$breadcrumbs['commands']])
                ->tag('kernel.event_subscriber');
        }
        if (interface_exists(MessageBusInterface::class)) {
            $services->set('fixwire.messenger_listener', MessengerListener::class)
                ->args([$breadcrumbs['messenger'], $tracing['messenger']])
                ->tag('kernel.event_subscriber');
        }
        if (interface_exists(\Doctrine\DBAL\Driver\Middleware::class) && ($breadcrumbs['queries'] || $tracing['queries'])) {
            $services->set('fixwire.doctrine_middleware', DoctrineMiddleware::class)
                ->args([$breadcrumbs['queries'], $tracing['queries']])
                ->tag('doctrine.middleware');
        }
        if (interface_exists(HttpClientInterface::class) && $tracing['http_client']) {
            // The transport under the default client and every scoped one; outermost (a low priority),
            // so that it also wraps a mock transport in tests.
            $services->set('fixwire.http_client', TracingHttpClient::class)
                ->decorate('http_client.transport', null, -64, ContainerBuilder::IGNORE_ON_INVALID_REFERENCE)
                ->args([service('.inner')]);
        }
        // Monolog first: loading the handler without it would fail.
        if (class_exists(\Monolog\Logger::class)) {
            // For config/packages/monolog.yaml: a handler of type service with this id.
            $services->set(MonologHandler::class);
        }
    }

    public function boot(): void
    {
        if ($this->container === null) {
            return;
        }
        $options = $this->container->getParameter('fixwire.options');
        if (!\is_array($options)) {
            return;
        }
        if ($this->container->has('fixwire.before_send')) {
            $options['before_send'] = $this->container->get('fixwire.before_send');
        }
        if ($this->container->has('fixwire.transport')) {
            $options['transport'] = $this->container->get('fixwire.transport'); // for tests
        }
        \Fixwire\init(array_filter($options, static fn(mixed $v): bool => $v !== null && $v !== ''));
    }
}
