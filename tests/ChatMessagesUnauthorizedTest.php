<?php

namespace App\Api\Models {
    class ConversationParticipantModel {
        public static $called = false;
        public static function isParticipant($conversationId, $userId) {
            self::$called = true;
            return false;
        }
    }
    class MessageModel {
        public static function getConversationMessagesWithDetails($conversationId, $limit, $offset) {
            throw new \RuntimeException('should not be called');
        }
    }
}

namespace Framework\Core {
    class Controller {}
    class DBManager { public static function getDB() { return new class {}; } }
    class Auth { public static function currentUser() { return null; } }
}

namespace Tests {

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../application/Api/ApiController.php';
require_once __DIR__ . '/../application/Api/Chat.php';

class ChatMessagesUnauthorizedTest extends TestCase
{
    public function testMessagesReturns403WhenUserNotParticipant(): void
    {
        $chat = new class extends \App\Api\Chat {
            public function __construct() {}
            protected function authenticate($required = true)
            {
                return ['user_id' => 99];
            }
        };

        $_GET['conversation_id'] = 1;

        $result = $chat->messages();

        $this->assertEquals(403, $result['status_code']);
        $this->assertTrue(\App\Api\Models\ConversationParticipantModel::$called);
    }
}
}
