<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Runs the app as it runs for real (`php -S` in front of public/index.php in the prod environment,
 * a Messenger worker, a cron command) against a fake Fixwire and a fake inventory service, and
 * checks what Fixwire receives.
 */
final class ExampleTest extends TestCase
{
    /** @var list<resource> */
    private array $servers = [];

    /** @var list<string> */
    private array $files = [];

    private string $ingest;

    /** @var array<string, string> */
    private array $env;

    protected function setUp(): void
    {
        $this->ingest = $this->tempFile();
        $inventoryLog = $this->tempFile();
        $database = $this->tempFile();
        $ingest = $this->serve(['-S', '127.0.0.1:%d', __DIR__ . '/ingest.php'], ['FAKE_INGEST_LOG' => $this->ingest]);
        $inventory = $this->serve(['-S', '127.0.0.1:%d', __DIR__ . '/inventory.php'], ['INVENTORY_LOG' => $inventoryLog]);
        $this->env = [
            'FIXWIRE_DSN' => "http://examplekey@127.0.0.1:{$ingest}",
            'INVENTORY_URL' => "http://127.0.0.1:{$inventory}",
            'APP_ENV' => 'prod',
            'APP_DEBUG' => '0',
            'DATABASE_URL' => "sqlite:///{$database}", // shared by the web server and the worker
        ];
        [$code, $out] = $this->console(['cache:clear']);
        self::assertSame(0, $code, $out);
        file_put_contents($this->ingest, ''); // what warming the cache sent, if anything
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            proc_terminate($server);
            proc_close($server);
        }
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testTheApiAndItsWorker(): void
    {
        // The built-in server keeps the environment out of $_SERVER and $_ENV unless asked; then
        // .env would win over it.
        $port = $this->serve(['-d', 'variables_order=EGPCS', '-S', '127.0.0.1:%d', \dirname(__DIR__) . '/public/index.php'], $this->env);
        $base = "http://127.0.0.1:{$port}";
        $json = ['Content-Type: application/json'];
        $ada = 'Authorization: Basic ' . base64_encode('ada:secret');
        $grace = 'Authorization: Basic ' . base64_encode('grace:secret');

        $this->assertAnswered(200, $this->http('GET', "{$base}/orders/7", null, []));
        $this->assertAnswered(401, $this->http('POST', "{$base}/orders", '{"sku":"sku_1","card":"4242424242424242"}', $json));
        $this->assertAnswered(201, $this->http('POST', "{$base}/orders", '{"sku":"sku_1","card":"4242424242424242"}', [...$json, $ada]));
        $this->assertAnswered(402, $this->http('POST', "{$base}/orders", '{"sku":"sku_2","card":"4000000000000002"}', [...$json, $grace]));
        $this->assertAnswered(500, $this->http('GET', "{$base}/admin/report", null, []));
        $requests = $this->received(12); // a trace and a session per request, the two errors

        $events = $this->events($requests);
        self::assertSame(['RuntimeException', 'DivisionByZeroError'], array_keys($events), 'not the 401');
        $declined = $events['RuntimeException'];
        self::assertSame(['RuntimeException', 'DomainException'], array_column($declined['fixwire.exceptions'], 'type'));
        self::assertSame(['POST /orders', 'grace', 'sku_2'], [$declined['fixwire.transaction'], $declined['user.id'], $declined['fixwire.contexts']['order']['sku']]);
        self::assertContains('http', array_column($declined['fixwire.breadcrumbs'], 'category'), 'the call to the inventory service');
        self::assertStringNotContainsString('4000000000000002', json_encode($requests, \JSON_THROW_ON_ERROR), 'the card stays in the app');

        $crash = $events['DivisionByZeroError'];
        self::assertSame(['symfony', false, 'GET /admin/report'], [$crash['fixwire.exceptions'][0]['mechanism']['type'], $crash['fixwire.handled'], $crash['fixwire.transaction']]);
        $frames = $crash['fixwire.exceptions'][0]['frames'];
        self::assertSame(['src/Controller/OrderController.php', true], [$frames[array_key_last($frames)]['file'], $frames[array_key_last($frames)]['in_app']]);

        $spans = $this->spans($requests);
        $show = $this->one($spans, static fn(array $s): bool => $s['name'] === 'GET /orders/{id}');
        self::assertStringStartsWith('select ? as id', $this->one($spans, static fn(array $s): bool => ($s['parentSpanId'] ?? null) === $show['spanId'])['name']);
        $orders = array_values(array_filter($spans, static fn(array $s): bool => $s['name'] === 'POST /orders'));
        self::assertCount(3, $orders, 'the 401 too');
        self::assertSame(['exited' => 3, 'errored' => 1, 'crashed' => 1], $this->sessions($requests));

        // The worker handles the invoice of ada's order, continuing its trace.
        $placed = $this->one($orders, static fn(array $s): bool => self::statusOf($s) === 201);
        file_put_contents($this->ingest, '');
        [$code, $out] = $this->console(['messenger:consume', 'async', '--limit=1', '--time-limit=20']);
        self::assertSame(0, $code, $out);
        $requests = $this->received(2);
        $failure = $this->events($requests)['RuntimeException'] ?? null;
        self::assertNotNull($failure, json_encode($requests, \JSON_THROW_ON_ERROR));
        self::assertStringStartsWith('the mail server refused the invoice for ord_', $failure['exception.message']);
        self::assertSame(['symfony.messenger', 'App\Message\SendInvoice', 'async'], [
            $failure['fixwire.exceptions'][0]['mechanism']['type'], $failure['fixwire.transaction'], $failure['fixwire.tags']['messenger.transport'],
        ]);
        $job = $this->one($this->spans($requests), static fn(array $s): bool => $s['name'] === 'App\Message\SendInvoice');
        self::assertSame([$placed['traceId'], $placed['spanId']], [$job['traceId'], $job['parentSpanId']], "the worker continues the order's trace");
    }

