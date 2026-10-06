<div align="center">

_Bugs reach production. Fixwire finds them first: errors, traces, logs and
AI agent runs in one place, an AI debugger on every plan, and your data
kept in Europe._

[![Discord](https://img.shields.io/badge/Discord-join%20us-5865F2?logo=discord&logoColor=white)](https://fixwire.io/discord)
[![Slack](https://img.shields.io/badge/Slack-community-4A154B?logo=slack&logoColor=white)](https://fixwire.io/slack)
[![X](https://img.shields.io/badge/X-follow%20us-000000?logo=x&logoColor=white)](https://fixwire.io/x)
[![Release](https://img.shields.io/github/v/release/fixwire/fixwire-symfony?label=release)](https://github.com/fixwire/fixwire-symfony/releases)
[![PHP](https://img.shields.io/badge/php-8.1%20%7C%208.2%20%7C%208.3%20%7C%208.4%20%7C%208.5-blue?logo=php&logoColor=white)](https://github.com/fixwire/fixwire-symfony/actions/workflows/ci.yml)
[![Symfony](https://img.shields.io/badge/symfony-6.4%20%7C%207%20%7C%208-000000?logo=symfony&logoColor=white)](https://github.com/fixwire/fixwire-symfony/actions/workflows/ci.yml)
[![CI](https://github.com/fixwire/fixwire-symfony/actions/workflows/ci.yml/badge.svg)](https://github.com/fixwire/fixwire-symfony/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](https://github.com/fixwire/fixwire-symfony/blob/main/LICENSE)

<br/>

</div>

# Fixwire SDK for Symfony

Welcome to the official Symfony SDK for **[Fixwire](https://fixwire.io)**.
It captures the exceptions the kernel handles, each request as a trace
named after its route, the signed-in user, console commands, Messenger
workers, Doctrine queries, the HTTP client, and release health.

## 📦 Getting started

### Prerequisites

- A Fixwire account and project: sign up at [fixwire.io](https://fixwire.io).
- Symfony 6.4, 7 or 8 on PHP 8.1 or later. The bundle builds on the
  [PHP SDK](https://github.com/fixwire/fixwire-php), which comes with it.

### Installation

```sh
composer require fixwire/symfony
```

```php
// config/bundles.php (Flex adds it)
Fixwire\Symfony\FixwireBundle::class => ['all' => true],
```

### Basic configuration

```sh
# .env.local, or the real environment
FIXWIRE_DSN=https://fw_pk_live_…@ingest.eu.fixwire.io
FIXWIRE_RELEASE=shop@1.4.0
```

The DSN is your project's publishable key and its ingest host:
`https://<publishable key>@<host>`. Without `FIXWIRE_DSN` (local
development, your test suite) Fixwire does nothing.

```yaml
# config/packages/fixwire.yaml
fixwire:
    environment: production   # else the kernel's environment
    traces_sample_rate: 0.2   # keep 20% of new traces
    # send_default_pii: true  # send the user's email, IP address and identifying headers
    # options:
    #     redact: false       # turn off on-device masking of secrets and personal data
```

`bin/console config:dump-reference fixwire` lists everything. Nothing in
it stops the container from compiling or the kernel from booting. A key
the bundle doesn't have, a value of the wrong type, or a `before_send`
naming no service is said on PHP's error log when the container is built
(`fixwire: no option 'x', ignored`) and left out, its default taken. A
malformed DSN or an unknown key under `options` is said there too, and
Fixwire stays off.

### Quick usage example

Exceptions the kernel handles are sent by themselves. Anywhere in your
app:

```php
// A message: an issue with its text, its level and the breadcrumbs before it.
Fixwire\captureMessage('Hello Fixwire!');

try {
    $payments->charge($order);
} catch (PaymentException $e) {
    // An error with the request, the route, the user and the breadcrumbs before it.
    Fixwire\captureException($e);
}
```

An exception you capture yourself is sent once.

## ✨ Why Fixwire

- **Secrets and personal data are masked on the device**, with the same
  rules as the Fixwire server, before anything leaves the app. SQL
  breadcrumbs leave out their values.
- **A crash loop costs a few events and a count**, not your quota: the
  error budget sends an issue's first 10 errors, then one a minute with the
  number held back.
- **It never gets in your app's way.** Nothing it does throws into your
  code, a broken DSN or option leaves it off rather than the app down,
  memory and the time a flush takes are bounded, and every string, value,
  stack and request has a strict size limit.
- **OpenTelemetry-native.** It speaks the Fixwire protocol (OpenTelemetry's
  OTLP/HTTP plus a few small JSON endpoints).
- **Trace headers only where you allow**: outgoing requests carry them only
  to `trace_propagation_targets`.
- **Your data is kept in Europe.**
- **Made for Symfony**: route paths are read from a file built when the
  cache is warmed, so naming a span costs nothing; HTTP client requests
  stay asynchronous; worker runtimes keep each request apart.

## 🧩 Integrations

| Integration | What it does | How to use |
| --- | --- | --- |
| Exceptions | What the kernel handles goes to Fixwire as a crash, with the request, the route (`GET /orders/{id}`), the user and the breadcrumbs. Client errors aren't sent: HTTP exceptions below 500 (404s, 403s) and what the firewall turns into a sign-in or a 403 | Automatic |
| Requests | Each request gets its own scope and, with tracing on, is a server span named after its route's path that continues the caller's trace, with its queries and HTTP calls under it | Automatic; `traces_sample_rate` |
| The signed-in user | From the token storage when an event needs it: the identifier only, plus the email (`getEmail()`) with `send_default_pii`. With a lazy firewall, reading it may authenticate the request | Automatic |
| Messenger | A message sent to a transport carries the sender's trace in a stamp. A worker handles each message in its own scope (the transport as a tag, the message as context) as a span that continues that trace. A handler that throws is a crash, with the handler's own exception rather than Messenger's wrapper. A worker sends after each message | Automatic |
| Console commands | Each command runs in its own scope, named after it; an exception that ends one is a crash | Automatic |
| Doctrine DBAL | Queries and prepared statements are breadcrumbs and spans (DBAL 3 and 4, with DoctrineBundle) | Automatic |
| HTTP client | Every client, scoped ones included: each request is a client span and a breadcrumb, with trace headers sent only to `trace_propagation_targets` | Automatic |
| Worker runtimes | Under FrankenPHP or RoadRunner, where one kernel serves request after request, each request still has its own scope, user and breadcrumbs | Automatic |
| Logs (Monolog) | Log records become breadcrumbs (from INFO) and events (from ERROR) | [A Monolog handler](https://github.com/fixwire/fixwire-symfony#logs) |
| Cron commands | Check-ins to a monitor, created from its schedule | `Fixwire\withMonitor()` in the command |
| Release health | Each request is a session, ended well, with an error, or crashed | `auto_session_tracking: true` |

### Logs

The bundle defines the handler; Monolog needs one entry:

```yaml
# config/packages/monolog.yaml
monolog:
    handlers:
        fixwire:
            type: service
            id: Fixwire\Monolog\Handler
```

### When data is sent

What was captured is sent at the end of the request, in `kernel.terminate`:
under PHP-FPM, after the response has gone out.

### The rest of the PHP SDK

Everything in the [PHP SDK](https://github.com/fixwire/fixwire-php) works
as it does there: `Fixwire\captureException()`, `Fixwire\withScope()`,
`Fixwire\setTag()`, `Fixwire\trace()`, `Fixwire\withMonitor()` for cron
commands, `Fixwire\captureFeedback()`, …

<a name="configuration"></a>

## ⚙️ Configuration

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

| Option | Default | What it does |
| --- | --- | --- |
| `dsn` | `FIXWIRE_DSN` | Where to send; nothing is sent without one |
| `release` | `FIXWIRE_RELEASE` | The app's version, such as `shop@1.4.0`; release health needs one |
| `environment` | the kernel's environment | Where the app runs |
| `traces_sample_rate` | 0 | Share of new traces kept; continued traces follow the caller |
| `trace_propagation_targets` | none | Where outgoing requests carry trace headers (see below) |
| `send_default_pii` | off | Send the user's email and IP address and identifying request headers |
| `auto_session_tracking` | off | A session per request, for crash-free sessions and users per release (one more request to Fixwire per request) |
| `before_send` | none | A service id: an invokable that changes an event or drops it by returning `null` |
| `options` | none | Any other option of the [PHP SDK](https://github.com/fixwire/fixwire-php#configuration), in its snake_case name (`sample_rate`, `error_budget`, `sensitive_keys`, `redact`, `max_value_length`, `max_stack_frames`, …) |
| `breadcrumbs.queries`, `.commands`, `.messenger` | on | What leaves breadcrumbs |
| `tracing.queries`, `.http_client`, `.messenger` | on | What becomes spans of a sampled trace |

Files in stack traces are named relative to the project directory.

### Trace propagation targets

A target with `://` is a URL prefix (`https://api.example.com/v2`); any
other is a host, with a port if it has one, and matches that host and its
subdomains (`example.com` matches `api.example.com`, not `badexample.com`).
URLs are compared without their user info, query and fragment.

### Changing or dropping events

```php
// src/Fixwire/DropHealthChecks.php, registered as a service
namespace App\Fixwire;

final class DropHealthChecks
{
    public function __invoke(\Fixwire\Event $event): ?\Fixwire\Event
    {
        return str_contains((string) $event->transaction, '/health') ? null : $event;
    }
}
```

### Sampling and redaction

`traces_sample_rate` keeps a share of new traces; a trace continued from a
caller follows the caller's decision. Secrets and personal data are masked
on the device with the server's rules; under `options`, `sensitive_keys`
replaces the list of key fragments whose values are filtered whole, and
`redact: false` turns masking off.

## 🧪 Examples

- [example](https://github.com/fixwire/fixwire-symfony/tree/main/example):
  a Symfony app (an API with users, a Messenger worker, a cron command)
  whose tests run it with `php -S`, `messenger:consume` and `bin/console`
  against a fake ingest.

## 📚 Documentation

The full guide lives in this README and the examples.

- [Configuration](https://github.com/fixwire/fixwire-symfony#configuration)
- [Examples](https://github.com/fixwire/fixwire-symfony/tree/main/example)
- [Changelog](https://github.com/fixwire/fixwire-symfony/blob/main/CHANGELOG.md)
- [Security policy](https://github.com/fixwire/fixwire-symfony/blob/main/SECURITY.md)
- [Contributing guide](https://github.com/fixwire/fixwire-symfony/blob/main/CONTRIBUTING.md)
- [The PHP SDK](https://github.com/fixwire/fixwire-php)

## 🚧 Coming from another error tracker?

The API follows the shape most error-tracking SDKs share: `init`, capture
an exception or a message, the user, tags, breadcrumbs and spans. Moving
over is mostly a change of package and DSN: remove the old bundle, then
`composer require fixwire/symfony` and set `FIXWIRE_DSN`. There is no
`init` call to write: the bundle does it from `config/packages/fixwire.yaml`,
and `before_send` is a service, as in most bundles.

## 🙌 Want to contribute?

We'd love your help, whether it's a bug report, a fix or a new
integration. Read the
[contributing guide](https://github.com/fixwire/fixwire-symfony/blob/main/CONTRIBUTING.md),
browse the [open issues](https://github.com/fixwire/fixwire-symfony/issues),
or pick one of the
[good first issues](https://github.com/fixwire/fixwire-symfony/issues?q=is%3Aopen+label%3A%22good+first+issue%22).

```sh
composer install
composer check-format && composer analyse && composer test
(cd example && composer install && vendor/bin/phpunit)
```

## 🛟 Need help?

- Questions: join us on [Discord](https://fixwire.io/discord) or
  [Slack](https://fixwire.io/slack).
- Bugs: open a [GitHub issue](https://github.com/fixwire/fixwire-symfony/issues).
- Found a security issue? Please don't open an issue; follow the
  [security policy](https://github.com/fixwire/fixwire-symfony/blob/main/SECURITY.md).

## 🔗 Resources

- [Website](https://fixwire.io)
- [Pricing](https://fixwire.io/pricing)
- [Discord](https://fixwire.io/discord)
- [Slack](https://fixwire.io/slack)
- [X](https://fixwire.io/x)
- [Changelog](https://github.com/fixwire/fixwire-symfony/blob/main/CHANGELOG.md)
- [Examples](https://github.com/fixwire/fixwire-symfony/tree/main/example)
- [Security policy](https://github.com/fixwire/fixwire-symfony/blob/main/SECURITY.md)

## 📃 License

The SDK is open source under the MIT license; see
[LICENSE](https://github.com/fixwire/fixwire-symfony/blob/main/LICENSE).

## 😘 Contributors

Thanks to everyone who helps make Fixwire better!

<a href="https://github.com/fixwire/fixwire-symfony/graphs/contributors"><img src="https://contrib.rocks/image?repo=fixwire/fixwire-symfony" alt="Contributors" /></a>
