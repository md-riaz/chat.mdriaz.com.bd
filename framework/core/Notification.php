<?php

namespace Framework\Core;

use Framework\Core\DBManager;
use Framework\Queue\Dispatcher;

use App\Enum\NotificationChannel;
use App\Enum\NotificationEventType;
use App\Enum\Status;

use App\Jobs\SendEmailNotification;
use App\Jobs\SendPushNotification;

use Exception;

class Notification
{

    public int      $event;
    public int      $actor;
    public int      $organization_id;
    public mixed    $entity;
    public string   $launch_url;
    public array    $party_ids;
    public array    $channels;

    private int     $email;
    private int     $notification_id;
    private int     $notification_receiver_id;
    private int     $push_subscription_id;
    private mixed   $db;
    private string  $subject;
    private string  $html;
    private string  $text;
    private array   $data;

    public function __construct()
    {

        $this->data = [
            'SITE_TITLE' => SITE_TITLE,
            'DOMAIN' => parse_url(SMSAPP_URL, PHP_URL_HOST),
            'YEAR' => date('Y')
        ];

    }


    public function Queue()
    {

        $this->db = DBManager::getDB();

        if (empty($this->event) || empty($this->organization_id) || empty($this->channels) || empty($this->party_ids)) {
            throw new Exception('All required data not found!');
        }

        $inTransaction = $this->db->inTransaction();

        try {

            !$inTransaction && $this->db->beginTransaction();

            $this->notification_id = $this->addNotification(
                $this->event,
                $this->entity,
                $this->actor,
                $this->data,
                $this->launch_url ?? NULL
            );

            foreach ($this->channels as $channel) {

                $template = $this->getNotificationTemplate($channel, $this->event);

                if (empty($template)) {
                    throw new Exception('Notification template not found !');
                }

                $this->subject = $this->contentReady($template['subject']);
                $this->setData('SUBJECT', $this->subject);
                $this->html = $this->contentReady($template['html']);
                $this->text = $this->contentReady($template['text']);

                foreach ($this->party_ids as $party_id) {

                    switch ($channel) {
                        case NotificationChannel::EMAIL->value:
                            $this->email = $party_id['email_id'] ?? $this->getEmail($party_id, $this->organization_id, $this->event);
                            if (empty($this->email)) {
                                continue 2;
                            }
                            break;

                        case NotificationChannel::PUSH->value:
                            $this->push_subscription_id = $party_id['push_subscription_id'] ?? $this->getPushSubscriptionId($party_id, $this->organization_id);
                            if (empty($this->push_subscription_id)) {
                                continue 2;
                            }
                            break;
                    }

                    $this->notification_receiver_id = $this->addNotificationReceiver(
                        $this->notification_id,
                        $this->organization_id,
                        $party_id['party_id'] ?? $party_id,
                        $channel
                    );

                    $this->addNotificationLog(
                        $this->notification_id,
                        $this->notification_receiver_id,
                        $channel,
                        $this->push_subscription_id ?? 0,
                        $this->email ?? 0,
                        $this->subject,
                        $this->html,
                        $this->text,
                        Status::PENDING->value,
                        'PENDING: This notification is waiting for dispatch.'
                    );

                    $this->email = 0;
                    $this->push_subscription_id = 0;

                }

                $channel == NotificationChannel::PUSH->value  && Dispatcher::dispatch(new SendPushNotification($this->notification_id));
                $channel == NotificationChannel::EMAIL->value && Dispatcher::dispatch(new SendEmailNotification($this->notification_id));

            }

            !$inTransaction && $this->db->commit();

            return true;

        } catch (Exception $e) {

            !$inTransaction && $this->db->rollback();

            return false;
        }

    }


    public function setData($key, $value)
    {
        $this->data[$key] = $value;
    }

    public function contentReady($content)
    {

        if (empty($content)) {
            return false;
        }

        foreach ($this->data as $name => $value) {
            $content = str_ireplace("{{DATA::" . $name . "}}", $value, $content);
        }

        if (preg_match('/{{DATA::([_A-Za-z0-9]+)}}/', $content, $regs)) {
            print_r($regs);
            return false;
        }

        return $content;

    }

    private function getNotificationTemplate($channel, $event_type)
    {

        return $this->db->query(
            "SELECT * FROM notification_templates WHERE channel = ? AND event_type = ?",
            [$channel, $event_type]
        )->fetchArray();

    }

    private function addNotification($event_type, $entity_id, $actor_id, $extra, $launch_url, $created = TIMESTAMP)
    {

        $id = $this->db->query(
            "INSERT INTO notifications (event_type, entity_id, actor_id, extra, launch_url, created) VALUES (?,?,?,?,?,?)",
            [$event_type, $entity_id, $actor_id, json_encode($extra), $launch_url, $created]
        )->lastInsertID();

        return $id;

    }

    private function addNotificationReceiver($notification_id, $organization_id, $notifier_party_id, $channel)
    {

        $id = $this->db->query(
            "INSERT INTO notification_receivers (notification_id, organization_id, notifier_party_id, channel) VALUES (?,?,?,?)",
            [$notification_id, $organization_id, $notifier_party_id, $channel]
        )->lastInsertID();

        return $id;
        
    }

    private function addNotificationLog($notification_id, $notification_receiver_id, $channel, $push_subscription_id, $email, $subject, $html, $text, $status, $comment, $created = TIMESTAMP)
    {

        $id = $this->db->query(
            "INSERT INTO notification_logs (notification_id, notification_receiver_id, channel, push_subscription_id, email, subject, html, text, status, comment, created) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            [$notification_id, $notification_receiver_id, $channel, $push_subscription_id, $email, $subject, $html, $text, $status, $comment, $created]
        )->lastInsertID();

        return $id;

    }

    private function getEmail(int $party_id, int $organization_id, int $event)
    {

        $role = $event == NotificationEventType::USER_INVITED->value ? Status::PENDING->value : Status::ACTIVE->value;

        return $this->db->query(
            "SELECT 
            pe.email_id
            FROM party_email    AS pe 
            JOIN party_role     AS pr   ON pe.party_id = pr.party_id
            WHERE pe.party_id = ? AND pr.organization_id = ? AND pr.status = ? AND pe.status = ?",
            [$party_id, $organization_id, $role, Status::ACTIVE->value]
        )->fetchArray()['email_id'] ?? 0;

    }

    private function getPushSubscriptionId ($party_id, $organization_id)
    {

        return $this->db->query(
            "SELECT 
            ps.id                   AS push_subscription_id
            FROM push_subscriptions AS ps
            JOIN user_devices       AS ud ON ps.device_id = ud.id
            JOIN party_role         AS pr ON ud.party_id = pr.party_id
            WHERE ud.party_id = ? AND pr.organization_id = ? AND ps.status = ?",
            [$party_id, $organization_id, Status::ACTIVE->value]
        )->fetchArray()['push_subscription_id'] ?? 0;

    }

}
