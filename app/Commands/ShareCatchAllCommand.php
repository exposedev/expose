<?php

namespace Expose\Client\Commands;



use Psr\Http\Message\ServerRequestInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use Symfony\Component\Console\Output\OutputInterface;
use function Expose\Common\info;

class ShareCatchAllCommand extends ShareCommand
{


    protected $signature = 'catch-all {host?} {--subdomain=} {--auth=} {--basicAuth=} {--dns=} {--domain=} {--prevent-cors} {--qr} {--qr-code}';

    protected $description = 'Share a catch-all site';

    public function handle()
    {
        $this->input->setArgument('host', $this->startCatchAllServer());

        parent::handle();
    }

    protected function startCatchAllServer(): string
    {
        $http = new HttpServer(function (ServerRequestInterface $request) {
            return Response::plaintext(
                "OK"
            );
        });

        $socket = new SocketServer('127.0.0.1:0');
        $http->listen($socket);

        $url = str_replace('tcp:', 'http:', $socket->getAddress());

        info("Catch-All Server is listening on $url", options: OutputInterface::VERBOSITY_VERBOSE);

        return $url;
    }
}
