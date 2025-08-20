<?php

namespace App\Api\Models;

use Framework\Core\Model;
use Framework\Core\Collection;
use App\Api\Models\UserModel;

class DeviceModel extends Model
{
    protected static string $table = 'user_devices';
    protected array $fillable = [
        'user_id', 'device_id', 'platform', 'fcm_token', 'device_name',
        'app_version', 'os_version', 'last_active_at', 'created_at', 'updated_at'
    ];

    public function user(): ?UserModel
    {
        return $this->belongsTo(UserModel::class);
    }

    public static function registerDevice($userId, $deviceId, $platform, $fcmToken = null, $deviceName = null, $appVersion = null, $osVersion = null)
    {
        $device = static::first([
            'user_id' => $userId,
            'device_id' => $deviceId,
        ]);

        $data = [
            'platform'    => $platform,
            'fcm_token'   => $fcmToken,
            'device_name' => $deviceName,
            'app_version' => $appVersion,
            'os_version'  => $osVersion,
            'last_active_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ];

        if ($device) {
            $device->fill($data);
            $device->save();
            return $device->id;
        }

        $device = new static(array_merge($data, [
            'user_id'   => $userId,
            'device_id' => $deviceId,
        ]));
        $device->save();
        return $device->id;
    }

    public static function updateLastActive($userId, $deviceId)
    {
        $device = static::first([
            'user_id' => $userId,
            'device_id' => $deviceId,
        ]);
        if (!$device) {
            return false;
        }
        $device->last_active_at = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $device->save();
        return true;
    }

    public static function getUserDevices($userId)
    {
        $devices = static::where(['user_id' => $userId]);
        usort($devices, fn($a, $b) => strcmp($b->last_active_at, $a->last_active_at));
        return (new Collection($devices))->toArray();
    }

    public static function removeDevice($userId, $deviceId)
    {
        $device = static::first([
            'user_id' => $userId,
            'device_id' => $deviceId,
        ]);
        if (!$device) {
            return false;
        }
        $device->delete();
        return true;
    }

    public static function updateFcmToken($userId, $deviceId, $fcmToken)
    {
        $device = static::first([
            'user_id' => $userId,
            'device_id' => $deviceId,
        ]);
        if (!$device) {
            return false;
        }
        $device->fcm_token = $fcmToken;
        $device->save();
        return true;
    }
}

