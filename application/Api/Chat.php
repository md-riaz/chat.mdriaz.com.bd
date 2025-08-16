<?php

namespace App\Api;

use App\Api\ApiController;
use App\Api\Models\ConversationModel;
use App\Api\Models\ConversationParticipantModel;
use App\Api\Models\MessageModel;
use App\Api\Models\UserModel;

class Chat extends ApiController
{
    /**
     * GET /api/chat/conversations - Get user's conversations
     */
    public function conversations()
    {
        $user = $this->authenticate();
        if (isset($user["status_code"])) {
            return $user;
        }
        $page = (int)($_GET['page'] ?? 1);
        $perPage = min((int)($_GET['per_page'] ?? 20), 100);
        $offset = ($page - 1) * $perPage;

        try {
            $conversations = ConversationModel::getUserConversations($user['user_id'], $perPage, $offset);

            return $this->respondSuccess($conversations, 'Conversations retrieved successfully');
        } catch (\Exception $e) {
            return $this->respondError(500, 'Failed to retrieve conversations');
        }
    }

    /**
     * POST /api/chat/send-message - Send a message
     */
    public function sendMessage()
    {
        $user = $this->authenticate();
        if (isset($user["status_code"])) {
            return $user;
        }
        $data = $this->getJsonInput();

        if ($error = $this->validateRequired($data, ['conversation_id', 'content'])) {
            return $error;
        }

        if (!ConversationParticipantModel::isParticipant($data['conversation_id'], $user['user_id'])) {
            return $this->respondError(403, 'User is not a participant of this conversation');
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

            return $this->respondSuccess([
                'message_id' => $messageId
            ], 'Message sent successfully', 201);
        } catch (\Exception $e) {
            return $this->respondError(500, 'Failed to send message');
        }
    }

    /**
     * GET /api/chat/messages - Get messages for a conversation
     */
    public function messages()
    {
        $user = $this->authenticate();
        if (isset($user["status_code"])) {
            return $user;
        }
        $conversationId = $_GET['conversation_id'] ?? null;
        $limit = min((int)($_GET['limit'] ?? 50), 100);
        $offset = (int)($_GET['offset'] ?? 0);

        if (!$conversationId) {
            return $this->respondError(400, 'Conversation ID is required');
        }

        if (!ConversationParticipantModel::isParticipant($conversationId, $user['user_id'])) {
            return $this->respondError(403, 'User is not a participant of this conversation');
        }

        try {
            $messages = MessageModel::getConversationMessagesWithDetails($conversationId, $limit, $offset);

            return $this->respondSuccess($messages, 'Messages retrieved successfully');
        } catch (\Exception $e) {
            return $this->respondError(500, 'Failed to retrieve messages');
        }
    }

