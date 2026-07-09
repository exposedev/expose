<?php

namespace Expose\Client\WebSockets;

use Ratchet\ConnectionInterface;
use Ratchet\RFC6455\Messaging\MessageInterface;
use Ratchet\WebSocket\MessageComponentInterface;

class Socket implements MessageComponentInterface
{
    public static $connections = [];

    public function onOpen(ConnectionInterface $connection)
    {
        self::$connections[spl_object_id($connection)] = $connection;
    }

    public function onMessage(ConnectionInterface $from, MessageInterface $msg)
    {
    }

    public function onClose(ConnectionInterface $connection)
    {
        unset(self::$connections[spl_object_id($connection)]);
    }

    public function onError(ConnectionInterface $connection, \Exception $e)
    {
        $this->onClose($connection);
    }
}
