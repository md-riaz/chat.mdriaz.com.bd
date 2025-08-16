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

namespace Framework\Core {
    class Controller {}
    class DBManager { public static function getDB() { return new class {}; } }
    class Auth { public static function currentUser() { return null; } }
}

namespace Tests {

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../application/Api/ApiController.php';
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
            public function __construct() {}
            protected function authenticate($required = true)
            {
                return ['user_id' => 99];
            }
        };
        $result = $chat->searchMessages();

        $this->assertEquals('searchConversationMessages', \App\Api\Models\MessageModel::$lastCalled['method']);
        $this->assertEquals(200, $result['status_code']);
    }

    public function testSearchMessagesWithoutConversationIdCallsUserMethod(): void
    {
        $_GET['q'] = 'hello';

        $chat = new class extends \App\Api\Chat {
            public function __construct() {}
            protected function authenticate($required = true)
            {
                return ['user_id' => 99];
            }
        };
        $result = $chat->searchMessages();

        $this->assertEquals('searchUserMessages', \App\Api\Models\MessageModel::$lastCalled['method']);
        $this->assertEquals(200, $result['status_code']);
    }
}
}

