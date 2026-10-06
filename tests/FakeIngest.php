<?php

declare(strict_types=1);

namespace Fixwire\Symfony\Tests;

use Fixwire\Transport\Transport;

/** A fake Fixwire behind the SDK's transport: keeps each request's path and decoded body. */
final class FakeIngest implements Transport
{
    /** @var list<array{path: string, body: array<string, mixed>}> */
    public array $received = [];

    public function send(string $url, string $body, array $headers): array
    {
        $json = ($headers['Content-Encoding'] ?? '') === 'gzip' ? gzdecode($body) : $body;
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $json, true, 512, \JSON_THROW_ON_ERROR);
        $this->received[] = ['path' => rawurldecode((string) parse_url($url, \PHP_URL_PATH)), 'body' => $decoded];

        return [200, []];
    }

    /**
     * The events, with plain attributes (and body, traceId, spanId).
     *
     * @return list<array<string, mixed>>
     */
    public function events(): array
    {
        $out = [];
        foreach ($this->received as $r) {
            foreach ($r['body']['resourceLogs'] ?? [] as $rl) {
                foreach ($rl['scopeLogs'] as $sl) {
                    foreach ($sl['logRecords'] as $rec) {
                        $out[] = self::kv($rec['attributes']) + [
                            'body' => isset($rec['body']) ? self::value($rec['body']) : null,
                            'traceId' => $rec['traceId'] ?? null,
                            'spanId' => $rec['spanId'] ?? null,
                        ];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * The spans, with plain attributes.
     *
     * @return list<array<string, mixed>>
     */
    public function spans(): array
    {
        $out = [];
        foreach ($this->received as $r) {
            foreach ($r['body']['resourceSpans'] ?? [] as $rs) {
                foreach ($rs['scopeSpans'] as $ss) {
                    foreach ($ss['spans'] as $s) {
                        $s['attributes'] = self::kv($s['attributes'] ?? []);
                        $out[] = $s;
                    }
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null the span of that name */
    public function span(string $name): ?array
    {
        foreach ($this->spans() as $s) {
            if ($s['name'] === $name) {
                return $s;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> the bodies sent to a path */
    public function bodies(string $path): array
    {
        return array_values(array_map(static fn(array $r): array => $r['body'], array_filter($this->received, static fn(array $r): bool => $r['path'] === $path)));
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
}
