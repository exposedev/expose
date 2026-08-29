<?php

namespace Tests\Unit;

use Expose\Client\Configuration;
use Expose\Client\Contracts\LocalUpstreamContract;
use Expose\Client\Http\HerdStudio;
use Expose\Client\Logger\RequestLogger;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use React\EventLoop\LoopInterface;
use Tests\TestCase;

class HttpClientDemuxTest extends TestCase
{
    /** @var TestHttpClient */
    protected $httpClient;

    /** @var Configuration */
    protected $configuration;

    /** @var string[] */
    protected $configFiles = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->configuration = new Configuration('sharedwith.dev', 443);

        $this->httpClient = new TestHttpClient(
            app(LoopInterface::class),
            app(RequestLogger::class),
            $this->configuration
        );

        $this->httpClient->setConnectionData((object) [
            'host' => 'myhost.test',
            'subdomain' => 'mysite',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->configFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    protected function bindHerdStudio(array $aiAssistant): void
    {
        $path = tempnam(sys_get_temp_dir(), 'herd-json-');
        $this->configFiles[] = $path;
        file_put_contents($path, json_encode(['aiAssistant' => $aiAssistant]));

        app()->instance(HerdStudio::class, new HerdStudio($path));
    }

    protected function studioSocketRequest(): Request
    {
        return new Request('GET', 'http://myhost.test/ai/ws?site=myhost.test', [
            'Connection' => 'Upgrade',
            'Upgrade' => 'websocket',
        ]);
    }

    /** @test */
    public function it_strips_the_default_https_port_from_the_host_header()
    {
        $this->configuration->setIsSecureSharedUrl(true);

        [$request] = $this->httpClient->callPrepareUpstreamRequest(
            new Request('GET', 'http://myhost.test:443/login')
        );

        $this->assertSame('myhost.test', $request->getHeaderLine('Host'));
        $this->assertSame('https', $request->getUri()->getScheme());
    }

    /** @test */
    public function it_strips_the_default_http_port_from_the_host_header()
    {
        [$request] = $this->httpClient->callPrepareUpstreamRequest(
            new Request('GET', 'http://myhost.test:80/login')
        );

        $this->assertSame('myhost.test', $request->getHeaderLine('Host'));
    }

    /** @test */
    public function it_keeps_non_default_ports_in_the_host_header()
    {
        [$request] = $this->httpClient->callPrepareUpstreamRequest(
            new Request('GET', 'http://127.0.0.1:8000/login')
        );

        $this->assertSame('127.0.0.1:8000', $request->getHeaderLine('Host'));
    }

    /** @test */
    public function it_routes_studio_websocket_upgrades_to_the_herd_api()
    {
        $this->bindHerdStudio(['enabled' => true, 'apiPort' => 2304]);

        [$request, $isViteRequest] = $this->httpClient->callPrepareUpstreamRequest($this->studioSocketRequest());

        $this->assertSame('http://127.0.0.1:2304/ai/ws?site=myhost.test', (string) $request->getUri());
        $this->assertSame('127.0.0.1:2304', $request->getHeaderLine('Host'));
        $this->assertFalse($isViteRequest);
    }

    /** @test */
    public function it_leaves_studio_requests_alone_when_herd_studio_is_disabled()
    {
        $this->bindHerdStudio(['enabled' => false]);

        [$request] = $this->httpClient->callPrepareUpstreamRequest($this->studioSocketRequest());

        $this->assertSame('myhost.test', $request->getUri()->getHost());
    }

    /** @test */
    public function custom_local_upstreams_can_be_registered()
    {
        $upstream = new class implements LocalUpstreamContract
        {
            public function shouldHandle(RequestInterface $request): bool
            {
                return $request->getUri()->getPath() === '/custom';
            }

            public function rewriteRequest(RequestInterface $request): RequestInterface
            {
                return $request->withUri(
                    $request->getUri()->withHost('127.0.0.1')->withPort(9999)
                );
            }
        };

        app()->instance('expose.local-upstreams', [$upstream]);

        [$request] = $this->httpClient->callPrepareUpstreamRequest(
            new Request('GET', 'http://myhost.test/custom')
        );

        $this->assertSame('http://127.0.0.1:9999/custom', (string) $request->getUri());

        [$request] = $this->httpClient->callPrepareUpstreamRequest(
            new Request('GET', 'http://myhost.test/other')
        );

        $this->assertSame('myhost.test', $request->getUri()->getHost());
    }

    /** @test */
    public function it_rewrites_bare_host_location_headers_when_the_registered_host_has_a_port()
    {
        $this->httpClient->setConnectionData((object) [
            'host' => 'myhost.test:443',
            'subdomain' => 'mysite',
        ]);

        $response = $this->httpClient->callRewriteResponseHeaders(
            new Response(302, ['Location' => 'https://myhost.test/dashboard'])
        );

        $this->assertSame('https://mysite.sharedwith.dev/dashboard', $response->getHeaderLine('Location'));
    }
}
