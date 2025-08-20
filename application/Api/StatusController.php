<?php

namespace App\Api;

use App\Api\ApiController;
use App\Api\Services\RedisService;
use Framework\Core\Util;

class StatusController extends ApiController
{
    /**
     * GET /api/status/typing - Get typing status for conversation
     */
    public function typing()
    {
        $user = $this->authenticate();
        $conversationId = $_GET['conversation_id'] ?? null;

        if (!$conversationId) {
            $this->respondError(400, 'Conversation ID is required');
        }

        try {
            $redis = RedisService::getInstance();
            $typingUsers = [];

            if ($redis->isConnected()) {
                $pattern = "typing:{$conversationId}:*";
                $client = $redis->getRedisInstance();
                $keys = $client->keys($pattern);

                foreach ($keys as $key) {
                    $ttl = $client->ttl($key);
                    if ($ttl <= 0) {
                        // Clean up stale keys
                        $redis->delete($key);
                        continue;
                    }
                    $parts = explode(':', $key);
                    $uid = $parts[2] ?? null;
                    if ($uid !== null) {
                        $typingUsers[] = (int)$uid;
                    }
                }
            }

            $this->respondSuccess([
                'typing_users' => $typingUsers
            ], 'Typing status retrieved successfully');
        } catch (\Exception $e) {
            Util::log($e->getMessage(), [
                'user_id' => $user['user_id'],
                'endpoint' => '/api/status/typing'
            ]);
            $this->respondError(500, 'Failed to get typing status');
        }
    }

    /**
     * POST /api/status/typing - Set typing status
     */
    public function setTyping()
    {
        $user = $this->authenticate();
        $data = $this->getJsonInput();

        $this->validate($data, [
            'conversation_id' => 'required',
            'is_typing' => 'required'
        ]);

        try {
            $redis = RedisService::getInstance();
            $conversationId = $data['conversation_id'];
            $userId = $user['user_id'];

            if ($redis->isConnected()) {
                $typingKey = "typing:{$conversationId}:{$userId}";
                if ($data['is_typing']) {
                    $redis->set($typingKey, 1, 10); // TTL 10 seconds
                } else {
                    $redis->delete($typingKey);
                }

                // Mark user as online in this conversation
                $onlineKey = "online:{$conversationId}:{$userId}";
                $redis->set($onlineKey, 1, 300); // TTL 5 minutes
            }

            $this->respondSuccess(null, 'Typing status updated');
        } catch (\Exception $e) {
            Util::log($e->getMessage(), [
                'user_id' => $user['user_id'],
                'endpoint' => '/api/status/typing'
            ]);
            $this->respondError(500, 'Failed to update typing status');
        }
    }

    /**
     * GET /api/status/online-users - Get online users
     */
    public function onlineUsers()
    {
        $user = $this->authenticate();
        $conversationId = $_GET['conversation_id'] ?? null;

        try {
            $redis = RedisService::getInstance();
            $onlineUsers = [];

            if ($redis->isConnected()) {
                $pattern = $conversationId ? "online:{$conversationId}:*" : "online:*";
                $client = $redis->getRedisInstance();
                $keys = $client->keys($pattern);

                foreach ($keys as $key) {
                    $ttl = $client->ttl($key);
                    if ($ttl <= 0) {
                        $redis->delete($key);
                        continue;
                    }
                    $parts = explode(':', $key);
                    $uid = $parts[2] ?? null;
                    if ($uid !== null) {
                        $onlineUsers[] = (int)$uid;
                    }
                }

                $onlineUsers = array_values(array_unique($onlineUsers));
            }

            $this->respondSuccess([
                'online_users' => $onlineUsers
            ], 'Online users retrieved successfully');
        } catch (\Exception $e) {
            Util::log($e->getMessage(), [
                'user_id' => $user['user_id'],
                'endpoint' => '/api/status/online-users'
            ]);
            $this->respondError(500, 'Failed to get online users');
        }
    }
}
