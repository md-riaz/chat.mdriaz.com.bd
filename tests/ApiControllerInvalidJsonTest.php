<?php
namespace Framework\Core {
    class Controller {}
    class DBManager { public static function getDB() { return null; } }
    class Auth { public static function currentUser() { return null; } }
}

namespace App\Api {
    function file_get_contents($filename) {
        if ($filename === 'php://input') {
            return '{"invalid"';
        }
        return \file_get_contents($filename);
    }
}

namespace Tests {
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/../application/Api/ApiController.php';

    class ApiControllerInvalidJsonTest extends TestCase
    {
        public function testInvalidJsonTriggersError(): void
        {
            $controller = new class extends \App\Api\ApiController {
                public $statusCode;
                public $errorMessage;
                public function __construct() {}
                public function triggerGetJsonInput() {
                    return $this->getJsonInput();
                }
                protected function respondError($statusCode, $message, $errors = null)
                {
                    $this->statusCode = $statusCode;
                    $this->errorMessage = $message;
                    throw new \Exception('error');
                }
            };

            try {
                $controller->triggerGetJsonInput();
            } catch (\Exception $e) {
                // Ignore exception to assert later
            }

            $this->assertEquals(400, $controller->statusCode);
            $this->assertEquals('Invalid JSON body', $controller->errorMessage);
        }
    }
}
