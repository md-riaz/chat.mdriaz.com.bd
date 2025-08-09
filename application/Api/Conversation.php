<?php

namespace App\Api;

use App\Api\ApiController;
use App\Api\Models\ConversationModel;
use App\Api\Models\ConversationParticipantModel;
use App\Api\Models\MessageModel;
use App\Api\Enum\Status;

class Conversation extends ApiController
{
    /**
     * GET /api/conversation - List user's conversations
     */
    public function index()
    {
        $user = $this->authenticate();

        try {
            // Use dataQuery for automatic pagination
            $query = "SELECT c.*, 
                             cp.last_read_message_id,
                             cp.role,
                             (SELECT COUNT(*) FROM conversation_participants cp2 WHERE cp2.conversation_id = c.id) as participant_count,
                             (SELECT m.content FROM messages m WHERE m.conversation_id = c.id ORDER BY m.created_at DESC LIMIT 1) as last_message,
                             (SELECT m.created_at FROM messages m WHERE m.conversation_id = c.id ORDER BY m.created_at DESC LIMIT 1) as last_message_time,
                             (SELECT u.name FROM messages m JOIN users u ON m.sender_id = u.id WHERE m.conversation_id = c.id ORDER BY m.created_at DESC LIMIT 1) as last_sender_name
                      FROM conversations c
                      JOIN conversation_participants cp ON c.id = cp.conversation_id
                      WHERE cp.user_id = ?
                      ORDER BY last_message_time IS NULL, last_message_time DESC";

            $result = ConversationModel::getUserConversationsPaginated($query, [$user['user_id']]);

            $this->respondPaginated(
                $result['items'],
                $result['item_count'],
                $result['page_number'],
                $result['item_limit'],
                'Conversations retrieved successfully'
            );
        } catch (\Exception $e) {
            $this->respondError(500, 'Failed to retrieve conversations');
        }
    }

    /**
     * POST /api/conversation - Create a new conversation
     */
    public function create()
    {
        $user = $this->authenticate();
        $data = $this->getJsonInput();

        $this->validateRequired($data, ['type']);

        // Validate conversation type
        if (!in_array($data['type'], ['direct', 'group'])) {
            $this->respondError(400, 'Invalid conversation type. Must be "direct" or "group"');
        }

        // For direct conversations, validate participant
        if ($data['type'] === 'direct') {
            $this->validateRequired($data, ['participant_id']);

            if ($data['participant_id'] == $user['user_id']) {
                $this->respondError(400, 'Cannot create conversation with yourself');
            }

            // Check if direct conversation already exists
            $existing = ConversationModel::getDirectConversation($user['user_id'], $data['participant_id']);
            if ($existing) {
                $this->respondSuccess($existing, 'Direct conversation already exists');
                return;
            }
        }

        // For group conversations, validate name and participants
        if ($data['type'] === 'group') {
            $this->validateRequired($data, ['name', 'participant_ids']);

            if (!is_array($data['participant_ids']) || count($data['participant_ids']) < 1) {
                $this->respondError(400, 'At least 1 participant is required for group conversations');
            }

            if (in_array($user['user_id'], $data['participant_ids'])) {
                $this->respondError(400, 'You are automatically added to the conversation');
            }
        }

        try {
            $this->db->beginTransaction();

            // Create conversation
            $conversationId = ConversationModel::createConversation(
                $data['name'] ?? null,
                $data['type'] === 'group',
                $user['user_id']
            );

            // Add creator as admin
            ConversationParticipantModel::addParticipant($conversationId, $user['user_id'], true);

            // Add other participants
            if ($data['type'] === 'direct') {
                ConversationParticipantModel::addParticipant($conversationId, $data['participant_id'], false);
            } else {
                foreach ($data['participant_ids'] as $participantId) {
                    ConversationParticipantModel::addParticipant($conversationId, $participantId, false);
                }
            }

            $this->db->commit();

            // Get the created conversation with details
            $conversation = ConversationModel::getConversationDetails($conversationId, $user['user_id']);

            $this->respondSuccess($conversation, 'Conversation created successfully', 201);
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->respondError(500, 'Failed to create conversation');
        }
    }

    /**
     * GET /api/conversation/{id} - Get conversation details
     */
    public function show($id = null)
    {
        if (!$id) {
            $this->respondError(400, 'Conversation ID is required');
        }

        $user = $this->authenticate();

        // Check if user is participant
        if (!ConversationParticipantModel::isParticipant($id, $user['user_id'])) {
            $this->respondError(403, 'You are not a participant in this conversation');
        }

        try {
            $conversation = ConversationModel::getConversationDetails($id, $user['user_id']);

            if (!$conversation) {
                $this->respondError(404, 'Conversation not found');
            }

            $this->respondSuccess($conversation, 'Conversation retrieved successfully');
        } catch (\Exception $e) {
            $this->respondError(500, 'Failed to retrieve conversation');
        }
    }

    /**
     * PUT /api/conversation/{id} - Update conversation details
     */
    public function update($id = null)
    {
        if (!$id) {
            $this->respondError(400, 'Conversation ID is required');
        }

        $user = $this->authenticate();
        $data = $this->getJsonInput();

        // Check if user is admin
        if (!ConversationParticipantModel::isAdmin($id, $user['user_id'])) {
            $this->respondError(403, 'Only admins can update conversation details');
        }

        $allowedFields = ['title'];
        $updateFields = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateFields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($updateFields)) {
            $this->respondError(400, 'No valid fields to update');
        }

