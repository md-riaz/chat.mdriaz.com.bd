<?php
namespace Tests;

use PHPUnit\Framework\TestCase;

class MessageModelReactionsTest extends TestCase
{
    private function boot(string $dbType, object $db): void
    {
        define('DB_TYPE', $dbType);
        eval('namespace Framework\\Core { class DBManager { public static $db; public static function getDB($dbname = null) { return self::$db; } } class Model { protected static function db() { return DBManager::getDB(); } } }');
        \Framework\Core\DBManager::$db = $db;
        require_once __DIR__ . '/../application/Api/Models/MessageModel.php';
    }

    /** @runInSeparateProcess */
    public function testMysqlReactionsFormat(): void
    {
        $fakeResults = [["reactions" => '😀,😂']];
        $db = new class($fakeResults) {
            public array $queries = [];
            public function __construct(private array $results) {}
            public function query($sql, $params = []) { $this->queries[] = $sql; return $this; }
            public function fetchAll() { return $this->results; }
        };
        $this->boot('mysql', $db);
        $messages = \App\Api\Models\MessageModel::getConversationMessagesWithDetails(1, 50, 0);
        $this->assertSame('😀,😂', $messages[0]['reactions']);
        $this->assertStringContainsString('GROUP_CONCAT', $db->queries[0]);
    }

    /** @runInSeparateProcess */
    public function testPgsqlReactionsFormat(): void
    {
        $fakeResults = [["reactions" => '😀,😂']];
        $db = new class($fakeResults) {
            public array $queries = [];
            public function __construct(private array $results) {}
            public function query($sql, $params = []) { $this->queries[] = $sql; return $this; }
            public function fetchAll() { return $this->results; }
        };
        $this->boot('pgsql', $db);
        $messages = \App\Api\Models\MessageModel::getConversationMessagesWithDetails(1, 50, 0);
        $this->assertSame('😀,😂', $messages[0]['reactions']);
        $this->assertStringContainsString('STRING_AGG', $db->queries[0]);
    }
}
