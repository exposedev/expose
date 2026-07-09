<?php

namespace Tests\Unit;

use Expose\Client\WebSockets\Socket;
use Mockery as m;
use Ratchet\ConnectionInterface;
use Tests\TestCase;

class WebSocketSocketTest extends TestCase
{
    protected function tearDown(): void
    {
        Socket::$connections = [];

        parent::tearDown();
    }

    /** @test */
    public function it_removes_closed_connections_from_the_dashboard_socket_list()
    {
        $socket = new Socket();
        $connection = m::mock(ConnectionInterface::class);

        $socket->onOpen($connection);
        $this->assertCount(1, Socket::$connections);

        $socket->onClose($connection);

        $this->assertCount(0, Socket::$connections);
    }

    /** @test */
    public function it_removes_failed_connections_from_the_dashboard_socket_list()
    {
        $socket = new Socket();
        $connection = m::mock(ConnectionInterface::class);

        $socket->onOpen($connection);
        $socket->onError($connection, new \Exception('Connection failed'));

        $this->assertCount(0, Socket::$connections);
    }
}
