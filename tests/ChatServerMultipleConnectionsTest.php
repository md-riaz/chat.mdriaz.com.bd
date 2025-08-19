<?php

namespace Ratchet {
    interface ConnectionInterface {
        public function send($data);
        public function close();
        public function __get($name);
        public function __set($name, $value);
    }

    interface MessageComponentInterface {
        public function onOpen(ConnectionInterface $conn): void;
        public function onMessage(ConnectionInterface $from, $msg): void;
        public function onClose(ConnectionInterface $conn): void;
        public function onError(ConnectionInterface $conn, \Exception $e): void;
    }
}

namespace React\EventLoop {
    class Loop {
        public static function get() { return new self(); }
        public static function addPeriodicTimer($interval, $callback) {}
        public static function addTimer($interval, $callback) {}
    }
}

namespace App\Api\Models {
    class UserModel {
        public static function validateToken($token) {
            return $token === 'abc' ? ['id' => 123] : null;
        }
    }
}

namespace Tests {

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../application/ChatServer.php';

class DummyConnection implements \Ratchet\ConnectionInterface
{
    public int $resourceId;
    public $httpRequest;
    public array $sent = [];
    private array $props = [];

    public function __construct(int $id, string $token)
    {
        $this->resourceId = $id;
        $this->httpRequest = new class($token) {
            private string $queryString;
            public function __construct(string $token)
            {
                $this->queryString = 'token=' . $token;
            }
            public function getUri()
            {
                return new class($this->queryString) {
                    private string $queryString;
                    public function __construct(string $queryString)
                    {
                        $this->queryString = $queryString;
                    }
                    public function getQuery(): string
                    {
                        return $this->queryString;
                    }
                };
            }
        };
    }

    public function send($data)
    {
        $this->sent[] = $data;
    }

    public function close() {}

    public function __get($name)
    {
        return $this->props[$name] ?? null;
    }

    public function __set($name, $value): void
    {
        $this->props[$name] = $value;
    }
}

class ChatServerMultipleConnectionsTest extends TestCase
{
    public function testMultipleConnectionsReceiveMessages(): void
    {
        $server = new \App\ChatServer();

        $conn1 = new DummyConnection(1, 'abc');
        $conn2 = new DummyConnection(2, 'abc');

        $server->onOpen($conn1);
        $server->onOpen($conn2);

        $prop = new \ReflectionProperty(\App\ChatServer::class, 'userSockets');
        $prop->setAccessible(true);
        $userSockets = $prop->getValue($server);

        $this->assertArrayHasKey(123, $userSockets);
        $this->assertCount(2, $userSockets[123]);

        $payload = json_encode(['type' => 'test']);
        $method = new \ReflectionMethod(\App\ChatServer::class, 'broadcastToUser');
        $method->setAccessible(true);
        $method->invoke($server, 123, $payload);

        $this->assertEquals([$payload], $conn1->sent);
        $this->assertEquals([$payload], $conn2->sent);

        $server->onClose($conn1);
        $userSockets = $prop->getValue($server);
        $this->assertArrayHasKey(123, $userSockets);
        $this->assertCount(1, $userSockets[123]);
    }
}
}

