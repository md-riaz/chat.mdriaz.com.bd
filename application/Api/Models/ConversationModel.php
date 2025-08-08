<?php

namespace App\Api\Models;

use Framework\Core\Database;
use App\Enum\Status;

class ConversationModel
{
    private static $db;

    public static function initDB()
    {
        if (!self::$db) {
            self::$db = new Database();
        }
        return self::$db;
    }

    /**
     * Create a new conversation
     */
    public static function createConversation($title = null, $isGroup = false, $createdBy = null)
    {
        $db = self::initDB();

        $sql = "INSERT INTO conversations (title, is_group, created_by, created_at) VALUES (?, ?, ?, NOW())";
        $result = $db->query($sql, [$title, $isGroup ? 1 : 0, $createdBy]);

        return $result->lastInsertID();
    }

    /**
     * Get conversation by ID
     */
    public static function getConversationById($conversationId)
    {
        $db = self::initDB();

        $sql = "SELECT * FROM conversations WHERE id = ?";
        return $db->query($sql, [$conversationId])->fetchArray();
    }

    /**
     * Get user's conversations
     */
    public static function getUserConversations($userId, $limit = 50, $offset = 0)
    {
        $db = self::initDB();

        $sql = "SELECT c.*, 
                       cp.last_read_message_id,
                       cp.role,
                       (SELECT COUNT(*) FROM conversation_participants cp2 WHERE cp2.conversation_id = c.id) as participant_count,
                       (SELECT m.content FROM messages m WHERE m.conversation_id = c.id ORDER BY m.created_at DESC LIMIT 1) as last_message,
                       (SELECT m.created_at FROM messages m WHERE m.conversation_id = c.id ORDER BY m.created_at DESC LIMIT 1) as last_message_time,
                       (SELECT u.name FROM messages m JOIN users u ON m.sender_id = u.id WHERE m.conversation_id = c.id ORDER BY m.created_at DESC LIMIT 1) as last_sender_name
                FROM conversations c
                JOIN conversation_participants cp ON c.id = cp.conversation_id
                WHERE cp.user_id = ?
                ORDER BY last_message_time DESC NULLS LAST
                LIMIT ? OFFSET ?";

        return $db->query($sql, [$userId, $limit, $offset])->fetchAll();
    }

    /**
     * Update conversation
     */
    public static function updateConversation($conversationId, $data)
    {
        $db = self::initDB();

        $setParts = [];
        $values = [];

        foreach ($data as $key => $value) {
            $setParts[] = "$key = ?";
            $values[] = $value;
        }

        $values[] = $conversationId;

        $sql = "UPDATE conversations SET " . implode(', ', $setParts) . " WHERE id = ?";
        return $db->query($sql, $values)->affectedRows();
    }

    /**
     * Delete conversation
     */
    public static function deleteConversation($conversationId)
    {
        $db = self::initDB();

        try {
            $db->beginTransaction();

            // Delete all messages and related data
            $db->query("DELETE FROM message_reactions WHERE message_id IN (SELECT id FROM messages WHERE conversation_id = ?)", [$conversationId]);
            $db->query("DELETE FROM message_mentions WHERE message_id IN (SELECT id FROM messages WHERE conversation_id = ?)", [$conversationId]);
            $db->query("DELETE FROM message_events WHERE message_id IN (SELECT id FROM messages WHERE conversation_id = ?)", [$conversationId]);
            $db->query("DELETE FROM message_attachments WHERE message_id IN (SELECT id FROM messages WHERE conversation_id = ?)", [$conversationId]);
            $db->query("DELETE FROM messages WHERE conversation_id = ?", [$conversationId]);

            // Delete participants
            $db->query("DELETE FROM conversation_participants WHERE conversation_id = ?", [$conversationId]);

            // Delete conversation
            $db->query("DELETE FROM conversations WHERE id = ?", [$conversationId]);

            $db->commit();
            return true;
        } catch (\Exception $e) {
            $db->rollBack();
            return false;
        }
    }

    /**
     * Check if user is participant in conversation
     */
    public static function isUserParticipant($conversationId, $userId)
    {
        $db = self::initDB();

        $sql = "SELECT COUNT(*) as count FROM conversation_participants WHERE conversation_id = ? AND user_id = ?";
        $result = $db->query($sql, [$conversationId, $userId])->fetchArray();

        return $result['count'] > 0;
    }

    /**
     * Get conversation participants
     */
    public static function getConversationParticipants($conversationId)
    {
        $db = self::initDB();

        $sql = "SELECT cp.*, u.name, u.username, u.avatar_url, u.email
                FROM conversation_participants cp
                JOIN users u ON cp.user_id = u.id
                WHERE cp.conversation_id = ?
                ORDER BY cp.joined_at";

        return $db->query($sql, [$conversationId])->fetchAll();
    }

    /**
     * Get user's conversations with pagination using dataQuery
     */
    public static function getUserConversationsPaginated($query, $params = [])
    {
        $db = self::initDB();
        return $db->dataQuery($query, $params);
    }

    /**
     * Get conversation details with participant info
     */
    public static function getConversationDetails($conversationId, $userId)
    {
        $db = self::initDB();

        $sql = "SELECT c.*, 
                       cp.role as user_role,
                       cp.last_read_message_id,
                       (SELECT COUNT(*) FROM conversation_participants cp2 WHERE cp2.conversation_id = c.id) as participant_count
                FROM conversations c
                JOIN conversation_participants cp ON c.id = cp.conversation_id
                WHERE c.id = ? AND cp.user_id = ?";

        return $db->query($sql, [$conversationId, $userId])->fetchArray();
    }

    /**
     * Check if direct conversation exists between two users
     */
    public static function getDirectConversation($userId1, $userId2)
    {
        $db = self::initDB();

        $sql = "SELECT c.* FROM conversations c
                WHERE c.is_group = 0
                AND c.id IN (
                    SELECT cp1.conversation_id 
                    FROM conversation_participants cp1
                    JOIN conversation_participants cp2 ON cp1.conversation_id = cp2.conversation_id
                    WHERE cp1.user_id = ? AND cp2.user_id = ?
                    AND cp1.conversation_id IN (
                        SELECT conversation_id FROM conversation_participants 
                        GROUP BY conversation_id HAVING COUNT(*) = 2
                    )
                )";

        return $db->query($sql, [$userId1, $userId2])->fetchArray();
    }

    /**
     * Get unread message count for user in conversation
     */
    public static function getUnreadCount($conversationId, $userId)
    {
        $db = self::initDB();

        $sql = "SELECT COUNT(*) as count 
                FROM messages m
                JOIN conversation_participants cp ON m.conversation_id = cp.conversation_id
                WHERE m.conversation_id = ? 
                AND cp.user_id = ?
                AND (cp.last_read_message_id IS NULL OR m.id > cp.last_read_message_id)
                AND m.sender_id != ?";

        $result = $db->query($sql, [$conversationId, $userId, $userId])->fetchArray();

        return $result['count'];
    }
}
