<?php

namespace App\Api;

use App\Api\ApiController;

class Status extends ApiController
{
    /**
     * GET /api/status - API health check
     */
    public function index()
    {
        $status = [
            'api_version' => '1.0.0',
            'status' => 'healthy',
            'timestamp' => date('Y-m-d H:i:s'),
            'timezone' => date_default_timezone_get(),
            'services' => []
        ];

        // Check database
        try {
            $result = $this->db->query("SELECT 1 as test")->fetchArray();
            $status['services']['database'] = $result ? 'connected' : 'disconnected';
        } catch (\Exception $e) {
            $status['services']['database'] = 'error';
        }

        // Check Redis
        if (class_exists('RedisService')) {
            $redis = \RedisService::getInstance();
            $status['services']['redis'] = $redis->ping() ? 'connected' : 'disconnected';
        } else {
            $status['services']['redis'] = 'not_available';
        }

        // Check if uploads directory exists
        $status['services']['uploads'] = is_dir(UPLOADS_DIR) && is_writable(UPLOADS_DIR) ? 'writable' : 'not_writable';

        // Check if logs directory exists
        $status['services']['logs'] = is_dir(LOGS_DIR) && is_writable(LOGS_DIR) ? 'writable' : 'not_writable';

        return $this->respondSuccess($status, 'API health check completed');
    }

    /**
     * GET /api/status/auth - Check authentication status
     */
    public function auth()
    {
        $user = $this->authenticate();
        if (isset($user["status_code"])) {
            return $user;
        }

        return $this->respondSuccess([
            'authenticated' => true,
            'user_id' => $user['user_id'],
            'timestamp' => date('Y-m-d H:i:s')
        ], 'Authentication successful');
    }

    /**
     * GET /api/status/database - Check database connection
     */
    public function database()
    {
        try {
            $result = $this->db->query("SELECT 1 as test")->fetchArray();

            if ($result && $result['test'] == 1) {
                return $this->respondSuccess([
                    'database' => 'connected',
                    'timestamp' => date('Y-m-d H:i:s')
                ], 'Database connection successful');
            } else {
                return $this->respondError(500, 'Database connection failed');
            }
        } catch (\Exception $e) {
            return $this->respondError(500, 'Database connection error: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/status/redis - Check Redis connection
     */
    public function redis()
    {
        if (!class_exists('RedisService')) {
            return $this->respondError(500, 'Redis service not available');
        }

        try {
            $redis = \RedisService::getInstance();

            if ($redis->isConnected() && $redis->ping()) {
                // Test basic operations
                $testKey = 'health_check_' . time();
                $testValue = 'test_' . uniqid();

                $redis->set($testKey, $testValue, 10);
                $retrieved = $redis->get($testKey);
                $redis->delete($testKey);

                if ($retrieved === $testValue) {
                    return $this->respondSuccess([
                        'redis' => 'connected',
                        'operations' => 'working',
                        'timestamp' => date('Y-m-d H:i:s')
                    ], 'Redis connection and operations successful');
                } else {
                    return $this->respondError(500, 'Redis operations failed');
                }
            } else {
                return $this->respondError(500, 'Redis connection failed');
            }
        } catch (\Exception $e) {
            return $this->respondError(500, 'Redis error: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/status/websocket - Check WebSocket server status
     */
    public function websocket()
    {
        $wsHost = defined('WEBSOCKET_HOST') ? WEBSOCKET_HOST : 'localhost';
        $wsPort = defined('WEBSOCKET_PORT') ? WEBSOCKET_PORT : 8080;

        // Try to connect to WebSocket server
        $connection = @fsockopen($wsHost, $wsPort, $errno, $errstr, 1);

        if ($connection) {
            fclose($connection);
            return $this->respondSuccess([
                'websocket_server' => 'running',
                'host' => $wsHost,
                'port' => $wsPort,
                'timestamp' => date('Y-m-d H:i:s')
            ], 'WebSocket server is running');
        } else {
            return $this->respondError(500, "WebSocket server not reachable on {$wsHost}:{$wsPort}");
        }
    }
}
