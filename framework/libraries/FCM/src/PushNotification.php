<?php

namespace FCM;

use DateTime;
use DateTimeZone;
use Exception;

class PushNotification 
{
    private const TOKEN_CACHE_FILE = __DIR__ . '/fcm_access_token.json';
    private const FCM_SCOPES = 'https://www.googleapis.com/auth/firebase.messaging';
    private const LOG_FILE = __DIR__ . '/fcm_log.txt';

    public $deviceToken;
    public $platform;
    public $icon;
    public $client_email;
    public $private_key;
    public $project_id;
    public $title;
    public $body;
    public $image;
    public $click_action;
    public $data = [];

    public function __construct($id, $email, $key)
    {
        if (empty($id) || empty($email) || empty($key)) {
            $this->logMessage('Missing required fields.');
            throw new Exception('Missing required fields.');
        }

        $this->project_id = $id;
        $this->client_email = $email;
        $this->private_key = str_replace('\n', "\n", $key);

        if (!openssl_pkey_get_private($this->private_key)) {
            $this->logMessage('Invalid key format.');
            throw new Exception('Invalid key format.');
        }

    }

    public function send()
    {
        $access_token = $this->getAccessToken();

        $url = "https://fcm.googleapis.com/v1/projects/{$this->project_id}/messages:send";

        $payload = [
            'message' => [
                'token' => $this->deviceToken,
                'android' => [
                    'priority' => 'high'
                ],
                'apns' => [
                    'headers' => [
                        'apns-priority' => '10'
                    ],
                    'payload' => [
                        'aps' => [
                            'sound' => 'default'
                        ]
                    ]
                ],
                'webpush' => [
                    'headers' => [
                        'Urgency' => 'high'
                    ]
                ]
            ]
        ];

        if (empty($this->deviceToken)) {
            $this->logMessage('Device token is empty.');
            throw new Exception('Device token is required.');
        }

        if (!empty($this->title) && !empty($this->body)) {
            $payload['message']['notification'] = [
                'title' => $this->title,
                'body' => $this->body
            ];

            if (!empty($this->click_action)) {
                $payload['message']['android']['notification']['tag'] = $this->click_action;
                $payload['message']['webpush']['notification']['tag'] = $this->click_action;
                $payload['message']['apns']['headers']['apns-collapse-id'] = $this->click_action;
            } else {
                $payload['message']['android']['notification']['tag'] = $this->title;
                $payload['message']['webpush']['notification']['tag'] = $this->title;
                $payload['message']['apns']['headers']['apns-collapse-id'] = $this->title;
            }

        } else {
            $this->logMessage('Title or body is empty. Notification will not be sent.');
            throw new Exception('Title or body cannot be empty.');
        }

        if (!empty($this->icon) && $this->platform === 'android') {
            $payload['message']['android']['notification']['icon'] = $this->icon;
        } 
        
        if (!empty($this->icon) && $this->platform === 'web') {
            $payload['message']['webpush']['notification']['icon'] = $this->icon;
        }

        if (!empty($this->image)) {
            $payload['message']['notification']['image'] = $this->image;
        }

        if (!empty($this->click_action)) {
            $payload['message']['webpush']['fcm_options']['link'] = $this->click_action;
        }

        if (!empty($this->data)) {
            $stringifiedData = $this->stringifyArrayValues($this->data);
            $payload['message']['data'] = $stringifiedData;
        }

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $access_token
        ];

        $response = $this->httpPost($url, json_encode($payload), $headers);

        $this->logMessage("Push notification sent to {$this->deviceToken}: " . json_encode($response));

        return $response;
    }

    /**
    * Recursively converts all values in an array to strings
    *
    * @param array $array
    * @return array
    */
    private function stringifyArrayValues(array $array): array {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->stringifyArrayValues($value); // Recurse into sub-array
            } else {
                $array[$key] = (string) $value; // Convert scalar to string
            }
        }
        return $array;
    }

    private function getAccessToken()
    {

        if (file_exists(self::TOKEN_CACHE_FILE)) {
            $cached = json_decode(file_get_contents(self::TOKEN_CACHE_FILE), true);
            if (!empty($cached['access_token']) && $cached['expires_at'] > time()) {
                return $cached['access_token'];
            }
        }

        $jwtHeader = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $jwtPayload = $this->base64UrlEncode(json_encode([
            'iss' => $this->client_email,
            'scope' => self::FCM_SCOPES,
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => time() + 3600,
            'iat' => time()
        ]));

        $signature = '';
        openssl_sign("$jwtHeader.$jwtPayload", $signature, $this->private_key, 'sha256');
        $jwt = "$jwtHeader.$jwtPayload." . $this->base64UrlEncode($signature);

        $response = $this->httpPost('https://oauth2.googleapis.com/token', json_encode([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ]), ['Content-Type: application/json']);

        if (empty($response['access_token'])) {
            $this->logMessage('Failed to obtain access token.');
            throw new Exception('Failed to obtain access token.');
        }

        file_put_contents(self::TOKEN_CACHE_FILE, json_encode([
            'access_token' => $response['access_token'],
            'expires_at' => time() + 3500
        ]));

        return $response['access_token'];
    }

    private function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function httpPost($url, $data, $headers)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);

        if (curl_errno($ch)) {
            $this->logMessage('Curl error: ' . curl_error($ch));
            throw new Exception('Curl Error: ' . curl_error($ch));
        }

        curl_close($ch);

        return json_decode($result, true);
    }

    public function logMessage($message)
    {
        $timestamp = (new DateTime('now', new DateTimeZone('Asia/Dhaka')))->format('Y-m-d H:i:s');
        file_put_contents(self::LOG_FILE, "$timestamp - $message\n", FILE_APPEND);
    }
}