<?php

namespace App\Api\Models;

use Framework\Core\Model;

class MessageReactionModel extends Model
{
    protected static string $table = 'message_reactions';
    protected array $fillable = ['message_id', 'user_id', 'emoji', 'created_at'];
    protected bool $timestamps = false;
}

