<?php

namespace App\Api\Models;

use Framework\Core\Database;

class ConversationParticipantModel
{
    private static $db;

    public static function initDB()
    {
        if (!self::$db) {
            self::$db = new Database();
        }
        return self::$db;
    }

    public static function isParticipant($conversationId, $userId)
    {
        $db = self::initDB();

        $result = $db->query(
            "SELECT COUNT(*) as count FROM conversation_participants WHERE conversation_id = ? AND user_id = ?",
            [$conversationId, $userId]
        )->fetchArray();

        return $result['count'] > 0;
    }

    public static function addParticipant($conversationId, $userId, $isAdmin = false)
    {
        $db = self::initDB();

        // Check if participant already exists
        $existing = $db->query(
            "SELECT id FROM conversation_participants WHERE conversation_id = ? AND user_id = ?",
            [$conversationId, $userId]
        )->fetchArray();

        if ($existing) {
            return false; // Already exists
        }

        $role = $isAdmin ? 'admin' : 'member';

        return $db->query(
            "INSERT INTO conversation_participants (conversation_id, user_id, role, joined_at) VALUES (?, ?, ?, NOW())",
            [$conversationId, $userId, $role]
        );
    }

    public static function addMultipleParticipants($conversationId, $userIds, $isAdmin = false)
    {
        $db = self::initDB();
        $addedCount = 0;

        foreach ($userIds as $userId) {
            if (self::addParticipant($conversationId, $userId, $isAdmin)) {
                $addedCount++;
            }
        }

        return $addedCount;
    }

    public static function removeParticipant($conversationId, $userId)
    {
        $db = self::initDB();

        return $db->query(
            "DELETE FROM conversation_participants WHERE conversation_id = ? AND user_id = ?",
            [$conversationId, $userId]
        );
    }

    public static function updateLastReadMessage($conversationId, $userId, $messageId)
    {
        $db = self::initDB();

        return $db->query(
            "UPDATE conversation_participants SET last_read_message_id = ? WHERE conversation_id = ? AND user_id = ?",
            [$messageId, $conversationId, $userId]
        );
    }

    public static function isAdmin($conversationId, $userId)
    {
        $db = self::initDB();

        $result = $db->query(
            "SELECT role FROM conversation_participants WHERE conversation_id = ? AND user_id = ?",
            [$conversationId, $userId]
        )->fetchArray();

        return $result && $result['role'] === 'admin';
    }

    public static function getParticipants($conversationId)
    {
        $db = self::initDB();

        return $db->query(
            "SELECT cp.*, u.name, u.username, u.email, u.avatar_url
             FROM conversation_participants cp
             JOIN users u ON cp.user_id = u.id
             WHERE cp.conversation_id = ?
             ORDER BY cp.joined_at ASC",
            [$conversationId]
        )->fetchAll();
    }

    public static function getParticipantRole($conversationId, $userId)
    {
        $db = self::initDB();

        $result = $db->query(
            "SELECT role FROM conversation_participants WHERE conversation_id = ? AND user_id = ?",
            [$conversationId, $userId]
        )->fetchArray();

        return $result ? $result['role'] : null;
    }
}
