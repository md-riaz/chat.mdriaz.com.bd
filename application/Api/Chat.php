<?php

namespace App\Api;

use App\Api\ApiController;
use App\Api\Models\ConversationModel;
use App\Api\Models\ConversationParticipantModel;
use App\Api\Models\MessageModel;
use App\Api\Models\UserModel;
use Framework\Core\Util;
use App\Api\Services\RedisService;

class Chat extends ApiController
{
    /**
     * GET /api/chat/conversations - Get user's conversations
     */
    public function conversations()
    {
        $user = $this->authenticate();
        $limit = min((int)($_GET['limit'] ?? 20), 100);
        $lastId = isset($_GET['last_id']) ? (int) $_GET['last_id'] : null;
        $lastTimestamp = $_GET['last_timestamp'] ?? null;

        try {
            $items = ConversationModel::getUserConversationsPaginated($user['user_id'], $limit, $lastId, $lastTimestamp);

            $this->respondCursor($items, $limit, 'Conversations retrieved successfully');
        } catch (\Exception $e) {
            Util::log($e->getMessage(), [
                'user_id' => $user['user_id'],
                'endpoint' => '/api/chat/conversations'
            ]);
            $this->respondError(500, 'Failed to retrieve conversations');
        }
    }

    /**
     * POST /api/chat/send-message - Send a message
     */
    public function sendMessage()
    {
        $user = $this->authenticate();
        $data = $this->getJsonInput();

        $this->validateRequired($data, ['conversation_id', 'content']);
        $tempId = $data['temp_id'] ?? null;

        if (!ConversationParticipantModel::isParticipant($data['conversation_id'], $user['user_id'])) {
            $this->respondError(403, 'User is not a participant of this conversation');
            return;
        }

        try {
            $messageId = MessageModel::createMessage(
                $data['conversation_id'],
                $user['user_id'],
                $data['content'],
                $data['message_type'] ?? 'text',
                $data['parent_id'] ?? null
            );

            // Handle mentions if provided
            if (!empty($data['mentions']) && is_array($data['mentions'])) {
                foreach ($data['mentions'] as $mentionedUserId) {
                    MessageModel::addMention($messageId, $mentionedUserId);
                }
            }

            // Publish message event to WebSocket subscribers using user channels
            $message = MessageModel::getMessageWithDetails($messageId);
            if ($tempId !== null) {
                $message['temp_id'] = $tempId;
            }
            if (class_exists('\\App\\Api\\Services\\RedisService')) {
                $redis = RedisService::getInstance();
                if ($redis->isConnected()) {
                    $participants = ConversationModel::getConversationParticipants($data['conversation_id']);
                    foreach ($participants as $participant) {
                        $redis->publish('user:' . $participant['user_id'], json_encode([
                            'conversation_id' => $data['conversation_id'],
                            'type' => 'message_created',
                            'payload' => $message
                        ]));
                    }
                }
            }

            $this->respondSuccess([
                'message_id' => $messageId,
                'temp_id' => $tempId
            ], 'Message sent successfully', 201);
        } catch (\Exception $e) {
            Util::log($e->getMessage(), [
                'user_id' => $user['user_id'],
                'endpoint' => '/api/chat/send-message'
            ]);
            $this->respondError(500, 'Failed to send message');
        }
    }

    /**
     * GET /api/chat/messages - Get messages for a conversation
     */
    public function messages()
    {
        $user = $this->authenticate();
        $conversationId = $_GET['conversation_id'] ?? null;
        $limit = min((int)($_GET['limit'] ?? 50), 100);
        $lastId = isset($_GET['last_id']) ? (int) $_GET['last_id'] : null;
        $lastTimestamp = $_GET['last_timestamp'] ?? null;
        if ($limit <= 0) {
            $limit = 50;
        }

        if (!$conversationId) {
            $this->respondError(400, 'Conversation ID is required');
        }

        if (!ConversationParticipantModel::isParticipant($conversationId, $user['user_id'])) {
            $this->respondError(403, 'User is not a participant of this conversation');
            return;
        }

        try {
            $messages = MessageModel::getConversationMessagesWithDetails($conversationId, $limit, $lastId, $lastTimestamp);

            $this->respondCursor($messages, $limit, 'Messages retrieved successfully');
        } catch (\Exception $e) {
            Util::log($e->getMessage(), [
                'user_id' => $user['user_id'],
                'endpoint' => '/api/chat/messages'
            ]);
            $this->respondError(500, 'Failed to retrieve messages');
        }
    }

