<?php

declare(strict_types=1);

namespace Fixwire\Symfony\HttpClient;

use Fixwire\Hub;
use Fixwire\OutgoingRequest;
use Symfony\Component\HttpClient\AsyncDecoratorTrait;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\AsyncResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @internal the http_client service, decorated: each request is a client span of the current trace
 * and an http breadcrumb, and carries trace headers to the trace propagation targets. It stays
 * asynchronous: the span ends with the response's last chunk.
 */
final class TracingHttpClient implements HttpClientInterface
{
    use AsyncDecoratorTrait;

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $full = $url;
        $base = $options['base_uri'] ?? null;
        if (\is_string($base) && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $full = rtrim($base, '/') . '/' . ltrim($url, '/');
        }
        $headers = \is_array($options['headers'] ?? null) ? $options['headers'] : [];
        $outgoing = OutgoingRequest::start(Hub::current(), $method, $full, static function (string $name, string $value) use (&$headers): void {
            if (!self::has($headers, $name)) {
                $headers[] = "{$name}: {$value}"; // the app's own trace headers win
            }
        });
        $options['headers'] = $headers;

        return new AsyncResponse($this->client, $method, $url, $options, static function (ChunkInterface $chunk, AsyncContext $context) use ($outgoing): \Generator {
            try {
                if ($chunk->isLast()) {
                    $outgoing->end($context->getStatusCode());
                }
            } catch (TransportExceptionInterface $e) {
                $outgoing->fail($e);
            }

            yield $chunk;
        });
    }

    /** @param array<array-key, mixed> $headers */
    private static function has(array $headers, string $name): bool
    {
        foreach ($headers as $key => $value) {
            $line = \is_string($key) ? $key : (\is_string($value) ? strstr($value, ':', true) : false);
            if (\is_string($line) && strcasecmp(trim($line), $name) === 0) {
                return true;
            }
        }

        return false;
    }
}
