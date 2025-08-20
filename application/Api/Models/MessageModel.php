<?php

namespace App\Api\Models;

use Framework\Core\Model;
use App\Api\Models\MessageReactionModel;
use App\Api\Models\MessageEventModel;
use App\Api\Models\MessageMentionModel;
use Framework\Core\Collection;

class MessageModel extends Model
{
    protected static string $table = 'messages';
    protected array $fillable = ['conversation_id', 'sender_id', 'content', 'message_type', 'parent_id', 'created_at'];
    protected bool $timestamps = false;

    public function sender(): ?UserModel
    {
        return $this->belongsTo(UserModel::class, 'sender_id');
    }

    public function attachments(): Collection
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
        $existing = MessageReactionModel::first([
            'message_id' => $messageId,
            'user_id'    => $userId,
            'emoji'      => $emoji,
        ]);

        if ($existing) {
            $existing->delete();
            return false;
        }

        MessageReactionModel::bulkInsert([
            [
                'message_id' => $messageId,
                'user_id'    => $userId,
                'emoji'      => $emoji,
            ],
        ]);

        return true;
    }

    /**
     * Search user's messages across all conversations
     */
    public static function searchUserMessages($userId, $searchQuery, $limit, $offset)
    {
        $joins = [
            ['table' => 'users', 'first' => 'messages.sender_id', 'second' => 'users.id'],
            ['table' => 'conversations', 'first' => 'messages.conversation_id', 'second' => 'conversations.id'],
            ['table' => 'conversation_participants', 'first' => 'conversations.id', 'second' => 'conversation_participants.conversation_id'],
        ];

        $conditions = [
            ['conversation_participants.user_id', '=', $userId],
            ['messages.content', 'LIKE', "%{$searchQuery}%"],
        ];

        $order = [['messages.created_at', 'DESC']];

        $columns = [
            'messages.*',
            'users.name as sender_name',
            'users.avatar_url as sender_avatar',
            'conversations.title as conversation_title',
        ];

        return static::filter($conditions, $joins, $order, $limit, $offset, $columns);
    }

    /**
     * Search user's messages within a specific conversation
     */
    public static function searchConversationMessages($userId, $conversationId, $searchQuery, $limit, $offset)
    {
        $joins = [
            ['table' => 'users', 'first' => 'messages.sender_id', 'second' => 'users.id'],
            ['table' => 'conversations', 'first' => 'messages.conversation_id', 'second' => 'conversations.id'],
            ['table' => 'conversation_participants', 'first' => 'conversations.id', 'second' => 'conversation_participants.conversation_id'],
        ];

        $conditions = [
            ['conversation_participants.user_id', '=', $userId],
            ['messages.conversation_id', '=', $conversationId],
            ['messages.content', 'LIKE', "%{$searchQuery}%"],
        ];

        $order = [['messages.created_at', 'DESC']];

        $columns = [
            'messages.*',
            'users.name as sender_name',
            'users.avatar_url as sender_avatar',
            'conversations.title as conversation_title',
        ];

        return static::filter($conditions, $joins, $order, $limit, $offset, $columns);
    }

    /**
     * Get count of search results for user
     */
    public static function getUserSearchCount($userId, $searchQuery)
    {
        $joins = [
            ['table' => 'conversation_participants', 'first' => 'messages.conversation_id', 'second' => 'conversation_participants.conversation_id'],
        ];

        $conditions = [
            ['conversation_participants.user_id', '=', $userId],
            ['messages.content', 'LIKE', "%{$searchQuery}%"],
        ];

        $result = static::filter($conditions, $joins, [], null, null, 'COUNT(*) as count');

        return (int) ($result[0]['count'] ?? 0);
    }

    /**
     * Get total message count for a conversation
     */
    public static function getConversationMessageCount($conversationId)
    {
        $result = static::filter(
            [
                ['messages.conversation_id', '=', $conversationId],
            ],
            [],
            [],
            null,
            null,
            'COUNT(*) as count'
        );

        return (int) ($result[0]['count'] ?? 0);
    }

    /**
     * Mark message as read by user
     */
    public static function markAsRead($messageId, $userId)
    {
        $existing = MessageEventModel::first([
            'message_id' => $messageId,
            'user_id'    => $userId,
            'event_type' => 'read',
        ]);

        if (!$existing) {
            MessageEventModel::bulkInsert([
                [
                    'message_id' => $messageId,
                    'user_id'    => $userId,
                    'event_type' => 'read',
                ],
            ]);
        }
    }

    /**
     * Add mention to a message
     */
    public static function addMention($messageId, $mentionedUserId)
    {
        MessageMentionModel::bulkInsert([
            [
                'message_id'        => $messageId,
                'mentioned_user_id' => $mentionedUserId,
            ],
        ]);
    }

    /**
     * Get messages for a conversation with details
     */
    public static function getConversationMessagesWithDetails($conversationId, $limit = 50, $lastId = null, $lastTimestamp = null, $search = null)
    {
        $db = static::db();

        $reactionsQuery = DB_TYPE === 'pgsql'
            ? "(SELECT STRING_AGG(DISTINCT emoji, ',') FROM message_reactions WHERE message_id = messages.id)"
            : "(SELECT GROUP_CONCAT(DISTINCT emoji) FROM message_reactions WHERE message_id = messages.id)";

        $where = "messages.conversation_id = ?";
        $params = [$conversationId];

        if ($search !== null && $search !== '') {
            $where .= " AND messages.content LIKE ?";
            $params[] = "%{$search}%";
        }

        if ($lastTimestamp !== null) {
            $where .= " AND (messages.created_at < ? OR (messages.created_at = ? AND messages.id < ?))";
            $params[] = $lastTimestamp;
            $params[] = $lastTimestamp;
            $params[] = $lastId ?? 0;
        } elseif ($lastId !== null) {
            $where .= " AND messages.id < ?";
            $params[] = $lastId;
        }

        $sql = "SELECT messages.*, u.name as sender_name, u.username as sender_username, u.avatar_url as sender_avatar,
                       (SELECT COUNT(*) FROM message_reactions WHERE message_id = messages.id) as reaction_count,
                       $reactionsQuery as reactions
                FROM messages
                JOIN users u ON messages.sender_id = u.id
                WHERE $where
                ORDER BY messages.created_at DESC, messages.id DESC
                LIMIT ?";

        $params[] = $limit;

        return $db->query($sql, $params)->fetchAll();
    }

    public static function searchUserMessagesCursor($userId, $searchQuery, $limit, $lastId = null, $lastTimestamp = null)
    {
        $db = static::db();

        $where = "cp.user_id = ? AND m.content LIKE ?";
        $params = [$userId, "%{$searchQuery}%"];

        if ($lastTimestamp !== null) {
            $where .= " AND (m.created_at < ? OR (m.created_at = ? AND m.id < ?))";
            $params[] = $lastTimestamp;
            $params[] = $lastTimestamp;
            $params[] = $lastId ?? 0;
        } elseif ($lastId !== null) {
            $where .= " AND m.id < ?";
            $params[] = $lastId;
        }

        $sql = "SELECT m.*, u.name as sender_name, u.avatar_url as sender_avatar, c.title as conversation_title
                FROM messages m
                JOIN users u ON m.sender_id = u.id
                JOIN conversations c ON m.conversation_id = c.id
                JOIN conversation_participants cp ON c.id = cp.conversation_id
                WHERE $where
                ORDER BY m.created_at DESC, m.id DESC
                LIMIT ?";

        $params[] = $limit;

        return $db->query($sql, $params)->fetchAll();
    }

    public static function searchConversationMessagesCursor($userId, $conversationId, $searchQuery, $limit, $lastId = null, $lastTimestamp = null)
    {
        $db = static::db();

        $where = "cp.user_id = ? AND m.conversation_id = ? AND m.content LIKE ?";
        $params = [$userId, $conversationId, "%{$searchQuery}%"];

        if ($lastTimestamp !== null) {
            $where .= " AND (m.created_at < ? OR (m.created_at = ? AND m.id < ?))";
            $params[] = $lastTimestamp;
            $params[] = $lastTimestamp;
            $params[] = $lastId ?? 0;
        } elseif ($lastId !== null) {
            $where .= " AND m.id < ?";
            $params[] = $lastId;
        }

        $sql = "SELECT m.*, u.name as sender_name, u.avatar_url as sender_avatar, c.title as conversation_title
                FROM messages m
                JOIN users u ON m.sender_id = u.id
                JOIN conversations c ON m.conversation_id = c.id
                JOIN conversation_participants cp ON c.id = cp.conversation_id
                WHERE $where
                ORDER BY m.created_at DESC, m.id DESC
                LIMIT ?";

        $params[] = $limit;

        return $db->query($sql, $params)->fetchAll();
    }
}

