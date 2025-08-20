<?php

namespace App\Api;

use App\Api\ApiController;
use App\Api\Models\MessageModel;
use App\Api\Models\ConversationParticipantModel;
use App\Api\Models\MessageAttachmentModel;
use App\Api\Enum\Status;

class Message extends ApiController
{
    /**
     * GET /api/message - Get messages for a conversation
     */
    public function index()
    {
        $user = $this->authenticate();
        $conversationId = $_GET['conversation_id'] ?? null;
        $limit = min((int)($_GET['limit'] ?? 50), 100);
        $offset = (int)($_GET['offset'] ?? 0);
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
            $messages = MessageModel::getConversationMessagesWithDetails($conversationId, $limit, $offset);
            $total = MessageModel::getConversationMessageCount($conversationId);
            $page = (int) floor($offset / $limit) + 1;

            $this->respondPaginated($messages, $total, $page, $limit, 'Messages retrieved successfully');
        } catch (\Exception $e) {
            $this->respondError(500, 'Failed to retrieve messages');
        }
    }

    /**
     * POST /api/message - Send a new message
     */
    public function create()
    {
        $user = $this->authenticate();
        $data = $this->getJsonInput();

        $this->validate($data, [
            'conversation_id' => 'required',
            'content' => 'required'
        ]);
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
                $redis = \App\Api\Services\RedisService::getInstance();
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
            $this->respondError(500, 'Failed to send message');
        }
    }

    /**
     * GET /api/message/{id} - Get a specific message
     */
    public function show($id = null)
    {
        if (!$id) {
            $this->respondError(400, 'Message ID is required');
        }

        $user = $this->authenticate();

        try {
            $message = MessageModel::getMessageWithDetails($id);

            if (!$message) {
                $this->respondError(404, 'Message not found');
            }

            // Check if user has access to this message
            if (!ConversationParticipantModel::isParticipant($message['conversation_id'], $user['user_id'])) {
                $this->respondError(403, 'You do not have access to this message');
            }

            $this->respondSuccess($message, 'Message retrieved successfully');
        } catch (\Exception $e) {
            $this->respondError(500, 'Failed to retrieve message');
        }
    }

    /**
     * PUT /api/message/{id} - Update a message (edit)
     */
    public function update($id = null)
    {
        if (!$id) {
            $this->respondError(400, 'Message ID is required');
        }

        $user = $this->authenticate();
        $data = $this->getJsonInput();

        $this->validate($data, ['content' => 'required']);

        try {
            // Check if message exists and user is the sender
            $message = $this->db->query(
                "SELECT conversation_id, sender_id FROM messages WHERE id = ?",
                [$id]
            )->fetchArray();

            if (!$message) {
                $this->respondError(404, 'Message not found');
            }

            if ($message['sender_id'] != $user['user_id']) {
                $this->respondError(403, 'You can only edit your own messages');
            }

            // Update message
            $this->db->query(
                "UPDATE messages SET content = ?, updated_at = NOW(), is_edited = 1 WHERE id = ?",
                [$data['content'], $id]
            );

            // Get updated message
            $updatedMessage = MessageModel::getMessageWithDetails($id);

            $this->respondSuccess($updatedMessage, 'Message updated successfully');
        } catch (\Exception $e) {
            $this->respondError(500, 'Failed to update message');
        }
    }

    /**
     * DELETE /api/message/{id} - Delete a message
     */
    public function delete($id = null)
    {
        if (!$id) {
            $this->respondError(400, 'Message ID is required');
        }

        $user = $this->authenticate();

        try {
            // Check if message exists and user is the sender
            $message = $this->db->query(
                "SELECT conversation_id, sender_id FROM messages WHERE id = ?",
                [$id]
            )->fetchArray();

            if (!$message) {
                $this->respondError(404, 'Message not found');
            }

            if ($message['sender_id'] != $user['user_id']) {
                $this->respondError(403, 'You can only delete your own messages');
            }

            // Soft delete message (for now just update timestamp, can add status column later)
            $this->db->query(
                "UPDATE messages SET updated_at = NOW() WHERE id = ?",
                [$id]
            );

            $this->respondSuccess(null, 'Message deleted successfully');
        } catch (\Exception $e) {
            $this->respondError(500, 'Failed to delete message');
        }
    }

    /**
     * POST /api/message/{id}/reaction - Add/remove reaction to a message
     */
    public function reaction($id = null)
    {
        $user = $this->authenticate();
        $data = $this->getJsonInput();

        $this->validate($data, [
            'message_id' => 'required',
            'emoji' => 'required'
        ]);

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
     * POST /api/message/{id}/mark-read - Mark message as read
     */
    public function markAsRead($id = null)
    {
        $user = $this->authenticate();
        $data = $this->getJsonInput();

        $this->validate($data, ['message_id' => 'required']);

        try {
            MessageModel::markAsRead($data['message_id'], $user['user_id']);

            $this->respondSuccess(null, 'Message marked as read');
        } catch (\Exception $e) {
            $this->respondError(500, 'Failed to mark message as read');
        }
    }

    /**
     * POST /api/message/upload - Upload file for message attachment
     */
    public function upload()
    {
        $user = $this->authenticate();

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->respondError(400, 'No valid file uploaded');
        }

        $file = $_FILES['file'];

        // Validate file size (max 50MB)
        $maxSize = 50 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            $this->respondError(400, 'File too large. Maximum size is 50MB');
        }

        // Get file extension and MIME type
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mimeType = $file['type'];

        // Validate file type with both extension and MIME type
        $allowedTypes = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'pdf' => ['application/pdf'],
            'txt' => ['text/plain'],
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xls' => ['application/vnd.ms-excel'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'mp3' => ['audio/mpeg'],
            'wav' => ['audio/wav'],
            'ogg' => ['audio/ogg'],
            'mp4' => ['video/mp4'],
            'webm' => ['video/webm'],
        ];

        if (!isset($allowedTypes[$extension]) || !in_array($mimeType, $allowedTypes[$extension])) {
            $this->respondError(400, 'File type not allowed');
        }

        try {
            // Create upload directory
            $uploadDir = ROOT . 'uploads/messages/' . date('Y/m/d') . '/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Generate unique filename with proper extension
            $filename = uniqid() . '_' . time() . '.' . $extension;
            $filePath = $uploadDir . $filename;

            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                $fileUrl = '/uploads/messages/' . date('Y/m/d') . '/' . $filename;

                // Create unlinked attachment record
                $attachmentId = MessageAttachmentModel::createUnlinkedAttachment(
                    $user['user_id'],
                    $fileUrl,
                    $file['type'],
                    $file['size'],
                    $file['name']
                );

                $this->respondSuccess([
                    'attachment_id' => $attachmentId,
                    'file_url' => $fileUrl,
                    'file_type' => $file['type'],
                    'file_size' => $file['size'],
                    'original_name' => $file['name']
                ], 'File uploaded successfully');
            } else {
                $this->respondError(500, 'Failed to save uploaded file');
            }
        } catch (\Exception $e) {
            $this->respondError(500, 'Failed to upload file');
        }
    }

    /**
     * GET /api/message/search - Search messages across all conversations
     */
    public function search()
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
                $messages = MessageModel::searchConversationMessages($user['user_id'], $conversationId, $query, $limit, $offset);
            } else {
                // Search across all user's conversations
                $messages = MessageModel::searchUserMessages($user['user_id'], $query, $limit, $offset);
            }

            $this->respondSuccess($messages, 'Messages found successfully');
        } catch (\Exception $e) {
            $this->respondError(500, 'Failed to search messages');
        }
    }
}
