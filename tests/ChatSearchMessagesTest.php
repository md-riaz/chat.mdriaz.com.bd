<?php

namespace App\Api\Models {
    class MessageModel {
        public static $lastCalled = null;

        public static function searchUserMessages($userId, $searchQuery, $limit, $offset) {
            self::$lastCalled = ['method' => __FUNCTION__, 'args' => func_get_args()];
            return [];
        }

        public static function searchConversationMessages($userId, $conversationId, $searchQuery, $limit, $offset) {
            self::$lastCalled = ['method' => __FUNCTION__, 'args' => func_get_args()];
            return [];
        }
    }
}

namespace App\Api {
    abstract class ApiController {}
}

namespace Tests {

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../application/Api/Chat.php';

class ChatSearchMessagesTest extends TestCase
{
    protected function setUp(): void
    {
        \App\Api\Models\MessageModel::$lastCalled = null;
        $_GET = [];
    }

    public function testSearchMessagesWithConversationIdCallsConversationMethod(): void
    {
        $_GET['q'] = 'hello';
        $_GET['conversation_id'] = 5;

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
                return $data;
            }
        };
        $chat->searchMessages();

        $this->assertEquals('searchConversationMessages', \App\Api\Models\MessageModel::$lastCalled['method']);
        $this->assertEquals(200, $chat->statusCode);
    }

    public function testSearchMessagesWithoutConversationIdCallsUserMethod(): void
    {
        $_GET['q'] = 'hello';

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
                return $data;
            }
        };
        $chat->searchMessages();

        $this->assertEquals('searchUserMessages', \App\Api\Models\MessageModel::$lastCalled['method']);
        $this->assertEquals(200, $chat->statusCode);
    }
}
}

