<?php

namespace App\Api\Models;

use Framework\Core\Model;

class MessageMentionModel extends Model
{
    protected static string $table = 'message_mentions';
    protected array $fillable = ['message_id', 'mentioned_user_id'];
    protected bool $timestamps = false;
}

