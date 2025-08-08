<?php

namespace App\Api\Enum;

enum Status: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case PENDING = 'pending';
    case BLOCKED = 'blocked';
    case DELETED = 'deleted';
    case DELIVERED = 'delivered';
    case READ = 'read';
    case SENT = 'sent';
    case FAILED = 'failed';
}
