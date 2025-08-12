<?php

namespace App\Api\Models;

use Framework\Core\Model;

class ConversationParticipantModel extends Model
{
    protected static string $table = 'conversation_participants';
    protected array $fillable = ['conversation_id', 'user_id', 'role', 'joined_at', 'last_read_message_id'];
    protected bool $timestamps = false;

    public static function isParticipant($conversationId, $userId)
    {
        return static::first([
            'conversation_id' => $conversationId,
            'user_id'        => $userId,
        ]) !== null;
    }

    public static function addParticipant($conversationId, $userId, $isAdmin = false)
    {
        if (static::isParticipant($conversationId, $userId)) {
            return false;
        }

        $participant = new static([
            'conversation_id' => $conversationId,
            'user_id'         => $userId,
            'role'            => $isAdmin ? 'admin' : 'member',
        ]);
        $participant->save();
        return true;
    }

    public static function addMultipleParticipants($conversationId, $userIds, $isAdmin = false)
    {
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
        $participant = static::first([
            'conversation_id' => $conversationId,
            'user_id'        => $userId,
        ]);
        if (!$participant) {
            return false;
        }
        $participant->delete();
        return true;
    }

    public static function updateLastReadMessage($conversationId, $userId, $messageId)
    {
        $participant = static::first([
            'conversation_id' => $conversationId,
            'user_id'        => $userId,
        ]);
        if (!$participant) {
            return false;
        }
        $participant->last_read_message_id = $messageId;
        $participant->save();
        return true;
    }

    public static function isAdmin($conversationId, $userId)
    {
        $participant = static::first([
            'conversation_id' => $conversationId,
            'user_id'        => $userId,
        ]);
        return $participant && $participant->role === 'admin';
    }

    public static function getParticipants($conversationId)
    {
        $db = static::db();

        return $db->query(
            "SELECT cp.*, u.name, u.username, u.email, u.avatar_url"
            . " FROM conversation_participants cp"
            . " JOIN users u ON cp.user_id = u.id"
            . " WHERE cp.conversation_id = ?"
            . " ORDER BY cp.joined_at ASC",
            [$conversationId]
        )->fetchAll();
    }

    public static function getParticipantRole($conversationId, $userId)
    {
        $participant = static::first([
            'conversation_id' => $conversationId,
            'user_id'        => $userId,
        ]);
        return $participant ? $participant->role : null;
    }
}

