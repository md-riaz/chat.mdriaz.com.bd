<?php

namespace App\Api\Models;

use Framework\Core\Model;

class MessageEventModel extends Model
{
    protected static string $table = 'message_events';
    protected array $fillable = ['message_id', 'user_id', 'event_type', 'created_at'];
    protected bool $timestamps = false;
}

