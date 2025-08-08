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
        $page = (int)($_GET['page'] ?? 1);
        $perPage = min((int)($_GET['per_page'] ?? 20), 100);

        try {
            $conversations = ConversationModel::getUserConversations($user['user_id'], $page, $perPage);

            $this->respondSuccess($conversations, 'Conversations retrieved successfully');
        } catch (\Exception $e) {
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

            $this->respondSuccess([
                'message_id' => $messageId
            ], 'Message sent successfully', 201);
        } catch (\Exception $e) {
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
        $offset = (int)($_GET['offset'] ?? 0);

        if (!$conversationId) {
            $this->respondError(400, 'Conversation ID is required');
        }

        try {
            $messages = MessageModel::getConversationMessagesWithDetails($conversationId, $limit, $offset);

            $this->respondSuccess($messages, 'Messages retrieved successfully');
        } catch (\Exception $e) {
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

            $this->respondSuccess([
                'conversation_id' => $conversationId
            ], 'Conversation created successfully', 201);
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->respondError(500, 'Failed to create conversation');
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
        $offset = (int)($_GET['offset'] ?? 0);

        if (empty($query)) {
            $this->respondError(400, 'Search query is required');
        }

        try {
            if ($conversationId) {
                // Search within specific conversation - use Message model method
                $messages = MessageModel::searchUserMessages($user['user_id'], $query, $limit, $offset);
            } else {
                // Search across all user's conversations
                $messages = MessageModel::searchUserMessages($user['user_id'], $query, $limit, $offset);
            }

            $this->respondSuccess($messages, 'Messages found successfully');
        } catch (\Exception $e) {
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
            // Get total unread count across all conversations
            $totalUnread = 0;
            $conversations = ConversationModel::getUserConversations($user['user_id'], 100, 0);

            foreach ($conversations as $conversation) {
                $totalUnread += ConversationModel::getUnreadCount($conversation['id'], $user['user_id']);
            }

            $this->respondSuccess([
                'unread_count' => $totalUnread
            ], 'Unread count retrieved successfully');
        } catch (\Exception $e) {
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
            // Get typing users (this would typically be cached/stored in Redis)
            // For now, return empty array
            $typingUsers = [];

            $this->respondSuccess([
                'typing_users' => $typingUsers
            ], 'Typing status retrieved successfully');
        } catch (\Exception $e) {
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
            // Store typing status (this would typically be cached/stored in Redis)
            // For now, just acknowledge the request

            $this->respondSuccess(null, 'Typing status updated');
        } catch (\Exception $e) {
            $this->respondError(500, 'Failed to update typing status');
        }
    }

    /**
     * GET /api/chat/online-users - Get online users
     */
    public function onlineUsers()
    {
        $user = $this->authenticate();

        try {
            // Get online users (this would typically check active sessions/WebSocket connections)
            // For now, return empty array
            $onlineUsers = [];

            $this->respondSuccess([
                'online_users' => $onlineUsers
            ], 'Online users retrieved successfully');
        } catch (\Exception $e) {
            $this->respondError(500, 'Failed to get online users');
        }
    }
}
