<?php

namespace App\Api\Models;

use Framework\Core\Model;

class MessageModel extends Model
{
    protected static string $table = 'messages';
    protected array $fillable = ['conversation_id', 'sender_id', 'content', 'message_type', 'parent_id', 'created_at'];
    protected bool $timestamps = false;

    public function sender(): ?UserModel
    {
        return $this->belongsTo(UserModel::class, 'sender_id');
    }

    public function attachments(): array
    {
        return $this->hasMany(MessageAttachmentModel::class, 'message_id');
    }

    public function conversation(): ?ConversationModel
    {
        return $this->belongsTo(ConversationModel::class, 'conversation_id');
    }

    public static function createMessage($conversationId, $senderId, $content, $messageType = 'text', $parentId = null)
    {
        $message = new static([
            'conversation_id' => $conversationId,
            'sender_id'       => $senderId,
            'content'         => $content,
            'message_type'    => $messageType,
            'parent_id'       => $parentId,
        ]);
        $message->save();
        return $message->id;
    }

    /**
     * Get messages with pagination using dataQuery
     */
    public static function getMessagesPaginated($query, $params = [])
    {
        $db = static::db();
        return $db->dataQuery($query, $params);
    }

    /**
     * Get message with sender details
     */
    public static function getMessageWithDetails($messageId)
    {
        $message = static::find((int) $messageId);
        if (!$message) {
            return null;
        }

        // Load desired relations
        $message->sender;
        $message->attachments;

        return $message->toArray();
    }

    /**
     * Toggle reaction on a message
     */
    public static function toggleReaction($messageId, $userId, $emoji)
    {
        $db = static::db();

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
        $db = static::db();

        return $db->query(
            "SELECT m.*, u.name as sender_name, u.avatar_url as sender_avatar, c.title as conversation_title"
            . " FROM messages m"
            . " JOIN users u ON m.sender_id = u.id"
            . " JOIN conversations c ON m.conversation_id = c.id"
            . " JOIN conversation_participants cp ON c.id = cp.conversation_id"
            . " WHERE cp.user_id = ? AND m.content LIKE ?"
            . " ORDER BY m.created_at DESC"
            . " LIMIT ? OFFSET ?",
            [$userId, "%{$searchQuery}%", $limit, $offset]
        )->fetchAll();
    }

    /**
     * Search user's messages within a specific conversation
     */
    public static function searchConversationMessages($userId, $conversationId, $searchQuery, $limit, $offset)
    {
        $db = static::db();

        return $db->query(
            "SELECT m.*, u.name as sender_name, u.avatar_url as sender_avatar, c.title as conversation_title"
            . " FROM messages m"
            . " JOIN users u ON m.sender_id = u.id"
            . " JOIN conversations c ON m.conversation_id = c.id"
            . " JOIN conversation_participants cp ON c.id = cp.conversation_id"
            . " WHERE cp.user_id = ? AND m.conversation_id = ? AND m.content LIKE ?"
            . " ORDER BY m.created_at DESC"
            . " LIMIT ? OFFSET ?",
            [$userId, $conversationId, "%{$searchQuery}%", $limit, $offset]
        )->fetchAll();
    }

    /**
     * Get count of search results for user
     */
    public static function getUserSearchCount($userId, $searchQuery)
    {
        $db = static::db();

        $result = $db->query(
            "SELECT COUNT(*) as count"
            . " FROM messages m"
            . " JOIN conversation_participants cp ON m.conversation_id = cp.conversation_id"
            . " WHERE cp.user_id = ? AND m.content LIKE ?",
            [$userId, "%{$searchQuery}%"]
        )->fetchArray();

        return $result['count'];
    }

    /**
     * Get total message count for a conversation
     */
    public static function getConversationMessageCount($conversationId)
    {
        $db = static::db();

        $result = $db->query(
            "SELECT COUNT(*) as count FROM messages WHERE conversation_id = ?",
            [$conversationId]
        )->fetchArray();

        return $result['count'];
    }

    /**
     * Mark message as read by user
     */
    public static function markAsRead($messageId, $userId)
    {
        $db = static::db();

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
        $db = static::db();

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
        $db = static::db();

        $reactionsQuery = DB_TYPE === 'pgsql'
            ? "(SELECT STRING_AGG(DISTINCT emoji, ',') FROM message_reactions WHERE message_id = m.id)"
            : "(SELECT GROUP_CONCAT(DISTINCT emoji) FROM message_reactions WHERE message_id = m.id)";

        return $db->query(
            "SELECT m.*, u.name as sender_name, u.username as sender_username, u.avatar_url as sender_avatar,"
            . " (SELECT COUNT(*) FROM message_reactions WHERE message_id = m.id) as reaction_count,"
            . " {$reactionsQuery} as reactions"
            . " FROM messages m"
            . " JOIN users u ON m.sender_id = u.id"
            . " WHERE m.conversation_id = ?"
            . " ORDER BY m.created_at ASC"
            . " LIMIT ? OFFSET ?",
            [$conversationId, $limit, $offset]
        )->fetchAll();
    }
}

