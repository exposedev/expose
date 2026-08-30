<?php

namespace Tests\Unit;

use Expose\Client\Configuration;
use Expose\Client\Http\HttpClient;
use Expose\Client\Http\ViteDevServer;
use Expose\Client\Logger\RequestLogger;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Ratchet\Client\WebSocket;
use React\EventLoop\LoopInterface;
use React\Http\Io\ReadableBodyStream;
use React\Stream\ThroughStream;
use Tests\TestCase;

class HttpClientViteTest extends TestCase
{
    /** @var TestHttpClient */
    protected $httpClient;

    public function setUp(): void
    {
        parent::setUp();

        $this->httpClient = new TestHttpClient(
            app(LoopInterface::class),
            app(RequestLogger::class),
            new Configuration('sharedwith.dev', 443)
        );

        $this->httpClient->setConnectionData((object) [
            'host' => 'myhost.test',
            'subdomain' => 'mysite',
        ]);
    }

    /** @test */
    public function it_buffers_rewrites_and_sets_the_content_length_of_html_responses()
    {
        $stream = new ThroughStream();

        $response = new Response(200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ], new ReadableBodyStream($stream));

        $this->httpClient->callBufferAndRewriteResponse($response, false);

        $stream->write('<script type="module" src="http://127.0.0.1:5173/@vite/client"></script>');
        $stream->end();

        $this->assertCount(1, $this->httpClient->chunks);

        $rawResponse = $this->httpClient->chunks[0];

        $expectedBody = '<script type="module" src="https://mysite.sharedwith.dev/@vite/client"></script>';

        $this->assertStringEndsWith("\r\n\r\n".$expectedBody, $rawResponse);
        $this->assertStringContainsString('Content-Length: '.strlen($expectedBody)."\r\n", $rawResponse);

