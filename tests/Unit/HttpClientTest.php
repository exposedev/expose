<?php

namespace Tests\Unit;

use Expose\Client\Configuration;
use Expose\Client\Http\HttpClient;
use Expose\Client\Logger\RequestLogger;
use GuzzleHttp\Psr7\Response;
use Laminas\Http\Request;
use Mockery as m;
use React\EventLoop\Loop;
use Tests\TestCase;

class HttpClientTest extends TestCase
{
    /** @test */
    public function it_does_not_buffer_large_image_responses_for_logging()
    {
        $this->assertFalse($this->shouldBufferResponseBodyForLogging(new Response(200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => 5 * 1024 * 1024,
        ])));
    }

    /** @test */
    public function it_does_not_buffer_oversized_text_responses_for_logging()
    {
        config()->set('expose.skip_body_log.size', '1MB');

        $this->assertFalse($this->shouldBufferResponseBodyForLogging(new Response(200, [
            'Content-Type' => 'text/plain',
            'Content-Length' => 2 * 1024 * 1024,
        ])));
    }

    /** @test */
    public function it_still_buffers_small_text_responses_for_logging()
    {
        $this->assertTrue($this->shouldBufferResponseBodyForLogging(new Response(200, [
            'Content-Type' => 'text/plain',
            'Content-Length' => 1024,
        ])));
    }

    protected function shouldBufferResponseBodyForLogging(Response $response): bool
    {
        $httpClient = new HttpClient(
            Loop::get(),
            m::mock(RequestLogger::class),
            new Configuration('127.0.0.1', 8080)
        );

        $method = new \ReflectionMethod(HttpClient::class, 'shouldBufferResponseBodyForLogging');
        $method->setAccessible(true);

        return $method->invoke($httpClient, $response, Request::fromString("GET /big.jpg HTTP/1.1\r\nHost: example.com\r\n\r\n"));
    }
}