    /**
     * POST /api/chat/create-conversation - Create a new conversation
     */
    public function createConversation()
    {
        $user = $this->authenticate();
        $data = $this->getJsonInput();

        $this->validateRequired($data, ['type']);

        try {
            $this->db->beginTransaction();

            $conversationId = ConversationModel::createConversation(
                $data['name'] ?? null,
                $data['type'] === 'group',
                $user['user_id']
            );

            // Add creator as admin
            ConversationParticipantModel::addParticipant($conversationId, $user['user_id'], true);

            // Add other participants
            if ($data['type'] === 'direct' && !empty($data['participant_id'])) {
                ConversationParticipantModel::addParticipant($conversationId, $data['participant_id'], false);
            } elseif ($data['type'] === 'group' && !empty($data['participant_ids'])) {
                foreach ($data['participant_ids'] as $participantId) {
                    ConversationParticipantModel::addParticipant($conversationId, $participantId, false);
                }
            }

            $this->db->commit();

            // Publish conversation event to WebSocket subscribers using user channels
            if (class_exists('\\App\\Api\\Services\\RedisService')) {
                $redis = RedisService::getInstance();
                if ($redis->isConnected()) {
                    $conversation = ConversationModel::getConversationById($conversationId);
                    $participants = ConversationModel::getConversationParticipants($conversationId);
                    foreach ($participants as $participant) {
                        $redis->publish('user:' . $participant['user_id'], json_encode([
                            'conversation_id' => $conversationId,
                            'type' => 'conversation_created',
                            'payload' => $conversation
                        ]));
                    }
                }
            }

            $this->respondSuccess([
                'conversation_id' => $conversationId
            ], 'Conversation created successfully', 201);
        } catch (\Exception $e) {
            $this->db->rollBack();
            Util::log($e->getMessage(), [
                'user_id' => $user['user_id'],
                'endpoint' => '/api/chat/create-conversation'
            ]);
            $this->respondError(500, 'Failed to create conversation');
        }
    }

    /**
     * POST /api/chat/create-group - Create a new group conversation
     */
    public function createGroup()
    {
        $user = $this->authenticate();
        $data = $this->getJsonInput();

        $this->validateRequired($data, ['name', 'participant_ids']);

        if (!is_array($data['participant_ids']) || count($data['participant_ids']) < 1) {
            $this->respondError(400, 'At least 1 participant is required for group conversations');
        }

        if (in_array($user['user_id'], $data['participant_ids'])) {
            $this->respondError(400, 'You are automatically added to the conversation');
        }

        try {
            $this->db->beginTransaction();

            $conversationId = ConversationModel::createConversation(
                $data['name'],
                true,
                $user['user_id']
            );

            // Add creator as admin
            ConversationParticipantModel::addParticipant($conversationId, $user['user_id'], true);

            foreach ($data['participant_ids'] as $participantId) {
                ConversationParticipantModel::addParticipant($conversationId, $participantId, false);
            }

            $this->db->commit();

            // Publish conversation event to WebSocket subscribers using user channels
            if (class_exists('\\App\\Api\\Services\\RedisService')) {
                $redis = RedisService::getInstance();
                if ($redis->isConnected()) {
                    $conversation = ConversationModel::getConversationById($conversationId);
                    $participants = ConversationModel::getConversationParticipants($conversationId);
                    foreach ($participants as $participant) {
                        $redis->publish('user:' . $participant['user_id'], json_encode([
                            'conversation_id' => $conversationId,
                            'type' => 'conversation_created',
                            'payload' => $conversation
                        ]));
                    }
                }
            }

            $this->respondSuccess([
                'conversation_id' => $conversationId
            ], 'Group conversation created successfully', 201);
        } catch (\Exception $e) {
            $this->db->rollBack();
            Util::log($e->getMessage(), [
                'user_id' => $user['user_id'],
                'endpoint' => '/api/chat/create-group'
            ]);
            $this->respondError(500, 'Failed to create group conversation');
        }
    }

    /**
     * GET /api/chat/find-conversation - Find direct conversation by username
     */
    public function findConversation()
    {
        $user = $this->authenticate();
        $username = $_GET['username'] ?? '';

        if (empty($username)) {
            $this->respondError(400, 'Username is required');
        }

        try {
            $conversation = ConversationModel::getDirectConversationByUsername($user['user_id'], $username);

            if (!$conversation) {
                $this->respondError(404, 'Conversation not found');
            }

            $this->respondSuccess($conversation, 'Conversation retrieved successfully');
        } catch (\Exception $e) {
            Util::log($e->getMessage(), [
                'user_id' => $user['user_id'],
                'endpoint' => '/api/chat/find-conversation'
            ]);
            $this->respondError(500, 'Failed to find conversation');
        }
    }

