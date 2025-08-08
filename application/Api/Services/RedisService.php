<?php

namespace App\Api\Services;

class RedisService
{
    private static $instance = null;
    private $redis = null;
    private $connected = false;

    private function __construct()
    {
        $this->connect();
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function connect()
    {
        try {
            if (extension_loaded('redis')) {
                // Use PHP Redis extension if available
                $this->redis = new Redis();
                $this->connected = $this->redis->connect(REDIS_HOST, REDIS_PORT);

                if ($this->connected && !empty(REDIS_PASSWORD)) {
                    $this->redis->auth(REDIS_PASSWORD);
                }

                if ($this->connected && REDIS_DATABASE > 0) {
                    $this->redis->select(REDIS_DATABASE);
                }
            } else {
                // Fallback to ReactPHP Redis if PHP Redis extension is not available
                $this->connectWithReactRedis();
            }
        } catch (Exception $e) {
            error_log("Redis connection failed: " . $e->getMessage());
            $this->connected = false;
        }
    }

    private function connectWithReactRedis()
    {
        if (class_exists('\Clue\React\Redis\Factory')) {
            $loop = \React\EventLoop\Factory::create();
            $factory = new \Clue\React\Redis\Factory($loop);

            $uri = 'redis://';
            if (!empty(REDIS_PASSWORD)) {
                $uri .= ':' . urlencode(REDIS_PASSWORD) . '@';
            }
            $uri .= REDIS_HOST . ':' . REDIS_PORT;
            if (REDIS_DATABASE > 0) {
                $uri .= '/' . REDIS_DATABASE;
            }

            $this->redis = $factory->createLazyClient($uri);
            $this->connected = true;
        }
    }

    public function isConnected()
    {
        return $this->connected;
    }

    public function set($key, $value, $ttl = null)
    {
        if (!$this->connected) return false;

        try {
            if ($ttl) {
                return $this->redis->setex($key, $ttl, $value);
            } else {
                return $this->redis->set($key, $value);
            }
        } catch (Exception $e) {
            error_log("Redis SET failed: " . $e->getMessage());
            return false;
        }
    }

    public function get($key)
    {
        if (!$this->connected) return null;

        try {
            return $this->redis->get($key);
        } catch (Exception $e) {
            error_log("Redis GET failed: " . $e->getMessage());
            return null;
        }
    }

    public function delete($key)
    {
        if (!$this->connected) return false;

        try {
            return $this->redis->del($key);
        } catch (Exception $e) {
            error_log("Redis DELETE failed: " . $e->getMessage());
            return false;
        }
    }

    public function exists($key)
    {
        if (!$this->connected) return false;

        try {
            return $this->redis->exists($key);
        } catch (Exception $e) {
            error_log("Redis EXISTS failed: " . $e->getMessage());
            return false;
        }
    }

    public function publish($channel, $message)
    {
        if (!$this->connected) return false;

        try {
            return $this->redis->publish($channel, $message);
        } catch (Exception $e) {
            error_log("Redis PUBLISH failed: " . $e->getMessage());
            return false;
        }
    }

    public function expire($key, $ttl)
    {
        if (!$this->connected) return false;

        try {
            return $this->redis->expire($key, $ttl);
        } catch (Exception $e) {
            error_log("Redis EXPIRE failed: " . $e->getMessage());
            return false;
        }
    }

    public function incr($key)
    {
        if (!$this->connected) return false;

        try {
            return $this->redis->incr($key);
        } catch (Exception $e) {
            error_log("Redis INCR failed: " . $e->getMessage());
            return false;
        }
    }

    public function hset($hash, $key, $value)
    {
        if (!$this->connected) return false;

        try {
            return $this->redis->hset($hash, $key, $value);
        } catch (Exception $e) {
            error_log("Redis HSET failed: " . $e->getMessage());
            return false;
        }
    }

    public function hget($hash, $key)
    {
        if (!$this->connected) return null;

        try {
            return $this->redis->hget($hash, $key);
        } catch (Exception $e) {
            error_log("Redis HGET failed: " . $e->getMessage());
            return null;
        }
    }

    public function hgetall($hash)
    {
        if (!$this->connected) return [];

        try {
            return $this->redis->hgetall($hash);
        } catch (Exception $e) {
            error_log("Redis HGETALL failed: " . $e->getMessage());
            return [];
        }
    }

    public function sadd($set, $member)
    {
        if (!$this->connected) return false;

        try {
            return $this->redis->sadd($set, $member);
        } catch (Exception $e) {
            error_log("Redis SADD failed: " . $e->getMessage());
            return false;
        }
    }

    public function srem($set, $member)
    {
        if (!$this->connected) return false;

        try {
            return $this->redis->srem($set, $member);
        } catch (Exception $e) {
            error_log("Redis SREM failed: " . $e->getMessage());
            return false;
        }
    }

    public function smembers($set)
    {
        if (!$this->connected) return [];

        try {
            return $this->redis->smembers($set);
        } catch (Exception $e) {
            error_log("Redis SMEMBERS failed: " . $e->getMessage());
            return [];
        }
    }

    public function getRedisInstance()
    {
        return $this->redis;
    }

    public function ping()
    {
        if (!$this->connected) return false;

        try {
            $response = $this->redis->ping();
            return $response === 'PONG' || $response === '+PONG';
        } catch (Exception $e) {
            error_log("Redis PING failed: " . $e->getMessage());
            return false;
        }
    }
}
