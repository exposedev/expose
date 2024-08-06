<?php

namespace App\Commands\Concerns;

use Illuminate\Support\Str;
use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Symfony\Component\Process\PhpExecutableFinder;

trait SharesViteServer
{
    protected $originalViteServer;

    protected $isSharingVite = false;

    protected $checkForViteTimer;

    /** @var Process */
    protected $viteProcess;

    protected $watchHotFileTimer;

    protected $sharedViteURL = '';

    protected function shareViteServer($hmrServer)
    {
        $this->info("Vite HMR server detected…");

        $phpBinary = (new PhpExecutableFinder())->find();
        if (! $phpBinary) {
            $this->warn('Unable to find PHP binary to run the Vite server. Skipping.');
            return;
        }

        $arguments = [
            '"'.$phpBinary.'"',
            '/Users/marcelpociot/Code/expose/expose.php',
            'share',
            $hmrServer,
            '--server=' . $this->option('server'),
            '--server-host=' . $this->option('server-host'),
            '--server-port=' . $this->option('server-port'),
            '--auth=' . $this->option('auth'),
            '--basicAuth=' . $this->option('basicAuth'),
            '--dns=' . $this->option('dns'),
            '--domain=' . $this->option('domain'),
            '--subdomain=' . strtolower(Str::random()),
        ];

        $arguments = array_filter($arguments, function ($argument) {
            $value = explode('=', $argument);
            $argument = $value[1] ?? $value[0];
            return $argument !== '';
        });

        $command = implode(' ', $arguments);

        $this->viteProcess = new Process($command);
        $this->viteProcess->start(app(LoopInterface::class));
        $this->viteProcess->stdout->on('data', function ($output) {
            if (preg_match('/Public HTTPS:\s+(.*)/', $output, $matches)) {
                $this->sharedViteURL = $matches[1];
                $this->info('Found shared Vite server at: '.$this->sharedViteURL);
                $this->replaceViteServer();
            }
        });
        $this->viteProcess->stderr->on('data', function ($output) {
            $this->error($output);
        });
    }

    protected function checkForVite()
    {
        $this->checkForViteTimer = app(LoopInterface::class)->addPeriodicTimer(1, function () {
            if ($this->shouldShareVite() && ! $this->isSharingVite) {
                var_dump('Sharing Vite server…');
                $this->isSharingVite = true;
                $this->shareViteServer($this->viteServerHost());

                $this->watchHotFileTimer = app(LoopInterface::class)->addPeriodicTimer(1, function () {
                    $hotFile = getcwd() . '/public/hot';
                    if (!file_exists($hotFile)) {
                        return;
                    }

                    if (file_get_contents(getcwd() . '/public/hot') !== $this->sharedViteURL) {
                        $this->info('Change detected in Vite server URL…');
                        var_dump($this->sharedViteURL);
                        $this->replaceViteServer();
                    }
                });
            }

            if (! $this->shouldShareVite() && $this->isSharingVite) {
                $this->isSharingVite = false;
                $this->info('Stopping Vite server…');
                $this->viteProcess->terminate();
            }
        });
    }

    protected function shouldShareVite(): bool
    {
        return file_exists(getcwd() . '/public/hot');
    }

    protected function viteServerHost(): string
    {
        $host = file_get_contents(getcwd() . '/public/hot');
        $host = str_replace('[::1]', 'localhost', $host);
        return $host;
    }

    protected function replaceViteServer()
    {
        $this->info('Replacing Vite server URL in public/hot file…');

        $this->originalViteServer = file_get_contents(getcwd() . '/public/hot');

        $viteServerFile = getcwd() . '/public/hot';
        file_put_contents($viteServerFile, $this->sharedViteURL);

        if (! defined('SIGINT')) {
            return;
        }
        app(LoopInterface::class)->addSignal(SIGINT, $func = function ($signal) use (&$func, $viteServerFile) {
            $this->revertViteServerFile();
            app(LoopInterface::class)->removeSignal(SIGINT, $func);
            exit(0);
        });
    }

    protected function revertViteServerFile()
    {
        $viteServerFile = getcwd() . '/public/hot';

        if (file_exists($viteServerFile)) {
            file_put_contents($viteServerFile, $this->originalViteServer);
        }
    }
}
