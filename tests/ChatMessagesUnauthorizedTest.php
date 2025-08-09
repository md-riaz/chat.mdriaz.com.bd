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

namespace App\Api {
    abstract class ApiController {}
}

namespace Tests {

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../application/Api/Chat.php';

class ChatMessagesUnauthorizedTest extends TestCase
{
    public function testMessagesReturns403WhenUserNotParticipant(): void
    {
        $chat = new class extends \App\Api\Chat {
            public $statusCode;
            public function __construct() {}
            protected function authenticate($required = true)
            {
                return ['user_id' => 99];
            }
            protected function respondError($statusCode, $message, $errors = null)
            {
                $this->statusCode = $statusCode;
                throw new \Exception('error');
            }
            protected function respondSuccess($data = null, $message = 'Success', $statusCode = 200)
            {
                $this->statusCode = $statusCode;
                throw new \Exception('success');
            }
        };

        $_GET['conversation_id'] = 1;

        try {
            $chat->messages();
        } catch (\Exception $e) {
            // Ignore to allow assertions
        }

        $this->assertEquals(403, $chat->statusCode);
        $this->assertTrue(\App\Api\Models\ConversationParticipantModel::$called);
    }
}
}
