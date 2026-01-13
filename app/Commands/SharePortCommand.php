<?php

namespace Expose\Client\Commands;

use Expose\Client\Commands\Concerns\TriggersLogin;
use Expose\Client\Factory;
use React\EventLoop\LoopInterface;

use function Expose\Common\banner;
use function Expose\Common\info;
use function Termwind\terminal;

class SharePortCommand extends ServerAwareCommand
{
    use TriggersLogin;

    protected $signature = 'share-port {port} {--auth=}';

    protected $description = 'Share a local port with a remote expose server';

    public function handle()
    {
        terminal()->clear();
        banner();

        $this->ensureExposeSetup();

        $auth = $this->option('auth') ?? config('expose.auth_token', '');

        (new Factory())
            ->setLoop(app(LoopInterface::class))
            ->setHost($this->getServerHost())
            ->setPort($this->getServerPort())
            ->setAuth($auth)
            ->createClient()
            ->sharePort($this->argument('port'))
            ->createHttpServer()
            ->run();
    }

    protected function ensureExposeSetup(): void
    {
        if (empty(config('expose.auth_token'))) {
            if (! $this->triggerLogin()) {
                exit(1);
            }
        }
    }
}
