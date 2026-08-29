<?php

namespace Expose\Client\Http;

use Expose\Client\Contracts\LocalUpstreamContract;
use Psr\Http\Message\RequestInterface;

/**
 * Routes Herd Studio requests to the local Herd app.
 *
 * Herd serves the Studio shell and widget assets same-origin from the shared
 * site (under /__herd-ai__/), so those tunnel like any other request. The one
 * thing that cannot: the widget's websocket to the Herd app on /ai/ws, which
 * this class demuxes to 127.0.0.1:{apiPort} based on Herd's own configuration.
 */
class HerdStudio implements LocalUpstreamContract
{
    public const STUDIO_SOCKET_PATH = '/ai/ws';

    public const DEFAULT_API_PORT = 2304;

    /**
     * Herd loads the widget bundle from this Vite dev server in dev mode
     * (hardcoded in Herd's valet Server.php). It only exists on the machine
     * of a Herd developer and must never be treated as the shared site's
     * Vite dev server.
     */
    public const WIDGET_DEV_SERVER_PORT = 5273;

    /** @var array|null */
    protected $config = null;

    /** @var string|null */
    protected $configPath = null;

    public function __construct(?string $configPath = null)
    {
        $this->configPath = $configPath;
    }

    public function shouldHandle(RequestInterface $request): bool
    {
        if (is_null($this->apiPort())) {
            return false;
        }

        if ($request->getUri()->getPath() !== static::STUDIO_SOCKET_PATH) {
            return false;
        }

        return strtolower($request->getHeaderLine('Upgrade')) === 'websocket';
    }

    public function rewriteRequest(RequestInterface $request): RequestInterface
    {
        $uri = $request->getUri()
            ->withScheme('http')
            ->withHost('127.0.0.1')
            ->withPort($this->apiPort());

        return $request->withUri($uri)->withHeader('Host', '127.0.0.1:'.$this->apiPort());
    }

    public function apiPort(): ?int
    {
        $config = $this->config();

        if (empty($config['enabled'])) {
            return null;
        }

        return (int) ($config['apiPort'] ?? static::DEFAULT_API_PORT);
    }

    public function widgetDevServerPort(): ?int
    {
        $config = $this->config();

        if (empty($config['enabled']) || empty($config['dev'])) {
            return null;
        }

        return static::WIDGET_DEV_SERVER_PORT;
    }

    protected function config(): array
    {
        if (is_null($this->config)) {
            $this->config = $this->readConfig();
        }

        return $this->config;
    }

    protected function readConfig(): array
    {
        $path = $this->configPath ?? $this->defaultConfigPath();

        if (is_null($path) || ! is_file($path)) {
            return [];
        }

        $config = json_decode((string) file_get_contents($path), true);

        if (! is_array($config) || ! is_array($config['aiAssistant'] ?? null)) {
            return [];
        }

        return $config['aiAssistant'];
    }

    protected function defaultConfigPath(): ?string
    {
        $herdHome = getenv('HERD_HOME');

        if (! $herdHome) {
            $home = $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? null;

            if (is_null($home)) {
                return null;
            }

            $isWindows = strpos(php_uname('s'), 'Windows') !== false;

            $herdHome = $isWindows
                ? $home.'\.config\herd'
                : $home.'/Library/Application Support/Herd';
        }

        return $herdHome.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'herd.json';
    }
}
