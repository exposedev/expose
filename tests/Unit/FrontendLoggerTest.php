<?php

namespace Tests\Unit;

use Expose\Client\Logger\FrontendLogger;
use Expose\Client\Logger\LoggedRequest;
use Expose\Client\WebSockets\Socket;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Request;
use Laminas\Http\Request as LaminasRequest;
use Mockery as m;
use Ratchet\ConnectionInterface;
use React\Http\Browser;
use Tests\TestCase;

class FrontendLoggerTest extends TestCase
{
    protected function tearDown(): void
    {
        Socket::$connections = [];

        parent::tearDown();
    }

    /** @test */
    public function it_does_not_push_logs_to_the_dashboard_when_no_dashboard_socket_is_connected()
    {
        $browser = m::mock(Browser::class);
        $browser->shouldNotReceive('post');

        (new FrontendLogger($browser))->synchronizeRequest($this->loggedRequest());
    }

    /** @test */
    public function it_pushes_logs_to_the_dashboard_when_a_dashboard_socket_is_connected()
    {
        Socket::$connections[] = m::mock(ConnectionInterface::class);

        $browser = m::mock(Browser::class);
        $browser->shouldReceive('post')->once();

        (new FrontendLogger($browser))->synchronizeRequest($this->loggedRequest());
    }

    protected function loggedRequest(): LoggedRequest
    {
        $request = new Request('GET', '/example');
        $requestString = Message::toString($request);

        return new LoggedRequest($requestString, LaminasRequest::fromString($requestString));
    }
}