    /**
     * POST /api/chat/create-conversation - Create a new conversation
     */
    public function createConversation()
    {
        $user = $this->authenticate();
        if (isset($user["status_code"])) {
            return $user;
        }
        $data = $this->getJsonInput();

        if ($error = $this->validateRequired($data, ['type'])) {
            return $error;
        }

        // For direct conversations, validate participant
        if ($data['type'] === 'direct') {
            if ($error = $this->validateRequired($data, ['participant_id'])) {
                return $error;
            }

            if ($data['participant_id'] == $user['user_id']) {
                return $this->respondError(400, 'Cannot create conversation with yourself');
            }

            // Check if direct conversation already exists
            $existing = ConversationModel::getDirectConversation($user['user_id'], $data['participant_id']);
            if ($existing) {
                return $this->respondSuccess($existing, 'Direct conversation already exists');
            }
        }

        // For group conversations, validate name and participants
        if ($data['type'] === 'group') {
            if ($error = $this->validateRequired($data, ['name', 'participant_ids'])) {
                return $error;
            }

            if (!is_array($data['participant_ids']) || count($data['participant_ids']) < 1) {
                return $this->respondError(400, 'At least 1 participant is required for group conversations');
            }

            if (in_array($user['user_id'], $data['participant_ids'])) {
                return $this->respondError(400, 'You are automatically added to the conversation');
            }
        }

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

            return $this->respondSuccess([
                'conversation_id' => $conversationId
            ], 'Conversation created successfully', 201);
        } catch (\Exception $e) {
            $this->db->rollBack();
            return $this->respondError(500, 'Failed to create conversation');
        }
    }

    /**
     * POST /api/chat/add-reaction - Add reaction to message
     */
    public function addReaction()
    {
        $user = $this->authenticate();
        if (isset($user["status_code"])) {
            return $user;
        }
        $data = $this->getJsonInput();

        if ($error = $this->validateRequired($data, ['message_id', 'emoji'])) {
            return $error;
        }

        try {
            $result = MessageModel::toggleReaction($data['message_id'], $user['user_id'], $data['emoji']);

            if ($result) {
                return $this->respondSuccess(null, 'Reaction added successfully');
            } else {
                return $this->respondSuccess(null, 'Reaction removed successfully');
            }
        } catch (\Exception $e) {
            return $this->respondError(500, 'Failed to add reaction');
        }
    }

    /**
     * POST /api/chat/mark-as-read - Mark message as read
     */
    public function markAsRead()
    {
        $user = $this->authenticate();
        if (isset($user["status_code"])) {
            return $user;
        }
        $data = $this->getJsonInput();

        if ($error = $this->validateRequired($data, ['message_id'])) {
            return $error;
        }

        try {
            MessageModel::markAsRead($data['message_id'], $user['user_id']);

            return $this->respondSuccess(null, 'Message marked as read');
        } catch (\Exception $e) {
            return $this->respondError(500, 'Failed to mark message as read');
        }
    }

    /**
     * GET /api/chat/search-messages - Search messages
     */
    public function searchMessages()
    {
        $user = $this->authenticate();
        if (isset($user["status_code"])) {
            return $user;
        }
        $query = $_GET['q'] ?? '';
        $conversationId = $_GET['conversation_id'] ?? null;
        $limit = min((int)($_GET['limit'] ?? 20), 100);
        $offset = (int)($_GET['offset'] ?? 0);

        if (empty($query)) {
            return $this->respondError(400, 'Search query is required');
        }

        try {
            if ($conversationId) {
                // Search within specific conversation - use Message model method
                $messages = MessageModel::searchConversationMessages($user['user_id'], $conversationId, $query, $limit, $offset);
            } else {
                // Search across all user's conversations
                $messages = MessageModel::searchUserMessages($user['user_id'], $query, $limit, $offset);
            }

            return $this->respondSuccess($messages, 'Messages found successfully');
        } catch (\Exception $e) {
            return $this->respondError(500, 'Failed to search messages');
        }
    }

    /**
     * GET /api/chat/unread-count - Get unread message count
     */
    public function unreadCount()
    {
        $user = $this->authenticate();
        if (isset($user["status_code"])) {
            return $user;
        }

        try {
            $totalUnread = ConversationModel::getTotalUnreadCount($user['user_id']);

            return $this->respondSuccess([
                'unread_count' => $totalUnread
            ], 'Unread count retrieved successfully');
        } catch (\Exception $e) {
            return $this->respondError(500, 'Failed to get unread count');
        }
    }

    /**
     * GET /api/chat/typing-status - Get typing status for conversation
     */
    public function typingStatus()
    {
        $user = $this->authenticate();
        if (isset($user["status_code"])) {
            return $user;
        }
        $conversationId = $_GET['conversation_id'] ?? null;

        if (!$conversationId) {
            return $this->respondError(400, 'Conversation ID is required');
        }

        try {
            // Get typing users (this would typically be cached/stored in Redis)
            // For now, return empty array
            $typingUsers = [];

            return $this->respondSuccess([
                'typing_users' => $typingUsers
            ], 'Typing status retrieved successfully');
        } catch (\Exception $e) {
            return $this->respondError(500, 'Failed to get typing status');
        }
    }

    /**
     * POST /api/chat/set-typing - Set typing status
     */
    public function setTyping()
    {
        $user = $this->authenticate();
        if (isset($user["status_code"])) {
            return $user;
        }
        $data = $this->getJsonInput();

        if ($error = $this->validateRequired($data, ['conversation_id', 'is_typing'])) {
            return $error;
        }

        try {
            // Store typing status (this would typically be cached/stored in Redis)
            // For now, just acknowledge the request

            return $this->respondSuccess(null, 'Typing status updated');
        } catch (\Exception $e) {
            return $this->respondError(500, 'Failed to update typing status');
        }
    }

    /**
     * GET /api/chat/online-users - Get online users
     */
    public function onlineUsers()
    {
        $user = $this->authenticate();
        if (isset($user["status_code"])) {
            return $user;
        }

        try {
            // Get online users (this would typically check active sessions/WebSocket connections)
            // For now, return empty array
            $onlineUsers = [];

            return $this->respondSuccess([
                'online_users' => $onlineUsers
            ], 'Online users retrieved successfully');
        } catch (\Exception $e) {
            return $this->respondError(500, 'Failed to get online users');
        }
    }
}
