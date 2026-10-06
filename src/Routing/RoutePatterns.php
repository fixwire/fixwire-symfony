<?php

declare(strict_types=1);

namespace Fixwire\Symfony\Routing;

use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * @internal the path of each route by its name (/orders/{id} for app_order_show), for span names
 * and events' transactions. Built when the cache is warmed, as reading the routes is not cheap;
 * in debug, from the router each time.
 */
final class RoutePatterns implements CacheWarmerInterface
{
    /** @var array<string, string>|null */
    private ?array $paths = null;

    public function __construct(private ?RouterInterface $router, private string $cacheDir, private bool $debug) {}

    public function pathOf(mixed $route): ?string
    {
        if (!\is_string($route) || $route === '' || $this->router === null) {
            return null;
        }
        $this->paths ??= $this->load();

        return $this->paths[$route] ?? null;
    }

    public function isOptional(): bool
    {
        return true;
    }

    /** @return list<string> */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        if ($this->router !== null) {
            $this->write(self::file($cacheDir), $this->build());
        }

        return [];
    }

    /** @return array<string, string> */
    private function load(): array
    {
        $file = self::file($this->cacheDir);
        if (!$this->debug && is_file($file)) {
            $paths = require $file;
            if (\is_array($paths)) {
                /** @var array<string, string> $paths */
                return $paths;
            }
        }
        $paths = $this->build();
        if (!$this->debug) {
            $this->write($file, $paths);
        }

        return $paths;
    }

    /** @return array<string, string> */
    private function build(): array
    {
        $paths = [];
        foreach ($this->router?->getRouteCollection()->all() ?? [] as $name => $route) {
            $paths[$name] = $route->getPath();
        }

        return $paths;
    }

    /** @param array<string, string> $paths */
    private function write(string $file, array $paths): void
    {
        $dir = \dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, '<?php return ' . var_export($paths, true) . ";\n") !== false) {
            @rename($tmp, $file);
        }
    }

    private static function file(string $cacheDir): string
    {
        return $cacheDir . '/fixwire/routes.php';
    }
}
