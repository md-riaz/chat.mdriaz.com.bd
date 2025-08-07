<?php 

namespace App\Jobs;

use App\Enum\NotificationChannel;
use App\Enum\Status;
use App\Models\NotificationModel;
use FCM\PushNotification;
use Framework\Core\DBManager;
use Framework\Core\Notification;
use Framework\Queue\JobInterface;

class SendPushNotification implements JobInterface
{
    private $db;
    private int $notification_id;

    public function __construct($notification_id)
    {
        $this->notification_id = $notification_id;
    }

    public function handle() : void
    {

        $this->db = DBManager::getDB();

        $sql = "SELECT
        s.name, v.value
        FROM system_settings AS s
        LEFT JOIN system_setting_values AS v ON s.id = v.setting_id
        WHERE s.name IN ('firebase_cloud_messaging.project_id','firebase_cloud_messaging.client_email','firebase_cloud_messaging.private_key')";

        $result = $this->db->query($sql)->fetchAll();

        $logo = APP_URL . '/images/logo.png';

        $notifications = NotificationModel::getPendingNotifications(NotificationChannel::PUSH->value, $this->notification_id);

        if (empty($notifications)) {
            return;
        }

        foreach ($notifications as $notification) {
            if (empty($notification['fcm_token'])) {
                $response = [
                    'error' => [
                        'message' => 'No valid device tokens found for notification log ID: ' . ($notification['log_id'] ?? 'unknown'),
                    ],
                    'name'  => 'PushNotificationError',
                    'token' => '',
                    'id'    => $notification['log_id'] ?? null,
                ];

                $status = Status::FAILED->value;
                $comment = $response['error']['message'];

                NotificationModel::updateNotificationLog($notification['log_id'], $status, $comment);

                continue;
            }

            
            $response = [];
            
            $status = Status::PROCESSING->value;
            $comment = 'Processing notification...';

            NotificationModel::updateNotificationLog($notification['log_id'], $status, $comment);

            try {

                if (empty($result)) {
                    throw new \Exception('ERROR: No FCM credentials found in the database.');
                }

                $creds = [];
                foreach ($result as $row) {
                    $creds[$row['name']] = $row['value'];
                }

                $fcm = new PushNotification($creds['firebase_cloud_messaging.project_id'], $creds['firebase_cloud_messaging.client_email'], $creds['firebase_cloud_messaging.private_key']);

                $fcm->deviceToken  = $notification['fcm_token']     ?? '';
                $fcm->title        = $notification['subject']       ?? '';
                $fcm->body         = $notification['text']          ?? '';
                $fcm->platform     = $notification['platform']      ?? '';
                $fcm->icon         = $logo                          ?? '';
                $fcm->image        = $notification['image_url']     ?? '';
                $fcm->click_action = $notification['launch_url']    ?? '';

                if (!empty($notification['extra'])) {

                    $notif_extra = json_decode($notification['extra'], true);
                    $fcm->data = $notif_extra;

                }

                echo $notification['log_id'] . ' - ' . $fcm->title . ' - ' . $fcm->body . PHP_EOL;

                $response = $fcm->send();

                $response['token'] = $notification['fcm_token'];
                $response['id']    = $notification['log_id'];

                $status = !empty($response['name']) ? Status::DELIVERED->value : Status::FAILED->value;
                $comment = $response['name'] ?? $response['error']['message'];

            } catch (\Throwable $e) {

                $response = [
                    'error' => [
                        'message' => $e->getMessage(),
                    ],
                    'name'  => 'PushNotificationError',
                    'token' => $notification['fcm_token'],
                    'id'    => $notification['log_id'],
                ];

                $status = Status::FAILED->value;
                $comment = $response['error']['message'];

            } finally {
                NotificationModel::updateNotificationLog($notification['log_id'], $status, $comment);
            }
            
        }
    }
}