    public function testTheNightlyReports(): void
    {
        [$code, $out] = $this->console(['app:send-reports']);
        self::assertSame(1, $code, $out);
        self::assertStringContainsString('acme: 20.00 EUR', $out);
        $requests = $this->received(3);

        $checkIns = array_values(array_filter($requests, static fn(array $r): bool => $r['path'] === '/v1/check-ins/nightly-report'));
        self::assertSame(['in_progress', 'error'], array_map(static fn(array $r): string => $r['body']['status'], $checkIns));
        self::assertSame(['schedule' => ['type' => 'crontab', 'value' => '0 3 * * *'], 'checkin_margin' => 10, 'timezone' => 'Europe/Berlin'], $checkIns[0]['body']['monitor_config']);

        $events = array_values(array_filter($this->allEvents($requests), static fn(array $e): bool => isset($e['exception.message'])));
        $byMessage = array_column($events, null, 'exception.message');
        $globex = $byMessage['building the report for globex'];
        self::assertEqualsCanonicalizing(['account' => 'globex', 'command' => 'app:send-reports'], $globex['fixwire.tags']);
        self::assertSame(['RuntimeException', 'LengthException'], array_column($globex['fixwire.exceptions'], 'type'));
        // The run failing is a crash of the command.
        self::assertSame(['symfony.console', false], [$byMessage['1 report(s) failed']['fixwire.exceptions'][0]['mechanism']['type'], $byMessage['1 report(s) failed']['fixwire.handled']]);
    }

