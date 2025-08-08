<?php

namespace App\Api\Models;

use Framework\Core\Database;

class DeviceModel
{
    private static $db;

    public static function initDB()
    {
        if (!self::$db) {
            self::$db = new Database();
        }
        return self::$db;
    }

    public static function registerDevice($userId, $deviceId, $platform, $fcmToken = null, $deviceName = null, $appVersion = null, $osVersion = null)
    {
        $db = self::initDB();
        
        // Check if device already exists
        $existing = $db->query(
            "SELECT id FROM user_devices WHERE user_id = ? AND device_id = ?",
            [$userId, $deviceId]
        )->fetchArray();
        
        if ($existing) {
            // Update existing device
            return $db->query(
                "UPDATE user_devices SET 
                 platform = ?, fcm_token = ?, device_name = ?, app_version = ?, os_version = ?, 
                 last_active_at = NOW(), updated_at = NOW()
                 WHERE id = ?",
                [$platform, $fcmToken, $deviceName, $appVersion, $osVersion, $existing['id']]
            );
        } else {
            // Insert new device
            return $db->query(
                "INSERT INTO user_devices (user_id, device_id, platform, fcm_token, device_name, app_version, os_version, last_active_at, created_at, updated_at) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())",
                [$userId, $deviceId, $platform, $fcmToken, $deviceName, $appVersion, $osVersion]
            );
        }
    }

    public static function updateLastActive($userId, $deviceId)
    {
        $db = self::initDB();
        
        return $db->query(
            "UPDATE user_devices SET last_active_at = NOW(), updated_at = NOW() WHERE user_id = ? AND device_id = ?",
            [$userId, $deviceId]
        );
    }

    public static function getUserDevices($userId)
    {
        $db = self::initDB();
        
        return $db->query(
            "SELECT * FROM user_devices WHERE user_id = ? ORDER BY last_active_at DESC",
            [$userId]
        )->fetchAll();
    }

    public static function removeDevice($userId, $deviceId)
    {
        $db = self::initDB();
        
        return $db->query(
            "DELETE FROM user_devices WHERE user_id = ? AND device_id = ?",
            [$userId, $deviceId]
        );
    }

    public static function updateFcmToken($userId, $deviceId, $fcmToken)
    {
        $db = self::initDB();
        
        return $db->query(
            "UPDATE user_devices SET fcm_token = ?, updated_at = NOW() WHERE user_id = ? AND device_id = ?",
            [$fcmToken, $userId, $deviceId]
        );
    }
}