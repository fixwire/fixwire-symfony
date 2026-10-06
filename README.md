# Fixwire for Symfony

Fixwire in a Symfony 6.4, 7 or 8 app (PHP 8.1+): the exceptions the kernel
handles, each request as a trace named after its route, the signed-in user,
console commands, Messenger workers, Doctrine queries, the HTTP client, and
release health. It builds on the [PHP SDK](https://github.com/fixwire/fixwire-php), which comes with it.

```sh
composer require fixwire/symfony
```

```php
// config/bundles.php (Flex adds it)
Fixwire\Symfony\FixwireBundle::class => ['all' => true],
```

```sh
# .env.local, or the real environment
FIXWIRE_DSN=https://fw_pk_live_…@ingest.eu.fixwire.io
FIXWIRE_RELEASE=shop@1.4.0
```

Without a DSN (local development, your test suite) it does nothing. To
change more:

```yaml
# config/packages/fixwire.yaml
fixwire:
    traces_sample_rate: 0.2
    trace_propagation_targets: ['%env(INVENTORY_URL)%']
    auto_session_tracking: true   # a session per request (one more request to Fixwire per request)
    before_send: App\Fixwire\DropHealthChecks   # an invokable service
    options:                      # any other option of the PHP SDK
        error_budget: { per_issue_burst: 5 }
```

`bin/console config:dump-reference fixwire` lists everything.

## What it does

- **Exceptions the kernel handles** go to Fixwire as crashes, with the
  request, the route (`GET /orders/{id}`) and the user, and the breadcrumbs
  that led to them: SQL queries (without their values), HTTP calls,
  Messenger messages, console commands. Client errors aren't sent: HTTP
  exceptions below 500 (404s, 403s) and what the firewall turns into a
  sign-in or a 403. An exception you capture yourself is sent once.
- **The signed-in user** comes from the token storage when an event needs
  it. That is the identifier only, plus the email (`getEmail()`) with
  `send_default_pii`. With a lazy firewall, reading it may authenticate the
  request.
- **Each request** gets its own scope and, with tracing on, is a server span
  named after its route's path that continues the caller's trace, with its
  queries and HTTP calls under it.
- **Messenger**: a message sent to a transport carries the sender's trace
  in a stamp. A worker handles each message in its own scope (the
  transport as a tag, the message as context) as a span that continues that
  trace. A handler that throws is a crash, with the handler's own
  exception rather than Messenger's wrapper. A worker sends after each
  message.
- **Console commands** each run in their own scope, named after the
  command. An exception that ends one is a crash.
- **Doctrine DBAL** (with DoctrineBundle): queries and prepared statements
  are breadcrumbs and spans. DBAL 3 and 4 are both supported.
- **The HTTP client** (every client, scoped ones included): each request is
  a client span and a breadcrumb, with trace headers sent only to
  `trace_propagation_targets`. Requests stay asynchronous.

What was captured is sent at the end of the request, in `kernel.terminate`.
Under PHP-FPM that happens after the response has gone out. Route paths are
read from a file built when the cache is warmed, so naming a span costs
nothing.

## Logs

Log records can become breadcrumbs (from INFO) and events (from ERROR). The
bundle defines the handler; Monolog needs one line:

```yaml
# config/packages/monolog.yaml
monolog:
    handlers:
        fixwire:
            type: service
            id: Fixwire\Monolog\Handler
```

## The rest of the SDK

Everything in the PHP SDK works as it does there:
`Fixwire\captureException()`, `Fixwire\withScope()`,
`Fixwire\setTag()`, `Fixwire\trace()`, `Fixwire\withMonitor()` for cron
commands, `Fixwire\captureFeedback()`, …

## Example

[example](example) is a Symfony app (an API with users, a Messenger worker,
a cron command) whose test runs it with `php -S`, `messenger:consume` and
`bin/console` against a fake ingest.

## Building

```sh
composer install
composer test && composer analyse && composer check-format
(cd example && composer install && vendor/bin/phpunit)
```

## License

MIT.
