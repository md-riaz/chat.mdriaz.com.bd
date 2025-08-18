<?php

namespace App;

use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use App\Api\Models\UserModel;

class ChatServer implements MessageComponentInterface
{
    /**
     * Active client connections keyed by the connection object
     */
    private \SplObjectStorage $clients;

    private $redisFactory = null;

    private string $redisUri = '';

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();

        if (class_exists('\\Clue\\React\\Redis\\Factory')) {
            $loop = \React\EventLoop\Loop::get();
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
        }
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

        $this->clients->attach($conn, [
            'userId' => (int)$user['id'],
        ]);

        echo "Connection opened: #{$conn->resourceId} user {$user['id']}\n";
    }

    private function subscribeUser(ConnectionInterface $conn): void
    {
        $info = $this->clients[$conn];
        if (isset($info['redisClient'])) {
            return;
        }

        if ($this->redisFactory) {
            $channel = 'user:' . $info['userId'];
            $this->redisFactory->createClient($this->redisUri)->then(function ($client) use ($conn, $channel) {
                $client->subscribe($channel);
                $client->on('message', function (string $chan, string $payload) use ($conn) {
                    $conn->send($payload);
                });

                $info = $this->clients[$conn];
                $info['redisClient'] = $client;
                $this->clients[$conn] = $info;
            });
        }
    }

    private function unsubscribeUser(ConnectionInterface $conn): void
    {
        $info = $this->clients[$conn];
        if (!isset($info['redisClient'])) {
            return;
        }

        $client = $info['redisClient'];
        $client->unsubscribe('user:' . $info['userId'])->then(function () use ($client) {
            $client->close();
        });
        unset($info['redisClient']);

        $this->clients[$conn] = $info;
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        if (!$this->clients->contains($from)) {
            return;
        }

        $data = json_decode($msg, true);
        if (!is_array($data)) {
            return;
        }

        $action = $data['action'] ?? null;
        if ($action === 'subscribe') {
            $this->subscribeUser($from);
        } elseif ($action === 'unsubscribe') {
            $this->unsubscribeUser($from);
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        if ($this->clients->contains($conn)) {
            $this->unsubscribeUser($conn);
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
