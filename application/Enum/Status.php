<?php

namespace App\Enum;



enum Status: int
{
    case ACTIVE = 1;
    case PENDING = 2;
    case SUSPENDED = 3;
    case DISABLED = 4;
    case CANCELLED = 5;
    case CLOSED = 6;
    case COMPLETED = 7;
    case DELETED = 8;
    case TERMINATED = 9;
    case PROCESSING = 10;
    case DELIVERED = 11;
    case FAILED = 12;
    case SCHEDULED = 13;
    case SENT = 14;
    case REJECTED = 15;
}