    /**
     * POST /api/chat/add-reaction - Add reaction to message
     */
    public function addReaction()
    {
        $user = $this->authenticate();
        $data = $this->getJsonInput();

        $this->validateRequired($data, ['message_id', 'emoji']);

        try {
            $result = MessageModel::toggleReaction($data['message_id'], $user['user_id'], $data['emoji']);

            if ($result) {
                $this->respondSuccess(null, 'Reaction added successfully');
            } else {
                $this->respondSuccess(null, 'Reaction removed successfully');
            }
        } catch (\Exception $e) {
            Util::log($e->getMessage(), [
                'user_id' => $user['user_id'],
                'endpoint' => '/api/chat/add-reaction'
            ]);
            $this->respondError(500, 'Failed to add reaction');
        }
    }

    /**
     * POST /api/chat/mark-as-read - Mark message as read
     */
    public function markAsRead()
    {
        $user = $this->authenticate();
        $data = $this->getJsonInput();

        $this->validateRequired($data, ['message_id']);

        try {
            MessageModel::markAsRead($data['message_id'], $user['user_id']);

            $this->respondSuccess(null, 'Message marked as read');
        } catch (\Exception $e) {
            Util::log($e->getMessage(), [
                'user_id' => $user['user_id'],
                'endpoint' => '/api/chat/mark-as-read'
            ]);
            $this->respondError(500, 'Failed to mark message as read');
        }
    }

    /**
     * GET /api/chat/search-messages - Search messages
     */
    public function searchMessages()
    {
        $user = $this->authenticate();
        $query = $_GET['q'] ?? '';
        $conversationId = $_GET['conversation_id'] ?? null;
        $limit = min((int)($_GET['limit'] ?? 20), 100);
        $lastId = isset($_GET['last_id']) ? (int) $_GET['last_id'] : null;
        $lastTimestamp = $_GET['last_timestamp'] ?? null;

        if (empty($query)) {
            $this->respondError(400, 'Search query is required');
        }

        try {
            if ($conversationId) {
                $messages = MessageModel::searchConversationMessagesCursor($user['user_id'], $conversationId, $query, $limit, $lastId, $lastTimestamp);
            } else {
                $messages = MessageModel::searchUserMessagesCursor($user['user_id'], $query, $limit, $lastId, $lastTimestamp);
            }

            $this->respondCursor($messages, $limit, 'Messages found successfully');
        } catch (\Exception $e) {
            Util::log($e->getMessage(), [
                'user_id' => $user['user_id'],
                'endpoint' => '/api/chat/search-messages'
            ]);
            $this->respondError(500, 'Failed to search messages');
        }
    }

    /**
     * GET /api/chat/unread-count - Get unread message count
     */
    public function unreadCount()
    {
        $user = $this->authenticate();

        try {
            $totalUnread = ConversationModel::getTotalUnreadCount($user['user_id']);

            $this->respondSuccess([
                'unread_count' => $totalUnread
            ], 'Unread count retrieved successfully');
        } catch (\Exception $e) {
            Util::log($e->getMessage(), [
                'user_id' => $user['user_id'],
                'endpoint' => '/api/chat/unread-count'
            ]);
            $this->respondError(500, 'Failed to get unread count');
        }
    }

    /**
     * GET /api/chat/typing-status - Get typing status for conversation
     */
    public function typingStatus()
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
                'endpoint' => '/api/chat/typing-status'
            ]);
            $this->respondError(500, 'Failed to get typing status');
        }
    }

    /**
     * POST /api/chat/set-typing - Set typing status
     */
    public function setTyping()
    {
        $user = $this->authenticate();
        $data = $this->getJsonInput();

        $this->validateRequired($data, ['conversation_id', 'is_typing']);

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
                'endpoint' => '/api/chat/set-typing'
            ]);
            $this->respondError(500, 'Failed to update typing status');
        }
    }

    /**
     * GET /api/chat/online-users - Get online users
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
                'endpoint' => '/api/chat/online-users'
            ]);
            $this->respondError(500, 'Failed to get online users');
        }
    }
}
