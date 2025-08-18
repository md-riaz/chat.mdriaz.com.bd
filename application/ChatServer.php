<?php

namespace App;

use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use App\WebSocket\Subscriber;
use App\Api\Models\UserModel;

class ChatServer implements MessageComponentInterface
{
    /** @var array<int,Subscriber> */
    private array $subscribers = [];

    /** @var array<int,array<int,Subscriber>> */
    private array $conversations = [];

    public function __construct()
    {
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
                $client->subscribe('chat_events');
                $client->on('message', function ($channel, $payload) {
                    $event = json_decode($payload, true);
                    if (!isset($event['conversation_id'])) {
                        return;
                    }
                    $conversationId = (int)$event['conversation_id'];
                    if (!isset($this->conversations[$conversationId])) {
                        return;
                    }
                    foreach ($this->conversations[$conversationId] as $subscriber) {
                        $subscriber->connection->send($payload);
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

        $subscriber = new Subscriber((int)$user['id'], $conn);
        $this->subscribers[$conn->resourceId] = $subscriber;

        if (!empty($params['conversation_id'])) {
            $this->subscribeToConversation($subscriber, (int)$params['conversation_id']);
        }

        echo "Connection opened: #{$conn->resourceId} user {$subscriber->userId}\n";
    }

    private function subscribeToConversation(Subscriber $subscriber, int $conversationId): void
    {
        $subscriber->subscribe($conversationId);
        $this->conversations[$conversationId][$subscriber->connection->resourceId] = $subscriber;
    }

    private function unsubscribeFromConversation(Subscriber $subscriber, int $conversationId): void
    {
        $subscriber->unsubscribe($conversationId);
        unset($this->conversations[$conversationId][$subscriber->connection->resourceId]);
        if (empty($this->conversations[$conversationId])) {
            unset($this->conversations[$conversationId]);
        }
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $subscriber = $this->subscribers[$from->resourceId] ?? null;
        if (!$subscriber) {
            return;
        }
        $data = json_decode($msg, true);
        if (!is_array($data)) {
            return;
        }
        $action = $data['action'] ?? null;
        $conversationId = isset($data['conversation_id']) ? (int)$data['conversation_id'] : null;
        if ($action === 'subscribe' && $conversationId !== null) {
            $this->subscribeToConversation($subscriber, $conversationId);
        } elseif ($action === 'unsubscribe' && $conversationId !== null) {
            $this->unsubscribeFromConversation($subscriber, $conversationId);
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $subscriber = $this->subscribers[$conn->resourceId] ?? null;
        if ($subscriber) {
            foreach ($subscriber->getSubscriptions() as $conversationId) {
                $this->unsubscribeFromConversation($subscriber, (int)$conversationId);
            }
            unset($this->subscribers[$conn->resourceId]);
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
