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
        $search = $_GET['search'] ?? '';
        $limit = min((int)($_GET['limit'] ?? 50), 100);
        $lastId = isset($_GET['last_id']) ? (int) $_GET['last_id'] : null;
        $lastTimestamp = $_GET['last_timestamp'] ?? null;

        if (!$conversationId) {
            $this->respondError(400, 'Conversation ID is required');
        }

        // Check if user is participant
        if (!ConversationParticipantModel::isParticipant($conversationId, $user['user_id'])) {
            $this->respondError(403, 'You are not a participant in this conversation');
        }

        try {
            $messages = MessageModel::getConversationMessagesWithDetails($conversationId, $limit, $lastId, $lastTimestamp, $search);

            $this->respondCursor($messages, $limit, 'Messages retrieved successfully');
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

        $this->validateRequired($data, ['conversation_id', 'content']);

        // Check if user is participant
        if (!ConversationParticipantModel::isParticipant($data['conversation_id'], $user['user_id'])) {
            $this->respondError(403, 'You are not a participant in this conversation');
        }

        try {
            $this->db->beginTransaction();

            // Create message
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

            // Handle file attachments if provided
            if (!empty($data['attachments']) && is_array($data['attachments'])) {
                foreach ($data['attachments'] as $attachment) {
                    if (isset($attachment['file_url']) && isset($attachment['file_type'])) {
                        MessageAttachmentModel::addAttachment(
                            $messageId,
                            $attachment['file_url'],
                            $attachment['file_type'],
                            $attachment['file_size'] ?? null,
                            $attachment['original_name'] ?? null
                        );
                    }
                }
            }

            $this->db->commit();

            // Get the created message with details
            $message = MessageModel::getMessageWithDetails($messageId);

            $this->respondSuccess($message, 'Message sent successfully', 201);
        } catch (\Exception $e) {
            $this->db->rollBack();
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

        $this->validateRequired($data, ['content']);

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
        if (!$id) {
            $this->respondError(400, 'Message ID is required');
        }

        $user = $this->authenticate();
        $data = $this->getJsonInput();

        $this->validateRequired($data, ['emoji']);

        try {
            // Check if message exists and user has access
            $message = $this->db->query(
                "SELECT conversation_id FROM messages WHERE id = ?",
                [$id]
            )->fetchArray();

            if (!$message) {
                $this->respondError(404, 'Message not found');
            }

            if (!ConversationParticipantModel::isParticipant($message['conversation_id'], $user['user_id'])) {
                $this->respondError(403, 'You do not have access to this message');
            }

            $result = MessageModel::toggleReaction($id, $user['user_id'], $data['emoji']);

            $action = $result ? 'added' : 'removed';
            $this->respondSuccess([
                'action' => $action,
                'message_id' => $id,
                'emoji' => $data['emoji']
            ], "Reaction {$action} successfully");
        } catch (\Exception $e) {
            $this->respondError(500, 'Failed to process reaction');
        }
    }

    /**
     * POST /api/message/{id}/mark-read - Mark message as read
     */
    public function markAsRead($id = null)
    {
        if (!$id) {
            $this->respondError(400, 'Message ID is required');
        }

        $user = $this->authenticate();

        try {
            // Check if message exists and user has access
            $message = $this->db->query(
                "SELECT conversation_id FROM messages WHERE id = ?",
                [$id]
            )->fetchArray();

            if (!$message) {
                $this->respondError(404, 'Message not found');
            }

            if (!ConversationParticipantModel::isParticipant($message['conversation_id'], $user['user_id'])) {
                $this->respondError(403, 'You do not have access to this message');
            }

            MessageModel::markAsRead($id, $user['user_id']);

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
        $limit = min((int)($_GET['limit'] ?? 50), 100);
        $lastId = isset($_GET['last_id']) ? (int) $_GET['last_id'] : null;
        $lastTimestamp = $_GET['last_timestamp'] ?? null;

        if (empty($query)) {
            $this->respondError(400, 'Search query is required');
        }

        try {
            $messages = MessageModel::searchUserMessagesCursor($user['user_id'], $query, $limit, $lastId, $lastTimestamp);

            $this->respondCursor($messages, $limit, 'Messages found successfully');
        } catch (\Exception $e) {
            $this->respondError(500, 'Failed to search messages');
        }
    }
}
