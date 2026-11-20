<?php

namespace Tests\Unit;

use Expose\Client\Logger\LoggedRequest;
use GuzzleHttp\Psr7\Request;
use Laminas\Http\Request as LaminasRequest;
use Tests\TestCase;
use GuzzleHttp\Psr7\Message;

class LoggedRequestTest extends TestCase
{
    /** @test */
    public function it_retrieves_the_request_id()
    {
        $rawRequest = Message::toString(new Request('GET', '/expose', [
            'X-Expose-Request-ID' => 'example-request',
        ]));
        $parsedRequest = LaminasRequest::fromString($rawRequest);

        $loggedRequest = new LoggedRequest($rawRequest, $parsedRequest);
        $this->assertSame('example-request', $loggedRequest->id());
    }

    /** @test */
    public function it_retrieves_the_request_for_chrome_extensions()
    {
        $rawRequest = Message::toString(new Request('GET', '/expose', [
            'Origin' => 'chrome-extension://expose',
            'X-Expose-Request-ID' => 'example-request',
        ]));
        $parsedRequest = LaminasRequest::fromString($rawRequest);

        $loggedRequest = new LoggedRequest($rawRequest, $parsedRequest);
        $this->assertSame('example-request', $loggedRequest->id());
    }

    /** @test */
    public function it_returns_post_data_for_json_payloads()
    {
        $postData = [
            'name' => 'Marcel',
            'project' => 'expose',
        ];

        $rawRequest = Message::toString(new Request('GET', '/expose', [
            'Content-Type' => 'application/json',
        ], json_encode($postData)));
        $parsedRequest = LaminasRequest::fromString($rawRequest);

        $loggedRequest = new LoggedRequest($rawRequest, $parsedRequest);

        $this->assertSame([
            [
                'name' => 'name',
                'value' => 'Marcel',
            ],
            [
                'name' => 'project',
                'value' => 'expose',
            ],
        ], $loggedRequest->getPostData());
    }

    /** @test */
    public function it_returns_the_raw_request()
    {
        $rawRequest = Message::toString(new Request('GET', '/expose', [
            'X-Expose-Request-ID' => 'example-request',
        ]));
        $parsedRequest = LaminasRequest::fromString($rawRequest);

        $loggedRequest = new LoggedRequest($rawRequest, $parsedRequest);
        $this->assertSame($rawRequest, $loggedRequest->getRequestData());
    }

    /** @test */
    public function it_returns_the_parsed_request()
    {
        $rawRequest = Message::toString(new Request('GET', '/expose', [
            'X-Expose-Request-ID' => 'example-request',
        ]));
        $parsedRequest = LaminasRequest::fromString($rawRequest);

        $loggedRequest = new LoggedRequest($rawRequest, $parsedRequest);
        $this->assertSame($parsedRequest, $loggedRequest->getRequest());
    }

    /** @test */
    public function it_skips_a_malformed_cookie_header()
    {
        $rawRequest = "POST /webhooks/test HTTP/1.1\r\n"
            ."Host: expose.test\r\n"
            ."Cookie:\r\n"
            ."X-Expose-Request-ID: example-request\r\n"
            ."Content-Type: application/json\r\n"
            ."\r\n"
            .'{"event":"ping"}';
        $parsedRequest = LaminasRequest::fromString($rawRequest);

        $loggedRequest = new LoggedRequest($rawRequest, $parsedRequest);

        $this->assertSame('example-request', $loggedRequest->id());
        $this->assertSame('expose.test', $loggedRequest->toArray()['request']['headers']['Host']);
        $this->assertArrayNotHasKey('Cookie', $loggedRequest->toArray()['request']['headers']);
    }
}