        $updateFields[] = "updated_at = NOW()";
        $params[] = $id;

        try {
            $this->db->query(
                "UPDATE conversations SET " . implode(', ', $updateFields) . " WHERE id = ?",
                $params
            );

            $conversation = ConversationModel::getConversationDetails($id, $user['user_id']);

            $this->respondSuccess($conversation, 'Conversation updated successfully');
        } catch (\Exception $e) {
            $this->respondError(500, 'Failed to update conversation');
        }
    }

    /**
     * DELETE /api/conversation/{id} - Delete conversation (admin only)
     */
    public function delete($id = null)
    {
        if (!$id) {
            $this->respondError(400, 'Conversation ID is required');
        }

        $user = $this->authenticate();

        // Check if user is admin
        if (!ConversationParticipantModel::isAdmin($id, $user['user_id'])) {
            $this->respondError(403, 'Only admins can delete conversations');
        }

        try {
            $this->db->beginTransaction();

            // Soft delete conversation (assuming we add a status column later)
            $this->db->query(
                "UPDATE conversations SET updated_at = NOW() WHERE id = ?",
                [$id]
            );

            $this->db->commit();

            $this->respondSuccess(null, 'Conversation deleted successfully');
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->respondError(500, 'Failed to delete conversation');
        }
    }

    /**
     * GET /api/conversation/{id}/participants - Get conversation participants
     */
    public function participants($id = null)
    {
        if (!$id) {
            $this->respondError(400, 'Conversation ID is required');
        }

        $user = $this->authenticate();

        // Check if user is participant
        if (!ConversationParticipantModel::isParticipant($id, $user['user_id'])) {
            $this->respondError(403, 'You are not a participant in this conversation');
        }

        try {
            $participants = ConversationParticipantModel::getParticipants($id);

            $this->respondSuccess($participants, 'Participants retrieved successfully');
        } catch (\Exception $e) {
            $this->respondError(500, 'Failed to retrieve participants');
        }
    }

    /**
     * POST /api/conversation/{id}/add-participants - Add participants to conversation
     */
    public function addParticipants($id = null)
    {
        if (!$id) {
            $this->respondError(400, 'Conversation ID is required');
        }

        $user = $this->authenticate();
        $data = $this->getJsonInput();

        $this->validateRequired($data, ['user_ids']);

        // Check if user is admin
        if (!ConversationParticipantModel::isAdmin($id, $user['user_id'])) {
            $this->respondError(403, 'Only admins can add participants');
        }

        if (!is_array($data['user_ids']) || empty($data['user_ids'])) {
            $this->respondError(400, 'At least one user ID is required');
        }

        try {
            $this->db->beginTransaction();

            $addedUsers = [];
            foreach ($data['user_ids'] as $userId) {
                if (!ConversationParticipantModel::isParticipant($id, $userId)) {
                    ConversationParticipantModel::addParticipant($id, $userId, false);
                    $addedUsers[] = $userId;
                }
            }

            $this->db->commit();

            $this->respondSuccess([
                'added_users' => $addedUsers,
                'total_added' => count($addedUsers)
            ], 'Participants added successfully');
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->respondError(500, 'Failed to add participants');
        }
    }

    /**
     * POST /api/conversation/{id}/remove-participant - Remove participant from conversation
     */
    public function removeParticipant($id = null)
    {
        if (!$id) {
            $this->respondError(400, 'Conversation ID is required');
        }

        $user = $this->authenticate();
        $data = $this->getJsonInput();
        $this->validateRequired($data, ['user_id']);

        $userId = $data['user_id'];

        // Check if current user is admin or removing themselves
        if (!ConversationParticipantModel::isAdmin($id, $user['user_id']) && $user['user_id'] != $userId) {
            $this->respondError(403, 'You can only remove yourself or you must be an admin');
        }

        try {
            $result = ConversationParticipantModel::removeParticipant($id, $userId);

            if ($result) {
                $this->respondSuccess(null, 'Participant removed successfully');
            } else {
                $this->respondError(404, 'Participant not found in conversation');
            }
        } catch (\Exception $e) {
            $this->respondError(500, 'Failed to remove participant');
        }
    }

    /**
     * POST /api/conversation/{id}/mark-read - Mark conversation as read
     */
    public function markAsRead($id = null)
    {
        if (!$id) {
            $this->respondError(400, 'Conversation ID is required');
        }

        $user = $this->authenticate();

        // Check if user is participant
        if (!ConversationParticipantModel::isParticipant($id, $user['user_id'])) {
            $this->respondError(403, 'You are not a participant in this conversation');
        }

        try {
            // Get the latest message in this conversation for the read marker
            $latestMessage = $this->db->query(
                "SELECT id FROM messages WHERE conversation_id = ? ORDER BY created_at DESC LIMIT 1",
                [$id]
            )->fetchArray();

            if ($latestMessage) {
                ConversationParticipantModel::updateLastReadMessage($id, $user['user_id'], $latestMessage['id']);
            }

            $this->respondSuccess(null, 'Conversation marked as read');
        } catch (\Exception $e) {
            $this->respondError(500, 'Failed to mark conversation as read');
        }
    }
}
