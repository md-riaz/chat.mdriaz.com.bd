<?php

namespace App;

use App\Api\Services\ChatService;
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

    private $chatService;

    /** @var \Clue\React\Redis\Client|null */
    private $redisSubscriber = null;

    /**
     * Interval in seconds between heartbeat pings
     */
    private int $heartbeatInterval = 30;

    /**
     * How long to wait for a pong response before timing out
     */
    private int $heartbeatTimeout = 60;


    public function __construct(ChatService $chatService)
    {
        $this->clients = new \SplObjectStorage();
        $this->chatService = $chatService;
        $this->connectRedis();


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

    private function connectRedis(): void
    {
        $this->redisSubscriber = $this->chatService->getRedis()->getRedisInstance();
        $this->redisSubscriber->psubscribe('user:*');
        $this->redisSubscriber->on('pmessage', function ($pattern, $channel, $payload) {
            $userId = (int)substr($channel, strpos($channel, ':') + 1);
            $this->broadcastToUser($userId, $payload);
        });
    }

    /**
     * Send a payload to all active sockets for a given user
     */
    private function broadcastToUser(int $userId, string $payload): void
    {
        if (!isset($this->userSockets[$userId])) {
            return;
        }

        foreach ($this->userSockets[$userId] as $socket) {
            $socket->send($payload);
        }
    }

    private function handleSubscriptionIssue(string $message): void
    {
        echo $message . "\n";

        if ($this->redisSubscriber !== null) {
            $this->redisSubscriber->close();
            $this->redisSubscriber = null;
        }

        foreach ($this->clients as $conn) {
            $conn->send(json_encode(['type' => 'subscription_error', 'message' => $message]));
        }

        $this->scheduleReconnect();
    }

    private function scheduleReconnect(): void
    {
        $delay = min(pow(2, $this->reconnectAttempts), $this->maxReconnectDelay);
        $this->reconnectAttempts++;
        Loop::addTimer($delay, function () {
            $this->connectRedis();
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
        $user = $this->chatService->authenticateUser($token);

        if (!$user) {
            $conn->send(json_encode(['type' => 'authorization_error', 'message' => 'Invalid or expired token']));
            $conn->close();
            return;
        }

        $userId = (int)$user['user_id'];
        $this->clients->attach($conn, [
            'userId' => $userId,
            'lastPong' => time(),
        ]);

        if (!isset($this->userSockets[$userId])) {
            $this->userSockets[$userId] = [];
        }
        $this->userSockets[$userId][$conn->resourceId] = $conn;

        echo "Connection opened: #{$conn->resourceId} user {$userId}\n";
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $data = json_decode($msg, true);
        if (!$this->clients->contains($from)) {
            return;
        }

        $info = $this->clients[$from];
        $userId = $info['userId'];

        if (isset($data['type'])) {
            switch ($data['type']) {
                case 'pong':
                    $info['lastPong'] = time();
                    $this->clients[$from] = $info;
                    break;
                case 'message':
                    $this->handleMessage($userId, $data['payload']);
                    break;
                case 'typing':
                    $this->handleTyping($userId, $data['payload']);
                    break;
            }
        }
    }

    private function handleMessage($userId, $payload)
    {
        try {
            $this->chatService->validateMessage($payload);
            $message = $this->chatService->sendMessage(
                $userId,
                $payload['conversation_id'],
                $payload['content'],
                $payload['message_type'] ?? 'text',
                $payload['parent_id'] ?? null
            );
            $this->broadcastToConversation($payload['conversation_id'], 'message', $message);
        } catch (\Exception $e) {
            $this->sendToUser($userId, 'error', ['message' => $e->getMessage()]);
        }
    }

    private function handleTyping($userId, $payload)
    {
        try {
            $this->chatService->setTypingStatus(
                $userId,
                $payload['conversation_id'],
                $payload['is_typing']
            );
            $this->broadcastToConversation($payload['conversation_id'], 'typing', [
                'user_id' => $userId,
                'is_typing' => $payload['is_typing']
            ]);
        } catch (\Exception $e) {
            $this->sendToUser($userId, 'error', ['message' => $e->getMessage()]);
        }
    }

    private function broadcastToConversation($conversationId, $type, $payload)
    {
        $participants = $this->chatService->getConversationParticipants($conversationId);
        foreach ($participants as $participant) {
            $this->sendToUser($participant['id'], $type, $payload);
        }
    }

    private function sendToUser($userId, $type, $payload)
    {
        $this->broadcastToUser($userId, json_encode([
            'type' => $type,
            'payload' => $payload
        ]));
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
