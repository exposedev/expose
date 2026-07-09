<?php

namespace Expose\Client;

use Psr\Http\Message\ResponseInterface;
use Expose\Client\Http\HttpClient;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\Frame;
use React\EventLoop\LoopInterface;
use React\Socket\Connector;
use function Ratchet\Client\connect;

class ProxyManager
{
    /** @var Configuration */
    protected $configuration;

    /** @var LoopInterface */
    protected $loop;

    public function __construct(Configuration $configuration, LoopInterface $loop)
    {
        $this->configuration = $configuration;
        $this->loop = $loop;
    }

    public function createProxy(string $clientId, $connectionData)
    {
        $protocol = $this->configuration->port() === 443 ? 'wss' : 'ws';
        $reusable = (bool) ($connectionData->reusable ?? false);

        connect($protocol."://{$this->configuration->host()}:{$this->configuration->port()}/expose/control", [], [
            'X-Expose-Control' => 'enabled',
        ], $this->loop)
            ->then(function (WebSocket $proxyConnection) use ($clientId, $connectionData, $reusable) {
                $localRequestConnection = null;

                $proxyConnection->on('message', function ($message) use (&$localRequestConnection, $proxyConnection, $connectionData, $clientId, $reusable) {
                    if ($localRequestConnection) {
                        $localRequestConnection->write($message);
                        return;
                    }

                    $this->performRequest($proxyConnection, (string) $message, $connectionData)
                        ->then(function ($response) use ($proxyConnection, &$localRequestConnection, $connectionData, $clientId, $reusable) {
                                if (is_null($response)) {
                                    return;
                                }

                                /** @var $body \React\Stream\DuplexStreamInterface */
                                $body = $response->getBody();
                                if ($body) {
                                    $localRequestConnection = $body;
                                    $body->on('close', function () use (&$localRequestConnection, $proxyConnection, $connectionData, $clientId, $reusable) {
                                        $localRequestConnection = null;

                                        if ($reusable) {
                                            $proxyConnection->send(json_encode([
                                                'event' => 'proxyComplete',
                                                'data' => [
                                                    'request_id' => $connectionData->request_id ?? null,
                                                    'client_id' => $clientId,
                                                ],
                                            ]));
                                        }
                                    });
                                }

                                if ($body->isWritable()) {
                                    $body->on('data', function ($chunk) use ($proxyConnection) {
                                        $binaryMsg = new Frame($chunk, true, Frame::OP_BINARY);
                                        $proxyConnection->send($binaryMsg);
                                    });
                                }
                            });
                        });

                $proxyConnection->send(json_encode([
                    'event' => 'registerProxy',
                    'data' => [
                        'request_id' => $connectionData->request_id ?? null,
                        'client_id' => $clientId,
                        'reusable' => $reusable,
                    ],
                ]));
            });
    }

    public function createTcpProxy(string $clientId, $connectionData)
    {
        $protocol = $this->configuration->port() === 443 ? 'wss' : 'ws';

        connect($protocol."://{$this->configuration->host()}:{$this->configuration->port()}/expose/control", [], [
            'X-Expose-Control' => 'enabled',
        ], $this->loop)
            ->then(function (WebSocket $proxyConnection) use ($clientId, $connectionData) {
                $connector = new Connector($this->loop);

                $connector->connect('127.0.0.1:'.$connectionData->port)->then(function ($connection) use ($proxyConnection) {
                    $connection->on('data', function ($data) use ($proxyConnection) {
                        $binaryMsg = new Frame($data, true, Frame::OP_BINARY);
                        $proxyConnection->send($binaryMsg);
                    });

                    $proxyConnection->on('message', function ($message) use ($connection) {
                        $connection->write($message);
                    });
                });

                $proxyConnection->send(json_encode([
                    'event' => 'registerTcpProxy',
                    'data' => [
                        'tcp_request_id' => $connectionData->tcp_request_id ?? null,
                        'client_id' => $clientId,
                    ],
                ]));
            });
    }

    protected function performRequest(WebSocket $proxyConnection, string $requestData, $connectionData)
    {
        return app(HttpClient::class)->performRequest((string) $requestData, $proxyConnection, $connectionData);
    }
}
