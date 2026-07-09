<?php

namespace Expose\Client\Http;

use Expose\Client\Configuration;
use Expose\Client\Http\Modifiers\CheckBasicAuthentication;
use Expose\Client\Http\Modifiers\CheckMagicAuthentication;
use Expose\Client\Logger\RequestLogger;
use GuzzleHttp\Psr7\Message;
use Illuminate\Support\Str;
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
        CheckMagicAuthentication::class,
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
                $shouldBufferResponseBody = $this->shouldBufferResponseBodyForLogging($response, $this->request);
                $maximumLoggedResponseSize = $this->getConfigSize(config()->get('expose.skip_body_log.size', '1MB'));

                $this->sendChunkToServer($responseBuffer, $proxyConnection);

                /* @var $body \React\Stream\DuplexStreamInterface */
                $body = $response->getBody();

                $this->logResponse(Message::toString($response));

                if (! $body->isWritable()) {
                    $body->on('data', function ($chunk) use ($proxyConnection, &$responseBuffer, &$shouldBufferResponseBody, $maximumLoggedResponseSize) {
                        if ($shouldBufferResponseBody) {
                            if (strlen($responseBuffer) + strlen($chunk) <= $maximumLoggedResponseSize) {
                                $responseBuffer .= $chunk;
                            } else {
                                $shouldBufferResponseBody = false;
                                $responseHeaders = strstr($responseBuffer, "\r\n\r\n", true);
                                $responseBuffer = ($responseHeaders === false ? $responseBuffer : $responseHeaders)."\r\n\r\n";
                            }
                        }

                        $this->sendChunkToServer($chunk, $proxyConnection);
                    });
                }

                $body->on('close', function () use ($proxyConnection, &$responseBuffer) {
                    $this->logResponse($responseBuffer);

                    if ($this->shouldCloseProxyConnection()) {
                        optional($proxyConnection)->close();
                    }
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

    protected function shouldBufferResponseBodyForLogging(ResponseInterface $response, Request $request): bool
    {
        if (! empty(config()->get('expose.skip_body_log.status')) && Str::is(config()->get('expose.skip_body_log.status'), $response->getStatusCode())) {
            return false;
        }

        $contentType = $response->getHeaderLine('Content-Type');

        if (str_contains($contentType, ';')) {
            $contentType = explode(';', $contentType, 2)[0];
        }

        if (! empty(config()->get('expose.skip_body_log.content_type')) && Str::is(config()->get('expose.skip_body_log.content_type'), $contentType)) {
            return false;
        }

        if (! empty(config()->get('expose.skip_body_log.extension')) && Str::is(config()->get('expose.skip_body_log.extension'), $request->getUri()->getPath())) {
            return false;
        }

        $contentLength = $response->getHeaderLine('Content-Length');

        if ($contentLength !== '' && (int) $contentLength > $this->getConfigSize(config()->get('expose.skip_body_log.size', '1MB'))) {
            return false;
        }

        return Str::is([
            'application/json',
            'text/*',
            '*javascript*',
        ], $contentType);
    }

    protected function getConfigSize(string $size): int
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $number = substr($size, 0, -2);
        $suffix = strtoupper(substr($size, -2));

        if (is_numeric(substr($suffix, 0, 1))) {
            return (int) preg_replace('/[^\d]/', '', $size);
        }

        $exponent = array_flip($units)[$suffix] ?? 5;

        return $number * (1024 ** $exponent);
    }

    protected function logResponse(string $rawResponse)
    {
        $this->logger->logResponse($this->request, $rawResponse);
    }

    protected function shouldCloseProxyConnection(): bool
    {
        return ! ($this->connectionData->reusable ?? false);
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
