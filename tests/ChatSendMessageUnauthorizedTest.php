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

namespace App\Api {
    abstract class ApiController {}
}

namespace Tests {

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../application/Api/Chat.php';

class ChatSendMessageUnauthorizedTest extends TestCase
{
    public function testSendMessageReturns403WhenUserNotParticipant(): void
    {
        $chat = new class extends \App\Api\Chat {
            public $statusCode;
            public function __construct() {}
            protected function authenticate($required = true)
            {
                return ['user_id' => 99];
            }
            protected function getJsonInput()
            {
                return ['conversation_id' => 1, 'content' => 'hello'];
            }
            protected function validateRequired($data, array $fields)
            {
                // no-op for testing
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

        try {
            $chat->sendMessage();
        } catch (\Exception $e) {
            // Ignore to allow assertions
        }

        $this->assertEquals(403, $chat->statusCode);
        $this->assertTrue(\App\Api\Models\ConversationParticipantModel::$called);
    }
}
}