    /** @param array<string, mixed> $span */
    private static function statusOf(array $span): ?int
    {
        foreach ($span['attributes'] ?? [] as $kv) {
            if ($kv['key'] === 'http.response.status_code') {
                return (int) $kv['value']['intValue'];
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>>           $spans
     * @param \Closure(array<string, mixed>): bool $match
     *
     * @return array<string, mixed>
     */
    private function one(array $spans, \Closure $match): array
    {
        $found = array_values(array_filter($spans, $match));
        self::assertCount(1, $found, 'spans: ' . implode(', ', array_column($spans, 'name')));

        return $found[0];
    }

    /**
     * @param list<array<string, mixed>> $requests
     *
     * @return list<array<string, mixed>>
     */
    private function allEvents(array $requests): array
    {
        $out = [];
        foreach ($requests as $r) {
            foreach ($r['body']['resourceLogs'] ?? [] as $rl) {
                foreach ($rl['scopeLogs'] as $sl) {
                    foreach ($sl['logRecords'] as $rec) {
                        $out[] = self::kv($rec['attributes']) + ['eventName' => $rec['eventName'] ?? null];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $requests
     *
     * @return array<string, array<string, mixed>> by exception type
     */
    private function events(array $requests): array
    {
        $out = [];
        foreach ($this->allEvents($requests) as $e) {
            $out[$e['exception.type'] ?? $e['eventName']] = $e;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $requests
     *
     * @return list<array<string, mixed>>
     */
    private function spans(array $requests): array
    {
        $out = [];
        foreach ($requests as $r) {
            foreach ($r['body']['resourceSpans'] ?? [] as $rs) {
                foreach ($rs['scopeSpans'] as $ss) {
                    foreach ($ss['spans'] as $s) {
                        $out[] = $s;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $requests
     *
     * @return array{exited: int, errored: int, crashed: int}
     */
    private function sessions(array $requests): array
    {
        $sum = ['exited' => 0, 'errored' => 0, 'crashed' => 0];
        foreach ($requests as $r) {
            foreach ($r['path'] === '/v1/sessions' ? $r['body']['aggregates'] : [] as $agg) {
                foreach ($sum as $k => $n) {
                    $sum[$k] = $n + ($agg[$k] ?? 0);
                }
            }
        }

        return $sum;
    }

    /**
     * @param list<array{key: string, value: array<string, mixed>}> $list
     *
     * @return array<string, mixed>
     */
    private static function kv(array $list): array
    {
        $out = [];
        foreach ($list as $kv) {
            $out[$kv['key']] = self::value($kv['value']);
        }

        return $out;
    }

    /** @param array<string, mixed> $v */
    private static function value(array $v): mixed
    {
        return match (true) {
            \array_key_exists('stringValue', $v) => $v['stringValue'],
            \array_key_exists('boolValue', $v) => $v['boolValue'],
            \array_key_exists('intValue', $v) => (int) $v['intValue'],
            \array_key_exists('doubleValue', $v) => (float) $v['doubleValue'],
            \array_key_exists('arrayValue', $v) => array_map(self::value(...), $v['arrayValue']['values'] ?? []),
            \array_key_exists('kvlistValue', $v) => self::kv($v['kvlistValue']['values'] ?? []),
            default => null,
        };
    }

    /**
     * Fails with what the app answered and what it reported, when the status isn't the one expected.
     *
     * @param array{int, string} $answer
     */
    private function assertAnswered(int $status, array $answer): void
    {
        if ($answer[0] === $status) {
            $this->addToAssertionCount(1);

            return;
        }
        usleep(500_000); // the app sends what it captured once it has answered
        $reported = [];
        foreach (array_filter(explode("\n", (string) file_get_contents($this->ingest))) as $line) {
            foreach (json_decode($line, true)['body']['resourceLogs'] ?? [] as $rl) {
                foreach ($rl['scopeLogs'] as $sl) {
                    foreach ($sl['logRecords'] as $rec) {
                        $a = array_column($rec['attributes'], 'value', 'key');
                        $reported[] = ($a['exception.type']['stringValue'] ?? 'message') . ': ' . ($a['exception.message']['stringValue'] ?? '');
                    }
                }
            }
        }
        self::fail("answered {$answer[0]}, not {$status}: " . substr($answer[1], 0, 500) . "\nreported: " . implode("\n          ", $reported));
    }

    /**
     * @param list<string> $headers
     *
     * @return array{int, string}
     */
    private function http(string $method, string $url, ?string $body, array $headers): array
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'ignore_errors' => true,
            'timeout' => 30,
        ]]);
        $stream = fopen($url, 'rb', false, $context);
        self::assertNotFalse($stream, "{$method} {$url}");
        $status = 0;
        foreach (stream_get_meta_data($stream)['wrapper_data'] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        $answer = (string) stream_get_contents($stream);
        fclose($stream);

        return [$status, $answer];
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string}
     */
    private function console(array $args): array
    {
        $process = proc_open([\PHP_BINARY, \dirname(__DIR__) . '/bin/console', ...$args], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, \dirname(__DIR__), $this->env + getenv());
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);

        return [proc_close($process), $out];
    }

    /**
     * Starts PHP's built-in server on a free port (%d in the arguments).
     *
     * @param list<string>          $args
     * @param array<string, string> $env
     */
    private function serve(array $args, array $env): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $null = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $server = proc_open(
            [\PHP_BINARY, ...array_map(static fn(string $a): string => \sprintf($a, $port), $args)],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            \dirname(__DIR__),
            $env + getenv(),
        );
        self::assertIsResource($server);
        $this->servers[] = $server;
        for ($i = 0; $i < 100; $i++) {
            $up = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if ($up !== false) {
                fclose($up);

                return $port;
            }
            usleep(50_000);
        }
        self::fail("php -S did not start on {$port}");
    }

    private function tempFile(): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'fixwire-symfony-example');
        $this->files[] = $file;

        return $file;
    }

    /**
     * What the fake Fixwire got, once it got at least $count requests (or 20 seconds passed).
     *
     * @return list<array<string, mixed>>
     */
    private function received(int $count): array
    {
        $read = fn(): array => array_map(
            static fn(string $l): array => json_decode($l, true, 512, \JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", (string) file_get_contents($this->ingest)))),
        );
        for ($i = 0; $i < 400 && \count($read()) < $count; $i++) {
            usleep(50_000);
        }
        usleep(300_000); // anything after it, too

        return $read();
    }
}
