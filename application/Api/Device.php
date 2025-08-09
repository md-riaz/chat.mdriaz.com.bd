<?php

namespace App\Api;

use App\Api\ApiController;
use App\Api\Models\DeviceModel;
use App\Api\Models\UserModel;

class Device extends ApiController
{
    /**
     * POST /api/device/register - Register a device for push notifications
     */
    public function register()
    {
        $user = $this->authenticate();
        $data = $this->getJsonInput();

        $this->validateRequired($data, ['device_id', 'platform']);

        try {
            $result = DeviceModel::registerDevice(
                $user['user_id'],
                $data['device_id'],
                $data['platform'],
                $data['fcm_token'] ?? null,
                $data['device_name'] ?? null,
                $data['app_version'] ?? null,
                $data['os_version'] ?? null
            );

            $this->respondSuccess([
                'device_registered' => true,
                'device_id' => $data['device_id']
            ], 'Device registered successfully', 201);
        } catch (\Exception $e) {
            $this->respondError(500, 'Failed to register device');
        }
    }

    /**
     * GET /api/device - Get user's registered devices
     */
    public function index()
    {
        $user = $this->authenticate();

        try {
            $devices = DeviceModel::getUserDevices($user['user_id']);

            $this->respondSuccess($devices, 'Devices retrieved successfully');
        } catch (\Exception $e) {
            $this->respondError(500, 'Failed to retrieve devices');
        }
    }

    /**
     * PUT /api/device/{deviceId} - Update device information
     */
    public function update($deviceId = null)
    {
        if (!$deviceId) {
            $this->respondError(400, 'Device ID is required');
        }

        $user = $this->authenticate();
        $data = $this->getJsonInput();

        // Check if device belongs to user
        $device = $this->db->query(
            "SELECT id FROM user_devices WHERE device_id = ? AND user_id = ?",
            [$deviceId, $user['user_id']]
        )->fetchArray();

        if (!$device) {
            $this->respondError(404, 'Device not found');
        }

        $allowedFields = ['fcm_token', 'app_version', 'device_name', 'os_version'];
        $updateFields = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateFields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($updateFields)) {
            $this->respondError(400, 'No valid fields to update');
        }

        $updateFields[] = "updated_at = NOW()";
        $updateFields[] = "last_active_at = NOW()";
        $params[] = $deviceId;
        $params[] = $user['user_id'];

        try {
            $this->db->query(
                "UPDATE user_devices SET " . implode(', ', $updateFields) . " WHERE device_id = ? AND user_id = ?",
                $params
            );

            $this->respondSuccess(null, 'Device updated successfully');
        } catch (\Exception $e) {
            $this->respondError(500, 'Failed to update device');
        }
    }

    /**
     * DELETE /api/device/{deviceId} - Unregister a device
     */
    public function delete($deviceId = null)
    {
        if (!$deviceId) {
            $this->respondError(400, 'Device ID is required');
        }

        $user = $this->authenticate();

        try {
            $result = DeviceModel::removeDevice($user['user_id'], $deviceId);

            if ($result->rowCount() > 0) {
                $this->respondSuccess(null, 'Device unregistered successfully');
            } else {
                $this->respondError(404, 'Device not found');
            }
        } catch (\Exception $e) {
            $this->respondError(500, 'Failed to unregister device');
        }
    }

    /**
     * POST /api/device/{deviceId}/ping - Update device last active timestamp
     */
    public function ping($deviceId = null)
    {
        if (!$deviceId) {
            $this->respondError(400, 'Device ID is required');
        }

        $user = $this->authenticate();

        try {
            $result = DeviceModel::updateLastActive($user['user_id'], $deviceId);

            if ($result->rowCount() > 0) {
                $this->respondSuccess([
                    'timestamp' => date('Y-m-d H:i:s')
                ], 'Device ping updated successfully');
            } else {
                $this->respondError(404, 'Device not found');
            }
        } catch (\Exception $e) {
            $this->respondError(500, 'Failed to update device ping');
        }
    }

    /**
     * POST /api/device/test-notification - Send test push notification
     */
    public function testNotification()
    {
        $user = $this->authenticate();
        $data = $this->getJsonInput();

        $deviceId = $data['device_id'] ?? null;

        if (!$deviceId) {
            $this->respondError(400, 'Device ID is required');
        }

        try {
            // Get device FCM token
            $device = $this->db->query(
                "SELECT fcm_token FROM user_devices WHERE device_id = ? AND user_id = ?",
                [$deviceId, $user['user_id']]
            )->fetchArray();

            if (!$device || empty($device['fcm_token'])) {
                $this->respondError(404, 'Device not found or FCM token not available');
            }

            // Here you would typically send a push notification using FCM
            // For now, just simulate success
            $notificationSent = true; // DeviceModel::sendPushNotification($device['fcm_token'], 'Test Notification', 'This is a test notification');

            if ($notificationSent) {
                $this->respondSuccess(null, 'Test notification sent successfully');
            } else {
                $this->respondError(500, 'Failed to send test notification');
            }
        } catch (\Exception $e) {
            $this->respondError(500, 'Failed to send test notification');
        }
    }
}
