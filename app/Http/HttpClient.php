<?php

namespace Expose\Client\Http;

use Expose\Client\Configuration;
use Expose\Client\Http\Modifiers\CheckBasicAuthentication;
use Expose\Client\Logger\RequestLogger;
use GuzzleHttp\Psr7\Message;
use Laminas\Http\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\Frame;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Promise\Promise;
use React\Socket\Connector;

class HttpClient
{
    /** @var LoopInterface */
    protected $loop;

    /** @var RequestLogger */
    protected $logger;

    /** @var Request */
    protected $request;

    protected $connectionData;

    /** @var array */
    protected $modifiers = [
        CheckBasicAuthentication::class,
    ];
    /** @var Configuration */
    protected $configuration;

    public function __construct(LoopInterface $loop, RequestLogger $logger, Configuration $configuration)
    {
        $this->loop = $loop;
        $this->logger = $logger;
        $this->configuration = $configuration;
    }

    public function performRequest(string $requestData, ?WebSocket $proxyConnection = null, $connectionData = null)
    {
        $this->connectionData = $connectionData;

        $this->request = $this->parseRequest($requestData);

        $this->logger->logRequest($requestData, $this->request);

        $request = $this->passRequestThroughModifiers(Message::parseRequest($requestData), $proxyConnection);

        if (is_null($request)) {
            return new Promise(fn () => null);
        }

        return transform($request, function ($request) use ($proxyConnection) {
            return $this->sendRequestToApplication($request, $proxyConnection);
        });
    }

    protected function passRequestThroughModifiers(RequestInterface $request, ?WebSocket $proxyConnection = null): ?RequestInterface
    {
        foreach ($this->modifiers as $modifier) {
            $request = app($modifier)->handle($request, $proxyConnection);

            if (is_null($request)) {
                break;
            }
        }

        return $request;
    }

    protected function createConnector(): Connector
    {
        return new Connector($this->loop, [
            'dns' => config('expose.dns', '127.0.0.1'),
            'tls' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
    }

    protected function sendRequestToApplication(RequestInterface $request, $proxyConnection = null)
    {
        // Remove Expect header to prevent 100-continue responses from interfering with the proxy
        $request = $request->withoutHeader('Expect');

        $uri = $request->getUri();

        if ($this->configuration->isSecureSharedUrl()) {
            $uri = $uri->withScheme('https');
        }

        return (new Browser($this->loop, $this->createConnector()))
            ->withFollowRedirects(false)
            ->withRejectErrorResponse(false)
            ->requestStreaming(
                $request->getMethod(),
                $uri,
                $request->getHeaders(),
                $request->getBody()
            )
            ->then(function (ResponseInterface $response) use ($proxyConnection) {
                $response = $this->rewriteResponseHeaders($response);

                $response = $response->withoutHeader('Transfer-Encoding');

                if ($this->configuration->preventCORS()) {
                    $response = $response->withoutHeader('Access-Control-Allow-Origin');
                    $response = $response->withAddedHeader('Access-Control-Allow-Origin', '*');
                }

                $responseBuffer = Message::toString($response);

                $this->sendChunkToServer($responseBuffer, $proxyConnection);

                /* @var $body \React\Stream\DuplexStreamInterface */
                $body = $response->getBody();

                $this->logResponse(Message::toString($response));

                if (! $body->isWritable()) {
                    $body->on('data', function ($chunk) use ($proxyConnection, &$responseBuffer) {
                        $responseBuffer .= $chunk;

                        $this->sendChunkToServer($chunk, $proxyConnection);
                    });
                }

                $body->on('close', function () use ($proxyConnection, &$responseBuffer) {
                    $this->logResponse($responseBuffer);

                    optional($proxyConnection)->close();
                });

                return $response;
            })
            ->catch(function ($e) {
                // Ignore possible errors
            });
    }

    protected function sendChunkToServer(string $chunk, ?WebSocket $proxyConnection = null)
    {
        transform($proxyConnection, function ($proxyConnection) use ($chunk) {
            $binaryMsg = new Frame($chunk, true, Frame::OP_BINARY);
            $proxyConnection->send($binaryMsg);
        });
    }

    protected function logResponse(string $rawResponse)
    {
        $this->logger->logResponse($this->request, $rawResponse);
    }

    protected function parseRequest($data): Request
    {
        return Request::fromString($data);
    }

    protected function rewriteResponseHeaders(ResponseInterface $response)
    {
        if (! $response->hasHeader('Location')) {
            return $response;
        }

        if(!$this->connectionData) {
            return $response;
        }

        $location = $response->getHeaderLine('Location');

        if (! strstr($location, $this->connectionData->host)) {
            return $response;
        }

        $location = str_replace(
            $this->connectionData->host,
            $this->configuration->getUrl($this->connectionData->subdomain),
            $location
        );

        return $response->withHeader('Location', $location);
    }
}
