<?php

namespace App\Api\Models;

use Framework\Core\Model;
use App\Api\Models\ConversationModel;
use App\Api\Models\UserModel;

class ConversationParticipantModel extends Model
{
    protected static string $table = 'conversation_participants';
    protected array $fillable = ['conversation_id', 'user_id', 'role', 'joined_at', 'last_read_message_id'];
    protected bool $timestamps = false;

    public function conversation(): ?ConversationModel
    {
        return $this->belongsTo(ConversationModel::class);
    }

    public function user(): ?UserModel
    {
        return $this->belongsTo(UserModel::class);
    }

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
        $conversation = ConversationModel::find((int) $conversationId);
        if (!$conversation) {
            return [];
        }

        $participants = $conversation->participants();
        return array_map(function ($user) {
            $data = $user->toArray();
            $pivot = $user->_pivot ?? [];
            $data['role'] = $pivot['role'] ?? null;
            $data['joined_at'] = $pivot['joined_at'] ?? null;
            $data['last_read_message_id'] = $pivot['last_read_message_id'] ?? null;
            return $data;
        }, $participants);
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

