<?php

namespace App;

use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use App\Api\Models\UserModel;
use React\EventLoop\Loop;

class ChatServer implements MessageComponentInterface
{
    /**
     * Active client connections keyed by the connection object
     */
    private \SplObjectStorage $clients;

    /**
     * Map of user IDs to their active websocket connections
     *
     * @var array<int, array<int, ConnectionInterface>>
     */
    private array $userSockets = [];

    private $redisFactory = null;

    /** @var \Clue\React\Redis\Client|null */
    private $redisSubscriber = null;

    private string $redisUri = '';

    /**
     * Interval in seconds between heartbeat pings
     */
    private int $heartbeatInterval = 30;

    /**
     * How long to wait for a pong response before timing out
     */
    private int $heartbeatTimeout = 60;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();

        if (class_exists('\\Clue\\React\\Redis\\Factory')) {
            $loop = Loop::get();
            $this->redisFactory = new \Clue\React\Redis\Factory($loop);

            $uri = 'redis://';
            if (defined('REDIS_PASSWORD') && REDIS_PASSWORD !== '') {
                $uri .= ':' . urlencode(REDIS_PASSWORD) . '@';
            }
            $uri .= REDIS_HOST . ':' . REDIS_PORT;
            if (defined('REDIS_DATABASE') && REDIS_DATABASE > 0) {
                $uri .= '/' . REDIS_DATABASE;
            }

            $this->redisUri = $uri;

            $this->redisFactory->createClient($this->redisUri)->then(function ($client) {
                $this->redisSubscriber = $client;
                $client->psubscribe('user:*');
                $client->on('pmessage', function ($pattern, $channel, $payload) {
                    $userId = (int)substr($channel, strpos($channel, ':') + 1);
                    if (isset($this->userSockets[$userId])) {
                        foreach ($this->userSockets[$userId] as $socket) {
                            $socket->send($payload);
                        }
                    }
                });
            });
        }

        Loop::addPeriodicTimer($this->heartbeatInterval, function () {
            $now = time();
            foreach ($this->clients as $conn) {
                $info = $this->clients[$conn];
                $lastPong = $info['lastPong'] ?? $now;
                if ($now - $lastPong >= $this->heartbeatTimeout) {
                    $conn->close();
                    continue;
                }
                $conn->send(json_encode(['type' => 'ping']));
            }
        });
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $queryString = '';
        if (isset($conn->httpRequest)) {
            $queryString = $conn->httpRequest->getUri()->getQuery();
        }
        parse_str($queryString, $params);

        $token = $params['token'] ?? null;
        $user = $token ? UserModel::validateToken($token) : null;

        if (!$user) {
            $conn->send(json_encode(['type' => 'authorization_error', 'message' => 'Invalid or expired token']));
            $conn->close();
            return;
        }

        $userId = (int)$user['id'];
        $this->clients->attach($conn, [
            'userId' => $userId,
            'lastPong' => time(),
        ]);

        if (!isset($this->userSockets[$userId])) {
            $this->userSockets[$userId] = [];
        }
        $this->userSockets[$userId][$conn->resourceId] = $conn;

        echo "Connection opened: #{$conn->resourceId} user {$user['id']}\n";
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $data = json_decode($msg, true);
        if (isset($data['type']) && $data['type'] === 'pong' && $this->clients->contains($from)) {
            $info = $this->clients[$from];
            $info['lastPong'] = time();
            $this->clients[$from] = $info;
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        if ($this->clients->contains($conn)) {
            $info = $this->clients[$conn];
            $userId = $info['userId'];
            unset($this->userSockets[$userId][$conn->resourceId]);
            if (empty($this->userSockets[$userId])) {
                unset($this->userSockets[$userId]);
            }
            $this->clients->detach($conn);
        }
        echo "Connection {$conn->resourceId} closed\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        echo "Error on connection {$conn->resourceId}: {$e->getMessage()}\n";
        $this->onClose($conn);
        $conn->close();
    }
}
