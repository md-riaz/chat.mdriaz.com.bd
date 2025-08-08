<?php

namespace App\Api\Models;

use Framework\Core\Database;

class MessageModel
{
    private static $db;

    public static function initDB()
    {
        if (!self::$db) {
            self::$db = new Database();
        }
        return self::$db;
    }

    public static function createMessage($conversationId, $senderId, $content, $messageType = 'text', $parentId = null)
    {
        $db = self::initDB();

        $result = $db->query(
            "INSERT INTO messages (conversation_id, sender_id, content, message_type, parent_id, created_at) 
             VALUES (?, ?, ?, ?, ?, NOW())",
            [$conversationId, $senderId, $content, $messageType, $parentId]
        );

        return $db->lastInsertId();
    }

    /**
     * Get messages with pagination using dataQuery
     */
    public static function getMessagesPaginated($query, $params = [])
    {
        $db = self::initDB();
        return $db->dataQuery($query, $params);
    }

    /**
     * Get message with sender details
     */
    public static function getMessageWithDetails($messageId)
    {
        $db = self::initDB();

        return $db->query(
            "SELECT m.*, u.name as sender_name, u.username as sender_username, u.avatar_url as sender_avatar
             FROM messages m
             JOIN users u ON m.sender_id = u.id
             WHERE m.id = ?",
            [$messageId]
        )->fetchArray();
    }

    /**
     * Toggle reaction on a message
     */
    public static function toggleReaction($messageId, $userId, $emoji)
    {
        $db = self::initDB();

        // Check if reaction already exists
        $existing = $db->query(
            "SELECT id FROM message_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?",
            [$messageId, $userId, $emoji]
        )->fetchArray();

        if ($existing) {
            // Remove reaction
            $db->query(
                "DELETE FROM message_reactions WHERE id = ?",
                [$existing['id']]
            );
            return false; // Removed
        } else {
            // Add reaction
            $db->query(
                "INSERT INTO message_reactions (message_id, user_id, emoji, created_at) VALUES (?, ?, ?, NOW())",
                [$messageId, $userId, $emoji]
            );
            return true; // Added
        }
    }

    /**
     * Search user's messages across all conversations
     */
    public static function searchUserMessages($userId, $searchQuery, $limit, $offset)
    {
        $db = self::initDB();

        return $db->query(
            "SELECT m.*, u.name as sender_name, u.avatar_url as sender_avatar, c.title as conversation_title
             FROM messages m
             JOIN users u ON m.sender_id = u.id
             JOIN conversations c ON m.conversation_id = c.id
             JOIN conversation_participants cp ON c.id = cp.conversation_id
             WHERE cp.user_id = ? AND m.content LIKE ?
             ORDER BY m.created_at DESC
             LIMIT ? OFFSET ?",
            [$userId, "%{$searchQuery}%", $limit, $offset]
        )->fetchAll();
    }

    /**
     * Get count of search results for user
     */
    public static function getUserSearchCount($userId, $searchQuery)
    {
        $db = self::initDB();

        $result = $db->query(
            "SELECT COUNT(*) as count
             FROM messages m
             JOIN conversation_participants cp ON m.conversation_id = cp.conversation_id
             WHERE cp.user_id = ? AND m.content LIKE ?",
            [$userId, "%{$searchQuery}%"]
        )->fetchArray();

        return $result['count'];
    }

    /**
     * Mark message as read by user
     */
    public static function markAsRead($messageId, $userId)
    {
        $db = self::initDB();

        // Check if already marked as read
        $existing = $db->query(
            "SELECT id FROM message_events WHERE message_id = ? AND user_id = ? AND event_type = 'read'",
            [$messageId, $userId]
        )->fetchArray();

        if (!$existing) {
            $db->query(
                "INSERT INTO message_events (message_id, user_id, event_type, created_at) VALUES (?, ?, 'read', NOW())",
                [$messageId, $userId]
            );
        }
    }

    /**
     * Add mention to a message
     */
    public static function addMention($messageId, $mentionedUserId)
    {
        $db = self::initDB();

        return $db->query(
            "INSERT INTO message_mentions (message_id, mentioned_user_id) VALUES (?, ?)",
            [$messageId, $mentionedUserId]
        );
    }

    /**
     * Get messages for a conversation with details
     */
    public static function getConversationMessagesWithDetails($conversationId, $limit = 50, $offset = 0)
    {
        $db = self::initDB();

        return $db->query(
            "SELECT m.*, u.name as sender_name, u.username as sender_username, u.avatar_url as sender_avatar,
                    (SELECT COUNT(*) FROM message_reactions WHERE message_id = m.id) as reaction_count,
                    (SELECT GROUP_CONCAT(DISTINCT emoji) FROM message_reactions WHERE message_id = m.id) as reactions
             FROM messages m
             JOIN users u ON m.sender_id = u.id
             WHERE m.conversation_id = ?
             ORDER BY m.created_at ASC
             LIMIT ? OFFSET ?",
            [$conversationId, $limit, $offset]
        )->fetchAll();
    }
}
