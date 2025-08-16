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
        public static function createMessage($conversationId, $userId, $content, $messageType, $parentId) {
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

class ChatSendMessageUnauthorizedTest extends TestCase
{
    public function testSendMessageReturns403WhenUserNotParticipant(): void
    {
        $chat = new class extends \App\Api\Chat {
            public function __construct() {}
            protected function authenticate($required = true)
            {
                return ['user_id' => 99];
            }
            protected function getJsonInput()
            {
                return ['conversation_id' => 1, 'content' => 'hello'];
            }
            protected function validateRequired($data, $fields)
            {
                return null;
            }
        };

        $result = $chat->sendMessage();

        $this->assertEquals(403, $result['status_code']);
        $this->assertTrue(\App\Api\Models\ConversationParticipantModel::$called);
    }
}
}
