<?php

namespace Expose\Client\Http;

use Psr\Http\Message\RequestInterface;

class ViteDevServer
{
    /** @var array|null ['scheme' => 'http'|'https', 'host' => string, 'port' => int] */
    protected $server = null;

    /** @var string|null */
    protected $hotFilePath = null;

    /** @var bool */
    protected $hotFileChecked = false;

    public function setHotFilePath(string $path): void
    {
        if (is_null($this->hotFilePath)) {
            $this->hotFilePath = $path;
        }
    }

    public function get(): ?array
    {
        if (is_null($this->server)) {
            $this->seedFromHotFile();
        }

        return $this->server;
    }

    public function set(string $scheme, string $host, int $port): void
    {
        $this->server = [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
        ];
    }

    public function isViteRequest(RequestInterface $request): bool
    {
        if (is_null($this->get())) {
            return false;
        }

        $path = $request->getUri()->getPath();

        if (preg_match('#^/(@vite/|@id/|@fs/|node_modules/|resources/|__laravel_vite_plugin__/)#', $path) || $path === '/@react-refresh') {
            return true;
        }

        return strtolower($request->getHeaderLine('Sec-WebSocket-Protocol')) === 'vite-hmr';
    }

    public function rewriteRequest(RequestInterface $request): RequestInterface
    {
        $server = $this->get();

        // Connect to the discovered host, as Vite might only listen on a
        // single address family (e.g. [::1], but not 127.0.0.1).
        $uri = $request->getUri()
            ->withScheme($server['scheme'])
            ->withHost($server['host'])
            ->withPort($server['port']);

        // Vite only accepts requests for hosts on its allowedHosts list.
        return $request->withUri($uri)->withHeader('Host', 'localhost');
    }

    /**
     * Rewrite absolute local URLs (e.g. http://127.0.0.1:5173) to the public share
     * origin. The first match that does not point at the shared app itself tells
     * us where the Vite dev server runs, so that a dev server restarting on a
     * different port is picked up without restarting the tunnel.
     */
    public function rewriteUrls(string $content, string $localHost, ?int $localPort, string $shareOrigin, bool $allowDiscovery = true): string
    {
        $pattern = '~(https?)://(127\.0\.0\.1|localhost|\[::1\]|'.preg_quote($localHost, '~').'):(\d+)~i';

        $discovered = false;

        return preg_replace_callback($pattern, function ($matches) use ($localPort, $shareOrigin, $allowDiscovery, &$discovered) {
            $port = (int) $matches[3];

            if ($allowDiscovery && ! $discovered && $port !== $localPort) {
                $this->set(strtolower($matches[1]), strtolower($matches[2]), $port);

                $discovered = true;
            }

            return $shareOrigin;
        }, $content);
    }

    protected function seedFromHotFile(): void
    {
        if ($this->hotFileChecked || is_null($this->hotFilePath) || ! file_exists($this->hotFilePath)) {
            return;
        }

        $this->hotFileChecked = true;

        $url = trim((string) file_get_contents($this->hotFilePath));
        $parsedUrl = parse_url($url);

        if (! isset($parsedUrl['scheme'], $parsedUrl['host'], $parsedUrl['port']) || ! in_array($parsedUrl['scheme'], ['http', 'https'])) {
            return;
        }

        $this->set($parsedUrl['scheme'], $parsedUrl['host'], (int) $parsedUrl['port']);
    }
}
