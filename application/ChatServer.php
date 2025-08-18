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

    private $redisClient = null;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();

        if (class_exists('\\Clue\\React\\Redis\\Factory')) {
            $loop = \React\EventLoop\Loop::get();
            $factory = new \Clue\React\Redis\Factory($loop);

            $uri = 'redis://';
            if (defined('REDIS_PASSWORD') && REDIS_PASSWORD !== '') {
                $uri .= ':' . urlencode(REDIS_PASSWORD) . '@';
            }
            $uri .= REDIS_HOST . ':' . REDIS_PORT;
            if (defined('REDIS_DATABASE') && REDIS_DATABASE > 0) {
                $uri .= '/' . REDIS_DATABASE;
            }

            $factory->createLazyClient($uri)->then(function ($client) {
                $this->redisClient = $client;
                $client->on('message', function ($channel, $payload) {
                    if (preg_match('/^user:(\d+)$/', $channel, $matches)) {
                        $userId = (int)$matches[1];
                        foreach ($this->clients as $conn) {
                            $info = $this->clients[$conn];
                            if ($info['userId'] === $userId && $info['subscribed']) {
                                $conn->send($payload);
                            }
                        }
                    }
                });
            });
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
            'subscribed' => false,
        ]);

        echo "Connection opened: #{$conn->resourceId} user {$user['id']}\n";
    }

    private function subscribeUser(ConnectionInterface $conn): void
    {
        $info = $this->clients[$conn];
        if ($info['subscribed']) {
            return;
        }
        $info['subscribed'] = true;
        $this->clients[$conn] = $info;

        if ($this->redisClient) {
            foreach ($this->clients as $otherConn) {
                if ($otherConn !== $conn) {
                    $otherInfo = $this->clients[$otherConn];
                    if ($otherInfo['userId'] === $info['userId'] && $otherInfo['subscribed']) {
                        return;
                    }
                }
            }
            $this->redisClient->subscribe('user:' . $info['userId']);
        }
    }

    private function unsubscribeUser(ConnectionInterface $conn): void
    {
        $info = $this->clients[$conn];
        if (!$info['subscribed']) {
            return;
        }
        $info['subscribed'] = false;
        $this->clients[$conn] = $info;

        if ($this->redisClient) {
            foreach ($this->clients as $otherConn) {
                if ($otherConn !== $conn) {
                    $otherInfo = $this->clients[$otherConn];
                    if ($otherInfo['userId'] === $info['userId'] && $otherInfo['subscribed']) {
                        return;
                    }
                }
            }
            $this->redisClient->unsubscribe('user:' . $info['userId']);
        }
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
