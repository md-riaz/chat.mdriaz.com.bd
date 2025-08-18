<?php

namespace App\WebSocket;

use Ratchet\ConnectionInterface;

class Subscriber
{
    public int $userId;
    public ConnectionInterface $connection;
    /** @var array<int,bool> */
    public array $subscriptions = [];

    public function __construct(int $userId, ConnectionInterface $connection)
    {
        $this->userId = $userId;
        $this->connection = $connection;
    }

    public function subscribe(int $conversationId): void
    {
        $this->subscriptions[$conversationId] = true;
    }

    public function unsubscribe(int $conversationId): void
    {
        unset($this->subscriptions[$conversationId]);
    }

    public function getSubscriptions(): array
    {
        return array_keys($this->subscriptions);
    }

    public function isSubscribed(int $conversationId): bool
    {
        return isset($this->subscriptions[$conversationId]);
    }
}
