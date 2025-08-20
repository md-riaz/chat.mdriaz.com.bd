<?php

namespace App\Api\Services;

use Framework\Core\DBManager;
use App\Api\Models\AuthTokenModel;
use App\Api\Models\ConversationModel;
use App\Api\Models\ConversationParticipantModel;
use App\Api\Models\MessageModel;
use App\Api\Models\UserModel;

class ChatService
{
    protected $db;
    protected $redis;

    public function __construct(DBManager $db, RedisService $redis)
    {
        $this->db = $db;
        $this->redis = $redis;
    }

    public function getRedis()
    {
        return $this->redis;
    }

    /**
     * Authenticate user by token
     */
    public function authenticateUser($token)
    {
        if (!$token) {
            return null;
        }

        $tokenModel = AuthTokenModel::validateToken($token);

        if (!$tokenModel) {
            return null;
        }

        $user = $tokenModel->user;
        if ($user) {
            $data = $user->toArray();
            $data['user_id'] = $user->id; // For compatibility
            return $data;
        }

        return null;
    }

    /**
     * Send message through WebSocket
     */
    public function sendMessage($senderId, $conversationId, $content, $messageType = 'text', $parentId = null)
    {
        // Validate if user is participant
        if (!ConversationParticipantModel::isParticipant($conversationId, $senderId)) {
            throw new \Exception('User is not a participant in this conversation');
        }

        try {
            $this->db->beginTransaction();

            // Create message
            $messageId = MessageModel::createMessage(
                $conversationId,
                $senderId,
                $content,
                $messageType,
                $parentId
            );

            // Get message with details for broadcasting
            $message = MessageModel::getMessageWithDetails($messageId);

            // Update conversation last message timestamp
            ConversationModel::updateTimestamp($conversationId);

            $this->db->commit();

            return $message;
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Get conversation participants for broadcasting
     */
    public function getConversationParticipants($conversationId)
    {
        return ConversationParticipantModel::getParticipants($conversationId);
    }

    /**
     * Update user typing status with Redis caching
     */
    public function setTypingStatus($userId, $conversationId, $isTyping)
    {
        $typingData = [
            'user_id' => $userId,
            'conversation_id' => $conversationId,
            'is_typing' => $isTyping,
            'timestamp' => time()
        ];

        // Store in Redis with TTL
        if ($this->redis && $this->redis->isConnected()) {
            $key = "typing:{$conversationId}:{$userId}";
            if ($isTyping) {
                $this->redis->set($key, json_encode($typingData), 10); // 10 seconds TTL
            } else {
                $this->redis->delete($key);
            }
        }

        return $typingData;
    }

    /**
     * Mark message as read
     */
    public function markMessageAsRead($messageId, $userId)
    {
        $message = MessageModel::get($messageId);

        if (!$message) {
            throw new \Exception('Message not found');
        }

        // Validate user is participant
        if (!ConversationParticipantModel::isParticipant($message['conversation_id'], $userId)) {
            throw new \Exception('User is not a participant in this conversation');
        }

        // Mark as read
        MessageModel::markAsRead($messageId, $userId);

        // Update participant's last read message
        ConversationParticipantModel::updateLastReadMessage($message['conversation_id'], $userId, $messageId);

        return true;
    }

    /**
     * Add reaction to message
     */
    public function addReaction($messageId, $userId, $emoji)
    {
        $message = MessageModel::get($messageId);

        if (!$message) {
            throw new \Exception('Message not found');
        }

        // Validate user is participant
        if (!ConversationParticipantModel::isParticipant($message['conversation_id'], $userId)) {
            throw new \Exception('User is not a participant in this conversation');
        }

        // Add reaction
        $result = MessageModel::toggleReaction($messageId, $userId, $emoji);

        // Get updated reactions for broadcasting
        $reactions = MessageModel::getReactions($messageId);

        return [
            'message_id' => $messageId,
            'reactions' => $reactions,
            'added_by' => $userId,
            'action' => $result ? 'added' : 'removed'
        ];
    }

    /**
     * Get user's online status from Redis
     */
    public function getUserOnlineStatus($userId)
    {
        if ($this->redis && $this->redis->isConnected()) {
            $statusData = $this->redis->get("user_status:{$userId}");
            if ($statusData) {
                return json_decode($statusData, true);
            }
        }

        // Fallback to database or default status
        return [
            'user_id' => $userId,
            'is_online' => false,
            'last_seen' => null
        ];
    }

    /**
     * Set user online status in Redis
     */
    public function setUserOnlineStatus($userId, $isOnline)
    {
        $statusData = [
            'user_id' => $userId,
            'is_online' => $isOnline,
            'timestamp' => time(),
            'last_seen' => $isOnline ? null : date('Y-m-d H:i:s')
        ];

        // Store in Redis
        if ($this->redis && $this->redis->isConnected()) {
            $key = "user_status:{$userId}";
            if ($isOnline) {
                $this->redis->set($key, json_encode($statusData), 300); // 5 minutes TTL
            } else {
                $statusData['last_seen'] = date('Y-m-d H:i:s');
                $this->redis->set($key, json_encode($statusData), 86400); // 24 hours TTL for offline status
            }

            // Add to online users set
            if ($isOnline) {
                $this->redis->sadd('online_users', $userId);
            } else {
                $this->redis->srem('online_users', $userId);
            }
        }

        return $statusData;
    }

    /**
     * Get conversation details for WebSocket clients
     */
    public function getConversationDetails($conversationId, $userId)
    {
        // Validate user is participant
        if (!ConversationParticipantModel::isParticipant($conversationId, $userId)) {
            throw new \Exception('User is not a participant in this conversation');
        }

        $conversation = ConversationModel::getConversationDetails($conversationId, $userId);

        if (!$conversation) {
            throw new \Exception('Conversation not found');
        }

        return $conversation;
    }

    /**
     * Validate message data
     */
    public function validateMessage($data)
    {
        $required = ['conversation_id', 'content'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new \Exception("Field '{$field}' is required");
            }
        }

        return true;
    }

    /**
     * Format message for WebSocket broadcast
     */
    public function formatMessageForBroadcast($message, $type = 'new_message')
    {
        return [
            'type' => $type,
            'data' => [
                'message' => $message,
                'timestamp' => time(),
                'server_time' => date('Y-m-d H:i:s')
            ]
        ];
    }

    /**
     * Format typing status for WebSocket broadcast
     */
    public function formatTypingForBroadcast($typingData)
    {
        return [
            'type' => 'typing_status',
            'data' => $typingData
        ];
    }

    /**
     * Format reaction for WebSocket broadcast
     */
    public function formatReactionForBroadcast($reactionData)
    {
        return [
            'type' => 'message_reaction',
            'data' => $reactionData
        ];
    }

    /**
     * Format user status for WebSocket broadcast
     */
    public function formatUserStatusForBroadcast($statusData)
    {
        return [
            'type' => 'user_status',
            'data' => $statusData
        ];
    }

    /**
     * Get unread count for user
     */
    public function getUnreadCount($userId)
    {
        return ConversationModel::getTotalUnreadCount($userId);
    }

    /**
     * Log WebSocket activity with Redis caching
     */
    public function logActivity($userId, $action, $data = null)
    {
        $logData = [
            'user_id' => $userId,
            'action' => $action,
            'data' => $data ? json_encode($data) : null,
            'timestamp' => date('Y-m-d H:i:s'),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];

        // Store in Redis for recent activity monitoring
        if ($this->redis && $this->redis->isConnected()) {
            $key = "activity:{$userId}:" . time();
            $this->redis->set($key, json_encode($logData), 3600); // 1 hour TTL
        }

        // Also log to file for persistent logging
        error_log("WebSocket Activity: " . json_encode($logData));
    }

    /**
     * Cache message in Redis for quick access
     */
    public function cacheMessage($message)
    {
        if ($this->redis && $this->redis->isConnected()) {
            $key = "message:{$message['id']}";
            $this->redis->set($key, json_encode($message), 3600); // 1 hour cache
        }
    }

    /**
     * Get message from cache or database
     */
    public function getCachedMessage($messageId)
    {
        // Try Redis first
        if ($this->redis && $this->redis->isConnected()) {
            $cached = $this->redis->get("message:{$messageId}");
            if ($cached) {
                return json_decode($cached, true);
            }
        }

        // Fallback to database
        return MessageModel::getMessageWithDetails($messageId);
    }

    /**
     * Get typing users for a conversation
     */
    public function getTypingUsers($conversationId)
    {
        $typingUsers = [];

        if ($this->redis && $this->redis->isConnected()) {
            $pattern = "typing:{$conversationId}:*";
            $iterator = null;
            while ($keys = $this->redis->getRedisInstance()->scan($iterator, $pattern, 100)) {
                foreach ($keys as $key) {
                    $data = $this->redis->get($key);
                    if ($data) {
                        $typingData = json_decode($data, true);
                        if ($typingData && $typingData['is_typing']) {
                            $typingUsers[] = $typingData;
                        }
                    }
                }
            }
        }

        return $typingUsers;
    }

    /**
     * Get online users
     */
    public function getOnlineUsers()
    {
        if ($this->redis && $this->redis->isConnected()) {
            return $this->redis->sMembers('online_users');
        }

        return [];
    }

    /**
     * Cache conversation participants
     */
    public function cacheConversationParticipants($conversationId, $participants)
    {
        if ($this->redis && $this->redis->isConnected()) {
            $key = "conversation_participants:{$conversationId}";
            $this->redis->set($key, json_encode($participants), 1800); // 30 minutes cache
        }
    }

    /**
     * Get cached conversation participants
     */
    public function getCachedConversationParticipants($conversationId)
    {
        // Try Redis first
        if ($this->redis && $this->redis->isConnected()) {
            $cached = $this->redis->get("conversation_participants:{$conversationId}");
            if ($cached) {
                return json_decode($cached, true);
            }
        }

        // Fallback to database and cache result
        $participants = $this->getConversationParticipants($conversationId);
        $this->cacheConversationParticipants($conversationId, $participants);

        return $participants;
    }

    /**
     * Publish message to Redis for WebSocket server
     */
    public function publishMessage($channel, $message)
    {
        if ($this->redis && $this->redis->isConnected()) {
            return $this->redis->publish($channel, json_encode($message));
        }

        return false;
    }

    /**
     * Get conversation statistics
     */
    public function getConversationStats($conversationId)
    {
        $stats = [
            'total_messages' => 0,
            'active_participants' => 0,
            'last_activity' => null
        ];

        // Get from cache first
        if ($this->redis && $this->redis->isConnected()) {
            $cached = $this->redis->get("conversation_stats:{$conversationId}");
            if ($cached) {
                return json_decode($cached, true);
            }
        }

        // Calculate from database
        $stats['total_messages'] = MessageModel::getConversationMessageCount($conversationId);
        $stats['last_activity'] = MessageModel::getLastActivity($conversationId);
        $stats['active_participants'] = ConversationParticipantModel::getParticipantCount($conversationId);


        // Cache for 5 minutes
        if ($this->redis && $this->redis->isConnected()) {
            $this->redis->set("conversation_stats:{$conversationId}", json_encode($stats), 300);
        }

        return $stats;
    }
}
