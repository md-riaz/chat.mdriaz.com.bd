<?php

namespace App\Api\Models;

use Framework\Core\Database;
use Framework\Core\Util;

class AuthTokenModel
{
    private static $db;

    public static function initDB()
    {
        if (!self::$db) {
            self::$db = new Database();
        }
        return self::$db;
    }

    public static function createToken($userId, $ipAddress = null, $userAgent = null, $deviceId = null, $expiresAt = null)
    {
        $db = self::initDB();
        
        $token = hash('sha256', Util::generateRandomString(64));
        
        $result = $db->query(
            "INSERT INTO auth_tokens (user_id, token, ip_address, user_agent, device_id, created_at, expires_at) 
             VALUES (?, ?, ?, ?, ?, NOW(), ?)",
            [$userId, $token, $ipAddress, $userAgent, $deviceId, $expiresAt]
        );
        
        return $token;
    }

    public static function validateToken($token)
    {
        $db = self::initDB();
        
        return $db->query(
            "SELECT at.*, u.id as user_id, u.name, u.email, u.username
             FROM auth_tokens at
             JOIN users u ON at.user_id = u.id
             WHERE at.token = ? 
             AND at.revoked_at IS NULL 
             AND (at.expires_at IS NULL OR at.expires_at > NOW())",
            [$token]
        )->fetchArray();
    }

    public static function revokeToken($token)
    {
        $db = self::initDB();
        
        return $db->query(
            "UPDATE auth_tokens SET revoked_at = NOW() WHERE token = ?",
            [$token]
        );
    }

    public static function revokeUserTokens($userId, $exceptToken = null)
    {
        $db = self::initDB();
        
        if ($exceptToken) {
            return $db->query(
                "UPDATE auth_tokens SET revoked_at = NOW() WHERE user_id = ? AND token != ?",
                [$userId, $exceptToken]
            );
        } else {
            return $db->query(
                "UPDATE auth_tokens SET revoked_at = NOW() WHERE user_id = ?",
                [$userId]
            );
        }
    }
}