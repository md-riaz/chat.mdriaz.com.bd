<?php

namespace App;

use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;

/**
 * Basic WebSocket chat server that tracks connections by user and conversation.
 *
 * Connections can be opened with query parameters like:
 *   ws://host/chat?user_id=1&conversation_id=2
 * Messages sent by a client are broadcast to all other clients in the same
 * conversation.
 */
class ChatServer implements MessageComponentInterface
{
    /** @var array<int|string, \SplObjectStorage<ConnectionInterface>> */
    private array $users = [];

    /** @var array<int|string, \SplObjectStorage<ConnectionInterface>> */
    private array $conversations = [];

    public function onOpen(ConnectionInterface $conn): void
    {
        $queryString = '';
        if (isset($conn->httpRequest)) {
            $queryString = $conn->httpRequest->getUri()->getQuery();
        }
        parse_str($queryString, $params);

        $userId = $params['user_id'] ?? null;
        $conversationId = $params['conversation_id'] ?? null;

        if ($userId !== null) {
            $conn->userId = $userId;
            if (!isset($this->users[$userId])) {
                $this->users[$userId] = new \SplObjectStorage();
            }
            $this->users[$userId]->attach($conn);
        }

        if ($conversationId !== null) {
            $conn->conversationId = $conversationId;
            if (!isset($this->conversations[$conversationId])) {
                $this->conversations[$conversationId] = new \SplObjectStorage();
            }
            $this->conversations[$conversationId]->attach($conn);
        }

        $info = ["#{$conn->resourceId}"];
        if ($userId !== null) {
            $info[] = "user {$userId}";
        }
        if ($conversationId !== null) {
            $info[] = "conversation {$conversationId}";
        }
        echo "Connection opened: " . implode(' ', $info) . "\n";
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $conversationId = $from->conversationId ?? null;
        if ($conversationId !== null && isset($this->conversations[$conversationId])) {
            foreach ($this->conversations[$conversationId] as $client) {
                if ($client !== $from) {
                    $client->send($msg);
                }
            }
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $this->detachConnection($conn);
        echo "Connection {$conn->resourceId} closed\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        echo "Error on connection {$conn->resourceId}: {$e->getMessage()}\n";
        $this->detachConnection($conn);
        $conn->close();
    }

    private function detachConnection(ConnectionInterface $conn): void
    {
        if (isset($conn->userId) && isset($this->users[$conn->userId])) {
            $this->users[$conn->userId]->detach($conn);
            if ($this->users[$conn->userId]->count() === 0) {
                unset($this->users[$conn->userId]);
            }
        }

        if (isset($conn->conversationId) && isset($this->conversations[$conn->conversationId])) {
            $this->conversations[$conn->conversationId]->detach($conn);
            if ($this->conversations[$conn->conversationId]->count() === 0) {
                unset($this->conversations[$conn->conversationId]);
            }
        }
    }
}
