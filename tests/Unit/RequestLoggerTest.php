<?php

namespace Tests\Unit;

use Expose\Client\Logger\CliLogger;
use Expose\Client\Logger\DatabaseLogger;
use Expose\Client\Logger\FrontendLogger;
use Expose\Client\Logger\RequestLogger;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;
use Laminas\Http\Request as LaminasRequest;
use Mockery as m;
use Tests\TestCase;
use GuzzleHttp\Psr7\Message;

class RequestLoggerTest extends TestCase
{
    /** @test */
    public function it_can_log_requests()
    {
        $cliLogger = m::mock(CliLogger::class);
        $cliLogger->shouldReceive('synchronizeRequest')->once();

        $frontendLogger = m::mock(FrontendLogger::class);
        $frontendLogger->shouldReceive('synchronizeRequest')->once();

        $logStorage = new DatabaseLogger();

        $requestString = Message::toString(new Request('GET', '/example'));
        $parsedRequest = LaminasRequest::fromString($requestString);

        $logger = new RequestLogger($cliLogger, $frontendLogger, $logStorage);
        $logger->logRequest($requestString, $parsedRequest);

        $this->assertCount(1, $logger->getData());
    }

    /** @test */
    public function it_can_clear_the_requests()
    {
        $cliLogger = m::mock(CliLogger::class);
        $cliLogger->shouldReceive('synchronizeRequest')->once();

        $frontendLogger = m::mock(FrontendLogger::class);
        $frontendLogger->shouldReceive('synchronizeRequest')->once();

        $logStorage = new DatabaseLogger();

        $requestString = Message::toString(new Request('GET', '/example'));
        $parsedRequest = LaminasRequest::fromString($requestString);

        $logger = new RequestLogger($cliLogger, $frontendLogger, $logStorage);
        $logger->logRequest($requestString, $parsedRequest);

        $logger->clear();

        $this->assertCount(0, $logger->getData());
    }

    /** @test */
    public function it_can_associate_a_response_with_a_request()
    {
        $cliLogger = m::mock(CliLogger::class);
        $cliLogger->shouldReceive('synchronizeRequest')->once();
        $cliLogger->shouldReceive('synchronizeResponse')->once();

        $frontendLogger = m::mock(FrontendLogger::class);
        $frontendLogger->shouldReceive('synchronizeRequest')->once();
        $frontendLogger->shouldReceive('synchronizeResponse')->once();

        $logStorage = new DatabaseLogger();

        $requestString = Message::toString(new Request('GET', '/example', ["x-expose-request-id" => Str::uuid()->toString()]));
        $parsedRequest = LaminasRequest::fromString($requestString);

        $logger = new RequestLogger($cliLogger, $frontendLogger, $logStorage);
        $loggedRequest = $logger->logRequest($requestString, $parsedRequest);

        $this->assertNull($logger->findLoggedRequest($loggedRequest->id())->getResponse());

        $responseString = Message::toString(new Response(200, [], 'Hello World!'));

        $logger->logResponse($parsedRequest, $responseString);

        $this->assertNotNull($logger->findLoggedRequest($loggedRequest->id())->getResponse());
    }

    /** @test */
    public function it_can_find_a_request_by_id()
    {
        $cliLogger = m::mock(CliLogger::class);
        $cliLogger->shouldReceive('synchronizeRequest')->once();

        $frontendLogger = m::mock(FrontendLogger::class);
        $frontendLogger->shouldReceive('synchronizeRequest')->once();

        $logStorage = new DatabaseLogger();

        $requestString = Message::toString(new Request('GET', '/example'));
        $parsedRequest = LaminasRequest::fromString($requestString);

        $logger = new RequestLogger($cliLogger, $frontendLogger, $logStorage);

        $loggedRequest = $logger->logRequest($requestString, $parsedRequest);

        $this->assertEquals($loggedRequest->id(), $logger->findLoggedRequest($loggedRequest->id())->id());
        $this->assertEquals($loggedRequest->getRequest()->getUri(), $logger->findLoggedRequest($loggedRequest->id())->getRequest()->getUri());
    }

    /** @test */
    public function it_only_stores_a_limited_amount_of_requests()
    {
        $numberOfRequests = config('expose.max_logged_requests') + 1;

        $cliLogger = m::mock(CliLogger::class);
        $cliLogger->shouldReceive('synchronizeRequest')->times($numberOfRequests);

        $frontendLogger = m::mock(FrontendLogger::class);
        $frontendLogger->shouldReceive('synchronizeRequest')->times($numberOfRequests);

        $logStorage = new DatabaseLogger();

        $requestString = Message::toString(new Request('GET', '/example'));
        $parsedRequest = LaminasRequest::fromString($requestString);

        $logger = new RequestLogger($cliLogger, $frontendLogger, $logStorage);

        foreach (range(1, $numberOfRequests) as $i) {
            $logger->logRequest($requestString, $parsedRequest);
        }

        $this->assertCount(config('expose.max_logged_requests'), $logger->getData());
    }

    /** @test */
    public function it_does_not_log_requests_matching_the_skip_request_log_patterns()
    {
        config()->set('expose.skip_request_log', ['/livewire/*', '*.css']);

        $cliLogger = m::mock(CliLogger::class);
        $cliLogger->shouldNotReceive('synchronizeRequest');
        $cliLogger->shouldNotReceive('synchronizeResponse');

        $frontendLogger = m::mock(FrontendLogger::class);
        $frontendLogger->shouldNotReceive('synchronizeRequest');
        $frontendLogger->shouldNotReceive('synchronizeResponse');

        $logStorage = new DatabaseLogger();

        $logger = new RequestLogger($cliLogger, $frontendLogger, $logStorage);

        foreach (['/livewire/update', '/css/app.css?ver=6.7.2'] as $uri) {
            $requestString = Message::toString(new Request('GET', $uri, ["x-expose-request-id" => Str::uuid()->toString()]));
            $parsedRequest = LaminasRequest::fromString($requestString);

            $logger->logRequest($requestString, $parsedRequest);
            $logger->logResponse($parsedRequest, Message::toString(new Response(200, [], 'Hello World!')));
        }

        $this->assertCount(0, $logger->getData());
    }

    /** @test */
    public function it_logs_requests_not_matching_the_skip_request_log_patterns()
    {
        config()->set('expose.skip_request_log', ['/livewire/*']);

        $cliLogger = m::mock(CliLogger::class);
        $cliLogger->shouldReceive('synchronizeRequest')->once();

        $frontendLogger = m::mock(FrontendLogger::class);
        $frontendLogger->shouldReceive('synchronizeRequest')->once();

        $logStorage = new DatabaseLogger();

        $requestString = Message::toString(new Request('GET', '/example'));
        $parsedRequest = LaminasRequest::fromString($requestString);

        $logger = new RequestLogger($cliLogger, $frontendLogger, $logStorage);
        $logger->logRequest($requestString, $parsedRequest);

        $this->assertCount(1, $logger->getData());
    }
}
