<?php

namespace Tests\Feature\Client;

use Expose\Client\Configuration;
use Expose\Client\Http\HttpClient;
use Expose\Client\Logger\RequestLogger;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Request;
use Mockery as m;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use React\Socket\Connector;
use React\Socket\ConnectorInterface;
use React\Socket\SocketServer;
use Tests\Feature\TestCase;

class HttpClientTest extends TestCase
{
    /** @var SocketServer */
    protected $socket;

    /** @var ServerRequestInterface|null */
    protected $receivedRequest;

    /** @var string|null */
    protected $connectedUri;

    /** @var string|null */
    protected $locationHeader;

    public function setUp(): void
    {
        parent::setUp();

        $server = new HttpServer($this->loop, function (ServerRequestInterface $request) {
            $this->receivedRequest = $request;

            if ($this->locationHeader) {
                return new Response(301, ['Location' => $this->locationHeader]);
            }

            return new Response(200, [], 'ok');
        });

        $this->socket = new SocketServer('127.0.0.1:0', [], $this->loop);

        $server->listen($this->socket);
    }

    public function tearDown(): void
    {
        $this->socket->close();

        parent::tearDown();
    }

    /** @test */
    public function it_strips_the_default_https_port_from_the_host_header()
    {
        $this->performRequest('example.test:443', true);

        $this->assertSame('example.test', $this->receivedRequest->getHeaderLine('Host'));
        $this->assertSame('tls://example.test:443', $this->connectedUri);
    }

    /** @test */
    public function it_strips_the_default_http_port_from_the_host_header()
    {
        $this->performRequest('example.test:80', false);

        $this->assertSame('example.test', $this->receivedRequest->getHeaderLine('Host'));
        $this->assertSame('example.test:80', $this->connectedUri);
    }

    /** @test */
    public function it_does_not_modify_a_host_header_without_a_port()
    {
        $this->performRequest('example.test', false);

        $this->assertSame('example.test', $this->receivedRequest->getHeaderLine('Host'));
        $this->assertSame('example.test:80', $this->connectedUri);
    }

    /** @test */
    public function it_keeps_custom_ports_in_the_host_header()
    {
        $this->performRequest('example.test:8443', true);

        $this->assertSame('example.test:8443', $this->receivedRequest->getHeaderLine('Host'));
        $this->assertSame('tls://example.test:8443', $this->connectedUri);

        $this->performRequest('localhost:8080', false);

        $this->assertSame('localhost:8080', $this->receivedRequest->getHeaderLine('Host'));
        $this->assertSame('localhost:8080', $this->connectedUri);
    }

    /** @test */
    public function it_rewrites_location_headers_for_http_sites()
    {
        $this->locationHeader = 'http://example.test/foo';

        $response = $this->performRequest('example.test', false);

        // The share itself is served over https (server port 443).
        $this->assertSame('https://tunnel.expose.test/foo', $response->getHeaderLine('Location'));
    }

    /** @test */
    public function it_rewrites_location_headers_for_https_sites()
    {
        $this->locationHeader = 'https://example.test/foo';

        $response = $this->performRequest('example.test:443', true);

        $this->assertSame('https://tunnel.expose.test/foo', $response->getHeaderLine('Location'));
    }

    /** @test */
    public function it_rewrites_location_headers_for_sites_with_custom_ports()
    {
        $this->locationHeader = 'https://example.test:8443/foo';

        $response = $this->performRequest('example.test:8443', true);

        $this->assertSame('https://tunnel.expose.test/foo', $response->getHeaderLine('Location'));
    }

    /** @test */
    public function it_does_not_rewrite_location_headers_for_other_hosts()
    {
        $this->locationHeader = 'https://beyondco.de/foo';

        $response = $this->performRequest('example.test:443', true);

        $this->assertSame('https://beyondco.de/foo', $response->getHeaderLine('Location'));
    }

    protected function performRequest(string $sharedHost, bool $isSecureSharedUrl): ResponseInterface
    {
        $this->receivedRequest = null;
        $this->connectedUri = null;

        $configuration = new Configuration('expose.test', 443);
        $configuration->setIsSecureSharedUrl($isSecureSharedUrl);

        $this->app->instance(Configuration::class, $configuration);

        $logger = m::mock(RequestLogger::class);
        $logger->shouldReceive('logRequest', 'logResponse');

        $httpClient = new class($this->loop, $logger, $configuration) extends HttpClient {
            public $connector;

            protected function createConnector(): Connector
            {
                return $this->connector;
            }
        };

        $httpClient->connector = $this->createConnectorToTestServer();

        $request = new Request('GET', '/', ['Host' => $sharedHost]);

        $connectionData = (object) [
            'host' => $sharedHost,
            'subdomain' => 'tunnel',
        ];

        return $this->await($httpClient->performRequest(Message::toString($request), null, $connectionData));
    }

    protected function createConnectorToTestServer(): Connector
    {
        $testConnector = new class($this->socket->getAddress(), $this->loop, function ($uri) {
            $this->connectedUri = $uri;
        }) implements ConnectorInterface {
            public function __construct(protected $address, protected $loop, protected $onConnect)
            {
            }

            public function connect($uri): PromiseInterface
            {
                call_user_func($this->onConnect, $uri);

                return (new Connector(['dns' => false], $this->loop))->connect($this->address);
            }
        };

        return new Connector([
            'tcp' => $testConnector,
            'tls' => $testConnector,
            'dns' => false,
        ], $this->loop);
    }
}
