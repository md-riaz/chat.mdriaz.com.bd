<?php

namespace App\Api\Models;

use Framework\Core\Model;
use Framework\Core\Collection;
use App\Api\Models\MessageModel;
use App\Api\Models\UserModel;

class ConversationModel extends Model
{
    protected static string $table = 'conversations';
    protected array $fillable = ['title', 'is_group', 'created_by', 'created_at'];
    protected bool $timestamps = false;

    public function messages(): Collection
    {
        return $this->hasMany(MessageModel::class, 'conversation_id');
    }

    public function participants(): Collection
    {
        // Join table: conversation_participants (conversation_id ↔ user_id)
        // Pivot fields: role, joined_at, last_read_message_id
        return $this->belongsToMany(UserModel::class, [
            'pivotTable'      => 'conversation_participants',
            'foreignPivotKey' => 'conversation_id',
            'relatedPivotKey' => 'user_id',
            'withPivot'       => true,
        ]);
    }

    public function creator(): ?UserModel
    {
        return $this->belongsTo(UserModel::class, 'created_by');
    }

    /**
     * Create a new conversation
     */
    public static function createConversation($title = null, $isGroup = false, $createdBy = null)
    {
        $conversation = new static([
            'title'      => $title,
            'is_group'   => $isGroup ? 1 : 0,
            'created_by' => $createdBy,
        ]);
        $conversation->save();

        return $conversation->id;
    }

    /**
     * Get conversation by ID
     */
    public static function getConversationById($conversationId)
    {
        $conversation = static::find((int) $conversationId);
        return $conversation?->toArray();
    }

    /**
     * Get user's conversations
     */
    public static function getUserConversations($userId, $limit = 50, $offset = 0)
    {
        $db = static::db();

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
                ORDER BY last_message_time IS NULL, last_message_time DESC
                LIMIT ? OFFSET ?";

        return $db->query($sql, [$userId, $limit, $offset])->fetchAll();
    }

    /**
     * Update conversation
     */
    public static function updateConversation($conversationId, $data)
    {
        $conversation = static::find((int) $conversationId);
        if (!$conversation) {
            return 0;
        }
        $conversation->fill($data);
        $conversation->save();
        return 1;
    }

    /**
     * Delete conversation
     */
    public static function deleteConversation($conversationId)
    {
        $db = static::db();

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
        return ConversationParticipantModel::first([
            'conversation_id' => $conversationId,
            'user_id'        => $userId,
        ]) !== null;
    }

    /**
     * Get conversation participants
     */
    public static function getConversationParticipants($conversationId)
    {
        $conversation = static::find((int) $conversationId);
        if (!$conversation) {
            return [];
        }

        $participants = $conversation->participants()->toArray();
        $results = [];
        foreach ($participants as $user) {
            $pivot = $user['_pivot'] ?? [];
            $user['role'] = $pivot['role'] ?? null;
            $user['joined_at'] = $pivot['joined_at'] ?? null;
            $user['last_read_message_id'] = $pivot['last_read_message_id'] ?? null;
            unset($user['_pivot']);
            $results[] = $user;
        }
        return $results;
    }

    /**
     * Get user's conversations with pagination
     */
    public static function getUserConversationsPaginated($userId, $limit = 20, $lastId = null, $lastTimestamp = null)
    {
        $db = static::db();

        $conditions = "WHERE cp.user_id = ?";
        $params = [$userId];

        if ($lastTimestamp !== null) {
            $conditions .= " AND (c.created_at < ? OR (c.created_at = ? AND c.id < ?))";
            $params[] = $lastTimestamp;
            $params[] = $lastTimestamp;
            $params[] = $lastId ?? 0;
        } elseif ($lastId !== null) {
            $conditions .= " AND c.id < ?";
            $params[] = $lastId;
        }

        $sql = "SELECT c.*,
                       cp.last_read_message_id,
                       cp.role,
                       (SELECT COUNT(*) FROM conversation_participants cp2 WHERE cp2.conversation_id = c.id) as participant_count,
                       (SELECT m.content FROM messages m WHERE m.conversation_id = c.id ORDER BY m.created_at DESC LIMIT 1) as last_message,
                       (SELECT m.created_at FROM messages m WHERE m.conversation_id = c.id ORDER BY m.created_at DESC LIMIT 1) as last_message_time,
                       (SELECT u.name FROM messages m JOIN users u ON m.sender_id = u.id WHERE m.conversation_id = c.id ORDER BY m.created_at DESC LIMIT 1) as last_sender_name
                FROM conversations c
                JOIN conversation_participants cp ON c.id = cp.conversation_id
                $conditions
                ORDER BY c.created_at DESC, c.id DESC
                LIMIT ?";

        $params[] = $limit;

        $items = $db->query($sql, $params)->fetchAll();

        // Append unread counts
        foreach ($items as &$item) {
            $item['unread_count'] = static::getUnreadCount($item['id'], $userId);
        }

        return $items;
    }

    /**
     * Get conversation details with participant info
     */
    public static function getConversationDetails($conversationId, $userId)
    {
        $db = static::db();

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
        $db = static::db();

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
        $db = static::db();

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

    /**
     * Get total unread message count across all conversations for user
     */
    public static function getTotalUnreadCount($userId)
    {
        $db = static::db();

        $sql = "SELECT COUNT(*) as count
                FROM messages m
                JOIN conversation_participants cp ON m.conversation_id = cp.conversation_id
                WHERE cp.user_id = ?
                AND (cp.last_read_message_id IS NULL OR m.id > cp.last_read_message_id)
                AND m.sender_id != ?";

        $result = $db->query($sql, [$userId, $userId])->fetchArray();

        return $result['count'];
    }
}
