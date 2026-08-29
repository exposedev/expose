<?php

namespace Expose\Client\Providers;

use Expose\Client\Contracts\LogStorageContract;
use Expose\Client\Logger\CliLogger;
use Expose\Client\Logger\DatabaseLogger;
use Expose\Client\Logger\FrontendLogger;
use Expose\Client\Logger\Plugins\PluginManager;
use Expose\Client\Logger\RequestLogger;
use Expose\Client\Http\HerdStudio;
use Expose\Client\Http\ViteDevServer;
use Expose\Client\Support\ExposeConfig;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Laminas\Uri\Uri;
use Laminas\Uri\UriFactory;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\Browser;

class AppServiceProvider extends ServiceProvider
{
    public function boot()
    {
        UriFactory::registerScheme('capacitor', Uri::class);
        UriFactory::registerScheme('chrome-extension', Uri::class);
    }

    public function register()
    {
        $this->loadConfigurationFile();

        $this->setMemoryLimit();

        $this->app->singleton(LoopInterface::class, function () {
            return Loop::get();
        });

        $this->app->singleton(PluginManager::class, function () {
            return new PluginManager;
        });

        $this->app->bind(Browser::class, function ($app) {
            return new Browser($app->make(LoopInterface::class));
        });

        $this->app->singleton(LogStorageContract::class, function ($app) {
            return new DatabaseLogger();
        });

        $this->app->singleton(RequestLogger::class, function ($app) {
            return new RequestLogger($app->make(CliLogger::class), $app->make(FrontendLogger::class), $app->make(LogStorageContract::class));
        });

        $this->app->singleton(HerdStudio::class, function () {
            return new HerdStudio();
        });

        $this->app->singleton(ViteDevServer::class, function ($app) {
            $viteDevServer = new ViteDevServer();

            // Never mistake the Herd Studio widget dev server for the shared
            // site's own Vite dev server.
            if ($widgetPort = $app->make(HerdStudio::class)->widgetDevServerPort()) {
                $viteDevServer->ignorePort($widgetPort);
            }

            return $viteDevServer;
        });

        // Local upstreams other than the shared application, in match order.
        // The HttpClient routes each tunneled request to the first upstream
        // whose shouldHandle() accepts it.
        $this->app->singleton('expose.local-upstreams', function ($app) {
            return [
                $app->make(ViteDevServer::class),
                $app->make(HerdStudio::class),
            ];
        });
    }

    protected function loadConfigurationFile()
    {
        ExposeConfig::load();
    }

    protected function setMemoryLimit()
    {
        ini_set('memory_limit', config()->get('expose.memory_limit', '128M'));
    }
}
