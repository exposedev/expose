<?php

namespace Expose\Client\Commands;

use Expose\Client\Commands\Concerns\DetectsLocalDevelopmentSites;
use Expose\Client\Http\ViteDevServer;
use Illuminate\Support\Arr;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

use function Expose\Common\info;

class ShareCurrentWorkingDirectoryCommand extends ShareCommand
{
    use DetectsLocalDevelopmentSites;

    protected $signature = 'share-cwd {host?} {--subdomain=} {--auth=} {--basicAuth=} {--magic-auth=} {--dns=} {--domain=} {--prevent-cors} {--qr} {--qr-code}';

    public function handle()
    {
        $this->loadConfigurationFiles();

        $yaml = $this->loadExposeYaml();

        $folderName = $this->detectSharedSiteNameFromCwd();

        $host = Arr::get($yaml, 'local-url', $this->prepareSharedHost($folderName . '.' . $this->detectTld()));

        $this->input->setArgument('host', $host);

        $subdomain = Arr::get($yaml, 'subdomain', $this->option('subdomain'));

        if (!$subdomain) {
            $this->input->setOption('subdomain', str_replace('.', '-', $folderName));
        } else {
            $this->input->setOption('subdomain', $subdomain);
        }

        $this->input->setOption('domain', Arr::get($yaml, 'custom-domain', $this->option('domain')));
        $this->input->setOption('server', Arr::get($yaml, 'expose-server', $this->option('server')));

        $authString = "";
        $username = Arr::get($yaml, "auth.username");
        $password = Arr::get($yaml, "auth.password");

        if ($username && $password) {
            $authString = $username . ":" . $password;
        }

        $authString = empty($authString) ? $this->option('basicAuth') : $authString;

        $this->input->setOption('basicAuth', $authString);

        // Used as a read-only fallback to detect a running Vite dev server.
        app(ViteDevServer::class)->setHotFilePath(getcwd() . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'hot');

        parent::handle();
    }

    protected function loadExposeYaml(): array
    {
        try {
            return Yaml::parseFile(getcwd() . DIRECTORY_SEPARATOR . 'expose.yml');
        } catch (\Exception $e) {
            return [];
        }
    }

    protected function prepareSharedHost($host): string
    {
        return $this->detectProtocol($host) . $host;
    }
}
