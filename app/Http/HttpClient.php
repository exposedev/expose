<?php

namespace Expose\Client\Http;

use Expose\Client\Configuration;
use Expose\Client\Http\Modifiers\CheckBasicAuthentication;
use Expose\Client\Http\Modifiers\CheckMagicAuthentication;
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
        [$request, $isViteRequest] = $this->prepareUpstreamRequest($request);

        return (new Browser($this->loop, $this->createConnector()))
            ->withFollowRedirects(false)
            ->withRejectErrorResponse(false)
            ->requestStreaming(
                $request->getMethod(),
                $request->getUri(),
                $request->getHeaders(),
                $request->getBody()
            )
            ->then(function (ResponseInterface $response) use ($proxyConnection, $isViteRequest) {
                $response = $this->rewriteResponseHeaders($response);

                $response = $response->withoutHeader('Transfer-Encoding');

                if ($this->configuration->preventCORS()) {
                    $response = $response->withoutHeader('Access-Control-Allow-Origin');
                    $response = $response->withAddedHeader('Access-Control-Allow-Origin', '*');
                }

                /* @var $body \React\Stream\DuplexStreamInterface */
                $body = $response->getBody();

                if (! $body->isWritable() && $this->shouldRewriteResponseBody($response)) {
                    return $this->bufferAndRewriteResponse($response, $proxyConnection, $isViteRequest);
                }

                $responseBuffer = Message::toString($response);

                $this->sendChunkToServer($responseBuffer, $proxyConnection);

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
            ->catch(function ($e) use ($proxyConnection) {
                // The local upstream is unreachable (e.g. a stopped Vite dev
                // server) - answer with a 502 instead of a dangling request.
                $this->sendChunkToServer("HTTP/1.1 502 Bad Gateway\r\nContent-Length: 0\r\nConnection: close\r\n\r\n", $proxyConnection);

                optional($proxyConnection)->close();
            });
    }

    /**
     * Demux the request onto one of the local upstreams (application, Vite dev
     * server, Herd Studio API) and normalize it for proxying.
     *
     * @return array{0: RequestInterface, 1: bool} the prepared request and
     *                                             whether it targets the Vite dev server
     */
    protected function prepareUpstreamRequest(RequestInterface $request): array
    {
        // Remove Expect header to prevent 100-continue responses from interfering with the proxy
        $request = $request->withoutHeader('Expect');

        // Request plaintext bodies so that local dev server URLs can be rewritten
        $request = $request->withHeader('Accept-Encoding', 'identity');

        foreach ($this->localUpstreams() as $upstream) {
            if ($upstream->shouldHandle($request)) {
                return [$upstream->rewriteRequest($request), $upstream instanceof ViteDevServer];
            }
        }

        $uri = $request->getUri();

        if ($this->configuration->isSecureSharedUrl()) {
            $uri = $uri->withScheme('https');
        }

        // The Host header may carry an explicit default port (e.g. myapp.test:443
        // for https shares). Browsers omit default ports, so strip them to keep
        // HTTP_HOST identical to what the application sees during local browsing.
        $request = $this->normalizeHostHeader($request->withUri($uri, true), $uri->getScheme());

        return [$request, false];
    }

    protected function normalizeHostHeader(RequestInterface $request, string $scheme): RequestInterface
    {
        $host = $request->getHeaderLine('Host');
        $defaultPort = $scheme === 'https' ? ':443' : ':80';

        if (str_ends_with($host, $defaultPort)) {
            $request = $request->withHeader('Host', substr($host, 0, -strlen($defaultPort)));
        }

        return $request;
    }

    protected function bufferAndRewriteResponse(ResponseInterface $response, $proxyConnection, bool $isViteRequest)
    {
        /* @var $body \React\Stream\ReadableStreamInterface */
        $body = $response->getBody();

        $bodyBuffer = '';

        $body->on('data', function ($chunk) use (&$bodyBuffer) {
            $bodyBuffer .= $chunk;
        });

        $body->on('close', function () use (&$bodyBuffer, $response, $proxyConnection, $isViteRequest) {
            $bodyBuffer = $this->rewriteResponseBody($bodyBuffer, $isViteRequest);

            $response = $response->withHeader('Content-Length', (string) strlen($bodyBuffer));

            $rawResponse = Message::toString($response).$bodyBuffer;

            // Send in bounded frames - the server buffers each websocket
            // message in full before relaying, so a single multi-megabyte
            // frame can exceed its message size limit and kill the proxy
            // connection.
            foreach (str_split($rawResponse, 64 * 1024) as $chunk) {
                $this->sendChunkToServer($chunk, $proxyConnection);
            }

            $this->logResponse($rawResponse);

            optional($proxyConnection)->close();
        });

        return $response;
    }

    protected function shouldRewriteResponseBody(ResponseInterface $response): bool
    {
        if (! isset($this->connectionData->host, $this->connectionData->subdomain)) {
            return false;
        }

        $contentEncoding = strtolower($response->getHeaderLine('Content-Encoding'));

        if ($contentEncoding !== '' && $contentEncoding !== 'identity') {
            return false;
        }

        $contentType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));

        return in_array($contentType, [
            'text/html',
            'application/javascript',
            'text/javascript',
            'text/css',
        ]);
    }

    protected function rewriteResponseBody(string $body, bool $isViteRequest): string
    {
        if (! isset($this->connectionData->host, $this->connectionData->subdomain)) {
            return $body;
        }

        $body = $this->viteDevServer()->rewriteUrls(
            $body,
            $this->localHost(),
            $this->localPort(),
            $this->shareOrigin(),
            // Only the application itself is authoritative about where Vite runs.
            ! $isViteRequest
        );

        if ($isViteRequest) {
            // laravel-vite-plugin bakes the local hostname into /@vite/client as
            // `hmr.host` when serving over TLS (Herd/Valet certificates). The
            // Vite client appends the port from import.meta.url itself, so the
            // replacement has to be the bare hostname.
            $body = str_replace(
                '"'.$this->localHost().'"',
                '"'.$this->connectionData->subdomain.'.'.$this->configuration->serverHost().'"',
                $body
            );
        }

        return $body;
    }

    /** @return \Expose\Client\Contracts\LocalUpstreamContract[] */
    protected function localUpstreams(): array
    {
        return app('expose.local-upstreams');
    }

    protected function viteDevServer(): ViteDevServer
    {
        return app(ViteDevServer::class);
    }

    protected function localHost(): string
    {
        return parse_url('http://'.$this->connectionData->host, PHP_URL_HOST) ?: $this->connectionData->host;
    }

    protected function localPort(): int
    {
        $port = parse_url('http://'.$this->connectionData->host, PHP_URL_PORT);

        return $port ?: ($this->configuration->isSecureSharedUrl() ? 443 : 80);
    }

    protected function shareOrigin(): string
    {
        $httpProtocol = $this->configuration->port() === 443 ? 'https' : 'http';

        return $httpProtocol.'://'.$this->configuration->getUrl($this->connectionData->subdomain);
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

        if (isset($this->connectionData->host, $this->connectionData->subdomain)) {
            $location = $this->viteDevServer()->rewriteUrls(
                $location,
                $this->localHost(),
                $this->localPort(),
                $this->shareOrigin(),
                false
            );
        }

        if (strstr($location, $this->connectionData->host)) {
            $location = str_replace(
                $this->connectionData->host,
                $this->configuration->getUrl($this->connectionData->subdomain),
                $location
            );
        } elseif (isset($this->connectionData->subdomain) && strstr($location, '://'.$this->localHost())) {
            // The registered host may carry an explicit default port
            // (myapp.test:443) while the application redirects to the bare
            // hostname - match that too.
            $location = str_replace(
                '://'.$this->localHost(),
                '://'.$this->configuration->getUrl($this->connectionData->subdomain),
                $location
            );
        }

        return $response->withHeader('Location', $location);
    }
}