        $this->assertSame([
            'scheme' => 'http',
            'host' => '127.0.0.1',
            'port' => 5173,
        ], app(ViteDevServer::class)->get());
    }

    /** @test */
    public function it_sends_large_buffered_responses_in_bounded_chunks()
    {
        $stream = new ThroughStream();

        $response = new Response(200, [
            'Content-Type' => 'text/javascript',
        ], new ReadableBodyStream($stream));

        $this->httpClient->callBufferAndRewriteResponse($response, false);

        $body = str_repeat('a', 200 * 1024);
        $stream->write($body);
        $stream->end();

        $this->assertGreaterThan(1, count($this->httpClient->chunks));

        foreach ($this->httpClient->chunks as $chunk) {
            $this->assertLessThanOrEqual(64 * 1024, strlen($chunk));
        }

        $rawResponse = implode('', $this->httpClient->chunks);

        $this->assertStringEndsWith($body, $rawResponse);
        $this->assertStringContainsString('Content-Length: '.strlen($body)."\r\n", $rawResponse);
    }

    /** @test */
    public function it_rewrites_the_hmr_hostname_in_vite_responses_only()
    {
        $viteClient = 'const hmrHostname = "myhost.test";';

        $this->assertSame(
            'const hmrHostname = "mysite.sharedwith.dev";',
            $this->httpClient->callRewriteResponseBody($viteClient, true)
        );

        $this->assertSame(
            $viteClient,
            $this->httpClient->callRewriteResponseBody($viteClient, false)
        );
    }

    /** @test */
    public function the_hmr_hostname_rewrite_never_contains_a_port()
    {
        $httpClient = new TestHttpClient(
            app(LoopInterface::class),
            app(RequestLogger::class),
            new Configuration('localhost', 8080)
        );

        $httpClient->setConnectionData((object) [
            'host' => 'myhost.test',
            'subdomain' => 'mysite',
        ]);

        $this->assertSame(
            'const hmrHostname = "mysite.localhost";',
            $httpClient->callRewriteResponseBody('const hmrHostname = "myhost.test";', true)
        );
    }

    /** @test */
    public function it_only_rewrites_rewritable_content_types()
    {
        $rewritable = [
            'text/html',
            'text/html; charset=UTF-8',
            'application/javascript',
            'text/javascript',
            'text/css',
        ];

        foreach ($rewritable as $contentType) {
            $this->assertTrue(
                $this->httpClient->callShouldRewriteResponseBody(new Response(200, ['Content-Type' => $contentType])),
                "Expected {$contentType} to be rewritable."
            );
        }

        $notRewritable = [
            new Response(200, ['Content-Type' => 'image/png']),
            new Response(200, ['Content-Type' => 'application/json']),
            new Response(200, []),
            new Response(200, ['Content-Type' => 'text/html', 'Content-Encoding' => 'gzip']),
        ];

        foreach ($notRewritable as $response) {
            $this->assertFalse($this->httpClient->callShouldRewriteResponseBody($response));
        }
    }

    /** @test */
    public function it_does_not_rewrite_responses_without_connection_data()
    {
        $this->httpClient->setConnectionData(null);

        $this->assertFalse(
            $this->httpClient->callShouldRewriteResponseBody(new Response(200, ['Content-Type' => 'text/html']))
        );
    }

    /** @test */
    public function it_upgrades_share_origin_urls_to_https_in_response_bodies()
    {
        $body = 'fetch("http://mysite.sharedwith.dev/_boost/browser-logs")';

        $this->assertSame(
            'fetch("https://mysite.sharedwith.dev/_boost/browser-logs")',
            $this->httpClient->callRewriteResponseBody($body, false)
        );
    }

    /** @test */
    public function it_upgrades_http_local_host_redirects_to_the_https_share_origin()
    {
        $response = $this->httpClient->callRewriteResponseHeaders(
            new Response(302, ['Location' => 'http://myhost.test/dashboard'])
        );

        $this->assertSame('https://mysite.sharedwith.dev/dashboard', $response->getHeaderLine('Location'));
    }

    /** @test */
    public function it_rewrites_dev_server_urls_in_location_headers()
    {
        $response = $this->httpClient->callRewriteResponseHeaders(
            new Response(302, ['Location' => 'http://127.0.0.1:5173/resources/js/app.js'])
        );

        $this->assertSame('https://mysite.sharedwith.dev/resources/js/app.js', $response->getHeaderLine('Location'));
    }

    /** @test */
    public function it_still_rewrites_the_local_host_in_location_headers()
    {
        $response = $this->httpClient->callRewriteResponseHeaders(
            new Response(302, ['Location' => 'https://myhost.test/dashboard'])
        );

        $this->assertSame('https://mysite.sharedwith.dev/dashboard', $response->getHeaderLine('Location'));
    }
}

class TestHttpClient extends HttpClient
{
    /** @var array */
    public $chunks = [];

    public function setConnectionData($connectionData)
    {
        $this->connectionData = $connectionData;
    }

    public function callBufferAndRewriteResponse(ResponseInterface $response, bool $isViteRequest)
    {
        return $this->bufferAndRewriteResponse($response, null, $isViteRequest);
    }

    public function callShouldRewriteResponseBody(ResponseInterface $response): bool
    {
        return $this->shouldRewriteResponseBody($response);
    }

    public function callRewriteResponseBody(string $body, bool $isViteRequest): string
    {
        return $this->rewriteResponseBody($body, $isViteRequest);
    }

    public function callRewriteResponseHeaders(ResponseInterface $response)
    {
        return $this->rewriteResponseHeaders($response);
    }

    public function callPrepareUpstreamRequest($request): array
    {
        return $this->prepareUpstreamRequest($request);
    }

    protected function sendChunkToServer(string $chunk, ?WebSocket $proxyConnection = null)
    {
        $this->chunks[] = $chunk;
    }

    protected function logResponse(string $rawResponse)
    {
        // Not needed for these tests.
    }
}
