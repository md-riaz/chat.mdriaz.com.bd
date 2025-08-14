<?php

namespace App\Api\Models {
    class ConversationModel {
        public static bool $totalCalled = false;
        public static bool $getUserCalled = false;
        public static bool $getUnreadCalled = false;

        public static function getTotalUnreadCount($userId) {
            self::$totalCalled = true;
            // Simulate more than 100 conversations with a total of 150 unread messages
            return 150;
        }

        public static function getUserConversations($userId, $limit, $offset) {
            self::$getUserCalled = true;
            return [];
        }

        public static function getUnreadCount($conversationId, $userId) {
            self::$getUnreadCalled = true;
            return 0;
        }
    }
}

namespace App\Api {
    abstract class ApiController {}
}

namespace Tests {

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../application/Api/Chat.php';

class ChatUnreadCountTest extends TestCase
{
    protected function setUp(): void
    {
        \App\Api\Models\ConversationModel::$totalCalled = false;
        \App\Api\Models\ConversationModel::$getUserCalled = false;
        \App\Api\Models\ConversationModel::$getUnreadCalled = false;
    }

    public function testUnreadCountUsesAggregateMethod(): void
    {
        $chat = new class extends \App\Api\Chat {
            public $statusCode;
            public $data;
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
                $this->data = $data;
                return $data;
            }
        };

        $chat->unreadCount();

        $this->assertTrue(\App\Api\Models\ConversationModel::$totalCalled);
        $this->assertFalse(\App\Api\Models\ConversationModel::$getUserCalled);
        $this->assertFalse(\App\Api\Models\ConversationModel::$getUnreadCalled);
        $this->assertEquals(200, $chat->statusCode);
        $this->assertEquals(150, $chat->data['unread_count']);
    }
}
}
