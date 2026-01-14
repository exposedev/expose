<?php

namespace Expose\Client\Commands;

use Expose\Client\Commands\Concerns\TriggersLogin;
use LaravelZero\Framework\Commands\Command;

use function Expose\Common\banner;
use function Termwind\terminal;

class LoginCommand extends Command
{
    use TriggersLogin;

    protected $signature = 'login';

    protected $description = 'Login to Expose via your browser';

    public function handle()
    {
        terminal()->clear();
        banner();

        if (! $this->triggerLogin()) {
            return 1;
        }

        return 0;
    }
}
