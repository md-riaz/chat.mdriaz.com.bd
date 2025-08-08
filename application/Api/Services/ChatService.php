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
    protected static $instance = null;

    public function __construct()
    {
        $this->db = DBManager::getDB();

        // Initialize Redis if available
        if (class_exists('RedisService')) {
            $this->redis = \RedisService::getInstance();
        }
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Authenticate user by token
     */
    public function authenticateUser($token)
    {
        if (!$token) {
            return null;
        }

        $tokenData = AuthTokenModel::validateToken($token);

        if (!$tokenData) {
            return null;
        }

        // Get user details
        $user = $this->db->query(
            "SELECT id, name, email, username, avatar_url FROM users WHERE id = ?",
            [$tokenData['user_id']]
        )->fetchArray();

        if ($user) {
            $user['user_id'] = $user['id']; // For compatibility
            return $user;
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
            $this->db->query(
                "UPDATE conversations SET updated_at = NOW() WHERE id = ?",
                [$conversationId]
            );

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
        $participants = $this->db->query(
            "SELECT u.id, u.name, u.username, u.avatar_url, cp.role
             FROM conversation_participants cp
             JOIN users u ON cp.user_id = u.id
             WHERE cp.conversation_id = ?",
            [$conversationId]
        )->fetchAll();

        return $participants;
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
        // Get message conversation
        $message = $this->db->query(
            "SELECT conversation_id FROM messages WHERE id = ?",
            [$messageId]
        )->fetchArray();

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
        // Get message conversation
        $message = $this->db->query(
            "SELECT conversation_id FROM messages WHERE id = ?",
            [$messageId]
        )->fetchArray();

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
        $reactions = $this->db->query(
            "SELECT r.emoji, r.user_id, u.name, u.username
             FROM message_reactions r
             JOIN users u ON r.user_id = u.id
             WHERE r.message_id = ?",
            [$messageId]
        )->fetchAll();

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

        $conversation = $this->db->query(
            "SELECT id, title, is_group, created_by, created_at
             FROM conversations
             WHERE id = ?",
            [$conversationId]
        )->fetchArray();

        if (!$conversation) {
            throw new \Exception('Conversation not found');
        }

        // Get participants
        $participants = $this->getConversationParticipants($conversationId);
        $conversation['participants'] = $participants;

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
        $result = $this->db->query(
            "SELECT COUNT(*) as unread_count
             FROM messages m
             JOIN conversation_participants cp ON m.conversation_id = cp.conversation_id
             WHERE cp.user_id = ? 
               AND m.sender_id != ? 
               AND (cp.last_read_message_id IS NULL OR m.id > cp.last_read_message_id)",
            [$userId, $userId]
        )->fetchArray();

        return (int)$result['unread_count'];
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
            // Note: This is a simplified approach. In production, you'd want to use SCAN
            $keys = $this->redis->getRedisInstance()->keys($pattern);

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

        return $typingUsers;
    }

    /**
     * Get online users
     */
    public function getOnlineUsers()
    {
        if ($this->redis && $this->redis->isConnected()) {
            return $this->redis->smembers('online_users');
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
        $result = $this->db->query(
            "SELECT COUNT(*) as total_messages, MAX(created_at) as last_activity
             FROM messages 
             WHERE conversation_id = ?",
            [$conversationId]
        )->fetchArray();

        $stats['total_messages'] = (int)$result['total_messages'];
        $stats['last_activity'] = $result['last_activity'];

        $participantResult = $this->db->query(
            "SELECT COUNT(*) as active_participants
             FROM conversation_participants 
             WHERE conversation_id = ?",
            [$conversationId]
        )->fetchArray();

        $stats['active_participants'] = (int)$participantResult['active_participants'];

        // Cache for 5 minutes
        if ($this->redis && $this->redis->isConnected()) {
            $this->redis->set("conversation_stats:{$conversationId}", json_encode($stats), 300);
        }

        return $stats;
    }
}
